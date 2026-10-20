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
$from     = sanitize($_GET['from'] ?? date('Y-m-01'));
$to       = sanitize($_GET['to']   ?? date('Y-m-d'));
$brgyName = htmlspecialchars($brgy['barangay_name'] ?? 'Barangay');

$whereDate = "DATE(created_at) BETWEEN '$from' AND '$to'";
// Certificates use date_issued (explicit issue date), not created_at
$whereDateCert = "date_issued BETWEEN '$from' AND '$to'";

// ── KPI Cards ─────────────────────────────────────────────
$kpi = [];
$kpi['total_residents']  = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND resident_status='Active'")->fetchColumn();
$kpi['new_residents']    = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND $whereDate")->fetchColumn();
$kpi['total_households'] = (int)$db->query("SELECT COUNT(*) FROM households WHERE barangay_id=$bid AND status='Active'")->fetchColumn();
$kpi['certs_issued']     = (int)$db->query("SELECT COUNT(*) FROM certificates WHERE barangay_id=$bid AND $whereDateCert")->fetchColumn();
$kpi['blotter_cases']    = (int)$db->query("SELECT COUNT(*) FROM blotter_cases WHERE barangay_id=$bid AND $whereDate")->fetchColumn();
$kpi['officials']        = (int)$db->query("SELECT COUNT(*) FROM officials WHERE barangay_id=$bid AND status='active'")->fetchColumn();

