<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
header('Content-Type: application/json');

$db  = Database::getInstance()->getConnection();
$u   = currentUser();
$bid = (int)$u['barangay_id'];
$q   = trim($_GET['q'] ?? '');

if ($q === '') { echo json_encode(['results' => []]); exit; }

$like = '%' . $q . '%';
$stmt = $db->prepare(
    "SELECT DISTINCT CONCAT(u.first_name,' ',u.last_name) AS user_name,
            al.action, al.description
     FROM activity_logs al
     LEFT JOIN users u ON al.user_id = u.id
     WHERE u.barangay_id = ? AND u.role = 'barangay_admin'
       AND (u.first_name LIKE ? OR u.last_name LIKE ?
            OR CONCAT(u.first_name,' ',u.last_name) LIKE ?
            OR al.action LIKE ? OR al.description LIKE ?)
     ORDER BY al.created_at DESC
     LIMIT 15"
);
$stmt->execute([$bid, $like, $like, $like, $like, $like]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$results = [];
foreach ($rows as $row) {
    $label = trim($row['user_name']) ?: '—';
    $desc  = mb_strimwidth($row['description'] ?? '', 0, 50, '…');
    $results[] = ['id' => $row['action'], 'text' => $label . ' — ' . $row['action'] . ($desc ? ' — ' . $desc : '')];
}

echo json_encode(['results' => $results]);
