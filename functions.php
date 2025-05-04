<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);



require __DIR__ . '/vendor/autoload.php';

// импортируем необходимые классы
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Shared\Date as PhpExcelDate;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

// ===============================================
// ==========  Файл: functions.php  ==============
// ===============================================

// Настройки подключения к базе данных
define('DB_HOST', 'localhost');         // Укажите свой хост
define('DB_USER', 'u2611449_zayavki');  // Имя пользователя БД
define('DB_PASS', 'u2611449_zayavki');  // Пароль к БД
define('DB_NAME', 'u2611449_zayavki');  // Название БД



function getDBConnection(): mysqli {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        die("Ошибка подключения: " . $conn->connect_error);
    }
    // Включаем кодировку UTF-8
    $conn->set_charset("utf8mb4");
    return $conn;
}

/**
 * Сколько дней прошло с момента даты подачи
 */
function getDaysPassed(?string $date_submitted): int {
    if (!$date_submitted) {
        return 0;
    }
    $d1 = new DateTime($date_submitted);
    $d2 = new DateTime();
    return $d1->diff($d2)->days;
}

function markAllNotificationsAsRead(string $username): void {
    $conn = getDBConnection();
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->close();
    $conn->close();
}

function getUsernameById(?int $id): string
{
    if (empty($id)) {
        return '';                 // executor ещё не назначен
    }

    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->bind_result($uname);
    $stmt->fetch();
    $stmt->close();
    $conn->close();

    return $uname ?: '';
}

function getAllCITSRequests(): array
{
    $conn = getDBConnection();
    $sql = "
        SELECT r.*
          FROM requests r
         WHERE r.status       = 'new'              -- только новые
           AND r.executor_id  IS NULL              -- без исполнителя
           AND r.archived     = 0
           AND r.id           = r.group_id         -- 1 строка на заявку
           AND r.created_by IN (                    -- автор – технолог
                 SELECT username
                 FROM   users
                 WHERE  role = 'technologist'
             )
      ORDER BY r.required_date , r.id
    ";
    $rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
    $conn->close();
    return $rows;
}

function getExecutorRequestCounts(string $dateFrom, string $dateTo): array {
    $rows = getRequestsByFilterAdvanced(
        '', 
        ['f_date_from' => $dateFrom, 'f_date_to' => $dateTo], 
        0, 
        PHP_INT_MAX, 
        '', 
        ''
    );
    $counts = [];
    foreach ($rows as $r) {
        $ex = $r['executor'] ?: '—';
        $counts[$ex] = ($counts[$ex] ?? 0) + 1;
    }
    // Гарантируем нумерацию по ключам
    return $counts;
}


function getRequestStatusStats(string $dateFrom, string $dateTo, string $executor = ''): array {
    $rows = getRequestsByFilterAdvanced(
        '', 
        ['f_date_from' => $dateFrom, 'f_date_to' => $dateTo], 
        0, 
        PHP_INT_MAX, 
        '', 
        ''
    );
    if ($executor) {
        $rows = array_filter($rows, fn($r)=>($r['executor']?:'—') === $executor);
    }
    $stat = ['total'=>0,'ontime'=>0,'late'=>0,'cancel'=>0];
    foreach ($rows as $r) {
        $stat['total']++;
        $d = getDaysPassed($r['date_submitted']);
        if (in_array($r['status'], ['deleted','cancelled','отмененная'], true)) {
            $stat['cancel']++;
        } elseif ($d > 10) {
            $stat['late']++;
        } elseif ($r['status'] === 'completed' && $d <= 10) {
            $stat['ontime']++;
        }
    }
    return $stat;
}

function getWaitingCITSRequests(): array
{
    $conn = getDBConnection();

    $sql = "
        SELECT r.*
          FROM requests r
          JOIN users   u ON u.username = r.created_by
         WHERE u.role   = 'technologist'
           AND r.status = 'pending'
           AND r.id     = r.group_id
      ORDER BY r.required_date , r.id
    ";

    $rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
    $conn->close();
    return $rows;
}

function cancelRequest($id) {
    // Получаем новое подключение через getDBConnection()
    $conn = getDBConnection();
    // Обновление статуса заявки и установка флага архивирования
    $query = "UPDATE requests SET status = 'отмененная', archived = 1 WHERE id = ?";
    
    if ($stmt = $conn->prepare($query)) {
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $stmt->close();
            $conn->close();
            return "Заявка № {$id} отменена и перемещена в архив со статусом 'отмененная'.";
        } else {
            error_log("Ошибка отмены заявки: " . $stmt->error);
        }
        $stmt->close();
    }
    $conn->close();
    return "Ошибка при отмене заявки.";
}

function getRequestsForShiftChief($user_shift) {
    $conn = getDBConnection();
    
    $stmt = $conn->prepare("SELECT * FROM requests WHERE shift = ? AND status != 'cancelled' ORDER BY date_submitted DESC");
    $stmt->bind_param("s", $user_shift);
    
    $stmt->execute();
    $result = $stmt->get_result();
    $requests = $result->fetch_all(MYSQLI_ASSOC);
    
    $stmt->close();
    $conn->close();
    
    return $requests;
}

function getComments(int $positionId): array {
    $conn = getDBConnection();
    $stmt = $conn->prepare(
        "SELECT
            username,
            comment,
            DATE_FORMAT(created_at, '%d.%m.%Y %H:%i') AS created_at
         FROM comments
         WHERE position_id = ?
         ORDER BY id DESC"
    );
    $stmt->bind_param('i', $positionId);
    $stmt->execute();
    $result   = $stmt->get_result();
    $comments = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();
    return $comments;
}

function addComment(int $positionId, string $user, string $text): bool {
    $conn = getDBConnection();
    $stmt = $conn->prepare(
        "INSERT INTO comments (position_id, username, comment)
         VALUES (?, ?, ?)"
    );
    if (!$stmt) {
        error_log('addComment prepare error: ' . $conn->error);
        return false;
    }
    $stmt->bind_param('iss', $positionId, $user, $text);
    $success = $stmt->execute();
    if (! $success) {
        error_log('addComment execute error: ' . $stmt->error);
    }
    $stmt->close();
    $conn->close();
    return $success;
}

function getActiveTicketsForAnalyst(int $offset, int $limit): array {
    return getRequestsByFilterAdvanced(
        'active',
        [],                // никаких дополнительных фильтров
        $offset,
        $limit,
        ROLE_ANALYTIC,
        ''                  // currentUser не нужен
    );
}

function getActiveTicketsCountForAnalyst(): int {
    return getRequestsCountAdvanced(
        'active',
        [],
        ROLE_ANALYTIC,
        ''
    );
}

