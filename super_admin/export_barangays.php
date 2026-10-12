<?php
require_once __DIR__ . '/../config/config.php';
requireRole('super_admin');

$db = Database::getInstance()->getConnection();
$u  = currentUser();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$where = $id ? "WHERE b.id = $id" : "";

$barangays = $db->query("
    SELECT b.*,
        (SELECT COUNT(*) FROM residents r WHERE r.barangay_id=b.id AND r.resident_status='Active')                          AS total_residents,
        (SELECT COUNT(*) FROM residents r WHERE r.barangay_id=b.id AND r.resident_status='Active' AND r.gender='Male')      AS total_male,
        (SELECT COUNT(*) FROM residents r WHERE r.barangay_id=b.id AND r.resident_status='Active' AND r.gender='Female')    AS total_female,
        (SELECT COUNT(*) FROM households h WHERE h.barangay_id=b.id)                                                        AS total_households,
        (SELECT COUNT(*) FROM blotter_cases bc WHERE bc.barangay_id=b.id)                                                   AS total_blotter,
        (SELECT COUNT(*) FROM certificates c WHERE c.barangay_id=b.id)                                                     AS total_certs,
        (SELECT COUNT(*) FROM residents r WHERE r.barangay_id=b.id AND r.resident_status='Active' AND r.is_pwd=1)           AS total_pwd,
        (SELECT COUNT(*) FROM residents r WHERE r.barangay_id=b.id AND r.resident_status='Active' AND r.is_senior=1)        AS total_senior,
        (SELECT COUNT(*) FROM residents r WHERE r.barangay_id=b.id AND r.resident_status='Active' AND r.is_solo_parent=1)   AS total_solo,
        (SELECT COUNT(*) FROM residents r WHERE r.barangay_id=b.id AND r.resident_status='Active' AND r.is_4ps=1)           AS total_4ps,
        (SELECT COUNT(*) FROM residents r WHERE r.barangay_id=b.id AND r.resident_status='Active' AND r.voter_status='Registered')     AS total_registered_voters,
        (SELECT COUNT(*) FROM residents r WHERE r.barangay_id=b.id AND r.resident_status='Active' AND r.voter_status='Not Registered') AS total_non_voters,
        (SELECT COUNT(*) FROM residents r WHERE r.barangay_id=b.id AND r.resident_status='Active' AND r.occupation_status='Employed')       AS emp_employed,
        (SELECT COUNT(*) FROM residents r WHERE r.barangay_id=b.id AND r.resident_status='Active' AND r.occupation_status='Unemployed')     AS emp_unemployed,
        (SELECT COUNT(*) FROM residents r WHERE r.barangay_id=b.id AND r.resident_status='Active' AND r.occupation_status='Self-Employed')  AS emp_self,
        (SELECT COUNT(*) FROM residents r WHERE r.barangay_id=b.id AND r.resident_status='Active' AND r.occupation_status='Student')        AS emp_student,
        (SELECT COUNT(*) FROM residents r WHERE r.barangay_id=b.id AND r.resident_status='Active' AND r.occupation_status='Retired')        AS emp_retired
    FROM barangays b $where
    ORDER BY b.barangay_name
")->fetchAll();

// ── System-wide totals ────────────────────────────────────
$totals = [
    'total_residents'        => array_sum(array_column($barangays, 'total_residents')),
    'total_male'             => array_sum(array_column($barangays, 'total_male')),
    'total_female'           => array_sum(array_column($barangays, 'total_female')),
    'total_households'       => array_sum(array_column($barangays, 'total_households')),
    'total_blotter'          => array_sum(array_column($barangays, 'total_blotter')),
    'total_certs'            => array_sum(array_column($barangays, 'total_certs')),
    'total_pwd'              => array_sum(array_column($barangays, 'total_pwd')),
    'total_senior'           => array_sum(array_column($barangays, 'total_senior')),
    'total_solo'             => array_sum(array_column($barangays, 'total_solo')),
    'total_4ps'              => array_sum(array_column($barangays, 'total_4ps')),
    'total_registered_voters'=> array_sum(array_column($barangays, 'total_registered_voters')),
    'total_non_voters'       => array_sum(array_column($barangays, 'total_non_voters')),
    'emp_employed'           => array_sum(array_column($barangays, 'emp_employed')),
    'emp_unemployed'         => array_sum(array_column($barangays, 'emp_unemployed')),
    'emp_self'               => array_sum(array_column($barangays, 'emp_self')),
    'emp_student'            => array_sum(array_column($barangays, 'emp_student')),
    'emp_retired'            => array_sum(array_column($barangays, 'emp_retired')),
];

logActivity($u['id'], 'EXPORT_BARANGAYS', 'Barangays', 'Exported full barangay statistics report');

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favcon.png">
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favcon.png">
<meta charset="UTF-8">
<title>Barangay Statistics Report — <?= APP_NAME ?></title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: Arial, sans-serif; font-size: 11px; color: #1a2e1f; background: #fff; padding: 20px; }

/* Print controls */
.no-print { margin-bottom: 18px; display: flex; gap: 8px; }
.no-print button { padding: 7px 18px; border: none; border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 600; }
.btn-print { background: #1a4f7a; color: #fff; }
.btn-close-btn { background: #e5e7eb; color: #374151; }

/* Header */
.report-header { text-align: center; margin-bottom: 18px; border-bottom: 2px solid #1a4f7a; padding-bottom: 12px; }
.report-header h1 { font-size: 16px; color: #1a4f7a; }
.report-header p  { font-size: 11px; color: #3a7fa8; margin-top: 3px; }

/* Section titles */
.section-title {
    font-size: 11px; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.06em; color: #fff; background: #1a4f7a;
    padding: 5px 10px; margin: 18px 0 6px; border-radius: 4px;
}

/* Tables */
table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
th { background: #3a7fa8; color: #fff; padding: 6px 8px; text-align: center; font-size: 10px; border: 1px solid #b0c4d8; }
th.left { text-align: left; }
td { padding: 5px 8px; border: 1px solid #90c0d8; font-size: 10.5px; }
td.num { text-align: right; }
td.center { text-align: center; }
tr:nth-child(even) { background: #f4f7fa; }
tr.total-row td { background: #1a4f7a; color: #fff; font-weight: 700; }
tr.total-row td.num { text-align: right; }

/* Summary boxes */
.summary-grid { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 18px; }
.summary-box { border: 1px solid #90c0d8; border-radius: 6px; padding: 8px 14px; min-width: 130px; flex: 1; }
.summary-box .val { font-size: 18px; font-weight: 800; color: #1a4f7a; }
.summary-box .lbl { font-size: 9.5px; color: #3a7fa8; margin-top: 2px; }

@media print {
    .no-print { display: none !important; }
    body { padding: 10px; }
    .section-title { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    tr.total-row td { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>
</head>
<body>

<div class="no-print">
    <button class="btn-print" onclick="window.print()">🖨️ Print / Save as PDF</button>
    <button class="btn-close-btn" onclick="window.close()">✕ Close</button>
</div>

<!-- Header -->
<div class="report-header">
    <h1><?= APP_NAME ?> — Barangay Statistics Report</h1>
    <p>Generated: <?= date('F d, Y h:i A') ?> &nbsp;|&nbsp; Prepared by: <?= htmlspecialchars($u['name']) ?></p>
</div>

<!-- ① SYSTEM-WIDE SUMMARY BOXES -->
<div class="section-title">📊 System-Wide Overview</div>
<div class="summary-grid">
    <div class="summary-box"><div class="val"><?= number_format($totals['total_residents']) ?></div><div class="lbl">Total Active Residents</div></div>
    <div class="summary-box"><div class="val"><?= number_format($totals['total_male']) ?></div><div class="lbl">Male</div></div>
    <div class="summary-box"><div class="val"><?= number_format($totals['total_female']) ?></div><div class="lbl">Female</div></div>
    <div class="summary-box"><div class="val"><?= number_format($totals['total_households']) ?></div><div class="lbl">Total Households</div></div>
    <div class="summary-box"><div class="val"><?= number_format($totals['total_blotter']) ?></div><div class="lbl">Blotter Cases</div></div>
    <div class="summary-box"><div class="val"><?= number_format($totals['total_certs']) ?></div><div class="lbl">Certificates Issued</div></div>
    <div class="summary-box"><div class="val"><?= number_format($totals['total_senior']) ?></div><div class="lbl">Senior Citizens</div></div>
    <div class="summary-box"><div class="val"><?= number_format($totals['total_pwd']) ?></div><div class="lbl">PWD</div></div>
    <div class="summary-box"><div class="val"><?= number_format($totals['total_solo']) ?></div><div class="lbl">Solo Parents</div></div>
    <div class="summary-box"><div class="val"><?= number_format($totals['total_4ps']) ?></div><div class="lbl">4Ps Beneficiaries</div></div>
    <div class="summary-box"><div class="val"><?= number_format($totals['total_registered_voters']) ?></div><div class="lbl">Registered Voters</div></div>
    <div class="summary-box"><div class="val"><?= number_format($totals['emp_employed']) ?></div><div class="lbl">Employed</div></div>
</div>

<!-- ② MAIN TABLE — per barangay -->
<div class="section-title">🏘️ Per Barangay Breakdown</div>
<table>
    <thead>
        <tr>
            <th class="left" rowspan="2">#</th>
            <th class="left" rowspan="2">Barangay</th>
            <th rowspan="2">Status</th>
            <th rowspan="2">Residents</th>
            <th colspan="2">Gender</th>
            <th rowspan="2">Households</th>
            <th rowspan="2">Blotter</th>
            <th rowspan="2">Certificates</th>
        </tr>
        <tr>
            <th>Male</th>
            <th>Female</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($barangays as $i => $b): ?>
        <tr>
            <td class="center"><?= $i + 1 ?></td>
            <td><?= htmlspecialchars($b['barangay_name']) ?><br><span style="color:#3a7fa8;font-size:9.5px;"><?= htmlspecialchars($b['municipality']) ?></span></td>
            <td class="center"><?= ucfirst($b['status']) ?></td>
            <td class="num"><?= number_format($b['total_residents']) ?></td>
            <td class="num"><?= number_format($b['total_male']) ?></td>
            <td class="num"><?= number_format($b['total_female']) ?></td>
            <td class="num"><?= number_format($b['total_households']) ?></td>
            <td class="num"><?= number_format($b['total_blotter']) ?></td>
            <td class="num"><?= number_format($b['total_certs']) ?></td>
        </tr>
    <?php endforeach; ?>
        <tr class="total-row">
            <td colspan="3">TOTAL</td>
            <td class="num"><?= number_format($totals['total_residents']) ?></td>
            <td class="num"><?= number_format($totals['total_male']) ?></td>
            <td class="num"><?= number_format($totals['total_female']) ?></td>
            <td class="num"><?= number_format($totals['total_households']) ?></td>
            <td class="num"><?= number_format($totals['total_blotter']) ?></td>
            <td class="num"><?= number_format($totals['total_certs']) ?></td>
        </tr>
    </tbody>
</table>

<!-- ③ SECTORS TABLE -->
<div class="section-title">👥 Sectors per Barangay</div>
<table>
    <thead>
        <tr>
            <th class="left">#</th>
            <th class="left">Barangay</th>
            <th>Senior Citizens</th>
            <th>PWD</th>
            <th>Solo Parents</th>
            <th>4Ps Beneficiaries</th>
            <th>Registered Voters</th>
            <th>Non-Voters</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($barangays as $i => $b): ?>
        <tr>
            <td class="center"><?= $i + 1 ?></td>
            <td><?= htmlspecialchars($b['barangay_name']) ?></td>
            <td class="num"><?= number_format($b['total_senior']) ?></td>
            <td class="num"><?= number_format($b['total_pwd']) ?></td>
            <td class="num"><?= number_format($b['total_solo']) ?></td>
            <td class="num"><?= number_format($b['total_4ps']) ?></td>
            <td class="num"><?= number_format($b['total_registered_voters']) ?></td>
            <td class="num"><?= number_format($b['total_non_voters']) ?></td>
        </tr>
    <?php endforeach; ?>
        <tr class="total-row">
            <td colspan="2">TOTAL</td>
            <td class="num"><?= number_format($totals['total_senior']) ?></td>
            <td class="num"><?= number_format($totals['total_pwd']) ?></td>
            <td class="num"><?= number_format($totals['total_solo']) ?></td>
            <td class="num"><?= number_format($totals['total_4ps']) ?></td>
            <td class="num"><?= number_format($totals['total_registered_voters']) ?></td>
            <td class="num"><?= number_format($totals['total_non_voters']) ?></td>
        </tr>
    </tbody>
</table>

<!-- ④ EMPLOYMENT TABLE -->
<div class="section-title">💼 Employment Status per Barangay</div>
<table>
    <thead>
        <tr>
            <th class="left">#</th>
            <th class="left">Barangay</th>
            <th>Employed</th>
            <th>Unemployed</th>
            <th>Self-Employed</th>
            <th>Student</th>
            <th>Retired</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($barangays as $i => $b): ?>
        <tr>
            <td class="center"><?= $i + 1 ?></td>
            <td><?= htmlspecialchars($b['barangay_name']) ?></td>
            <td class="num"><?= number_format($b['emp_employed']) ?></td>
            <td class="num"><?= number_format($b['emp_unemployed']) ?></td>
            <td class="num"><?= number_format($b['emp_self']) ?></td>
            <td class="num"><?= number_format($b['emp_student']) ?></td>
            <td class="num"><?= number_format($b['emp_retired']) ?></td>
        </tr>
    <?php endforeach; ?>
        <tr class="total-row">
            <td colspan="2">TOTAL</td>
            <td class="num"><?= number_format($totals['emp_employed']) ?></td>
            <td class="num"><?= number_format($totals['emp_unemployed']) ?></td>
            <td class="num"><?= number_format($totals['emp_self']) ?></td>
            <td class="num"><?= number_format($totals['emp_student']) ?></td>
            <td class="num"><?= number_format($totals['emp_retired']) ?></td>
        </tr>
    </tbody>
</table>

<p style="margin-top:16px;font-size:9.5px;color:#3a7fa8;text-align:center;">
    — End of Report — &nbsp;|&nbsp; <?= APP_NAME ?> &nbsp;|&nbsp; <?= date('F d, Y') ?>
</p>

</body>
</html>
