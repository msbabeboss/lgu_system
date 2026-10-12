<?php
require_once __DIR__ . '/../config/config.php';
requireRole('super_admin');

$db = Database::getInstance()->getConnection();
$u  = currentUser();

$search       = trim(strip_tags($_GET['search'] ?? ''));
$statusFilter = trim(strip_tags($_GET['status'] ?? ''));

$where = "WHERE 1=1";
if ($search)       $where .= " AND (b.barangay_name LIKE " . $db->quote("%$search%") . " OR b.municipality LIKE " . $db->quote("%$search%") . ")";
if ($statusFilter) $where .= " AND b.status = " . $db->quote($statusFilter);

logActivity($u['id'], 'EXPORT_BR_BARANGAYS', 'Barangay Records', 'Exported barangay list CSV' . ($search ? " search=$search" : '') . ($statusFilter ? " status=$statusFilter" : ''));

$rows = $db->query("
    SELECT b.id, b.barangay_name, b.municipality, b.region, b.province, b.zip_code, b.status, b.created_at,
        (SELECT COUNT(*) FROM residents r    WHERE r.barangay_id=b.id AND r.resident_status='Active') AS total_residents,
        (SELECT COUNT(*) FROM households h   WHERE h.barangay_id=b.id)                                AS total_households,
        (SELECT COUNT(*) FROM officials o    WHERE o.barangay_id=b.id AND o.status='active')          AS total_officials,
        (SELECT COUNT(*) FROM certificates c WHERE c.barangay_id=b.id)                                AS total_certs,
        (SELECT o2.full_name FROM officials o2
         WHERE o2.barangay_id=b.id AND o2.position='Barangay Captain' AND o2.status='active'
         ORDER BY o2.term_start DESC LIMIT 1)                                                         AS captain_name
    FROM barangays b
    $where
    ORDER BY b.barangay_name
")->fetchAll();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="barangays_export_' . date('Ymd') . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['ID','Barangay Name','Municipality','Region','Province','ZIP','Status','Active Residents','Households','Officials','Certificates','Barangay Captain','Date Registered']);
foreach ($rows as $row) {
    fputcsv($out, [
        $row['id'],
        $row['barangay_name'],
        $row['municipality'],
        $row['region']           ?? '',
        $row['province']         ?? '',
        $row['zip_code']         ?? '',
        $row['status'],
        $row['total_residents'],
        $row['total_households'],
        $row['total_officials'],
        $row['total_certs'],
        $row['captain_name']     ?? '',
        $row['created_at'] ? "\t".$row['created_at'] : '',
    ]);
}
fclose($out);
exit;