function getOverdueTicketsForAnalyst(int $offset, int $limit): array {
    $conn = getDBConnection();
    // выбираем **все** заявки, где DATEDIFF > 10, без исключения по статусу
    $stmt = $conn->prepare("
        SELECT *
          FROM requests
         WHERE DATEDIFF(CURDATE(), date_submitted) > 10
      ORDER BY date_submitted DESC
         LIMIT ?, ?
    ");
    $stmt->bind_param("ii", $offset, $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();
    return $rows;
}

function getAnalyticsRequests(string $from, string $to): array {
    $conn = getDBConnection();
    $sql  = "
      SELECT
        r.*,
        DATEDIFF(
          IFNULL(r.actual_time, CURDATE()),
          r.date_submitted
        ) AS days_passed
      FROM requests AS r
     WHERE r.date_submitted BETWEEN ? AND ?
     ORDER BY r.date_submitted DESC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $from, $to);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();
    return $rows;
}

function getOverdueTicketsCountForAnalyst(): int {
    $conn = getDBConnection();
    $res  = $conn->query("
        SELECT COUNT(*) AS cnt
          FROM requests
         WHERE DATEDIFF(CURDATE(), date_submitted) > 10
    ");
    $cnt = (int)($res->fetch_assoc()['cnt'] ?? 0);
    $conn->close();
    return $cnt;
}

function getArchivedTicketsForAnalyst(int $offset, int $limit): array {
    return getRequestsByFilterAdvanced(
        'archive',
        [], $offset, $limit, ROLE_ANALYTIC, ''
    );
}

function getArchivedTicketsCountForAnalyst(): int {
    return getRequestsCountAdvanced(
        'archive',
        [],
        ROLE_ANALYTIC,
        ''
    );
}


function assignExecutor(int $requestId, int $executorId): bool
{
    $conn = getDBConnection();

    // ставим ID + логин исполнителя, меняем статус на «pending»
    $executorName = getUsernameById($executorId);

    $stmt = $conn->prepare(
        "UPDATE requests
            SET executor_id = ?, executor = ?, status = 'pending'
          WHERE id = ?"
    );
    $stmt->bind_param("isi", $executorId, $executorName, $requestId);
    $ok = $stmt->execute();
    $stmt->close();
    $conn->close();

    return $ok;
}

function getMyRequestsForCITS(int $myId): array
{
    $conn = getDBConnection();
    $stmt = $conn->prepare("
        SELECT *
          FROM requests
         WHERE executor_id    = ?
           AND status NOT IN ('completed','отмененная')
           AND archived        = 0
           AND id              = group_id         -- 1 строка на заявку
      ORDER BY required_date , id
    ");
    $stmt->bind_param('i', $myId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();
    return $rows;
}

function getAwaitingForCITS(): array
{
    $conn = getDBConnection();
    $sql = "
        SELECT *
          FROM requests
         WHERE status   = 'pending'
           AND archived  = 0
           AND id        = group_id             -- 1 строка на заявку
      ORDER BY required_date , id
    ";
    $rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
    $conn->close();
    return $rows;
}

/*  Нажата кнопка «Назначить ЦИТС» */
function assignExecutorToSelf(int $requestId, int $myId, string $myLogin): bool
{
    $conn = getDBConnection();
    $stmt = $conn->prepare("
        UPDATE requests
           SET executor_id = ?,
               executor     = ?,        -- сохраняем логин для простых отчётов
               status       = 'pending' -- «ожидающая»
         WHERE id           = ?
           AND status        = 'new'    -- ставим только из «new»
           AND executor_id IS NULL
    ");
    $stmt->bind_param('isi', $myId, $myLogin, $requestId);
    $ok = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $ok;
}


function renderCITSTable(array $rows): void
{
    echo '<div class="table-container">';
    echo '<table class="cits-table">';
    /* ───────────────────── заголовок ───────────────────── */
    $cols = [
        '№ п/п','Дата подачи','Смена','ЦДНГ','Цех КРС','Бригада','Мастер',
        'Куст','Скважина','Вид заявки','Наименование','Отв. исп.',
        'Треб. дата','Треб. время','Исполнитель','Статус',
        'Факт. время','Примечание','Длит.(дн)','+10','+3','Действия'
    ];
    echo '<tr>';
    foreach ($cols as $c) echo "<th>{$c}🔍</th>";
    echo '</tr>';

    /* ───────────────────── строки ───────────────────── */
    $i = 1;
    foreach ($rows as $r) {
        // кол-во дней
        $days = getDaysPassed($r['date_submitted'] ?? null);
        // маркеры +10 / +3
        $flag10 = $days > 10 ? '❗' : '';
        $flag3  = ($days > 3 && $days <= 10) ? '⚠️' : '';

        echo '<tr>';
        echo '<td>'.($i++).'</td>';
        echo '<td>'.htmlspecialchars($r['date_submitted']).'</td>';
        echo '<td>'.htmlspecialchars($r['shift']).'</td>';
        echo '<td>'.htmlspecialchars($r['cdng']).'</td>';
        echo '<td>'.htmlspecialchars($r['ceh_krs']).'</td>';
        echo '<td>'.htmlspecialchars($r['brigade']).'</td>';
        echo '<td>'.htmlspecialchars($r['master']).'</td>';
        echo '<td>'.htmlspecialchars($r['kust']).'</td>';
        echo '<td>'.htmlspecialchars($r['skvazhina']).'</td>';
        echo '<td>'.htmlspecialchars($r['type']).'</td>';
        echo '<td>'.htmlspecialchars($r['description']).'</td>';
        echo '<td>'.htmlspecialchars($r['responsible_executive'] ?? '').'</td>';
        echo '<td>'.htmlspecialchars($r['required_date']).'</td>';
        echo '<td>'.htmlspecialchars($r['required_time']).'</td>';
        echo '<td>'.htmlspecialchars(getUsernameById($r['executor_id'] ?? 0)).'</td>';
        echo '<td>'.htmlspecialchars($r['status']).'</td>';
        echo '<td>'.htmlspecialchars($r['actual_time']).'</td>';
        echo '<td>'.htmlspecialchars($r['note']).'</td>';
        echo '<td>'.(int)$days.'</td>';
        echo '<td class="days10">'.$flag10.'</td>';
        echo '<td class="days3">'.$flag3.'</td>';

        /* ------ действия (пример) ------ */
        echo '<td>';
        if (is_null($r['executor_id'])) {
            echo '<a href="?action=assign&id='.$r['id'].'">Назначить</a>';
        } else {
            echo '<a href="?action=viewRequest&id='.$r['group_id'].'">Открыть</a>';
        }
        echo '</td>';

        echo '</tr>';
    }
    echo '</table>';
    echo '</div>';
}



/**
 * Отменить заявку (для технолога)
 * Переводит всю группу заявок (записей с одинаковым group_id) в статус "отмененная".
 * Такие заявки не будут отображаться у начальника смены ЦИТС (если не выбран фильтр архив).
 */

function techCancelRequest(int $id): bool {
    $conn = getDBConnection();
    // Обновляем заявку, если либо group_id = ? либо id = ?
    // Используем статус 'deleted', который уже разрешён в enum,
    // и устанавливаем archived = 1.
    $stmt = $conn->prepare("UPDATE requests SET status = 'deleted', archived = 1 
                            WHERE (group_id = ? OR id = ?) 
                              AND status NOT IN ('deleted','completed')");
    if (!$stmt) {
        error_log("Ошибка подготовки запроса в techCancelRequest: " . $conn->error);
        $conn->close();
        return false;
    }
    $stmt->bind_param("ii", $id, $id);
    $res = $stmt->execute();
    if (!$res) {
        error_log("Ошибка выполнения запроса в techCancelRequest: " . $stmt->error);
    }
    $stmt->close();
    $conn->close();
    return $res;
}

function confirmByTechnologist(int $requestId): bool
{
    $conn = getDBConnection();
    $stmt = $conn->prepare("
        UPDATE requests
           SET status   = 'completed',
               archived = 1
         WHERE id = ?
    ");
    $stmt->bind_param("i", $requestId);
    $ok = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $ok;
}

function updateStatusByPerformer(int $requestId, string $newStatus): bool
{
    // если поле статуса пустое → «в работе»
    if ($newStatus === '') {
        $newStatus = 'in_work';
    }

    // статусы, после которых фиксируем время и отправляем в архив
    $finalStatuses = ['ready_for_dispatch', 'shipped', 'completed'];

    $conn = getDBConnection();

    /* ─────────── SQL ─────────── */
    if (in_array($newStatus, $finalStatuses, true)) {
        // пишем actual_time и archived = 1
        $stmt = $conn->prepare(
            "UPDATE requests 
                SET status = ?,
                    actual_time = NOW(),
                    archived = 1
              WHERE id = ?"
        );
    } else {
        $stmt = $conn->prepare(
            "UPDATE requests 
                SET status = ?
              WHERE id = ?"
        );
    }
    $stmt->bind_param("si", $newStatus, $requestId);
    $ok = $stmt->execute();
    $stmt->close();

    /* ─────────── Уведомления ─────────── */
    if ($ok && in_array($newStatus, ['ready_for_dispatch', 'shipped'], true)) {
        $pos = getPositionById($requestId);
        if ($pos) {
            // уведомляем автора заявки
            if (!empty($pos['created_by'])) {
                addNotification(
                    $pos['created_by'],
                    "Позиция № {$requestId} переведена ЦИТС в статус «{$newStatus}»",
                    "?action=viewRequest&id=" . ($pos['group_id'] ?? $requestId)
                );
            }
            // уведомляем всех технологов
            $techConn = getDBConnection();
            $res = $techConn->query("SELECT username FROM users WHERE role = 'technologist'");
            while ($row = $res->fetch_assoc()) {
                addNotification(
                    $row['username'],
                    "Позиция № {$requestId} перешла в статус «{$newStatus}». Проверьте выполнение.",
                    "?action=viewRequest&id=" . ($pos['group_id'] ?? $requestId)
                );
            }
            $res->close();
            $techConn->close();
        }
    }

    $conn->close();
    return $ok;
}

/**
 * Обрабатывает inline-редактирование одного поля заявки.
 *
 * Ожидает POST-параметры:
 * - request_id: идентификатор записи в таблице requests
 * - field_name: имя поля для редактирования (разрешённые поля заданы в массиве $allowed_fields)
 * - new_value: новое значение для указанного поля
 *
 * Возвращает true при успехе или строку с сообщением об ошибке.
 */
function processEditRequestField() {
    // Перечень полей, которые разрешено менять через inline-редактирование
    $allowed_fields = ['shift', 'brigade', 'master', 'kust', 'skvazhina', 'required_date', 'required_time', 'note'];
    // Поля, требующие конвертации из пользовательского формата
    $dateFields     = ['required_date'];

    if (isset($_POST['request_id'], $_POST['field_name'], $_POST['new_value'])) {
        $request_id = intval($_POST['request_id']);
        $field      = $_POST['field_name'];
        $new_value  = trim($_POST['new_value']);

        // Проверяем, что поле разрешено
        if (!in_array($field, $allowed_fields, true)) {
            return "Недопустимое поле.";
        }

        // Если это дата — ожидаем дд.мм.гггг, конвертируем в MySQL-формат
        if (in_array($field, $dateFields, true)) {
            $dt = DateTime::createFromFormat('d.m.Y', $new_value);
            if ($dt !== false) {
                $new_value = $dt->format('Y-m-d');
            }
        }

        $conn  = getDBConnection();
        $query = "UPDATE requests SET `$field` = ? WHERE id = ?";
        $stmt  = $conn->prepare($query);
        if (!$stmt) {
            $err = $conn->error;
            $conn->close();
            return "Ошибка подготовки запроса: $err";
        }

        $stmt->bind_param('si', $new_value, $request_id);
        if ($stmt->execute()) {
            $stmt->close();
            $conn->close();
            return true;
        } else {
            $err = $stmt->error;
            $stmt->close();
            $conn->close();
            return "Ошибка обновления: $err";
        }
    }

    return "Необходимо передать все параметры (request_id, field_name, new_value).";
}


function updatePositionField(int $id, string $field, string $value): bool {
    // Список полей, которые разрешено менять
    $allowed = [
        'date_submitted','shift','cdng','ceh_krs','brigade',
        'master','kust','skvazhina','type','description',
        'required_date','required_time','note'
    ];
    if (!in_array($field, $allowed, true)) {
        return false;
    }
    // Получаем соединение
    $conn = getDBConnection();
    // Обновляем именно строку в таблице requests
    $sql = "UPDATE requests SET `$field` = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        $conn->close();
        return false;
    }
    $stmt->bind_param("si", $value, $id);
    $ok = $stmt->execute();
    if (!$ok) {
        error_log("Execute failed: " . $stmt->error);
    }
    $stmt->close();
    $conn->close();
    return $ok;
}


function addRequests(array $data, array $positions) {
    // Нет позиций — нет смысла продолжать
    if (empty($positions)) {
        return false;
    }

    $conn = getDBConnection();
    $newIds = [];

    // Подготавливаем INSERT (status — 'new' по умолчанию)
    $stmt = $conn->prepare(
        "INSERT INTO requests
         (date_submitted, shift, cdng, ceh_krs, brigade, master,
          kust, skvazhina, type, description,
          required_date, required_time,
          created_by, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$stmt) {
        $conn->close();
        return false;
    }

    $initial_status = 'new';

    foreach ($positions as $pos) {
        $type          = trim($pos['type']          ?? '');
        $description   = trim($pos['description']   ?? '');
        $required_date = trim($pos['required_date'] ?? '');
        $required_time = trim($pos['required_time'] ?? '');

        $stmt->bind_param(
            "ssssssssssssss",
            $data['date_submitted'],
            $data['shift'],
            $data['cdng'],
            $data['ceh_krs'],
            $data['brigade'],
            $data['master'],
            $data['kust'],
            $data['skvazhina'],
            $type,
            $description,
            $required_date,
            $required_time,
            $data['created_by'],
            $initial_status
        );

        if ($stmt->execute()) {
            $newId = $stmt->insert_id;
            // Для группировки: group_id = id первой вставленной записи
            $conn->query("UPDATE requests SET group_id = $newId WHERE id = $newId");
            $newIds[] = $newId;
        }
    }

    $stmt->close();
    $conn->close();

    return $newIds;
}



/**
 * Генерация HTML-кода колокольчика уведомлений (улучшенный дизайн)
 */
function renderNotificationsBell(string $username): string {
    // Получаем все уведомления этого пользователя
    $notifications = getNotifications($username);

    // Считаем непрочитанные
    $unreadCount = 0;
    if (!empty($notifications)) {
        foreach ($notifications as $note) {
            if (!$note['is_read']) {
                $unreadCount++;
            }
        }
    }

    ob_start();
    ?>
    <!-- Контейнер колокольчика -->
    <div class="bell-container">
      <!-- Иконка колокольчика -->
      <span class="bell-icon" onclick="toggleNotifications(); markAllReadAjax();">
        &#128276;
        <?php if ($unreadCount > 0): ?>
          <span class="badge"><?php echo $unreadCount; ?></span>
        <?php endif; ?>
      </span>

      <!-- Выпадающая панель уведомлений -->
      <div class="notification-list" id="notificationList">
        <div class="notification-header">
          <h4>Уведомления</h4>
          <button type="button" class="notification-close-btn" onclick="closeNotifications()">×</button>
        </div>
        
        <div class="notification-body">
          <?php if (!empty($notifications)): ?>
            <ul class="notifications-ul">
              <?php foreach ($notifications as $note): ?>
                <li class="notification-item <?php echo $note['is_read'] ? '' : 'unread'; ?>">
                  <a href="<?php echo htmlspecialchars($note['link'], ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo htmlspecialchars($note['message'], ENT_QUOTES, 'UTF-8'); ?>
                  </a>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <div class="notification-empty">
              <em>Нет уведомлений</em>
            </div>
          <?php endif; ?>
        </div>
        
        <div class="notification-footer">
          <button type="button" class="notification-close-btn-footer" onclick="closeNotifications()">Закрыть</button>
        </div>
      </div>
    </div>

    <!-- Скрипт AJAX для обнуления счётчика непрочитанных -->
    <script>
      function markAllReadAjax() {
        // Посылаем запрос на index.php?action=markAllRead (пример)
        fetch('index.php?action=markAllRead', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
          },
          body: 'username=<?php echo urlencode($username); ?>'
        })
        .then(response => response.text())
        .then(data => {
          // Скрываем бейдж и снимаем класс unread
          const badge = document.querySelector('.bell-icon .badge');
          if (badge) {
            badge.style.display = 'none';
          }
          document.querySelectorAll('#notificationList .unread').forEach(li => {
            li.classList.remove('unread');
          });
        })
        .catch(error => console.error('Error:', error));
      }
    </script>
    <?php
    return ob_get_clean();
}


/**
 * Логин пользователя
 */
function loginUser(string $username, string $password) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT id, username, password, role FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($user = $result->fetch_assoc()) {
        if (password_verify($password, $user['password'])) {
            $stmt->close();
            $conn->close();
            return $user;
        }
    }
    $stmt->close();
    $conn->close();
    return false;
}

/**
 * Регистрация пользователя
 */
function registerUser(
    string $username,
    string $password,
    string $role,
    string $fullname = '',
    string $position = '',
    string $email = '',
    string $phone = '',
    string $struct_division = ''  // Новый параметр
) {
    // Проверка, существует ли уже пользователь с таким именем
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
    if (!$stmt) {
        die("Ошибка подготовки запроса: " . $conn->error);
    }
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        $stmt->close();
        $conn->close();
        return "Пользователь с таким именем уже существует.";
    }
    $stmt->close();

    // Хэширование пароля
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    // Вставляем все данные, включая structural_division
    $stmt = $conn->prepare("INSERT INTO users (`username`, `password`, `role`, `fullname`, `position`, `email`, `phone`, `structural_division`)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        die("Ошибка подготовки запроса: " . $conn->error);
    }
    $stmt->bind_param("ssssssss", $username, $hashedPassword, $role, $fullname, $position, $email, $phone, $struct_division);
    
    if ($stmt->execute()) {
        $result = true;
    } else {
        $result = "Ошибка регистрации: " . $stmt->error;
    }
    $stmt->close();
    $conn->close();
    return $result;
}




function renderToastNotification(string $message, string $type = 'success'): string {
    ob_start();
    ?>
    <!-- Блок toast уведомления -->
    <div id="toast" class="toast <?php echo htmlspecialchars($type, ENT_QUOTES, 'UTF-8'); ?>">
      <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
    </div>

    <!-- JavaScript для показа и скрытия уведомления -->
    <script>
      (function() {
        const toast = document.getElementById('toast');
        if (!toast) return;
        // Запускаем анимацию показа
        toast.classList.add('show');
        // Устанавливаем автоматическое скрытие через 3 секунды
        setTimeout(() => {
          toast.classList.remove('show');
        }, 3000);
      })();
    </script>

    <!-- CSS стили для toast уведомления -->
    <style>
      .toast {
        visibility: hidden;
        min-width: 250px;
        margin-left: -125px;
        background-color: #333;
        color: #fff;
        text-align: center;
        border-radius: 4px;
        padding: 16px;
        position: fixed;
        z-index: 9999;
        left: 50%;
        bottom: 30px;
        font-size: 16px;
        opacity: 0;
        transition: opacity 0.5s ease-in-out, visibility 0.5s;
      }
      .toast.show {
        visibility: visible;
        opacity: 1;
      }
      .toast.success {
        background-color: #4CAF50;
      }
      .toast.error {
        background-color: #f44336;
      }
    </style>
    <?php
    return ob_get_clean();
}

/**
 * Получить список исполнителей (role='executor')
 */
function getExecutors(): array {
    $conn = getDBConnection();
    $sql = "SELECT id, username, structural_division FROM users WHERE role = 'executor'";
    $result = $conn->query($sql);
    $executors = [];
    while ($row = $result->fetch_assoc()){
        $executors[] = $row;
    }
    $conn->close();
    return $executors;
}


/**
 * Получить список начальников (role='chief')
 */
function getChiefs(): array {
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT username FROM users WHERE role = 'chief'");
    $stmt->execute();
    $result = $stmt->get_result();
    $chiefs = [];
    while ($row = $result->fetch_assoc()){
        $chiefs[] = $row;
    }
    $stmt->close();
    $conn->close();
    return $chiefs;
}

/**
 * Добавить уведомление пользователю
 */
function addNotification(string $username, string $message, string $link) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("INSERT INTO notifications (username, message, link, is_read, created_at) VALUES (?, ?, ?, 0, NOW())");
    $stmt->bind_param("sss", $username, $message, $link);
    $stmt->execute();
    $stmt->close();
    $conn->close();
}

/**
 * Получить все уведомления пользователя
 */
function getNotifications(string $username): array {
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT * FROM notifications WHERE username = ? ORDER BY created_at DESC");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $notifications = [];
    while ($row = $result->fetch_assoc()){
        $notifications[] = $row;
    }
    $stmt->close();
    $conn->close();
    return $notifications;
}

/**
 * Пометить уведомление прочитанным
 */
function markNotificationRead(int $id) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    $conn->close();
}

/**
 * Обновить профиль пользователя (ФИО, должность, email, телефон)
 */
function updateUserProfile(int $user_id, ?string $fullname, ?string $position, ?string $email, ?string $phone): bool {
    $conn = getDBConnection();
    $stmt = $conn->prepare("UPDATE users SET fullname = ?, position = ?, email = ?, phone = ? WHERE id = ?");
    $stmt->bind_param("ssssi", $fullname, $position, $email, $phone, $user_id);
    $res = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $res;
}

/**
 * Получить профиль (ФИО, должность и т.д.)
 */
function getUserProfile(int $user_id): ?array {
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT fullname, position, email, phone FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $profile = $result->fetch_assoc();
    $stmt->close();
    $conn->close();
    return $profile;
}

/**
 * Добавить новую «головную» заявку (запись в таблице requests)
 */
function addRequest(array $data) {
    $conn = getDBConnection();
    $sql = "INSERT INTO requests (
        date_submitted, shift, cdng, ceh_krs, brigade, master,
        kust, skvazhina, type, description, required_date, required_time, created_by
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);

    // Из массива $data берём поля
    $date_submitted = $data['date_submitted'] ?? '';
    $shift          = $data['shift'] ?? '';
    $cdng           = $data['cdng'] ?? '';
    $ceh_krs        = $data['ceh_krs'] ?? '';
    $brigade        = $data['brigade'] ?? '';
    $master         = $data['master'] ?? '';
    $kust           = $data['kust'] ?? '';
    $skvazhina      = $data['skvazhina'] ?? '';
    $type           = $data['type'] ?? '';
    $description    = $data['description'] ?? '';
    $required_date  = $data['required_date'] ?? '';
    $required_time  = $data['required_time'] ?? '';
    $created_by     = $data['created_by'] ?? '';

    $stmt->bind_param(
        "sssssssssssss",
        $date_submitted,
        $shift,
        $cdng,
        $ceh_krs,
        $brigade,
        $master,
        $kust,
        $skvazhina,
        $type,
        $description,
        $required_date,
        $required_time,
        $created_by
    );

    if ($stmt->execute()) {
        $newId = $stmt->insert_id;
        $stmt->close();
        // Ставим group_id = id (обычно для группировки позиций в одну заявку)
        $conn->query("UPDATE requests SET group_id = $newId WHERE id = $newId");
        $conn->close();

        // === УВЕДОМЛЕНИЯ ДЛЯ CHIEF ===
        // если создаёт технолог
        if (isset($_SESSION['role']) && $_SESSION['role'] === 'technologist') {
            $chiefs = getChiefs();  // берёт всех пользователей role='chief'
            foreach ($chiefs as $chief) {
                addNotification(
                    $chief['username'],
                    "Новая заявка (№$newId) создана технологом: " . ($_SESSION['user'] ?? ''),
                    "?action=viewRequest&id=$newId"
                );
            }
        }

        // === РЕДИРЕКТ ===
        // Чтобы сразу открыть страницу просмотра заявки:
        header("Location: index.php?action=viewRequest&id=$newId");
        exit;
    }
    // если неудачно вставили
    $stmt->close();
    $conn->close();
    return false;
}