// ── Monthly trends (12 months) ─────────────────────────────
$monthLabels = []; $monthNewRes = []; $monthCerts = []; $monthBlotter = [];
for ($i = 11; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $monthLabels[]  = date('M Y', strtotime("-$i months"));
    $monthNewRes[]  = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND DATE_FORMAT(created_at,'%Y-%m')='$m'")->fetchColumn();
    $monthCerts[]   = (int)$db->query("SELECT COUNT(*) FROM certificates WHERE barangay_id=$bid AND DATE_FORMAT(date_issued,'%Y-%m')='$m'")->fetchColumn();
    $monthBlotter[] = (int)$db->query("SELECT COUNT(*) FROM blotter_cases WHERE barangay_id=$bid AND DATE_FORMAT(created_at,'%Y-%m')='$m'")->fetchColumn();
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
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=3.0">
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
        <div style="margin-top:10px;color:#38bdf8;font-weight:bold;">Monthly Trend (last 12 months)</div>
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

    <!-- ── Filters ──── -->
    <div class="card mb-4" style="border-radius:12px;border:1.5px solid #d0e8f4;">
        <div class="card-body py-2">
            <form method="GET" class="d-flex flex-wrap align-items-center gap-2">
                <label class="small fw-semibold text-muted mb-0">Date Range:</label>
                <input type="date" name="from" class="form-control form-control-sm" style="width:150px;" value="<?= htmlspecialchars($from) ?>">
                <span class="small text-muted">to</span>
                <input type="date" name="to"   class="form-control form-control-sm" style="width:150px;" value="<?= htmlspecialchars($to) ?>">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel-fill me-1"></i>Apply</button>
                <a href="?" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-lg"></i></a>
                <span class="ms-auto badge" style="background:#dbeafe;color:#1a4f7a;font-size:.75rem;padding:6px 12px;border-radius:20px;">
                    <i class="bi bi-geo-alt-fill me-1"></i><?= $brgyName ?>
                </span>
            </form>
        </div>
    </div>

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
    <div class="sec-title"><i class="bi bi-graph-up text-success"></i>Trends (Last 12 Months)</div>
    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="chart-card">
                <div class="ch"><i class="bi bi-graph-up-arrow text-primary"></i>Resident Registrations, Certificates &amp; Blotter Cases</div>
                <div class="cb" id="cb-trendChart"><canvas id="trendChart" style="max-height:220px;"></canvas></div>
            </div>
        </div>
    </div>

    <!-- ── Demographics ──── -->
    <div class="sec-title"><i class="bi bi-people-fill text-info"></i>Demographics</div>
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="chart-card h-100">
                <div class="ch"><i class="bi bi-gender-ambiguous text-primary"></i>Gender</div>
                <div class="cb d-flex align-items-center justify-content-center" id="cb-genderChart"><canvas id="genderChart" style="max-height:180px;"></canvas></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="chart-card h-100">
                <div class="ch"><i class="bi bi-calendar-person text-success"></i>Age Groups</div>
                <div class="cb d-flex align-items-center justify-content-center" id="cb-ageChart"><canvas id="ageChart" style="max-height:180px;"></canvas></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="chart-card h-100">
                <div class="ch"><i class="bi bi-person-check-fill text-warning"></i>Voter Status</div>
                <div class="cb d-flex align-items-center justify-content-center" id="cb-voterChart"><canvas id="voterChart" style="max-height:180px;"></canvas></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="chart-card h-100">
                <div class="ch"><i class="bi bi-person-workspace text-danger"></i>Resident Status</div>
                <div class="cb d-flex align-items-center justify-content-center" id="cb-resStatusChart"><canvas id="resStatusChart" style="max-height:180px;"></canvas></div>
            </div>
        </div>
    </div>

    <!-- ── Employment & Sectors ──── -->
    <div class="sec-title"><i class="bi bi-briefcase-fill text-warning"></i>Employment &amp; Sectors</div>
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="chart-card h-100">
                <div class="ch"><i class="bi bi-bar-chart-fill text-primary"></i>Employment Status</div>
                <div class="cb" id="cb-empChart"><canvas id="empChart" style="max-height:200px;"></canvas></div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="chart-card h-100">
                <div class="ch"><i class="bi bi-people-fill text-info"></i>Sector Breakdown</div>
                <div class="cb d-flex align-items-center justify-content-center" id="cb-sectorChart"><canvas id="sectorChart" style="max-height:200px;"></canvas></div>
            </div>
        </div>
    </div>

    <!-- ── Services & Blotter ──── -->
    <div class="sec-title"><i class="bi bi-file-earmark-text-fill text-primary"></i>Services &amp; Blotter</div>
    <div class="row g-3 mb-4">
        <div class="col-md-5">
            <div class="chart-card h-100">
                <div class="ch"><i class="bi bi-file-earmark-bar-graph-fill text-success"></i>Certificates by Type</div>
                <div class="cb" id="cb-certChart"><canvas id="certChart" style="max-height:200px;"></canvas></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="chart-card h-100">
                <div class="ch"><i class="bi bi-shield-fill-exclamation text-danger"></i>Blotter by Status</div>
                <div class="cb d-flex align-items-center justify-content-center" id="cb-blotterChart"><canvas id="blotterChart" style="max-height:200px;"></canvas></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="chart-card h-100">
                <div class="ch"><i class="bi bi-currency-exchange text-warning"></i>Household Income</div>
                <div class="cb" id="cb-incomeChart"><canvas id="incomeChart" style="max-height:200px;"></canvas></div>
            </div>
        </div>
    </div>

    <!-- ── Officials & Requests ──── -->
    <div class="sec-title"><i class="bi bi-person-badge-fill text-primary"></i>Officials &amp; Requests</div>
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="chart-card h-100">
                <div class="ch"><i class="bi bi-person-badge-fill text-primary"></i>Officials by Position</div>
                <div class="cb" id="cb-officialsChart"><canvas id="officialsChart" style="max-height:200px;"></canvas></div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="chart-card h-100">
                <div class="ch"><i class="bi bi-inbox-fill text-warning"></i>Requests by Type</div>
                <div class="cb" id="cb-reqChart"><canvas id="reqChart" style="max-height:200px;"></canvas></div>
            </div>
        </div>
    </div>

</div><!-- /main-content -->

<script defer src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script defer src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script defer>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('show'); }

const palette = ['#1a4f7a','#2176ae','#3a9fd8','#43a047','#e53935','#fb8c00','#8e24aa','#00897b'];
const tt = { ticks:{font:{size:10}}, grid:{color:'rgba(0,0,0,0.06)'} };
const pctTip = { callbacks:{ label: ctx => { const t=ctx.dataset.data.reduce((a,b)=>a+b,0); return ` ${ctx.label}: ${ctx.parsed.toLocaleString()} (${t?((ctx.parsed/t)*100).toFixed(1):0}%)`; }}};

/**
 * Renders a Chart.js chart inside a canvas.
 * If all data values are zero (or the data array is empty), hides the canvas
 * and shows a "No data for this period" empty state inside the parent .cb div.
 *
 * @param {string} canvasId   - The id of the <canvas> element
 * @param {object} config     - Standard Chart.js config object
 * @param {Array}  dataValues - Flat array of numeric values used to detect empty state
 */
