<?php
/**
 * AJAX endpoint for Select2 resident search autocomplete.
 * Returns JSON: { results: [ { id, text }, ... ] }
 */
require_once __DIR__ . '/../config/config.php';
requireLogin();

header('Content-Type: application/json');

$db  = Database::getInstance()->getConnection();
$u   = currentUser();
$bid = (int)$u['barangay_id'];

$q    = trim($_GET['q'] ?? '');
$tab  = $_GET['tab'] ?? 'active'; // active | transferred

if ($q === '') {
    echo json_encode(['results' => []]);
    exit;
}

$like = '%' . $q . '%';

if ($tab === 'transferred') {
    $statusCond = "r.resident_status = 'Transferred'";
} else {
    $statusCond = "r.resident_status NOT IN ('Inactive','Deceased','Transferred')";
}

$stmt = $db->prepare(
    "SELECT r.id,
            CONCAT(r.last_name, ', ', r.first_name,
                   IF(r.middle_name != '' AND r.middle_name IS NOT NULL,
                      CONCAT(' ', LEFT(r.middle_name, 1), '.'), ''),
                   IF(r.suffix IS NOT NULL AND r.suffix != '',
                      CONCAT(' ', r.suffix), '')
                  ) AS full_name
     FROM residents r
     WHERE r.barangay_id = ?
       AND $statusCond
       AND (
           r.first_name  LIKE ?  OR
           r.last_name   LIKE ?  OR
           r.middle_name LIKE ?  OR
           CONCAT(r.first_name, ' ', r.last_name)                   LIKE ? OR
           CONCAT(r.last_name,  ' ', r.first_name)                  LIKE ? OR
           CONCAT(r.last_name,  ', ', r.first_name)                 LIKE ? OR
           CONCAT(r.first_name, ' ', r.middle_name, ' ', r.last_name) LIKE ?
       )
     ORDER BY r.last_name ASC, r.first_name ASC
     LIMIT 20"
);
$stmt->execute([$bid, $like, $like, $like, $like, $like, $like, $like]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$results = array_map(fn($row) => [
    'id'   => $row['id'],        // used as ?search= value (resident id)
    'text' => $row['full_name'],
], $rows);

echo json_encode(['results' => $results]);
