<?php


session_start();
define('ROLE_ANALYTIC', 'аналитик');
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
require __DIR__ . '/vendor/autoload.php';
include_once 'functions.php';


$action = $_REQUEST['action'] ?? 'home';
$role = $_SESSION['role'] ?? '';
$isShiftLead = in_array($role, ['chief', 'cits']);
$isAnalyst = ($role === ROLE_ANALYTIC);


// Обработка AJAX inline-редактирования
if ($action === 'edit_field' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id    = intval($_POST['equipment_id'] ?? 0);
    $field = trim($_POST['field_name']   ?? '');
    $value = trim($_POST['new_value']    ?? '');
    if ($id > 0 && updatePositionField($id, $field, $value)) {
        // Можно вернуть JSON или просто текст
        echo json_encode(['status' => 'ok']);
    } else {
        http_response_code(400);
        echo json_encode(['status' => 'error']);
    }
    exit;
}
/* ---------- КОММЕНТАРИИ ---------- */
// получить список
if ($action === 'get_comments' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $posId = intval($_GET['position_id'] ?? 0);
    echo json_encode(getComments($posId)); // getComments(int $id): array
    exit;
}
// добавить новый
if ($action === 'add_comment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // берем ID группы (чтобы вернуться обратно) и ID позиции
    $groupId     = intval($_POST['group_id'] ?? 0);
    $positionId  = intval($_POST['position_id'] ?? 0);
    $text        = trim($_POST['comment'] ?? '');
    $user        = $_SESSION['user'] ?? '';

    if (addComment($positionId, $user, $text)) {
        // после успешного добавления — возвращаемся к просмотру заявки
        header('Location: index.php?action=viewRequest&id=' . $groupId);
        exit;
    } else {
        echo "<p class='error'>Ошибка при сохранении комментария.</p>";
        exit;
    }
}


// Обработка регистрации
if ($action === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');
    $role = trim($_POST['role'] ?? '');
    
    if ($password !== $confirm_password) {
        $regError = "Пароли не совпадают.";
    } else {
        // Получаем значение структурного подразделения (только для исполнителя)
        $struct_division = ($role === 'executor') ? trim($_POST['struct_division'] ?? '') : '';
        // Передаём пустые строки для полей fullname, position, email, phone
        $result = registerUser($username, $password, $role, '', '', '', '', $struct_division);
        if ($result === true) {
            $_SESSION['toast_message'] = "Регистрация прошла успешно. Теперь вы можете войти.";
            $_SESSION['toast_type'] = "success";
            header("Location: index.php");
            exit;
        } else {
            $regError = $result;
        }
    }
}

// Обработка логина
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $user = loginUser($username, $password);
    if ($user) {
        $_SESSION['user'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['user_id'] = $user['id'];
        header("Location: index.php");
        exit;
    } else {
        $loginError = "Неверное имя пользователя или пароль.";
    }
}



// Выход из системы
if ($action === 'logout') {
    session_destroy();
    header("Location: index.php");
    exit;
}

// Обработка отмены заявки (для технолога)
if ($action === 'cancelRequest') {
    if ($_SESSION['role'] !== 'technologist') {
        echo "<p class='error'>Доступ запрещен.</p>";
        exit;
    }
    $groupId = intval($_GET['id'] ?? 0);
    if (!$groupId) {
        echo "<p class='error'>Не указан идентификатор заявки.</p>";
        exit;
    }
    $message = cancelRequest($groupId);
    header("Location: index.php?message=" . urlencode($message));
    exit;
}

// Обработка редактирования заявки (для технолога)
if ($action === 'editRequest') {
    if ($_SESSION['role'] !== 'technologist') {
        echo "<p class='error'>Доступ запрещен.</p>";
        exit;
    }
    $groupId = intval($_GET['id'] ?? 0);
    if (!$groupId) {
        echo "<p class='error'>Не указан идентификатор заявки.</p>";
        exit;
    }
    $requestPositions = getRequestById($groupId);
    if (empty($requestPositions)) {
        echo "<p class='error'>Заявка не найдена.</p>";
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = [
            'date_submitted' => trim($_POST['date_submitted'] ?? ''),
            'shift'          => trim($_POST['shift'] ?? ''),
            'cdng'           => trim($_POST['cdng'] ?? ''),
            'ceh_krs'        => trim($_POST['ceh_krs'] ?? ''),
            'brigade'        => trim($_POST['brigade'] ?? ''),
            'master'         => trim($_POST['master'] ?? ''),
            'kust'           => trim($_POST['kust'] ?? ''),
            'skvazhina'      => trim($_POST['skvazhina'] ?? ''),
            'type'           => trim($_POST['type'] ?? ''),
            'description'    => trim($_POST['description'] ?? ''),
            'required_date'  => '',
            'required_time'  => '',
        ];
        if (updateRequest($groupId, $data)) {
            echo renderToastNotification("Заявка № $groupId успешно обновлена.", "success");
        } else {
            echo renderToastNotification("Ошибка при обновлении заявки.", "error");
        }
        exit;
    } else {
        $firstPosition = $requestPositions[0];
        ?>
        <!DOCTYPE html>
        <html lang="ru">
        <head>
            <meta charset="UTF-8">
            <title>Редактирование заявки № <?php echo htmlspecialchars($groupId, ENT_QUOTES, 'UTF-8'); ?></title>
            <link rel="stylesheet" href="style.css">
        </head>
        <body>
            <h2>Редактирование заявки № <?php echo htmlspecialchars($groupId, ENT_QUOTES, 'UTF-8'); ?></h2>
            <form method="post" action="?action=editRequest&id=<?php echo htmlspecialchars($groupId, ENT_QUOTES, 'UTF-8'); ?>">
                <label>Дата подачи:<br>
                    <input type="date" name="date_submitted" value="<?php echo htmlspecialchars($firstPosition['date_submitted'], ENT_QUOTES, 'UTF-8'); ?>" required>
                </label>
                <label>Смена:<br>
                    <input type="text" name="shift" value="<?php echo htmlspecialchars($firstPosition['shift'], ENT_QUOTES, 'UTF-8'); ?>" required>
                </label>
                <label>ЦДНГ:<br>
                    <input type="text" name="cdng" value="<?php echo htmlspecialchars($firstPosition['cdng'], ENT_QUOTES, 'UTF-8'); ?>" required>
                </label>
                <label>Цех КРС:<br>
                    <input type="text" name="ceh_krs" value="<?php echo htmlspecialchars($firstPosition['ceh_krs'], ENT_QUOTES, 'UTF-8'); ?>" required>
                </label>
                <label>Бригада:<br>
                    <input type="text" name="brigade" value="<?php echo htmlspecialchars($firstPosition['brigade'], ENT_QUOTES, 'UTF-8'); ?>" required>
                </label>
                <label>Мастер:<br>
                    <input type="text" name="master" value="<?php echo htmlspecialchars($firstPosition['master'], ENT_QUOTES, 'UTF-8'); ?>" required>
                </label>
                <label>Куст:<br>
                    <input type="text" name="kust" value="<?php echo htmlspecialchars($firstPosition['kust'], ENT_QUOTES, 'UTF-8'); ?>" required>
                </label>
                <label>Скважина:<br>
                    <input type="text" name="skvazhina" value="<?php echo htmlspecialchars($firstPosition['skvazhina'], ENT_QUOTES, 'UTF-8'); ?>" required>
                </label>
                <label>Вид заявки:<br>
                    <input type="text" name="type" value="<?php echo htmlspecialchars($firstPosition['type'], ENT_QUOTES, 'UTF-8'); ?>" required>
                </label>
                <label>Наименование:<br>
                    <input type="text" name="description" value="<?php echo htmlspecialchars($firstPosition['description'], ENT_QUOTES, 'UTF-8'); ?>" required>
                </label>
                
                <br><br>
                <input type="submit" value="Сохранить изменения">
            </form>
        















<style>
  th{position:relative;}
  .filter-icon{
    position:absolute;
    bottom:2px;
    right:4px;
    font-size:12px;
    color:#666;
    cursor:pointer;
    user-select:none;
    opacity:0.7;
  }
  th.filtered .filter-icon{color:#0d6efd;opacity:1;}
  #resetFilterBtn{
    padding:6px 12px;
    margin:6px 0;
    background:#f8f9fa;
    border:1px solid #ccc;
    border-radius:4px;
    cursor:pointer;
    font-size:0.9rem;
  }
</style>
<script>
(function(){
  const DATE_FIELDS = ['date_submitted','required_date','actual_time'];
  const TIME_FIELDS = ['required_time'];
  const STATUS_MAP = {
    'new':'Новая','pending':'В ожидании','in_progress':'В работе',
    'ready_for_dispatch':'Готово к отправке','shipped':'Отправлено',
    'completed':'Завершена','deleted':'Удалена','отмененная':'Отменена'
  };

  const isoToRu = iso => {
    if(!/^\d{4}-\d{2}-\d{2}$/.test(iso)) return iso;
    const [y,m,d] = iso.split('-');
    return `${d}.${m}.${y}`;
  };
  const ruToIso = ru => {
    if(!/^\d{2}\.\d{2}\.\d{4}$/.test(ru)) return ru;
    const [d,m,y] = ru.split('.');
    return `${y}-${m}-${d}`;
  };

  function localizeCell(cell){
    const txt = cell.textContent.trim();
    if(/^\d{4}-\d{2}-\d{2}$/.test(txt)){
      cell.textContent = isoToRu(txt);
    } else if(STATUS_MAP[txt]){
      cell.textContent = STATUS_MAP[txt];
    }
  }
  function localizeTable(table){
    if(!table) return;
    table.querySelectorAll('td').forEach(localizeCell);
  }

  window.openInlineEditor = function(cell){
    const field = cell.dataset.field;
    let val = cell.textContent.trim();
    const input = document.getElementById('inline_new_value');

    if (DATE_FIELDS.includes(field)) {
      input.type = 'date';
      val = val.includes('.') ? ruToIso(val) : val;
    }
    else if (TIME_FIELDS.includes(field)) {
      input.type = 'time';
    }
    else {
      input.type = 'text';
    }

    input.value = val;
    document.getElementById('inline_equipment_id').value = cell.dataset.id;
    document.getElementById('inline_field_name').value   = field;
    document.getElementById('inline_label').textContent  = 'Изменить ' + field + ':';
    document.getElementById('modalInline').style.display = 'block';
  };

  document.addEventListener('DOMContentLoaded', ()=>{
    const form = document.getElementById('inlineEditForm');
    form.addEventListener('submit', ()=>{
      setTimeout(()=>{
        localizeTable(document.getElementById('requestsTable'));
        document.getElementById('modalInline').style.display = 'none';
      }, 200);
    });

    document.getElementById('closeInlineModal').addEventListener('click', ()=>{
      document.getElementById('modalInline').style.display = 'none';
    });
    window.addEventListener('click', e => {
      if (e.target === document.getElementById('modalInline')) {
        document.getElementById('modalInline').style.display = 'none';
      }
    });
  });

  function enableColumnFiltering(tableId){
    const table = document.getElementById(tableId);
    if(!table) return;
    const headers = [...(table.tHead?.rows[0]||table.rows[0]).cells];
    let filters = JSON.parse(localStorage.getItem('columnFilters_'+tableId)||'{}');

    headers.forEach((th, i)=>{
      if(!th.querySelector('.filter-icon')){
        const ic = document.createElement('span');
        ic.className = 'filter-icon';
        ic.textContent = '🔍';
        ic.title = 'Фильтр';
        ic.onclick = e=>{ e.stopPropagation(); openFilter(i); };
        th.appendChild(ic);
      }
    });

    function openFilter(col){
      const cur = filters[col]||'';
      const val = prompt(
        cur ? `Текущий: "${cur}". Введите новый или пусто для сброса:` : 'Введите значение фильтра:',
        cur
      );
      if(val===null) return;
      if(val.trim()==='') delete filters[col];
      else filters[col] = val.trim();
      localStorage.setItem('columnFilters_'+tableId, JSON.stringify(filters));
      apply();
    }

    function apply(){
      [...table.rows].forEach(row=>{
        if(row.querySelector('th')) return;
        let show = true;
        for(const [c,v] of Object.entries(filters)){
          const txt = row.cells[c]?.textContent.toLowerCase()||'';
          if(!txt.includes(v.toLowerCase())){ show=false; break; }
        }
        row.style.display = show?'':'none';
      });
      headers.forEach((th,i)=> th.classList.toggle('filtered', !!filters[i]));
    }

    if(!document.getElementById('resetFilterBtn')){
      const btn = document.createElement('button');
      btn.id = 'resetFilterBtn';
      btn.textContent = 'Сбросить фильтры';
      btn.onclick = ()=>{
        filters = {}; localStorage.removeItem('columnFilters_'+tableId);
        apply();
      };
      table.parentNode.insertBefore(btn, table);
    }

    apply();
  }

  window.addEventListener('load', ()=>{
    localizeTable(document.getElementById('requestsTable'));
    enableColumnFiltering('requestsTable');
  });
})();
</script>





</body>
        </html>
        <?php
        exit;
    }
}

