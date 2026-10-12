<?php
require_once __DIR__ . '/../config/config.php';
requireRole('super_admin');

$db     = Database::getInstance()->getConnection();
$u      = currentUser();
$format = sanitize($_GET['format'] ?? 'excel');
$bid    = (int)($_GET['barangay_id'] ?? 0);

$where  = $bid ? "WHERE r.barangay_id=$bid" : "WHERE 1=1";
$whereB = $bid ? "WHERE barangay_id=$bid"   : "WHERE 1=1";
$andB   = $bid ? " AND barangay_id=$bid"    : "";
$andR   = $bid ? " AND r.barangay_id=$bid"  : "";

// ── Gather all stats ───────────────────────────────────────
$brgyLabel = $bid
    ? ($db->query("SELECT barangay_name FROM barangays WHERE id=$bid")->fetchColumn() ?: 'Barangay')
    : 'All Barangays';

$stats = [];
$stats['Total Residents (Active)']  = $db->query("SELECT COUNT(*) FROM residents r $where AND r.resident_status='Active'")->fetchColumn();
$stats['Total Households']          = $db->query("SELECT COUNT(*) FROM households $whereB")->fetchColumn();
$stats['Total Blotter Cases']       = $db->query("SELECT COUNT(*) FROM blotter_cases $whereB")->fetchColumn();
$stats['Total Certificates Issued'] = $db->query("SELECT COUNT(*) FROM certificates" . ($bid ? " WHERE barangay_id=$bid" : ""))->fetchColumn();

// Resident status
$stats['Resident - Active']         = $stats['Total Residents (Active)'];
$stats['Resident - Inactive']       = $db->query("SELECT COUNT(*) FROM residents r $where AND r.resident_status='Inactive'")->fetchColumn();
$stats['Resident - Transferred']    = $db->query("SELECT COUNT(*) FROM residents r $where AND r.resident_status='Transferred'")->fetchColumn();
$stats['Resident - Deceased']       = $db->query("SELECT COUNT(*) FROM residents r $where AND r.resident_status='Deceased'")->fetchColumn();

// Demographics
$stats['Male']                      = $db->query("SELECT COUNT(*) FROM residents r $where AND r.gender='Male' AND r.resident_status='Active'")->fetchColumn();
$stats['Female']                    = $db->query("SELECT COUNT(*) FROM residents r $where AND r.gender='Female' AND r.resident_status='Active'")->fetchColumn();
$stats['Registered Voters']         = $db->query("SELECT COUNT(*) FROM residents r $where AND r.voter_status='Registered' AND r.resident_status='Active'")->fetchColumn();
$stats['Non-Voters']                = (int)$stats['Total Residents (Active)'] - (int)$db->query("SELECT COUNT(*) FROM residents r $where AND r.voter_status='Registered' AND r.resident_status='Active'")->fetchColumn();

// Sectors
$stats['PWD']                       = $db->query("SELECT COUNT(*) FROM residents r $where AND r.is_pwd=1 AND r.resident_status='Active'")->fetchColumn();
$stats['Senior Citizens']           = $db->query("SELECT COUNT(*) FROM residents r $where AND r.is_senior=1 AND r.resident_status='Active'")->fetchColumn();
$stats['Solo Parents']              = $db->query("SELECT COUNT(*) FROM residents r $where AND r.is_solo_parent=1 AND r.resident_status='Active'")->fetchColumn();
$stats['4Ps Beneficiaries']         = $db->query("SELECT COUNT(*) FROM residents r $where AND r.is_4ps=1 AND r.resident_status='Active'")->fetchColumn();

// Employment
foreach (['Employed','Unemployed','Self-Employed','Student','Retired'] as $es) {
    $stats[$es] = $db->query("SELECT COUNT(*) FROM residents r $where AND r.occupation_status='$es' AND r.resident_status='Active'")->fetchColumn();
}

// Blotter breakdown
foreach (['Open','Settled','Escalated','Closed'] as $bs) {
    $stats["Blotter - $bs"] = $db->query("SELECT COUNT(*) FROM blotter_cases WHERE status='$bs'$andB")->fetchColumn();
}

// Certificates top type
$topCert = $db->query("SELECT certificate_type FROM certificates" . ($bid ? " WHERE barangay_id=$bid" : "") . " GROUP BY certificate_type ORDER BY COUNT(*) DESC LIMIT 1")->fetchColumn();
$stats['Top Certificate Type'] = $topCert ?: '—';

