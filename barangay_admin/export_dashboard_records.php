<?php
require_once __DIR__ . '/../config/config.php';
requireRole('barangay_admin');

$db        = Database::getInstance()->getConnection();
$u         = currentUser();
$bid       = (int)$u['barangay_id'];

$brgyRow   = $db->prepare("SELECT * FROM barangays WHERE id=?");
$brgyRow->execute([$bid]); $brgyRow = $brgyRow->fetch();
$brgyName  = $brgyRow['barangay_name'] ?? 'Barangay';
$generated = date('F d, Y h:i A');
$filename  = 'all_records_' . strtolower(str_replace(' ', '_', $brgyName)) . '_' . date('Ymd') . '.xls';

$q = fn($sql, $p = []) => (function () use ($db, $sql, $p) {
    $s = $db->prepare($sql); $s->execute($p); return (int)$s->fetchColumn();
})();

// ── Summary stats ─────────────────────────────────────────
$stats = [
    'Total Residents (Active)'      => $q("SELECT COUNT(*) FROM residents WHERE barangay_id=? AND resident_status='Active'",[$bid]),
    'Total Households'              => $q("SELECT COUNT(*) FROM households WHERE barangay_id=? AND status='Active'",[$bid]),
    'Total Blotter Cases'           => $q("SELECT COUNT(*) FROM blotter_cases WHERE barangay_id=? AND status!='Archived'",[$bid]),
    'Total Certificates Issued'     => $q("SELECT COUNT(*) FROM certificates WHERE barangay_id=?",[$bid]),
    'Active Officials'              => $q("SELECT COUNT(*) FROM officials WHERE barangay_id=? AND status='active'",[$bid]),
    'Pending Requests'              => $q("SELECT COUNT(*) FROM user_requests WHERE barangay_id=? AND status='pending'",[$bid]),
    'Resident - Active'             => $q("SELECT COUNT(*) FROM residents WHERE barangay_id=? AND resident_status='Active'",[$bid]),
    'Resident - Inactive'           => $q("SELECT COUNT(*) FROM residents WHERE barangay_id=? AND resident_status='Inactive'",[$bid]),
    'Resident - Transferred'        => $q("SELECT COUNT(*) FROM residents WHERE barangay_id=? AND resident_status='Transferred'",[$bid]),
    'Resident - Deceased'           => $q("SELECT COUNT(*) FROM residents WHERE barangay_id=? AND resident_status='Deceased'",[$bid]),
    'Male'                          => $q("SELECT COUNT(*) FROM residents WHERE barangay_id=? AND gender='Male' AND resident_status='Active'",[$bid]),
    'Female'                        => $q("SELECT COUNT(*) FROM residents WHERE barangay_id=? AND gender='Female' AND resident_status='Active'",[$bid]),
    'Registered Voters'             => $q("SELECT COUNT(*) FROM residents WHERE barangay_id=? AND voter_status='Registered' AND resident_status='Active'",[$bid]),
    'Non-Voters'                    => 0,
    'PWD'                           => $q("SELECT COUNT(*) FROM residents WHERE barangay_id=? AND is_pwd=1 AND resident_status='Active'",[$bid]),
    'Senior Citizens'               => $q("SELECT COUNT(*) FROM residents WHERE barangay_id=? AND is_senior=1 AND resident_status='Active'",[$bid]),
    'Solo Parents'                  => $q("SELECT COUNT(*) FROM residents WHERE barangay_id=? AND is_solo_parent=1 AND resident_status='Active'",[$bid]),
    '4Ps Beneficiaries'             => $q("SELECT COUNT(*) FROM residents WHERE barangay_id=? AND is_4ps=1 AND resident_status='Active'",[$bid]),
    'Employed'                      => $q("SELECT COUNT(*) FROM residents WHERE barangay_id=? AND occupation_status='Employed' AND resident_status='Active'",[$bid]),
    'Unemployed'                    => $q("SELECT COUNT(*) FROM residents WHERE barangay_id=? AND occupation_status='Unemployed' AND resident_status='Active'",[$bid]),
    'Self-Employed'                 => $q("SELECT COUNT(*) FROM residents WHERE barangay_id=? AND occupation_status='Self-Employed' AND resident_status='Active'",[$bid]),
    'Student'                       => $q("SELECT COUNT(*) FROM residents WHERE barangay_id=? AND occupation_status='Student' AND resident_status='Active'",[$bid]),
    'Retired'                       => $q("SELECT COUNT(*) FROM residents WHERE barangay_id=? AND occupation_status='Retired' AND resident_status='Active'",[$bid]),
    'Households - Active'           => $q("SELECT COUNT(*) FROM households WHERE barangay_id=? AND status='Active'",[$bid]),
    'Households - Archived'         => $q("SELECT COUNT(*) FROM households WHERE barangay_id=? AND status='Archived'",[$bid]),
    'Total Household Members'       => $q("SELECT COALESCE(SUM(total_members),0) FROM households WHERE barangay_id=? AND status='Active'",[$bid]),
    'Certificates This Month'       => $q("SELECT COUNT(*) FROM certificates WHERE barangay_id=? AND DATE_FORMAT(date_issued,'%Y-%m')=?",[$bid, date('Y-m')]),
    'Blotter - Open'                => $q("SELECT COUNT(*) FROM blotter_cases WHERE barangay_id=? AND status='Open'",[$bid]),
    'Blotter - Under Investigation' => $q("SELECT COUNT(*) FROM blotter_cases WHERE barangay_id=? AND status='Under Investigation'",[$bid]),
    'Blotter - Settled'             => $q("SELECT COUNT(*) FROM blotter_cases WHERE barangay_id=? AND status='Settled'",[$bid]),
    'Blotter - Escalated'           => $q("SELECT COUNT(*) FROM blotter_cases WHERE barangay_id=? AND status='Escalated'",[$bid]),
    'Blotter - Closed'              => $q("SELECT COUNT(*) FROM blotter_cases WHERE barangay_id=? AND status='Closed'",[$bid]),
    'Transferred OUT (Total)'       => $q("SELECT COUNT(*) FROM residents WHERE barangay_id=? AND resident_status='Transferred'",[$bid]),
    'Transferred OUT (Outside LGU)' => $q("SELECT COUNT(*) FROM residents WHERE barangay_id=? AND resident_status='Transferred' AND transfer_outside=1",[$bid]),
    'Transferred OUT (Local)'       => 0,
    'Transferred IN (Total)'        => $q("SELECT COUNT(*) FROM residents WHERE barangay_id=? AND resident_status='Active' AND transfer_from_barangay IS NOT NULL AND transfer_from_barangay!=''",[$bid]),
    'Net Population Movement'       => 0,
];
$stats['Non-Voters']              = $stats['Total Residents (Active)'] - $stats['Registered Voters'];
$stats['Transferred OUT (Local)'] = $stats['Transferred OUT (Total)'] - $stats['Transferred OUT (Outside LGU)'];
$stats['Net Population Movement'] = $stats['Transferred IN (Total)'] - $stats['Transferred OUT (Total)'];

