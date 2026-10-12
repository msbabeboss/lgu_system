<?php
require_once __DIR__ . '/../config/config.php';
requireRole('super_admin');

$db = Database::getInstance()->getConnection();
$u  = currentUser();

// ── POST: selected IDs ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrAbort();
    $rawIds = $_POST['ids'] ?? [];
    if (empty($rawIds) || !is_array($rawIds)) { http_response_code(400); exit('No rows selected.'); }
    $ids = array_values(array_unique(array_filter(array_map('intval', $rawIds))));
    if (empty($ids)) { http_response_code(400); exit('Invalid IDs.'); }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $where        = "WHERE c.id IN ($placeholders)";
    $bindParams   = $ids;
} else {
    // ── GET: filter-based (all) ───────────────────────────────
    $search    = trim(strip_tags($_GET['search']    ?? ''));
    $brgFilter = (int)($_GET['barangay_id'] ?? 0);
    $typeF     = trim(strip_tags($_GET['type']      ?? ''));
    $dateFrom  = trim(strip_tags($_GET['date_from'] ?? ''));
    $dateTo    = trim(strip_tags($_GET['date_to']   ?? ''));

    $where = "WHERE 1=1";
    if ($brgFilter) $where .= " AND c.barangay_id = " . (int)$brgFilter;
    if ($typeF)     $where .= " AND c.certificate_type = " . $db->quote($typeF);
    if ($dateFrom)  $where .= " AND c.date_issued >= " . $db->quote($dateFrom);
    if ($dateTo)    $where .= " AND c.date_issued <= " . $db->quote($dateTo);
    if ($search)    $where .= " AND (c.certificate_type LIKE " . $db->quote("%$search%") .
                              " OR r.first_name LIKE " . $db->quote("%$search%") .
                              " OR r.last_name LIKE " . $db->quote("%$search%") .
                              " OR b.barangay_name LIKE " . $db->quote("%$search%") . ")";
    $bindParams = null;
}

logActivity($u['id'], 'EXPORT_BR_CERTIFICATES', 'Barangay Records', 'Exported certificates CSV');

$sql = "
    SELECT c.certificate_type, c.purpose, c.date_issued, c.created_at,
           b.barangay_name,
           CONCAT(r.last_name, ', ', r.first_name,
                  IF(r.middle_name IS NOT NULL AND r.middle_name != '',
                     CONCAT(' ', LEFT(r.middle_name,1), '.'), '')) AS resident_name,
           CONCAT(u.first_name,' ',u.last_name) AS issued_by_name,
           u.email AS issued_by_email
    FROM certificates c
    LEFT JOIN barangays b ON b.id = c.barangay_id
    LEFT JOIN residents r ON r.id = c.resident_id
    LEFT JOIN users u     ON u.id = c.issued_by
    $where
    ORDER BY c.date_issued DESC, c.created_at DESC
";

if ($bindParams !== null) {
    $stmt = $db->prepare($sql);
    $stmt->execute($bindParams);
    $rows = $stmt->fetchAll();
} else {
    $rows = $db->query($sql)->fetchAll();
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="certificates_export_' . date('Ymd') . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['Barangay','Certificate Type','Resident Name','Purpose','Issued By','Issued By Email','Date Issued','Date Added']);
foreach ($rows as $row) {
    fputcsv($out, [
        $row['barangay_name']    ?? '',
        $row['certificate_type'] ?? '',
        $row['resident_name']    ?? '',
        $row['purpose']          ?? '',
        $row['issued_by_name']   ?? '',
        $row['issued_by_email']  ?? '',
        $row['date_issued'] ? "\t".$row['date_issued'] : '',
        $row['created_at']  ? "\t".$row['created_at']  : '',
    ]);
}
fclose($out);
exit;
