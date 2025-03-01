<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

// Чтобы избежать проблем с отображением UTF-8 в браузере:
header("Content-Type: text/html; charset=UTF-8");

include_once 'functions.php';

$action = $_GET['action'] ?? 'home';

// ------------------- Блок логина / логаута -------------------
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
  <!-- Подключаем Chart.js для инфографики -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script>
    // Добавить новую строку (позицию) в таблицу позиций
    function addPosition() {
      var table = document.getElementById("positionsTable");
      var row = table.insertRow(-1);

      // Вид заявки
      var cellType = row.insertCell(0);
      cellType.innerHTML = '<input type="text" name="type[]" placeholder="Вид заявки" required>';

      // Наименование
      var cellDesc = row.insertCell(1);
      cellDesc.innerHTML = '<input type="text" name="description[]" placeholder="Наименование" required>';

      // Кнопка «Удалить»
      var cellRemove = row.insertCell(2);
      cellRemove.innerHTML = '<button type="button" onclick="removePosition(this)">Удалить</button>';
    }

    // Удалить строку (позицию)
    function removePosition(btn) {
      var row = btn.parentNode.parentNode;
      row.parentNode.removeChild(row);
    }

    // Переход на нужную страницу (пагинация)
    function goToPage() {
      var page = document.getElementById("pageInput").value;
      var url = new URL(window.location.href);
      url.searchParams.set("page", page);
      window.location.href = url.href;
    }

    // Скрипты для выпадающего списка уведомлений
    function toggleNotifications() {
      const list = document.getElementById("notificationList");
      list.classList.toggle("active");
    }
    function closeNotifications() {
      const list = document.getElementById("notificationList");
      list.classList.remove("active");
    }
  </script>
</head>
<body>
<?php
// ------------------- Страница логина/регистрации -------------------
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
  // ------------------- Основной интерфейс (после входа) -------------------
  $role = $_SESSION['role'];

  // Вместо прямого счёта уведомлений здесь — просто рендер колокольчика
  // из functions.php (renderNotificationsBell).
  // Но, если хотите, можно оставить как есть. Пример:
  echo "<header>";
  echo "<h1>Система обеспечения потребности бригад</h1>";
  echo "<h2>Личный кабинет: " . htmlspecialchars($_SESSION['user'], ENT_QUOTES, 'UTF-8')
       . " (" . htmlspecialchars($role, ENT_QUOTES, 'UTF-8') . ")</h2>";

  // Вывод колокольчика:
  echo renderNotificationsBell($_SESSION['user']);
