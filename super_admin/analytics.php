<?php
require_once __DIR__ . '/../config/config.php';
requireRole('super_admin');

$db = Database::getInstance()->getConnection();
$u  = currentUser();

// ── Date range filter ──────────────────────────────────────
$from = sanitize($_GET['from'] ?? date('Y-m-01'));
$to   = sanitize($_GET['to']   ?? date('Y-m-d'));
$bid  = (int)($_GET['barangay_id'] ?? 0);

$whereDate  = "DATE(created_at) BETWEEN '$from' AND '$to'";
$andBrgy    = $bid ? " AND barangay_id=$bid" : '';
// Certificates use date_issued (explicit issue date), not created_at
$whereDateCert = "date_issued BETWEEN '$from' AND '$to'";

// ── All barangays for filter ───────────────────────────────
$barangays = $db->query("SELECT id, barangay_name FROM barangays WHERE status='active' ORDER BY barangay_name")->fetchAll();

// ── KPI Cards ─────────────────────────────────────────────
$kpi = [];
$kpi['total_residents']   = (int)$db->query("SELECT COUNT(*) FROM residents WHERE resident_status='Active'$andBrgy")->fetchColumn();
$kpi['new_residents']     = (int)$db->query("SELECT COUNT(*) FROM residents WHERE $whereDate$andBrgy")->fetchColumn();
$kpi['total_households']  = (int)$db->query("SELECT COUNT(*) FROM households WHERE status='Active'$andBrgy")->fetchColumn();
$kpi['certs_issued']      = (int)$db->query("SELECT COUNT(*) FROM certificates WHERE $whereDateCert$andBrgy")->fetchColumn();
$kpi['blotter_cases']     = (int)$db->query("SELECT COUNT(*) FROM blotter_cases WHERE $whereDate$andBrgy")->fetchColumn();
$kpi['active_barangays']  = (int)$db->query("SELECT COUNT(*) FROM barangays WHERE status='active'")->fetchColumn();

// ── Monthly resident growth (12 months) ───────────────────
$monthLabels = []; $monthNewRes = []; $monthCerts = []; $monthBlotter = [];
for ($i = 11; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $monthLabels[]  = date('M Y', strtotime("-$i months"));
    $monthNewRes[]  = (int)$db->query("SELECT COUNT(*) FROM residents WHERE DATE_FORMAT(created_at,'%Y-%m')='$m'".($bid?" AND barangay_id=$bid":''))->fetchColumn();
    $monthCerts[]   = (int)$db->query("SELECT COUNT(*) FROM certificates WHERE DATE_FORMAT(date_issued,'%Y-%m')='$m'".($bid?" AND barangay_id=$bid":''))->fetchColumn();
    $monthBlotter[] = (int)$db->query("SELECT COUNT(*) FROM blotter_cases WHERE DATE_FORMAT(created_at,'%Y-%m')='$m'".($bid?" AND barangay_id=$bid":''))->fetchColumn();
}

// ── Gender breakdown ───────────────────────────────────────
$genderData = ['Male' => 0, 'Female' => 0];
foreach ($db->query("SELECT gender, COUNT(*) c FROM residents WHERE resident_status='Active'".($bid?" AND barangay_id=$bid":'')." GROUP BY gender")->fetchAll() as $r)
    $genderData[$r['gender']] = (int)$r['c'];

// ── Age group breakdown ────────────────────────────────────
$ageGroups = ['0-12' => 0, '13-17' => 0, '18-35' => 0, '36-59' => 0, '60+' => 0];
$ageQ = $db->query("SELECT birthdate FROM residents WHERE resident_status='Active' AND birthdate IS NOT NULL".($bid?" AND barangay_id=$bid":''));
foreach ($ageQ->fetchAll() as $r) {
    $age = (int)date_diff(date_create($r['birthdate']), date_create())->y;
    if      ($age <= 12) $ageGroups['0-12']++;
    elseif  ($age <= 17) $ageGroups['13-17']++;
    elseif  ($age <= 35) $ageGroups['18-35']++;
    elseif  ($age <= 59) $ageGroups['36-59']++;
    else                 $ageGroups['60+']++;
}