function renderChart(canvasId, config, dataValues) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return null;
    const container = document.getElementById('cb-' + canvasId);

    // Determine if all values are zero / empty
    const vals = (dataValues || []).map(Number);
    const isEmpty = vals.length === 0 || vals.every(v => v === 0 || isNaN(v));

    if (isEmpty) {
        canvas.style.display = 'none';
        if (container) {
            container.innerHTML =
                '<div class="chart-empty-state w-100">' +
                '<i class="bi bi-bar-chart-line"></i>' +
                '<span>No data for this period</span>' +
                '</div>';
        }
        return null;
    }

    return new Chart(canvas, config);
}

// ── Trend ─────────────────────────────────────────────────
const trendResidents = <?= json_encode($monthNewRes) ?>;
const trendCerts     = <?= json_encode($monthCerts) ?>;
const trendBlotter   = <?= json_encode($monthBlotter) ?>;
const trendAllValues = [...trendResidents, ...trendCerts, ...trendBlotter];

renderChart('trendChart', {
    type:'line',
    data:{
        labels: <?= json_encode($monthLabels) ?>,
        datasets:[
            { label:'New Residents', data:trendResidents, borderColor:'#1a4f7a', backgroundColor:'rgba(26,79,122,0.08)',  tension:.4, fill:true, pointRadius:3, pointBackgroundColor:'#1a4f7a' },
            { label:'Certificates',  data:trendCerts,     borderColor:'#43a047', backgroundColor:'rgba(67,160,71,0.07)',   tension:.4, fill:true, pointRadius:3, pointBackgroundColor:'#43a047' },
            { label:'Blotter Cases', data:trendBlotter,   borderColor:'#e53935', backgroundColor:'rgba(229,57,53,0.06)',   tension:.4, fill:true, pointRadius:3, pointBackgroundColor:'#e53935' },
        ]
    },
    options:{ responsive:true, plugins:{ legend:{ position:'top', labels:{ font:{ size:11 }, boxWidth:12 }}}, scales:{ x:tt, y:{...tt, beginAtZero:true} }}
}, trendAllValues);

// ── Gender ────────────────────────────────────────────────
renderChart('genderChart', {
    type:'doughnut',
    data:{ labels:['Male','Female'], datasets:[{ data:[<?= $genderData['Male'] ?>,<?= $genderData['Female'] ?>], backgroundColor:['#2176ae','#e91e8c'], borderWidth:2, borderColor:'#fff', hoverOffset:8 }]},
    options:{ responsive:true, plugins:{ legend:{ position:'bottom', labels:{ font:{size:10}, boxWidth:10 }}, tooltip:pctTip }}
}, [<?= $genderData['Male'] ?>,<?= $genderData['Female'] ?>]);

// ── Age ───────────────────────────────────────────────────
const ageVals = <?= json_encode(array_values($ageGroups)) ?>;
renderChart('ageChart', {
    type:'doughnut',
    data:{ labels:<?= json_encode(array_keys($ageGroups)) ?>, datasets:[{ data:ageVals, backgroundColor:['#1a4f7a','#2176ae','#43a047','#fb8c00','#8e24aa'], borderWidth:2, borderColor:'#fff', hoverOffset:8 }]},
    options:{ responsive:true, plugins:{ legend:{ position:'bottom', labels:{ font:{size:10}, boxWidth:10 }}, tooltip:pctTip }}
}, ageVals);

// ── Voter ─────────────────────────────────────────────────
renderChart('voterChart', {
    type:'doughnut',
    data:{ labels:['Registered','Not Registered'], datasets:[{ data:[<?= $voterData['Registered'] ?>,<?= $voterData['Not Registered'] ?>], backgroundColor:['#1a4f7a','#e0e0e0'], borderWidth:2, borderColor:'#fff', hoverOffset:8 }]},
    options:{ responsive:true, plugins:{ legend:{ position:'bottom', labels:{ font:{size:10}, boxWidth:10 }}, tooltip:pctTip }}
}, [<?= $voterData['Registered'] ?>,<?= $voterData['Not Registered'] ?>]);

// ── Resident Status ───────────────────────────────────────
const resStatusVals = <?= json_encode(array_values($resStatus ?: [])) ?>;
renderChart('resStatusChart', {
    type:'doughnut',
    data:{ labels:<?= json_encode(array_keys($resStatus ?: [])) ?>, datasets:[{ data:resStatusVals, backgroundColor:['#43a047','#64748b','#2176ae','#e53935'], borderWidth:2, borderColor:'#fff', hoverOffset:8 }]},
    options:{ responsive:true, plugins:{ legend:{ position:'bottom', labels:{ font:{size:10}, boxWidth:10 }}, tooltip:pctTip }}
}, resStatusVals);