/**
 * Добавить позицию (запись) к существующей заявке (group_id)
 */
function addRequestPosition(int $group_id, array $data) {
    $conn = getDBConnection();
    // Возьмём общие поля из первой записи заявки (головной)
    $stmt = $conn->prepare("SELECT date_submitted, shift, cdng, ceh_krs, brigade, master, kust, skvazhina, required_date, required_time 
                            FROM requests 
                            WHERE group_id = ? 
                            ORDER BY id ASC LIMIT 1");
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    $baseData = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$baseData) {
        $conn->close();
        return false;
    }

    // В новой позиции мы меняем только «type» и «description»
    $sql = "INSERT INTO requests 
        (group_id, date_submitted, shift, cdng, ceh_krs, brigade, master, kust, skvazhina, type, description, required_date, required_time) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);

    $type        = $data['type'] ?? '';
    $description = $data['description'] ?? '';

    $stmt->bind_param("issssssssssss",
        $group_id,
        $baseData['date_submitted'],
        $baseData['shift'],
        $baseData['cdng'],
        $baseData['ceh_krs'],
        $baseData['brigade'],
        $baseData['master'],
        $baseData['kust'],
        $baseData['skvazhina'],
        $type,
        $description,
        $baseData['required_date'],
        $baseData['required_time']
    );

    if ($stmt->execute()) {
        $newPosId = $stmt->insert_id;
        $stmt->close();
        $conn->close();
        return $newPosId;
    }
    $stmt->close();
    $conn->close();
    return false;
}

