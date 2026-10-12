<?php
require_once __DIR__ . '/../config/config.php';
requireRole('super_admin');

$db      = Database::getInstance()->getConnection();
$u       = currentUser();
$tabView = $_GET['view'] ?? 'residents';
$search  = sanitize($_GET['search']      ?? '');
$brgFilter = (int)($_GET['barangay_id'] ?? 0);
$genderF   = sanitize($_GET['gender']   ?? '');
$statusF   = sanitize($_GET['status']   ?? '');

logActivity($u['id'], 'EXPORT_BARANGAY_RECORDS', 'Barangay Records', "Exported $tabView list");

if ($tabView === 'residents') {
    $where = "WHERE r.resident_status NOT IN ('Inactive')";
    if ($brgFilter) $where .= " AND r.barangay_id = $brgFilter";
    if ($genderF)   $where .= " AND r.gender = " . $db->quote($genderF);
    if ($statusF)   $where .= " AND r.resident_status = " . $db->quote($statusF);
    if ($search)    $where .= " AND (r.first_name LIKE " . $db->quote("%$search%") .
                              " OR r.last_name LIKE "   . $db->quote("%$search%") .
                              " OR b.barangay_name LIKE " . $db->quote("%$search%") . ")";

    $rows = $db->query("
        SELECT b.barangay_name,
               r.last_name, r.first_name, r.middle_name,
               r.gender, r.birthdate, r.civil_status,
               r.contact_number, r.resident_status, r.voter_status,
               r.occupation_status,
               IF(r.is_pwd,    'Yes','No') AS pwd,
               IF(r.is_senior, 'Yes','No') AS senior,
               IF(r.is_solo_parent,'Yes','No') AS solo_parent,
               IF(r.is_4ps,    'Yes','No') AS is_4ps,
               DATE_FORMAT(r.created_at,'%Y-%m-%d') AS date_added
        FROM residents r
        LEFT JOIN barangays b ON b.id = r.barangay_id
        $where
        ORDER BY b.barangay_name, r.last_name, r.first_name
    ")->fetchAll(PDO::FETCH_ASSOC);

    $filename = 'residents_all_barangays_' . date('Ymd') . '.csv';
    $headers  = ['Barangay','Last Name','First Name','Middle Name','Gender','Birthdate','Civil Status',
                 'Contact','Status','Voter Status','Employment','PWD','Senior','Solo Parent','4Ps','Date Added'];
} else {
    $where = "WHERE 1=1";
    if ($brgFilter) $where .= " AND h.barangay_id = $brgFilter";
    if ($search)    $where .= " AND (h.household_code LIKE " . $db->quote("%$search%") .
                              " OR b.barangay_name LIKE " . $db->quote("%$search%") . ")";

    $rows = $db->query("
        SELECT b.barangay_name,
               h.household_code,
               CONCAT(r.last_name, ', ', r.first_name) AS head_name,
               CONCAT_WS(', ', NULLIF(a.purok,''), NULLIF(a.street,''), NULLIF(a.barangay,''), NULLIF(a.city,'')) AS address,
               (SELECT COUNT(*) FROM residents r2 WHERE r2.household_id=h.id AND r2.resident_status='Active') AS active_members,
               DATE_FORMAT(h.created_at,'%Y-%m-%d') AS date_registered
        FROM households h
        LEFT JOIN barangays b ON b.id = h.barangay_id
        LEFT JOIN residents r ON r.id = h.head_resident_id
        LEFT JOIN addresses a ON a.id = h.address_id
        $where
        ORDER BY b.barangay_name, h.household_code
    ")->fetchAll(PDO::FETCH_ASSOC);

    $filename = 'households_all_barangays_' . date('Ymd') . '.csv';
    $headers  = ['Barangay','Household Code','Head of Household','Address','Active Members','Date Registered'];
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fputcsv($out, $headers);
foreach ($rows as $row) {
    fputcsv($out, array_values($row));
}
fclose($out);
exit;
