<?php
require_once __DIR__ . '/../config/config.php';
requireRole('barangay_admin');

$db  = Database::getInstance()->getConnection();
$u   = currentUser();
$bid = (int)$u['barangay_id'];
$id  = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("
    SELECT c.certificate_type, c.purpose, c.date_issued, c.certificate_data,
           r.id AS resident_id, r.first_name, r.middle_name, r.last_name, r.suffix,
           r.age, r.gender, r.civil_status, r.birthdate, r.photo,
           a.purok
    FROM certificates c
    LEFT JOIN residents r ON c.resident_id = r.id
    LEFT JOIN addresses a ON r.address_id  = a.id
    WHERE c.id = ? AND c.barangay_id = ?
");
$stmt->execute([$id, $bid]);
$row = $stmt->fetch();

if (!$row) { jsonResponse([]); }

$fullName = strtoupper(
    $row['last_name'] . ', ' . $row['first_name'] .
    ($row['middle_name'] ? ' ' . $row['middle_name'] : '') .
    ($row['suffix']      ? ' ' . $row['suffix']      : '')
);

$certTypeMap = [
    'Clearance'           => 'clearance',
    'Indigency'           => 'indigency',
    'Residency'           => 'residency',
    'Live-in'             => 'livein',
    'Same Person'         => 'sameperson',
    'Business Permit'     => 'business',
    'BARC Certificate'    => 'barc',       // matches JS typeMap key 'barc'
    'Solo Parent'         => 'soloparent',
    'Proof of Low Income' => 'lowincome',  // matches JS typeMap key 'lowincome'
    // Legacy aliases kept for backward-compat with any old rows
    'BARC'                => 'barc',
    'Low Income'          => 'lowincome',
    'Building Permit'     => 'business',
    'Good Moral'          => 'clearance',
];

$bday = $row['birthdate'] ? date('F d, Y', strtotime($row['birthdate'])) : '';
$dateIssued = $row['date_issued'] ? date('F d, Y', strtotime($row['date_issued'])) : '';
$dayNum     = $row['date_issued'] ? date('j',  strtotime($row['date_issued'])) : '';
$monthStr   = $row['date_issued'] ? date('F',  strtotime($row['date_issued'])) : '';
$yearStr    = $row['date_issued'] ? date('Y',  strtotime($row['date_issued'])) : '';
$yearLast   = $row['date_issued'] ? date('y',  strtotime($row['date_issued'])) : '';
// For Solo Parent the year field uses last digit only
$yearLastDigit = $yearStr ? substr($yearStr, -1) : '';
$certificateData = $row['certificate_data'] ? json_decode($row['certificate_data'], true) : null;

jsonResponse([
    'cert_type'   => $row['certificate_type'],
    'cert_key'    => $certTypeMap[$row['certificate_type']] ?? 'clearance',
    'purpose'     => $row['purpose'],
    'resident_id' => $row['resident_id'],
    'full_name'   => $fullName,
    'age'         => $row['age'],
    'gender'      => $row['gender'],
    'civil_status'=> $row['civil_status'],
    'birthdate'   => $bday,
    'photo'       => $row['photo'],
    'purok'       => $row['purok'] ?? '',
    'day'         => $dayNum,
    'month'       => $monthStr,
    'year'        => $yearStr,
    'year_last'   => $yearLastDigit,
    'date_issued' => $dateIssued,
    'certificate_data' => is_array($certificateData) ? $certificateData : null,
]);