/**
 * Получить все позиции (строки) заявки по group_id
 */
function getRequestById(int $group_id): array {
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT * FROM requests WHERE group_id = ? ORDER BY id ASC");
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $positions = [];
    while ($row = $result->fetch_assoc()){
        $positions[] = $row;
    }
    $stmt->close();
    $conn->close();
    return $positions;
}

/**
 * Получить одну позицию заявки по ID
 */
function getPositionById(int $id): ?array {
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT * FROM requests WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $position = $result->fetch_assoc();
    $stmt->close();
    $conn->close();
    return $position ?: null;
}

/**
 * Фильтр/пагинация заявок, учёт ролей
 */
/**
 * Получить заявки по фильтру (с пагинацией), учитывая роль и дополнительные условия.
 * - Сортировка: ORDER BY id DESC (новые id вверху).
 * - Если исполнитель без фильтра, исключаем shipped/completed (не показывает исполненные/архивные).
 * - Для архивов (filter=archive) показываем status='completed'.
 */
/**
 * Фильтрация заявок с учётом роли и сортировка по убыванию id.
 */
/**
 * Получить заявки с учётом базового / расширенного фильтра, роли пользователя и пагинации.
 *
 * ─  Аргументы  ────────────────────────────────────────────────────────────────────
 * $filter            'waiting' | 'overdue' | 'archive' | ''           — базовый фильтр
 * $advancedFilters    ключ-значение из формы (f_date_from, f_shift и т. д.)
 * $offset, $limit    для LIMIT
 * $userRole          роль текущего пользователя (technologist / executor / chief / аналитик / …)
 * $currentUser       логин, если нужно показывать только «свои» записи (исполнитель)
 *
 * ─  Логика  ───────────────────────────────────────────────────────────────────────
 * 1.  Условия складываются в $conditions[], затем склеиваются в WHERE.
 * 2.  Просроченные → разница > 10 дней между date_submitted и actual_time|today.
 * 3.  completed / отменённые скрываются ВЕЗДЕ, кроме:
 *         • filter = 'archive'  (показываем только архив)
 *         • filter = 'overdue'  (нужны все, даже completed, кроме отменённых)
 * 4.  Дополнительные текстовые/датовые фильтры приходят в $advancedFilters.
 */
function getRequestsByFilterAdvanced(
    string $filter,
    array  $advancedFilters,
    int    $offset,
    int    $limit,
    string $userRole   = "",
    string $currentUser = ""
): array
{
    $conn       = getDBConnection();
    $conditions = [];

    /**************** 1. Базовые исключения по статусу ****************/

    // скрываем completed / отменённые, КРОМЕ archive и overdue
    if ($filter !== 'archive' && $filter !== 'overdue') {
        $conditions[] = "status NOT IN ('completed', 'отмененная')";
    }

    // режим «archive» — наоборот, показываем ТОЛЬКО completed / отменённые
    if ($filter === 'archive') {
        $conditions[] = "status IN ('completed', 'отмененная')";
    }

    /**************** 2. Специальные режимы ****************/

    // «waiting» (ожидающие) — свои нюансы для разных ролей
    if ($filter === 'waiting') {
        if ($userRole === 'technologist') {
            // технолог видит всё, что назначено (есть executor) и ещё не закрыто
            $conditions[] = "executor IS NOT NULL";
            $conditions[] = "status NOT IN ('completed','отмененная')";
        } else {
            // остальные роли — новые или pending
            $conditions[] = "status IN ('new','pending')";
        }
    }

    // «overdue» — старше 10 дн.; completed остаются, отменённые убираем
    if ($filter === 'overdue') {
        $conditions[] = "DATEDIFF( IFNULL(actual_time, CURDATE()), date_submitted ) > 10";
        $conditions[] = "status <> 'отмененная'";
    }

    /**************** 3. Ограничения по роли ****************/

    // Начальник смены (chief / cits) — по умолчанию только «new»
    if ($userRole === 'chief' && $filter === '') {
        $conditions[] = "status = 'new'";
    }

    // Исполнитель (executor) — по умолчанию не показывает архив
    if ($userRole === 'executor' && $filter === '') {
        $conditions[] = "status NOT IN ('completed','отмененная')";
    }

    // Аналитик — никаких дополнительных ограничений (но можно добавить)

    // Показать только «мои» (для исполнителя)
    if ($userRole === 'executor' && $currentUser !== '') {
        $escUser = $conn->real_escape_string($currentUser);
        $conditions[] = "executor = '{$escUser}'";
    }

    /**************** 4. Расширенные текстовые и датовые фильтры ****************/

    if (!empty($advancedFilters['f_date_from'])) {
        $from = $conn->real_escape_string($advancedFilters['f_date_from']);
        $conditions[] = "date_submitted >= '{$from}'";
    }
    if (!empty($advancedFilters['f_date_to'])) {
        $to = $conn->real_escape_string($advancedFilters['f_date_to']);
        $conditions[] = "date_submitted <= '{$to}'";
    }

    // список простых LIKE-фильтров
    foreach ([
        'shift', 'cdng', 'ceh_krs', 'brigade', 'master',
        'kust', 'skvazhina', 'type'
    ] as $field) {
        $key = "f_{$field}";
        if (!empty($advancedFilters[$key])) {
            $val = $conn->real_escape_string($advancedFilters[$key]);
            $conditions[] = "{$field} LIKE '%{$val}%'";
        }
    }

    // фильтр по статусу (из выпадающего списка)
    if (!empty($advancedFilters['f_status'])) {
        $st = $conn->real_escape_string($advancedFilters['f_status']);
        $conditions[] = "status = '{$st}'";
    }

    /**************** 5. Сборка WHERE и выполнение ****************/

    $where = $conditions ? (' WHERE ' . implode(' AND ', $conditions)) : '';

    $sql = "
        SELECT *
          FROM requests
        {$where}
        ORDER BY id DESC
        LIMIT {$offset}, {$limit}
    ";
    $result   = $conn->query($sql);
    $requests = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

    $conn->close();
    return $requests;
}





/**
 * Подсчёт количества заявок под фильтром (для пагинации)
 */