// ── Employment ────────────────────────────────────────────
const empVals = <?= json_encode($empValues) ?>;
renderChart('empChart', {
    type:'bar',
    data:{ labels:<?= json_encode($empStatuses) ?>, datasets:[{ data:empVals, backgroundColor:palette, borderRadius:6, borderWidth:0 }]},
    options:{ responsive:true, plugins:{ legend:{ display:false }}, scales:{ x:tt, y:{...tt, beginAtZero:true} }}
}, empVals);

// ── Sectors ───────────────────────────────────────────────
const sectorVals = <?= json_encode(array_values($sectors)) ?>;
renderChart('sectorChart', {
    type:'bar',
    data:{ labels:<?= json_encode(array_keys($sectors)) ?>, datasets:[{ data:sectorVals, backgroundColor:['#8e24aa','#fb8c00','#e53935','#00897b'], borderRadius:6, borderWidth:0 }]},
    options:{ responsive:true, indexAxis:'y', plugins:{ legend:{ display:false }}, scales:{ x:{...tt, beginAtZero:true}, y:tt }}
}, sectorVals);

// ── Certificates ──────────────────────────────────────────
const certVals = <?= json_encode($certCounts ?: []) ?>;
renderChart('certChart', {
    type:'bar',
    data:{ labels:<?= json_encode($certTypes ?: []) ?>, datasets:[{ data:certVals, backgroundColor:palette, borderRadius:6, borderWidth:0 }]},
    options:{ responsive:true, plugins:{ legend:{ display:false }}, scales:{ x:{...tt, ticks:{...tt.ticks, maxRotation:30}}, y:{...tt, beginAtZero:true} }}
}, certVals);

// ── Blotter ───────────────────────────────────────────────
const blotterVals = <?= json_encode(array_values($blotterStatus ?: [])) ?>;
renderChart('blotterChart', {
    type:'doughnut',
    data:{ labels:<?= json_encode(array_keys($blotterStatus ?: [])) ?>, datasets:[{ data:blotterVals, backgroundColor:['#fb8c00','#1a4f7a','#43a047','#e53935','#64748b'], borderWidth:2, borderColor:'#fff', hoverOffset:8 }]},
    options:{ responsive:true, plugins:{ legend:{ position:'bottom', labels:{ font:{size:9}, boxWidth:9 }}, tooltip:pctTip }}
}, blotterVals);

// ── Income ────────────────────────────────────────────────
const incomeVals = <?= json_encode($incomeCounts) ?>;
renderChart('incomeChart', {
    type:'bar',
    data:{ labels:<?= json_encode($incomeRanges) ?>, datasets:[{ data:incomeVals, backgroundColor:'rgba(26,79,122,0.75)', borderRadius:6, borderWidth:0, hoverBackgroundColor:'#1a4f7a' }]},
    options:{ responsive:true, plugins:{ legend:{ display:false }}, scales:{ x:{...tt, ticks:{...tt.ticks, maxRotation:30}}, y:{...tt, beginAtZero:true} }}
}, incomeVals);

// ── Officials ─────────────────────────────────────────────
const officialsVals = <?= json_encode(array_values($officialsByPos ?: [])) ?>;
renderChart('officialsChart', {
    type:'bar',
    data:{ labels:<?= json_encode(array_keys($officialsByPos ?: [])) ?>, datasets:[{ data:officialsVals, backgroundColor:palette, borderRadius:6, borderWidth:0 }]},
    options:{ responsive:true, indexAxis:'y', plugins:{ legend:{ display:false }}, scales:{ x:{...tt, beginAtZero:true}, y:tt }}
}, officialsVals);

// ── Requests ──────────────────────────────────────────────
const reqVals = <?= json_encode($reqCounts) ?>;
renderChart('reqChart', {
    type:'bar',
    data:{ labels:<?= json_encode(array_values($reqTypes)) ?>, datasets:[{ data:reqVals, backgroundColor:['#e53935','#1a4f7a','#43a047','#64748b','#8e24aa'], borderRadius:6, borderWidth:0 }]},
    options:{ responsive:true, plugins:{ legend:{ display:false }}, scales:{ x:tt, y:{...tt, beginAtZero:true} }}
}, reqVals);
</script>
</body>
</html>
