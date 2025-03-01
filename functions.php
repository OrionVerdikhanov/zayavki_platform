<?php
// ===============================================
// ==========  Файл: functions.php  ==============
// ===============================================

// Настройки подключения к базе данных
define('DB_HOST', 'localhost');         // Укажите свой хост
define('DB_USER', 'u2611449_zayavki');  // Имя пользователя БД
define('DB_PASS', 'u2611449_zayavki');  // Пароль к БД
define('DB_NAME', 'u2611449_zayavki');  // Название БД

/**
 * Подключение к MySQL (устанавливаем UTF-8)
 */
function getDBConnection(): mysqli {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        die("Ошибка подключения: " . $conn->connect_error);
    }
    // Включаем кодировку UTF-8
    $conn->set_charset("utf8");
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
function registerUser(string $username, string $password, string $role) {
    $conn = getDBConnection();
    // Проверка, есть ли пользователь с таким именем
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        $stmt->close();
        $conn->close();
        return "Пользователь с таким именем уже существует.";
    }
    $stmt->close();

    // Создаём нового пользователя
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $username, $hashedPassword, $role);
    if ($stmt->execute()) {
        $result = true;
    } else {
        $result = "Ошибка регистрации: " . $stmt->error;
    }
    $stmt->close();
    $conn->close();
    return $result;
}

/**
 * Получить список исполнителей (role='executor')
 */
