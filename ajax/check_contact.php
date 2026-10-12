<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();

$db      = Database::getInstance()->getConnection();
$u       = currentUser();
$bid     = (int)($u['barangay_id'] ?? 0);
$contact = sanitize($_GET['contact'] ?? '');
$exclude = (int)($_GET['exclude_id'] ?? 0);   // resident/household/official id being edited
$table   = $_GET['table'] ?? 'residents';      // residents | households | officials

$allowed = ['residents', 'households', 'officials'];
if (!in_array($table, $allowed) || !$contact) {
    jsonResponse(['taken' => false]);
}

$col = ($table === 'officials') ? 'contact' : 'contact_number';
$statusCol = match($table) {
    'residents'  => "AND resident_status != 'Inactive'",
    'households' => "AND status != 'Inactive'",
    'officials'  => "AND status != 'inactive'",
    default      => ''
};

$stmt = $db->prepare("SELECT id, " . ($table === 'residents' ? "CONCAT(last_name,', ',first_name) as name" : ($table === 'officials' ? "full_name as name" : "household_code as name")) . " FROM `$table` WHERE `$col`=? AND barangay_id=? AND id!=? $statusCol LIMIT 1");
$stmt->execute([$contact, $bid, $exclude]);
$row = $stmt->fetch();

jsonResponse(['taken' => (bool)$row, 'name' => $row['name'] ?? '']);