function getRequestsCountAdvanced(
    string $filter, 
    array $advancedFilters, 
    string $userRole = "", 
    string $currentUser = ""
): int {
    $conn = getDBConnection();
    $conditions = [];
    
    if ($filter !== 'deleted') {
        $conditions[] = "status <> 'deleted'";
    }
    if ($filter === 'waiting') {
        if ($userRole === 'technologist') {
            $conditions[] = "executor IS NOT NULL";
            $conditions[] = "status NOT IN ('completed','deleted','отмененная')";
        } else {
            $conditions[] = "status IN ('new','pending')";
        }
    } elseif ($filter === 'overdue') {
        $conditions[] = "DATEDIFF(CURDATE(), date_submitted) > 10";
    } elseif ($filter === 'archive') {
        $conditions[] = "status IN ('completed','отмененная')";
    }
    if (!empty($advancedFilters['f_date_from'])) {
        $conditions[] = "date_submitted >= '" . $conn->real_escape_string($advancedFilters['f_date_from']) . "'";
    }
    if (!empty($advancedFilters['f_date_to'])) {
        $conditions[] = "date_submitted <= '" . $conn->real_escape_string($advancedFilters['f_date_to']) . "'";
    }
    if (!empty($advancedFilters['f_shift'])) {
        $conditions[] = "shift LIKE '%" . $conn->real_escape_string($advancedFilters['f_shift']) . "%'";
    }
    if (!empty($advancedFilters['f_cdng'])) {
        $conditions[] = "cdng LIKE '%" . $conn->real_escape_string($advancedFilters['f_cdng']) . "%'";
    }
    if (!empty($advancedFilters['f_ceh_krs'])) {
        $conditions[] = "ceh_krs LIKE '%" . $conn->real_escape_string($advancedFilters['f_ceh_krs']) . "%'";
    }
    if (!empty($advancedFilters['f_brigade'])) {
        $conditions[] = "brigade LIKE '%" . $conn->real_escape_string($advancedFilters['f_brigade']) . "%'";
    }
    if (!empty($advancedFilters['f_master'])) {
        $conditions[] = "master LIKE '%" . $conn->real_escape_string($advancedFilters['f_master']) . "%'";
    }
    if (!empty($advancedFilters['f_kust'])) {
        $conditions[] = "kust LIKE '%" . $conn->real_escape_string($advancedFilters['f_kust']) . "%'";
    }
    if (!empty($advancedFilters['f_skvazhina'])) {
        $conditions[] = "skvazhina LIKE '%" . $conn->real_escape_string($advancedFilters['f_skvazhina']) . "%'";
    }
    if (!empty($advancedFilters['f_type'])) {
        $conditions[] = "type LIKE '%" . $conn->real_escape_string($advancedFilters['f_type']) . "%'";
    }
    if (!empty($advancedFilters['f_status'])) {
        $conditions[] = "status = '" . $conn->real_escape_string($advancedFilters['f_status']) . "'";
    }
    // Если роль технолога и фильтр пустой, исключаем заявки с completed и отмененная
    if ($userRole === 'technologist' && $filter === '') {
        $conditions[] = "status NOT IN ('completed','отмененная')";
    }
    if ($userRole === 'executor' && !empty($currentUser)) {
        $conditions[] = "executor = '" . $conn->real_escape_string($currentUser) . "'";
    }
    
    $where = "";
    if (count($conditions) > 0) {
        $where = " WHERE " . implode(" AND ", $conditions);
    }
    $sql = "SELECT COUNT(*) as count FROM requests" . $where;
    $result = $conn->query($sql);
    $row = $result->fetch_assoc();
    $conn->close();
    return (int)($row['count'] ?? 0);
}


/**
 * Пометить заявку на удаление (запрос на удаление)
 */
function requestDeletion(int $id): bool {
    $conn = getDBConnection();
    $stmt = $conn->prepare("UPDATE requests SET deletion_request = 1, status = 'deletion_requested' WHERE id = ?");
    $stmt->bind_param("i", $id);
    $res = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $res;
}

/**
 * Получить заявки, запрошенные на удаление
 */
function getDeletionRequests(): array {
    $conn = getDBConnection();
    $sql = "SELECT * FROM requests WHERE deletion_request = 1 AND status <> 'deleted'";
    $result = $conn->query($sql);
    $requests = [];
    while ($row = $result->fetch_assoc()){
        $requests[] = $row;
    }
    $conn->close();
    return $requests;
}

/**
 * Получить удалённые заявки
 */
function getDeletedRequests(): array {
    $conn = getDBConnection();
    $sql = "SELECT * FROM requests WHERE status = 'deleted'";
    $result = $conn->query($sql);
    $requests = [];
    while ($row = $result->fetch_assoc()){
        $requests[] = $row;
    }
    $conn->close();
    return $requests;
}

/**
 * Администратор удаляет заявку окончательно
 */
function adminDeleteRequest(int $id): bool {
    $conn = getDBConnection();
    $stmt = $conn->prepare("UPDATE requests SET status = 'deleted' WHERE id = ?");
    $stmt->bind_param("i", $id);
    $res = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $res;
}

/**
 * Найти срочные заявки исполнителя (если до required_date <=3 дня)
 */
function getUrgentRequests(): array {
    $conn = getDBConnection();
    $currentUser = $_SESSION['user'] ?? '';
    $escUser = $conn->real_escape_string($currentUser);

    $sql = "
        SELECT *, DATEDIFF(required_date, CURDATE()) AS days_left
          FROM requests
         WHERE executor = '$escUser'
           AND required_date IS NOT NULL
           AND DATEDIFF(required_date, CURDATE()) <= 3
           AND status NOT IN ('completed', 'deleted', 'отмененная')
         ORDER BY days_left ASC
    ";

    $result = $conn->query($sql);
    $requests = [];
    while ($row = $result->fetch_assoc()) {
        $requests[] = $row;
    }
    $conn->close();
    return $requests;
}

/**
 * Обновить статус всей заявки (по group_id), если нужно
 */

function updateRequest(int $groupId, array $data): bool {
    $conn = getDBConnection();

    // Обновляем «шапку» заявки — все записи с этим group_id
    $sql = "UPDATE requests
            SET date_submitted = ?,
                shift          = ?,
                cdng           = ?,
                ceh_krs        = ?,
                brigade        = ?,
                master         = ?,
                kust           = ?,
                skvazhina      = ?,
                required_date  = ?,
                required_time  = ?
            WHERE group_id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $conn->close();
        return false;
    }
    $stmt->bind_param(
        "ssssssssssi",
        $data['date_submitted'],
        $data['shift'],
        $data['cdng'],
        $data['ceh_krs'],
        $data['brigade'],
        $data['master'],
        $data['kust'],
        $data['skvazhina'],
        $data['required_date'],
        $data['required_time'],
        $groupId
    );
    $ok = $stmt->execute();
    $stmt->close();

    // Если нужно, обновляем поля type и description только для первой позиции
    if ($ok && isset($data['type'], $data['description'])) {
        $positions = getRequestById($groupId);
        if (!empty($positions)) {
            $firstId = (int)$positions[0]['id'];
            $stmt2 = $conn->prepare("UPDATE requests SET type = ?, description = ? WHERE id = ?");
            if ($stmt2) {
                $stmt2->bind_param("ssi", $data['type'], $data['description'], $firstId);
                $stmt2->execute();
                $stmt2->close();
            }
        }
    }

    $conn->close();
    return $ok;
}


// Пример функции для получения данных заявки по id



function deleteRequestGroup(int $group_id): bool {
    $conn = getDBConnection();
    $stmt = $conn->prepare("UPDATE requests SET status = 'deleted' WHERE group_id = ?");
    if (!$stmt) {
        $conn->close();
        return false;
    }
    $stmt->bind_param("i", $group_id);
    $res = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $res;
}

/**
 * Обновить статус одной позиции (по её ID)
 */
/**
 * Обновить статус одной позиции (по её ID).
 * Если устанавливаем "shipped" => записываем actual_time = NOW().
 * Если статус уже однажды был установлен, можно добавить проверку
 * (например, не перезаписывать actual_time повторно).
 */
