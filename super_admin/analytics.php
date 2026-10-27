<?php
require_once __DIR__ . '/../config/config.php';
requireRole('super_admin');

$db = Database::getInstance()->getConnection();
$u  = currentUser();

// ── Date range filter ──────────────────────────────────────
$from = sanitize($_GET['from'] ?? date('Y-01-01'));
$to   = sanitize($_GET['to']   ?? date('Y-m-d'));
$bid  = (int)($_GET['barangay_id'] ?? 0);

$andBrgy       = $bid ? " AND barangay_id=$bid" : '';
$andBrgyW      = $bid ? " WHERE barangay_id=$bid" : '';
$whereDate     = "DATE(created_at) BETWEEN '$from' AND '$to'";
$whereDateCert = "date_issued BETWEEN '$from' AND '$to'";

// ── All barangays for filter ───────────────────────────────
$barangays = $db->query("SELECT id, barangay_name FROM barangays WHERE status='active' ORDER BY barangay_name")->fetchAll();

// ── KPI Cards ─────────────────────────────────────────────
$kpi = [];
$kpi['total_residents']  = (int)$db->query("SELECT COUNT(*) FROM residents WHERE resident_status='Active'$andBrgy")->fetchColumn();
$kpi['new_residents']    = (int)$db->query("SELECT COUNT(*) FROM residents WHERE $whereDate$andBrgy")->fetchColumn();
$kpi['total_households'] = (int)$db->query("SELECT COUNT(*) FROM households WHERE status='Active'$andBrgy")->fetchColumn();
$kpi['certs_issued']     = (int)$db->query("SELECT COUNT(*) FROM certificates WHERE $whereDateCert$andBrgy")->fetchColumn();
$kpi['blotter_cases']    = (int)$db->query("SELECT COUNT(*) FROM blotter_cases WHERE $whereDate$andBrgy")->fetchColumn();
$kpi['active_barangays'] = (int)$db->query("SELECT COUNT(*) FROM barangays WHERE status='active'")->fetchColumn();

// ── Monthly trends (12 months, anchored to latest record) ──
$_latestRes  = $db->query("SELECT MAX(created_at) FROM residents"  .($bid?" WHERE barangay_id=$bid":''))->fetchColumn();
$_latestCert = $db->query("SELECT MAX(date_issued) FROM certificates".($bid?" WHERE barangay_id=$bid":''))->fetchColumn();
$_latestBlot = $db->query("SELECT MAX(created_at) FROM blotter_cases".($bid?" WHERE barangay_id=$bid":''))->fetchColumn();
$_latestDates = array_filter([$_latestRes, $_latestCert, $_latestBlot]);
$anchorTs     = $_latestDates ? strtotime(max($_latestDates)) : time();

$monthLabels = []; $monthNewRes = []; $monthCerts = []; $monthBlotter = [];
for ($i = 11; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i months", $anchorTs));
    $monthLabels[]  = date('M Y', strtotime("-$i months", $anchorTs));
    $monthNewRes[]  = (int)$db->query("SELECT COUNT(*) FROM residents WHERE DATE_FORMAT(created_at,'%Y-%m')='$m'".($bid?" AND barangay_id=$bid":''))->fetchColumn();
    $monthCerts[]   = (int)$db->query("SELECT COUNT(*) FROM certificates WHERE DATE_FORMAT(date_issued,'%Y-%m')='$m'".($bid?" AND barangay_id=$bid":''))->fetchColumn();
    $monthBlotter[] = (int)$db->query("SELECT COUNT(*) FROM blotter_cases WHERE DATE_FORMAT(created_at,'%Y-%m')='$m'".($bid?" AND barangay_id=$bid":''))->fetchColumn();
}

// ── Gender ─────────────────────────────────────────────────
$genderData = ['Male' => 0, 'Female' => 0];
foreach ($db->query("SELECT gender, COUNT(*) c FROM residents WHERE resident_status='Active'".($bid?" AND barangay_id=$bid":'')." GROUP BY gender")->fetchAll() as $r)
    $genderData[$r['gender']] = (int)$r['c'];

// ── Age groups ─────────────────────────────────────────────
$ageGroups = ['0-12' => 0, '13-17' => 0, '18-35' => 0, '36-59' => 0, '60+' => 0];
foreach ($db->query("SELECT birthdate FROM residents WHERE resident_status='Active' AND birthdate IS NOT NULL".($bid?" AND barangay_id=$bid":''))->fetchAll() as $r) {
    $age = (int)date_diff(date_create($r['birthdate']), date_create())->y;
    if      ($age <= 12) $ageGroups['0-12']++;
    elseif  ($age <= 17) $ageGroups['13-17']++;
    elseif  ($age <= 35) $ageGroups['18-35']++;
    elseif  ($age <= 59) $ageGroups['36-59']++;
    else                 $ageGroups['60+']++;
}