// ── Resident status breakdown ──────────────────────────────
$resStatus = [];
foreach ($db->query("SELECT resident_status, COUNT(*) c FROM residents".($bid?" WHERE barangay_id=$bid":'')." GROUP BY resident_status")->fetchAll() as $r)
    $resStatus[$r['resident_status']] = (int)$r['c'];

// ── Employment breakdown ───────────────────────────────────
$empStatuses = ['Employed','Unemployed','Self-Employed','Student','Retired'];
$empValues = [];
foreach ($empStatuses as $es)
    $empValues[] = (int)$db->query("SELECT COUNT(*) FROM residents WHERE occupation_status='$es' AND resident_status='Active'".($bid?" AND barangay_id=$bid":''))->fetchColumn();

// ── Sector breakdown ───────────────────────────────────────
$sectors = [
    'PWD'         => (int)$db->query("SELECT COUNT(*) FROM residents WHERE is_pwd=1 AND resident_status='Active'".($bid?" AND barangay_id=$bid":''))->fetchColumn(),
    'Senior'      => (int)$db->query("SELECT COUNT(*) FROM residents WHERE is_senior=1 AND resident_status='Active'".($bid?" AND barangay_id=$bid":''))->fetchColumn(),
    'Solo Parent' => (int)$db->query("SELECT COUNT(*) FROM residents WHERE is_solo_parent=1 AND resident_status='Active'".($bid?" AND barangay_id=$bid":''))->fetchColumn(),
    '4Ps'         => (int)$db->query("SELECT COUNT(*) FROM residents WHERE is_4ps=1 AND resident_status='Active'".($bid?" AND barangay_id=$bid":''))->fetchColumn(),
];

// ── Voter registration ─────────────────────────────────────
$voterData = ['Registered' => 0, 'Not Registered' => 0];
foreach ($db->query("SELECT voter_status, COUNT(*) c FROM residents WHERE resident_status='Active'".($bid?" AND barangay_id=$bid":'')." GROUP BY voter_status")->fetchAll() as $r)
    $voterData[$r['voter_status']] = (int)$r['c'];

// ── Certificate types ──────────────────────────────────────
$certQ = $db->query("SELECT certificate_type, COUNT(*) c FROM certificates".($bid?" WHERE barangay_id=$bid":'')." GROUP BY certificate_type ORDER BY c DESC LIMIT 6");
$certTypes = []; $certCounts = [];
foreach ($certQ->fetchAll() as $r) { $certTypes[] = $r['certificate_type']; $certCounts[] = (int)$r['c']; }

// ── Blotter status breakdown ───────────────────────────────
$blotterStatus = [];
foreach ($db->query("SELECT status, COUNT(*) c FROM blotter_cases".($bid?" WHERE barangay_id=$bid":'')." GROUP BY status")->fetchAll() as $r)
    $blotterStatus[$r['status']] = (int)$r['c'];