function updatePositionStatusById(int $id, string $newStatus): bool
{
    // Если исполнитель выбрал «исполнено» (по-русски),
    // переводим в 'awaiting_confirmation' - если у вас так задумано.
    if ($_SESSION['role'] === 'executor' && $newStatus === 'исполнено') {
        $newStatus = 'awaiting_confirmation';
    }

    // Если статус = shipped/completed, то нужно записать actual_time = NOW().
    // Также добавляем ready_to_ship, если хотите, чтобы он тоже записывал actual_time.
    $setActualTime = in_array($newStatus, ['shipped','completed','ready_to_ship']);

    $conn = getDBConnection();
    if ($setActualTime) {
        // Запишем actual_time = NOW() при смене на указанные статусы
        $stmt = $conn->prepare("UPDATE requests 
                                SET status = ?, actual_time = NOW() 
                                WHERE id = ?");
        $stmt->bind_param("si", $newStatus, $id);
    } else {
        // Просто меняем статус, без actual_time
        $stmt = $conn->prepare("UPDATE requests 
                                SET status = ? 
                                WHERE id = ?");
        $stmt->bind_param("si", $newStatus, $id);
    }

    $res = $stmt->execute();
    $stmt->close();

    if ($res) {
        // Если успешно обновили статус, шлём уведомления
        $pos = getPositionById($id);
        if ($pos) {
            // 1) Уведомляем автора (created_by)
            if (!empty($pos['created_by'])) {
                addNotification(
                    $pos['created_by'],
                    "Статус позиции № $id изменён исполнителем на «{$newStatus}»",
                    "?action=viewRequest&id=" . ($pos['group_id'] ?? $id)
                );
            }

            // 2) Если исполнитель поставил статус shipped / ready_to_ship,
            //    уведомляем (а) всех технологов (role='technologist')
            //    и (б) всех начальников (role='chief').
            if ($_SESSION['role'] === 'executor' && in_array($newStatus, ['shipped','ready_to_ship'])) {
                
                // (a) Уведомляем технологов
                $techConn = getDBConnection();
                $resTech = $techConn->query("SELECT username FROM users WHERE role = 'technologist'");
                while ($row = $resTech->fetch_assoc()) {
                    addNotification(
                        $row['username'],
                        "Позиция № $id перешла в статус «{$newStatus}». Проверьте выполнение заявки.",
                        "?action=viewRequest&id=" . ($pos['group_id'] ?? $id)
                    );
                }
                $resTech->close();

                // (б) Уведомляем начальников (chief)
                $chiefConn = getDBConnection();
                $resChief = $chiefConn->query("SELECT username FROM users WHERE role = 'chief'");
                while ($c = $resChief->fetch_assoc()) {
                    addNotification(
                        $c['username'],
                        "Позиция № $id перешла в статус «{$newStatus}». Проверьте выполнение заявки.",
                        "?action=viewRequest&id=" . ($pos['group_id'] ?? $id)
                    );
                }
                $resChief->close();

                $techConn->close();
                $chiefConn->close();
            }
        }
    }

    $conn->close();
    return $res;
}



function showTechChiefDashboard(string $start_date, string $end_date) {
    $conn = getDBConnection();

    // (1) ФИШКА: Блокируем возможные SQL-инъекции в $start_date, $end_date (хотя у нас и prepare, на всякий случай).
    $start_date = $conn->real_escape_string($start_date);
    $end_date   = $conn->real_escape_string($end_date);

    // WHERE по датам
    $conditions = [];
    if (!empty($start_date)) {
        $conditions[] = "date_submitted >= '{$start_date}'";
    }
    if (!empty($end_date)) {
        $conditions[] = "date_submitted <= '{$end_date}'";
    }
    $where = $conditions ? "WHERE " . implode(" AND ", $conditions) : "";

    // Загружаем все заявки
    $sql = "SELECT * FROM requests $where ORDER BY date_submitted ASC";
    $result = $conn->query($sql);

    $requests = [];
    while ($row = $result->fetch_assoc()) {
        // days_passed: либо (date_submitted..actual_time), либо (date_submitted..now)
        if (!empty($row['actual_time'])) {
            $dtStart = new DateTime($row['date_submitted']);
            $dtEnd   = new DateTime($row['actual_time']);
            $interval = $dtStart->diff($dtEnd);
            $row['days_passed'] = $interval->days;
        } else {
            $row['days_passed'] = getDaysPassed($row['date_submitted']);
        }
        $requests[] = $row;
    }
    $result->close();
    $conn->close();

    // Подготавливаем счётчики для разных разрезов
    $statusCounts  = [];
    $shiftCounts   = [];
    $typeCounts    = [];
    $execCounts    = [];
    $brigadeCounts = [];

    // Счётчики для просрочено / в срок / прочие
    $countOverdue = 0;
    $countOnTime  = 0;
    $countOthers  = 0;

    foreach ($requests as $r) {
        // Счётчик по статусам
        $st = $r['status'] ?? '—';
        $statusCounts[$st] = ($statusCounts[$st] ?? 0) + 1;

        // Счётчик по сменам
        $sh = $r['shift'] ?? '—';
        $shiftCounts[$sh] = ($shiftCounts[$sh] ?? 0) + 1;

        // Счётчик по типам заявок
        $tp = $r['type'] ?? '—';
        $typeCounts[$tp] = ($typeCounts[$tp] ?? 0) + 1;

        // Счётчик по исполнителям
        $ex = $r['executor'] ?? '—';
        $execCounts[$ex] = ($execCounts[$ex] ?? 0) + 1;

        // Счётчик по бригадам
        $br = $r['brigade'] ?? '—';
        $brigadeCounts[$br] = ($brigadeCounts[$br] ?? 0) + 1;

        // Логика просрочено/всрок/прочее
        $days = (int)($r['days_passed'] ?? 0);
        $curStatus = $r['status'] ?? '';
        if ($days > 10) {
            $countOverdue++;
        } elseif ($curStatus === 'completed' && $days <= 10) {
            $countOnTime++;
        } else {
            $countOthers++;
        }
    }

    // Для Chart.js
    $statusLabels = array_keys($statusCounts);
    $statusData   = array_values($statusCounts);

    $shiftLabels  = array_keys($shiftCounts);
    $shiftData    = array_values($shiftCounts);

    $typeLabels   = array_keys($typeCounts);
    $typeData     = array_values($typeCounts);

    $execLabels   = array_keys($execCounts);
    $execData     = array_values($execCounts);

    $brigadeLabels= array_keys($brigadeCounts);
    $brigadeData  = array_values($brigadeCounts);

    $overdueOnTimeLabels = ['Просрочено','В срок','Прочие'];
    $overdueOnTimeData   = [$countOverdue, $countOnTime, $countOthers];

    // (2) ФИШКА: Если просроченных заявок больше 10 - показываем "ПРЕДУПРЕЖДЕНИЕ" на экране или trigger special effect

    // Выводим HTML
    header("Content-Type: text/html; charset=utf-8");
    echo "<!DOCTYPE html><html lang='ru'><head>";
    echo "<meta charset='UTF-8'>";
    echo "<title>Супер-крутой дашборд</title>";
    echo "<script src='https://cdn.jsdelivr.net/npm/chart.js'></script>";

    // (3) ФИШКА: Подключим небольшую библиотеку для «конфетти» (можно взять готовую мини-библиотеку)
    // Для демонстрации вставим ниже JS c конфетти (в реальном проекте вы подключите внешний файл).

    echo "<script>
    // (минимальная демо-функция конфетти)
    function runConfetti() {
      const duration = 3000;
      const end = Date.now() + duration;
      (function frame() {
        confetti({
          particleCount: 6,
          angle: 60,
          spread: 55,
          origin: { x: 0 }
        });
        confetti({
          particleCount: 6,
          angle: 120,
          spread: 55,
          origin: { x: 1 }
        });
        if (Date.now() < end) {
          requestAnimationFrame(frame);
        }
      }());
    }
    </script>
    <script src='https://cdn.jsdelivr.net/npm/canvas-confetti@1.5.1/dist/confetti.browser.min.js'></script>";

    // (4) ФИШКА: Анимация при открытии дашборда, плюс стили "super-luxe"
    echo "<style>
      body {
        font-family: 'Montserrat', sans-serif;
        background: linear-gradient(135deg, #FDEB71, #F8D800);
        margin: 20px;
        color: #333;
        animation: fadeInBG 2s ease;
      }
      @keyframes fadeInBG {
        0% {opacity: 0;}
        100% {opacity: 1;}
      }
      h1, h2, h3 {
        margin-bottom: 0.5em;
        color: #C0392B;
      }

      /* (5) ФИШКА: Кнопка \"ПРЕДУПРЕЖДЕНИЕ\" будет красной */
      .btn {
        display: inline-block;
        padding: 10px 20px;
        background: #007bff;
        color: #fff;
        text-decoration: none;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        margin: 10px 5px 20px 0;
        transition: transform 0.2s, box-shadow 0.2s;
      }
      .btn:hover {
        background: #0056b3;
        transform: scale(1.05);
      }

      /* (6) ФИШКА: Небольшая тень у заголовков */
      h1 {
        text-shadow: 2px 2px 2px rgba(0,0,0,0.2);
      }

      /* Сетка для графиков */
      #chartsGrid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(450px, 1fr));
        gap: 30px;
        margin-bottom: 30px;
      }
      .chart-block {
        background: #fff;
        border: 2px solid #FAD7A0;
        border-radius: 10px;
        padding: 20px;
        box-shadow: 0 6px 12px rgba(0,0,0,0.1);
        position: relative;
        transition: transform 0.3s;
      }
      .chart-block:hover {
        transform: translateY(-5px);
      }
      .chart-block h3 {
        margin-bottom: 10px;
        color: #E74C3C;
      }
      .chart-block canvas {
        display: block;
        width: 100% !important;
        height: 350px !important;
        /* (7) ФИШКА: Лёгкая анимация появления */
        animation: fadeCanvas 1s ease;
      }
      @keyframes fadeCanvas {
        from {opacity: 0; transform: scale(0.95);}
        to {opacity: 1; transform: scale(1);}
      }

      /* (8) ФИШКА: Кнопка \"Развернуть\" для каждого графика (пример) */
      .expand-btn {
        position: absolute;
        top: 10px;
        right: 10px;
        background: #FFD700;
        color: #000;
        padding: 5px 8px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 0.8rem;
        transition: background 0.2s;
      }
      .expand-btn:hover {
        background: #FFC107;
      }

      /* (9) ФИШКА: При разворачивании графика - fullscreen modal (пример) */
      .chart-fullscreen {
        position: fixed;
        top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0,0,0,0.8);
        z-index: 9999;
        display: flex;
        justify-content: center;
        align-items: center;
      }
      .chart-fullscreen canvas {
        width: 90% !important;
        height: 80% !important;
      }
      .close-full-btn {
        position: absolute;
        top: 20px;
        right: 20px;
        background: #C0392B;
        color: #fff;
        padding: 10px 14px;
        border-radius: 6px;
        cursor: pointer;
        font-weight: bold;
      }

      #tableContainer {
        margin-top: 20px;
      }
      table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 10px;
        background: #fff;
        border-radius: 6px;
        overflow: hidden;
      }
      th, td {
        border: 1px solid #ccc;
        padding: 8px;
      }
      th {
        background: #f2f2f2;
      }
      /* (10) ФИШКА: Поиск в таблице */
      #searchInput {
        margin-bottom: 10px;
        padding: 5px 10px;
        width: 300px;
      }

      /* (11) ФИШКА: Плавная прокрутка при переходе (scroll-behavior) */
      html {
        scroll-behavior: smooth;
      }

      /* (12) ФИШКА: При наличии более 10 просроченных заявок - трясём заголовок! */
      ".($countOverdue > 10 ? "h1 { animation: shake 1s infinite alternate; }" : "")."
      @keyframes shake {
        0% { transform: translateX(0); }
        100% { transform: translateX(-10px); }
      }

      /* (13) ФИШКА: Hover info balloon (можно для подсказок) */
      .chart-block:hover::after {
        content: 'Кликните на график для фильтра!';
        position: absolute;
        bottom: 10px;
        left: 10px;
        font-size: 0.8rem;
        color: #333;
        background: #F9E79F;
        padding: 4px 8px;
        border-radius: 4px;
        opacity: 0.9;
      }
      .chart-block:hover::before {
        content: '';
        position: absolute;
        bottom: 3px;
        left: 15px;
        width: 0; height: 0;
        border: 5px solid transparent;
        border-top-color: #F9E79F;
      }
    </style>";

    echo "</head><body>";

    // (14) ФИШКА: При наличии более 10 просроченных заявок автоматически конфетти
    if ($countOverdue > 10) {
        echo "<script>document.addEventListener('DOMContentLoaded', runConfetti);</script>";
    }

    echo "<h1>Аналитика за период: "
         . htmlspecialchars($start_date, ENT_QUOTES, 'UTF-8')
         . " - "
         . htmlspecialchars($end_date, ENT_QUOTES, 'UTF-8')
         . "</h1>";

    // Кнопки
    echo "<button class='btn' onclick='window.print()'>Сформировать PDF</button>";
    echo "<button class='btn' onclick='resetFocus()'>Сброс фокуса</button>";

    // (15) ФИШКА: Поле поиска по таблице
    echo "<input type='text' id='searchInput' placeholder='Поиск в таблице...' oninput='searchInTable()'>";

    echo "<p><strong>Всего заявок: </strong>" . count($requests) . "</p>";

    // Сетка с диаграммами
    echo "<div id='chartsGrid'>";

    // Блоки графиков + кнопка «Развернуть»
    echo "<div class='chart-block'>
            <button class='expand-btn' onclick='expandChart(\"chartStatus\")'>Развернуть</button>
            <h3>По статусам</h3>
            <canvas id='chartStatus'></canvas>
          </div>";

    echo "<div class='chart-block'>
            <button class='expand-btn' onclick='expandChart(\"chartShift\")'>Развернуть</button>
            <h3>По сменам</h3>
            <canvas id='chartShift'></canvas>
          </div>";

    echo "<div class='chart-block'>
            <button class='expand-btn' onclick='expandChart(\"chartType\")'>Развернуть</button>
            <h3>По виду заявки</h3>
            <canvas id='chartType'></canvas>
          </div>";

    echo "<div class='chart-block'>
            <button class='expand-btn' onclick='expandChart(\"chartExec\")'>Развернуть</button>
            <h3>По исполнителям</h3>
            <canvas id='chartExec'></canvas>
          </div>";

    echo "<div class='chart-block'>
            <button class='expand-btn' onclick='expandChart(\"chartBrigade\")'>Развернуть</button>
            <h3>По бригадам</h3>
            <canvas id='chartBrigade'></canvas>
          </div>";

    echo "<div class='chart-block'>
            <button class='expand-btn' onclick='expandChart(\"chartOverdueOnTime\")'>Развернуть</button>
            <h3>Просрочено / В срок / Прочие</h3>
            <canvas id='chartOverdueOnTime'></canvas>
          </div>";

    echo "</div> <!-- /#chartsGrid -->";

    // Таблица под графиками
    echo "<div id='tableContainer'><h2>Детальный список</h2><div id='requestsTable'></div></div>";

    // Передаём заявки в JS
    $jsRequests = json_encode($requests, JSON_UNESCAPED_UNICODE);
    echo "<script>
    // Все заявки
    window.requestsData = $jsRequests;

    // Отрисовка таблицы
    function renderTable(rlist) {
      let html = '';
      if (!rlist || rlist.length === 0) {
        html = '<p>Нет заявок для отображения</p>';
      } else {
        html += '<table id=\"dataTable\"><tr>' +
          '<th>ID</th><th>Дата подачи</th><th>Смена</th>' +
          '<th>Статус</th><th>Исполнитель</th><th>Бригада</th>' +
          '<th>Вид</th><th>Длит.(дн)</th>' +
        '</tr>';
        for (let r of rlist) {
          html += '<tr>' +
            '<td>' + (r.id ?? '') + '</td>' +
            '<td>' + (r.date_submitted ?? '') + '</td>' +
            '<td>' + (r.shift ?? '') + '</td>' +
            '<td>' + (r.status ?? '') + '</td>' +
            '<td>' + (r.executor ?? '') + '</td>' +
            '<td>' + (r.brigade ?? '') + '</td>' +
            '<td>' + (r.type ?? '') + '</td>' +
            '<td>' + (r.days_passed ?? '') + '</td>' +
          '</tr>';
        }
        html += '</table>';
      }
      document.getElementById('requestsTable').innerHTML = html;
    }

    // Показываем все заявки изначально
    renderTable(window.requestsData);

    // Сброс фильтра графика
    function resetFocus() {
      document.getElementById('searchInput').value = '';
      renderTable(window.requestsData);
    }

    // Фильтр по статусу
    function filterByStatus(st) {
      return window.requestsData.filter(r => r.status === st);
    }
    function filterByShift(shVal) {
      return window.requestsData.filter(r => r.shift === shVal);
    }
    function filterByType(tpVal) {
      return window.requestsData.filter(r => r.type === tpVal);
    }
    function filterByExecutor(exVal) {
      return window.requestsData.filter(r => r.executor === exVal);
    }
    function filterByBrigade(brVal) {
      return window.requestsData.filter(r => r.brigade === brVal);
    }
    // 0=Просрочено,1=В срок,2=Прочие
    function filterOverdueOnTime(idx) {
      return window.requestsData.filter(r => {
        let d = parseInt(r.days_passed || '0');
        let st = r.status || '';
        if (idx === 0) { // просрочено
          return (d > 10);
        } else if (idx === 1) { // в срок
          return (st === 'completed' && d <= 10);
        } else {
          // прочие
          if (d > 10) return false;
          if (st === 'completed' && d <= 10) return false;
          return true;
        }
      });
    }

    // (10) ФИШКА (дополнено): Поиск в таблице
    function searchInTable() {
      let val = document.getElementById('searchInput').value.toLowerCase().trim();
      if (!val) {
        renderTable(window.requestsData);
        return;
      }
      let filtered = window.requestsData.filter(item => {
        // Пробегаемся по полям
        return (
          (item.id || '').toString().toLowerCase().includes(val) ||
          (item.status || '').toLowerCase().includes(val) ||
          (item.shift || '').toLowerCase().includes(val) ||
          (item.executor || '').toLowerCase().includes(val) ||
          (item.brigade || '').toLowerCase().includes(val) ||
          (item.type || '').toLowerCase().includes(val)
        );
      });
      renderTable(filtered);
    }

    // (8) - Разворачивание графика
    let currentFullscreen = null;
    function expandChart(chartId) {
      if (currentFullscreen) return; // уже развёрнут
      const chartCanvas = document.getElementById(chartId);
      const clone = chartCanvas.cloneNode(true);

      // Создаём оверлей
      const overlay = document.createElement('div');
      overlay.className = 'chart-fullscreen';
      const closeBtn = document.createElement('div');
      closeBtn.className = 'close-full-btn';
      closeBtn.textContent = 'X';
      closeBtn.onclick = () => {
        document.body.removeChild(overlay);
        currentFullscreen = null;
      };
      overlay.appendChild(clone);
      overlay.appendChild(closeBtn);
      document.body.appendChild(overlay);
      currentFullscreen = overlay;

      // Пересоздадим Chart.js
      new Chart(clone.getContext('2d'), {
        type: window[chartId + 'Obj'].config.type,
        data: window[chartId + 'Obj'].config.data,
        options: window[chartId + 'Obj'].config.options
      });
    }

    // Создаём диаграммы Chart.js
    const ctxStatus = document.getElementById('chartStatus').getContext('2d');
    window['chartStatusObj'] = new Chart(ctxStatus, {
      type: 'pie',
      data: {
        labels: " . json_encode($statusLabels, JSON_UNESCAPED_UNICODE) . ",
        datasets: [{
          data: " . json_encode($statusData) . ",
          backgroundColor: ['#FF6384','#36A2EB','#FFCE56','#4BC0C0','#9966FF','#FF9F40','#808080']
        }]
      },
      options: {
        responsive: true,
        onClick: function(evt, item) {
          if (item.length>0) {
            let idx = item[0].index;
            let clickedLabel = this.data.labels[idx];
            renderTable(filterByStatus(clickedLabel));
          }
        },
        // (15) ФИШКА: Анимация вращения
        animation: {
          animateRotate: true,
          duration: 1500
        }
      }
    });

    const ctxShift = document.getElementById('chartShift').getContext('2d');
    window['chartShiftObj'] = new Chart(ctxShift, {
      type: 'bar',
      data: {
        labels: " . json_encode($shiftLabels, JSON_UNESCAPED_UNICODE) . ",
        datasets: [{
          data: " . json_encode($shiftData) . ",
          backgroundColor: '#36b9cc',
          label: 'Смены'
        }]
      },
      options: {
        responsive: true,
        scales: { y:{ beginAtZero:true } },
        onClick: function(evt, item) {
          if (item.length>0) {
            let idx = item[0].index;
            let lab = this.data.labels[idx];
            renderTable(filterByShift(lab));
          }
        },
        animation: {
          duration: 1200,
          easing: 'easeOutBounce'
        }
      }
    });

    const ctxType = document.getElementById('chartType').getContext('2d');
    window['chartTypeObj'] = new Chart(ctxType, {
      type: 'doughnut',
      data: {
        labels: " . json_encode($typeLabels, JSON_UNESCAPED_UNICODE) . ",
        datasets: [{
          data: " . json_encode($typeData) . ",
          backgroundColor: ['#FF6384','#36A2EB','#FFCE56','#4BC0C0','#9966FF','#FF9F40','#808080']
        }]
      },
      options: {
        responsive: true,
        onClick: function(evt, item) {
          if (item.length>0) {
            let idx = item[0].index;
            let lab = this.data.labels[idx];
            renderTable(filterByType(lab));
          }
        },
        animation: {
          animateScale: true,
          duration: 1500
        }
      }
    });

    const ctxExec = document.getElementById('chartExec').getContext('2d');
    window['chartExecObj'] = new Chart(ctxExec, {
      type: 'bar',
      data: {
        labels: " . json_encode($execLabels, JSON_UNESCAPED_UNICODE) . ",
        datasets: [{
          data: " . json_encode($execData) . ",
          backgroundColor: '#F39C12',
          label: 'Исполнители'
        }]
      },
      options: {
        responsive: true,
        scales: { y:{ beginAtZero:true } },
        onClick: function(evt, item) {
          if (item.length>0) {
            let idx = item[0].index;
            let lab = this.data.labels[idx];
            renderTable(filterByExecutor(lab));
          }
        },
        animation: {
          duration: 1200,
          easing: 'easeInOutExpo'
        }
      }
    });

    const ctxBrigade = document.getElementById('chartBrigade').getContext('2d');
    window['chartBrigadeObj'] = new Chart(ctxBrigade, {
      type: 'pie',
      data: {
        labels: " . json_encode($brigadeLabels, JSON_UNESCAPED_UNICODE) . ",
        datasets: [{
          data: " . json_encode($brigadeData) . ",
          backgroundColor: ['#4e73df','#1cc88a','#36b9cc','#f6c23e','#e74a3b','#858796','#5a5c69']
        }]
      },
      options: {
        responsive: true,
        onClick: function(evt, item) {
          if (item.length>0) {
            let idx = item[0].index;
            let lab = this.data.labels[idx];
            renderTable(filterByBrigade(lab));
          }
        },
        animation: {
          animateRotate: true,
          duration: 1300
        }
      }
    });

    const ctxOOT = document.getElementById('chartOverdueOnTime').getContext('2d');
    window['chartOverdueOnTimeObj'] = new Chart(ctxOOT, {
      type: 'bar',
      data: {
        labels: " . json_encode($overdueOnTimeLabels, JSON_UNESCAPED_UNICODE) . ",
        datasets: [{
          data: " . json_encode($overdueOnTimeData) . ",
          backgroundColor: ['#e74a3b','#1cc88a','#858796'],
          label: 'Количество'
        }]
      },
      options: {
        responsive: true,
        scales: { y:{ beginAtZero:true } },
        onClick: function(evt, item) {
          if (item.length>0) {
            let idx = item[0].index;
            let filtered = filterOverdueOnTime(idx);
            renderTable(filtered);
          }
        },
        animation: {
          duration: 1400,
          easing: 'easeInOutBounce'
        }
      }
    });
    </script>";

    echo "</body></html>";
    exit;
}
/**
 * Назначить исполнителя (обновить executor, статус pending)
 */