// ── Resident status ────────────────────────────────────────
$resStatus = [];
foreach ($db->query("SELECT resident_status, COUNT(*) c FROM residents".($bid?" WHERE barangay_id=$bid":'')." GROUP BY resident_status")->fetchAll() as $r)
    $resStatus[$r['resident_status']] = (int)$r['c'];

// ── Voter status ───────────────────────────────────────────
$voterData = ['Registered' => 0, 'Not Registered' => 0];
foreach ($db->query("SELECT voter_status, COUNT(*) c FROM residents WHERE resident_status='Active'".($bid?" AND barangay_id=$bid":'')." GROUP BY voter_status")->fetchAll() as $r)
    $voterData[$r['voter_status']] = (int)$r['c'];

// ── Employment ─────────────────────────────────────────────
$empStatuses = ['Employed', 'Unemployed', 'Self-Employed', 'Student', 'Retired'];
$empValues   = [];
foreach ($empStatuses as $es)
    $empValues[] = (int)$db->query("SELECT COUNT(*) FROM residents WHERE occupation_status='$es' AND resident_status='Active'".($bid?" AND barangay_id=$bid":''))->fetchColumn();

// ── Sectors ────────────────────────────────────────────────
$sectors = [
    'PWD'         => (int)$db->query("SELECT COUNT(*) FROM residents WHERE is_pwd=1 AND resident_status='Active'".($bid?" AND barangay_id=$bid":''))->fetchColumn(),
    'Senior'      => (int)$db->query("SELECT COUNT(*) FROM residents WHERE is_senior=1 AND resident_status='Active'".($bid?" AND barangay_id=$bid":''))->fetchColumn(),
    'Solo Parent' => (int)$db->query("SELECT COUNT(*) FROM residents WHERE is_solo_parent=1 AND resident_status='Active'".($bid?" AND barangay_id=$bid":''))->fetchColumn(),
    '4Ps'         => (int)$db->query("SELECT COUNT(*) FROM residents WHERE is_4ps=1 AND resident_status='Active'".($bid?" AND barangay_id=$bid":''))->fetchColumn(),
];

// ── Certificate types ──────────────────────────────────────
$certTypes = []; $certCounts = [];
foreach ($db->query("SELECT certificate_type, COUNT(*) c FROM certificates".($bid?" WHERE barangay_id=$bid":'')." GROUP BY certificate_type ORDER BY c DESC LIMIT 6")->fetchAll() as $r) {
    $certTypes[]  = $r['certificate_type'];
    $certCounts[] = (int)$r['c'];
}

// ── Blotter by status ──────────────────────────────────────
$blotterStatus = [];
foreach ($db->query("SELECT status, COUNT(*) c FROM blotter_cases".($bid?" WHERE barangay_id=$bid":'')." GROUP BY status")->fetchAll() as $r)
    $blotterStatus[$r['status']] = (int)$r['c'];

// ── Household income distribution ─────────────────────────
$incomeRanges = ['Below ₱10,000', '₱10,000 - ₱20,000', '₱20,001 - ₱40,000', '₱40,001 - ₱60,000', 'Above ₱60,000'];
$incomeCounts = [];
foreach ($incomeRanges as $ir) {
    $s = $db->prepare("SELECT COUNT(*) FROM households WHERE income_range=? AND status='Active'".($bid?" AND barangay_id=$bid":''));
    $s->execute([$ir]);
    $incomeCounts[] = (int)$s->fetchColumn();
}

