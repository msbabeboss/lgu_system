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
    "SELECT DISTINCT r.resident_name
     FROM resident_transfer_requests r
     WHERE r.origin_barangay_id = ?
       AND r.resident_name LIKE ?
     ORDER BY r.resident_name ASC
     LIMIT 15"
);
$stmt->execute([$bid, $like]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$results = array_map(fn($row) => [
    'id'   => $row['resident_name'],
    'text' => $row['resident_name'],
], $rows);

echo json_encode(['results' => $results]);
