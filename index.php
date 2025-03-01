<?php
// index.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
include_once 'functions.php';

$action = $_GET['action'] ?? 'home';

// Обработка входа
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

// Обработка выхода
if ($action === 'logout') {
    session_destroy();
    header("Location: index.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <title>Система обеспечения потребности бригад</title>
  <link rel="stylesheet" href="style.css">
  <script>
    function addRow() {
      var table = document.getElementById("materialsTable");
      var row = table.insertRow(-1);
      var cell1 = row.insertCell(0);
      var cell2 = row.insertCell(1);
      cell1.innerHTML = '<input type="text" name="material_type[]" required>';
      cell2.innerHTML = '<input type="text" name="material_desc[]" required>';
    }
    function goToPage() {
      var page = document.getElementById("pageInput").value;
      var url = new URL(window.location.href);
      url.searchParams.set("page", page);
      window.location.href = url.href;
    }
  </script>
</head>
<body>
<?php
if (!isset($_SESSION['user'])):
  if ($action === 'register'):
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      $username         = trim($_POST['username'] ?? '');
      $password         = trim($_POST['password'] ?? '');
      $confirm_password = trim($_POST['confirm_password'] ?? '');
      $role             = $_POST['role'] ?? '';
      if ($password !== $confirm_password) {
        $regError = "Пароли не совпадают.";
      } else {
        $result = registerUser($username, $password, $role);
        if ($result === true) {
          echo "<p class='success'>Регистрация прошла успешно! Теперь вы можете <a href='index.php'>войти</a>.</p>";
          exit;
        } else {
          $regError = $result;
        }
      }
    }
    ?>
    <div class="login-container">
      <h2>Регистрация</h2>
      <?php if (isset($regError)) echo "<p class='error'>" . htmlspecialchars((string)$regError, ENT_QUOTES, 'UTF-8') . "</p>"; ?>
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
          <select name="role" required>
            <option value="technologist">Технолог</option>
            <option value="chief">Начальник смены ЦИТС</option>
            <option value="executor">Исполнитель</option>
            <option value="admin">Админ</option>
          </select>
        </label>
        <input type="submit" value="Зарегистрироваться">
      </form>
      <p>Уже есть аккаунт? <a href="index.php">Войти</a></p>
    </div>
  <?php else: ?>
    <div class="login-container">
      <h2>Вход в систему</h2>
      <?php if (isset($loginError)) echo "<p class='error'>" . htmlspecialchars((string)$loginError, ENT_QUOTES, 'UTF-8') . "</p>"; ?>
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
?>
  <header>
    <h1>Система обеспечения потребности бригад</h1>
    <h2>Личный кабинет: <?php echo htmlspecialchars((string)$_SESSION['user'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo htmlspecialchars((string)$role, ENT_QUOTES, 'UTF-8'); ?>)</h2>
    <a href="?action=logout" class="logout">Выйти</a>
  </header>
  <!-- Оптимизированное меню: для каждой роли свои вкладки -->
  <nav>
    <ul>
      <?php
      if ($role === 'technologist') {
        echo '<li><a href="?action=addRequest">Добавить заявку</a></li>';
        echo '<li><a href="?action=viewRequests">Мои заявки</a></li>';
      } elseif ($role === 'chief') {
        echo '<li><a href="?action=viewRequests">Просмотр заявок</a></li>';
        echo '<li><a href="?action=deletionRequests">Запросы на удаление</a></li>';
      } elseif ($role === 'executor') {
        echo '<li><a href="?action=updateApplication">Обновить статус заявки</a></li>';
        echo '<li><a href="?action=findUrgent">Найти срочные заявки</a></li>';
        echo '<li><a href="?action=viewRequests">Мои заявки</a></li>';
      } elseif ($role === 'admin') {
        echo '<li><a href="?action=viewRequests">Просмотр всех заявок</a></li>';
        echo '<li><a href="?action=deletionRequests">Запросы на удаление</a></li>';
        echo '<li><a href="?action=exportExcel">Экспорт в CSV</a></li>';
      }
      // Общие вкладки
      echo '<li><a href="?action=viewRequests&filter=waiting">Ожидающие</a></li>';
      echo '<li><a href="?action=viewRequests&filter=overdue">Просроченные</a></li>';
      echo '<li><a href="?action=viewRequests&filter=archive">Архив</a></li>';
      ?>
    </ul>
  </nav>
  <main>
  <?php
  // Фильтрация – добавлен выбор статуса из выпадающего списка
  if ($action === 'viewRequests'):
  ?>
    <div class="filter-form">
      <form method="get" action="index.php">
        <input type="hidden" name="action" value="viewRequests">
        <div class="filter-row">
          <label>Дата подачи от:<br>
            <input type="date" name="f_date_from" value="<?php echo htmlspecialchars($_GET['f_date_from'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
          </label>
          <label>до:<br>
            <input type="date" name="f_date_to" value="<?php echo htmlspecialchars($_GET['f_date_to'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
          </label>
        </div>
        <div class="filter-row">
          <label>Смена:<br>
            <input type="text" name="f_shift" placeholder="Например, 1" value="<?php echo htmlspecialchars($_GET['f_shift'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
          </label>
          <label>ЦДНГ:<br>
            <input type="text" name="f_cdng" placeholder="Например, 1" value="<?php echo htmlspecialchars($_GET['f_cdng'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
          </label>
          <label>Цех КРС:<br>
            <input type="text" name="f_ceh_krs" placeholder="Например, 2" value="<?php echo htmlspecialchars($_GET['f_ceh_krs'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
          </label>
        </div>
        <div class="filter-row">
          <label>Бригада:<br>
            <input type="text" name="f_brigade" value="<?php echo htmlspecialchars($_GET['f_brigade'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
          </label>
          <label>Мастер:<br>
            <input type="text" name="f_master" value="<?php echo htmlspecialchars($_GET['f_master'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
          </label>
          <label>Куст:<br>
            <input type="text" name="f_kust" value="<?php echo htmlspecialchars($_GET['f_kust'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
          </label>
        </div>
        <div class="filter-row">
          <label>Скважина:<br>
            <input type="text" name="f_skvazhina" value="<?php echo htmlspecialchars($_GET['f_skvazhina'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
          </label>
          <label>Вид заявки:<br>
            <input type="text" name="f_type" value="<?php echo htmlspecialchars($_GET['f_type'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
          </label>
          <label>Статус:<br>
            <select name="f_status">
              <option value="">Все</option>
              <option value="in_progress" <?php if (($_GET['f_status'] ?? '') === 'in_progress') echo 'selected'; ?>>В работе</option>
              <option value="not_available" <?php if (($_GET['f_status'] ?? '') === 'not_available') echo 'selected'; ?>>Нет в наличии</option>
              <option value="ready_for_dispatch" <?php if (($_GET['f_status'] ?? '') === 'ready_for_dispatch') echo 'selected'; ?>>Готово к отправке</option>
            </select>
          </label>
        </div>
        <input type="submit" value="Применить фильтры">
      </form>
    </div>
  <?php
  endif;
  
  switch ($action) {

    case 'viewRequest':
      if (!isset($_GET['id'])) {
        echo "<p class='error'>Не указан идентификатор заявки.</p>";
        break;
      }
      $id = intval($_GET['id']);
      $req = getRequestById($id);
      if (!$req) {
        echo "<p class='error'>Заявка не найдена.</p>";
        break;
      }
      ?>
      <h2>Подробности заявки № <?php echo htmlspecialchars((string)($req['group_id'] ?: $req['id']), ENT_QUOTES, 'UTF-8'); ?></h2>
      <div class="table-container">
        <table class="details">
          <tr>
            <?php 
            $fields = ['Дата подачи', 'Смена', 'ЦДНГ', 'Цех КРС', 'Бригада', 'Мастер', 'Куст', 'Скважина', 'Вид заявки', 'Заявка', 'Отв. исполнитель', 'Треб. дата', 'Треб. время', 'Исполнитель', 'Статус', 'Факт. время', 'Примечание', 'Длит. выполнение (дней)', 'Свыше 10 дней', 'Свыше 3 дней'];
            foreach ($fields as $field) {
              echo "<th>" . htmlspecialchars((string)$field, ENT_QUOTES, 'UTF-8') . "</th>";
            }
            ?>
          </tr>
          <tr>
            <?php 
            $values = [
              'date_submitted'        => $req['date_submitted'] ?? '',
              'shift'                 => $req['shift'] ?? '',
              'cdng'                  => $req['cdng'] ?? '',
              'ceh_krs'               => $req['ceh_krs'] ?? '',
              'brigade'               => $req['brigade'] ?? '',
              'master'                => $req['master'] ?? '',
              'kust'                  => $req['kust'] ?? '',
              'skvazhina'             => $req['skvazhina'] ?? '',
              'type'                  => $req['type'] ?? '',
              'description'           => $req['description'] ?? '',
              'responsible_executive' => $req['responsible_executive'] ?? '',
              'required_date'         => $req['required_date'] ?? '',
              'required_time'         => $req['required_time'] ?? '',
              'executor'              => $req['executor'] ?? '',
              'status'                => $req['status'] ?? '',
              'actual_time'           => $req['actual_time'] ?? '',
              'note'                  => $req['note'] ?? '',
              'long_execution'        => (!empty($req['date_submitted']) && !empty($req['required_date'])) ? (new DateTime($req['date_submitted']))->diff(new DateTime($req['required_date']))->days : '',
              'delay_over_10'         => (!empty($req['date_submitted']) && !empty($req['required_date']) && ((new DateTime($req['date_submitted']))->diff(new DateTime($req['required_date']))->days) > 10) ? "Свыше 10 дней" : "Менее 10 дней",
              'delay_over_3'          => (!empty($req['date_submitted']) && !empty($req['required_date']) && ((new DateTime($req['date_submitted']))->diff(new DateTime($req['required_date']))->days) > 3) ? "Свыше 3 дней" : "Менее 3 дней"
            ];
            foreach ($values as $val) {
              echo "<td>" . htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8') . "</td>";
            }
            ?>
          </tr>
        </table>
      </div>
      <div class="extra-functions">
        <?php if ($role === 'technologist'): ?>
          <button onclick="window.location.href='?action=confirmRequest&id=<?php echo htmlspecialchars((string)($req['group_id'] ?: $req['id']), ENT_QUOTES, 'UTF-8'); ?>'">Подтвердить выполнение заявки</button>
        <?php endif; ?>
      </div>
      <?php
      break;

    case 'addRequest':
      if ($role !== 'technologist') {
        echo "<p class='error'>Доступ запрещен.</p>";
        break;
      }
      if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = [
          'date_submitted' => $_POST['date_submitted'] ?? date("Y-m-d"),
          'shift'          => $_POST['shift'] ?? '',
          'cdng'           => $_POST['cdng'] ?? '',
          'ceh_krs'        => $_POST['ceh_krs'] ?? '',
          'brigade'        => $_POST['brigade'] ?? '',
          'master'         => $_POST['master'] ?? '',
          'kust'           => $_POST['kust'] ?? '',
          'skvazhina'      => $_POST['skvazhina'] ?? '',
          'required_date'  => $_POST['required_date'] ?? '',
          'required_time'  => $_POST['required_time'] ?? ''
        ];
        $firstMaterial = [
          'type'        => $_POST['material_type'][0] ?? '',
          'description' => $_POST['material_desc'][0] ?? ''
        ];
        $firstData = array_merge($data, $firstMaterial);
        $groupId = addRequest($firstData);
        if ($groupId) {
          if (isset($_POST['material_type']) && count($_POST['material_type']) > 1) {
            $count = count($_POST['material_type']);
            for ($i = 1; $i < $count; $i++) {
              $materialData = [
                'type'        => $_POST['material_type'][$i],
                'description' => $_POST['material_desc'][$i]
              ];
              addRequestPosition($groupId, $materialData);
            }
          }
          echo "<p class='success'>Заявка успешно добавлена. Номер заявки (group_id): " . htmlspecialchars((string)$groupId, ENT_QUOTES, 'UTF-8') . "</p>";
          echo "<p><a href='?action=viewRequest&id=" . htmlspecialchars((string)$groupId, ENT_QUOTES, 'UTF-8') . "' target='_blank'>Открыть заявку в новом окне</a></p>";
        } else {
          echo "<p class='error'>Ошибка при добавлении заявки.</p>";
        }
      }
      ?>
      <h2>Добавить заявку (табличный режим)</h2>
      <form method="post" action="?action=addRequest">
        <fieldset>
          <legend>Общие данные заявки</legend>
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
          <label>Требуемая дата исполнения:<br>
            <input type="date" name="required_date">
          </label>
          <label>Требуемое время:<br>
            <input type="text" name="required_time">
          </label>
        </fieldset>
        <fieldset>
          <legend>Номенклатура (позиции заявки)</legend>
          <div class="table-container">
            <table id="materialsTable">
              <tr>
                <th>Вид заявки</th>
                <th>Описание позиции</th>
              </tr>
              <tr>
                <td><input type="text" name="material_type[]" required></td>
                <td><input type="text" name="material_desc[]" required></td>
              </tr>
            </table>
          </div>
          <button type="button" onclick="addRow()">Добавить новую позицию</button>
        </fieldset>
        <input type="submit" value="Сохранить заявку">
      </form>
      <?php
      break;

    case 'assignPosition':
      if ($role !== 'chief') {
        echo "<p class='error'>Доступ запрещен.</p>";
        break;
      }
      $posId = isset($_GET['id']) ? intval($_GET['id']) : 0;
      if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $posId = intval($_POST['id'] ?? 0);
        $executor = trim($_POST['executor'] ?? '');
        if (assignPosition($posId, $executor)) {
          echo "<p class='success'>Исполнитель назначен для позиции № " . htmlspecialchars((string)$posId, ENT_QUOTES, 'UTF-8') . ".</p>";
        } else {
          echo "<p class='error'>Ошибка при назначении исполнителя.</p>";
        }
      } else {
        $executors = getExecutors();
        ?>
        <h2>Назначить исполнителя для позиции № <?php echo htmlspecialchars((string)$posId, ENT_QUOTES, 'UTF-8'); ?></h2>
        <form method="post" action="?action=assignPosition">
          <input type="hidden" name="id" value="<?php echo htmlspecialchars((string)$posId, ENT_QUOTES, 'UTF-8'); ?>">
          <label>Выберите исполнителя:<br>
            <select name="executor" required>
              <?php foreach ($executors as $exec): ?>
                <option value="<?php echo htmlspecialchars((string)$exec['username'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)$exec['username'], ENT_QUOTES, 'UTF-8'); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <input type="submit" value="Назначить исполнителя">
        </form>
        <?php
      }
      break;

    case 'updateApplication':
      if ($role !== 'executor') {
        echo "<p class='error'>Доступ запрещен.</p>";
        break;
      }
      if (isset($_GET['id'])) {
        $appId = intval($_GET['id']);
        $app = getRequestById($appId);
        if (!$app) {
          echo "<p class='error'>Заявка не найдена.</p>";
          break;
        }
        if ($app['executor'] !== $_SESSION['user']) {
          echo "<p class='error'>Данная заявка не назначена вам.</p>";
          break;
        }
        ?>
        <h2>Обновление статуса заявки № <?php echo htmlspecialchars((string)($app['group_id'] ?: $app['id']), ENT_QUOTES, 'UTF-8'); ?></h2>
        <div class="table-container">
          <table class="details">
            <tr>
              <?php 
              $fields = ['Дата подачи', 'Смена', 'ЦДНГ', 'Цех КРС', 'Бригада', 'Мастер', 'Куст', 'Скважина', 'Вид заявки', 'Заявка', 'Статус'];
              foreach ($fields as $field) {
                echo "<th>" . htmlspecialchars((string)$field, ENT_QUOTES, 'UTF-8') . "</th>";
              }
              ?>
            </tr>
            <tr>
              <?php 
              $values = [
                'date_submitted' => $app['date_submitted'] ?? '',
                'shift'          => $app['shift'] ?? '',
                'cdng'           => $app['cdng'] ?? '',
                'ceh_krs'        => $app['ceh_krs'] ?? '',
                'brigade'        => $app['brigade'] ?? '',
                'master'         => $app['master'] ?? '',
                'kust'           => $app['kust'] ?? '',
                'skvazhina'      => $app['skvazhina'] ?? '',
                'type'           => $app['type'] ?? '',
                'description'    => $app['description'] ?? '',
                'status'         => $app['status'] ?? ''
              ];
              foreach ($values as $val) {
                echo "<td>" . htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8') . "</td>";
              }
              ?>
            </tr>
          </table>
        </div>
        <form method="post" action="?action=updateApplication">
          <input type="hidden" name="id" value="<?php echo htmlspecialchars((string)$app['id'], ENT_QUOTES, 'UTF-8'); ?>">
          <label>Выберите новый статус:<br>
            <select name="status" required>
              <option value="в работе">В работе</option>
              <option value="нет в наличии">Нет в наличии</option>
              <option value="готово к отправке">Готово к отправке</option>
            </select>
          </label>
          <input type="submit" value="Обновить статус">
        </form>
        <?php
      } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $appId = intval($_POST['id'] ?? 0);
        $status = trim($_POST['status'] ?? '');
        if ($status === 'в работе') {
          $status = 'in_progress';
        } elseif ($status === 'нет в наличии') {
          $status = 'not_available';
        } elseif ($status === 'готово к отправке') {
          $status = 'ready_for_dispatch';
        }
        if (updateRequestStatus($appId, $status)) {
          echo "<p class='success'>Статус заявки № " . htmlspecialchars((string)$appId, ENT_QUOTES, 'UTF-8') . " обновлён на " . htmlspecialchars((string)$status, ENT_QUOTES, 'UTF-8') . ".</p>";
        } else {
          echo "<p class='error'>Ошибка при обновлении статуса.</p>";
        }
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
        <table>
          <tr>
            <th>ID</th>
            <th>Дата подачи</th>
            <th>Требуемая дата</th>
            <th>Осталось дней</th>
            <th>Статус</th>
            <th>Действие</th>
          </tr>
          <?php foreach ($urgent as $req): 
            $days = "";
            if (!empty($req['date_submitted']) && !empty($req['required_date'])) {
              $d1 = new DateTime($req['date_submitted']);
              $d2 = new DateTime($req['required_date']);
              $days = $d1->diff($d2)->days;
            }
          ?>
          <tr>
            <td><a href="?action=viewRequest&id=<?php echo htmlspecialchars((string)$req['id'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank"><?php echo htmlspecialchars((string)$req['id'], ENT_QUOTES, 'UTF-8'); ?></a></td>
            <td><?php echo htmlspecialchars((string)$req['date_submitted'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars((string)$req['required_date'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars((string)$days, ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars((string)$req['status'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td><a href="?action=updateApplication&id=<?php echo htmlspecialchars((string)$req['id'], ENT_QUOTES, 'UTF-8'); ?>">Обновить статус</a></td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
      <?php
      break;

    case 'viewRequests':
      $currentUser = ($_SESSION['role'] === 'executor') ? $_SESSION['user'] : "";
      $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
      $limit = 20;
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
      $filter = $_GET['filter'] ?? '';
      $requests = getRequestsByFilterAdvanced($filter, $advancedFilters, $offset, $limit, $_SESSION['role'], $currentUser);
      $total = getRequestsCountAdvanced($filter, $advancedFilters, $_SESSION['role'], $currentUser);
      $totalPages = ceil($total / $limit);
      ?>
      <h2>Список заявок<?php echo ($filter ? " (фильтр: " . htmlspecialchars((string)$filter, ENT_QUOTES, 'UTF-8') . ")" : ""); ?></h2>
      <div class="table-container">
        <table>
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
            <th>Заявка</th>
            <th>Отв. исполнитель</th>
            <th>Треб. дата</th>
            <th>Треб. время</th>
            <th>Исполнитель</th>
            <th>Статус</th>
            <th>Факт. время</th>
            <th>Примечание</th>
            <th>Длит. выполнение (дней)</th>
            <th>Свыше 10 дней</th>
            <th>Свыше 3 дней</th>
            <th>Действия</th>
          </tr>
          <?php 
          $i = $offset + 1;
          foreach ($requests as $req):
            $days = "";
            $over10 = "Менее 10 дней";
            $over3  = "Менее 3 дней";
            if (!empty($req['date_submitted']) && !empty($req['required_date'])) {
              $d1 = new DateTime($req['date_submitted']);
              $d2 = new DateTime($req['required_date']);
              $days = $d1->diff($d2)->days;
              if ($days > 10) { $over10 = "Свыше 10 дней"; }
              if ($days > 3)  { $over3  = "Свыше 3 дней"; }
            }
          ?>
          <tr>
            <td><a href="?action=viewRequest&id=<?php echo htmlspecialchars((string)($req['group_id'] ?: $req['id']), ENT_QUOTES, 'UTF-8'); ?>" target="_blank"><?php echo $i++; ?></a></td>
            <td><?php echo htmlspecialchars((string)$req['date_submitted'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars((string)$req['shift'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars((string)$req['cdng'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars((string)$req['ceh_krs'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars((string)$req['brigade'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars((string)$req['master'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars((string)$req['kust'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars((string)$req['skvazhina'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars((string)$req['type'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars((string)$req['description'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars((string)$req['responsible_executive'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars((string)$req['required_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars((string)$req['required_time'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars((string)$req['executor'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars((string)$req['status'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars((string)$req['actual_time'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars((string)$req['note'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars((string)$days, ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars((string)$over10, ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars((string)$over3, ENT_QUOTES, 'UTF-8'); ?></td>
            <td>
              <?php 
              if ($role === 'chief' && empty($req['executor'])) {
                echo "<a href='?action=assignPosition&id=" . htmlspecialchars((string)$req['id'], ENT_QUOTES, 'UTF-8') . "'>Назначить исполнителя</a>";
              } elseif ($role === 'executor') {
                echo "<a href='?action=updateApplication&id=" . htmlspecialchars((string)$req['id'], ENT_QUOTES, 'UTF-8') . "'>Обновить статус</a>";
              }
              ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
      <div class="pagination">
        <?php if ($page > 1): ?>
          <a href="?action=viewRequests&page=<?php echo $page - 1 . ($filter ? "&filter=" . urlencode($filter) : ""); ?>">&laquo; Предыдущая</a>
        <?php endif; ?>
        Страница <?php echo $page; ?> из <?php echo $totalPages; ?>
        <input type="number" id="pageInput" min="1" max="<?php echo $totalPages; ?>" placeholder="Страница">
        <button type="button" onclick="goToPage()">Перейти</button>
        <?php if ($page < $totalPages): ?>
          <a href="?action=viewRequests&page=<?php echo $page + 1 . ($filter ? "&filter=" . urlencode($filter) : ""); ?>">Следующая &raquo;</a>
        <?php endif; ?>
      </div>
      <?php
      break;

    case 'exportExcel':
      if ($role !== 'admin') {
        echo "<p class='error'>Доступ запрещен.</p>";
        break;
      }
      exportRequestsToCSV();
      break;

    case 'updateApplication':
      if ($role !== 'executor') {
        echo "<p class='error'>Доступ запрещен.</p>";
        break;
      }
      if (isset($_GET['id'])) {
        $appId = intval($_GET['id']);
        $app = getRequestById($appId);
        if (!$app) {
          echo "<p class='error'>Заявка не найдена.</p>";
          break;
        }
        if ($app['executor'] !== $_SESSION['user']) {
          echo "<p class='error'>Данная заявка не назначена вам.</p>";
          break;
        }
        ?>
        <h2>Обновление статуса заявки № <?php echo htmlspecialchars((string)($app['group_id'] ?: $app['id']), ENT_QUOTES, 'UTF-8'); ?></h2>
        <div class="table-container">
          <table class="details">
            <tr>
              <?php 
              $fields = ['Дата подачи', 'Смена', 'ЦДНГ', 'Цех КРС', 'Бригада', 'Мастер', 'Куст', 'Скважина', 'Вид заявки', 'Заявка', 'Статус'];
              foreach ($fields as $field) {
                echo "<th>" . htmlspecialchars((string)$field, ENT_QUOTES, 'UTF-8') . "</th>";
              }
              ?>
            </tr>
            <tr>
              <?php 
              $values = [
                'date_submitted' => $app['date_submitted'] ?? '',
                'shift'          => $app['shift'] ?? '',
                'cdng'           => $app['cdng'] ?? '',
                'ceh_krs'        => $app['ceh_krs'] ?? '',
                'brigade'        => $app['brigade'] ?? '',
                'master'         => $app['master'] ?? '',
                'kust'           => $app['kust'] ?? '',
                'skvazhina'      => $app['skvazhina'] ?? '',
                'type'           => $app['type'] ?? '',
                'description'    => $app['description'] ?? '',
                'status'         => $app['status'] ?? ''
              ];
              foreach ($values as $val) {
                echo "<td>" . htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8') . "</td>";
              }
              ?>
            </tr>
          </table>
        </div>
        <form method="post" action="?action=updateApplication">
          <input type="hidden" name="id" value="<?php echo htmlspecialchars((string)$app['id'], ENT_QUOTES, 'UTF-8'); ?>">
          <label>Выберите новый статус:<br>
            <select name="status" required>
              <option value="в работе">В работе</option>
              <option value="нет в наличии">Нет в наличии</option>
              <option value="готово к отправке">Готово к отправке</option>
            </select>
          </label>
          <input type="submit" value="Обновить статус">
        </form>
        <?php
      } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $appId = intval($_POST['id'] ?? 0);
        $status = trim($_POST['status'] ?? '');
        if ($status === 'в работе') {
          $status = 'in_progress';
        } elseif ($status === 'нет в наличии') {
          $status = 'not_available';
        } elseif ($status === 'готово к отправке') {
          $status = 'ready_for_dispatch';
        }
        if (updateRequestStatus($appId, $status)) {
          echo "<p class='success'>Статус заявки № " . htmlspecialchars((string)$appId, ENT_QUOTES, 'UTF-8') . " обновлён на " . htmlspecialchars((string)$status, ENT_QUOTES, 'UTF-8') . ".</p>";
        } else {
          echo "<p class='error'>Ошибка при обновлении статуса.</p>";
        }
      }
      break;

    case 'confirmRequest':
      if ($role !== 'technologist') {
        echo "<p class='error'>Доступ запрещен.</p>";
        break;
      }
      if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $requestId = intval($_POST['request_id'] ?? 0);
        if (confirmRequest($requestId)) {
          echo "<p class='success'>Заявка № " . htmlspecialchars((string)$requestId, ENT_QUOTES, 'UTF-8') . " подтверждена и переведена в архив.</p>";
        } else {
          echo "<p class='error'>Ошибка при подтверждении заявки.</p>";
        }
      } else {
        ?>
        <h2>Подтвердить выполнение заявки</h2>
        <form method="post" action="?action=confirmRequest">
          <label>ID позиции (любая из группы):<br>
            <input type="number" name="request_id" required>
          </label>
          <input type="submit" value="Подтвердить заявку">
        </form>
        <?php
      }
      break;

    default:
      echo "<h2>Добро пожаловать в систему обеспечения потребности бригад</h2>";
      echo "<p>Выберите нужное действие из меню.</p>";
      break;
  }
  ?>
  </main>
  <footer>
    <p>&copy; <?php echo date("Y"); ?> Система обеспечения потребности бригад</p>
  </footer>
<?php endif; ?>
</body>
</html>