?>
    <nav class="user-nav">
      <a href="?action=profile">Личный кабинет</a>
      <a href="?action=report">Формирование отчета</a>
      <a href="?action=logout" class="logout">Выйти</a>
    </nav>
  </header>
  
  <nav>
    <ul>
      <?php
      // Меню в зависимости от роли
      if ($role === 'technologist') {
        echo '<li><a href="?action=addRequest">Добавить заявку</a></li>';
        echo '<li><a href="?action=viewRequests">Мои заявки</a></li>';
        echo '<li><a href="?action=managerInfo">Информация (Графики)</a></li>';
      } elseif ($role === 'chief') {
        echo '<li><a href="?action=viewRequests">Просмотр заявок</a></li>';
        echo '<li><a href="?action=deletionRequests">Запросы на удаление</a></li>';
        echo '<li><a href="?action=managerInfo">Информация (Графики)</a></li>';
      } elseif ($role === 'executor') {
        echo '<li><a href="?action=updateApplication">Обновить статус позиции</a></li>';
        echo '<li><a href="?action=findUrgent">Найти срочные заявки</a></li>';
        echo '<li><a href="?action=viewRequests">Мои заявки</a></li>';
      } elseif ($role === 'admin') {
        echo '<li><a href="?action=viewRequests">Просмотр всех заявок</a></li>';
        echo '<li><a href="?action=deletionRequests">Запросы на удаление</a></li>';
        echo '<li><a href="?action=exportExcel">Экспорт в CSV</a></li>';
        echo '<li><a href="?action=managerInfo">Информация (Графики)</a></li>';
      }

      // Общие фильтры
      echo '<li><a href="?action=viewRequests&filter=waiting">Ожидающие</a></li>';
      echo '<li><a href="?action=viewRequests&filter=overdue">Просроченные</a></li>';
      echo '<li><a href="?action=viewRequests&filter=archive">Архив</a></li>';
      ?>
    </ul>
  </nav>

  <main>
  <?php
  // ------------------- Обработка действий (switch) -------------------
  switch ($action) {

    // ========== (A) AJAX обнуления счётчика уведомлений ==========
    case 'markAllRead':
      if ($_SERVER['REQUEST_METHOD'] === 'POST') {
          $username = $_POST['username'] ?? '';
          if (!empty($username)) {
              markAllNotificationsAsRead($username);
          }
          echo "OK"; // Можно вернуть любой ответ
      }
      exit;

    // ========== (1) Добавление заявки (технолог) + динамические позиции ==========
    case 'addRequest':
      if ($role !== 'technologist') {
          echo "<p class='error'>Доступ запрещен.</p>";
          break;
      }
      if ($_SERVER['REQUEST_METHOD'] === 'POST') {
          $date_submitted = trim($_POST['date_submitted'] ?? '');
          $shift = trim($_POST['shift'] ?? '');
          $cdng = trim($_POST['cdng'] ?? '');
          $ceh_krs = trim($_POST['ceh_krs'] ?? '');
          $brigade = trim($_POST['brigade'] ?? '');
          $master = trim($_POST['master'] ?? '');
          $kust = trim($_POST['kust'] ?? '');
          $skvazhina = trim($_POST['skvazhina'] ?? '');
          $required_date = trim($_POST['required_date'] ?? '');
          $required_time = trim($_POST['required_time'] ?? '');

          $data = [
            'date_submitted' => $date_submitted,
            'shift'          => $shift,
            'cdng'           => $cdng,
            'ceh_krs'        => $ceh_krs,
            'brigade'        => $brigade,
            'master'         => $master,
            'kust'           => $kust,
            'skvazhina'      => $skvazhina,
            'type'           => '',
            'description'    => '',
            'required_date'  => $required_date,
            'required_time'  => $required_time,
            'created_by'     => $_SESSION['user']
          ];
          $newGroupId = addRequest($data);

          if ($newGroupId) {
              // Добавляем позиции
              if (!empty($_POST['type']) && is_array($_POST['type'])) {
                  foreach ($_POST['type'] as $idx => $valType) {
                      $valType = trim($valType);
                      $valDesc = trim($_POST['description'][$idx] ?? '');
                      if ($valType !== '' || $valDesc !== '') {
                          addRequestPosition($newGroupId, [
                              'type'        => $valType,
                              'description' => $valDesc
                          ]);
                      }
                  }
              }
              echo "<p class='success'>Заявка успешно создана (group_id: $newGroupId).</p>";
          } else {
              echo "<p class='error'>Ошибка при сохранении заявки.</p>";
          }

      } else {
          // Форма добавления
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
            <label>Требуемая дата:<br>
              <input type="date" name="required_date">
            </label>
            <label>Требуемое время:<br>
              <input type="text" name="required_time">
            </label>

            <hr>
            <h3>Позиции заявки</h3>
            <p>Введите «Вид заявки» и «Наименование», можно несколько.</p>
            <table id="positionsTable" border="1" style="border-collapse: collapse;">
              <tr>
                <th>Вид заявки</th>
                <th>Наименование</th>
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

    // ========== (2) Назначение исполнителя (chief) ==========
    case 'assignPosition':
      if ($role !== 'chief') {
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

      // Обработка формы назначения
      if ($_SERVER['REQUEST_METHOD'] === 'POST') {
          $executor = trim($_POST['executor'] ?? '');
          if ($executor === '') {
              echo "<p class='error'>Не выбран исполнитель.</p>";
          } else {
              if (assignPositionById($posId, $executor)) {
                  echo "<p class='success'>Исполнитель '$executor' назначен на позицию № $posId.</p>";
              } else {
                  echo "<p class='error'>Ошибка при назначении исполнителя.</p>";
              }
          }
      } else {
          // Показать форму со списком исполнителей
          $executors = getExecutors();
          ?>
          <h2>Назначить исполнителя на позицию № <?php echo htmlspecialchars($posId, ENT_QUOTES, 'UTF-8'); ?></h2>
          <form method="post" action="?action=assignPosition&id=<?php echo htmlspecialchars($posId, ENT_QUOTES, 'UTF-8'); ?>">
            <label>Исполнитель:<br>
              <select name="executor" required>
                <option value="">-- Выберите исполнителя --</option>
                <?php foreach ($executors as $exec): ?>
                  <option value="<?php echo htmlspecialchars($exec['username'], ENT_QUOTES, 'UTF-8'); ?>">
                    <?php echo htmlspecialchars($exec['username'], ENT_QUOTES, 'UTF-8'); ?>
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

    // ========== (3) Личный кабинет пользователя ==========
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
              <input type="text" name="fullname" 
                     value="<?php echo htmlspecialchars($profile['fullname'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
          </label>
          <label>Должность:<br>
              <input type="text" name="position" 
                     value="<?php echo htmlspecialchars($profile['position'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
          </label>
          <label>Email:<br>
              <input type="email" name="email" 
                     value="<?php echo htmlspecialchars($profile['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
          </label>
          <label>Телефон:<br>
              <input type="text" name="phone" 
                     value="<?php echo htmlspecialchars($profile['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
          </label>
          <input type="submit" value="Сохранить">
      </form>
      <?php
      break;

    // ========== (4) Формирование отчета (Excel) ==========
    case 'report':
      if ($_SERVER['REQUEST_METHOD'] === 'POST') {
          ob_clean();
          generateExcelReport(
              $_POST['start_date'] ?? '', 
              $_POST['end_date'] ?? '', 
              $_POST['criteria'] ?? 'all'
          );
          exit;
      } else {
          ?>
          <h2>Формирование отчета</h2>
          <form method="post" action="?action=report">
              <label>Дата начала:<br>
                  <input type="date" name="start_date">
              </label>
              <label>Дата окончания:<br>
                  <input type="date" name="end_date">
              </label>
              <label>Критерий:<br>
                  <select name="criteria">
                      <option value="all">Все заявки</option>
                      <option value="over10">Свыше 10 дней</option>
                      <option value="over3">Свыше 3 дней</option>
                      <option value="completed">Исполненные</option>
                  </select>
              </label>
              <input type="submit" value="Сформировать отчет">
          </form>
          <?php
      }
      break;
    // Пример: в switch():
    case 'managerInfo':
        if (($role === 'technologist') || ($role === 'chief')) {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $start_date = $_POST['start_date'] ?? '';
                $end_date   = $_POST['end_date'] ?? '';
                showTechChiefDashboard($start_date, $end_date);
            } else {
                // Форма
                echo '<h2>Информация за период</h2>';
                echo '<form method="post" action="?action=managerInfo">';
                echo '  <label>Дата начала: <input type="date" name="start_date"></label><br><br>';
                echo '  <label>Дата окончания: <input type="date" name="end_date"></label><br><br>';
                echo '  <input type="submit" value="Сформировать отчет">';
                echo '</form>';
            }
        } else {
            echo '<p class="error">Доступ запрещен.</p>';
        }
        break;

        
    // ========== (5) Просмотр одной заявки (группы позиций) ==========
    case 'viewRequest':
      if (!isset($_GET['id'])) {
          echo "<p class='error'>Не указан идентификатор заявки.</p>";
          break;
      }
      $group_id = intval($_GET['id']);
      $positions = getRequestById($group_id);
      if (!$positions || count($positions) === 0) {
          echo "<p class='error'>Заявка не найдена.</p>";
          break;
      }
      ?>
      <h2>Подробности заявки № <?php echo htmlspecialchars($group_id, ENT_QUOTES, 'UTF-8'); ?></h2>
      <table class="details">
          <tr>
              <th>ID позиции</th>
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
              <th>Отв. исполнитель</th>
              <th>Треб. дата</th>
              <th>Треб. время</th>
              <th>Исполнитель</th>
              <th>Статус</th>
              <th>Факт. время</th>
              <th>Примечание</th>
              <th>Длит. (дней)</th>
              <th>Свыше 10</th>
              <th>Свыше 3</th>
              <th>Действия</th>
          </tr>
          <?php 
          foreach($positions as $position):
              $daysPassed = getDaysPassed($position['date_submitted']);
              $over10 = ($daysPassed >= 10) ? "+" : "";
              $over3 = ($daysPassed >= 3) ? "+" : "";
          ?>
          <tr>
              <td><?php echo htmlspecialchars($position['id'], ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?php echo htmlspecialchars($position['date_submitted'], ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?php echo htmlspecialchars($position['shift'], ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?php echo htmlspecialchars($position['cdng'], ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?php echo htmlspecialchars($position['ceh_krs'], ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?php echo htmlspecialchars($position['brigade'], ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?php echo htmlspecialchars($position['master'], ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?php echo htmlspecialchars($position['kust'], ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?php echo htmlspecialchars($position['skvazhina'], ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?php echo htmlspecialchars($position['type'], ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?php echo htmlspecialchars($position['description'], ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?php echo htmlspecialchars($position['responsible_executive'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?php echo htmlspecialchars($position['required_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?php echo htmlspecialchars($position['required_time'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?php echo htmlspecialchars($position['executor'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?php echo htmlspecialchars($position['status'], ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?php echo htmlspecialchars($position['actual_time'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?php echo htmlspecialchars($position['note'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?php echo htmlspecialchars($daysPassed, ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?php echo htmlspecialchars($over10, ENT_QUOTES, 'UTF-8'); ?></td>
              <td><?php echo htmlspecialchars($over3, ENT_QUOTES, 'UTF-8'); ?></td>
              <td>
                  <?php 
                  // Назначение исполнителя (chief)
                  if ($role === 'chief' && empty($position['executor'])) {
                      echo "<a href='?action=assignPosition&id=" . htmlspecialchars($position['id'], ENT_QUOTES, 'UTF-8') . "'>Назначить исполнителя</a>";
                  }
                  // Исполнитель – обновить статус
                  elseif ($role === 'executor') {
                      echo "<a href='?action=updateApplication&id=" . htmlspecialchars($position['id'], ENT_QUOTES, 'UTF-8') . "'>Обновить статус</a>";
                  }

                  // Технолог – подтвердить выполнение, если не completed/deleted
                  if ($role === 'technologist' 
                      && !in_array($position['status'], ['completed','deleted'])) {
                      echo " | <a href='?action=techConfirmPosition&id=" . htmlspecialchars($position['id'], ENT_QUOTES, 'UTF-8') . "'>Подтвердить выполнение</a>";
                  }
                  ?>
              </td>
          </tr>
          <?php endforeach; ?>
      </table>
      <?php
      break;

    // ========== (6) Обновление статуса (исполнитель) ==========
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
                $fields = ['Дата подачи', 'Смена', 'ЦДНГ', 'Цех КРС', 'Бригада', 'Мастер','Куст', 'Скважина', 'Вид заявки', 'Наименование', 'Статус'];
                foreach ($fields as $field) {
                    echo "<th>" . htmlspecialchars($field, ENT_QUOTES, 'UTF-8') . "</th>";
                }
                ?>
              </tr>
              <tr>
                <?php 
                $values = [
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
                foreach ($values as $val) {
                    echo "<td>" . htmlspecialchars($val, ENT_QUOTES, 'UTF-8') . "</td>";
                }
                ?>
              </tr>
            </table>
          </div>
          <form method="post" action="?action=updateApplication">
            <input type="hidden" name="id" value="<?php echo htmlspecialchars($position['id'], ENT_QUOTES, 'UTF-8'); ?>">
            <label>Выберите новый статус:<br>
              <select name="status" required>
                <option value="в работе">В работе</option>
                <option value="нет в наличии">Нет в наличии</option>
                <option value="готово к отгрузке">Готово к отгрузке</option>
                <option value="отгружено">Отгружено</option>
              </select>
            </label>
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
              echo "<p class='success'>
                     Статус позиции № " . htmlspecialchars($posId, ENT_QUOTES, 'UTF-8') . " обновлён на " 
                   . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . ".</p>";
          } else {
              echo "<p class='error'>Ошибка при обновлении статуса.</p>";
          }
      }
      break;

    // ========== (7) Технолог подтверждает выполнение (completed) ==========
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

      if (!in_array($position['status'], ['ready_for_dispatch','shipped'])) {
          echo "<p class='error'>
                 Нельзя подтвердить выполнение заявки со статусом '" 
               . htmlspecialchars($position['status'], ENT_QUOTES, 'UTF-8') . "'. 
                 <br>Допустимые статусы: 'Готово к отгрузке' или 'Отгружено'.
                </p>";
          break;
      }

      if (techConfirmPosition($posId)) {
          echo "<p class='success'>
                Позиция № " . htmlspecialchars($posId, ENT_QUOTES, 'UTF-8') . " переведена в 'completed' (архив).
               </p>";
      } else {
          echo "<p class='error'>Ошибка при подтверждении выполнения.</p>";
      }
      break;

    // ========== (8) Найти срочные заявки исполнителя ==========
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
              $days = getDaysPassed($req['date_submitted']);
          ?>
          <tr>
            <td>
              <a href="?action=viewRequest&id=<?php echo htmlspecialchars($req['group_id'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank">
                <?php echo htmlspecialchars($req['id'], ENT_QUOTES, 'UTF-8'); ?>
              </a>
            </td>
            <td><?php echo htmlspecialchars($req['date_submitted'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars($req['required_date'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars($days, ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars($req['status'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td><a href="?action=updateApplication&id=<?php echo htmlspecialchars($req['id'], ENT_QUOTES, 'UTF-8'); ?>">Обновить статус</a></td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
      <?php
      break;

    // ========== (9) Просмотр списка заявок (фильтр, пагинация) ==========
    case 'viewRequests':
      $currentUser = ($role === 'executor') ? $_SESSION['user'] : "";
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

      ?>
      <form method="GET" action="index.php" style="margin-bottom: 20px;">
        <input type="hidden" name="action" value="viewRequests">
        
        <!-- Первая строка полей -->
        <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 10px;">
          <label>
            Дата от:
            <input type="date" name="f_date_from"
              value="<?php echo htmlspecialchars($advancedFilters['f_date_from'], ENT_QUOTES, 'UTF-8'); ?>">
          </label>
          
          <label>
            Дата до:
            <input type="date" name="f_date_to"
              value="<?php echo htmlspecialchars($advancedFilters['f_date_to'], ENT_QUOTES, 'UTF-8'); ?>">
          </label>
          
          <label>
            Смена:
            <input type="text" name="f_shift"
              value="<?php echo htmlspecialchars($advancedFilters['f_shift'], ENT_QUOTES, 'UTF-8'); ?>">
          </label>

          <label>
            ЦДНГ:
            <input type="text" name="f_cdng"
              value="<?php echo htmlspecialchars($advancedFilters['f_cdng'], ENT_QUOTES, 'UTF-8'); ?>">
          </label>
          
          <label>
            Цех КРС:
            <input type="text" name="f_ceh_krs"
              value="<?php echo htmlspecialchars($advancedFilters['f_ceh_krs'], ENT_QUOTES, 'UTF-8'); ?>">
          </label>
        </div>

        <!-- Вторая строка полей -->
        <div style="display: flex; gap: 15px; flex-wrap: wrap;">
          <label>
            Бригада:
            <input type="text" name="f_brigade"
              value="<?php echo htmlspecialchars($advancedFilters['f_brigade'], ENT_QUOTES, 'UTF-8'); ?>">
          </label>
          
          <label>
            Мастер:
            <input type="text" name="f_master"
              value="<?php echo htmlspecialchars($advancedFilters['f_master'], ENT_QUOTES, 'UTF-8'); ?>">
          </label>
          
          <label>
            Куст:
            <input type="text" name="f_kust"
              value="<?php echo htmlspecialchars($advancedFilters['f_kust'], ENT_QUOTES, 'UTF-8'); ?>">
          </label>
          
          <label>
            Скважина:
            <input type="text" name="f_skvazhina"
              value="<?php echo htmlspecialchars($advancedFilters['f_skvazhina'], ENT_QUOTES, 'UTF-8'); ?>">
          </label>

          <label>
            Вид заявки:
            <input type="text" name="f_type"
              value="<?php echo htmlspecialchars($advancedFilters['f_type'], ENT_QUOTES, 'UTF-8'); ?>">
          </label>

          <label>
            Статус:
            <select name="f_status">
              <option value="">(Любой)</option>
              <option value="new"       <?php if(($advancedFilters['f_status'] ?? '')==='new')       echo 'selected'; ?>>New</option>
              <option value="pending"   <?php if(($advancedFilters['f_status'] ?? '')==='pending')   echo 'selected'; ?>>Pending</option>
              <option value="in_progress" <?php if(($advancedFilters['f_status'] ?? '')==='in_progress') echo 'selected'; ?>>In Progress</option>
              <option value="ready_for_dispatch" <?php if(($advancedFilters['f_status'] ?? '')==='ready_for_dispatch') echo 'selected'; ?>>Ready for dispatch</option>
              <option value="shipped"   <?php if(($advancedFilters['f_status'] ?? '')==='shipped')   echo 'selected'; ?>>Shipped</option>
              <option value="completed" <?php if(($advancedFilters['f_status'] ?? '')==='completed') echo 'selected'; ?>>Completed</option>
            </select>
          </label>
        </div>

        <button type="submit" style="margin-top: 10px; padding: 10px 20px;">Применить фильтр</button>
      </form>
      <?php

      $requests = getRequestsByFilterAdvanced($filter, $advancedFilters, $offset, $limit, $role, $currentUser);
      $total = getRequestsCountAdvanced($filter, $advancedFilters, $role, $currentUser);
      $totalPages = ceil($total / $limit);

      ?>
      <h2>Список заявок<?php echo ($filter ? " (фильтр: " . htmlspecialchars($filter, ENT_QUOTES, 'UTF-8') . ")" : ""); ?></h2>
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
            <th>Наименование</th>
            <th>Отв. исполнитель</th>
            <th>Треб. дата</th>
            <th>Треб. время</th>
            <th>Исполнитель</th>
            <th>Статус</th>
            <th>Факт. время</th>
            <th>Примечание</th>
            <th>Длит. (дней)</th>
            <th>Свыше 10</th>
            <th>Свыше 3</th>
            <th>Действия</th>
          </tr>
          <?php 
          $i = $offset + 1;
          foreach ($requests as $req) {
              $days = getDaysPassed($req['date_submitted']);
              $over10 = ($days >= 10) ? "+" : "";
              $over3 = ($days >= 3) ? "+" : "";
              ?>
              <tr>
                <td><?php echo $i++; ?></td>
                <td><?php echo htmlspecialchars($req['date_submitted'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($req['shift'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($req['cdng'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($req['ceh_krs'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($req['brigade'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($req['master'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($req['kust'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($req['skvazhina'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($req['type'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($req['description'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($req['responsible_executive'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($req['required_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($req['required_time'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($req['executor'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($req['status'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($req['actual_time'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($req['note'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($days, ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($over10, ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($over3, ENT_QUOTES, 'UTF-8'); ?></td>
                <td>
                  <?php 
                  if ($role === 'chief' && empty($req['executor'])) {
                      echo "<a href='?action=assignPosition&id=" . htmlspecialchars($req['id'], ENT_QUOTES, 'UTF-8') . "'>Назначить исполнителя</a>";
                  } elseif ($role === 'executor') {
                      echo "<a href='?action=updateApplication&id=" . htmlspecialchars($req['id'], ENT_QUOTES, 'UTF-8') . "'>Обновить статус</a>";
                  }
                  if ($role === 'technologist' && !in_array($req['status'], ['completed','deleted'])) {
                      echo " | <a href='?action=techConfirmPosition&id=" . htmlspecialchars($req['id'], ENT_QUOTES, 'UTF-8') . "'>Подтвердить выполнение</a>";
                  }
                  ?>
                </td>
              </tr>
              <?php
          }
          ?>
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

    // ========== (10) Экспорт в CSV (для admin) ==========
    case 'exportExcel':
      if ($role !== 'admin') {
          echo "<p class='error'>Доступ запрещен.</p>";
          break;
      }
      exportRequestsToCSV();
      break;

    // ========== (11) Подтверждение всей заявки (если нужно) ==========
    case 'confirmRequest':
      if ($role !== 'technologist') {
          echo "<p class='error'>Доступ запрещен.</p>";
          break;
      }
      if ($_SERVER['REQUEST_METHOD'] === 'POST') {
          $requestId = intval($_POST['request_id'] ?? 0);
          if (confirmRequest($requestId)) {
              echo "<p class='success'>Заявка № " . htmlspecialchars($requestId, ENT_QUOTES, 'UTF-8') . " подтверждена и переведена в архив.</p>";
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

    // ========== (12) По умолчанию (home) ==========
    default:
      echo "<h2>Добро пожаловать в систему обеспечения потребности бригад</h2>";
      echo "<p>Выберите нужное действие из меню.</p>";
      break;
  } // end switch
  ?>
  </main>
  <footer>
    <p>&copy; <?php echo date("Y"); ?> Система обеспечения потребности бригад</p>
  </footer>
<?php endif; // конец if(!isset($_SESSION['user'])) ?>
<?php ob_end_flush(); ?>
</body>
</html>
