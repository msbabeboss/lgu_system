<?php
require_once __DIR__ . '/../config/config.php';
requireRole('super_admin');

$db = Database::getInstance()->getConnection();
$u  = currentUser();

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

$bid = max(0, (int)($_GET['barangay_id'] ?? 0));
$andBrgy = $bid ? " AND barangay_id=$bid" : '';
$residentTransferOutExclusion = " AND resident_status <> 'Transferred'";
$periodCount = static function (string $table, string $dateColumn, string $extraWhere = '', array $extraParams = []) use ($db, $from, $to, $andBrgy, $residentTransferOutExclusion): int {
    $residentFilter = $table === 'residents'
        ? $residentTransferOutExclusion
        : '';
    $stmt = $db->prepare("SELECT COUNT(*) FROM $table WHERE DATE($dateColumn) BETWEEN ? AND ?$extraWhere$residentFilter$andBrgy");
    $stmt->execute(array_merge([$from, $to], $extraParams));
    return (int)$stmt->fetchColumn();
};
$periodRows = static function (string $sql) use ($db, $from, $to): array {
    $stmt = $db->prepare($sql);
    $stmt->execute([$from, $to]);
    return $stmt->fetchAll();
};
$selectedPeriodLabel = date('F j', strtotime($from)) . (date('Y-m', strtotime($from)) === date('Y-m', strtotime($to))
    ? '–' . date('j, Y', strtotime($to))
    : ', ' . date('Y', strtotime($from)) . ' – ' . date('F j, Y', strtotime($to)));

// ── All barangays for filter ───────────────────────────────
$barangays = $db->query("SELECT id, barangay_name FROM barangays WHERE status='active' ORDER BY barangay_name")->fetchAll();

// ── KPI Cards ─────────────────────────────────────────────
$kpi = [];
$kpi['total_residents']  = (int)$db->query("SELECT COUNT(*) FROM residents WHERE resident_status='Active'$andBrgy")->fetchColumn();
$kpi['new_residents']    = $periodCount('residents', 'created_at');
$kpi['total_households'] = (int)$db->query("SELECT COUNT(*) FROM households WHERE status='Active'$andBrgy")->fetchColumn();
$kpi['certs_issued']     = $periodCount('certificates', 'date_issued');
$kpi['blotter_cases']    = $periodCount('blotter_cases', 'created_at');
$kpi['active_barangays'] = $bid
    ? (int)$db->query("SELECT COUNT(*) FROM barangays WHERE status='active' AND id=$bid")->fetchColumn()
    : (int)$db->query("SELECT COUNT(*) FROM barangays WHERE status='active'")->fetchColumn();

