<?php
require_once __DIR__ . '/../config/config.php';
requireRole('barangay_admin');

$db  = Database::getInstance()->getConnection();
$u   = currentUser();
$bid = (int)$u['barangay_id'];

// Guard: if barangay_id is 0 (null session), abort early with a clear message
if ($bid <= 0) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><title>Error</title></head><body>';
    echo '<div style="font-family:sans-serif;padding:40px;text-align:center;">';
    echo '<h2>⚠️ No barangay assigned to your account.</h2>';
    echo '<p>Please contact your system administrator.</p>';
    echo '</div></body></html>';
    exit;
}

$brgy = $db->prepare("SELECT * FROM barangays WHERE id=?");
$brgy->execute([$bid]);
$brgy = $brgy->fetch();

// ── Date range filter ──────────────────────────────────────
$today = new DateTimeImmutable('today');
$todayString = $today->format('Y-m-d');
$periodLabels = [
    'current_month' => 'Current Month',
    'last_3_months' => 'Last 3 Months',
    'last_6_months' => 'Last 6 Months',
    'last_12_months' => 'Last 12 Months',
    'custom' => 'Custom Range',
];
$requestedPeriod = $_GET['period'] ?? '';
$filterError = '';
$hasCustomDates = isset($_GET['from']) || isset($_GET['to']);
if (!is_string($requestedPeriod)) {
    $requestedPeriod = '';
    $filterError = 'Hindi valid ang napiling date range.';
} elseif ($requestedPeriod !== '' && !isset($periodLabels[$requestedPeriod])) {
    if ($hasCustomDates) {
        $requestedPeriod = 'custom';
    } else {
        $filterError = 'Hindi kilala ang napiling date range. Ipinapakita ang kasalukuyang buwan.';
        $requestedPeriod = '';
    }
}

if (isset($periodLabels[$requestedPeriod]) && $requestedPeriod !== 'custom') {
    $period = $requestedPeriod;
    $monthsBack = [
        'current_month' => 0,
        'last_3_months' => 2,
        'last_6_months' => 5,
        'last_12_months' => 11,
    ][$period];
    $from = $today->modify('first day of this month')->modify("-$monthsBack months")->format('Y-m-d');
    $to = $todayString;
} elseif ($requestedPeriod === 'custom' || $hasCustomDates) {
    $period = 'custom';
    $parseDate = static function ($value): ?DateTimeImmutable {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $date : null;
    };
    $customFrom = $parseDate($_GET['from'] ?? null);
    $customTo = $parseDate($_GET['to'] ?? null);
    if (!$customFrom || !$customTo || $customFrom > $customTo || $customTo > $today) {
        $filterError = 'Pumili ng valid na custom date range na hindi lalampas sa kasalukuyang petsa.';
        $period = 'current_month';
        $from = $today->modify('first day of this month')->format('Y-m-d');
        $to = $todayString;
    } else {
        $from = $customFrom->format('Y-m-d');
        $to = $customTo->format('Y-m-d');
    }
} else {
    $period = 'current_month';
    $from = $today->modify('first day of this month')->format('Y-m-d');
    $to = $todayString;
}

$brgyName = htmlspecialchars($brgy['barangay_name'] ?? 'Barangay');
$selectedPeriodLabel = date('F j', strtotime($from)) . (date('Y-m', strtotime($from)) === date('Y-m', strtotime($to))
    ? '–' . date('j, Y', strtotime($to))
    : ', ' . date('Y', strtotime($from)) . ' – ' . date('F j, Y', strtotime($to)));
$periodCount = static function (string $table, string $dateColumn) use ($db, $bid, $from, $to): int {
    $stmt = $db->prepare("SELECT COUNT(*) FROM $table WHERE barangay_id=? AND DATE($dateColumn) BETWEEN ? AND ?");
    $stmt->execute([$bid, $from, $to]);
    return (int)$stmt->fetchColumn();
};

// ── KPI Cards ─────────────────────────────────────────────
$kpi = [];
$kpi['total_residents']  = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND resident_status='Active'")->fetchColumn();
$kpi['new_residents']    = $periodCount('residents', 'created_at');
$kpi['total_households'] = (int)$db->query("SELECT COUNT(*) FROM households WHERE barangay_id=$bid AND status='Active'")->fetchColumn();
$kpi['certs_issued']     = $periodCount('certificates', 'date_issued');
$kpi['blotter_cases']    = $periodCount('blotter_cases', 'created_at');
$kpi['officials']        = (int)$db->query("SELECT COUNT(*) FROM officials WHERE barangay_id=$bid AND status='active'")->fetchColumn();

