<?php
require_once __DIR__ . '/../config/config.php';
requireRole('super_admin');

$db     = Database::getInstance()->getConnection();
$u      = currentUser();
$format = sanitize($_GET['format'] ?? 'pdf');

// Support single report_id or multiple report_ids
$reportIds = [];
if (!empty($_GET['report_ids'])) {
    $reportIds = array_map('intval', explode(',', $_GET['report_ids']));
} elseif (!empty($_GET['report_id'])) {
    $reportIds = [(int)$_GET['report_id']];
}

if (empty($reportIds)) { http_response_code(404); exit('No reports specified.'); }

$placeholders = implode(',', array_fill(0, count($reportIds), '?'));
$stmt = $db->prepare("
    SELECT r.*, b.barangay_name, CONCAT(u.first_name,' ',u.last_name) as submitted_by_name
    FROM reports r
    LEFT JOIN barangays b ON r.barangay_id = b.id
    LEFT JOIN users u ON r.submitted_by = u.id
    WHERE r.id IN ($placeholders)
    ORDER BY b.barangay_name, r.created_at DESC
");
$stmt->execute($reportIds);
$reports = $stmt->fetchAll();

if (empty($reports)) { http_response_code(404); exit('Reports not found.'); }

$months = ['','January','February','March','April','May','June','July','August','September','October','November','December'];

function parseNotes(string $notes): array {
    $clean = preg_replace('/^\[[^\]]+\]\s*/', '', $notes);
    return array_filter(explode(' | ', $clean));
}

/**
 * Parse notes into a key→value map.
 * Supports both "Key: Value" (new format) and legacy "Key:Value" (no space).
 */
function parseNotesMap(string $notes): array {
    $parts = parseNotes($notes);
    $map = [];
    foreach ($parts as $p) {
        $idx = strpos($p, ': ');
        if ($idx !== false) {
            $map[trim(substr($p, 0, $idx))] = trim(substr($p, $idx + 2));
        } else {
            // Legacy format: no space after colon
            $idx2 = strpos($p, ':');
            if ($idx2 !== false) {
                $map[trim(substr($p, 0, $idx2))] = trim(substr($p, $idx2 + 1));
            }
        }
    }
    return $map;
}

function getPeriodLabel(array $r, array $months): string {
    preg_match('/^\[([^\]]+)\]/', $r['notes'] ?? '', $m);
    return $m[1] ?? (($months[$r['month']] ?? '') . ' ' . $r['year']);
}

/**
 * Fetch live transfer counts for a barangay from the DB.
 * Returns [transferred_in, transferred_out, transferred_outside]
 */
function getLiveTransferCounts(PDO $db, int $bid): array {
    $out     = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND resident_status='Transferred'")->fetchColumn();
    $in      = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND resident_status='Active' AND transfer_from_barangay IS NOT NULL AND transfer_from_barangay != ''")->fetchColumn();
    $outside = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND resident_status='Transferred' AND transfer_outside=1")->fetchColumn();
    return ['in' => $in, 'out' => $out, 'outside' => $outside, 'net' => $in - $out];
}

// Pre-fetch live transfer counts per unique barangay_id in the result set
$transferCache = [];
foreach ($reports as $rep) {
    $bid = (int)$rep['barangay_id'];
    if (!isset($transferCache[$bid])) {
        $transferCache[$bid] = getLiveTransferCounts($db, $bid);
    }
}

$filename = count($reports) === 1
    ? 'report_' . strtolower(str_replace(' ', '_', $reports[0]['barangay_name'])) . '_' . date('Ymd', strtotime($reports[0]['created_at']))
    : 'reports_bulk_' . date('Ymd');

if ($format === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
    header('Cache-Control: max-age=0');

    // Build all metrics in order
    $allMetrics = [
        // Section header rows use key starting with '__'
        '__OVERVIEW & RESIDENTS'        => null,
        'Total Residents'               => 'Total Residents',
        'New Residents (Period)'        => 'New Residents (period)',
        'Households'                    => 'Households',
        '__DEMOGRAPHICS'                => null,
        'Male'                          => 'Male',
        'Female'                        => 'Female',
        'Registered Voters'             => 'Registered Voters',
        'Non-Voters'                    => 'Non-Voters',
        '__SECTORS'                     => null,
        'PWD'                           => 'PWD',
        'Senior Citizens'               => 'Senior Citizens',
        '4Ps Beneficiaries'             => '4Ps',
        'Solo Parents'                  => 'Solo Parents',
        '__EMPLOYMENT'                  => null,
        'Employed'                      => 'Employed',
        'Unemployed'                    => 'Unemployed',
        'Self-Employed'                 => 'Self-Employed',
        'Student'                       => 'Student',
        'Retired'                       => 'Retired',
        '__CERTIFICATES'                => null,
        'Total Certificates'            => 'Total Certificates',
        'Certificates (Period)'         => 'Certificates (period)',
        'Top Certificate'               => 'Top Certificate',
        '__BLOTTER / PUBLIC SAFETY'     => null,
        'Total Blotter (All-Time)'      => 'Total Blotter',
        'Blotter (Period)'              => 'Blotter (period)',
        'Open'                          => 'Blotter Open',
        'Settled'                       => 'Blotter Settled',
        'Escalated'                     => 'Blotter Escalated',
        'Closed'                        => 'Blotter Closed',
        '__POPULATION TRANSFERS'        => null,
        'Transferred IN (All-Time)'     => '__tin_total',
        'Transferred IN (Period)'       => '__tin_period',
        'Transferred OUT (All-Time)'    => '__tout_total',
        'Transferred OUT (Period)'      => '__tout_period',
        'Outside LGU / Municipality'    => '__toutside',
        'Net Movement (In − Out)'       => '__tnet',
    ];

    // Pre-build per-report data maps
    $repMaps = [];
    foreach ($reports as $rep) {
        $map = parseNotesMap($rep['notes'] ?? '');
        $tc  = $transferCache[(int)$rep['barangay_id']];
        $tInTotal  = $map['Transferred In (Total)']   ?? $tc['in'];
        $tOutTotal = $map['Transferred Out (Total)']  ?? $tc['out'];
        $tInPeriod = $map['Transferred In (period)']  ?? $tInTotal;
        $tOutPeriod= $map['Transferred Out (period)'] ?? $tOutTotal;
        $tOutside  = $map['Transferred Outside LGU']  ?? $tc['outside'];
        $tNet      = is_numeric($tInTotal) && is_numeric($tOutTotal)
            ? ((int)$tInTotal - (int)$tOutTotal) : $tc['net'];
        $map['__tin_total']  = (string)$tInTotal;
        $map['__tin_period'] = (string)$tInPeriod;
        $map['__tout_total'] = (string)$tOutTotal;
        $map['__tout_period']= (string)$tOutPeriod;
        $map['__toutside']   = (string)$tOutside;
        $map['__tnet']       = ($tNet >= 0 ? '+' : '') . $tNet;
        $repMaps[] = $map;
    }

    $colCount = count($reports);
    $thStyle  = 'background:#1a4f7a;color:#fff;padding:6px 10px;border:1px solid #ccc;min-width:140px;font-size:11px;';
    $tdStyle  = 'padding:5px 10px;border:1px solid #ddd;font-size:11px;min-width:140px;';
    $secStyle = 'background:#1a4f7a;color:#fff;font-weight:bold;font-size:11px;padding:5px 10px;border:1px solid #ccc;';
    $lblStyle = 'background:#f4f7fa;color:#1a4f7a;font-weight:600;font-size:11px;padding:5px 10px;border:1px solid #ddd;min-width:200px;';
    $valStyle = 'padding:5px 10px;border:1px solid #ddd;font-size:11px;font-weight:700;text-align:center;min-width:140px;';

    echo '<!DOCTYPE html><html><head><meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; font-size: 11px; }
        table { border-collapse: collapse; width: 100%; }
        tr:nth-child(even) td { background: #f9fbfd; }
    </style></head><body>';

    echo '<h2 style="color:#1a4f7a;font-size:14px;margin-bottom:4px;">LGU Data Reports Export</h2>';
    echo '<p style="font-size:11px;color:#555;">Generated: ' . date('M d, Y h:i A') . ' &nbsp;|&nbsp; Total reports: ' . $colCount . ' &nbsp;|&nbsp; Exported by: ' . htmlspecialchars($u['name'] ?? '') . '</p>';
    echo '<table>';

    // Header row: Metric label | Brgy 1 | Brgy 2 | ...
    echo '<tr>';
    echo '<th style="' . $thStyle . 'min-width:200px;">Metric</th>';
    foreach ($reports as $i => $rep) {
        $period = getPeriodLabel($rep, $months);
        echo '<th style="' . $thStyle . '">'
            . htmlspecialchars($rep['barangay_name']) . '<br>'
            . '<span style="font-weight:400;font-size:10px;">' . htmlspecialchars($period) . '</span><br>'
            . '<span style="font-weight:400;font-size:10px;opacity:.85;">' . ucfirst($rep['status']) . ' · ' . date('M d, Y', strtotime($rep['created_at'])) . '</span>'
            . '</th>';
    }
    echo '</tr>';

    // Data rows
    foreach ($allMetrics as $label => $noteKey) {
        if ($noteKey === null) {
            // Section header
            $secLabel = ltrim($label, '_');
            echo '<tr>';
            echo '<td style="' . $secStyle . '" colspan="' . ($colCount + 1) . '">' . htmlspecialchars($secLabel) . '</td>';
            echo '</tr>';
        } else {
            echo '<tr>';
            echo '<td style="' . $lblStyle . '">' . htmlspecialchars($label) . '</td>';
            foreach ($repMaps as $map) {
                $val = $map[$noteKey] ?? '—';
                echo '<td style="' . $valStyle . '">' . htmlspecialchars((string)$val) . '</td>';
            }
            echo '</tr>';
        }
    }

    echo '</table>';
    echo '</body></html>';
    exit;
}

// PDF view
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>LGU Reports Export</title>
<style>
    * { box-sizing: border-box; }
    body { font-family: Arial, sans-serif; font-size: 11px; margin: 24px; color: #222; }
    .report-block { border: 1px solid #ddd; border-radius: 8px; padding: 16px; margin-bottom: 24px; page-break-inside: avoid; }
    .report-header { border-bottom: 2px solid #1a4f7a; padding-bottom: 10px; margin-bottom: 12px; display: flex; justify-content: space-between; align-items: flex-start; }
    .report-header h2 { color: #1a4f7a; font-size: 14px; margin: 0 0 3px; }
    .report-header p { margin: 2px 0; color: #555; font-size: 10px; }
    .status-badge { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: 10px; font-weight: bold; }
    .status-submitted { background: #fff3cd; color: #856404; }
    .status-approved  { background: #d1e7dd; color: #0f5132; }
    .status-rejected  { background: #f8d7da; color: #842029; }
    .status-done      { background: #d8b4fe; color: #4c1d95; }
    .status-draft     { background: #e2e3e5; color: #41464b; }
    .meta-row { display: flex; gap: 16px; margin-bottom: 10px; flex-wrap: wrap; }
    .meta-item { flex: 1; min-width: 120px; }
    .meta-item .lbl { font-size: 9px; color: #888; font-weight: bold; text-transform: uppercase; }
    .meta-item .val { font-size: 11px; font-weight: bold; color: #1a4f7a; }
    .sections-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 10px; }
    .section-block { border: 1px solid #e0f0e4; border-radius: 8px; padding: 10px 12px; }
    .section-block .sec-title { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #3a7fa8; margin-bottom: 7px; }
    .kv-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 4px; }
    .kv-item { background: #f8fafc; border-radius: 6px; padding: 5px 8px; }
    .kv-item .k { font-size: 9px; color: #888; }
    .kv-item .v { font-size: 11px; font-weight: 700; color: #1a2e1f; }
    .transfer-block { grid-column: 1 / -1; border: 1px solid #bbf7d0; border-radius: 8px; padding: 10px 12px; background: #f0fdf4; }
    .transfer-block .sec-title { color: #15803d; }
    .transfer-stat { display: inline-block; text-align: center; padding: 6px 14px; border-radius: 8px; margin-right: 8px; margin-bottom: 6px; }
    .t-in    { background: #dcfce7; }
    .t-out   { background: #fee2e2; }
    .t-out2  { background: #fef3c7; }
    .t-net   { background: #eff6ff; }
    .transfer-stat .tv { font-size: 14px; font-weight: 800; }
    .transfer-stat .tl { font-size: 9px; color: #555; margin-top: 2px; }
    .page-header { border-bottom: 3px solid #1a4f7a; padding-bottom: 10px; margin-bottom: 20px; }
    .page-header h1 { color: #1a4f7a; font-size: 16px; margin: 0 0 4px; }
    .print-btn { margin-bottom: 16px; padding: 8px 20px; background: #1a4f7a; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-size: 13px; }
    @media print { .print-btn { display: none; } body { margin: 10px; } }
</style>
</head>
<body>
<button class="print-btn" onclick="window.print()">🖨️ Print / Save as PDF</button>

<div class="page-header">
    <h1>LGU Reports Export</h1>
    <p>Total: <?= count($reports) ?> report<?= count($reports) !== 1 ? 's' : '' ?> &nbsp;|&nbsp; Generated: <?= date('M d, Y h:i A') ?> &nbsp;|&nbsp; By: <?= htmlspecialchars($u['name'] ?? '') ?></p>
</div>

<?php
$pdfSectionGroups = [
    ['title' => 'Overview & Residents', 'color' => '#1a4f7a', 'rows' => [
        ['Total Residents', 'Total Residents'],
        ['New (Period)', 'New Residents (period)'],
        ['Households', 'Households'],
        ['Total Certificates', 'Total Certificates'],
        ['Certs (Period)', 'Certificates (period)'],
        ['Top Certificate', 'Top Certificate'],
    ]],
    ['title' => 'Demographics', 'color' => '#0369a1', 'rows' => [
        ['Male', 'Male'],
        ['Female', 'Female'],
        ['Reg. Voters', 'Registered Voters'],
        ['Non-Voters', 'Non-Voters'],
    ]],
    ['title' => 'Sectors', 'color' => '#c2410c', 'rows' => [
        ['PWD', 'PWD'],
        ['Senior Citizens', 'Senior Citizens'],
        ['4Ps Beneficiaries', '4Ps'],
        ['Solo Parents', 'Solo Parents'],
    ]],
    ['title' => 'Employment', 'color' => '#1d4ed8', 'rows' => [
        ['Employed', 'Employed'],
        ['Unemployed', 'Unemployed'],
        ['Self-Employed', 'Self-Employed'],
        ['Student', 'Student'],
        ['Retired', 'Retired'],
    ]],
    ['title' => 'Blotter / Public Safety', 'color' => '#b91c1c', 'rows' => [
        ['Total Blotter', 'Total Blotter'],
        ['Blotter (Period)', 'Blotter (period)'],
        ['Open', 'Blotter Open'],
        ['Settled', 'Blotter Settled'],
        ['Escalated', 'Blotter Escalated'],
        ['Closed', 'Blotter Closed'],
    ]],
];
?>

<?php foreach ($reports as $rep):
    $periodLabel = getPeriodLabel($rep, $months);
    $map         = parseNotesMap($rep['notes'] ?? '');
    $bid         = (int)$rep['barangay_id'];
    $tc          = $transferCache[$bid];

    // Transfer values: prefer notes values, fall back to live DB
    $tInTotal  = $map['Transferred In (Total)']    ?? $tc['in'];
    $tOutTotal = $map['Transferred Out (Total)']   ?? $tc['out'];
    $tInPeriod = $map['Transferred In (period)']   ?? $map['Transferred In (Total)']  ?? $tc['in'];
    $tOutPeriod= $map['Transferred Out (period)']  ?? $map['Transferred Out (Total)'] ?? $tc['out'];
    $tOutside  = $map['Transferred Outside LGU']   ?? $tc['outside'];
    $tNet      = (is_numeric($tInTotal) && is_numeric($tOutTotal))
        ? ((int)$tInTotal - (int)$tOutTotal)
        : $tc['net'];
    $netLabel  = ($tNet >= 0 ? '+' : '') . $tNet;
    $netColor  = $tNet >= 0 ? '#15803d' : '#dc2626';
?>
<div class="report-block">
    <div class="report-header">
        <div>
            <h2><?= htmlspecialchars($rep['barangay_name']) ?></h2>
            <p><strong>Period:</strong> <?= htmlspecialchars($periodLabel) ?> &nbsp;|&nbsp; <strong>Type:</strong> <?= ucfirst($rep['report_type']) ?></p>
        </div>
        <span class="status-badge status-<?= $rep['status'] ?>"><?= ucfirst($rep['status']) ?></span>
    </div>
    <div class="meta-row">
        <div class="meta-item"><div class="lbl">Submitted By</div><div class="val"><?= htmlspecialchars($rep['submitted_by_name'] ?? '—') ?></div></div>
        <div class="meta-item"><div class="lbl">Date Submitted</div><div class="val"><?= date('M d, Y h:i A', strtotime($rep['created_at'])) ?></div></div>
        <div class="meta-item"><div class="lbl">Status</div><div class="val"><?= ucfirst($rep['status']) ?></div></div>
    </div>

    <div class="sections-grid">
        <?php foreach ($pdfSectionGroups as $sg): ?>
        <div class="section-block">
            <div class="sec-title" style="color:<?= $sg['color'] ?>;"><?= htmlspecialchars($sg['title']) ?></div>
            <div class="kv-grid">
            <?php foreach ($sg['rows'] as [$label, $key]): ?>
                <div class="kv-item">
                    <div class="k"><?= htmlspecialchars($label) ?></div>
                    <div class="v"><?= htmlspecialchars($map[$key] ?? '—') ?></div>
                </div>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Transfers block — spans full width -->
        <div class="transfer-block">
            <div class="sec-title">🔄 Population Transfers (Movement)</div>
            <div>
                <div class="transfer-stat t-in">
                    <div class="tv" style="color:#16a34a;"><?= htmlspecialchars((string)$tInTotal) ?></div>
                    <div class="tl">Transferred IN<br>(All-Time)</div>
                </div>
                <div class="transfer-stat t-in">
                    <div class="tv" style="color:#16a34a;"><?= htmlspecialchars((string)$tInPeriod) ?></div>
                    <div class="tl">Transferred IN<br>(Period)</div>
                </div>
                <div class="transfer-stat t-out">
                    <div class="tv" style="color:#dc2626;"><?= htmlspecialchars((string)$tOutTotal) ?></div>
                    <div class="tl">Transferred OUT<br>(All-Time)</div>
                </div>
                <div class="transfer-stat t-out">
                    <div class="tv" style="color:#dc2626;"><?= htmlspecialchars((string)$tOutPeriod) ?></div>
                    <div class="tl">Transferred OUT<br>(Period)</div>
                </div>
                <div class="transfer-stat t-out2">
                    <div class="tv" style="color:#92400e;"><?= htmlspecialchars((string)$tOutside) ?></div>
                    <div class="tl">Outside LGU /<br>Municipality</div>
                </div>
                <div class="transfer-stat t-net">
                    <div class="tv" style="color:<?= $netColor ?>;"><?= htmlspecialchars($netLabel) ?></div>
                    <div class="tl">Net Movement<br>(In − Out)</div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

</body>
</html>
<?php exit; ?>