// ── Monthly trends within the selected period ──────────────
$trendResidents = $periodRows("SELECT DATE_FORMAT(created_at,'%Y-%m') AS period, COUNT(*) AS total
    FROM residents WHERE DATE(created_at) BETWEEN ? AND ?$residentTransferOutExclusion$andBrgy GROUP BY period");
$trendCertificates = $periodRows("SELECT DATE_FORMAT(date_issued,'%Y-%m') AS period, COUNT(*) AS total
    FROM certificates WHERE DATE(date_issued) BETWEEN ? AND ?$andBrgy GROUP BY period");
$trendBlotter = $periodRows("SELECT DATE_FORMAT(created_at,'%Y-%m') AS period, COUNT(*) AS total
    FROM blotter_cases WHERE DATE(created_at) BETWEEN ? AND ?$andBrgy GROUP BY period");
$toMonth = new DateTimeImmutable(date('Y-m-01', strtotime($to)));
$monthCursor = new DateTimeImmutable(date('Y-m-01', strtotime($from)));
$trendMaps = ['residents' => [], 'certificates' => [], 'blotter' => []];
foreach ($trendResidents as $row) $trendMaps['residents'][$row['period']] = (int)$row['total'];
foreach ($trendCertificates as $row) $trendMaps['certificates'][$row['period']] = (int)$row['total'];
foreach ($trendBlotter as $row) $trendMaps['blotter'][$row['period']] = (int)$row['total'];
$monthLabels = []; $monthNewRes = []; $monthCerts = []; $monthBlotter = [];
while ($monthCursor <= $toMonth) {
    $monthKey = $monthCursor->format('Y-m');
    $monthLabels[] = $monthCursor->format('M Y');
    $monthNewRes[] = $trendMaps['residents'][$monthKey] ?? 0;
    $monthCerts[] = $trendMaps['certificates'][$monthKey] ?? 0;
    $monthBlotter[] = $trendMaps['blotter'][$monthKey] ?? 0;
    $monthCursor = $monthCursor->modify('+1 month');
}

// ── Gender ─────────────────────────────────────────────────
$genderData = ['Male' => 0, 'Female' => 0];
foreach ($periodRows("SELECT gender, COUNT(*) c FROM residents WHERE DATE(created_at) BETWEEN ? AND ?$residentTransferOutExclusion$andBrgy GROUP BY gender") as $r)
    $genderData[$r['gender']] = (int)$r['c'];

// ── Age groups ─────────────────────────────────────────────
$ageGroups = ['0-12' => 0, '13-17' => 0, '18-35' => 0, '36-59' => 0, '60+' => 0];
foreach ($periodRows("SELECT birthdate FROM residents WHERE DATE(created_at) BETWEEN ? AND ?$residentTransferOutExclusion AND resident_status='Active' AND birthdate IS NOT NULL$andBrgy") as $r) {
    $age = (int)date_diff(date_create($r['birthdate']), date_create($todayString))->y;
    if      ($age <= 12) $ageGroups['0-12']++;
    elseif  ($age <= 17) $ageGroups['13-17']++;
    elseif  ($age <= 35) $ageGroups['18-35']++;
    elseif  ($age <= 59) $ageGroups['36-59']++;
    else                 $ageGroups['60+']++;
}

// ── Resident status ────────────────────────────────────────
$resStatus = [];
foreach ($periodRows("SELECT resident_status, COUNT(*) c FROM residents WHERE DATE(created_at) BETWEEN ? AND ?$residentTransferOutExclusion$andBrgy GROUP BY resident_status") as $r)
    $resStatus[$r['resident_status']] = (int)$r['c'];

// ── Voter status ───────────────────────────────────────────
$voterData = ['Registered' => 0, 'Not Registered' => 0];
foreach ($periodRows("SELECT voter_status, COUNT(*) c FROM residents WHERE DATE(created_at) BETWEEN ? AND ?$residentTransferOutExclusion AND resident_status='Active'$andBrgy GROUP BY voter_status") as $r)
    $voterData[$r['voter_status']] = (int)$r['c'];

// ── Employment ─────────────────────────────────────────────
$empStatuses = ['Employed', 'Unemployed', 'Self-Employed', 'Student', 'Retired'];
$empValues   = [];
foreach ($empStatuses as $es)
    $empValues[] = $periodCount('residents', 'created_at', " AND occupation_status=? AND resident_status='Active'", [$es]);

// ── Sectors ────────────────────────────────────────────────
$sectors = [
    'PWD'         => $periodCount('residents', 'created_at', " AND is_pwd=1 AND resident_status='Active'"),
    'Senior'      => $periodCount('residents', 'created_at', " AND is_senior=1 AND resident_status='Active'"),
    'Solo Parent' => $periodCount('residents', 'created_at', " AND is_solo_parent=1 AND resident_status='Active'"),
    '4Ps'         => $periodCount('residents', 'created_at', " AND is_4ps=1 AND resident_status='Active'"),
];

// ── Certificate types ──────────────────────────────────────
$certTypes = []; $certCounts = [];
foreach ($periodRows("SELECT certificate_type, COUNT(*) c FROM certificates WHERE DATE(date_issued) BETWEEN ? AND ?$andBrgy GROUP BY certificate_type ORDER BY c DESC LIMIT 6") as $r) {
    $certTypes[]  = $r['certificate_type'];
    $certCounts[] = (int)$r['c'];
}

// ── Blotter by status ──────────────────────────────────────
$blotterStatus = [];
foreach ($periodRows("SELECT status, COUNT(*) c FROM blotter_cases WHERE DATE(created_at) BETWEEN ? AND ?$andBrgy GROUP BY status") as $r)
    $blotterStatus[$r['status']] = (int)$r['c'];

// ── Household income distribution ─────────────────────────
$incomeRanges = ['Below ₱10,000', '₱10,000 - ₱20,000', '₱20,001 - ₱40,000', '₱40,001 - ₱60,000', 'Above ₱60,000'];
$incomeCounts = [];
foreach ($incomeRanges as $ir)
    $incomeCounts[] = $periodCount('households', 'created_at', " AND income_range=? AND status='Active'", [$ir]);

// ── Top barangays by residents registered in the period ────
$topBrgy = $periodRows("SELECT b.barangay_name, COUNT(r.id) AS residents
    FROM barangays b
    LEFT JOIN residents r ON r.barangay_id=b.id AND r.resident_status='Active'
        AND DATE(r.created_at) BETWEEN ? AND ?
    WHERE b.status='active'" . ($bid ? " AND b.id=$bid" : '') . "
    GROUP BY b.id ORDER BY residents DESC LIMIT 5");

// ── Login activity within the selected period ──────────────
$loginQuery = "SELECT DATE_FORMAT(l.login_time,'%Y-%m') AS period, COUNT(*) AS total FROM login_logs l";
if ($bid) $loginQuery .= " INNER JOIN users u ON u.id=l.user_id";
$loginQuery .= " WHERE DATE(l.login_time) BETWEEN ? AND ?" . ($bid ? " AND u.barangay_id=?" : '') . " GROUP BY period";
$loginStmt = $db->prepare($loginQuery);
$loginParams = [$from, $to];
if ($bid) $loginParams[] = $bid;
$loginStmt->execute($loginParams);
$loginByMonth = [];
foreach ($loginStmt->fetchAll() as $row) $loginByMonth[$row['period']] = (int)$row['total'];
$loginLabels = $monthLabels;
$loginValues = [];
$monthCursor = new DateTimeImmutable(date('Y-m-01', strtotime($from)));
while ($monthCursor <= $toMonth) {
    $loginValues[] = $loginByMonth[$monthCursor->format('Y-m')] ?? 0;
    $monthCursor = $monthCursor->modify('+1 month');
}

$pageIcon     = 'bi-bar-chart-line-fill';
$pageTitle    = 'Analytics';
$pageSubtitle = 'System-wide demographic charts and population insights.';
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
        .topbar { height:80px !important; }
        .main-content { margin-top:80px !important; padding:12px 20px 20px 22px; }
        .sidebar-brand { height:80px !important; }

        /* KPI cards — colored bg same as brgy admin */
        .kpi-card {
            border-radius: 14px;
            padding: 18px 20px;
            display: flex;
            align-items: center;
            gap: 14px;
            border: 1.5px solid rgba(0,0,0,0.08);
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            transition: transform .15s, box-shadow .15s;
            height: 100%;
        }
        .kpi-card:hover { transform: translateY(-2px); box-shadow: 0 6px 18px rgba(0,0,0,.12); }
        .kpi-icon {
            width: 46px; height: 46px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.3rem; flex-shrink: 0;
        }
        .kpi-val { font-size: 1.6rem; font-weight: 800; line-height: 1; color: #1a2e1f; }
        .kpi-lbl { font-size: 0.72rem; color: #64748b; font-weight: 500; margin-top: 3px; }

        /* Chart cards */
        .chart-card {
            background: #fff;
            border: 1.5px solid #d0e8f4;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(26,79,122,0.06);
        }
        .chart-card .ch {
            padding: 10px 16px;
            font-size: 0.78rem;
            font-weight: 700;
            color: #1a2e1f;
            background: #f4f8fb;
            border-bottom: 1.5px solid #d0e8f4;
            display: flex; align-items: center; gap: 8px;
        }
        .chart-card .cb { padding: 14px 16px; }

        /* Progress bar rows for top barangays */
        .brgy-bar-row { display:flex; align-items:center; gap:10px; margin-bottom:10px; }
        .brgy-bar-row .brgy-name { font-size:0.75rem; font-weight:600; color:#0f172a; width:130px; flex-shrink:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .brgy-bar-row .brgy-bar-wrap { flex:1; background:#e8eef4; border-radius:6px; height:8px; }
        .brgy-bar-row .brgy-bar-fill { height:8px; border-radius:6px; background:#2563eb; }
        .brgy-bar-row .brgy-count { font-size:0.72rem; font-weight:700; color:#2563eb; width:50px; text-align:right; flex-shrink:0; }
        .analytics-period-note { color:#334155; font-size:.8rem; }
        .analytics-period-note .btn { padding:0; vertical-align:baseline; }
        .analytics-period-note .popover { max-width:min(360px, calc(100vw - 24px)); }

        /* Section titles */
        .sec-title {
            font-size: .72rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: .07em;
            color: #3a7fa8; margin-bottom: 10px;
            display: flex; align-items: center; gap: 6px;
        }
        .sec-title::after { content:''; flex:1; height:1px; background:#d0e8f4; }

        /* Dark mode */
        html.dark-mode .kpi-val { color: #c1e8ff; }
        html.dark-mode .chart-card { background:#0f1f35; border-color:#1e3a5f; }
        html.dark-mode .chart-card .ch { background:#0a1929; border-color:#1e3a5f; color:#c1e8ff; }
        html.dark-mode .brgy-bar-row .brgy-name { color:#c1e8ff; }
        html.dark-mode .brgy-bar-row .brgy-bar-wrap { background:#1e3a5f; }
        html.dark-mode .main-content { background:#071428; }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/sidebar_super.php'; ?>
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<div class="main-content">

    <!-- ── Filter ── -->
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
                <select name="barangay_id" class="form-select form-select-sm" style="width:200px;">
                    <option value="">All Barangays</option>
                    <?php foreach ($barangays as $b): ?>
                    <option value="<?= $b['id'] ?>" <?= $bid==$b['id']?'selected':'' ?>><?= htmlspecialchars($b['barangay_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel-fill me-1"></i>Apply</button>
                <a href="?period=current_month<?= $bid ? '&barangay_id=' . $bid : '' ?>" class="btn btn-sm btn-outline-secondary" aria-label="Reset date filter"><i class="bi bi-arrow-counterclockwise"></i></a>
                <?php if ($bid): ?>
                <span class="badge" style="background:#dbeafe;color:#1a4f7a;font-size:.75rem;padding:6px 12px;border-radius:20px;">
                    <i class="bi bi-geo-alt-fill me-1"></i><?= htmlspecialchars($barangays[array_search($bid, array_column($barangays,'id'))]['barangay_name'] ?? '') ?>
                </span>
                <?php endif; ?>
                <div class="w-100 d-flex flex-wrap align-items-center gap-1 mt-1 analytics-period-note">
                    <span><strong>Selected period:</strong> <?= htmlspecialchars($selectedPeriodLabel) ?>.</span>
                    <button type="button" class="btn btn-link btn-sm" aria-label="Explain date ranges"
                            data-bs-toggle="popover" data-bs-trigger="focus" data-bs-placement="bottom" data-bs-html="true"
                            data-bs-content="<?= htmlspecialchars('<strong>How date ranges work</strong><br>Current Month: first day of this month through today.<br>Last 3, 6, or 12 Months: starts on the first day of the earliest included month and ends today.<br>Custom Range: includes both selected dates.<br><br>The selected period filters registration, certificate, blotter, household, login, and related chart data. Transferred-out origin records are excluded. A resident is counted in the destination barangay only after an active destination record is created; pending or rejected transfer requests are not counted there. Total residents, active households, and active barangays are current snapshots.', ENT_QUOTES, 'UTF-8') ?>">
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

    <!-- ── KPI Cards ── -->
    <div class="sec-title"><i class="bi bi-grid-1x2-fill text-primary"></i>Key Metrics</div>
    <div class="row g-3 mb-4" style="align-items:stretch;">
        <?php
        $kpis = [
            ['label'=>'Total Residents (Current)', 'val'=>$kpi['total_residents'],  'icon'=>'bi-people-fill',             'ic'=>'#1a4f7a','bg'=>'#dbeafe','ibg'=>'#93c5fd'],
            ['label'=>'New Residents',     'val'=>$kpi['new_residents'],    'icon'=>'bi-person-plus-fill',        'ic'=>'#065f46','bg'=>'#d1fae5','ibg'=>'#6ee7b7'],
            ['label'=>'Active Households (Current)', 'val'=>$kpi['total_households'], 'icon'=>'bi-house-fill',              'ic'=>'#1a4f7a','bg'=>'#e0f2fe','ibg'=>'#7dd3fc'],
            ['label'=>'Certs Issued',      'val'=>$kpi['certs_issued'],     'icon'=>'bi-file-earmark-check-fill', 'ic'=>'#6d28d9','bg'=>'#ede9fe','ibg'=>'#c4b5fd'],
            ['label'=>'Blotter Cases',     'val'=>$kpi['blotter_cases'],    'icon'=>'bi-shield-exclamation',      'ic'=>'#991b1b','bg'=>'#fee2e2','ibg'=>'#fca5a5'],
            ['label'=>'Active Barangays (Current)', 'val'=>$kpi['active_barangays'], 'icon'=>'bi-geo-alt-fill',            'ic'=>'#059669','bg'=>'#d1fae5','ibg'=>'#6ee7b7'],
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

    <!-- ── Trend Chart ── -->
    <div class="sec-title"><i class="bi bi-graph-up text-success"></i>Trends (<?= htmlspecialchars($selectedPeriodLabel) ?>)</div>
    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="chart-card">
                <div class="ch"><i class="bi bi-graph-up-arrow text-primary"></i>Resident Registrations, Certificates &amp; Blotter Cases</div>
                <div class="cb"><canvas id="trendChart" style="max-height:220px;display:block;"></canvas></div>
            </div>
        </div>
    </div>

    <!-- ── Demographics ── -->
    <div class="sec-title"><i class="bi bi-people-fill text-info"></i>Demographics (Residents Registered During Selected Period)</div>
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="chart-card h-100">
                <div class="ch"><i class="bi bi-gender-ambiguous text-primary"></i>Gender</div>
                <div class="cb d-flex align-items-center justify-content-center"><canvas id="genderChart" style="max-height:180px;display:block;"></canvas></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="chart-card h-100">
                <div class="ch"><i class="bi bi-calendar-person text-success"></i>Age Groups</div>
                <div class="cb d-flex align-items-center justify-content-center"><canvas id="ageChart" style="max-height:180px;display:block;"></canvas></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="chart-card h-100">
                <div class="ch"><i class="bi bi-person-check-fill text-warning"></i>Voter Status</div>
                <div class="cb d-flex align-items-center justify-content-center"><canvas id="voterChart" style="max-height:180px;display:block;"></canvas></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="chart-card h-100">
                <div class="ch"><i class="bi bi-person-workspace text-danger"></i>Resident Status</div>
                <div class="cb d-flex align-items-center justify-content-center"><canvas id="resStatusChart" style="max-height:180px;display:block;"></canvas></div>
            </div>
        </div>
    </div>

    <!-- ── Employment & Sectors ── -->
    <div class="sec-title"><i class="bi bi-briefcase-fill text-warning"></i>Employment &amp; Sectors</div>
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="chart-card h-100">
                <div class="ch"><i class="bi bi-bar-chart-fill text-primary"></i>Employment Status</div>
                <div class="cb"><canvas id="empChart" style="max-height:200px;display:block;"></canvas></div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="chart-card h-100">
                <div class="ch"><i class="bi bi-people-fill text-info"></i>Sector Breakdown</div>
                <div class="cb d-flex align-items-center justify-content-center"><canvas id="sectorChart" style="max-height:200px;display:block;"></canvas></div>
            </div>
        </div>
    </div>

    <!-- ── Services & Blotter ── -->
    <div class="sec-title"><i class="bi bi-file-earmark-text-fill text-primary"></i>Services &amp; Blotter</div>
    <div class="row g-3 mb-4">
        <div class="col-md-5">
            <div class="chart-card h-100">
                <div class="ch"><i class="bi bi-file-earmark-bar-graph-fill text-success"></i>Certificates by Type</div>
                <div class="cb"><canvas id="certChart" style="max-height:200px;display:block;"></canvas></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="chart-card h-100">
                <div class="ch"><i class="bi bi-shield-fill-exclamation text-danger"></i>Blotter by Status</div>
                <div class="cb d-flex align-items-center justify-content-center"><canvas id="blotterChart" style="max-height:200px;display:block;"></canvas></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="chart-card h-100">
                <div class="ch"><i class="bi bi-currency-exchange text-warning"></i>Income of Households Added in Period</div>
                <div class="cb"><canvas id="incomeChart" style="max-height:200px;display:block;"></canvas></div>
            </div>
        </div>
    </div>

    <!-- ── Top Barangays & Login Activity ── -->
    <div class="sec-title"><i class="bi bi-trophy-fill text-warning"></i>Barangay Overview &amp; Activity</div>
    <div class="row g-3 mb-4">
        <div class="col-md-5">
            <div class="chart-card h-100">
                <div class="ch"><i class="bi bi-trophy-fill text-warning"></i>Top Barangays by Residents Registered</div>
                <div class="cb">
                    <?php
                    $maxPop = $topBrgy ? max(array_column($topBrgy, 'residents')) : 1;
                    if ($topBrgy):
                        foreach ($topBrgy as $i => $tb):
                            $pct = $maxPop > 0 ? round(($tb['residents'] / $maxPop) * 100) : 0;
                    ?>
                    <div class="brgy-bar-row">
                        <span class="brgy-name"><?= $i+1 ?>. <?= htmlspecialchars($tb['barangay_name']) ?></span>
                        <div class="brgy-bar-wrap"><div class="brgy-bar-fill" style="width:<?= $pct ?>%;"></div></div>
                        <span class="brgy-count"><?= number_format($tb['residents']) ?></span>
                    </div>
                    <?php endforeach; else: ?>
                    <div class="text-center text-muted py-4 small">No barangay data available.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-7">
            <div class="chart-card h-100">
                <div class="ch"><i class="bi bi-person-check text-success"></i>Login Activity (Selected Period)</div>
                <div class="cb"><canvas id="loginChart" style="max-height:200px;display:block;"></canvas></div>
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
document.querySelectorAll('[data-bs-toggle="popover"]').forEach(el => new bootstrap.Popover(el));

const palette = ['#1a4f7a','#2176ae','#3a9fd8','#43a047','#e53935','#fb8c00','#8e24aa','#00897b'];
const tt = { ticks:{font:{size:10}}, grid:{color:'rgba(0,0,0,0.06)'} };
const pctTip = { callbacks:{ label: ctx => {
    const t = ctx.dataset.data.reduce((a,b)=>a+b,0);
    return ` ${ctx.label}: ${ctx.parsed.toLocaleString()} (${t?((ctx.parsed/t)*100).toFixed(1):0}%)`;
}}};

// ── Trend ──────────────────────────────────────────────────
new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($monthLabels) ?>,
        datasets: [
            { label:'New Residents', data:<?= json_encode($monthNewRes) ?>,   borderColor:'#1a4f7a', backgroundColor:'rgba(26,79,122,0.08)',  tension:.4, fill:true, pointRadius:3, pointBackgroundColor:'#1a4f7a' },
            { label:'Certificates',  data:<?= json_encode($monthCerts) ?>,    borderColor:'#43a047', backgroundColor:'rgba(67,160,71,0.07)',   tension:.4, fill:true, pointRadius:3, pointBackgroundColor:'#43a047' },
            { label:'Blotter Cases', data:<?= json_encode($monthBlotter) ?>,  borderColor:'#e53935', backgroundColor:'rgba(229,57,53,0.06)',   tension:.4, fill:true, pointRadius:3, pointBackgroundColor:'#e53935' },
        ]
    },
    options:{ responsive:true, plugins:{ legend:{ position:'top', labels:{ font:{ size:11 }, boxWidth:12 }}}, scales:{ x:tt, y:{...tt, beginAtZero:true} }}
});

// ── Gender ─────────────────────────────────────────────────
new Chart(document.getElementById('genderChart'), {
    type: 'doughnut',
    data: { labels:['Male','Female'], datasets:[{ data:[<?= $genderData['Male'] ?>,<?= $genderData['Female'] ?>], backgroundColor:['#2176ae','#e91e8c'], borderWidth:2, borderColor:'#fff', hoverOffset:8 }]},
    options: { responsive:true, plugins:{ legend:{ position:'bottom', labels:{ font:{size:10}, boxWidth:10 }}, tooltip:pctTip }}
});

// ── Age ────────────────────────────────────────────────────
new Chart(document.getElementById('ageChart'), {
    type: 'doughnut',
    data: { labels:<?= json_encode(array_keys($ageGroups)) ?>, datasets:[{ data:<?= json_encode(array_values($ageGroups)) ?>, backgroundColor:['#1a4f7a','#2176ae','#43a047','#fb8c00','#8e24aa'], borderWidth:2, borderColor:'#fff', hoverOffset:8 }]},
    options: { responsive:true, plugins:{ legend:{ position:'bottom', labels:{ font:{size:10}, boxWidth:10 }}, tooltip:pctTip }}
});

// ── Voter ──────────────────────────────────────────────────
new Chart(document.getElementById('voterChart'), {
    type: 'doughnut',
    data: { labels:['Registered','Not Registered'], datasets:[{ data:[<?= $voterData['Registered'] ?>,<?= $voterData['Not Registered'] ?>], backgroundColor:['#1a4f7a','#e0e0e0'], borderWidth:2, borderColor:'#fff', hoverOffset:8 }]},
    options: { responsive:true, plugins:{ legend:{ position:'bottom', labels:{ font:{size:10}, boxWidth:10 }}, tooltip:pctTip }}
});

// ── Resident Status ────────────────────────────────────────
new Chart(document.getElementById('resStatusChart'), {
    type: 'doughnut',
    data: { labels:<?= json_encode(array_keys($resStatus ?: ['No Data'=>0])) ?>, datasets:[{ data:<?= json_encode(array_values($resStatus ?: [0])) ?>, backgroundColor:['#43a047','#64748b','#2176ae','#e53935'], borderWidth:2, borderColor:'#fff', hoverOffset:8 }]},
    options: { responsive:true, plugins:{ legend:{ position:'bottom', labels:{ font:{size:10}, boxWidth:10 }}, tooltip:pctTip }}
});

// ── Employment ─────────────────────────────────────────────
new Chart(document.getElementById('empChart'), {
    type: 'bar',
    data: { labels:<?= json_encode($empStatuses) ?>, datasets:[{ data:<?= json_encode($empValues) ?>, backgroundColor:palette, borderRadius:6, borderWidth:0 }]},
    options: { responsive:true, plugins:{ legend:{ display:false }}, scales:{ x:tt, y:{...tt, beginAtZero:true} }}
});

// ── Sector ─────────────────────────────────────────────────
new Chart(document.getElementById('sectorChart'), {
    type: 'bar',
    data: { labels:<?= json_encode(array_keys($sectors)) ?>, datasets:[{ data:<?= json_encode(array_values($sectors)) ?>, backgroundColor:['#8e24aa','#fb8c00','#e53935','#00897b'], borderRadius:6, borderWidth:0 }]},
    options: { responsive:true, indexAxis:'y', plugins:{ legend:{ display:false }}, scales:{ x:{...tt, beginAtZero:true}, y:tt }}
});

// ── Certificates ───────────────────────────────────────────
new Chart(document.getElementById('certChart'), {
    type: 'bar',
    data: { labels:<?= json_encode($certTypes ?: ['No Data']) ?>, datasets:[{ data:<?= json_encode($certCounts ?: [0]) ?>, backgroundColor:palette, borderRadius:6, borderWidth:0 }]},
    options: { responsive:true, plugins:{ legend:{ display:false }}, scales:{ x:{...tt, ticks:{...tt.ticks, maxRotation:30}}, y:{...tt, beginAtZero:true} }}
});

// ── Blotter ────────────────────────────────────────────────
new Chart(document.getElementById('blotterChart'), {
    type: 'doughnut',
    data: { labels:<?= json_encode(array_keys($blotterStatus ?: ['No Data'=>0])) ?>, datasets:[{ data:<?= json_encode(array_values($blotterStatus ?: [0])) ?>, backgroundColor:['#fb8c00','#1a4f7a','#43a047','#e53935','#64748b'], borderWidth:2, borderColor:'#fff', hoverOffset:8 }]},
    options: { responsive:true, plugins:{ legend:{ position:'bottom', labels:{ font:{size:9}, boxWidth:9 }}, tooltip:pctTip }}
});

// ── Household Income ───────────────────────────────────────
new Chart(document.getElementById('incomeChart'), {
    type: 'bar',
    data: { labels:<?= json_encode($incomeRanges) ?>, datasets:[{ data:<?= json_encode($incomeCounts) ?>, backgroundColor:'rgba(26,79,122,0.75)', borderRadius:6, borderWidth:0, hoverBackgroundColor:'#1a4f7a' }]},
    options: { responsive:true, plugins:{ legend:{ display:false }}, scales:{ x:{...tt, ticks:{...tt.ticks, maxRotation:30}}, y:{...tt, beginAtZero:true} }}
});

// ── Login Activity ─────────────────────────────────────────
new Chart(document.getElementById('loginChart'), {
    type: 'bar',
    data: { labels:<?= json_encode($loginLabels) ?>, datasets:[{ data:<?= json_encode($loginValues) ?>, backgroundColor:'rgba(26,79,122,0.7)', borderRadius:4, borderWidth:0, hoverBackgroundColor:'#1a4f7a' }]},
    options: { responsive:true, plugins:{ legend:{ display:false }}, scales:{ x:{...tt, ticks:{...tt.ticks, maxRotation:45}}, y:{...tt, beginAtZero:true} }}
});

}); // end DOMContentLoaded
</script>
</body>
</html>