// ── Top 5 barangays by population ─────────────────────────
$topBrgy = $db->query("SELECT b.barangay_name, COUNT(r.id) as residents
    FROM barangays b
    LEFT JOIN residents r ON r.barangay_id=b.id AND r.resident_status='Active'
    WHERE b.status='active'
    GROUP BY b.id ORDER BY residents DESC LIMIT 5")->fetchAll();

// ── Login activity (last 30 days) ─────────────────────────
$loginLabels = []; $loginValues = [];
for ($i = 29; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i days"));
    $loginLabels[] = date('M d', strtotime("-$i days"));
    try {
        $loginValues[] = (int)$db->query("SELECT COUNT(*) FROM login_logs WHERE DATE(created_at)='$day'")->fetchColumn();
    } catch (\Throwable $e) { $loginValues[] = 0; }
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
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=3.0">
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
            <form method="GET" class="d-flex flex-wrap align-items-center gap-2">
                <label class="small fw-semibold text-muted mb-0">Date Range (default: year-to-date):</label>
                <input type="date" name="from" class="form-control form-control-sm" style="width:150px;" value="<?= htmlspecialchars($from) ?>">
                <span class="small text-muted">to</span>
                <input type="date" name="to"   class="form-control form-control-sm" style="width:150px;" value="<?= htmlspecialchars($to) ?>">
                <select name="barangay_id" class="form-select form-select-sm" style="width:200px;">
                    <option value="">All Barangays</option>
                    <?php foreach ($barangays as $b): ?>
                    <option value="<?= $b['id'] ?>" <?= $bid==$b['id']?'selected':'' ?>><?= htmlspecialchars($b['barangay_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel-fill me-1"></i>Apply</button>
                <a href="?" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-lg"></i></a>
                <?php if ($bid): ?>
                <span class="ms-auto badge" style="background:#dbeafe;color:#1a4f7a;font-size:.75rem;padding:6px 12px;border-radius:20px;">
                    <i class="bi bi-geo-alt-fill me-1"></i><?= htmlspecialchars($barangays[array_search($bid, array_column($barangays,'id'))]['barangay_name'] ?? '') ?>
                </span>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- ── KPI Cards ── -->
    <div class="sec-title"><i class="bi bi-grid-1x2-fill text-primary"></i>Key Metrics</div>
    <div class="row g-3 mb-4" style="align-items:stretch;">
        <?php
        $kpis = [
            ['label'=>'Total Residents',   'val'=>$kpi['total_residents'],  'icon'=>'bi-people-fill',             'ic'=>'#1a4f7a','bg'=>'#dbeafe','ibg'=>'#93c5fd'],
            ['label'=>'New Residents',     'val'=>$kpi['new_residents'],    'icon'=>'bi-person-plus-fill',        'ic'=>'#065f46','bg'=>'#d1fae5','ibg'=>'#6ee7b7'],
            ['label'=>'Active Households', 'val'=>$kpi['total_households'], 'icon'=>'bi-house-fill',              'ic'=>'#1a4f7a','bg'=>'#e0f2fe','ibg'=>'#7dd3fc'],
            ['label'=>'Certs Issued',      'val'=>$kpi['certs_issued'],     'icon'=>'bi-file-earmark-check-fill', 'ic'=>'#6d28d9','bg'=>'#ede9fe','ibg'=>'#c4b5fd'],
            ['label'=>'Blotter Cases',     'val'=>$kpi['blotter_cases'],    'icon'=>'bi-shield-exclamation',      'ic'=>'#991b1b','bg'=>'#fee2e2','ibg'=>'#fca5a5'],
            ['label'=>'Active Barangays',  'val'=>$kpi['active_barangays'], 'icon'=>'bi-geo-alt-fill',            'ic'=>'#059669','bg'=>'#d1fae5','ibg'=>'#6ee7b7'],
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
    <div class="sec-title"><i class="bi bi-graph-up text-success"></i>Trends (Last 12 Months ending <?= date('M Y', $anchorTs) ?>)</div>
    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="chart-card">
                <div class="ch"><i class="bi bi-graph-up-arrow text-primary"></i>Resident Registrations, Certificates &amp; Blotter Cases</div>
                <div class="cb"><canvas id="trendChart" style="max-height:220px;display:block;"></canvas></div>
            </div>
        </div>
    </div>

    <!-- ── Demographics ── -->
    <div class="sec-title"><i class="bi bi-people-fill text-info"></i>Demographics</div>
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
                <div class="ch"><i class="bi bi-currency-exchange text-warning"></i>Household Income</div>
                <div class="cb"><canvas id="incomeChart" style="max-height:200px;display:block;"></canvas></div>
            </div>
        </div>
    </div>

    <!-- ── Top Barangays & Login Activity ── -->
    <div class="sec-title"><i class="bi bi-trophy-fill text-warning"></i>Barangay Overview &amp; Activity</div>
    <div class="row g-3 mb-4">
        <div class="col-md-5">
            <div class="chart-card h-100">
                <div class="ch"><i class="bi bi-trophy-fill text-warning"></i>Top Barangays by Population</div>
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
                <div class="ch"><i class="bi bi-person-check text-success"></i>Login Activity (Last 30 Days)</div>
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