function getExecutors(): array {
    $conn = getDBConnection();
    $sql = "SELECT id, username FROM users WHERE role = 'executor'";
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
    $stmt = $conn->prepare("INSERT INTO requests (date_submitted, shift, cdng, ceh_krs, brigade, master, kust, skvazhina, type, description, required_date, required_time, created_by) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
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

    $stmt->bind_param("sssssssssssss",
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
        // group_id = id (для связки позиций в одну заявку)
        $conn->query("UPDATE requests SET group_id = $newId WHERE id = $newId");
        $conn->close();

        // Уведомление начальникам, если заявку создал технолог
        if (isset($_SESSION['role']) && $_SESSION['role'] === 'technologist') {
            $chiefs = getChiefs();
            foreach ($chiefs as $chief) {
                addNotification(
                    $chief['username'], 
                    "Новая заявка создана от " . ($_SESSION['user'] ?? ''), 
                    "?action=viewRequest&id=" . $newId
                );
            }
        }
        return $newId;
    }
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
function getRequestsByFilterAdvanced(
    string $filter,
    array $advancedFilters,
    int $offset,
    int $limit,
    string $userRole = "",
    string $currentUser = ""
): array {
    $conn = getDBConnection();
    $conditions = [];

    // 1) Если filter != 'deleted', не показываем status='deleted'
    if ($filter !== 'deleted') {
        $conditions[] = "status <> 'deleted'";
    }

    // 2) Обработка специальных фильтров: waiting, overdue, archive, и т.д.
    if ($filter === 'waiting') {
        // Если технолог – отображаем те, где executor IS NOT NULL, статус НЕ completed/deleted
        if ($userRole === 'technologist') {
            $conditions[] = "executor IS NOT NULL";
            $conditions[] = "status NOT IN ('completed','deleted')";
        } else {
            // Для начальника, исполнителя: status IN (new, pending)
            $conditions[] = "status IN ('new','pending')";
        }
    } elseif ($filter === 'overdue') {
        // Просроченные > 10 дней
        $conditions[] = "DATEDIFF(CURDATE(), date_submitted) > 10";
    } elseif ($filter === 'archive') {
        // Архив = completed
        $conditions[] = "status = 'completed'";
    }
    // Можно добавить и другие фильтры (executed и т.д.), если нужно

    // 3) Если технолог (role='technologist') и filter == '' (то есть «Мои заявки»),
    //    исключаем 'completed', чтобы подтверждённые ему не показывались
    if ($userRole === 'technologist' && $filter === '') {
        $conditions[] = "status <> 'completed'";
    }

    // 4) Если исполнитель (role='executor') и filter == '' (его «Мои заявки»),
    //    исключаем completed, shipped (если хотим, чтобы «Отгружено» тоже не показывалось).
    //    Если хотите оставить shipped, уберите его.
    if ($userRole === 'executor' && $filter === '') {
        $conditions[] = "status NOT IN ('completed','shipped')";
    }

    // 5) Применяем поля расширенного фильтра (дата от, дата до, shift, cdng, и т.д.)
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

    // 6) Если роль=executor, показываем только заявки, где executor=текущий пользователь
    //    (если у вас такова логика «Мои заявки»)
    if ($userRole === 'executor' && !empty($currentUser)) {
        // Убедимся, что выводим именно его заявки
        $conditions[] = "executor = '" . $conn->real_escape_string($currentUser) . "'";
    }

    // 7) Собираем WHERE
    $where = "";
    if (count($conditions) > 0) {
        $where = " WHERE " . implode(" AND ", $conditions);
    }

    // 8) СОРТИРОВКА ПО УБЫВАНИЮ id => новые (с большим id) сверху
    $sql = "SELECT * FROM requests " . $where . " ORDER BY id DESC LIMIT $offset, $limit";

    $result = $conn->query($sql);
    $requests = [];
    while ($row = $result->fetch_assoc()) {
        $requests[] = $row;
    }
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
            $conditions[] = "status NOT IN ('completed','deleted')";
        } else {
            $conditions[] = "status IN ('new','pending')";
        }
    } elseif ($filter === 'overdue') {
        $conditions[] = "DATEDIFF(CURDATE(), date_submitted) > 10";
    } elseif ($filter === 'archive') {
        $conditions[] = "status = 'completed'";
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
    $sql = "SELECT *, DATEDIFF(required_date, CURDATE()) as days_left 
            FROM requests 
            WHERE executor = '" . $conn->real_escape_string($currentUser) . "' 
              AND required_date IS NOT NULL 
              AND DATEDIFF(required_date, CURDATE()) <= 3 
              AND status NOT IN ('completed','deleted')
            ORDER BY days_left ASC";
    $result = $conn->query($sql);
    $requests = [];
    while ($row = $result->fetch_assoc()){
        $requests[] = $row;
    }
    $conn->close();
    return $requests;
}

/**
 * Обновить статус всей заявки (по group_id), если нужно
 */
function updateRequestStatus(int $group_id, string $status): bool {
    // Примерная логика
    if ($_SESSION['role'] === 'executor' && $status === 'исполнено') {
        $status = 'awaiting_confirmation';
    }
    $conn = getDBConnection();
    $stmt = $conn->prepare("UPDATE requests SET status = ? WHERE group_id = ?");
    $stmt->bind_param("si", $status, $group_id);
    $res = $stmt->execute();
    $stmt->close();

    if ($res) {
        // уведомление автору
        $req = getRequestById($group_id);
        if ($req && isset($req[0]['created_by'])) {
            addNotification(
                $req[0]['created_by'], 
                "Статус заявки № $group_id изменён исполнителем", 
                "?action=viewRequest&id=" . $group_id
            );
        }
    }
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
function updatePositionStatusById(int $id, string $status): bool {
    // Если исполнитель выбрал «исполнено», можно менять на 'awaiting_confirmation' — если нужно
    if ($_SESSION['role'] === 'executor' && $status === 'исполнено') {
        $status = 'awaiting_confirmation';
    }

    // Подготовка для записи actual_time, если статус = shipped / completed
    // (подстройте под вашу логику, например только при shipped)
    $setActualTime = in_array($status, ['shipped','completed']);

    $conn = getDBConnection();
    if ($setActualTime) {
        // Запишем actual_time = NOW() при shipped/completed
        $stmt = $conn->prepare("UPDATE requests 
                                SET status = ?, actual_time = NOW() 
                                WHERE id = ?");
        $stmt->bind_param("si", $status, $id);
    } else {
        $stmt = $conn->prepare("UPDATE requests 
                                SET status = ? 
                                WHERE id = ?");
        $stmt->bind_param("si", $status, $id);
    }

    $res = $stmt->execute();
    $stmt->close();

    if ($res) {
        $pos = getPositionById($id);
        if ($pos && isset($pos['created_by'])) {
            addNotification(
                $pos['created_by'], 
                "Статус позиции № $id изменён исполнителем", 
                "?action=viewRequest&id=" . ($pos['group_id'] ?? $id)
            );
        }
    }
    $conn->close();
    return $res;
}

function showTechChiefDashboard(string $start_date, string $end_date) {
    $conn = getDBConnection();

    // Собираем WHERE по датам
    $conditions = [];
    if (!empty($start_date)) {
        $conditions[] = "date_submitted >= '" . $conn->real_escape_string($start_date) . "'";
    }
    if (!empty($end_date)) {
        $conditions[] = "date_submitted <= '" . $conn->real_escape_string($end_date) . "'";
    }
    $where = "";
    if ($conditions) {
        $where = "WHERE " . implode(" AND ", $conditions);
    }

    // Выбираем все заявки за период
    $sql = "SELECT * FROM requests $where ORDER BY date_submitted ASC";
    $result = $conn->query($sql);

    $requests = [];
    while ($row = $result->fetch_assoc()) {
        // days_passed: либо разница между date_submitted и actual_time (если есть),
        // иначе — между date_submitted и текущим временем.
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

    // Подсчёт для разных графиков
    $statusCounts  = [];
    $shiftCounts   = [];
    $typeCounts    = [];
    $execCounts    = [];
    $brigadeCounts = [];

    // Для просроченных/в-срок/прочие
    $countOverdue = 0;
    $countOnTime  = 0;
    $countOthers  = 0;

    foreach ($requests as $r) {
        // STATUS
        $st = $r['status'] ?? '—';
        if (!isset($statusCounts[$st])) {
            $statusCounts[$st] = 0;
        }
        $statusCounts[$st]++;

        // SHIFT
        $sh = $r['shift'] ?? '—';
        if (!isset($shiftCounts[$sh])) {
            $shiftCounts[$sh] = 0;
        }
        $shiftCounts[$sh]++;

        // TYPE
        $tp = $r['type'] ?? '—';
        if (!isset($typeCounts[$tp])) {
            $typeCounts[$tp] = 0;
        }
        $typeCounts[$tp]++;

        // EXECUTOR
        $ex = $r['executor'] ?? '—';
        if (!isset($execCounts[$ex])) {
            $execCounts[$ex] = 0;
        }
        $execCounts[$ex]++;

        // BRIGADE
        $br = $r['brigade'] ?? '—';
        if (!isset($brigadeCounts[$br])) {
            $brigadeCounts[$br] = 0;
        }
        $brigadeCounts[$br]++;

        // daysPassed >10 => просрочка,
        // status='completed' && days<=10 => выполнено в срок,
        // иначе => прочие
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

    // Формируем JS-массивы для Chart.js
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

    // Начинаем вывод HTML
    header("Content-Type: text/html; charset=utf-8");
    echo "<!DOCTYPE html><html lang='ru'><head>";
    echo "<meta charset='UTF-8'>";
    echo "<title>Аналитика (расширенная)</title>";
    echo "<script src='https://cdn.jsdelivr.net/npm/chart.js'></script>";
    // Стили для flex-контейнера, чтобы графики не наслаивались друг на друга
    echo "<style>
      body {
        font-family: sans-serif; 
        margin: 20px;
      }
      h1, h2 {
        margin-bottom: 10px;
      }
      .pdf-button {
        padding: 10px 20px; 
        background: #007bff; 
        color: #fff; 
        border: none; 
        cursor: pointer;
        margin-bottom: 20px;
      }
      .pdf-button:hover {
        background: #0056b3;
      }

      /* Контейнер для графиков: flex, перенос, выравнивание по центру */
      #chartsContainer {
        display: flex;
        flex-wrap: wrap;
        gap: 30px;
        justify-content: center;
      }
      .chart-block {
        background: #fff;
        border: 1px solid #eee;
        border-radius: 8px;
        padding: 10px;
        width: 480px; /* фиксированная ширина для каждого блока */
        text-align: center;
      }
      /* Канвас растягивается на всю ширину блока */
      .chart-block canvas {
        width: 450px !important;
        height: 300px !important;
      }

      /* Таблица */
      #tableContainer {
        margin-top: 40px;
      }
      table {
        border-collapse: collapse;
        width: 100%;
        margin-top: 10px;
      }
      th, td {
        border: 1px solid #ccc;
        padding: 8px;
      }
      th {
        background: #f2f2f2;
      }
    </style>";
    echo "</head><body>";

    echo "<h1>Аналитика за период: " 
         . htmlspecialchars($start_date, ENT_QUOTES, 'UTF-8') 
         . " - " 
         . htmlspecialchars($end_date, ENT_QUOTES, 'UTF-8') 
         . "</h1>";

    // Кнопка для PDF (print)
    echo "<button class='pdf-button' onclick='window.print()'>Сформировать PDF</button>";

    echo "<p><strong>Всего заявок:</strong> " . count($requests) . "</p>";

    // Flex-контейнер с графиками
    echo "<div id='chartsContainer'>";

    // 1) chartStatus
    echo "<div class='chart-block'><h3>По статусам</h3><canvas id='chartStatus'></canvas></div>";
    // 2) chartShift
    echo "<div class='chart-block'><h3>По сменам</h3><canvas id='chartShift'></canvas></div>";
    // 3) chartType
    echo "<div class='chart-block'><h3>По виду заявки</h3><canvas id='chartType'></canvas></div>";
    // 4) chartExec
    echo "<div class='chart-block'><h3>По исполнителям</h3><canvas id='chartExec'></canvas></div>";
    // 5) chartBrigade
    echo "<div class='chart-block'><h3>По бригадам</h3><canvas id='chartBrigade'></canvas></div>";
    // 6) chartOverdueOnTime
    echo "<div class='chart-block'><h3>Просрочено / В срок / Прочие</h3><canvas id='chartOverdueOnTime'></canvas></div>";

    echo "</div>"; // #chartsContainer

    // Контейнер, куда будем выводить таблицу (JS)
    echo "<div id='tableContainer'><h2>Детальный список</h2><div id='requestsTable'></div></div>";

    // Подготовим весь массив заявок в JS
    $jsRequests = json_encode($requests, JSON_UNESCAPED_UNICODE);
    echo "<script>
    window.requestsData = $jsRequests;

    function renderTable(rlist) {
      let html = '';
      if (!rlist || rlist.length === 0) {
        html = '<p>Нет заявок для отображения</p>';
      } else {
        html += '<table><tr>' +
          '<th>ID</th><th>Дата подачи</th><th>Смена</th><th>Статус</th>' +
          '<th>Исполнитель</th><th>Бригада</th><th>Вид</th>' +
          '<th>Длит. (дней)</th>' +
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

    // Фильтры
    function filterByStatus(status) {
      return window.requestsData.filter(r => (r.status === status));
    }
    function filterByShift(shiftVal) {
      return window.requestsData.filter(r => (r.shift === shiftVal));
    }
    function filterByType(typeVal) {
      return window.requestsData.filter(r => (r.type === typeVal));
    }
    function filterByExecutor(execVal) {
      return window.requestsData.filter(r => (r.executor === execVal));
    }
    function filterByBrigade(bVal) {
      return window.requestsData.filter(r => (r.brigade === bVal));
    }
    // 0=Просрочено, 1=В срок, 2=Прочие
    function filterOverdueOnTime(idx) {
      return window.requestsData.filter(r => {
        let d = parseInt(r.days_passed ?? '0');
        let st = r.status ?? '';
        if (idx === 0) {
          return (d > 10);
        } else if (idx === 1) {
          return (st==='completed' && d <=10);
        } else {
          // прочее
          if (d>10) return false;
          if (st==='completed' && d<=10) return false;
          return true;
        }
      });
    }

    // При загрузке страницы — выводим все
    renderTable(window.requestsData);

    // Теперь создаём графики
    const ctxStatus = document.getElementById('chartStatus').getContext('2d');
    const chartStatus = new Chart(ctxStatus, {
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
            const filtered = filterByStatus(clickedLabel);
            renderTable(filtered);
          }
        }
      }
    });

    // SHIFT
    const ctxShift = document.getElementById('chartShift').getContext('2d');
    const chartShift = new Chart(ctxShift, {
      type: 'bar',
      data: {
        labels: " . json_encode($shiftLabels, JSON_UNESCAPED_UNICODE) . ",
        datasets: [{
          label: 'Смены',
          data: " . json_encode($shiftData) . ",
          backgroundColor: '#36b9cc'
        }]
      },
      options: {
        responsive: true,
        scales: { y:{ beginAtZero:true } },
        onClick: function(evt, item) {
          if (item.length>0) {
            let idx = item[0].index;
            let clickedLabel = this.data.labels[idx];
            renderTable(filterByShift(clickedLabel));
          }
        }
      }
    });

    // TYPE
    const ctxType = document.getElementById('chartType').getContext('2d');
    const chartType = new Chart(ctxType, {
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
            let clickedLabel = this.data.labels[idx];
            renderTable(filterByType(clickedLabel));
          }
        }
      }
    });

    // EXECUTOR
    const ctxExec = document.getElementById('chartExec').getContext('2d');
    const chartExec = new Chart(ctxExec, {
      type: 'bar',
      data: {
        labels: " . json_encode($execLabels, JSON_UNESCAPED_UNICODE) . ",
        datasets: [{
          label: 'Исполнитель',
          data: " . json_encode($execData) . ",
          backgroundColor: '#F39C12'
        }]
      },
      options: {
        responsive: true,
        scales: { y:{ beginAtZero:true } },
        onClick: function(evt, item) {
          if (item.length>0) {
            let idx = item[0].index;
            let clickedLabel = this.data.labels[idx];
            renderTable(filterByExecutor(clickedLabel));
          }
        }
      }
    });

    // BRIGADE
    const ctxBrigade = document.getElementById('chartBrigade').getContext('2d');
    const chartBrigade = new Chart(ctxBrigade, {
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
            let clickedLabel = this.data.labels[idx];
            renderTable(filterByBrigade(clickedLabel));
          }
        }
      }
    });

    // OVERDUE/ONTIME/OTHER
    const ctxOOT = document.getElementById('chartOverdueOnTime').getContext('2d');
    const chartOverdueOnTime = new Chart(ctxOOT, {
      type: 'bar',
      data: {
        labels: " . json_encode($overdueOnTimeLabels, JSON_UNESCAPED_UNICODE) . ",
        datasets: [{
          label: 'Количество',
          data: " . json_encode($overdueOnTimeData) . ",
          backgroundColor: ['#e74a3b','#1cc88a','#858796']
        }]
      },
      options: {
        responsive: true,
        scales: { y:{ beginAtZero:true } },
        onClick: function(evt, item) {
          if (item.length>0) {
            let idx = item[0].index;
            renderTable(filterOverdueOnTime(idx));
          }
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
    $stmt = $conn->prepare("UPDATE requests SET status = 'completed' WHERE id = ? AND status NOT IN ('completed','deleted')");
    $stmt->bind_param("i", $positionId);
    $res = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $res;
}


/**
 * Генерация Excel-отчёта
 */
function generateExcelReport(string $start_date, string $end_date, string $criteria) {
    $conn = getDBConnection();
    $conditions = [];
    if (!empty($start_date)) {
        $conditions[] = "date_submitted >= '" . $conn->real_escape_string($start_date) . "'";
    }
    if (!empty($end_date)) {
        $conditions[] = "date_submitted <= '" . $conn->real_escape_string($end_date) . "'";
    }
    // Доп. критерии (over10, over3, completed)
    if ($criteria === 'over10') {
        $conditions[] = "DATEDIFF(CURDATE(), date_submitted) >= 10";
    } elseif ($criteria === 'over3') {
        $conditions[] = "DATEDIFF(CURDATE(), date_submitted) >= 3";
    } elseif ($criteria === 'completed') {
        $conditions[] = "status = 'completed'";
    }
    $where = "";
    if (count($conditions) > 0) {
        $where = " WHERE " . implode(" AND ", $conditions);
    }

    // Запрашиваем все заявки (requests)
    $sql = "SELECT * FROM requests" . $where . " ORDER BY date_submitted DESC";
    $result = $conn->query($sql);
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        // days_passed показываем «сколько прошло от date_submitted до СЕЙЧАС» (если actual_time нет),
        // либо «сколько прошло от date_submitted до actual_time» (если actual_time есть).
        if (!empty($row['actual_time'])) {
            // Разница между date_submitted и actual_time
            $dtStart = new DateTime($row['date_submitted']);
            $dtEnd   = new DateTime($row['actual_time']);
            $interval = $dtStart->diff($dtEnd);
            $row['days_passed'] = $interval->days;
        } else {
            // Если фактического времени нет, считаем до текущего момента
            $row['days_passed'] = getDaysPassed($row['date_submitted']);
        }
        $rows[] = $row;
    }
    $conn->close();

    // Маппинг полей в заголовки Excel
    $columnMapping = [
        'id' => 'ID',
        'group_id' => 'Номер группы',
        'created_by' => 'Автор',
        'date_submitted' => 'Дата подачи',
        'shift' => 'Смена',
        'cdng' => 'ЦДНГ',
        'ceh_krs' => 'Цех КРС',
        'brigade' => 'Бригада',
        'master' => 'Мастер',
        'kust' => 'Куст',
        'skvazhina' => 'Скважина',
        'type' => 'Вид заявки',
        'description' => 'Описание',
        'responsible_executive' => 'Ответственный исполнитель',
        'required_date' => 'Требуемая дата',
        'required_time' => 'Требуемое время',
        'executor' => 'Исполнитель',
        'status' => 'Статус',
        'actual_time' => 'Фактическое время',
        'note' => 'Примечание',
        'days_passed' => 'Прошло дней'
    ];

    // Подсчёт для дашборда
    $total = count($rows);
    $overdue = 0;
    $completedOnTime = 0;

    foreach ($rows as $r) {
        // days_passed уже рассчитан
        $daysInExecution = $r['days_passed'] ?? 0;
        $status = $r['status'] ?? '';

        // Если заявка фактически исполнена (либо shipped, либо completed) – зависит от вашей логики.
        // Предположим, «просрочка» считаем, если daysInExecution > 10
        //             «выполнено в срок» если daysInExecution <= 10 и status='completed'
        //             (или status in('completed','shipped'), подстраивайте как нужно)
        
        if ($daysInExecution > 10) {
            $overdue++;
        }
        // Если статус=completed, daysInExecution <=10 => «выполнено в срок»
        if ($status === 'completed' && $daysInExecution <= 10) {
            $completedOnTime++;
        }
    }

    // Вывод Excel
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=report.xls");
    header("Cache-Control: max-age=0");
    // BOM
    echo "\xEF\xBB\xBF";

    echo "<html><head><meta charset='UTF-8'></head><body>";
    echo "<h2>Отчет за период " 
         . htmlspecialchars($start_date, ENT_QUOTES, 'UTF-8') 
         . " до " 
         . htmlspecialchars($end_date, ENT_QUOTES, 'UTF-8') 
         . "</h2>";

    if (!empty($rows)) {
        echo "<table border='1'>";
        // Заголовки
        echo "<tr>";
        foreach ($columnMapping as $colKey => $colName) {
            echo "<th>" . htmlspecialchars($colName, ENT_QUOTES, 'UTF-8') . "</th>";
        }
        echo "</tr>";
        // Данные
        foreach ($rows as $r) {
            echo "<tr>";
            foreach ($columnMapping as $colKey => $colName) {
                echo "<td>" 
                     . htmlspecialchars((string)($r[$colKey] ?? ''), ENT_QUOTES, 'UTF-8') 
                     . "</td>";
            }
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>Данных не найдено</p>";
    }

    // Дашборд
    echo "<h3>Дашборд</h3>";
    echo "<table border='1'>";
    echo "<tr><td>Всего заявок</td><td>" 
         . htmlspecialchars((string)$total, ENT_QUOTES, 'UTF-8') . "</td></tr>";
    echo "<tr><td>Просрочено (days > 10)</td><td>" 
         . htmlspecialchars((string)$overdue, ENT_QUOTES, 'UTF-8') . "</td></tr>";
    echo "<tr><td>Выполнено в срок (status=completed, days <=10)</td><td>" 
         . htmlspecialchars((string)$completedOnTime, ENT_QUOTES, 'UTF-8') . "</td></tr>";
    echo "</table>";

    // Пример графика (Chart.js)
    echo "<h3>Инфографика</h3>";
    echo "<canvas id='chartDashboard' width='400' height='200'></canvas>";
    echo "<script>
        const ctx = document.getElementById('chartDashboard').getContext('2d');
        const chart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: ['Всего', 'Просрочено', 'Выполнено в срок'],
                datasets: [{
                    label: 'Количество заявок',
                    data: " . json_encode([$total, $overdue, $completedOnTime]) . ",
                    backgroundColor: ['#4e73df', '#e74a3b', '#1cc88a']
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false },
                    title: { display: true, text: 'Статистика заявок' }
                }
            }
        });
    </script>";
    echo "</body></html>";
    exit;
}