// Transfers
$stats['Transferred OUT (Total)']       = $db->query("SELECT COUNT(*) FROM residents r $where AND r.resident_status='Transferred'")->fetchColumn();
$stats['Transferred OUT (Outside LGU)'] = $db->query("SELECT COUNT(*) FROM residents r $where AND r.resident_status='Transferred' AND r.transfer_outside=1")->fetchColumn();
$stats['Transferred OUT (Local)']       = (int)$stats['Transferred OUT (Total)'] - (int)$stats['Transferred OUT (Outside LGU)'];
$stats['Transferred IN (Total)']        = $db->query("SELECT COUNT(*) FROM residents r $where AND r.resident_status='Active' AND r.transfer_from_barangay IS NOT NULL AND r.transfer_from_barangay != ''")->fetchColumn();
$stats['Net Population Movement']       = (int)$stats['Transferred IN (Total)'] - (int)$stats['Transferred OUT (Total)'];

// ── Per-barangay breakdown (for all-barangay view) ─────────
$brgyBreakdown = [];
if (!$bid) {
    $brgyBreakdown = $db->query("
        SELECT b.barangay_name,
            (SELECT COUNT(*) FROM residents r WHERE r.barangay_id=b.id AND r.resident_status='Active') as residents,
            (SELECT COUNT(*) FROM households h WHERE h.barangay_id=b.id) as households,
            (SELECT COUNT(*) FROM blotter_cases bc WHERE bc.barangay_id=b.id) as blotter,
            (SELECT COUNT(*) FROM certificates c WHERE c.barangay_id=b.id) as certificates,
            (SELECT COUNT(*) FROM residents r WHERE r.barangay_id=b.id AND r.gender='Male' AND r.resident_status='Active') as male,
            (SELECT COUNT(*) FROM residents r WHERE r.barangay_id=b.id AND r.gender='Female' AND r.resident_status='Active') as female,
            (SELECT COUNT(*) FROM residents r WHERE r.barangay_id=b.id AND r.is_pwd=1 AND r.resident_status='Active') as pwd,
            (SELECT COUNT(*) FROM residents r WHERE r.barangay_id=b.id AND r.is_senior=1 AND r.resident_status='Active') as senior,
            (SELECT COUNT(*) FROM residents r WHERE r.barangay_id=b.id AND r.is_4ps=1 AND r.resident_status='Active') as fourps,
            (SELECT COUNT(*) FROM residents r WHERE r.barangay_id=b.id AND r.is_solo_parent=1 AND r.resident_status='Active') as solo,
            (SELECT COUNT(*) FROM residents r WHERE r.barangay_id=b.id AND r.resident_status='Transferred') as transferred_out,
            (SELECT COUNT(*) FROM residents r WHERE r.barangay_id=b.id AND r.resident_status='Active' AND r.transfer_from_barangay IS NOT NULL AND r.transfer_from_barangay != '') as transferred_in
        FROM barangays b WHERE b.status='active'
        ORDER BY residents DESC
    ")->fetchAll();
}

$filename = 'dashboard_records_' . ($bid ? strtolower(str_replace(' ','_',$brgyLabel)) : 'all') . '_' . date('Ymd');
$generated = date('M d, Y h:i A');

// ── Sections map ───────────────────────────────────────────
$sections = [
    'OVERVIEW' => [
        'Total Residents (Active)', 'Total Households',
        'Total Blotter Cases', 'Total Certificates Issued',
    ],
    'RESIDENT STATUS' => [
        'Resident - Active','Resident - Inactive',
        'Resident - Transferred','Resident - Deceased',
    ],
    'DEMOGRAPHICS' => [
        'Male','Female','Registered Voters','Non-Voters',
    ],
    'SECTORS' => [
        'PWD','Senior Citizens','Solo Parents','4Ps Beneficiaries',
    ],
    'EMPLOYMENT' => [
        'Employed','Unemployed','Self-Employed','Student','Retired',
    ],
    'CERTIFICATES' => [
        'Total Certificates Issued','Top Certificate Type',
    ],
    'BLOTTER / PUBLIC SAFETY' => [
        'Total Blotter Cases',
        'Blotter - Open','Blotter - Settled',
        'Blotter - Escalated','Blotter - Closed',
    ],
    'POPULATION TRANSFERS' => [
        'Transferred IN (Total)',
        'Transferred OUT (Total)',
        'Transferred OUT (Outside LGU)',
        'Transferred OUT (Local)',
        'Net Population Movement',
    ],
];

// ══════════════════════════════════════════════════════════
// EXCEL OUTPUT
// ══════════════════════════════════════════════════════════
if ($format === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
    header('Cache-Control: max-age=0');

    // ── Section colors ────────────────────────────────────
    $sectionMeta = [
        'OVERVIEW'               => ['bg'=>'#1a4f7a','fg'=>'#ffffff','rowbg'=>'#e8f4fb'],
        'RESIDENT STATUS'        => ['bg'=>'#0369a1','fg'=>'#ffffff','rowbg'=>'#e0f2fe'],
        'DEMOGRAPHICS'           => ['bg'=>'#15803d','fg'=>'#ffffff','rowbg'=>'#dcfce7'],
        'SECTORS'                => ['bg'=>'#7c3aed','fg'=>'#ffffff','rowbg'=>'#ede9fe'],
        'EMPLOYMENT'             => ['bg'=>'#b45309','fg'=>'#ffffff','rowbg'=>'#fef3c7'],
        'CERTIFICATES'           => ['bg'=>'#0f766e','fg'=>'#ffffff','rowbg'=>'#ccfbf1'],
        'BLOTTER / PUBLIC SAFETY'=> ['bg'=>'#b91c1c','fg'=>'#ffffff','rowbg'=>'#fee2e2'],
        'POPULATION TRANSFERS'   => ['bg'=>'#065f46','fg'=>'#ffffff','rowbg'=>'#d1fae5'],
    ];

    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
    *{font-family:Arial,sans-serif;}
    body{font-size:11px;margin:0;padding:0;}
    table{border-collapse:collapse;width:100%;}
    </style></head><body>';

    // ── Title block ───────────────────────────────────────
    echo '<table style="margin-bottom:0;width:100%;">';
    echo '<tr><td colspan="2" style="background:#021024;color:#ffffff;padding:14px 18px;font-size:15px;font-weight:800;letter-spacing:.02em;border:none;">
        &#128200; LGU INFORMATION MANAGEMENT SYSTEM
    </td></tr>';
    echo '<tr><td colspan="2" style="background:#1a4f7a;color:#c1e8ff;padding:7px 18px;font-size:11px;border:none;">
        <b>Dashboard — All Records Export</b>
        &nbsp;&nbsp;|&nbsp;&nbsp; Scope: <b>' . htmlspecialchars($brgyLabel) . '</b>
        &nbsp;&nbsp;|&nbsp;&nbsp; Generated: ' . $generated . '
        &nbsp;&nbsp;|&nbsp;&nbsp; By: ' . htmlspecialchars($u['name'] ?? '') . '
    </td></tr>';
    echo '<tr><td colspan="2" style="padding:8px 0;border:none;background:#fff;"></td></tr>';
    echo '</table>';

    // ── Summary Statistics ────────────────────────────────
    echo '<table style="width:420px;margin-bottom:20px;">';
    echo '<tr>
        <th style="background:#021024;color:#fff;padding:8px 14px;font-size:12px;font-weight:800;border:1px solid #334;text-align:left;min-width:240px;">METRIC</th>
        <th style="background:#021024;color:#fff;padding:8px 14px;font-size:12px;font-weight:800;border:1px solid #334;text-align:center;min-width:140px;">VALUE</th>
    </tr>';

    foreach ($sections as $secTitle => $keys) {
        $meta = $sectionMeta[$secTitle] ?? ['bg'=>'#334155','fg'=>'#fff','rowbg'=>'#f8fafc'];
        // Section header row
        echo '<tr>
            <td colspan="2" style="background:' . $meta['bg'] . ';color:' . $meta['fg'] . ';font-weight:700;font-size:10px;padding:6px 14px;border:1px solid rgba(0,0,0,.15);text-transform:uppercase;letter-spacing:.07em;">'
            . htmlspecialchars($secTitle) .
        '</td></tr>';

        $odd = true;
        foreach ($keys as $key) {
            if (!isset($stats[$key])) continue;
            $v = $stats[$key];
            $display = is_numeric($v) ? number_format((int)$v) : htmlspecialchars((string)$v);
            $rowbg = $odd ? '#ffffff' : $meta['rowbg'];
            echo '<tr>
                <td style="background:' . $rowbg . ';padding:5px 14px 5px 22px;border:1px solid #dde6ee;font-size:11px;color:#1a2e3f;">'
                . htmlspecialchars($key) . '</td>
                <td style="background:' . $rowbg . ';padding:5px 14px;border:1px solid #dde6ee;font-size:12px;font-weight:700;text-align:center;color:#1a2e3f;">'
                . $display . '</td>
            </tr>';
            $odd = !$odd;
        }
        // Spacer
        echo '<tr><td colspan="2" style="background:#fff;border:none;padding:3px 0;"></td></tr>';
    }
    echo '</table>';

    // ── Per-barangay breakdown ────────────────────────────
    if (!empty($brgyBreakdown)) {
        $bCols = [
            'barangay_name'  => 'BARANGAY',
            'residents'      => 'RESIDENTS',
            'households'     => 'HOUSEHOLDS',
            'blotter'        => 'BLOTTER',
            'certificates'   => 'CERTIFICATES',
            'male'           => 'MALE',
            'female'         => 'FEMALE',
            'pwd'            => 'PWD',
            'senior'         => 'SENIOR',
            'fourps'         => '4Ps',
            'solo'           => 'SOLO PARENTS',
            'transferred_out'=> 'TRANSFERRED OUT',
            'transferred_in' => 'TRANSFERRED IN',
        ];

        // Section title
        echo '<table style="width:100%;margin-bottom:6px;">';
        echo '<tr><td colspan="' . count($bCols) . '" style="background:#021024;color:#c1e8ff;padding:8px 14px;font-size:12px;font-weight:800;border:1px solid #334;letter-spacing:.03em;">
            &#128205; PER-BARANGAY BREAKDOWN
        </td></tr></table>';

        echo '<table style="width:100%;margin-bottom:20px;">';
        // Column headers
        echo '<tr>';
        foreach ($bCols as $key => $label) {
            $align = $key === 'barangay_name' ? 'left' : 'center';
            echo '<th style="background:#1a4f7a;color:#fff;padding:7px 10px;font-size:9px;font-weight:700;border:1px solid #2d6fa3;text-align:' . $align . ';white-space:nowrap;letter-spacing:.05em;">' . $label . '</th>';
        }
        echo '</tr>';

        // Color coding per column group
        $colColors = [
            'barangay_name'=>'#f8fafc','residents'=>'#e8f4fb','households'=>'#e8f4fb',
            'blotter'=>'#fee2e2','certificates'=>'#e0f2fe',
            'male'=>'#dcfce7','female'=>'#fce7f3',
            'pwd'=>'#ede9fe','senior'=>'#fef3c7','fourps'=>'#d1fae5','solo'=>'#fce7f3',
            'transferred_out'=>'#fee2e2','transferred_in'=>'#dcfce7',
        ];

        $odd = true;
        foreach ($brgyBreakdown as $b) {
            $rowBase = $odd ? '' : 'filter:brightness(.97);';
            echo '<tr>';
            foreach ($bCols as $key => $label) {
                $bg    = $colColors[$key] ?? '#fff';
                $fw    = $key === 'barangay_name' ? '700' : '600';
                $align = $key === 'barangay_name' ? 'left' : 'center';
                $v     = $key === 'barangay_name' ? htmlspecialchars($b[$key]) : number_format((int)$b[$key]);
                echo '<td style="background:' . $bg . ';padding:5px 10px;border:1px solid #dde6ee;font-size:10px;font-weight:' . $fw . ';text-align:' . $align . ';color:#1a2e3f;white-space:nowrap;">' . $v . '</td>';
            }
            echo '</tr>';
            $odd = !$odd;
        }

        // Totals row
        $numKeys = ['residents','households','blotter','certificates','male','female','pwd','senior','fourps','solo','transferred_out','transferred_in'];
        echo '<tr>';
        echo '<td style="background:#021024;color:#fff;padding:7px 10px;border:1px solid #334;font-size:10px;font-weight:800;">TOTAL</td>';
        foreach ($numKeys as $k) {
            $total = array_sum(array_column($brgyBreakdown, $k));
            echo '<td style="background:#021024;color:#c1e8ff;padding:7px 10px;border:1px solid #334;font-size:11px;font-weight:800;text-align:center;">' . number_format($total) . '</td>';
        }
        echo '</tr>';
        echo '</table>';
    }

    echo '</body></html>';
    exit;
}

// ══════════════════════════════════════════════════════════
// PDF OUTPUT
// ══════════════════════════════════════════════════════════
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Dashboard Records — <?= htmlspecialchars($brgyLabel) ?></title>
<style>
*{box-sizing:border-box;}
body{font-family:Arial,sans-serif;font-size:11px;margin:20px;color:#222;}
h1{color:#1a4f7a;font-size:15px;margin-bottom:2px;}
h2{color:#1a4f7a;font-size:12px;border-bottom:2px solid #1a4f7a;padding-bottom:4px;margin-top:16px;}
.meta{font-size:10px;color:#555;margin-bottom:12px;}
table{border-collapse:collapse;width:100%;margin-bottom:14px;}
th{background:#1a4f7a;color:#fff;padding:5px 10px;font-size:10px;border:1px solid #ccc;}
td{padding:4px 10px;border:1px solid #ddd;font-size:10px;}
tr:nth-child(even) td{background:#f5f8fc;}
.sec-row td{background:#e8f0fe;color:#1a4f7a;font-weight:bold;font-size:10px;}
.val{font-weight:700;text-align:center;}
.tot{background:#1a4f7a !important;color:#fff;font-weight:bold;}
.print-btn{padding:7px 18px;background:#1a4f7a;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:12px;margin-bottom:14px;}
@media print{.print-btn{display:none;}body{margin:8px;}}
</style>
</head>
<body>
<button class="print-btn" onclick="window.print()">🖨️ Print / Save as PDF</button>
<h1>LGU Dashboard — All Records</h1>
<div class="meta">Scope: <b><?= htmlspecialchars($brgyLabel) ?></b> &nbsp;|&nbsp; Generated: <?= $generated ?> &nbsp;|&nbsp; By: <?= htmlspecialchars($u['name'] ?? '') ?></div>

<h2>Summary Statistics</h2>
<table>
  <tr><th style="min-width:200px;">Metric</th><th style="min-width:120px;text-align:center;">Count / Value</th></tr>
  <?php foreach ($sections as $secTitle => $keys): ?>
  <tr class="sec-row"><td colspan="2"><?= htmlspecialchars($secTitle) ?></td></tr>
  <?php foreach ($keys as $key): if (!isset($stats[$key])) continue;
      $v = $stats[$key];
      $display = is_numeric($v) ? number_format((int)$v) : htmlspecialchars((string)$v); ?>
  <tr><td><?= htmlspecialchars($key) ?></td><td class="val"><?= $display ?></td></tr>
  <?php endforeach; endforeach; ?>
</table>

<?php if (!empty($brgyBreakdown)): ?>
<h2>Per-Barangay Breakdown</h2>
<table>
  <tr>
    <th>Barangay</th><th>Residents</th><th>Households</th><th>Blotter</th>
    <th>Certs</th><th>Male</th><th>Female</th><th>PWD</th><th>Senior</th>
    <th>4Ps</th><th>Solo</th><th>Out</th><th>In</th>
  </tr>
  <?php foreach ($brgyBreakdown as $b): ?>
  <tr>
    <td style="font-weight:600;"><?= htmlspecialchars($b['barangay_name']) ?></td>
    <?php foreach (['residents','households','blotter','certificates','male','female','pwd','senior','fourps','solo','transferred_out','transferred_in'] as $k): ?>
    <td class="val"><?= number_format((int)$b[$k]) ?></td>
    <?php endforeach; ?>
  </tr>
  <?php endforeach; ?>
  <tr>
    <td class="tot">TOTAL</td>
    <?php foreach (['residents','households','blotter','certificates','male','female','pwd','senior','fourps','solo','transferred_out','transferred_in'] as $k): ?>
    <td class="tot val"><?= number_format(array_sum(array_column($brgyBreakdown,$k))) ?></td>
    <?php endforeach; ?>
  </tr>
</table>
<?php endif; ?>

</body>
</html>
