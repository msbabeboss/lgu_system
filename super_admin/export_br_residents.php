<?php
require_once __DIR__ . '/../config/config.php';
requireRole('super_admin');

$db = Database::getInstance()->getConnection();
$u  = currentUser();

// Safe migration
try { $db->exec("ALTER TABLE residents ADD COLUMN IF NOT EXISTS email VARCHAR(150) NULL"); } catch (PDOException $e) {}

// ── GET: filter-based (all) ───────────────────────────────────
$search    = trim(strip_tags($_GET['search']    ?? ''));
$brgFilter = (int)($_GET['barangay_id'] ?? 0);
$genderF   = trim(strip_tags($_GET['gender']   ?? ''));
$statusF   = trim(strip_tags($_GET['status']   ?? ''));
$dateFrom  = trim(strip_tags($_GET['date_from']?? ''));
$dateTo    = trim(strip_tags($_GET['date_to']  ?? ''));

$where = "WHERE 1=1";
if ($brgFilter) $where .= " AND r.barangay_id = " . (int)$brgFilter;
if ($genderF)   $where .= " AND r.gender = " . $db->quote($genderF);
if ($statusF)   $where .= " AND r.resident_status = " . $db->quote($statusF);
if ($dateFrom)  $where .= " AND r.created_at >= " . $db->quote($dateFrom . ' 00:00:00');
if ($dateTo)    $where .= " AND r.created_at <= " . $db->quote($dateTo   . ' 23:59:59');
if ($search)    $where .= " AND (r.first_name LIKE " . $db->quote("%$search%") .
                          " OR r.last_name LIKE " . $db->quote("%$search%") .
                          " OR r.contact_number LIKE " . $db->quote("%$search%") .
                          " OR b.barangay_name LIKE " . $db->quote("%$search%") . ")";

logActivity($u['id'], 'EXPORT_BR_RESIDENTS', 'Barangay Records', 'Exported residents CSV');

$sql = "
    SELECT r.first_name, r.middle_name, r.last_name, r.birthdate, r.gender,
           r.civil_status, r.contact_number, r.email, r.resident_status, r.is_pwd, r.is_senior,
           r.is_solo_parent, r.is_4ps, r.voter_status, r.occupation_status, r.created_at,
           b.barangay_name
    FROM residents r
    LEFT JOIN barangays b ON b.id = r.barangay_id
    $where
    ORDER BY b.barangay_name, r.last_name, r.first_name
";

$rows = $db->query($sql)->fetchAll();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="residents_export_' . date('Ymd') . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['Barangay','Last Name','First Name','Middle Name','Gender','Birthdate','Age','Civil Status','Contact','Email','Status','Voter Status','Employment','PWD','Senior','Solo Parent','4Ps','Date Added']);
foreach ($rows as $r) {
    $age = $r['birthdate'] ? (int)(new DateTime($r['birthdate']))->diff(new DateTime())->y : '';
    fputcsv($out, [
        $r['barangay_name']    ?? '',
        $r['last_name'],
        $r['first_name'],
        $r['middle_name']      ?? '',
        $r['gender']           ?? '',
        $r['birthdate'] ? "\t".$r['birthdate'] : '',
        $age,
        $r['civil_status']     ?? '',
        $r['contact_number']   ?? '',
        $r['email']            ?? '',
        $r['resident_status']  ?? '',
        $r['voter_status']     ?? '',
        $r['occupation_status']?? '',
        $r['is_pwd']    ? 'Yes' : 'No',
        $r['is_senior'] ? 'Yes' : 'No',
        $r['is_solo_parent'] ? 'Yes' : 'No',
        $r['is_4ps']    ? 'Yes' : 'No',
        $r['created_at'] ? "\t".$r['created_at'] : '',
    ]);
}
fclose($out);
exit;
