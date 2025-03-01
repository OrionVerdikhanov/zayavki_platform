<?php
// functions.php

define('DB_HOST', 'localhost');
define('DB_USER', 'u2611449_zayavki');
define('DB_PASS', 'u2611449_zayavki');
define('DB_NAME', 'u2611449_zayavki');

function getDBConnection() {
  $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
  if ($conn->connect_error) {
    die("Ошибка подключения: " . $conn->connect_error);
  }
  return $conn;
}

/*
SQL-команды для создания/обновления таблиц через phpMyAdmin:
--------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `role` ENUM('technologist','chief','executor','admin') NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `requests` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `group_id` INT(11) DEFAULT NULL,
  `date_submitted` DATE NOT NULL,
  `shift` VARCHAR(10) NOT NULL,
  `cdng` VARCHAR(10) NOT NULL,
  `ceh_krs` VARCHAR(100) NOT NULL,
  `brigade` VARCHAR(50) NOT NULL,
  `master` VARCHAR(100) NOT NULL,
  `kust` VARCHAR(50) NOT NULL,
  `skvazhina` VARCHAR(50) NOT NULL,
  `type` VARCHAR(100) NOT NULL,
  `description` TEXT NOT NULL,
  `responsible_executive` VARCHAR(100) DEFAULT NULL,
  `required_date` DATE DEFAULT NULL,
  `required_time` VARCHAR(50) DEFAULT NULL,
  `executor` VARCHAR(100) DEFAULT NULL,
  `status` ENUM('new','pending','in_progress','not_available','awaiting_confirmation','ready_for_dispatch','completed','overdue','deletion_requested','deleted') DEFAULT 'new',
  `actual_time` VARCHAR(50) DEFAULT NULL,
  `note` TEXT DEFAULT NULL,
  `long_execution` VARCHAR(50) DEFAULT NULL,
  `delay_over_10` VARCHAR(50) DEFAULT NULL,
  `delay_over_3` VARCHAR(50) DEFAULT NULL,
  `deletion_request` TINYINT(1) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
--------------------------------------------------------------
*/

