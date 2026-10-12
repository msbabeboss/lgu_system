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
    "SELECT bc.case_number,
            COALESCE(CONCAT(c.last_name,', ',c.first_name), bc.complainant_name_manual, '—') AS complainant_display
     FROM blotter_cases bc
     LEFT JOIN residents c ON bc.complainant_id = c.id
     LEFT JOIN residents r ON bc.respondent_id  = r.id
     WHERE bc.barangay_id = ?
       AND bc.status != 'Archived'
       AND (bc.case_number LIKE ? OR bc.location LIKE ?
            OR COALESCE(c.first_name,'') LIKE ? OR COALESCE(c.last_name,'') LIKE ?
            OR COALESCE(CONCAT(c.first_name,' ',c.last_name),'') LIKE ?
            OR COALESCE(CONCAT(c.last_name,', ',c.first_name),'') LIKE ?
            OR COALESCE(bc.complainant_name_manual,'') LIKE ?
            OR COALESCE(r.first_name,'') LIKE ? OR COALESCE(r.last_name,'') LIKE ?
            OR COALESCE(CONCAT(r.first_name,' ',r.last_name),'') LIKE ?
            OR COALESCE(CONCAT(r.last_name,', ',r.first_name),'') LIKE ?
            OR COALESCE(bc.respondent_name_manual,'') LIKE ?)
     ORDER BY bc.created_at DESC
     LIMIT 15"
);
$stmt->execute([$bid,
    $like, $like,
    $like, $like, $like, $like, $like,
    $like, $like, $like, $like, $like
]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$results = array_map(fn($row) => [
    'id'   => $row['case_number'],
    'text' => $row['case_number'] . ' — ' . $row['complainant_display'],
], $rows);

echo json_encode(['results' => $results]);