$sections = [
    'OVERVIEW'                => ['Total Residents (Active)','Total Households','Total Blotter Cases','Total Certificates Issued','Active Officials','Pending Requests'],
    'RESIDENT STATUS'         => ['Resident - Active','Resident - Inactive','Resident - Transferred','Resident - Deceased'],
    'DEMOGRAPHICS'            => ['Male','Female','Registered Voters','Non-Voters'],
    'SECTORS'                 => ['PWD','Senior Citizens','Solo Parents','4Ps Beneficiaries'],
    'EMPLOYMENT'              => ['Employed','Unemployed','Self-Employed','Student','Retired'],
    'HOUSEHOLDS'              => ['Households - Active','Households - Archived','Total Household Members'],
    'CERTIFICATES'            => ['Total Certificates Issued','Certificates This Month'],
    'BLOTTER / PUBLIC SAFETY' => ['Total Blotter Cases','Blotter - Open','Blotter - Under Investigation','Blotter - Settled','Blotter - Escalated','Blotter - Closed'],
    'POPULATION TRANSFERS'    => ['Transferred IN (Total)','Transferred OUT (Total)','Transferred OUT (Outside LGU)','Transferred OUT (Local)','Net Population Movement'],
];

// ── Actual records ────────────────────────────────────────
$residents = $db->prepare("
    SELECT r.id, r.last_name, r.first_name, r.middle_name, r.suffix,
           r.birthdate, r.age, r.gender, r.civil_status, r.contact_number,
           r.voter_status, r.occupation_status, r.resident_status,
           r.is_pwd, r.is_senior, r.is_4ps, r.is_solo_parent,
           r.transfer_from_barangay, r.transfer_to_barangay, r.transfer_outside, r.transfer_reason,
           a.region, a.province, a.city, a.barangay AS addr_barangay,
           a.street, a.purok, a.zone, a.zip_code,
           hm.relationship AS household_role, h.household_code,
           CONCAT(hr.last_name, ', ', hr.first_name) AS household_head,
           r.created_at
    FROM residents r
    LEFT JOIN addresses a         ON r.address_id       = a.id
    LEFT JOIN household_members hm ON hm.resident_id    = r.id
    LEFT JOIN households h        ON hm.household_id    = h.id
    LEFT JOIN residents hr        ON h.head_resident_id = hr.id
    WHERE r.barangay_id = ?
    ORDER BY r.last_name, r.first_name
");
$residents->execute([$bid]); $residents = $residents->fetchAll();

$households = $db->prepare("
    SELECT h.id, h.household_code,
           CONCAT(r.last_name, ', ', r.first_name) AS head_name,
           a.purok, a.street, a.barangay AS addr_barangay,
           h.total_members, h.total_senior, h.total_pwd,
           h.income_range, h.length_of_stay, h.status, h.created_at
    FROM households h
    LEFT JOIN addresses a  ON h.address_id       = a.id
    LEFT JOIN residents r  ON h.head_resident_id = r.id
    WHERE h.barangay_id = ?
    ORDER BY h.household_code
");
$households->execute([$bid]); $households = $households->fetchAll();

$certificates = $db->prepare("
    SELECT c.id,
           CONCAT(r.last_name, ', ', r.first_name, IF(r.middle_name IS NOT NULL AND r.middle_name != '', CONCAT(' ', r.middle_name), '')) AS resident_name,
           c.certificate_type, c.purpose,
           CONCAT(u.first_name, ' ', u.last_name) AS issued_by,
           c.date_issued, c.created_at
    FROM certificates c
    LEFT JOIN residents r ON c.resident_id = r.id
    LEFT JOIN users u     ON c.issued_by   = u.id
    WHERE c.barangay_id = ?
    ORDER BY c.date_issued DESC
");
$certificates->execute([$bid]); $certificates = $certificates->fetchAll();

$blotters = $db->prepare("
    SELECT bc.id, bc.case_number, bc.incident_date, bc.incident_time, bc.location,
           CONCAT(c.last_name, ', ', c.first_name) AS complainant,
           CONCAT(r.last_name, ', ', r.first_name) AS respondent,
           bc.narrative, bc.status, bc.action_taken, bc.created_at
    FROM blotter_cases bc
    LEFT JOIN residents c ON bc.complainant_id = c.id
    LEFT JOIN residents r ON bc.respondent_id  = r.id
    WHERE bc.barangay_id = ? AND bc.status != 'Archived'
    ORDER BY bc.created_at DESC
");
$blotters->execute([$bid]); $blotters = $blotters->fetchAll();

$officials = $db->prepare("
    SELECT full_name, position, term_start, term_end, contact, email, status
    FROM officials WHERE barangay_id = ?
    ORDER BY FIELD(position,'Punong Barangay','Barangay Kagawad','SK Chairperson','Barangay Secretary','Barangay Treasurer'), full_name
");
$officials->execute([$bid]); $officials = $officials->fetchAll();

$certBreakdown = $db->prepare("SELECT certificate_type, COUNT(*) as cnt FROM certificates WHERE barangay_id=? GROUP BY certificate_type ORDER BY cnt DESC");
$certBreakdown->execute([$bid]); $certBreakdown = $certBreakdown->fetchAll();

logActivity($u['id'], 'EXPORT_ALL_RECORDS', 'Dashboard', "Exported all barangay records");

header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
?>
<html>
<head>
<meta charset="UTF-8">
<style>
  body  { font-family: Arial, sans-serif; font-size: 11px; }
  h2    { color: #1a4f7a; margin-bottom: 2px; font-size: 14px; }
  h3    { color: #1a4f7a; font-size: 11px; margin: 20px 0 4px; border-bottom: 2px solid #1a4f7a; padding-bottom: 3px; }
  .meta { font-size: 10px; color: #555; margin-bottom: 14px; }
  table { border-collapse: collapse; width: 100%; margin-bottom: 6px; }
  th    { background: #1a4f7a; color: #fff; padding: 5px 8px; border: 1px solid #ccc;
          font-size: 10px; text-align: left; white-space: nowrap; }
  td    { padding: 4px 8px; border: 1px solid #ddd; font-size: 10px; vertical-align: top; white-space: nowrap; }
  tr:nth-child(even) td { background: #f5f8fc; }
  .sec  { background: #e8f0fe; color: #1a4f7a; font-weight: 700; font-size: 10px;
          padding: 4px 8px; border: 1px solid #c8d8f0; }
  .tot  { background: #1a4f7a; color: #fff; font-weight: 700; }
  .yes  { color: #15803d; font-weight: 700; }
  .no   { color: #9ca3af; }
  .num  { text-align: right; font-weight: 700; }
</style>
</head>
<body>

<h2>Barangay <?= htmlspecialchars($brgyName) ?> — Complete Records Export</h2>
<div class="meta">
  <strong>Generated:</strong> <?= $generated ?> &nbsp;|&nbsp;
  <strong>By:</strong> <?= htmlspecialchars($u['name'] ?? '') ?> &nbsp;|&nbsp;
  <strong>Total Residents:</strong> <?= number_format(count($residents)) ?> &nbsp;|&nbsp;
  <strong>Households:</strong> <?= number_format(count($households)) ?> &nbsp;|&nbsp;
  <strong>Certificates:</strong> <?= number_format(count($certificates)) ?> &nbsp;|&nbsp;
  <strong>Blotter Cases:</strong> <?= number_format(count($blotters)) ?>
</div>

<!-- ═══ SECTION 1: SUMMARY STATISTICS ═══ -->
<h3>Summary Statistics</h3>
<table style="width:420px;">
  <tr><th style="min-width:260px;">Metric</th><th style="min-width:120px;text-align:right;">Value</th></tr>
  <?php foreach ($sections as $secTitle => $keys): ?>
  <tr><td colspan="2" class="sec"><?= htmlspecialchars($secTitle) ?></td></tr>
  <?php foreach ($keys as $key): if (!array_key_exists($key, $stats)) continue;
    $v = $stats[$key]; $d = is_numeric($v) ? number_format((int)$v) : htmlspecialchars((string)$v); ?>
  <tr><td style="padding-left:16px;"><?= htmlspecialchars($key) ?></td><td class="num"><?= $d ?></td></tr>
  <?php endforeach; endforeach; ?>
</table>

<!-- Certificate breakdown -->
<?php if (!empty($certBreakdown)): ?>
<table style="width:420px;margin-top:8px;">
  <tr><th style="min-width:260px;">Certificate Type</th><th style="min-width:120px;text-align:right;">Count</th></tr>
  <?php foreach ($certBreakdown as $cb): ?>
  <tr><td style="padding-left:16px;"><?= htmlspecialchars($cb['certificate_type']) ?></td><td class="num"><?= number_format($cb['cnt']) ?></td></tr>
  <?php endforeach; ?>
</table>
<?php endif; ?>

<!-- ═══ SECTION 2: ALL RESIDENTS ═══ -->
<h3>All Residents (<?= number_format(count($residents)) ?> records)</h3>
<table>
  <thead><tr>
    <th>#</th>
    <th>Last Name</th><th>First Name</th><th>Middle Name</th><th>Suffix</th>
    <th>Birthdate</th><th>Age</th><th>Gender</th><th>Civil Status</th>
    <th>Contact Number</th><th>Voter Status</th><th>Occupation Status</th><th>Resident Status</th>
    <th>PWD</th><th>Senior Citizen</th><th>4Ps</th><th>Solo Parent</th>
    <th>Region</th><th>Province</th><th>City / Municipality</th><th>Barangay</th>
    <th>Street</th><th>Purok</th><th>Zone</th><th>Zip Code</th>
    <th>Household Code</th><th>Household Role</th><th>Household Head</th>
    <th>Transferred From</th><th>Transferred To</th><th>Transfer Outside</th><th>Transfer Reason</th>
    <th>Date Registered</th>
  </tr></thead>
  <tbody>
  <?php foreach ($residents as $i => $r): ?>
  <tr>
    <td><?= $i + 1 ?></td>
    <td><?= htmlspecialchars($r['last_name']   ?? '') ?></td>
    <td><?= htmlspecialchars($r['first_name']  ?? '') ?></td>
    <td><?= htmlspecialchars($r['middle_name'] ?? '') ?></td>
    <td><?= htmlspecialchars($r['suffix']      ?? '') ?></td>
    <td><?= $r['birthdate'] ? date('M d, Y', strtotime($r['birthdate'])) : '' ?></td>
    <td><?= (int)$r['age'] ?></td>
    <td><?= htmlspecialchars($r['gender']           ?? '') ?></td>
    <td><?= htmlspecialchars($r['civil_status']      ?? '') ?></td>
    <td><?= htmlspecialchars($r['contact_number']    ?? '') ?></td>
    <td><?= htmlspecialchars($r['voter_status']      ?? '') ?></td>
    <td><?= htmlspecialchars($r['occupation_status'] ?? '') ?></td>
    <td><?= htmlspecialchars($r['resident_status']   ?? '') ?></td>
    <td class="<?= $r['is_pwd']         ? 'yes' : 'no' ?>"><?= $r['is_pwd']         ? 'Yes' : 'No' ?></td>
    <td class="<?= $r['is_senior']      ? 'yes' : 'no' ?>"><?= $r['is_senior']      ? 'Yes' : 'No' ?></td>
    <td class="<?= $r['is_4ps']         ? 'yes' : 'no' ?>"><?= $r['is_4ps']         ? 'Yes' : 'No' ?></td>
    <td class="<?= $r['is_solo_parent'] ? 'yes' : 'no' ?>"><?= $r['is_solo_parent'] ? 'Yes' : 'No' ?></td>
    <td><?= htmlspecialchars($r['region']        ?? '') ?></td>
    <td><?= htmlspecialchars($r['province']      ?? '') ?></td>
    <td><?= htmlspecialchars($r['city']          ?? '') ?></td>
    <td><?= htmlspecialchars($r['addr_barangay'] ?? '') ?></td>
    <td><?= htmlspecialchars($r['street']        ?? '') ?></td>
    <td><?= htmlspecialchars($r['purok']         ?? '') ?></td>
    <td><?= htmlspecialchars($r['zone']          ?? '') ?></td>
    <td><?= htmlspecialchars($r['zip_code']      ?? '') ?></td>
    <td><?= htmlspecialchars($r['household_code'] ?? '') ?></td>
    <td><?= htmlspecialchars($r['household_role'] ?? '') ?></td>
    <td><?= htmlspecialchars($r['household_head'] ?? '') ?></td>
    <td><?= htmlspecialchars($r['transfer_from_barangay'] ?? '') ?></td>
    <td><?= htmlspecialchars($r['transfer_to_barangay']   ?? '') ?></td>
    <td><?= !empty($r['transfer_outside']) ? 'Yes' : 'No' ?></td>
    <td><?= htmlspecialchars($r['transfer_reason'] ?? '') ?></td>
    <td><?= $r['created_at'] ? date('M d, Y', strtotime($r['created_at'])) : '' ?></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<!-- ═══ SECTION 3: ALL HOUSEHOLDS ═══ -->
<h3>All Households (<?= number_format(count($households)) ?> records)</h3>
<table>
  <thead><tr>
    <th>#</th>
    <th>Household Code</th><th>Head of Family</th>
    <th>Purok</th><th>Street</th><th>Barangay</th>
    <th>Total Members</th><th>Senior</th><th>PWD</th>
    <th>Income Range</th><th>Length of Stay</th><th>Status</th><th>Date Added</th>
  </tr></thead>
  <tbody>
  <?php foreach ($households as $i => $h): ?>
  <tr>
    <td><?= $i + 1 ?></td>
    <td><?= htmlspecialchars($h['household_code'] ?? '') ?></td>
    <td><?= htmlspecialchars($h['head_name']      ?? '') ?></td>
    <td><?= htmlspecialchars($h['purok']          ?? '') ?></td>
    <td><?= htmlspecialchars($h['street']         ?? '') ?></td>
    <td><?= htmlspecialchars($h['addr_barangay']  ?? '') ?></td>
    <td><?= (int)$h['total_members'] ?></td>
    <td><?= (int)$h['total_senior']  ?></td>
    <td><?= (int)$h['total_pwd']     ?></td>
    <td><?= htmlspecialchars($h['income_range']   ?? '') ?></td>
    <td><?= htmlspecialchars($h['length_of_stay'] ?? '') ?></td>
    <td><?= htmlspecialchars($h['status']         ?? '') ?></td>
    <td><?= $h['created_at'] ? date('M d, Y', strtotime($h['created_at'])) : '' ?></td>
  </tr>
  <?php endforeach; ?>
  <tr>
    <td colspan="6" class="tot">TOTAL</td>
    <td class="tot"><?= number_format(array_sum(array_column($households,'total_members'))) ?></td>
    <td class="tot"><?= number_format(array_sum(array_column($households,'total_senior'))) ?></td>
    <td class="tot"><?= number_format(array_sum(array_column($households,'total_pwd'))) ?></td>
    <td colspan="4" class="tot"></td>
  </tr>
  </tbody>
</table>

<!-- ═══ SECTION 4: ALL CERTIFICATES ═══ -->
<h3>All Certificates Issued (<?= number_format(count($certificates)) ?> records)</h3>
<table>
  <thead><tr>
    <th>#</th>
    <th>Resident Name</th><th>Certificate Type</th><th>Purpose</th>
    <th>Issued By</th><th>Date Issued</th>
  </tr></thead>
  <tbody>
  <?php foreach ($certificates as $i => $c): ?>
  <tr>
    <td><?= $i + 1 ?></td>
    <td><?= htmlspecialchars($c['resident_name']    ?? '') ?></td>
    <td><?= htmlspecialchars($c['certificate_type'] ?? '') ?></td>
    <td><?= htmlspecialchars($c['purpose']          ?? '') ?></td>
    <td><?= htmlspecialchars($c['issued_by']        ?? '') ?></td>
    <td><?= $c['date_issued'] ? date('M d, Y', strtotime($c['date_issued'])) : '' ?></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<!-- ═══ SECTION 5: ALL BLOTTER CASES ═══ -->
<h3>All Blotter Cases (<?= number_format(count($blotters)) ?> records)</h3>
<table>
  <thead><tr>
    <th>#</th>
    <th>Case No.</th><th>Complainant</th><th>Respondent</th>
    <th>Incident Date</th><th>Incident Time</th><th>Location</th>
    <th>Status</th><th>Action Taken</th><th>Date Filed</th>
  </tr></thead>
  <tbody>
  <?php foreach ($blotters as $i => $b): ?>
  <tr>
    <td><?= $i + 1 ?></td>
    <td><?= htmlspecialchars($b['case_number']  ?? '') ?></td>
    <td><?= htmlspecialchars($b['complainant']  ?? '') ?></td>
    <td><?= htmlspecialchars($b['respondent']   ?? '') ?></td>
    <td><?= $b['incident_date'] ? date('M d, Y', strtotime($b['incident_date'])) : '' ?></td>
    <td><?= htmlspecialchars($b['incident_time'] ?? '') ?></td>
    <td><?= htmlspecialchars($b['location']      ?? '') ?></td>
    <td><?= htmlspecialchars($b['status']        ?? '') ?></td>
    <td><?= htmlspecialchars(mb_substr($b['action_taken'] ?? '', 0, 100)) ?></td>
    <td><?= $b['created_at'] ? date('M d, Y', strtotime($b['created_at'])) : '' ?></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<!-- ═══ SECTION 6: OFFICIALS ═══ -->
<h3>Barangay Officials (<?= number_format(count($officials)) ?> records)</h3>
<table>
  <thead><tr>
    <th>#</th>
    <th>Full Name</th><th>Position</th>
    <th>Term Start</th><th>Term End</th>
    <th>Contact</th><th>Email</th><th>Status</th>
  </tr></thead>
  <tbody>
  <?php foreach ($officials as $i => $o): ?>
  <tr>
    <td><?= $i + 1 ?></td>
    <td><?= htmlspecialchars($o['full_name'] ?? '') ?></td>
    <td><?= htmlspecialchars($o['position']  ?? '') ?></td>
    <td><?= $o['term_start'] ? date('M d, Y', strtotime($o['term_start'])) : '' ?></td>
    <td><?= $o['term_end']   ? date('M d, Y', strtotime($o['term_end']))   : '' ?></td>
    <td><?= htmlspecialchars($o['contact'] ?? '') ?></td>
    <td><?= htmlspecialchars($o['email']   ?? '') ?></td>
    <td><?= htmlspecialchars($o['status']  ?? '') ?></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>

</body>
</html>
