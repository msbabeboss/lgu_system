<?php
require_once __DIR__ . '/../config/config.php';
requireRole('barangay_admin');

header('Content-Type: application/json');

$db = Database::getInstance()->getConnection();
$u = currentUser();
$bid = (int)$u['barangay_id'];
$q = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';

if ($q === '') {
    echo json_encode(['results' => []]);
    exit;
}

$like = '%' . $q . '%';
$stmt = $db->prepare(
    "SELECT o.full_name, o.position
     FROM officials o
     WHERE o.barangay_id = ?
       AND o.id NOT IN (SELECT record_id FROM archives WHERE table_name = 'officials')
       AND (o.full_name LIKE ? OR o.position LIKE ? OR o.contact LIKE ? OR o.email LIKE ?)
     ORDER BY FIELD(o.status, 'active', 'inactive'), o.position, o.full_name
     LIMIT 15"
);
$stmt->execute([$bid, $like, $like, $like, $like]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$results = array_map(static fn($row) => [
    'id' => $row['full_name'],
    'text' => $row['full_name'] . ' — ' . $row['position'],
], $rows);

echo json_encode(['results' => $results]);
