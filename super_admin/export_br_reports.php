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
    $where        = "WHERE rp.id IN ($placeholders)";
    $bindParams   = $ids;
} else {
    // ── GET: filter-based (all) ───────────────────────────────
    $search    = trim(strip_tags($_GET['search']    ?? ''));
    $brgFilter = (int)($_GET['barangay_id'] ?? 0);
    $statusF   = trim(strip_tags($_GET['status']   ?? ''));
    $categoryF = trim(strip_tags($_GET['category'] ?? ''));
    $dateFrom  = trim(strip_tags($_GET['date_from']?? ''));
    $dateTo    = trim(strip_tags($_GET['date_to']  ?? ''));

    $where = "WHERE 1=1";
    if ($brgFilter) $where .= " AND rp.barangay_id = " . (int)$brgFilter;
    if ($statusF)   $where .= " AND rp.status = " . $db->quote($statusF);
    if ($categoryF) $where .= " AND rp.category = " . $db->quote($categoryF);
    if ($dateFrom)  $where .= " AND rp.created_at >= " . $db->quote($dateFrom . ' 00:00:00');
    if ($dateTo)    $where .= " AND rp.created_at <= " . $db->quote($dateTo   . ' 23:59:59');
    if ($search)    $where .= " AND (rp.notes LIKE " . $db->quote("%$search%") .
                              " OR b.barangay_name LIKE " . $db->quote("%$search%") .
                              " OR rp.report_type LIKE " . $db->quote("%$search%") . ")";
    $bindParams = null;
}

logActivity($u['id'], 'EXPORT_BR_REPORTS', 'Barangay Records', 'Exported reports CSV');

$sql = "
    SELECT rp.report_type, rp.month, rp.year, rp.notes, rp.status, rp.created_at,
           b.barangay_name,
           CONCAT(u.first_name,' ',u.last_name) AS submitted_by_name
    FROM reports rp
    LEFT JOIN barangays b ON b.id = rp.barangay_id
    LEFT JOIN users u     ON u.id = rp.submitted_by
    $where
    ORDER BY rp.created_at DESC
";

if ($bindParams !== null) {
    $stmt = $db->prepare($sql);
    $stmt->execute($bindParams);
    $rows = $stmt->fetchAll();
} else {
    $rows = $db->query($sql)->fetchAll();
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="reports_export_' . date('Ymd') . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['Barangay','Report Type','Month','Year','Notes','Status','Submitted By','Date Submitted']);
foreach ($rows as $row) {
    fputcsv($out, [
        $row['barangay_name']     ?? '',
        ucfirst($row['report_type'] ?? ''),
        $row['month'] ? date('F', mktime(0,0,0,$row['month'],1)) : '',
        $row['year']              ?? '',
        $row['notes']             ?? '',
        $row['status']            ?? '',
        $row['submitted_by_name'] ?? '',
        $row['created_at'] ? "\t".$row['created_at'] : '',
    ]);
}
fclose($out);
exit;
