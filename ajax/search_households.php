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
    "SELECT h.id, h.household_code,
            CONCAT(r.last_name,', ',r.first_name) AS head_name
     FROM households h
     LEFT JOIN residents r ON h.head_resident_id = r.id
     WHERE h.barangay_id = ?
       AND (h.household_code LIKE ? OR r.first_name LIKE ? OR r.last_name LIKE ?
            OR CONCAT(r.first_name,' ',r.last_name) LIKE ?
            OR CONCAT(r.last_name,', ',r.first_name) LIKE ?)
     ORDER BY h.household_code ASC
     LIMIT 15"
);
$stmt->execute([$bid, $like, $like, $like, $like, $like]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$results = array_map(fn($row) => [
    'id'   => $row['household_code'],
    'text' => $row['household_code'] . ($row['head_name'] ? ' — ' . $row['head_name'] : ''),
], $rows);

echo json_encode(['results' => $results]);
