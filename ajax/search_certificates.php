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
    "SELECT DISTINCT CONCAT(r.last_name,', ',r.first_name,
             IF(r.middle_name IS NOT NULL AND r.middle_name != '',
                CONCAT(' ',LEFT(r.middle_name,1),'.'), '')) AS full_name
     FROM certificates c
     LEFT JOIN residents r ON c.resident_id = r.id
     WHERE c.barangay_id = ?
       AND (r.first_name LIKE ? OR r.last_name LIKE ?
            OR CONCAT(r.first_name,' ',r.last_name) LIKE ?
            OR CONCAT(r.last_name,', ',r.first_name) LIKE ?)
     ORDER BY r.last_name ASC, r.first_name ASC
     LIMIT 15"
);
$stmt->execute([$bid, $like, $like, $like, $like]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$results = array_map(fn($row) => [
    'id'   => $row['full_name'],
    'text' => $row['full_name'],
], $rows);

echo json_encode(['results' => $results]);
