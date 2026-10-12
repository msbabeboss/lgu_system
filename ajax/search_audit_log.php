<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
header('Content-Type: application/json');

$db  = Database::getInstance()->getConnection();
$u   = currentUser();
$bid = (int)$u['barangay_id'];
$q   = trim($_GET['q'] ?? '');

if ($q === '') { echo json_encode(['results' => []]); exit; }

$auditActions = [
    'ADD_RESIDENT','EDIT_RESIDENT','ARCHIVE_RESIDENT','DELETE_RESIDENT',
    'ADD_HOUSEHOLD','EDIT_HOUSEHOLD','DELETE_HOUSEHOLD',
    'ADD_BLOTTER','EDIT_BLOTTER','DELETE_BLOTTER',
    'ISSUE_CERTIFICATE','DELETE_CERTIFICATE',
    'ADD_OFFICIAL','EDIT_OFFICIAL','ARCHIVE_OFFICIAL',
    'CHANGE_PASSWORD','RESTORE_ARCHIVE','PERMANENT_DELETE',
    'SAVE_REPORT','SUBMIT_REPORT','ADD_NOTICE','DELETE_NOTICE',
];
$placeholders = implode(',', array_fill(0, count($auditActions), '?'));
$like = '%' . $q . '%';

$stmt = $db->prepare(
    "SELECT DISTINCT CONCAT(u.first_name,' ',u.last_name) AS user_name,
            al.action, al.description
     FROM activity_logs al
     LEFT JOIN users u ON al.user_id = u.id
     WHERE u.barangay_id = ?
       AND al.action IN ($placeholders)
       AND (u.first_name LIKE ? OR u.last_name LIKE ?
            OR CONCAT(u.first_name,' ',u.last_name) LIKE ?
            OR al.action LIKE ? OR al.description LIKE ?)
     ORDER BY al.created_at DESC
     LIMIT 15"
);
$params = array_merge([$bid], $auditActions, [$like, $like, $like, $like, $like]);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$results = [];
foreach ($rows as $row) {
    $label = trim($row['user_name']) ?: '—';
    $desc  = mb_strimwidth($row['description'] ?? '', 0, 50, '…');
    $results[] = ['id' => $row['action'], 'text' => $label . ' — ' . $row['action'] . ($desc ? ' — ' . $desc : '')];
}

echo json_encode(['results' => $results]);