// ── Monthly trends within the selected period ──────────────
$trendStmt = $db->prepare("SELECT DATE_FORMAT(created_at,'%Y-%m') AS period, COUNT(*) AS total
    FROM residents WHERE barangay_id=? AND DATE(created_at) BETWEEN ? AND ? GROUP BY period");
$trendStmt->execute([$bid, $from, $to]);
$trendResidents = $trendStmt->fetchAll(PDO::FETCH_KEY_PAIR);
$trendStmt = $db->prepare("SELECT DATE_FORMAT(date_issued,'%Y-%m') AS period, COUNT(*) AS total
    FROM certificates WHERE barangay_id=? AND DATE(date_issued) BETWEEN ? AND ? GROUP BY period");
$trendStmt->execute([$bid, $from, $to]);
$trendCertificates = $trendStmt->fetchAll(PDO::FETCH_KEY_PAIR);
$trendStmt = $db->prepare("SELECT DATE_FORMAT(created_at,'%Y-%m') AS period, COUNT(*) AS total
    FROM blotter_cases WHERE barangay_id=? AND DATE(created_at) BETWEEN ? AND ? GROUP BY period");
$trendStmt->execute([$bid, $from, $to]);
$trendBlotter = $trendStmt->fetchAll(PDO::FETCH_KEY_PAIR);
$monthLabels = []; $monthNewRes = []; $monthCerts = []; $monthBlotter = [];
$monthCursor = new DateTimeImmutable(date('Y-m-01', strtotime($from)));
$toMonth = new DateTimeImmutable(date('Y-m-01', strtotime($to)));
while ($monthCursor <= $toMonth) {
    $monthKey = $monthCursor->format('Y-m');
    $monthLabels[] = $monthCursor->format('M Y');
    $monthNewRes[] = (int)($trendResidents[$monthKey] ?? 0);
    $monthCerts[] = (int)($trendCertificates[$monthKey] ?? 0);
    $monthBlotter[] = (int)($trendBlotter[$monthKey] ?? 0);
    $monthCursor = $monthCursor->modify('+1 month');
}

// ── Gender ─────────────────────────────────────────────────
$genderData = ['Male' => 0, 'Female' => 0];
foreach ($db->query("SELECT gender, COUNT(*) c FROM residents WHERE barangay_id=$bid AND resident_status='Active' GROUP BY gender")->fetchAll() as $r)
    $genderData[$r['gender']] = (int)$r['c'];

// ── Age groups ─────────────────────────────────────────────
$ageGroups = ['0-12' => 0, '13-17' => 0, '18-35' => 0, '36-59' => 0, '60+' => 0];
foreach ($db->query("SELECT birthdate FROM residents WHERE barangay_id=$bid AND resident_status='Active' AND birthdate IS NOT NULL")->fetchAll() as $r) {
    $age = (int)date_diff(date_create($r['birthdate']), date_create())->y;
    if      ($age <= 12) $ageGroups['0-12']++;
    elseif  ($age <= 17) $ageGroups['13-17']++;
    elseif  ($age <= 35) $ageGroups['18-35']++;
    elseif  ($age <= 59) $ageGroups['36-59']++;
    else                 $ageGroups['60+']++;
}

// ── Resident status ────────────────────────────────────────
$resStatus = [];
foreach ($db->query("SELECT resident_status, COUNT(*) c FROM residents WHERE barangay_id=$bid GROUP BY resident_status")->fetchAll() as $r)
    $resStatus[$r['resident_status']] = (int)$r['c'];

// ── Voter status ───────────────────────────────────────────
$voterData = ['Registered' => 0, 'Not Registered' => 0];
foreach ($db->query("SELECT voter_status, COUNT(*) c FROM residents WHERE barangay_id=$bid AND resident_status='Active' GROUP BY voter_status")->fetchAll() as $r)
    $voterData[$r['voter_status']] = (int)$r['c'];

// ── Employment ─────────────────────────────────────────────
$empStatuses = ['Employed', 'Unemployed', 'Self-Employed', 'Student', 'Retired'];
$empValues   = [];
foreach ($empStatuses as $es)
    $empValues[] = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND occupation_status='$es' AND resident_status='Active'")->fetchColumn();

// ── Sectors ────────────────────────────────────────────────
$sectors = [
    'PWD'         => (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND is_pwd=1 AND resident_status='Active'")->fetchColumn(),
    'Senior'      => (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND is_senior=1 AND resident_status='Active'")->fetchColumn(),
    'Solo Parent' => (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND is_solo_parent=1 AND resident_status='Active'")->fetchColumn(),
    '4Ps'         => (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND is_4ps=1 AND resident_status='Active'")->fetchColumn(),
];

// ── Certificate types ──────────────────────────────────────
$certTypes = []; $certCounts = [];
foreach ($db->query("SELECT certificate_type, COUNT(*) c FROM certificates WHERE barangay_id=$bid GROUP BY certificate_type ORDER BY c DESC LIMIT 6")->fetchAll() as $r) {
    $certTypes[]  = $r['certificate_type'];
    $certCounts[] = (int)$r['c'];
}

// ── Blotter by status ──────────────────────────────────────
$blotterStatus = [];
foreach ($db->query("SELECT status, COUNT(*) c FROM blotter_cases WHERE barangay_id=$bid GROUP BY status")->fetchAll() as $r)
    $blotterStatus[$r['status']] = (int)$r['c'];

// ── Household income distribution ─────────────────────────
$incomeRanges = ['Below ₱10,000', '₱10,000 - ₱20,000', '₱20,001 - ₱40,000', '₱40,001 - ₱60,000', 'Above ₱60,000'];
$incomeCounts = [];
foreach ($incomeRanges as $ir) {
    $s = $db->prepare("SELECT COUNT(*) FROM households WHERE barangay_id=? AND income_range=? AND status='Active'");
    $s->execute([$bid, $ir]);
    $incomeCounts[] = (int)$s->fetchColumn();
}

// ── Officials by position ──────────────────────────────────
$officialsByPos = [];
foreach ($db->query("SELECT position, COUNT(*) c FROM officials WHERE barangay_id=$bid AND status='active' GROUP BY position ORDER BY c DESC")->fetchAll() as $r)
    $officialsByPos[$r['position']] = (int)$r['c'];

// ── Requests breakdown ─────────────────────────────────────
$reqTypes = ['password_change' => 'Password Change', 'edit_information' => 'Edit Info', 'new_user' => 'New User', 'access_request' => 'Access', 'reset_2fa' => 'Reset 2FA'];
$reqCounts = [];
foreach (array_keys($reqTypes) as $rt) {
    $s = $db->prepare("SELECT COUNT(*) FROM user_requests WHERE barangay_id=? AND request_type=?");
    $s->execute([$bid, $rt]);
    $reqCounts[] = (int)$s->fetchColumn();
}

// ── Debug data (only collected when ?debug=1) ──────────────
$debugData = null;
if (isset($_GET['debug']) && $_GET['debug'] === '1') {
    $debugData = [
        'barangay_id'    => $bid,
        'barangay_name'  => $brgy['barangay_name'] ?? null,
        'date_from'      => $from,
        'date_to'        => $to,
        'kpi'            => $kpi,
        'gender'         => $genderData,
        'age_groups'     => $ageGroups,
        'res_status'     => $resStatus,
        'voter'          => $voterData,
        'employment'     => array_combine($empStatuses, $empValues),
        'sectors'        => $sectors,
        'cert_types'     => array_combine(
            count($certTypes) ? $certTypes : ['(none)'],
            count($certCounts) ? $certCounts : [0]
        ),
        'blotter_status' => $blotterStatus,
        'income_dist'    => array_combine($incomeRanges, $incomeCounts),
        'officials_pos'  => $officialsByPos,
        'requests'       => array_combine(array_values($reqTypes), $reqCounts),
        'trend_labels'   => $monthLabels,
        'trend_residents'=> $monthNewRes,
        'trend_certs'    => $monthCerts,
        'trend_blotter'  => $monthBlotter,
    ];
}

$pageIcon     = 'bi-bar-chart-line-fill';
$pageTitle    = 'Analytics';
$pageSubtitle = 'Demographic charts and population insights.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favcon.png">
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= $pageTitle ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=3.3">
    <style>
        .topbar{height:80px !important;}
        .main-content{margin-top:80px !important;padding:12px 20px 20px 22px;}
        .sidebar-brand{height:80px !important;}
        .kpi-card { border-radius:14px; padding:18px 20px; display:flex; align-items:center; gap:14px; border:1.5px solid rgba(0,0,0,0.08); box-shadow:0 2px 8px rgba(0,0,0,0.06); transition:transform .15s,box-shadow .15s; height:100%; }
        .kpi-card:hover { transform:translateY(-2px); box-shadow:0 6px 18px rgba(0,0,0,.12); }
        .kpi-icon { width:46px; height:46px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:1.3rem; flex-shrink:0; }
        .kpi-val  { font-size:1.6rem; font-weight:800; line-height:1; color:#1a2e1f; }
        .kpi-lbl  { font-size:0.72rem; color:#64748b; font-weight:500; margin-top:3px; }
        .chart-card { background:#fff; border:1.5px solid #d0e8f4; border-radius:14px; overflow:hidden; box-shadow:0 2px 8px rgba(26,79,122,0.06); }
        .chart-card .ch { padding:10px 16px; font-size:0.78rem; font-weight:700; color:#1a2e1f; background:#f4f8fb; border-bottom:1.5px solid #d0e8f4; display:flex; align-items:center; gap:8px; }
        .chart-card .cb { padding:14px 16px; }
        .sec-title { font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.07em; color:#3a7fa8; margin-bottom:10px; display:flex; align-items:center; gap:6px; }
        .sec-title::after { content:''; flex:1; height:1px; background:#d0e8f4; }
        html.dark-mode .kpi-card  { background:#0f1f35; border-color:#1e3a5f; }
        html.dark-mode .kpi-val   { color:#c1e8ff; }
        html.dark-mode .chart-card { background:#0f1f35; border-color:#1e3a5f; }
        html.dark-mode .chart-card .ch { background:#0a1929; border-color:#1e3a5f; color:#c1e8ff; }
        /* Empty state for charts */
        .chart-empty-state { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:8px; padding:30px 16px; color:#94a3b8; }
        .chart-empty-state i { font-size:2rem; opacity:.5; }
        .chart-empty-state span { font-size:.78rem; font-weight:500; }
        /* Debug panel */
        .debug-panel { background:#0f172a; color:#94a3b8; border-radius:12px; padding:18px; font-size:.72rem; font-family:monospace; overflow-x:auto; }
        .debug-panel h6 { color:#38bdf8; font-size:.78rem; margin-bottom:10px; }
        .debug-row { display:flex; gap:10px; padding:3px 0; border-bottom:1px solid #1e293b; }
        .debug-key { color:#f472b6; min-width:180px; flex-shrink:0; }
        .debug-val { color:#e2e8f0; }
        .debug-val.zero { color:#ef4444; }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/sidebar_barangay.php'; ?>
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<div class="main-content">

<?php if ($debugData !== null): ?>
    <!-- ── Debug Panel (remove ?debug=1 to hide) ──── -->
    <div class="alert alert-warning d-flex align-items-center gap-2 mb-3" style="border-radius:10px;">
        <i class="bi bi-bug-fill fs-5"></i>
        <div><strong>Debug mode active.</strong> Raw query counts are shown below. Remove <code>?debug=1</code> from the URL to hide this panel.</div>
    </div>
    <div class="debug-panel mb-4">
        <h6><i class="bi bi-database-fill me-2"></i>Raw Analytics Data — Barangay ID: <?= $bid ?> (<?= htmlspecialchars($debugData['barangay_name'] ?? 'unknown') ?>)</h6>
        <div class="debug-row"><span class="debug-key">Date Range</span><span class="debug-val"><?= htmlspecialchars($debugData['date_from']) ?> → <?= htmlspecialchars($debugData['date_to']) ?></span></div>
        <?php
        function debugRows(array $data, string $section): void {
            echo '<div style="margin-top:10px;color:#38bdf8;font-weight:bold;">' . htmlspecialchars($section) . '</div>';
            foreach ($data as $k => $v) {
                $isZero = ($v === 0 || $v === '0' || $v === null);
                echo '<div class="debug-row"><span class="debug-key">' . htmlspecialchars($k) . '</span>'
                   . '<span class="debug-val' . ($isZero ? ' zero' : '') . '">' . htmlspecialchars((string)$v) . ($isZero ? ' ← 0' : '') . '</span></div>';
            }
        }
        debugRows($debugData['kpi'], 'KPI Counts');
        debugRows($debugData['gender'], 'Gender');
        debugRows($debugData['age_groups'], 'Age Groups');
        debugRows($debugData['res_status'], 'Resident Status');
        debugRows($debugData['voter'], 'Voter Status');
        debugRows($debugData['employment'], 'Employment');
        debugRows($debugData['sectors'], 'Sectors (PWD / Senior / Solo / 4Ps)');
        debugRows($debugData['cert_types'], 'Certificate Types');
        debugRows($debugData['blotter_status'], 'Blotter Status');
        debugRows($debugData['income_dist'], 'Household Income');
        debugRows($debugData['officials_pos'], 'Officials by Position');
        debugRows($debugData['requests'], 'Requests by Type');
        ?>
        <div style="margin-top:10px;color:#38bdf8;font-weight:bold;">Monthly Trend (selected period)</div>
        <?php
        foreach ($debugData['trend_labels'] as $i => $lbl) {
            $r = $debugData['trend_residents'][$i];
            $c = $debugData['trend_certs'][$i];
            $b = $debugData['trend_blotter'][$i];
            echo '<div class="debug-row"><span class="debug-key">' . htmlspecialchars($lbl) . '</span>'
               . '<span class="debug-val">Residents: ' . $r . ' | Certs: ' . $c . ' | Blotter: ' . $b . '</span></div>';
        }
        ?>
    </div>
<?php endif; ?>

    <!-- ── Date Range Filter ──── -->
    <div class="card mb-4" style="border-radius:12px;border:1.5px solid #d0e8f4;">
        <div class="card-body py-2">
            <form method="GET" id="analyticsDateFilter" class="d-flex flex-wrap align-items-center gap-2">
                <label for="analyticsPeriod" class="small fw-semibold text-muted mb-0">Date Range:</label>
                <select name="period" id="analyticsPeriod" class="form-select form-select-sm" style="width:180px;">
                    <?php foreach ($periodLabels as $key => $label): ?>
                    <option value="<?= htmlspecialchars($key) ?>" <?= $period === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <div id="customDateFields" class="d-<?= $period === 'custom' ? 'flex' : 'none' ?> align-items-center gap-2">
                    <input type="date" name="from" aria-label="Start date" class="form-control form-control-sm" style="width:150px;" value="<?= htmlspecialchars($from) ?>" max="<?= htmlspecialchars($todayString) ?>">
                    <span class="small text-muted">to</span>
                    <input type="date" name="to" aria-label="End date" class="form-control form-control-sm" style="width:150px;" value="<?= htmlspecialchars($to) ?>" max="<?= htmlspecialchars($todayString) ?>">
                </div>
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel-fill me-1"></i>Apply</button>
                <a href="?period=current_month" class="btn btn-sm btn-outline-secondary" aria-label="Reset date filter"><i class="bi bi-arrow-counterclockwise"></i></a>
                <span class="ms-auto badge" style="background:#dbeafe;color:#1a4f7a;font-size:.75rem;padding:6px 12px;border-radius:20px;">
                    <i class="bi bi-geo-alt-fill me-1"></i><?= $brgyName ?>
                </span>
                <div class="w-100 d-flex flex-wrap align-items-center gap-1 mt-1 analytics-period-note">
                    <span><strong>Selected period:</strong> <?= htmlspecialchars($selectedPeriodLabel) ?>.</span>
                    <button type="button" class="btn btn-link btn-sm" aria-label="Explain date ranges"
                            data-bs-toggle="popover" data-bs-trigger="focus" data-bs-placement="bottom" data-bs-html="true"
                            data-bs-content="<?= htmlspecialchars('<strong>Paano gumagana ang date range?</strong><br>Current Month: mula unang araw ng buwang ito hanggang ngayon.<br>Last 3, 6, o 12 Months: mula unang araw ng pinakamaagang buwan sa napiling saklaw hanggang ngayon.<br>Custom Range: kasama ang parehong petsang pinili.<br><br>Ang napiling panahon ang ginagamit sa New Residents, Certificates Issued, Blotter Cases, at Trends. Ang ibang demographic at current total charts ay kasalukuyang snapshot ng barangay mo.', ENT_QUOTES, 'UTF-8') ?>">
                        <i class="bi bi-exclamation-circle-fill text-warning"></i>
                        Paano gumagana ang date range?
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php if ($filterError): ?>
    <div class="alert alert-warning py-2 small" role="alert"><i class="bi bi-exclamation-triangle-fill me-1"></i><?= htmlspecialchars($filterError) ?></div>
    <?php endif; ?>

    <!-- ── KPI Cards ──── -->
    <div class="sec-title"><i class="bi bi-grid-1x2-fill text-primary"></i>Key Metrics</div>
    <div class="row g-3 mb-4" style="align-items:stretch;">
        <?php
        $kpis = [
            ['label'=>'Total Residents',   'val'=>$kpi['total_residents'],  'icon'=>'bi-people-fill',             'ic'=>'#1a4f7a','bg'=>'#dbeafe','ibg'=>'#93c5fd'],
            ['label'=>'New Residents',     'val'=>$kpi['new_residents'],    'icon'=>'bi-person-plus-fill',        'ic'=>'#065f46','bg'=>'#d1fae5','ibg'=>'#6ee7b7'],
            ['label'=>'Active Households', 'val'=>$kpi['total_households'], 'icon'=>'bi-house-fill',              'ic'=>'#1a4f7a','bg'=>'#e0f2fe','ibg'=>'#7dd3fc'],
            ['label'=>'Certs Issued',      'val'=>$kpi['certs_issued'],     'icon'=>'bi-file-earmark-check-fill', 'ic'=>'#6d28d9','bg'=>'#ede9fe','ibg'=>'#c4b5fd'],
            ['label'=>'Blotter Cases',     'val'=>$kpi['blotter_cases'],    'icon'=>'bi-shield-exclamation',      'ic'=>'#991b1b','bg'=>'#fee2e2','ibg'=>'#fca5a5'],
            ['label'=>'Active Officials',  'val'=>$kpi['officials'],        'icon'=>'bi-person-badge-fill',       'ic'=>'#92400e','bg'=>'#fef3c7','ibg'=>'#fcd34d'],
        ];
        foreach ($kpis as $k): ?>
        <div class="col-6 col-md-4 col-lg-2 d-flex">
            <div class="kpi-card w-100" style="background:<?= $k['bg'] ?>;">
                <div class="kpi-icon" style="background:<?= $k['ibg'] ?>;"><i class="bi <?= $k['icon'] ?>" style="color:<?= $k['ic'] ?>;"></i></div>
                <div>
                    <div class="kpi-val"><?= number_format($k['val']) ?></div>
                    <div class="kpi-lbl"><?= $k['label'] ?></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ── Trend Charts ──── -->
    <div class="sec-title"><i class="bi bi-graph-up text-success"></i>Trends (<?= htmlspecialchars($selectedPeriodLabel) ?>)</div>
    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="chart-card">
                <div class="ch"><i class="bi bi-graph-up-arrow text-primary"></i>Resident Registrations, Certificates &amp; Blotter Cases</div>
                <div class="cb" id="cb-trendChart"><canvas id="trendChart" style="max-height:220px;display:block;"></canvas></div>
            </div>
        </div>
    </div>

    <!-- ── Demographics ──── -->
    <div class="sec-title"><i class="bi bi-people-fill text-info"></i>Demographics</div>
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="chart-card h-100">
                <div class="ch"><i class="bi bi-gender-ambiguous text-primary"></i>Gender</div>
                <div class="cb d-flex align-items-center justify-content-center" id="cb-genderChart"><canvas id="genderChart" style="max-height:180px;display:block;"></canvas></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="chart-card h-100">
                <div class="ch"><i class="bi bi-calendar-person text-success"></i>Age Groups</div>
                <div class="cb d-flex align-items-center justify-content-center" id="cb-ageChart"><canvas id="ageChart" style="max-height:180px;display:block;"></canvas></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="chart-card h-100">
                <div class="ch"><i class="bi bi-person-check-fill text-warning"></i>Voter Status</div>
                <div class="cb d-flex align-items-center justify-content-center" id="cb-voterChart"><canvas id="voterChart" style="max-height:180px;display:block;"></canvas></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="chart-card h-100">
                <div class="ch"><i class="bi bi-person-workspace text-danger"></i>Resident Status</div>
                <div class="cb d-flex align-items-center justify-content-center" id="cb-resStatusChart"><canvas id="resStatusChart" style="max-height:180px;display:block;"></canvas></div>
            </div>
        </div>
    </div>

    <!-- ── Employment & Sectors ──── -->
    <div class="sec-title"><i class="bi bi-briefcase-fill text-warning"></i>Employment &amp; Sectors</div>
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="chart-card h-100">
                <div class="ch"><i class="bi bi-bar-chart-fill text-primary"></i>Employment Status</div>
                <div class="cb" id="cb-empChart"><canvas id="empChart" style="max-height:200px;display:block;"></canvas></div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="chart-card h-100">
                <div class="ch"><i class="bi bi-people-fill text-info"></i>Sector Breakdown</div>
                <div class="cb d-flex align-items-center justify-content-center" id="cb-sectorChart"><canvas id="sectorChart" style="max-height:200px;display:block;"></canvas></div>
            </div>
        </div>
    </div>

    <!-- ── Services & Blotter ──── -->
    <div class="sec-title"><i class="bi bi-file-earmark-text-fill text-primary"></i>Services &amp; Blotter</div>
    <div class="row g-3 mb-4">
        <div class="col-md-5">
            <div class="chart-card h-100">
                <div class="ch"><i class="bi bi-file-earmark-bar-graph-fill text-success"></i>Certificates by Type</div>
                <div class="cb" id="cb-certChart"><canvas id="certChart" style="max-height:200px;display:block;"></canvas></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="chart-card h-100">
                <div class="ch"><i class="bi bi-shield-fill-exclamation text-danger"></i>Blotter by Status</div>
                <div class="cb d-flex align-items-center justify-content-center" id="cb-blotterChart"><canvas id="blotterChart" style="max-height:200px;display:block;"></canvas></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="chart-card h-100">
                <div class="ch"><i class="bi bi-currency-exchange text-warning"></i>Household Income</div>
                <div class="cb" id="cb-incomeChart"><canvas id="incomeChart" style="max-height:200px;display:block;"></canvas></div>
            </div>
        </div>
    </div>

    <!-- ── Officials & Requests ──── -->
    <div class="sec-title"><i class="bi bi-person-badge-fill text-primary"></i>Officials &amp; Requests</div>
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="chart-card h-100">
                <div class="ch"><i class="bi bi-person-badge-fill text-primary"></i>Officials by Position</div>
                <div class="cb" id="cb-officialsChart"><canvas id="officialsChart" style="max-height:200px;display:block;"></canvas></div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="chart-card h-100">
                <div class="ch"><i class="bi bi-inbox-fill text-warning"></i>Requests by Type</div>
                <div class="cb" id="cb-reqChart"><canvas id="reqChart" style="max-height:200px;display:block;"></canvas></div>
            </div>
        </div>
    </div>

</div><!-- /main-content -->

<script defer src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('show'); }

document.addEventListener('DOMContentLoaded', function () {

const periodSelect = document.getElementById('analyticsPeriod');
const customDateFields = document.getElementById('customDateFields');
periodSelect.addEventListener('change', function () {
    customDateFields.classList.toggle('d-flex', this.value === 'custom');
    customDateFields.classList.toggle('d-none', this.value !== 'custom');
});
document.querySelectorAll('[data-bs-toggle="popover"]').forEach(function (element) {
    new bootstrap.Popover(element);
});

const palette = ['#1a4f7a','#2176ae','#3a9fd8','#43a047','#e53935','#fb8c00','#8e24aa','#00897b'];
const tt = { ticks:{font:{size:10}}, grid:{color:'rgba(0,0,0,0.06)'} };
const pctTip = { callbacks:{ label: ctx => { const t=ctx.dataset.data.reduce((a,b)=>a+b,0); return ` ${ctx.label}: ${ctx.parsed.toLocaleString()} (${t?((ctx.parsed/t)*100).toFixed(1):0}%)`; }}};

// ── Trend ─────────────────────────────────────────────────
new Chart(document.getElementById('trendChart'), {
    type:'line',
    data:{
        labels: <?= json_encode($monthLabels) ?>,
        datasets:[
            { label:'New Residents', data:<?= json_encode($monthNewRes) ?>,   borderColor:'#1a4f7a', backgroundColor:'rgba(26,79,122,0.08)',  tension:.4, fill:true, pointRadius:3, pointBackgroundColor:'#1a4f7a' },
            { label:'Certificates',  data:<?= json_encode($monthCerts) ?>,    borderColor:'#43a047', backgroundColor:'rgba(67,160,71,0.07)',   tension:.4, fill:true, pointRadius:3, pointBackgroundColor:'#43a047' },
            { label:'Blotter Cases', data:<?= json_encode($monthBlotter) ?>,  borderColor:'#e53935', backgroundColor:'rgba(229,57,53,0.06)',   tension:.4, fill:true, pointRadius:3, pointBackgroundColor:'#e53935' },
        ]
    },
    options:{ responsive:true, plugins:{ legend:{ position:'top', labels:{ font:{ size:11 }, boxWidth:12 }}}, scales:{ x:tt, y:{...tt, beginAtZero:true} }}
});

// ── Gender ────────────────────────────────────────────────
new Chart(document.getElementById('genderChart'), {
    type:'doughnut',
    data:{ labels:['Male','Female'], datasets:[{ data:[<?= $genderData['Male'] ?>,<?= $genderData['Female'] ?>], backgroundColor:['#2176ae','#e91e8c'], borderWidth:2, borderColor:'#fff', hoverOffset:8 }]},
    options:{ responsive:true, plugins:{ legend:{ position:'bottom', labels:{ font:{size:10}, boxWidth:10 }}, tooltip:pctTip }}
});

// ── Age ───────────────────────────────────────────────────
new Chart(document.getElementById('ageChart'), {
    type:'doughnut',
    data:{ labels:<?= json_encode(array_keys($ageGroups)) ?>, datasets:[{ data:<?= json_encode(array_values($ageGroups)) ?>, backgroundColor:['#1a4f7a','#2176ae','#43a047','#fb8c00','#8e24aa'], borderWidth:2, borderColor:'#fff', hoverOffset:8 }]},
    options:{ responsive:true, plugins:{ legend:{ position:'bottom', labels:{ font:{size:10}, boxWidth:10 }}, tooltip:pctTip }}
});

// ── Voter ─────────────────────────────────────────────────
new Chart(document.getElementById('voterChart'), {
    type:'doughnut',
    data:{ labels:['Registered','Not Registered'], datasets:[{ data:[<?= $voterData['Registered'] ?>,<?= $voterData['Not Registered'] ?>], backgroundColor:['#1a4f7a','#e0e0e0'], borderWidth:2, borderColor:'#fff', hoverOffset:8 }]},
    options:{ responsive:true, plugins:{ legend:{ position:'bottom', labels:{ font:{size:10}, boxWidth:10 }}, tooltip:pctTip }}
});

// ── Resident Status ───────────────────────────────────────
new Chart(document.getElementById('resStatusChart'), {
    type:'doughnut',
    data:{ labels:<?= json_encode(array_keys($resStatus ?: ['No Data'=>0])) ?>, datasets:[{ data:<?= json_encode(array_values($resStatus ?: [0])) ?>, backgroundColor:['#43a047','#64748b','#2176ae','#e53935'], borderWidth:2, borderColor:'#fff', hoverOffset:8 }]},
    options:{ responsive:true, plugins:{ legend:{ position:'bottom', labels:{ font:{size:10}, boxWidth:10 }}, tooltip:pctTip }}
});

// ── Employment ────────────────────────────────────────────
new Chart(document.getElementById('empChart'), {
    type:'bar',
    data:{ labels:<?= json_encode($empStatuses) ?>, datasets:[{ data:<?= json_encode($empValues) ?>, backgroundColor:palette, borderRadius:6, borderWidth:0 }]},
    options:{ responsive:true, plugins:{ legend:{ display:false }}, scales:{ x:tt, y:{...tt, beginAtZero:true} }}
});

// ── Sectors ───────────────────────────────────────────────
new Chart(document.getElementById('sectorChart'), {
    type:'bar',
    data:{ labels:<?= json_encode(array_keys($sectors)) ?>, datasets:[{ data:<?= json_encode(array_values($sectors)) ?>, backgroundColor:['#8e24aa','#fb8c00','#e53935','#00897b'], borderRadius:6, borderWidth:0 }]},
    options:{ responsive:true, indexAxis:'y', plugins:{ legend:{ display:false }}, scales:{ x:{...tt, beginAtZero:true}, y:tt }}
});

// ── Certificates ──────────────────────────────────────────
new Chart(document.getElementById('certChart'), {
    type:'bar',
    data:{ labels:<?= json_encode($certTypes ?: ['No Data']) ?>, datasets:[{ data:<?= json_encode($certCounts ?: [0]) ?>, backgroundColor:palette, borderRadius:6, borderWidth:0 }]},
    options:{ responsive:true, plugins:{ legend:{ display:false }}, scales:{ x:{...tt, ticks:{...tt.ticks, maxRotation:30}}, y:{...tt, beginAtZero:true} }}
});

// ── Blotter ───────────────────────────────────────────────
new Chart(document.getElementById('blotterChart'), {
    type:'doughnut',
    data:{ labels:<?= json_encode(array_keys($blotterStatus ?: ['No Data'=>0])) ?>, datasets:[{ data:<?= json_encode(array_values($blotterStatus ?: [0])) ?>, backgroundColor:['#fb8c00','#1a4f7a','#43a047','#e53935','#64748b'], borderWidth:2, borderColor:'#fff', hoverOffset:8 }]},
    options:{ responsive:true, plugins:{ legend:{ position:'bottom', labels:{ font:{size:9}, boxWidth:9 }}, tooltip:pctTip }}
});

// ── Income ────────────────────────────────────────────────
new Chart(document.getElementById('incomeChart'), {
    type:'bar',
    data:{ labels:<?= json_encode($incomeRanges) ?>, datasets:[{ data:<?= json_encode($incomeCounts) ?>, backgroundColor:'rgba(26,79,122,0.75)', borderRadius:6, borderWidth:0, hoverBackgroundColor:'#1a4f7a' }]},
    options:{ responsive:true, plugins:{ legend:{ display:false }}, scales:{ x:{...tt, ticks:{...tt.ticks, maxRotation:30}}, y:{...tt, beginAtZero:true} }}
});

// ── Officials ─────────────────────────────────────────────
new Chart(document.getElementById('officialsChart'), {
    type:'bar',
    data:{ labels:<?= json_encode(array_keys($officialsByPos ?: ['No Data'=>0])) ?>, datasets:[{ data:<?= json_encode(array_values($officialsByPos ?: [0])) ?>, backgroundColor:palette, borderRadius:6, borderWidth:0 }]},
    options:{ responsive:true, indexAxis:'y', plugins:{ legend:{ display:false }}, scales:{ x:{...tt, beginAtZero:true}, y:tt }}
});

// ── Requests ──────────────────────────────────────────────
new Chart(document.getElementById('reqChart'), {
    type:'bar',
    data:{ labels:<?= json_encode(array_values($reqTypes)) ?>, datasets:[{ data:<?= json_encode($reqCounts) ?>, backgroundColor:['#e53935','#1a4f7a','#43a047','#64748b','#8e24aa'], borderRadius:6, borderWidth:0 }]},
    options:{ responsive:true, plugins:{ legend:{ display:false }}, scales:{ x:tt, y:{...tt, beginAtZero:true} }}
});

}); // end DOMContentLoaded
</script>
</body>
</html>