// ── Top 5 barangays by population ─────────────────────────
$topBrgy = $db->query("SELECT b.barangay_name,
    COUNT(r.id) as residents
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
$pageSubtitle = '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favcon.png">
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= $pageTitle ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=2.2">
    <style>
        /* ── Analytics page overrides ── */
        .main-content { background: #f0f6fb; min-height: 100vh; }

        /* KPI cards */
        .kpi-card {
          background: #ffffff;
          border-radius: 16px;
          padding: 20px 22px;
          border: 1px solid #e8eef4;
          box-shadow: 0 2px 12px rgba(0,0,0,0.06);
          transition: transform .15s, box-shadow .15s;
          height: 100%;
          display: flex;
          flex-direction: column;
          gap: 4px;
        }
        .kpi-card:hover { transform: translateY(-3px); box-shadow: 0 8px 24px rgba(0,0,0,0.10); }
        .kpi-icon-wrap {
          width: 44px; height: 44px;
          border-radius: 12px;
          display: flex; align-items: center; justify-content: center;
          font-size: 1.3rem;
          margin-bottom: 8px;
        }
        .kpi-label { font-size: 0.72rem; color: #64748b; font-weight: 500; }
        .kpi-value { font-size: 2rem; font-weight: 800; color: #0f172a; line-height: 1.1; }
        .kpi-change { font-size: 0.68rem; font-weight: 600; color: #16a34a; margin-top: 4px; }
        .kpi-change i { font-size: 0.65rem; }

        /* Chart cards */
        .chart-card {
          background: #ffffff;
          border: 1px solid #e8eef4;
          border-radius: 16px;
          overflow: hidden;
          box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .chart-card .cc-header {
          padding: 14px 18px;
          display: flex;
          align-items: center;
          justify-content: space-between;
          border-bottom: 1px solid #e8eef4;
        }
        .chart-card .cc-title {
          font-size: 0.85rem;
          font-weight: 700;
          color: #0f172a;
          display: flex;
          align-items: center;
          gap: 8px;
        }
        .chart-card .cc-body { padding: 16px 18px; }

        /* Sector mini cards */
        .sector-mini {
          background: #ffffff;
          border: 1px solid #e8eef4;
          border-radius: 14px;
          padding: 16px;
          display: flex;
          flex-direction: column;
          gap: 6px;
          box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        .sector-mini .sm-icon {
          width: 40px; height: 40px;
          border-radius: 10px;
          display: flex; align-items: center; justify-content: center;
          font-size: 1.15rem;
          margin-bottom: 4px;
        }
        .sector-mini .sm-val { font-size: 1.5rem; font-weight: 800; color: #0f172a; line-height: 1; }
        .sector-mini .sm-lbl { font-size: 0.70rem; color: #64748b; font-weight: 500; }
        .sector-mini .sm-chg { font-size: 0.65rem; font-weight: 600; color: #16a34a; }

        /* Progress bar rows */
        .brgy-bar-row { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
        .brgy-bar-row .brgy-name { font-size: 0.75rem; font-weight: 600; color: #0f172a; width: 130px; flex-shrink: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .brgy-bar-row .brgy-bar-wrap { flex: 1; background: #e8eef4; border-radius: 6px; height: 8px; }
        .brgy-bar-row .brgy-bar-fill { height: 8px; border-radius: 6px; background: #2563eb; }
        .brgy-bar-row .brgy-count { font-size: 0.72rem; font-weight: 700; color: #2563eb; width: 50px; text-align: right; flex-shrink: 0; }

        /* Section labels */
        .sec-title {
          font-size: 0.72rem; font-weight: 700;
          text-transform: uppercase; letter-spacing: .07em;
          color: #2563eb; margin-bottom: 10px;
          display: flex; align-items: center; gap: 6px;
        }
        .sec-title::after { content:''; flex:1; height:1px; background:#e8eef4; }

        /* Filter card */
        .filter-card {
          background: #ffffff;
          border: 1px solid #e8eef4;
          border-radius: 14px;
          box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }

        /* Dark mode */
        html.dark-mode .kpi-card,
        html.dark-mode .chart-card,
        html.dark-mode .sector-mini { background: #0f1f35; border-color: #1e3a5f; }
        html.dark-mode .kpi-value,
        html.dark-mode .sector-mini .sm-val { color: #c1e8ff; }
        html.dark-mode .chart-card .cc-header { border-color: #1e3a5f; }
        html.dark-mode .chart-card .cc-title { color: #c1e8ff; }
        html.dark-mode .brgy-bar-row .brgy-name { color: #c1e8ff; }
        html.dark-mode .brgy-bar-row .brgy-bar-wrap { background: #1e3a5f; }
        html.dark-mode .main-content { background: #071428; }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/sidebar_super.php'; ?>
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<div class="main-content">

    <!-- ── Filter ── -->
    <div class="filter-card mb-4">
        <div class="card-body py-2">
            <form method="GET" class="d-flex flex-wrap align-items-center gap-2">
                <label class="small fw-semibold text-muted mb-0">Date Range:</label>
                <input type="date" name="from" class="form-control form-control-sm" style="width:150px;" value="<?= htmlspecialchars($from) ?>">
                <span class="small text-muted">to</span>
                <input type="date" name="to"   class="form-control form-control-sm" style="width:150px;" value="<?= htmlspecialchars($to) ?>">
                <select name="barangay_id" class="form-select form-select-sm" style="width:190px;">
                    <option value="">All Barangays</option>
                    <?php foreach ($barangays as $b): ?>
                    <option value="<?= $b['id'] ?>" <?= $bid==$b['id']?'selected':'' ?>><?= htmlspecialchars($b['barangay_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel-fill me-1"></i>Apply</button>
                <a href="?" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-lg"></i></a>
            </form>
        </div>
    </div>

    <!-- ── KPI Cards ── -->
    <div class="sec-title"><i class="bi bi-grid-1x2-fill text-primary"></i>Key Metrics</div>
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="kpi-card">
                <div class="kpi-icon-wrap" style="background: #dbeafe;">
                    <i class="bi bi-people-fill" style="color:#1d4ed8;"></i>
                </div>
                <div class="kpi-label">Total Residents</div>
                <div class="kpi-value"><?= number_format($kpi['total_residents']) ?></div>
                <div class="kpi-change"><i class="bi bi-arrow-up-short"></i> vs. last year</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="kpi-card">
                <div class="kpi-icon-wrap" style="background: #fed7aa;">
                    <i class="bi bi-file-earmark-check-fill" style="color:#ea580c;"></i>
                </div>
                <div class="kpi-label">Certs Issued</div>
                <div class="kpi-value"><?= number_format($kpi['certs_issued']) ?></div>
                <div class="kpi-change"><i class="bi bi-arrow-up-short"></i> vs. last year</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="kpi-card">
                <div class="kpi-icon-wrap" style="background: #fee2e2;">
                    <i class="bi bi-shield-exclamation" style="color:#dc2626;"></i>
                </div>
                <div class="kpi-label">Blotter Cases</div>
                <div class="kpi-value"><?= number_format($kpi['blotter_cases']) ?></div>
                <div class="kpi-change"><i class="bi bi-arrow-up-short"></i> vs. last year</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="kpi-card">
                <div class="kpi-icon-wrap" style="background: #d1fae5;">
                    <i class="bi bi-geo-alt-fill" style="color:#059669;"></i>
                </div>
                <div class="kpi-label">Active Barangays</div>
                <div class="kpi-value"><?= number_format($kpi['active_barangays']) ?></div>
                <div class="kpi-change"><i class="bi bi-arrow-up-short"></i> vs. last year</div>
            </div>
        </div>
    </div>

    <!-- ── Trend Chart ── -->
    <div class="sec-title"><i class="bi bi-graph-up text-primary"></i>Trends (Last 12 Months)</div>
    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="chart-card">
                <div class="cc-header">
                    <span class="cc-title"><i class="bi bi-graph-up-arrow" style="color:#2563eb;"></i>Resident Registrations, Certificates &amp; Blotter Cases</span>
                </div>
                <div class="cc-body"><canvas id="trendChart" style="max-height:220px;"></canvas></div>
            </div>
        </div>
    </div>

    <!-- ── Demographics ── -->
    <div class="sec-title"><i class="bi bi-people-fill" style="color:#2563eb;"></i>Demographics</div>
    <div class="row g-3 mb-4">
        <!-- Gender donut -->
        <div class="col-md-4">
            <div class="chart-card h-100">
                <div class="cc-header">
                    <span class="cc-title"><i class="bi bi-gender-ambiguous" style="color:#2563eb;"></i>Male vs Female Distribution</span>
                </div>
                <div class="cc-body d-flex align-items-center justify-content-center">
                    <canvas id="genderChart" style="max-height:200px;"></canvas>
                </div>
            </div>
        </div>
        <!-- Voter donut -->
        <div class="col-md-4">
            <div class="chart-card h-100">
                <div class="cc-header">
                    <span class="cc-title"><i class="bi bi-person-check-fill" style="color:#f59e0b;"></i>Voter Registration Status</span>
                </div>
                <div class="cc-body d-flex align-items-center justify-content-center">
                    <canvas id="voterChart" style="max-height:200px;"></canvas>
                </div>
            </div>
        </div>
        <!-- Sector mini 2x2 -->
        <div class="col-md-4">
            <div class="sec-title mb-2" style="font-size:0.68rem;"><i class="bi bi-people" style="color:#2563eb;"></i>Sector Statistics</div>
            <div class="row g-2">
                <div class="col-6">
                    <div class="sector-mini">
                        <div class="sm-icon" style="background:#ede9fe;"><i class="bi bi-person-wheelchair" style="color:#7c3aed;"></i></div>
                        <div class="sm-val"><?= number_format($sectors['PWD']) ?></div>
                        <div class="sm-lbl">PWD Beneficiaries</div>
                        <div class="sm-chg"><i class="bi bi-arrow-up-short"></i></div>
                    </div>
                </div>
                <div class="col-6">
                    <div class="sector-mini">
                        <div class="sm-icon" style="background:#fed7aa;"><i class="bi bi-person-cane" style="color:#ea580c;"></i></div>
                        <div class="sm-val"><?= number_format($sectors['Senior']) ?></div>
                        <div class="sm-lbl">Senior Citizens</div>
                        <div class="sm-chg"><i class="bi bi-arrow-up-short"></i></div>
                    </div>
                </div>
                <div class="col-6">
                    <div class="sector-mini">
                        <div class="sm-icon" style="background:#fce7f3;"><i class="bi bi-person-heart" style="color:#db2777;"></i></div>
                        <div class="sm-val"><?= number_format($sectors['Solo Parent']) ?></div>
                        <div class="sm-lbl">Solo Parents</div>
                        <div class="sm-chg"><i class="bi bi-arrow-up-short"></i></div>
                    </div>
                </div>
                <div class="col-6">
                    <div class="sector-mini">
                        <div class="sm-icon" style="background:#d1fae5;"><i class="bi bi-house-heart-fill" style="color:#059669;"></i></div>
                        <div class="sm-val"><?= number_format($sectors['4Ps']) ?></div>
                        <div class="sm-lbl">4Ps Beneficiaries</div>
                        <div class="sm-chg"><i class="bi bi-arrow-up-short"></i></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Age Groups + Resident Status ── -->
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="chart-card h-100">
                <div class="cc-header">
                    <span class="cc-title"><i class="bi bi-calendar-person" style="color:#16a34a;"></i>Age Groups</span>
                </div>
                <div class="cc-body d-flex align-items-center justify-content-center">
                    <canvas id="ageChart" style="max-height:200px;"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="chart-card h-100">
                <div class="cc-header">
                    <span class="cc-title"><i class="bi bi-person-workspace" style="color:#dc2626;"></i>Resident Status</span>
                </div>
                <div class="cc-body d-flex align-items-center justify-content-center">
                    <canvas id="resStatusChart" style="max-height:200px;"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Employment & Sectors ── -->
    <div class="sec-title"><i class="bi bi-briefcase-fill" style="color:#f59e0b;"></i>Employment &amp; Sectors</div>
    <div class="row g-3 mb-4">
        <div class="col-md-5">
            <div class="chart-card h-100">
                <div class="cc-header">
                    <span class="cc-title"><i class="bi bi-bar-chart-fill" style="color:#2563eb;"></i>Employment Distribution</span>
                </div>
                <div class="cc-body"><canvas id="empChart" style="max-height:220px;"></canvas></div>
            </div>
        </div>
        <div class="col-md-7">
            <div class="chart-card h-100">
                <div class="cc-header">
                    <span class="cc-title"><i class="bi bi-people-fill" style="color:#0891b2;"></i>Sector Breakdown</span>
                </div>
                <div class="cc-body d-flex align-items-center justify-content-center">
                    <canvas id="sectorChart" style="max-height:220px;"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Services & Blotter ── -->
    <div class="sec-title"><i class="bi bi-file-earmark-text-fill" style="color:#2563eb;"></i>Services &amp; Blotter</div>
    <div class="row g-3 mb-4">
        <div class="col-md-5">
            <div class="chart-card h-100">
                <div class="cc-header">
                    <span class="cc-title"><i class="bi bi-file-earmark-bar-graph-fill" style="color:#16a34a;"></i>Certificates by Type</span>
                </div>
                <div class="cc-body"><canvas id="certChart" style="max-height:220px;"></canvas></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="chart-card h-100">
                <div class="cc-header">
                    <span class="cc-title"><i class="bi bi-shield-fill-exclamation" style="color:#dc2626;"></i>Blotter Cases Trend</span>
                </div>
                <div class="cc-body d-flex align-items-center justify-content-center">
                    <canvas id="blotterChart" style="max-height:220px;"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="chart-card h-100">
                <div class="cc-header">
                    <span class="cc-title"><i class="bi bi-trophy-fill" style="color:#f59e0b;"></i>Top Barangays by Population</span>
                </div>
                <div class="cc-body">
                    <?php
                    $maxPop = $topBrgy ? max(array_column($topBrgy, 'residents')) : 1;
                    foreach ($topBrgy as $i => $tb):
                        $pct = $maxPop > 0 ? round(($tb['residents'] / $maxPop) * 100) : 0;
                    ?>
                    <div class="brgy-bar-row">
                        <span class="brgy-name"><?= $i+1 ?>. <?= htmlspecialchars($tb['barangay_name']) ?></span>
                        <div class="brgy-bar-wrap">
                            <div class="brgy-bar-fill" style="width:<?= $pct ?>%;"></div>
                        </div>
                        <span class="brgy-count"><?= number_format($tb['residents']) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Login Activity ── -->
    <div class="sec-title"><i class="bi bi-activity" style="color:#dc2626;"></i>Login Activity (Last 30 Days)</div>
    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="chart-card">
                <div class="cc-header">
                    <span class="cc-title"><i class="bi bi-person-check" style="color:#16a34a;"></i>Daily Logins</span>
                </div>
                <div class="cc-body"><canvas id="loginChart" style="max-height:160px;"></canvas></div>
            </div>
        </div>
    </div>

</div><!-- /main-content -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('show'); }

// Real-time clock
(function() {
    const days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    const months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    function tick() {}
    tick(); setInterval(tick, 1000);
})();

const palette = ['#1a4f7a','#2176ae','#3a9fd8','#43a047','#e53935','#fb8c00','#8e24aa','#00897b'];
const tt = { ticks:{font:{size:10}}, grid:{color:'rgba(0,0,0,0.06)'} };
const pctTip = { callbacks:{ label: ctx => { const t=ctx.dataset.data.reduce((a,b)=>a+b,0); return ` ${ctx.label}: ${ctx.parsed.toLocaleString()} (${t?((ctx.parsed/t)*100).toFixed(1):0}%)`; }}};

// Trend
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

// Gender
new Chart(document.getElementById('genderChart'), {
    type:'doughnut',
    data:{ labels:['Male','Female'], datasets:[{ data:[<?= $genderData['Male'] ?>,<?= $genderData['Female'] ?>], backgroundColor:['#2176ae','#e91e8c'], borderWidth:2, borderColor:'#fff', hoverOffset:8 }]},
    options:{ responsive:true, plugins:{ legend:{ position:'bottom', labels:{ font:{ size:10 }, boxWidth:10 }}, tooltip:pctTip }}
});

// Age
new Chart(document.getElementById('ageChart'), {
    type:'doughnut',
    data:{ labels:<?= json_encode(array_keys($ageGroups)) ?>, datasets:[{ data:<?= json_encode(array_values($ageGroups)) ?>, backgroundColor:['#1a4f7a','#2176ae','#43a047','#fb8c00','#8e24aa'], borderWidth:2, borderColor:'#fff', hoverOffset:8 }]},
    options:{ responsive:true, plugins:{ legend:{ position:'bottom', labels:{ font:{ size:10 }, boxWidth:10 }}, tooltip:pctTip }}
});

// Voter
new Chart(document.getElementById('voterChart'), {
    type:'doughnut',
    data:{ labels:['Registered','Not Registered'], datasets:[{ data:[<?= $voterData['Registered'] ?>,<?= $voterData['Not Registered'] ?>], backgroundColor:['#1a4f7a','#e0e0e0'], borderWidth:2, borderColor:'#fff', hoverOffset:8 }]},
    options:{ responsive:true, plugins:{ legend:{ position:'bottom', labels:{ font:{ size:10 }, boxWidth:10 }}, tooltip:pctTip }}
});

// Resident Status
new Chart(document.getElementById('resStatusChart'), {
    type:'doughnut',
    data:{ labels:<?= json_encode(array_keys($resStatus)) ?>, datasets:[{ data:<?= json_encode(array_values($resStatus)) ?>, backgroundColor:['#43a047','#64748b','#2176ae','#e53935'], borderWidth:2, borderColor:'#fff', hoverOffset:8 }]},
    options:{ responsive:true, plugins:{ legend:{ position:'bottom', labels:{ font:{ size:10 }, boxWidth:10 }}, tooltip:pctTip }}
});

// Employment
new Chart(document.getElementById('empChart'), {
    type:'bar',
    data:{ labels:<?= json_encode($empStatuses) ?>, datasets:[{ data:<?= json_encode($empValues) ?>, backgroundColor:palette, borderRadius:6, borderWidth:0 }]},
    options:{ responsive:true, plugins:{ legend:{ display:false }}, scales:{ x:tt, y:{...tt, beginAtZero:true} }}
});

// Sector
new Chart(document.getElementById('sectorChart'), {
    type:'bar',
    data:{ labels:<?= json_encode(array_keys($sectors)) ?>, datasets:[{ data:<?= json_encode(array_values($sectors)) ?>, backgroundColor:['#8e24aa','#fb8c00','#e53935','#00897b'], borderRadius:6, borderWidth:0 }]},
    options:{ responsive:true, indexAxis:'y', plugins:{ legend:{ display:false }}, scales:{ x:{...tt, beginAtZero:true}, y:tt }}
});

// Certificates
new Chart(document.getElementById('certChart'), {
    type:'bar',
    data:{ labels:<?= json_encode($certTypes ?: ['No Data']) ?>, datasets:[{ data:<?= json_encode($certCounts ?: [0]) ?>, backgroundColor:palette, borderRadius:6, borderWidth:0 }]},
    options:{ responsive:true, plugins:{ legend:{ display:false }}, scales:{ x:{...tt, ticks:{...tt.ticks, maxRotation:30}}, y:{...tt, beginAtZero:true} }}
});

// Blotter
new Chart(document.getElementById('blotterChart'), {
    type:'doughnut',
    data:{ labels:<?= json_encode(array_keys($blotterStatus)) ?>, datasets:[{ data:<?= json_encode(array_values($blotterStatus)) ?>, backgroundColor:['#fb8c00','#1a4f7a','#43a047','#e53935','#64748b'], borderWidth:2, borderColor:'#fff', hoverOffset:8 }]},
    options:{ responsive:true, plugins:{ legend:{ position:'bottom', labels:{ font:{ size:9 }, boxWidth:9 }}, tooltip:pctTip }}
});

// Login Activity
new Chart(document.getElementById('loginChart'), {
    type:'bar',
    data:{ labels:<?= json_encode($loginLabels) ?>, datasets:[{ data:<?= json_encode($loginValues) ?>, backgroundColor:'rgba(26,79,122,0.7)', borderRadius:4, borderWidth:0, hoverBackgroundColor:'#1a4f7a' }]},
    options:{ responsive:true, plugins:{ legend:{ display:false }}, scales:{ x:{...tt, ticks:{...tt.ticks, maxRotation:45}}, y:{...tt, beginAtZero:true} }}
});
</script>
</body>
</html>