// Вывод toast-уведомлений, если они были установлены
if (isset($_SESSION['toast_message'])) {
    echo renderToastNotification($_SESSION['toast_message'], $_SESSION['toast_type'] ?? 'success');
    unset($_SESSION['toast_message'], $_SESSION['toast_type']);
}




header("Content-Type: text/html; charset=UTF-8");
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <title>Система обеспечения потребности бригад</title>
  <link rel="stylesheet" href="style.css">
  <!-- Подключаем Chart.js для инфографики -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    /* Стили для модальных окон inline редактирования */
    .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background: rgba(0,0,0,0.5); }
    .modal-content { background: #fff; margin: 10% auto; padding: 20px; border-radius: 4px; width: 90%; max-width: 500px; position: relative; }
    .close-button { position: absolute; top: 10px; right: 15px; font-size: 24px; font-weight: bold; cursor: pointer; }
  </style>
  <script>
    // Функция для добавления новой позиции заявки
    function addPosition() {
      var table = document.getElementById("positionsTable");
      var row = table.insertRow(-1);
      var cellType = row.insertCell(0);
      cellType.innerHTML = '<input type="text" name="type[]" placeholder="Вид заявки" required>';
      var cellDesc = row.insertCell(1);
      cellDesc.innerHTML = '<input type="text" name="description[]" placeholder="Наименование" required>';
      var cellDate = row.insertCell(2);
      cellDate.innerHTML = '<input type="date" name="required_date[]" required>';
      var cellTime = row.insertCell(3);
      cellTime.innerHTML = '<input type="time" name="required_time[]" required>';
      var cellRemove = row.insertCell(4);
      cellRemove.innerHTML = '<button type="button" onclick="removePosition(this)">Удалить</button>';
    }
    function removePosition(btn) {
      var row = btn.parentNode.parentNode;
      row.parentNode.removeChild(row);
    }
    function goToPage() {
      var page = document.getElementById("pageInput").value;
      var url = new URL(window.location.href);
      url.searchParams.set("page", page);
      window.location.href = url.href;
    }
    function toggleStructuralDivision() {
      var roleSelect = document.getElementById('roleSelect');
      var structBlock = document.getElementById('structDivisionBlock');
      if (roleSelect.value === 'executor') {
          structBlock.style.display = 'block';
      } else {
          structBlock.style.display = 'none';
      }
    }

    // AJAX-обработчик отправки формы inline редактирования
    window.addEventListener('DOMContentLoaded', function() {
        document.getElementById('inlineEditForm').addEventListener('submit', function(e) {
            e.preventDefault();
            var formData = new FormData(this);
            fetch('index.php', {
                method: 'POST',
                body: formData
            })
            .then(function(response) { return response.text(); })
            .then(function(responseText) {
                var equipmentId = formData.get('equipment_id');
                var field = formData.get('field_name');
                var newValue = formData.get('new_value');
                var cell = document.querySelector('td[data-id="'+equipmentId+'"][data-field="'+field+'"]');
                if (cell) { cell.innerText = newValue; }
                document.getElementById('modalInline').style.display = 'none';
            })
            .catch(function(error) { alert('Ошибка обновления!'); console.error(error); });
        });
    });
    window.addEventListener('click', function(e) {
        var modalInline = document.getElementById('modalInline');
        if (e.target === modalInline) { modalInline.style.display = 'none'; }
    });
  </script>
</head>
<body>
<?php
if (!isset($_SESSION['user'])):
    if ($action === 'register'):
?>
    <div class="login-container">
      <h2>Регистрация</h2>
      <?php if (isset($regError)) echo "<p class='error'>" . htmlspecialchars($regError, ENT_QUOTES, 'UTF-8') . "</p>"; ?>
      <form method="post" action="?action=register">
        <label>Имя пользователя:<br>
          <input type="text" name="username" required>
        </label>
        <label>Пароль:<br>
          <input type="password" name="password" required>
        </label>
        <label>Подтверждение пароля:<br>
          <input type="password" name="confirm_password" required>
        </label>
        <label>Роль:<br>
          <select name="role" id="roleSelect" required onchange="toggleStructuralDivision()">
            <option value="technologist">Технолог</option>
            <option value="chief">Начальник смены ЦИТС</option>
            <option value="executor">Исполнитель</option>
            <option value="admin">Админ</option>
          </select>
        </label>
        <div id="structDivisionBlock" style="display: none;">
          <label>Структурное подразделение (для исполнителей):<br>
            <select name="struct_division">
              <option value="">-- Выберите подразделение --</option>
              <option value="БПО">БПО</option>
              <option value="ЦЕХ">ЦЕХ</option>
              <option value="ЦПА">ЦПА</option>
              <option value="Электрики">Электрики</option>
              <option value="ЦИТС">ЦИТС</option>
            </select>
          </label>
        </div>
        <input type="submit" value="Зарегистрироваться">
      </form>
      <p>Уже есть аккаунт? <a href="index.php">Войти</a></p>
    </div>
<?php
    else:
?>
    <div class="login-container">
      <h2>Вход в систему</h2>
      <?php if (isset($loginError)) echo "<p class='error'>" . htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') . "</p>"; ?>
      <form method="post" action="?action=login">
        <label>Имя пользователя:<br>
          <input type="text" name="username" required>
        </label>
        <label>Пароль:<br>
          <input type="password" name="password" required>
        </label>
        <input type="submit" value="Войти">
      </form>
      <p>Нет аккаунта? <a href="?action=register">Зарегистрироваться</a></p>
    </div>
<?php
    endif;
    exit;
else:
    $role = $_SESSION['role'];
    echo "<header>";
    echo "<h1>Система обеспечения потребности бригад</h1>";
    echo "<h2>Личный кабинет: " . htmlspecialchars($_SESSION['user'], ENT_QUOTES, 'UTF-8') . " (" . htmlspecialchars($role, ENT_QUOTES, 'UTF-8') . ")</h2>";
    echo renderNotificationsBell($_SESSION['user']);
?>
    <nav class="user-nav">
        <a href="?action=profile" class="btn-main">Личный кабинет</a>
        <a href="?action=report" class="btn-main">Формирование отчёта</a>
        <a href="?action=logout" class="btn-main btn-danger">Выйти</a>
    </nav>
</header>
<?php if (in_array($role, ['technologist','chief','executor','admin','cits', ROLE_ANALYTIC])): ?>
<nav>
  <ul>
    <?php
      if ($role === 'technologist') {
          echo '<li><a href="?action=addRequest">Добавить заявку</a></li>';
          echo '<li><a href="?action=viewRequests">Мои заявки</a></li>';
          echo '<li><a href="?action=onlineAnalytics">Онлайн аналитика</a></li>';
      } elseif($isShiftLead){echo'<li><a href="?action=citsAll">Все заявки ЦИТС</a></li>';echo'<li><a href="?action=myRequests">Мои заявки ЦИТС</a></li>';

      } elseif ($role === 'executor') {
          echo '<li><a href="?action=updateApplication">Обновить статус позиции</a></li>';
          echo '<li><a href="?action=findUrgent">Найти срочные заявки</a></li>';
          echo '<li><a href="?action=viewRequests">Мои заявки</a></li>';
      } elseif ($role === 'admin') {
          echo '<li><a href="?action=viewRequests">Просмотр всех заявок</a></li>';
          echo '<li><a href="?action=deletionRequests">Запросы на удаление</a></li>';
          echo '<li><a href="?action=exportExcel">Экспорт в CSV</a></li>';
          echo '<li><a href="?action=onlineAnalytics">Онлайн аналитика</a></li>';
      } elseif ($isShiftLead) {
          // Для ЦИТС: все заявки и “Мои заявки ЦИТС”
          echo '<li><a href="?action=viewRequests">Все заявки</a></li>';
          echo '<li><a href="?action=myRequests">Мои заявки ЦИТС</a></li>';
      } elseif ($role === ROLE_ANALYTIC) {
          echo '<li><a href="?action=viewRequests&filter=active">Активные заявки</a></li>';
          echo '<li><a href="?action=onlineAnalytics">Онлайн аналитика</a></li>';

      }
      
      
      // Общие ссылки
      echo '<li><a href="?action=viewRequests&filter=waiting">Ожидающие</a></li>';
      echo '<li><a href="?action=viewRequests&filter=overdue">Просроченные</a></li>';
      echo '<li><a href="?action=viewRequests&filter=archive">Архив</a></li>';
    ?>
  </ul>
</nav>
<?php endif; ?>


<?php endif; // Закрытие блока авторизации ?>
<main>
<?php
switch ($action) {
    case 'markAllRead':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $username = $_POST['username'] ?? '';
            if (!empty($username)) {
                markAllNotificationsAsRead($username);
            }
            echo "OK";
        }
        exit;
        
    case 'addRequest':
        if ($role !== 'technologist') {
            echo "<p class='error'>Доступ запрещен.</p>";
            break;
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = [
                'date_submitted' => trim($_POST['date_submitted'] ?? ''),
                'shift'          => trim($_POST['shift'] ?? ''),
                'cdng'           => trim($_POST['cdng'] ?? ''),
                'ceh_krs'        => trim($_POST['ceh_krs'] ?? ''),
                'brigade'        => trim($_POST['brigade'] ?? ''),
                'master'         => trim($_POST['master'] ?? ''),
                'kust'           => trim($_POST['kust'] ?? ''),
                'skvazhina'      => trim($_POST['skvazhina'] ?? ''),
                'created_by'     => $_SESSION['user'],
                'required_date'  => '',
                'required_time'  => '',
            ];
    
            $positions = [];
if (!empty($_POST['type']) && is_array($_POST['type'])) {
    foreach ($_POST['type'] as $idx => $valType) {
        $valType = trim($valType);
        $valDesc = trim($_POST['description'][$idx] ?? '');
        $reqDate = trim($_POST['required_date'][$idx] ?? '');
        $reqTime = trim($_POST['required_time'][$idx] ?? '');
        if ($valType !== '' || $valDesc !== '') {
            $positions[] = [
                'type'          => $valType,
                'description'   => $valDesc,
                'required_date' => $reqDate,
                'required_time' => $reqTime
            ];
        }
    }
}
            if (empty($positions)) {
                echo renderToastNotification("Ошибка: не заполнены позиции.", "error");
                break;
            }
            $newIds = addRequests($data, $positions);
            if ($newIds && count($newIds) > 0) {
                echo renderToastNotification("Заявки успешно созданы (кол-во: " . count($newIds) . ")", "success");
            } else {
                echo renderToastNotification("Ошибка при сохранении заявок.", "error");
            }
        } else {
            ?>
            <h2>Добавить новую заявку</h2>
            <form method="post" action="?action=addRequest">
              <label>Дата подачи:<br>
                <input type="date" name="date_submitted" required>
              </label>
              <label>Смена:<br>
                <input type="text" name="shift" required>
              </label>
              <label>ЦДНГ:<br>
                <input type="text" name="cdng" required>
              </label>
              <label>Цех КРС:<br>
                <input type="text" name="ceh_krs" required>
              </label>
              <label>Бригада:<br>
                <input type="text" name="brigade" required>
              </label>
              <label>Мастер:<br>
                <input type="text" name="master" required>
              </label>
              <label>Куст:<br>
                <input type="text" name="kust" required>
              </label>
              <label>Скважина:<br>
                <input type="text" name="skvazhina" required>
              </label>
              
              <hr>
              <h3>Позиции заявки</h3>
              <p>Введите «Вид заявки» и «Наименование». Каждая заполненная строка создаст отдельную запись.</p>
              <table id="positionsTable" border="1" style="border-collapse: collapse;">
                <tr>
                  <th>Вид заявки</th>
                  <th>Наименование</th>
                  <th>Требуемая дата</th>
                  <th>Требуемое время</th>
                  <th>Действие</th>
                </tr>
              </table>
              <button type="button" onclick="addPosition()">Добавить позицию</button>
              <br><br>
              <input type="submit" value="Создать заявку">
            </form>
            <?php
        }
        break;
        
    case 'assignPosition':
        if (!$isShiftLead) {
            echo "<p class='error'>Доступ запрещен.</p>";
            break;
        }
        $posId = intval($_GET['id'] ?? 0);
        if (!$posId) {
            echo "<p class='error'>Не указан ID позиции.</p>";
            break;
        }
        $position = getPositionById($posId);
        if (!$position) {
            echo "<p class='error'>Позиция не найдена.</p>";
            break;
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $executor = trim($_POST['executor'] ?? '');
            if ($executor === '') {
                echo "<p class='error'>Не выбран исполнитель.</p>";
            } else {
                if (assignPositionById($posId, $executor)) {
                    echo renderToastNotification("Исполнитель '$executor' назначен на позицию № $posId.", "success");
                } else {
                    echo "<p class='error'>Ошибка при назначении исполнителя.</p>";
                }
            }
        } else {
            $executors = getExecutors();
            ?>
            <h2>Назначить исполнителя на позицию № <?php echo htmlspecialchars($posId, ENT_QUOTES, 'UTF-8'); ?></h2>
            <form method="post" action="?action=assignPosition&id=<?php echo htmlspecialchars($posId, ENT_QUOTES, 'UTF-8'); ?>">
              <label>Исполнитель:<br>
                <select name="executor" required>
                  <option value="">-- Выберите исполнителя --</option>
                  <?php foreach ($executors as $exec): ?>
                    <option value="<?php echo htmlspecialchars($exec['username'], ENT_QUOTES, 'UTF-8'); ?>">
                      <?php echo htmlspecialchars($exec['username'], ENT_QUOTES, 'UTF-8'); 
                      if (!empty($exec['structural_division'])) {
                          echo " (" . htmlspecialchars($exec['structural_division'], ENT_QUOTES, 'UTF-8') . ")";
                      } ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>
              <br><br>
              <input type="submit" value="Назначить">
            </form>
            <?php
        }
        break;
        
    case 'profile':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $fullname = trim($_POST['fullname'] ?? '');
            $position = trim($_POST['position'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            if (updateUserProfile($_SESSION['user_id'], $fullname, $position, $email, $phone)) {
                echo "<p class='success'>Профиль обновлён.</p>";
            } else {
                echo "<p class='error'>Ошибка обновления профиля.</p>";
            }
        }
        $profile = getUserProfile($_SESSION['user_id']);
        ?>
        <h2>Личный кабинет</h2>
        <form method="post" action="?action=profile">
            <label>ФИО:<br>
                <input type="text" name="fullname" value="<?php echo htmlspecialchars($profile['fullname'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            </label>
            <label>Должность:<br>
                <input type="text" name="position" value="<?php echo htmlspecialchars($profile['position'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            </label>
            <label>Email:<br>
                <input type="email" name="email" value="<?php echo htmlspecialchars($profile['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            </label>
            <label>Телефон:<br>
                <input type="text" name="phone" value="<?php echo htmlspecialchars($profile['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            </label>
            <input type="submit" value="Сохранить">
        </form>
        <?php
        break;
        
    case 'report':if($_SERVER['REQUEST_METHOD']==='POST'){$startDate=(!empty($_POST['start_date'])&&$_POST['start_date']<=($_POST['end_date']??''))?$_POST['start_date']:date('Y-m-d',strtotime('-7 days'));$endDate=(!empty($_POST['end_date'])&&$_POST['end_date']>=$startDate)?$_POST['end_date']:date('Y-m-d');$executors=array_filter($_POST['executors']??[],fn($v)=>trim($v)!=='');$statuses=array_filter($_POST['statuses']??[],fn($v)=>trim($v)!=='');$ageFilter=$_POST['age_filter']??'all';ob_clean();generateExcelReport($startDate,$endDate,$executors,$statuses,$ageFilter);exit;}else{$executorsList=getExecutors();$statusOptions=['new'=>'Новая','in_progress'=>'В работе','not_available'=>'Нет в наличии','ready_for_dispatch'=>'Готово к отправке','shipped'=>'Отгружено','completed'=>'Завершена','deleted'=>'Удалена','cancelled'=>'Отменена'];echo '<h2>Формирование отчёта</h2><form method="post" action="?action=report" style="max-width:800px;margin:auto;"><div style="display:flex;gap:2rem;flex-wrap:wrap;"><div style="flex:1 1 200px;"><label>Дата начала:<br><input type="date" name="start_date" value="'.date('Y-m-d',strtotime('-7 days')).'" style="width:100%;padding:0.5em;border:1px solid #ccc;border-radius:4px;"></label></div><div style="flex:1 1 200px;"><label>Дата окончания:<br><input type="date" name="end_date" value="'.date('Y-m-d').'" style="width:100%;padding:0.5em;border:1px solid #ccc;border-radius:4px;"></label></div><div style="flex:1 1 300px;"><label>Исполнители:<br><select name="executors[]" multiple size="5" style="width:100%;padding:0.5em;border:1px solid #ccc;border-radius:4px;"><option value="">Все исполнители</option>';foreach($executorsList as $exec)echo '<option value="'.htmlspecialchars($exec['username'],ENT_QUOTES).'">'.htmlspecialchars($exec['username'],ENT_QUOTES).'</option>';echo '</select><br><small>Ctrl/Cmd + клик — множественный выбор.</small></label></div><div style="flex:1 1 300px;"><label>Статусы:<br><select name="statuses[]" multiple size="5" style="width:100%;padding:0.5em;border:1px solid #ccc;border-radius:4px;"><option value="">Все статусы</option>';foreach($statusOptions as $code=>$label)echo '<option value="'.$code.'">'.$label.'</option>';echo '</select><br><small>Ctrl/Cmd + клик — множественный выбор.</small></label></div><div style="flex:1 1 200px;"><label>По возрасту заявок:<br><select name="age_filter" style="width:100%;padding:0.5em;border:1px solid #ccc;border-radius:4px;"><option value="all">Все заявки</option><option value="over10">Свыше 10 дней</option><option value="over3">Свыше 3 дней</option></select></label></div></div><br><button type="submit" class="btn-main" style="display:block;margin:0 auto;">Сформировать отчёт</button></form>';}break;
    
    
        
    /*───────────────────────────────────────────────────────────────
 *            О Н - Л А Й Н   А Н А Л И Т И К А
 *──────────────────────────────────────────────────────────────*/
case 'onlineAnalytics':

    /* 1.  Данные за период ---------------------------------------------------*/
    $sd   = $_GET['start'] ?? date('Y-m-d', strtotime('-7 day'));
    $ed   = $_GET['end']   ?? date('Y-m-d');
    $rows = getAnalyticsRequests($sd, $ed);           // функция из functions.php

    $execCnt = [];
    $stat    = ['total'=>0,'ontime'=>0,'late'=>0,'cancel'=>0];

    foreach ($rows as $r) {
        $who = $r['executor'] ?: '—';
        $execCnt[$who] = ($execCnt[$who] ?? 0) + 1;

        $stat['total']++;
        $d = (int)$r['days_passed'];
        if (in_array($r['status'], ['deleted','cancelled','отмененная'], true))      $stat['cancel']++;
        elseif ($d > 10)                                                             $stat['late']++;
        elseif ($r['status']==='completed' && $d <= 10)                              $stat['ontime']++;
    }

    arsort($execCnt);                                   // TOP-10 + «Другие»
    $top10       = array_slice($execCnt, 0, 10, true);
    $othersCount = array_sum(array_slice($execCnt, 10, null, true));
    if ($othersCount) $top10['Другие'] = $othersCount;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <title>Онлайн-аналитика</title>

  <!-- внешние библиотеки -->
  <link  rel="stylesheet"
         href="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/css/select2.min.css">
  <link  rel="stylesheet"
         href="https://cdn.datatables.net/1.13.4/css/jquery.dataTables.min.css">
  <style>
      /* ───── общий стиль ───── */
      body{margin:20px;font:16px/1.4 system-ui,-apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:#fefbf3;color:#333}
      h2{margin:0 0 1.5rem;text-align:center;font-size:1.6rem;font-weight:700;color:#d87f00}

      /* ───── блок фильтров ───── */
      #filters{display:flex;flex-wrap:wrap;gap:1rem;justify-content:center;align-items:center;margin-bottom:2rem}
      #filters label{display:flex;align-items:center;gap:.5rem;font-size:.92rem;white-space:nowrap}
      .btn{padding:7px 16px;border:0;border-radius:4px;font-weight:600;cursor:pointer}
      .btn-main{background:#ff9f1a;color:#fff} .btn-main:hover{background:#ff8700}
      .status-btn{background:#e0e0e0} .status-btn:hover{background:#d5d5d5}
      .status-btn.active{background:#007bff;color:#fff}

      /* ───── графики ───── */
      .charts{display:flex;flex-wrap:wrap;gap:2rem;justify-content:center;margin-bottom:2rem}
      .chart-wrapper{background:#fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.05);padding:1rem}
      /* главный график исполнителей — растягиваем */
      #execWrap{flex:2 1 700px;min-width:600px}
      /* диаграмма статусов */
      #statWrap{flex:1 1 350px;min-width:320px}

      /* ───── таблица ───── */
      table.dataTable{width:100%}
      table.dataTable thead th{white-space:nowrap}
      table.dataTable tbody td{white-space:nowrap}
      /* селект «показывать N записей» переносим вправо */
      div.dataTables_length{float:right}
  </style>
</head>
<body>

<h2>Он-лайн аналитика (<?=htmlspecialchars($sd)?> — <?=htmlspecialchars($ed)?>)</h2>

<div id="filters">
  <!-- период -->
  <label>
    Период:
    <input type="date" id="startDate" value="<?=htmlspecialchars($sd)?>">
    —
    <input type="date" id="endDate" value="<?=htmlspecialchars($ed)?>">
    <button type="button" id="applyPeriod" class="btn btn-main">Применить</button>
  </label>

  <!-- исполнитель -->
  <label>
    Исполнитель:
    <select id="executorSelect" style="width:220px">
      <option value="">Все</option>
      <?php foreach(array_keys($execCnt) as $who): ?>
        <option><?=htmlspecialchars($who)?></option>
      <?php endforeach; ?>
    </select>
  </label>

  <!-- статус -->
  <label>Статус:
    <button type="button" class="status-btn active" data-status="">ВСЕ</button>
    <button type="button" class="status-btn"        data-status="completed">В СРОК</button>
    <button type="button" class="status-btn"        data-status="late">ПРОСРОЧЕНО</button>
    <button type="button" class="status-btn"        data-status="cancel">ОТМЕНЕНО</button>
  </label>
</div>

<!-- ░░░ диаграммы ░░░ -->
<div class="charts">
  <div class="chart-wrapper" id="execWrap"><canvas id="execChart"></canvas></div>
  <div class="chart-wrapper" id="statWrap"><canvas id="statChart"></canvas></div>
</div>

<!-- ░░░ таблица ░░░ -->
<table id="analyticsTable" class="display">
  <thead>
    <tr><th>ID</th><th>Дата</th><th>Исполнитель</th><th>Статус</th><th>Дни прошли</th></tr>
  </thead>
</table>

<!-- библиотеки JS -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script defer src="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<script>
$(function(){

  /* данные с PHP */
  const ALL = <?=json_encode($rows   ,JSON_UNESCAPED_UNICODE)?>;
  const TOP = <?=json_encode($top10  ,JSON_UNESCAPED_UNICODE)?>;
  const S   = {tot:<?=$stat['total']?>,ok:<?=$stat['ontime']?>,late:<?=$stat['late']?>,ccl:<?=$stat['cancel']?>};

  /* Select2 */
  if ($.fn.select2) $('#executorSelect').select2({placeholder:'Все',allowClear:true,width:'resolve'});

  /* ░░ DataTable ░░ */
  const savedLen = +localStorage.getItem('analyticsRowsPerPage')||100;
  const tbl = $('#analyticsTable').DataTable({
      language:{url:'https://cdn.datatables.net/plug-ins/1.13.4/i18n/ru.json'},
      data: ALL.map(r=>[r.id,r.date_submitted,r.executor||'—',r.status,r.days_passed]),
      pageLength: savedLen,
      lengthMenu: [[10,50,100,500,1000,-1],[10,50,100,500,1000,'Все']],
      order: [[1,'desc']]
  });
  /* сохраняем выбор количества строк */
  tbl.on('length.dt', function(e,settings,len){
      localStorage.setItem('analyticsRowsPerPage',len);
  });

  /* ░░ график исполнителей (bar, горизонт) ░░ */
  const execChart = new Chart(
    document.getElementById('execChart'),
    {
      type:'bar',
      data:{labels:Object.keys(TOP),datasets:[{data:Object.values(TOP),label:'Заявки',backgroundColor:'#ffbe3b'}]},
      options:{
        indexAxis:'y',
        plugins:{legend:{display:false},title:{display:true,text:'Топ-10 исполнителей'}},
        onClick(e,els){
          if(!els.length) return;
          const name=this.data.labels[els[0].index];
          $('#executorSelect').val(name).trigger('change');
        }
      }
    }
  );

  /* ░░ график статусов (doughnut) ░░ */
  new Chart(
    document.getElementById('statChart'),
    {
      type:'doughnut',
      data:{
        labels:['Всего','В срок','Просрочено','Отменено'],
        datasets:[{data:[S.tot,S.ok,S.late,S.ccl],
                   backgroundColor:['#f1c40f','#2ecc71','#e74c3c','#a4b0be']}]
      },
      options:{
        plugins:{title:{display:true,text:'Статусы заявок'}},
        onClick(e,els){
          if(!els.length) return;
          const idx=els[0].index;
          const map=['','completed','late','cancel'];
          $('.status-btn').removeClass('active');
          $(`.status-btn[data-status="${map[idx]}"]`).addClass('active');
          applyFilters();
        }
      }
    }
  );

  /* ░░ фильтры ░░ */
  $('.status-btn').on('click',function(){
      $('.status-btn').removeClass('active');
      $(this).addClass('active');
      applyFilters();
  });
  $('#executorSelect').on('change',applyFilters);

  $('#applyPeriod').on('click',function(){
      const s=$('#startDate').val(), e=$('#endDate').val();
      if(!s||!e) return;
      location.href=`?action=onlineAnalytics&start=${s}&end=${e}`;
  });

  function applyFilters(){
      const execSel=$('#executorSelect').val()||'';
      const stSel=$('.status-btn.active').data('status')||'';
      const filt = ALL.filter(r=>{
          if(execSel && (r.executor||'—')!==execSel) return false;
          if(stSel==='completed') return r.status==='completed' && r.days_passed<=10;
          if(stSel==='late')      return r.days_passed>10;
          if(stSel==='cancel')    return ['deleted','cancelled','отмененная'].includes(r.status);
          return true;
      });
      tbl.clear().rows.add(filt.map(r=>[r.id,r.date_submitted,r.executor||'—',r.status,r.days_passed])).draw();
  }

}); /* конец ready */
</script>
</body>
</html>
<?php
    exit;
/*───────────────────────────────────────────────────────────────*/


        
    case 'updateApplication':
        if ($role !== 'executor') {
            echo "<p class='error'>Доступ запрещен.</p>";
            break;
        }
        if (isset($_GET['id'])) {
            $posId = intval($_GET['id']);
            $position = getPositionById($posId);
            if (!$position) {
                echo "<p class='error'>Позиция не найдена.</p>";
                break;
            }
            if ($position['executor'] !== $_SESSION['user']) {
                echo "<p class='error'>Эта позиция не назначена вам.</p>";
                break;
            }
            ?>
            <h2>Обновление статуса позиции № <?php echo htmlspecialchars($posId, ENT_QUOTES, 'UTF-8'); ?></h2>
            <div class="table-container">
              <table class="details">
                <tr>
                  <?php 
                  $fields = ['Дата подачи','Смена','ЦДНГ','Цех КРС','Бригада','Мастер','Куст','Скважина','Вид заявки','Наименование','Текущий статус'];
                  foreach ($fields as $f) {
                      echo "<th>" . htmlspecialchars($f, ENT_QUOTES, 'UTF-8') . "</th>";
                  }
                  ?>
                </tr>
                <tr>
                  <?php 
                  $vals = [
                      $position['date_submitted'] ?? '',
                      $position['shift'] ?? '',
                      $position['cdng'] ?? '',
                      $position['ceh_krs'] ?? '',
                      $position['brigade'] ?? '',
                      $position['master'] ?? '',
                      $position['kust'] ?? '',
                      $position['skvazhina'] ?? '',
                      $position['type'] ?? '',
                      $position['description'] ?? '',
                      $position['status'] ?? ''
                  ];
                  foreach ($vals as $v) {
                      echo "<td>" . htmlspecialchars($v, ENT_QUOTES, 'UTF-8') . "</td>";
                  }
                  ?>
                </tr>
              </table>
            </div>
            <form method="post" action="?action=updateApplication">
              <input type="hidden" name="id" value="<?php echo htmlspecialchars($posId, ENT_QUOTES, 'UTF-8'); ?>">
              <label>Выберите новый статус:<br>
                <select name="status" required>
                  <option value="в работе">В работе</option>
                  <option value="нет в наличии">Нет в наличии</option>
                  <option value="готово к отгрузке">Готово к отгрузке</option>
                  <option value="отгружено">Отгружено</option>
                </select>
              </label>
              <br><br>
              <input type="submit" value="Обновить статус">
            </form>
            <?php
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $posId = intval($_POST['id'] ?? 0);
            $status = trim($_POST['status'] ?? '');
            if ($status === 'в работе') {
                $status = 'in_progress';
            } elseif ($status === 'нет в наличии') {
                $status = 'not_available';
            } elseif ($status === 'готово к отгрузке') {
                $status = 'ready_for_dispatch';
            } elseif ($status === 'отгружено') {
                $status = 'shipped';
            }
            if (updatePositionStatusById($posId, $status)) {
                echo "<p class='success'>Статус позиции № " . htmlspecialchars($posId, ENT_QUOTES, 'UTF-8') . " обновлён на " . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . ".</p>";
            } else {
                echo "<p class='error'>Ошибка при обновлении статуса.</p>";
            }
        }
        break;
        
    case 'techConfirmPosition':
        if ($role !== 'technologist') {
            echo "<p class='error'>Доступ запрещен.</p>";
            break;
        }
        $posId = intval($_GET['id'] ?? 0);
        if (!$posId) {
            echo "<p class='error'>Не указан ID позиции.</p>";
            break;
        }
        $position = getPositionById($posId);
        if (!$position) {
            echo "<p class='error'>Позиция не найдена.</p>";
            break;
        }

        if (techConfirmPosition($posId)) {
            echo "<p class='success'>Позиция № " . htmlspecialchars($posId, ENT_QUOTES, 'UTF-8') . " переведена в 'completed' (архив).</p>";
        } else {
            echo "<p class='error'>Ошибка при подтверждении выполнения.</p>";
        }
        break;
        
    case 'findUrgent':
        if ($role !== 'executor') {
            echo "<p class='error'>Доступ запрещен.</p>";
            break;
        }
        $urgent = getUrgentRequests();
        ?>
        <h2>Срочные заявки</h2>
        <div class="table-container">
            <table id="requestsTable" border="1" style="border-collapse:collapse;">
              <tr>
                <th>ID</th>
                <th>Дата подачи</th>
                <th>Требуемая дата</th>
                <th>Осталось дней</th>
                <th>Статус</th>
                <th>Действие</th>
              </tr>
              <?php foreach ($urgent as $req):
                  $days = getDaysPassed($req['date_submitted']);
              ?>
              <tr>
                <td>
                  <a href="?action=viewRequest&id=<?php echo htmlspecialchars($req['group_id'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank">
                    <?php echo htmlspecialchars($req['id'], ENT_QUOTES, 'UTF-8'); ?>
                  </a>
                </td>
                <td data-id="<?php echo htmlspecialchars($req['id'], ENT_QUOTES, 'UTF-8'); ?>" data-field="date_submitted" onclick="openInlineEditor(this)"><?php echo htmlspecialchars($req['date_submitted'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td data-id="<?php echo htmlspecialchars($req['id'], ENT_QUOTES, 'UTF-8'); ?>" data-field="required_date" onclick="openInlineEditor(this)"><?php echo htmlspecialchars($req['required_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($days, ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($req['status'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><a href="?action=updateApplication&id=<?php echo htmlspecialchars($req['id'], ENT_QUOTES, 'UTF-8'); ?>">Обновить статус</a></td>
              </tr>
              <?php endforeach; ?>
            </table>
        </div>
        <?php
        break;
    
    case 'assignExecutor':
        // Назначить исполнителя (или себя, если ЦИТС)
        if (!$isShiftLead && $role!=='admin') {
            echo "<p class='error'>Доступ запрещён.</p>";
            break;
        }
        $requestId = intval($_REQUEST['request_id'] ?? 0);
        if (!$requestId) {
            echo "<p class='error'>Не указан ID заявки.</p>";
            break;
        }
        // если пришёл флаг assign_cits — назначаем себя
        $executorId = isset($_REQUEST['assign_cits']) 
                      ? $_SESSION['user_id'] 
                      : intval($_REQUEST['executor_id'] ?? 0);
        assignExecutor($requestId, $executorId);
        header('Location: index.php?action=viewRequests');
        exit;
    
    case 'updateStatus': if(!$isShiftLead){echo "<p class='error'>Доступ запрещён.</p>";break;} $requestId=intval($_REQUEST['request_id']??0); if(!$requestId){echo "<p class='error'>Не указан ID позиции.</p>";break;} if($_SERVER['REQUEST_METHOD']==='POST'){ $newStatus=trim($_POST['status']??''); echo updateStatusByPerformer($requestId,$newStatus)?renderToastNotification("Статус обновлён на «{$newStatus}».","success"):renderToastNotification("Ошибка обновления статуса.","error"); break;} echo "<h2>Обновление статуса позиции № ".htmlspecialchars($requestId,ENT_QUOTES,'UTF-8')."</h2><form method=\"post\" action=\"?action=updateStatus&request_id={$requestId}\"><label>Новый статус:<br><select name=\"status\" required><option value=\"in_work\">В работе</option><option value=\"not_available\">Нет в наличии</option><option value=\"ready_for_dispatch\">Готово к отправке</option><option value=\"shipped\">Отгружено</option></select></label><br><br><input type=\"submit\" value=\"Сохранить\"></form>"; break;

        
    case 'myRequests': if (!$isShiftLead) { echo "<p class='error'>Доступ запрещён.</p>"; break; } $requests = getMyRequestsForCITS($_SESSION['user_id']); echo "<h2>Мои заявки ЦИТС</h2><div class='table-container'><table id='requestsTable' border='1' style='border-collapse:collapse;width:100%;'><tr><th>№ п/п</th><th>Дата подачи</th><th>Смена</th><th>ЦДНГ</th><th>Цех КРС</th><th>Бригада</th><th>Мастер</th><th>Куст</th><th>Скважина</th><th>Вид заявки</th><th>Наименование</th><th>Отв. исп.</th><th>Треб. дата</th><th>Треб. время</th><th>Исполнитель</th><th>Статус</th><th>Факт. время</th><th>Примечание</th><th>Длит.(дн)</th><th>+10</th><th>+3</th><th>Действия</th></tr>"; $i = 1; foreach($requests as $req) { $days = getDaysPassed($req['date_submitted']); $over10 = $days >= 10 ? "+" : ""; $over3 = $days >= 3 ? "+" : ""; echo "<tr><td>{$i}</td><td data-id='{$req['id']}' data-field='date_submitted' onclick='openInlineEditor(this)'>" . htmlspecialchars($req['date_submitted'], ENT_QUOTES) . "</td><td data-id='{$req['id']}' data-field='shift' onclick='openInlineEditor(this)'>" . htmlspecialchars($req['shift'], ENT_QUOTES) . "</td><td data-id='{$req['id']}' data-field='cdng' onclick='openInlineEditor(this)'>" . htmlspecialchars($req['cdng'], ENT_QUOTES) . "</td><td data-id='{$req['id']}' data-field='ceh_krs' onclick='openInlineEditor(this)'>" . htmlspecialchars($req['ceh_krs'], ENT_QUOTES) . "</td><td data-id='{$req['id']}' data-field='brigade' onclick='openInlineEditor(this)'>" . htmlspecialchars($req['brigade'], ENT_QUOTES) . "</td><td data-id='{$req['id']}' data-field='master' onclick='openInlineEditor(this)'>" . htmlspecialchars($req['master'], ENT_QUOTES) . "</td><td data-id='{$req['id']}' data-field='kust' onclick='openInlineEditor(this)'>" . htmlspecialchars($req['kust'], ENT_QUOTES) . "</td><td data-id='{$req['id']}' data-field='skvazhina' onclick='openInlineEditor(this)'>" . htmlspecialchars($req['skvazhina'], ENT_QUOTES) . "</td><td data-id='{$req['id']}' data-field='type' onclick='openInlineEditor(this)'>" . htmlspecialchars($req['type'], ENT_QUOTES) . "</td><td data-id='{$req['id']}' data-field='description' onclick='openInlineEditor(this)'>" . htmlspecialchars($req['description'], ENT_QUOTES) . "</td><td>" . htmlspecialchars($req['responsible_executive'] ?? '', ENT_QUOTES) . "</td><td data-id='{$req['id']}' data-field='required_date' onclick='openInlineEditor(this)'>" . htmlspecialchars($req['required_date'] ?? '', ENT_QUOTES) . "</td><td data-id='{$req['id']}' data-field='required_time' onclick='openInlineEditor(this)'>" . htmlspecialchars($req['required_time'] ?? '', ENT_QUOTES) . "</td><td>" . htmlspecialchars($req['executor'] ?? '', ENT_QUOTES) . "</td><td>" . htmlspecialchars($req['status'], ENT_QUOTES) . "</td><td>" . htmlspecialchars($req['actual_time'] ?? '', ENT_QUOTES) . "</td><td data-id='{$req['id']}' data-field='note' onclick='openInlineEditor(this)'>" . htmlspecialchars($req['note'] ?? '', ENT_QUOTES) . "</td><td>{$days}</td><td>{$over10}</td><td>{$over3}</td><td style='white-space:nowrap;'><button onclick=\"location.href='?action=viewRequest&id={$req['group_id']}'\" title='Открыть' style='border:none;background:none;cursor:pointer;font-size:1.2rem;margin-right:8px;'>👁️</button><button onclick=\"location.href='?action=updateStatus&request_id={$req['id']}'\" title='Обновить статус' style='border:none;background:none;cursor:pointer;font-size:1.2rem;'>🔄</button></td></tr>"; $i++; } echo "</table></div>"; break;

    case 'citsAll': if (!$isShiftLead) { echo "<p class='error'>Доступ запрещён.</p>"; break; } $requests = getAllCITSRequests(); echo "<h2>Все заявки ЦИТС</h2><div class='table-container'><table id='requestsTable' border='1' style='border-collapse:collapse;width:100%;'><tr><th>№ п/п</th><th>Дата подачи</th><th>Смена</th><th>ЦДНГ</th><th>Цех КРС</th><th>Бригада</th><th>Мастер</th><th>Куст</th><th>Скважина</th><th>Вид заявки</th><th>Наименование</th><th>Отв. исп.</th><th>Треб. дата</th><th>Треб. время</th><th>Исполнитель</th><th>Статус</th><th>Факт. время</th><th>Примечание</th><th>Длит.(дн)</th><th>+10</th><th>+3</th><th>Действия</th></tr>"; $i = 1; foreach ($requests as $req) { $days = getDaysPassed($req['date_submitted']); $over10 = $days >= 10 ? "+" : ""; $over3 = $days >= 3 ? "+" : ""; echo "<tr><td>{$i}</td><td data-id='{$req['id']}' data-field='date_submitted' onclick='openInlineEditor(this)'>" . htmlspecialchars($req['date_submitted'], ENT_QUOTES) . "</td><td data-id='{$req['id']}' data-field='shift' onclick='openInlineEditor(this)'>" . htmlspecialchars($req['shift'], ENT_QUOTES) . "</td><td data-id='{$req['id']}' data-field='cdng' onclick='openInlineEditor(this)'>" . htmlspecialchars($req['cdng'], ENT_QUOTES) . "</td><td data-id='{$req['id']}' data-field='ceh_krs' onclick='openInlineEditor(this)'>" . htmlspecialchars($req['ceh_krs'], ENT_QUOTES) . "</td><td data-id='{$req['id']}' data-field='brigade' onclick='openInlineEditor(this)'>" . htmlspecialchars($req['brigade'], ENT_QUOTES) . "</td><td data-id='{$req['id']}' data-field='master' onclick='openInlineEditor(this)'>" . htmlspecialchars($req['master'], ENT_QUOTES) . "</td><td data-id='{$req['id']}' data-field='kust' onclick='openInlineEditor(this)'>" . htmlspecialchars($req['kust'], ENT_QUOTES) . "</td><td data-id='{$req['id']}' data-field='skvazhina' onclick='openInlineEditor(this)'>" . htmlspecialchars($req['skvazhina'], ENT_QUOTES) . "</td><td data-id='{$req['id']}' data-field='type' onclick='openInlineEditor(this)'>" . htmlspecialchars($req['type'], ENT_QUOTES) . "</td><td data-id='{$req['id']}' data-field='description' onclick='openInlineEditor(this)'>" . htmlspecialchars($req['description'], ENT_QUOTES) . "</td><td>" . htmlspecialchars($req['responsible_executive'] ?? '', ENT_QUOTES) . "</td><td data-id='{$req['id']}' data-field='required_date' onclick='openInlineEditor(this)'>" . htmlspecialchars($req['required_date'] ?? '', ENT_QUOTES) . "</td><td data-id='{$req['id']}' data-field='required_time' onclick='openInlineEditor(this)'>" . htmlspecialchars($req['required_time'] ?? '', ENT_QUOTES) . "</td><td>" . htmlspecialchars($req['executor'] ?? '', ENT_QUOTES) . "</td><td>" . htmlspecialchars($req['status'], ENT_QUOTES) . "</td><td>" . htmlspecialchars($req['actual_time'] ?? '', ENT_QUOTES) . "</td><td data-id='{$req['id']}' data-field='note' onclick='openInlineEditor(this)'>" . htmlspecialchars($req['note'] ?? '', ENT_QUOTES) . "</td><td>{$days}</td><td>{$over10}</td><td>{$over3}</td><td style='white-space:nowrap;'>" . (empty($req['executor_id']) ? "<a href='?action=assignPosition&id={$req['id']}' title='Назначить исполнителя' style='margin-right:8px;color:green;font-size:1.2rem;'>➤</a><a href='?action=assignExecutor&request_id={$req['id']}&assign_cits=1' title='Заявка ЦИТС' style='font-size:1.2rem;'>👨‍💼</a>" : ($req['executor_id'] == ($_SESSION['user_id'] ?? 0) ? "<a href='?action=updateStatus&request_id={$req['id']}' title='Обновить статус' style='font-size:1.2rem;'>🔄</a>" : "—")) . "</td></tr>"; $i++; } echo "</table></div>"; break;

    case 'cits_all':
        $requests = getAllCITSRequests();
        break;
    
    case 'cits_my':
        $requests = getMyRequestsForCITS($_SESSION['user_id']);
        break;
        
    case 'viewRequests':
        $currentUser = ($role === 'executor') ? $_SESSION['user'] : "";
        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $limit = 500;
        $offset = ($page - 1) * $limit;
        $advancedFilters = [
            'f_date_from'  => $_GET['f_date_from'] ?? '',
            'f_date_to'    => $_GET['f_date_to'] ?? '',
            'f_shift'      => $_GET['f_shift'] ?? '',
            'f_cdng'       => $_GET['f_cdng'] ?? '',
            'f_ceh_krs'    => $_GET['f_ceh_krs'] ?? '',
            'f_brigade'    => $_GET['f_brigade'] ?? '',
            'f_master'     => $_GET['f_master'] ?? '',
            'f_kust'       => $_GET['f_kust'] ?? '',
            'f_skvazhina'  => $_GET['f_skvazhina'] ?? '',
            'f_type'       => $_GET['f_type'] ?? '',
            'f_status'     => $_GET['f_status'] ?? ''
        ];
        if ($role === 'executor') $advancedFilters['exclude_statuses'] = ['completed', 'отмененная'];
        $filter = $_GET['filter'] ?? '';
        ?>
        <form method="GET" action="index.php" style="margin-bottom: 20px;">
            <input type="hidden" name="action" value="viewRequests">
            <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                <label>Бригада:
                    <input type="text" name="f_brigade" value="">
                </label>
            </div>
            <button type="submit" style="margin-top:10px; padding:10px 20px;">Применить фильтр</button>
        </form>

        <?php
        $requests = getRequestsByFilterAdvanced($filter, $advancedFilters, $offset, $limit, $role, $currentUser);
        $total = getRequestsCountAdvanced($filter, $advancedFilters, $role, $currentUser);
        $totalPages = ceil($total / $limit);
        echo "<h2>Список заявок" . ($filter ? " (фильтр: " . htmlspecialchars($filter, ENT_QUOTES, 'UTF-8') . ")" : "") . "</h2>";
        ?>
        <div class="table-container">
            <table id="requestsTable" border="1" style="border-collapse: collapse;">
              <tr>
                <th>№ п/п</th>
                <th>Дата подачи</th>
                <th>Смена</th>
                <th>ЦДНГ</th>
                <th>Цех КРС</th>
                <th>Бригада</th>
                <th>Мастер</th>
                <th>Куст</th>
                <th>Скважина</th>
                <th>Вид заявки</th>
                <th>Наименование</th>
                <th>Отв. исп.</th>
                <th>Треб. дата</th>
                <th>Треб. время</th>
                <th>Исполнитель</th>
                <th>Статус</th>
                <th>Факт. время</th>
                <th>Примечание</th>
                <th>Длит.(дн)</th>
                <th>+10</th>
                <th>+3</th>
                <th>Действия</th>
              </tr>
              <?php 
              $i = $offset + 1;
              foreach ($requests as $req) {
                  $days = getDaysPassed($req['date_submitted']);
                  $over10 = ($days >= 10) ? "+" : "";
                  $over3 = ($days >= 3) ? "+" : "";
                  echo "<tr>";
                  echo "<td>" . $i++ . "</td>";
                  // Дата подачи
                  echo "<td data-id='" . htmlspecialchars($req['id'], ENT_QUOTES, 'UTF-8') . "' data-field='date_submitted' onclick='openInlineEditor(this)'>" . htmlspecialchars($req['date_submitted'], ENT_QUOTES, 'UTF-8') . "</td>";
                  // Смена
                  echo "<td data-id='" . htmlspecialchars($req['id'], ENT_QUOTES, 'UTF-8') . "' data-field='shift' onclick='openInlineEditor(this)'>" . htmlspecialchars($req['shift'], ENT_QUOTES, 'UTF-8') . "</td>";
                  // ЦДНГ
                  echo "<td data-id='" . htmlspecialchars($req['id'], ENT_QUOTES, 'UTF-8') . "' data-field='cdng' onclick='openInlineEditor(this)'>" . htmlspecialchars($req['cdng'], ENT_QUOTES, 'UTF-8') . "</td>";
                  // Цех КРС
                  echo "<td data-id='" . htmlspecialchars($req['id'], ENT_QUOTES, 'UTF-8') . "' data-field='ceh_krs' onclick='openInlineEditor(this)'>" . htmlspecialchars($req['ceh_krs'], ENT_QUOTES, 'UTF-8') . "</td>";
                  // Бригада
                  echo "<td data-id='" . htmlspecialchars($req['id'], ENT_QUOTES, 'UTF-8') . "' data-field='brigade' onclick='openInlineEditor(this)'>" . htmlspecialchars($req['brigade'], ENT_QUOTES, 'UTF-8') . "</td>";
                  // Мастер
                  echo "<td data-id='" . htmlspecialchars($req['id'], ENT_QUOTES, 'UTF-8') . "' data-field='master' onclick='openInlineEditor(this)'>" . htmlspecialchars($req['master'], ENT_QUOTES, 'UTF-8') . "</td>";
                  // Куст
                  echo "<td data-id='" . htmlspecialchars($req['id'], ENT_QUOTES, 'UTF-8') . "' data-field='kust' onclick='openInlineEditor(this)'>" . htmlspecialchars($req['kust'], ENT_QUOTES, 'UTF-8') . "</td>";
                  // Скважина
                  echo "<td data-id='" . htmlspecialchars($req['id'], ENT_QUOTES, 'UTF-8') . "' data-field='skvazhina' onclick='openInlineEditor(this)'>" . htmlspecialchars($req['skvazhina'], ENT_QUOTES, 'UTF-8') . "</td>";
                  // Вид заявки
                  echo "<td data-id='" . htmlspecialchars($req['id'], ENT_QUOTES, 'UTF-8') . "' data-field='type' onclick='openInlineEditor(this)'>" . htmlspecialchars($req['type'], ENT_QUOTES, 'UTF-8') . "</td>";
                  // Наименование
                  echo "<td data-id='" . htmlspecialchars($req['id'], ENT_QUOTES, 'UTF-8') . "' data-field='description' onclick='openInlineEditor(this)'>" . htmlspecialchars($req['description'], ENT_QUOTES, 'UTF-8') . "</td>";
                  // Отв. исполнитель
                  echo "<td>" . htmlspecialchars($req['responsible_executive'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>";
                  // Требуемая дата
                  echo "<td data-id='" . htmlspecialchars($req['id'], ENT_QUOTES, 'UTF-8') . "' data-field='required_date' onclick='openInlineEditor(this)'>" . htmlspecialchars($req['required_date'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>";
                  // Требуемое время
                  echo "<td data-id='" . htmlspecialchars($req['id'], ENT_QUOTES, 'UTF-8') . "' data-field='required_time' onclick='openInlineEditor(this)'>" . htmlspecialchars($req['required_time'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>";
                  // Исполнитель, Статус, Факт. время, Примечание
                  echo "<td>" . htmlspecialchars($req['executor'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>";
                  echo "<td>" . htmlspecialchars($req['status'], ENT_QUOTES, 'UTF-8') . "</td>";
                  echo "<td>" . htmlspecialchars($req['actual_time'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>";
                  echo "<td data-id='" . htmlspecialchars($req['id'], ENT_QUOTES, 'UTF-8') . "' data-field='note' class='note-cell' onclick='openInlineEditor(this)'>" . htmlspecialchars($req['note'] ?? '', ENT_QUOTES, 'UTF-8') . "</td>";

                  echo "<td>" . htmlspecialchars($days, ENT_QUOTES, 'UTF-8') . "</td>";
                  echo "<td>" . htmlspecialchars($over10, ENT_QUOTES, 'UTF-8') . "</td>";
                  echo "<td>" . htmlspecialchars($over3, ENT_QUOTES, 'UTF-8') . "</td>";
                  echo "<td>".($role==='executor'?"<a href='?action=updateApplication&id=".htmlspecialchars($req['id'],ENT_QUOTES,'UTF-8')."' title='Обновить статус'>🔄 Обновить статус</a>":($role==='technologist'?"<a href='?action=editRequest&id=".htmlspecialchars($req['group_id'],ENT_QUOTES,'UTF-8')."' title='Редактировать'>✏️</a> <a href='?action=cancelRequest&id=".htmlspecialchars($req['group_id'],ENT_QUOTES,'UTF-8')."' title='Удалить' onclick=\"return confirm('Вы уверены, что хотите удалить заявку?');\">🗑️</a>".(!in_array($req['status'],['completed','deleted','отмененная'])?" <a href='?action=techConfirmPosition&id=".htmlspecialchars($req['id'],ENT_QUOTES,'UTF-8')."' title='Подтвердить выполнение'>✔️</a>":""):""))."</td>";

                  echo "</tr>";
              }
              ?>
            </table>
        </div>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="?action=viewRequests&page=<?php echo ($page - 1) . ($filter ? "&filter=" . urlencode($filter) : ""); ?>">&laquo; Предыдущая</a>
            <?php endif; ?>
            Страница <?php echo $page; ?> из <?php echo $totalPages; ?>
            <input type="number" id="pageInput" min="1" max="<?php echo $totalPages; ?>" placeholder="Страница">
            <button type="button" onclick="goToPage()">Перейти</button>
            <?php if ($page < $totalPages): ?>
                <a href="?action=viewRequests&page=<?php echo ($page + 1) . ($filter ? "&filter=" . urlencode($filter) : ""); ?>">Следующая &raquo;</a>
            <?php endif; ?>
        </div>
        <?php
        break;
        
    default:
        echo "<h2>Добро пожаловать в систему обеспечения потребности бригад</h2>";
        echo "<p>Выберите нужное действие из меню.</p>";
        break;
}
?>
</main>
<footer>
  <p>&copy; <?php echo date("Y"); ?> Разработчик ПО: Вердиханов Фейтулла Нейруллаевич</p>
</footer>

<!-- Модальное окно для inline редактирования ячейки -->
<div id="modalInline" class="modal">
  <div class="modal-content">
    <span class="close-button" id="closeInlineModal">&times;</span>
    <h3>Редактирование параметра</h3>
    <form id="inlineEditForm" method="post" action="index.php">
      <input type="hidden" name="action" value="edit_field">
      <input type="hidden" name="equipment_id" id="inline_equipment_id">
      <input type="hidden" name="field_name" id="inline_field_name">
      <label id="inline_label"></label>
      <input type="text" name="new_value" id="inline_new_value" required>
      <button type="submit">Сохранить</button>
    </form>
  </div>
</div>

<!-- Добавляем функции для показа/скрытия уведомлений колокольчика -->
<script>
function toggleNotifications() {
    const list = document.getElementById('notificationList');
    if (list.style.display === 'block') {
        list.style.display = 'none';
    } else {
        list.style.display = 'block';
    }
}
function closeNotifications() {
    document.getElementById('notificationList').style.display = 'none';
}
</script>


















<style>
  th{position:relative;}
  .filter-icon{
    position:absolute;
    bottom:2px;
    right:4px;
    font-size:12px;
    color:#666;
    cursor:pointer;
    user-select:none;
    opacity:0.7;
  }
  th.filtered .filter-icon{color:#0d6efd;opacity:1;}
  #resetFilterBtn{
    padding:6px 12px;
    margin:6px 0;
    background:#f8f9fa;
    border:1px solid #ccc;
    border-radius:4px;
    cursor:pointer;
    font-size:0.9rem;
  }
</style>
<script>
(function(){
  const DATE_FIELDS=['date_submitted','required_date','actual_time'];
  const STATUS_MAP={
    'new':'Новая',
    'pending':'В ожидании',
    'in_progress':'В работе',
    'ready_for_dispatch':'Готово к отправке',
    'shipped':'Отправлено',
    'completed':'Завершена',
    'deleted':'Удалена',
    'отмененная':'Отменена'
  };

  const isoToRu=iso=>{if(!/^\d{4}-\d{2}-\d{2}$/.test(iso))return iso;const[p1,p2,p3]=iso.split('-');return `${p3}.${p2}.${p1}`;};
  const ruToIso=ru=>{if(!/^\d{2}\.\d{2}\.\d{4}$/.test(ru))return ru;const[p1,p2,p3]=ru.split('.');return `${p3}-${p2}-${p1}`;};

  function localizeCell(cell){
    const txt=cell.textContent.trim();
    if(/^\d{4}-\d{2}-\d{2}$/.test(txt)){
      cell.textContent=isoToRu(txt);
    }else if(STATUS_MAP[txt]){
      cell.textContent=STATUS_MAP[txt];
    }
  }
  function localizeTable(table){
    if(!table) return;
    table.querySelectorAll('td').forEach(localizeCell);
  }

  // Override openInlineEditor
  window.openInlineEditor=function(cell){
    const equipmentId=cell.getAttribute('data-id');
    const fieldName=cell.getAttribute('data-field');
    let currentValue=cell.innerText.trim();
    const input=document.getElementById('inline_new_value');
    if(DATE_FIELDS.includes(fieldName)){
       input.type='date';
       currentValue=currentValue.includes('.')?ruToIso(currentValue):currentValue;
       input.value=currentValue;
    }else{
       input.type='text';
       input.value=currentValue;
    }
    document.getElementById('inline_equipment_id').value=equipmentId;
    document.getElementById('inline_field_name').value=fieldName;
    document.getElementById('inline_label').innerText='Изменить '+fieldName+':';
    document.getElementById('modalInline').style.display='block';
  };

  // After submit convert updated cell to localized view
  document.addEventListener('DOMContentLoaded',()=>{
    const form=document.getElementById('inlineEditForm');
    form.addEventListener('submit',()=>{
      // little delay to wait previous handler update cell
      setTimeout(()=>localizeTable(document.getElementById('requestsTable')),200);
    });
  });

  // Column filtering with icon
  function enableColumnFiltering(tableId){
    const table=document.getElementById(tableId)||document.querySelector('table');
    if(!table) return;
    const headerRow=(table.tHead&&table.tHead.rows.length)?table.tHead.rows[0]:table.rows[0];
    const headers=[...headerRow.cells];
    let filters=JSON.parse(localStorage.getItem('columnFilters_'+tableId)||'{}');

    headers.forEach((th,idx)=>{
      if(!th.querySelector('.filter-icon')){
        const icon=document.createElement('span');
        icon.className='filter-icon';
        icon.textContent='\u{1F50D}';
        icon.title='Фильтр';
        icon.onclick=e=>{e.stopPropagation(); openFilter(idx);};
        th.appendChild(icon);
      }
    });

    function openFilter(col){
      const cur=filters[col]||'';
      const val=prompt(cur?`Текущий фильтр: "${cur}".\nВведите новый или оставьте пустым для сброса:`:'Введите значение фильтра:',cur);
      if(val===null) return;
      if(val.trim()==='') delete filters[col]; else filters[col]=val.trim();
      localStorage.setItem('columnFilters_'+tableId,JSON.stringify(filters));
      applyFilters();
    }
    const isHeader=row=>row.querySelector('th')!==null;
    function applyFilters(){
      const rows=[...table.rows];
      rows.forEach(row=>{
        if(isHeader(row)) return;
        let visible=true;
        for(const [c,v] of Object.entries(filters)){
          const cell=row.cells[c];
          if(cell && !cell.textContent.toLowerCase().includes(v.toLowerCase())){visible=false;break;}
        }
        row.style.display=visible?'':'none';
      });
      headers.forEach((th,i)=>th.classList.toggle('filtered', !!filters[i]));
    }
    function resetAll(){
      filters={};
      localStorage.removeItem('columnFilters_'+tableId);
      applyFilters();
    }
    if(!document.getElementById('resetFilterBtn')){
      const btn=document.createElement('button');
      btn.id='resetFilterBtn';
      btn.textContent='Сбросить фильтры';
      btn.onclick=resetAll;
      table.parentNode.insertBefore(btn, table);
    }
    applyFilters();
  }

  window.addEventListener('load',()=>{
    const tblId='requestsTable';
    localizeTable(document.getElementById(tblId));
    enableColumnFiltering(tblId);
  });
})();
</script>

</body>
</html>
