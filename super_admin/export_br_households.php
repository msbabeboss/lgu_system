<?php
require_once __DIR__ . '/../config/config.php';
requireRole('super_admin');

$db = Database::getInstance()->getConnection();
$u  = currentUser();

// ── GET: filter-based (all) ───────────────────────────────────
$brgFilter = (int)($_GET['barangay_id'] ?? 0);
$search    = trim(strip_tags($_GET['search']    ?? ''));
$statusF   = trim(strip_tags($_GET['status']    ?? ''));
$dateFrom  = trim(strip_tags($_GET['date_from'] ?? ''));
$dateTo    = trim(strip_tags($_GET['date_to']   ?? ''));

$where = "WHERE 1=1";
if ($brgFilter) $where .= " AND h.barangay_id = " . (int)$brgFilter;
if ($statusF)   $where .= " AND h.status = " . $db->quote($statusF);
if ($dateFrom)  $where .= " AND h.created_at >= " . $db->quote($dateFrom . ' 00:00:00');
if ($dateTo)    $where .= " AND h.created_at <= " . $db->quote($dateTo   . ' 23:59:59');
if ($search)    $where .= " AND (h.household_code LIKE " . $db->quote("%$search%") .
                          " OR b.barangay_name LIKE " . $db->quote("%$search%") .
                          " OR r.last_name LIKE " . $db->quote("%$search%") .
                          " OR r.first_name LIKE " . $db->quote("%$search%") . ")";

logActivity($u['id'], 'EXPORT_BR_HOUSEHOLDS', 'Barangay Records', 'Exported households CSV');

$sql = "
    SELECT h.id, h.household_code, h.total_members, h.total_senior, h.total_pwd,
           h.income_range, h.length_of_stay, h.contact_number, h.status, h.created_at,
           b.barangay_name,
           CONCAT(COALESCE(r.last_name,''),
                  IF(r.first_name IS NOT NULL AND r.first_name != '',
                     CONCAT(', ', r.first_name), '')) AS head_name,
           CONCAT_WS(', ', NULLIF(a.purok,''), NULLIF(a.street,''),
                           NULLIF(a.barangay,''), NULLIF(a.city,'')) AS full_address
    FROM households h
    LEFT JOIN barangays b ON b.id = h.barangay_id
    LEFT JOIN residents r ON r.id = h.head_resident_id
    LEFT JOIN addresses a ON a.id = h.address_id
    $where
    ORDER BY b.barangay_name, h.household_code
";

$rows = $db->query($sql)->fetchAll();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="households_export_' . date('Ymd') . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['Barangay','Household Code','Head of Household','Address','Total Members',
               'Total Senior','Total PWD','Income Range','Length of Stay','Contact','Status','Date Registered']);
foreach ($rows as $row) {
    fputcsv($out, [
        $row['barangay_name']   ?? '',
        $row['household_code']  ?? '',
        $row['head_name']       ?? '',
        $row['full_address']    ?? '',
        (int)$row['total_members'],
        (int)$row['total_senior'],
        (int)$row['total_pwd'],
        $row['income_range']    ?? '',
        $row['length_of_stay']  ?? '',
        $row['contact_number']  ?? '',
        $row['status']          ?? '',
        $row['created_at'] ? "\t".$row['created_at'] : '',
    ]);
}
fclose($out);
exit;