function loginUser($username, $password) {
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

function registerUser($username, $password, $role) {
  $conn = getDBConnection();
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

function getExecutors() {
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

function addRequest($data) {
  $conn = getDBConnection();
  $stmt = $conn->prepare("INSERT INTO requests (date_submitted, shift, cdng, ceh_krs, brigade, master, kust, skvazhina, type, description, required_date, required_time) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
  $stmt->bind_param("ssssssssssss",
    $data['date_submitted'],
    $data['shift'],
    $data['cdng'],
    $data['ceh_krs'],
    $data['brigade'],
    $data['master'],
    $data['kust'],
    $data['skvazhina'],
    $data['type'],
    $data['description'],
    $data['required_date'],
    $data['required_time']
  );
  if ($stmt->execute()) {
    $newId = $stmt->insert_id;
    $stmt->close();
    $conn->query("UPDATE requests SET group_id = $newId WHERE id = $newId");
    $conn->close();
    return $newId;
  }
  $stmt->close();
  $conn->close();
  return false;
}

function addRequestPosition($group_id, $data) {
  $conn = getDBConnection();
  $stmt = $conn->prepare("SELECT date_submitted, shift, cdng, ceh_krs, brigade, master, kust, skvazhina, required_date, required_time FROM requests WHERE group_id = ? LIMIT 1");
  $stmt->bind_param("i", $group_id);
  $stmt->execute();
  $baseData = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$baseData) {
    $conn->close();
    return false;
  }
  $stmt = $conn->prepare("INSERT INTO requests (group_id, date_submitted, shift, cdng, ceh_krs, brigade, master, kust, skvazhina, type, description, required_date, required_time) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
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
    $data['type'],
    $data['description'],
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

function getRequestById($id) {
  $conn = getDBConnection();
  $stmt = $conn->prepare("SELECT * FROM requests WHERE group_id = ? ORDER BY id ASC");
  $stmt->bind_param("i", $id);
  $stmt->execute();
  $result = $stmt->get_result();
  $positions = [];
  while ($row = $result->fetch_assoc()){
    $positions[] = $row;
  }
  $stmt->close();
  $conn->close();
  if (count($positions) > 1) {
    $positions[0]['positions'] = $positions;
    return $positions[0];
  }
  return $positions ? $positions[0] : null;
}

function getRequestsByFilterAdvanced($filter, $advancedFilters, $offset, $limit, $userRole = "", $currentUser = "") {
  $conn = getDBConnection();
  $conditions = [];
  if ($filter === 'waiting') {
    $conditions[] = "status IN ('new','pending')";
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
  $sql = "SELECT * FROM requests" . $where . " ORDER BY date_submitted DESC LIMIT $offset, $limit";
  $result = $conn->query($sql);
  $requests = [];
  while ($row = $result->fetch_assoc()){
    $requests[] = $row;
  }
  $conn->close();
  return $requests;
}

function getRequestsCountAdvanced($filter, $advancedFilters, $userRole = "", $currentUser = "") {
  $conn = getDBConnection();
  $conditions = [];
  if ($filter === 'waiting') {
    $conditions[] = "status IN ('new','pending')";
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
  return $row['count'] ?? 0;
}

function updateRequestStatus($id, $status) {
  if ($_SESSION['role'] === 'executor' && $status === 'исполнено') {
    $status = 'awaiting_confirmation';
  }
  $conn = getDBConnection();
  $stmt = $conn->prepare("UPDATE requests SET status = ? WHERE id = ?");
  $stmt->bind_param("si", $status, $id);
  $res = $stmt->execute();
  $stmt->close();
  $conn->close();
  return $res;
}

function assignPosition($id, $executor) {
  $conn = getDBConnection();
  $stmt = $conn->prepare("UPDATE requests SET executor = ?, status = 'pending' WHERE id = ?");
  $stmt->bind_param("si", $executor, $id);
  $res = $stmt->execute();
  $stmt->close();
  $conn->close();
  return $res;
}

function requestDeletion($id) {
  $conn = getDBConnection();
  $stmt = $conn->prepare("UPDATE requests SET deletion_request = 1, status = 'deletion_requested' WHERE id = ?");
  $stmt->bind_param("i", $id);
  $res = $stmt->execute();
  $stmt->close();
  $conn->close();
  return $res;
}

function getDeletionRequests() {
  $conn = getDBConnection();
  $sql = "SELECT * FROM requests WHERE deletion_request = 1";
  $result = $conn->query($sql);
  $requests = [];
  while ($row = $result->fetch_assoc()){
    $requests[] = $row;
  }
  $conn->close();
  return $requests;
}

function adminDeleteRequest($id) {
  $conn = getDBConnection();
  $stmt = $conn->prepare("DELETE FROM requests WHERE id = ?");
  $stmt->bind_param("i", $id);
  $res = $stmt->execute();
  $stmt->close();
  $conn->close();
  return $res;
}

function getUrgentRequests() {
  $conn = getDBConnection();
  $currentUser = $_SESSION['user'] ?? '';
  $sql = "SELECT *, DATEDIFF(required_date, CURDATE()) as days_left FROM requests 
          WHERE executor = '" . $conn->real_escape_string($currentUser) . "' 
            AND required_date IS NOT NULL 
            AND DATEDIFF(required_date, CURDATE()) <= 3 
          ORDER BY days_left ASC";
  $result = $conn->query($sql);
  $requests = [];
  while ($row = $result->fetch_assoc()){
    $requests[] = $row;
  }
  $conn->close();
  return $requests;
}

function confirmRequest($id) {
  $conn = getDBConnection();
  $stmt = $conn->prepare("UPDATE requests SET status = 'completed' WHERE id = ? AND status = 'awaiting_confirmation'");
  $stmt->bind_param("i", $id);
  $res = $stmt->execute();
  $stmt->close();
  $conn->close();
  return $res;
}

function exportRequestsToCSV() {
  $conn = getDBConnection();
  $sql = "SELECT * FROM requests";
  $result = $conn->query($sql);
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename=export_requests.csv');
  $output = fopen('php://output', 'w');
  if ($row = $result->fetch_assoc()) {
    fputcsv($output, array_keys($row));
    fputcsv($output, array_values($row));
  }
  while ($row = $result->fetch_assoc()) {
    fputcsv($output, $row);
  }
  fclose($output);
  exit;
}
?>