function assignPositionById(int $id, string $executor): bool {
    $conn = getDBConnection();
    // Устанавливаем executor, меняем статус на 'pending'
    $stmt = $conn->prepare("UPDATE requests SET executor = ?, status = 'pending' WHERE id = ?");
    $stmt->bind_param("si", $executor, $id);
    $res = $stmt->execute();
    $stmt->close();

    if ($res) {
        // Получим данные позиции, чтобы отправить уведомление
        $pos = getPositionById($id);
        if ($pos && isset($pos['group_id'])) {
            // Уведомление исполнителю
            addNotification(
                $executor, 
                "Вам назначена позиция заявки № $id", 
                "?action=viewRequest&id=" . ($pos['group_id'] ?? $id)
            );
        }
    }
    $conn->close();
    return $res;
}


/**
 * Подтвердить выполнение (для технолога) — статус=completed
 */
function techConfirmPosition(int $positionId): bool {
    $conn = getDBConnection();

    // сначала получаем group_id переданной позиции
    $stmt0 = $conn->prepare("SELECT group_id FROM requests WHERE id = ?");
    $stmt0->bind_param("i", $positionId);
    $stmt0->execute();
    $stmt0->bind_result($groupId);
    if (!$stmt0->fetch()) {
        // позиция не найдена
        $stmt0->close();
        $conn->close();
        return false;
    }
    $stmt0->close();

    // теперь меняем статус и флаг archived у всех строк с этим group_id
    $stmt = $conn->prepare("
        UPDATE requests 
           SET status   = 'completed',
               archived = 1
         WHERE group_id = ?
    ");
    $stmt->bind_param("i", $groupId);
    $ok = $stmt->execute();

    $stmt->close();
    $conn->close();
    return $ok;
}



/**
 * Генерация Excel-отчёта
 */
function generateExcelReport(string $start_date, string $end_date, array $executors = [], array $statuses = []): void {
    $conn = getDBConnection();

    // 1. Собираем условия по датам
    $conds = [];
    if ($start_date !== '') {
        $sd = $conn->real_escape_string($start_date);
        $conds[] = "date_submitted >= '$sd'";
    }
    if ($end_date !== '') {
        $ed = $conn->real_escape_string($end_date);
        $conds[] = "date_submitted <= '$ed'";
    }
    $where = $conds ? 'WHERE '.implode(' AND ', $conds) : '';

    // 2. Фильтр по исполнителям
    if (!empty($executors)) {
        $esc = array_map([$conn, 'real_escape_string'], $executors);
        $where .= ($where ? ' AND ' : 'WHERE ')
                . "executor IN ('".implode("','", $esc)."')";
    }

    // 3. Фильтр по статусам
    if (!empty($statuses)) {
        $esc = array_map([$conn, 'real_escape_string'], $statuses);
        $where .= ($where ? ' AND ' : 'WHERE ')
                . "status IN ('".implode("','", $esc)."')";
    }

    // 4. Основные заявки (не отменённые)
    $sqlMain = "
        SELECT * FROM requests
        $where
          AND status NOT IN ('отмененная','deleted','cancelled')
        ORDER BY date_submitted DESC
    ";
    $mainRequests = $conn->query($sqlMain)->fetch_all(MYSQLI_ASSOC);

    // 5. Отменённые заявки
    $sqlCancelled = "
        SELECT * FROM requests
        $where
          AND status IN ('отмененная','deleted','cancelled')
        ORDER BY date_submitted DESC
    ";
    $cancelledRequests = $conn->query($sqlCancelled)->fetch_all(MYSQLI_ASSOC);

    $conn->close();

    // 6. Считаем метрики
    $totalMain       = count($mainRequests);
    $cancelledCount  = count($cancelledRequests);
    $overdue         = 0;
    $completedOnTime = 0;
    foreach ($mainRequests as $r) {
        $days = !empty($r['actual_time'])
            ? (new DateTime($r['date_submitted']))->diff(new DateTime($r['actual_time']))->days
            : getDaysPassed($r['date_submitted']);
        if ($days > 10) {
            $overdue++;
        }
        if (in_array($r['status'], ['completed','complete'], true) && $days <= 10) {
            $completedOnTime++;
        }
    }

    // 7. Маппинг столбцов и отображение статусов
    $columnMapping = [
        'id'                    => 'ID',
        'group_id'              => 'Группа',
        'created_by'            => 'Автор',
        'date_submitted'        => 'Дата подачи',
        'shift'                 => 'Смена',
        'cdng'                  => 'ЦДНГ',
        'ceh_krs'               => 'Цех КРС',
        'brigade'               => 'Бригада',
        'master'                => 'Мастер',
        'kust'                  => 'Куст',
        'skvazhina'             => 'Скважина',
        'type'                  => 'Вид заявки',
        'description'           => 'Описание',
        'responsible_executive' => 'Отв. исп.',
        'required_date'         => 'Треб. дата',
        'required_time'         => 'Треб. время',
        'executor'              => 'Исполнитель',
        'status'                => 'Статус',
        'actual_time'           => 'Факт. время',
        'note'                  => 'Примечание'
    ];
    $statusMap = [
        'new'                => 'Новая',
        'pending'            => 'В ожидании',
        'in_progress'        => 'В работе',
        'ready_for_dispatch' => 'Готово к отгрузке',
        'shipped'            => 'Отгружено',
        'completed'          => 'Завершена',
        'complete'           => 'Завершена',
        'deleted'            => 'Отменена',
        'cancelled'          => 'Отменена',
        'отмененная'         => 'Отменена'
    ];

    // 8. Заголовки HTTP + BOM
    header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"report_{$start_date}_{$end_date}.xls\"");
    header("Cache-Control: max-age=0");
    echo "\xEF\xBB\xBF";

    // 9. Начало HTML
    echo "<html><head><meta charset='UTF-8'><style>
        table{border-collapse:collapse;width:100%}
        th,td{border:1px solid #ccc;padding:6px;text-align:left}
        th{background:#f2f2f2}
        .overdue{background:#ffdddd}
        .summary{margin-bottom:20px}
    </style></head><body>";

    // 10. Заголовок и сводная таблица
    echo "<h1 style='text-align:center;'>Отчёт за период {$start_date} – {$end_date}</h1>";
    echo "<p style='text-align:center;'>Сформирован: ".date("Y-m-d H:i:s")."</p>";
    echo "<h2>Сводная информация</h2>";
    echo "<table class='summary'>
            <tr><th>Показатель</th><th>Кол-во</th></tr>
            <tr><td>Заявок (без отменённых)</td><td>{$totalMain}</td></tr>
            <tr><td>Исполнено в срок</td><td>{$completedOnTime}</td></tr>
            <tr><td>Просрочено (>10 дн.)</td><td>{$overdue}</td></tr>
            <tr><td>Отменено</td><td>{$cancelledCount}</td></tr>
          </table>";

    // 11. Основной список
    echo "<h2>Основной перечень</h2><table><tr>";
    foreach ($columnMapping as $col) {
        echo "<th>{$col}</th>";
    }
    echo "<th>Прошло дн.</th></tr>";
    foreach ($mainRequests as $r) {
        $days = !empty($r['actual_time'])
            ? (new DateTime($r['date_submitted']))->diff(new DateTime($r['actual_time']))->days
            : getDaysPassed($r['date_submitted']);
        $cls = $days > 10 ? " class='overdue'" : "";
        echo "<tr{$cls}>";
        foreach ($columnMapping as $k => $h) {
            $v = $r[$k] ?? '';
            if ($k === 'status' && isset($statusMap[$v])) {
                $v = $statusMap[$v];
            }
            echo "<td>".htmlspecialchars($v, ENT_QUOTES, "UTF-8")."</td>";
        }
        echo "<td>{$days}</td></tr>";
    }
    echo "</table>";

    // 12. Отменённые заявки
    echo "<h2>Отменённые заявки</h2>";
    if ($cancelledRequests) {
        echo "<table><tr>";
        foreach ($columnMapping as $col) {
            echo "<th>{$col}</th>";
        }
        echo "<th>Прошло дн.</th></tr>";
        foreach ($cancelledRequests as $r) {
            $days = !empty($r['actual_time'])
                ? (new DateTime($r['date_submitted']))->diff(new DateTime($r['actual_time']))->days
                : getDaysPassed($r['date_submitted']);
            echo "<tr>";
            foreach ($columnMapping as $k => $h) {
                $v = $r[$k] ?? '';
                if ($k === 'status' && isset($statusMap[$v])) {
                    $v = $statusMap[$v];
                }
                echo "<td>".htmlspecialchars($v, ENT_QUOTES, "UTF-8")."</td>";
            }
            echo "<td>{$days}</td></tr>";
        }
        echo "</table>";
    } else {
        echo "<p>Нет отменённых заявок за выбранный период.</p>";
    }

    // 13. Завершение HTML
    echo "</body></html>";
    exit;
}
