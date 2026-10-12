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

// Search residents/officials/households/blotter/certificates from archives JSON
$stmt = $db->prepare(
    "SELECT a.table_name, a.data_json
     FROM archives a
     WHERE (a.data_json LIKE ? OR a.data_json LIKE ? OR a.data_json LIKE ? OR a.data_json LIKE ?)
       AND (a.data_json LIKE ? OR a.data_json LIKE ? OR a.data_json LIKE ?)
       AND a.table_name IN ('residents','officials','households','blotter_cases','certificates')
     LIMIT 15"
);
$bidStr1 = '%"barangay_id":"' . $bid . '"%';
$bidStr2 = '%"barangay_id":' . $bid . '%';
$bidStr3 = '%"barangay_id": ' . $bid . '%';
$bidStr4 = '%"barangay_id": "' . $bid . '"%';
$stmt->execute([$bidStr1, $bidStr2, $bidStr3, $bidStr4, $like, $like, $like]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$results = [];
foreach ($rows as $row) {
    $data = json_decode($row['data_json'], true) ?? [];
    $label = '';
    switch ($row['table_name']) {
        case 'residents':
        case 'officials':
            $fn = $data['first_name'] ?? '';
            $ln = $data['last_name']  ?? '';
            $label = $ln && $fn ? "$ln, $fn" : ($data['full_name'] ?? '');
            break;
        case 'households':
            $label = $data['household_code'] ?? '';
            break;
        case 'blotter_cases':
            $label = $data['case_number'] ?? '';
            break;
        case 'certificates':
            $label = ($data['certificate_type'] ?? '') . ' — ' . ($data['purpose'] ?? '');
            break;
    }
    if ($label) {
        $results[] = ['id' => $label, 'text' => '[' . ucfirst(str_replace('_',' ',$row['table_name'])) . '] ' . $label];
    }
}

// Also search notices
$nStmt = $db->prepare(
    "SELECT n.title FROM notices n
     INNER JOIN notice_dismissals nd ON nd.notice_id = n.id AND nd.user_id = ?
     WHERE n.title LIKE ? OR n.content LIKE ?
     LIMIT 5"
);
$nStmt->execute([$u['id'], $like, $like]);
foreach ($nStmt->fetchAll(PDO::FETCH_ASSOC) as $n) {
    $results[] = ['id' => $n['title'], 'text' => '[Notice] ' . $n['title']];
}

echo json_encode(['results' => array_slice($results, 0, 15)]);
