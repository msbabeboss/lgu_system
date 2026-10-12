<?php
require_once __DIR__ . '/../config/config.php';
requireRole('super_admin');

$db = Database::getInstance()->getConnection();
$u  = currentUser();

// ── GET: filter-based (all) ───────────────────────────────────
$search    = trim(strip_tags($_GET['search']    ?? ''));
$brgFilter = (int)($_GET['barangay_id'] ?? 0);
$positionF = trim(strip_tags($_GET['position'] ?? ''));
$statusF   = trim(strip_tags($_GET['status']   ?? ''));
$dateFrom  = trim(strip_tags($_GET['date_from']?? ''));
$dateTo    = trim(strip_tags($_GET['date_to']  ?? ''));

$where = "WHERE 1=1";
if ($brgFilter) $where .= " AND o.barangay_id = " . (int)$brgFilter;
if ($positionF) $where .= " AND o.position = " . $db->quote($positionF);
if ($statusF)   $where .= " AND o.status = " . $db->quote($statusF);
if ($dateFrom)  $where .= " AND o.term_start >= " . $db->quote($dateFrom);
if ($dateTo)    $where .= " AND o.term_start <= " . $db->quote($dateTo);
if ($search)    $where .= " AND (o.full_name LIKE " . $db->quote("%$search%") .
                          " OR o.position LIKE " . $db->quote("%$search%") .
                          " OR b.barangay_name LIKE " . $db->quote("%$search%") . ")";

logActivity($u['id'], 'EXPORT_BR_OFFICIALS', 'Barangay Records', 'Exported officials CSV');

$sql = "
    SELECT o.full_name, o.position, o.contact, o.email,
           o.term_start, o.term_end, o.status,
           b.barangay_name
    FROM officials o
    LEFT JOIN barangays b ON b.id = o.barangay_id
    $where
    ORDER BY b.barangay_name, o.position, o.full_name
";

$rows = $db->query($sql)->fetchAll();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="officials_export_' . date('Ymd') . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['Barangay','Full Name','Position','Contact','Email','Term Start','Term End','Status']);
foreach ($rows as $row) {
    fputcsv($out, [
        $row['barangay_name'] ?? '',
        $row['full_name'],
        $row['position']      ?? '',
        $row['contact']       ?? '',
        $row['email']         ?? '',
        $row['term_start'] ? "\t".$row['term_start'] : '',
        $row['term_end']   ? "\t".$row['term_end']   : '',
        $row['status']        ?? '',
    ]);
}
fclose($out);
exit;
