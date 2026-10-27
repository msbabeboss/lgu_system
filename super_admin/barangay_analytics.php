<?php
require_once __DIR__ . '/../config/config.php';
requireRole('super_admin');

$db = Database::getInstance()->getConnection();

// ── Date range filter ──────────────────────────────────────
$from = sanitize($_GET['from'] ?? date('Y-m-01'));
$to   = sanitize($_GET['to']   ?? date('Y-m-d'));

// ── All active barangays ───────────────────────────────────
$barangays = $db->query("SELECT id, barangay_name FROM barangays WHERE status='active' ORDER BY barangay_name")->fetchAll();

// ── Shared label arrays (used in loop + JS data blocks) ───
$empStatuses = ['Employed', 'Unemployed', 'Self-Employed', 'Student', 'Retired'];
$incomeRanges = ['Below ₱10,000', '₱10,000 - ₱20,000', '₱20,001 - ₱40,000', '₱40,001 - ₱60,000', 'Above ₱60,000'];

// ────────────────────────────────────────────────────────────
// ALL BARANGAYS AGGREGATE
// ────────────────────────────────────────────────────────────
$agg = [];
$agg['total_residents']  = (int)$db->query("SELECT COUNT(*) FROM residents WHERE resident_status='Active'")->fetchColumn();
$agg['new_residents']    = (int)$db->query("SELECT COUNT(*) FROM residents WHERE DATE(created_at) BETWEEN '$from' AND '$to'")->fetchColumn();
$agg['total_households'] = (int)$db->query("SELECT COUNT(*) FROM households WHERE status='Active'")->fetchColumn();
$agg['certs_issued']     = (int)$db->query("SELECT COUNT(*) FROM certificates WHERE date_issued BETWEEN '$from' AND '$to'")->fetchColumn();
$agg['blotter_cases']    = (int)$db->query("SELECT COUNT(*) FROM blotter_cases WHERE DATE(created_at) BETWEEN '$from' AND '$to'")->fetchColumn();
$agg['active_barangays'] = (int)$db->query("SELECT COUNT(*) FROM barangays WHERE status='active'")->fetchColumn();
$agg['active_officials'] = (int)$db->query("SELECT COUNT(*) FROM officials WHERE status='active'")->fetchColumn();

// Comparison bar: each barangay resident count
$brgyNames = []; $brgyResCounts = [];
foreach ($barangays as $b) {
    $bid_tmp = (int)$b['id'];
    $brgyNames[]     = $b['barangay_name'];
    $brgyResCounts[] = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid_tmp AND resident_status='Active'")->fetchColumn();
}

// All-barangay 12-month trend
$_latestRes  = $db->query("SELECT MAX(created_at) FROM residents")->fetchColumn();
$_latestCert = $db->query("SELECT MAX(date_issued) FROM certificates")->fetchColumn();
$_latestBlot = $db->query("SELECT MAX(created_at) FROM blotter_cases")->fetchColumn();
$_latestDates = array_filter([$_latestRes, $_latestCert, $_latestBlot]);
$_anchorTs    = $_latestDates ? strtotime(max($_latestDates)) : time();

$allMonthLabels = []; $allMonthNewRes = []; $allMonthCerts = []; $allMonthBlotter = [];
for ($i = 11; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i months", $_anchorTs));
    $allMonthLabels[]  = date('M Y', strtotime("-$i months", $_anchorTs));
    $allMonthNewRes[]  = (int)$db->query("SELECT COUNT(*) FROM residents WHERE DATE_FORMAT(created_at,'%Y-%m')='$m'")->fetchColumn();
    $allMonthCerts[]   = (int)$db->query("SELECT COUNT(*) FROM certificates WHERE DATE_FORMAT(date_issued,'%Y-%m')='$m'")->fetchColumn();
    $allMonthBlotter[] = (int)$db->query("SELECT COUNT(*) FROM blotter_cases WHERE DATE_FORMAT(created_at,'%Y-%m')='$m'")->fetchColumn();
}

// ────────────────────────────────────────────────────────────
// PER-BARANGAY DATA
// ────────────────────────────────────────────────────────────
$brgyData = [];
foreach ($barangays as $b) {
    $bid = (int)$b['id'];
    $d = [];

    // KPI
    $d['total_residents']  = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND resident_status='Active'")->fetchColumn();
    $d['new_residents']    = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND DATE(created_at) BETWEEN '$from' AND '$to'")->fetchColumn();
    $d['total_households'] = (int)$db->query("SELECT COUNT(*) FROM households WHERE barangay_id=$bid AND status='Active'")->fetchColumn();
    $d['certs_issued']     = (int)$db->query("SELECT COUNT(*) FROM certificates WHERE barangay_id=$bid AND date_issued BETWEEN '$from' AND '$to'")->fetchColumn();
    $d['blotter_cases']    = (int)$db->query("SELECT COUNT(*) FROM blotter_cases WHERE barangay_id=$bid AND DATE(created_at) BETWEEN '$from' AND '$to'")->fetchColumn();
    $d['officials']        = (int)$db->query("SELECT COUNT(*) FROM officials WHERE barangay_id=$bid AND status='active'")->fetchColumn();

    // 12-month trend (anchor to latest record in that barangay)
    $latestResident = $db->query("SELECT MAX(created_at) FROM residents WHERE barangay_id=$bid")->fetchColumn();
    $latestCert     = $db->query("SELECT MAX(date_issued) FROM certificates WHERE barangay_id=$bid")->fetchColumn();
    $latestBlotter  = $db->query("SELECT MAX(created_at) FROM blotter_cases WHERE barangay_id=$bid")->fetchColumn();
    $latestDates    = array_filter([$latestResident, $latestCert, $latestBlotter]);
    $anchorTs       = $latestDates ? strtotime(max($latestDates)) : time();

    $d['monthLabels'] = []; $d['monthNewRes'] = []; $d['monthCerts'] = []; $d['monthBlotter'] = [];
    for ($i = 11; $i >= 0; $i--) {
        $m = date('Y-m', strtotime("-$i months", $anchorTs));
        $d['monthLabels'][]  = date('M Y', strtotime("-$i months", $anchorTs));
        $d['monthNewRes'][]  = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND DATE_FORMAT(created_at,'%Y-%m')='$m'")->fetchColumn();
        $d['monthCerts'][]   = (int)$db->query("SELECT COUNT(*) FROM certificates WHERE barangay_id=$bid AND DATE_FORMAT(date_issued,'%Y-%m')='$m'")->fetchColumn();
        $d['monthBlotter'][] = (int)$db->query("SELECT COUNT(*) FROM blotter_cases WHERE barangay_id=$bid AND DATE_FORMAT(created_at,'%Y-%m')='$m'")->fetchColumn();
    }

    // Gender
    $genderData = ['Male' => 0, 'Female' => 0];
    foreach ($db->query("SELECT gender, COUNT(*) c FROM residents WHERE barangay_id=$bid AND resident_status='Active' GROUP BY gender")->fetchAll() as $r)
        $genderData[$r['gender']] = (int)$r['c'];
    $d['genderMale']   = $genderData['Male'];
    $d['genderFemale'] = $genderData['Female'];

    // Age groups
    $d['ageGroups'] = ['0-12' => 0, '13-17' => 0, '18-35' => 0, '36-59' => 0, '60+' => 0];
    foreach ($db->query("SELECT birthdate FROM residents WHERE barangay_id=$bid AND resident_status='Active' AND birthdate IS NOT NULL")->fetchAll() as $r) {
        $age = (int)date_diff(date_create($r['birthdate']), date_create())->y;
        if      ($age <= 12) $d['ageGroups']['0-12']++;
        elseif  ($age <= 17) $d['ageGroups']['13-17']++;
        elseif  ($age <= 35) $d['ageGroups']['18-35']++;
        elseif  ($age <= 59) $d['ageGroups']['36-59']++;
        else                 $d['ageGroups']['60+']++;
    }

    // Voter status
    $voterData = ['Registered' => 0, 'Not Registered' => 0];
    foreach ($db->query("SELECT voter_status, COUNT(*) c FROM residents WHERE barangay_id=$bid AND resident_status='Active' GROUP BY voter_status")->fetchAll() as $r)
        $voterData[$r['voter_status']] = (int)$r['c'];
    $d['voterRegistered']    = $voterData['Registered'];
    $d['voterNotRegistered'] = $voterData['Not Registered'];

    // Resident status
    $d['resStatus'] = [];
    foreach ($db->query("SELECT resident_status, COUNT(*) c FROM residents WHERE barangay_id=$bid GROUP BY resident_status")->fetchAll() as $r)
        $d['resStatus'][$r['resident_status']] = (int)$r['c'];

    // Employment
    $d['empValues'] = [];
    foreach ($empStatuses as $es)
        $d['empValues'][] = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND occupation_status='$es' AND resident_status='Active'")->fetchColumn();

    // Sectors
    $d['sectors'] = [
        'PWD'         => (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND is_pwd=1 AND resident_status='Active'")->fetchColumn(),
        'Senior'      => (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND is_senior=1 AND resident_status='Active'")->fetchColumn(),
        'Solo Parent' => (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND is_solo_parent=1 AND resident_status='Active'")->fetchColumn(),
        '4Ps'         => (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND is_4ps=1 AND resident_status='Active'")->fetchColumn(),
    ];

    // Certificate types (top 6)
    $d['certTypes'] = []; $d['certCounts'] = [];
    foreach ($db->query("SELECT certificate_type, COUNT(*) c FROM certificates WHERE barangay_id=$bid GROUP BY certificate_type ORDER BY c DESC LIMIT 6")->fetchAll() as $r) {
        $d['certTypes'][]  = $r['certificate_type'];
        $d['certCounts'][] = (int)$r['c'];
    }

    // Blotter by status
    $d['blotterStatus'] = [];
    foreach ($db->query("SELECT status, COUNT(*) c FROM blotter_cases WHERE barangay_id=$bid GROUP BY status")->fetchAll() as $r)
        $d['blotterStatus'][$r['status']] = (int)$r['c'];

    // Household income
    $d['incomeCounts'] = [];
    foreach ($incomeRanges as $ir) {
        $s = $db->prepare("SELECT COUNT(*) FROM households WHERE barangay_id=? AND income_range=? AND status='Active'");
        $s->execute([$bid, $ir]);
        $d['incomeCounts'][] = (int)$s->fetchColumn();
    }

    // Officials by position
    $d['officialsByPos'] = [];
    foreach ($db->query("SELECT position, COUNT(*) c FROM officials WHERE barangay_id=$bid AND status='active' GROUP BY position ORDER BY c DESC")->fetchAll() as $r)
        $d['officialsByPos'][$r['position']] = (int)$r['c'];

    $brgyData[$bid] = $d;
}

$pageIcon     = 'bi-bar-chart-steps';
$pageTitle    = 'Brgy Analytics';
$pageSubtitle = 'Per-barangay analytics and aggregate overview.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favcon.png">
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= htmlspecialchars($pageTitle) ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=3.0">
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

        /* Tab-specific */
        .brgy-tab-panel { padding: 0; }
        .tab-section-divider { border-top: 1.5px solid #e8eef4; margin: 24px 0 16px; }

        /* Dark mode */
        html.dark-mode .kpi-card,
        html.dark-mode .chart-card,
        html.dark-mode .sector-mini { background: #0f1f35; border-color: #1e3a5f; }
        html.dark-mode .kpi-value,
        html.dark-mode .sector-mini .sm-val { color: #c1e8ff; }
        html.dark-mode .chart-card .cc-header { border-color: #1e3a5f; }
        html.dark-mode .chart-card .cc-title { color: #c1e8ff; }
        html.dark-mode .main-content { background: #071428; }
        html.dark-mode .filter-card { background: #0f1f35; border-color: #1e3a5f; }
        html.dark-mode .nav-tabs .nav-link { color: #94a3b8; }
        html.dark-mode .nav-tabs .nav-link.active { color: #c1e8ff; background: #0f1f35; border-color: #1e3a5f #1e3a5f #0f1f35; }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/sidebar_super.php'; ?>
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<div class="main-content">

    <!-- ── Date range filter ── -->
    <div class="filter-card mb-4">
        <div class="card-body py-2 px-3">
            <form method="GET" class="d-flex flex-wrap align-items-center gap-2">
                <label class="small fw-semibold text-muted mb-0">Date Range:</label>
                <input type="date" name="from" class="form-control form-control-sm" style="width:150px;" value="<?= htmlspecialchars($from) ?>">
                <span class="small text-muted">to</span>
                <input type="date" name="to"   class="form-control form-control-sm" style="width:150px;" value="<?= htmlspecialchars($to) ?>">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel-fill me-1"></i>Apply</button>
                <a href="?" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-lg"></i></a>
            </form>
        </div>
    </div>

    <!-- ── Nav Tabs ── -->
    <ul class="nav nav-tabs mb-0" id="brgyTabs" role="tablist" style="border-bottom:2px solid #e8eef4; flex-wrap:nowrap; overflow-x:auto; overflow-y:hidden; -webkit-overflow-scrolling:touch;">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tab-all-tab" data-bs-toggle="tab" data-bs-target="#tab-all" type="button" role="tab" style="white-space:nowrap;">
                <i class="bi bi-globe2 me-1"></i>All Barangays
            </button>
        </li>
        <?php foreach ($barangays as $b): ?>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-<?= (int)$b['id'] ?>-tab" data-bs-toggle="tab" data-bs-target="#tab-<?= (int)$b['id'] ?>" type="button" role="tab" style="white-space:nowrap;">
                <i class="bi bi-geo-alt-fill me-1"></i><?= htmlspecialchars($b['barangay_name']) ?>
            </button>
        </li>
        <?php endforeach; ?>
    </ul>

    <div class="tab-content pt-4" id="brgyTabsContent">

        <!-- ════════════════════════════════════════════════
             ALL BARANGAYS TAB
        ════════════════════════════════════════════════ -->
        <div class="tab-pane fade show active brgy-tab-panel" id="tab-all" role="tabpanel" aria-labelledby="tab-all-tab">

            <!-- KPI Cards -->
            <div class="sec-title"><i class="bi bi-grid-1x2-fill text-primary"></i>Key Metrics — All Barangays</div>
            <div class="row g-3 mb-4">
                <?php
                $aggKpis = [
                    ['label'=>'Total Residents',   'val'=>$agg['total_residents'],  'icon'=>'bi-people-fill',             'ic'=>'#1d4ed8','bg'=>'#dbeafe'],
                    ['label'=>'New Residents',      'val'=>$agg['new_residents'],    'icon'=>'bi-person-plus-fill',        'ic'=>'#065f46','bg'=>'#d1fae5'],
                    ['label'=>'Active Households',  'val'=>$agg['total_households'], 'icon'=>'bi-house-fill',              'ic'=>'#0369a1','bg'=>'#e0f2fe'],
                    ['label'=>'Certs Issued',       'val'=>$agg['certs_issued'],     'icon'=>'bi-file-earmark-check-fill', 'ic'=>'#6d28d9','bg'=>'#ede9fe'],
                    ['label'=>'Blotter Cases',      'val'=>$agg['blotter_cases'],    'icon'=>'bi-shield-exclamation',      'ic'=>'#dc2626','bg'=>'#fee2e2'],
                    ['label'=>'Active Barangays',   'val'=>$agg['active_barangays'], 'icon'=>'bi-geo-alt-fill',            'ic'=>'#059669','bg'=>'#d1fae5'],
                    ['label'=>'Active Officials',   'val'=>$agg['active_officials'], 'icon'=>'bi-person-badge-fill',       'ic'=>'#92400e','bg'=>'#fef3c7'],
                ];
                foreach ($aggKpis as $k): ?>
                <div class="col-6 col-md-4 col-lg-3 col-xl-auto flex-xl-fill">
                    <div class="kpi-card">
                        <div class="kpi-icon-wrap" style="background:<?= $k['bg'] ?>;">
                            <i class="bi <?= $k['icon'] ?>" style="color:<?= $k['ic'] ?>;"></i>
                        </div>
                        <div class="kpi-label"><?= $k['label'] ?></div>
                        <div class="kpi-value"><?= number_format($k['val']) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Comparison Bar Chart -->
            <div class="sec-title"><i class="bi bi-bar-chart-fill text-primary"></i>Resident Count by Barangay</div>
            <div class="row g-3 mb-4">
                <div class="col-12">
                    <div class="chart-card">
                        <div class="cc-header">
                            <span class="cc-title"><i class="bi bi-bar-chart-fill" style="color:#2563eb;"></i>All Barangays — Active Residents Comparison</span>
                        </div>
                        <div class="cc-body">
                            <canvas id="brgyCompareChart" style="max-height:280px;"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 12-Month Trend (All) -->
            <div class="sec-title"><i class="bi bi-graph-up text-success"></i>12-Month Trend — All Barangays Combined</div>
            <div class="row g-3 mb-4">
                <div class="col-12">
                    <div class="chart-card">
                        <div class="cc-header">
                            <span class="cc-title"><i class="bi bi-graph-up-arrow" style="color:#2563eb;"></i>Resident Registrations, Certificates &amp; Blotter Cases</span>
                        </div>
                        <div class="cc-body">
                            <canvas id="allTrendChart" style="max-height:220px;"></canvas>
                        </div>
                    </div>
                </div>
            </div>

        </div><!-- /tab-all -->

        <!-- ════════════════════════════════════════════════
             PER-BARANGAY TABS
        ════════════════════════════════════════════════ -->
        <?php foreach ($barangays as $b):
            $bid = (int)$b['id'];
            $d   = $brgyData[$bid];
            $bName = htmlspecialchars($b['barangay_name']);
        ?>
        <div class="tab-pane fade brgy-tab-panel" id="tab-<?= $bid ?>" role="tabpanel" aria-labelledby="tab-<?= $bid ?>-tab">

            <!-- KPI Cards -->
            <div class="sec-title"><i class="bi bi-grid-1x2-fill text-primary"></i>Key Metrics — <?= $bName ?></div>
            <div class="row g-3 mb-4">
                <?php
                $brgyKpis = [
                    ['label'=>'Total Residents',   'val'=>$d['total_residents'],  'icon'=>'bi-people-fill',             'ic'=>'#1d4ed8','bg'=>'#dbeafe'],
                    ['label'=>'New Residents',      'val'=>$d['new_residents'],    'icon'=>'bi-person-plus-fill',        'ic'=>'#065f46','bg'=>'#d1fae5'],
                    ['label'=>'Active Households',  'val'=>$d['total_households'], 'icon'=>'bi-house-fill',              'ic'=>'#0369a1','bg'=>'#e0f2fe'],
                    ['label'=>'Certs Issued',       'val'=>$d['certs_issued'],     'icon'=>'bi-file-earmark-check-fill', 'ic'=>'#6d28d9','bg'=>'#ede9fe'],
                    ['label'=>'Blotter Cases',      'val'=>$d['blotter_cases'],    'icon'=>'bi-shield-exclamation',      'ic'=>'#dc2626','bg'=>'#fee2e2'],
                    ['label'=>'Active Officials',   'val'=>$d['officials'],        'icon'=>'bi-person-badge-fill',       'ic'=>'#92400e','bg'=>'#fef3c7'],
                ];
                foreach ($brgyKpis as $k): ?>
                <div class="col-6 col-md-4 col-lg-2 d-flex">
                    <div class="kpi-card w-100">
                        <div class="kpi-icon-wrap" style="background:<?= $k['bg'] ?>;">
                            <i class="bi <?= $k['icon'] ?>" style="color:<?= $k['ic'] ?>;"></i>
                        </div>
                        <div class="kpi-label"><?= $k['label'] ?></div>
                        <div class="kpi-value"><?= number_format($k['val']) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- 12-Month Trend -->
            <div class="sec-title"><i class="bi bi-graph-up text-success"></i>Trends (12 Months)</div>
            <div class="row g-3 mb-4">
                <div class="col-12">
                    <div class="chart-card">
                        <div class="cc-header">
                            <span class="cc-title"><i class="bi bi-graph-up-arrow" style="color:#2563eb;"></i>Resident Registrations, Certificates &amp; Blotter Cases</span>
                        </div>
                        <div class="cc-body">
                            <canvas id="brgy-<?= $bid ?>-trend" style="max-height:220px;display:block;"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Demographics -->
            <div class="sec-title"><i class="bi bi-people-fill" style="color:#2563eb;"></i>Demographics</div>
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="chart-card h-100">
                        <div class="cc-header"><span class="cc-title"><i class="bi bi-gender-ambiguous" style="color:#2563eb;"></i>Gender</span></div>
                        <div class="cc-body d-flex align-items-center justify-content-center">
                            <canvas id="brgy-<?= $bid ?>-gender" style="max-height:180px;display:block;"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="chart-card h-100">
                        <div class="cc-header"><span class="cc-title"><i class="bi bi-calendar-person" style="color:#16a34a;"></i>Age Groups</span></div>
                        <div class="cc-body d-flex align-items-center justify-content-center">
                            <canvas id="brgy-<?= $bid ?>-age" style="max-height:180px;display:block;"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="chart-card h-100">
                        <div class="cc-header"><span class="cc-title"><i class="bi bi-person-check-fill" style="color:#f59e0b;"></i>Voter Status</span></div>
                        <div class="cc-body d-flex align-items-center justify-content-center">
                            <canvas id="brgy-<?= $bid ?>-voter" style="max-height:180px;display:block;"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="chart-card h-100">
                        <div class="cc-header"><span class="cc-title"><i class="bi bi-person-workspace" style="color:#dc2626;"></i>Resident Status</span></div>
                        <div class="cc-body d-flex align-items-center justify-content-center">
                            <canvas id="brgy-<?= $bid ?>-resstatus" style="max-height:180px;display:block;"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Employment & Sectors -->
            <div class="sec-title"><i class="bi bi-briefcase-fill" style="color:#f59e0b;"></i>Employment &amp; Sectors</div>
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <div class="chart-card h-100">
                        <div class="cc-header"><span class="cc-title"><i class="bi bi-bar-chart-fill" style="color:#2563eb;"></i>Employment Status</span></div>
                        <div class="cc-body">
                            <canvas id="brgy-<?= $bid ?>-emp" style="max-height:200px;display:block;"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="chart-card h-100">
                        <div class="cc-header"><span class="cc-title"><i class="bi bi-people-fill" style="color:#0891b2;"></i>Sector Breakdown</span></div>
                        <div class="cc-body d-flex align-items-center justify-content-center">
                            <canvas id="brgy-<?= $bid ?>-sector" style="max-height:200px;display:block;"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Services & Blotter -->
            <div class="sec-title"><i class="bi bi-file-earmark-text-fill" style="color:#2563eb;"></i>Services &amp; Blotter</div>
            <div class="row g-3 mb-4">
                <div class="col-md-5">
                    <div class="chart-card h-100">
                        <div class="cc-header"><span class="cc-title"><i class="bi bi-file-earmark-bar-graph-fill" style="color:#16a34a;"></i>Certificates by Type</span></div>
                        <div class="cc-body">
                            <canvas id="brgy-<?= $bid ?>-certs" style="max-height:200px;display:block;"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="chart-card h-100">
                        <div class="cc-header"><span class="cc-title"><i class="bi bi-shield-fill-exclamation" style="color:#dc2626;"></i>Blotter by Status</span></div>
                        <div class="cc-body d-flex align-items-center justify-content-center">
                            <canvas id="brgy-<?= $bid ?>-blotter" style="max-height:200px;display:block;"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="chart-card h-100">
                        <div class="cc-header"><span class="cc-title"><i class="bi bi-currency-exchange" style="color:#f59e0b;"></i>Household Income</span></div>
                        <div class="cc-body">
                            <canvas id="brgy-<?= $bid ?>-income" style="max-height:200px;display:block;"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Officials -->
            <div class="sec-title"><i class="bi bi-person-badge-fill text-primary"></i>Officials by Position</div>
            <div class="row g-3 mb-4">
                <div class="col-12">
                    <div class="chart-card">
                        <div class="cc-header"><span class="cc-title"><i class="bi bi-person-badge-fill" style="color:#2563eb;"></i>Officials by Position — <?= $bName ?></span></div>
                        <div class="cc-body">
                            <canvas id="brgy-<?= $bid ?>-officials" style="max-height:200px;display:block;"></canvas>
                        </div>
                    </div>
                </div>
            </div>

        </div><!-- /tab-<?= $bid ?> -->

        <!-- JS data for this barangay -->
        <script>
        window.brgyChartData = window.brgyChartData || {};
        window.brgyChartData[<?= $bid ?>] = {
            monthLabels:    <?= json_encode($d['monthLabels']) ?>,
            monthNewRes:    <?= json_encode($d['monthNewRes']) ?>,
            monthCerts:     <?= json_encode($d['monthCerts']) ?>,
            monthBlotter:   <?= json_encode($d['monthBlotter']) ?>,
            genderMale:     <?= (int)$d['genderMale'] ?>,
            genderFemale:   <?= (int)$d['genderFemale'] ?>,
            ageLabels:      <?= json_encode(array_keys($d['ageGroups'])) ?>,
            ageData:        <?= json_encode(array_values($d['ageGroups'])) ?>,
            voterReg:       <?= (int)$d['voterRegistered'] ?>,
            voterNoReg:     <?= (int)$d['voterNotRegistered'] ?>,
            resStatusLabels:<?= json_encode(array_keys($d['resStatus'] ?: ['No Data' => 0])) ?>,
            resStatusData:  <?= json_encode(array_values($d['resStatus'] ?: [0])) ?>,
            empLabels:      <?= json_encode($empStatuses) ?>,
            empData:        <?= json_encode($d['empValues']) ?>,
            sectorLabels:   <?= json_encode(array_keys($d['sectors'])) ?>,
            sectorData:     <?= json_encode(array_values($d['sectors'])) ?>,
            certLabels:     <?= json_encode($d['certTypes'] ?: ['No Data']) ?>,
            certData:       <?= json_encode($d['certCounts'] ?: [0]) ?>,
            blotterLabels:  <?= json_encode(array_keys($d['blotterStatus'] ?: ['No Data' => 0])) ?>,
            blotterData:    <?= json_encode(array_values($d['blotterStatus'] ?: [0])) ?>,
            incomeLabels:   <?= json_encode($incomeRanges) ?>,
            incomeData:     <?= json_encode($d['incomeCounts']) ?>,
            officialLabels: <?= json_encode(array_keys($d['officialsByPos'] ?: ['No Data' => 0])) ?>,
            officialData:   <?= json_encode(array_values($d['officialsByPos'] ?: [0])) ?>
        };
        </script>

        <?php endforeach; ?>

    </div><!-- /tab-content -->
</div><!-- /main-content -->

<script defer src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('show'); }

// ── Chart helper constants ────────────────────────────────
const palette = ['#1a4f7a','#2176ae','#3a9fd8','#43a047','#e53935','#fb8c00','#8e24aa','#00897b'];
const tt = { ticks:{font:{size:10}}, grid:{color:'rgba(0,0,0,0.06)'} };
const pctTip = { callbacks:{ label: ctx => {
    const t = ctx.dataset.data.reduce((a,b) => a+b, 0);
    return ` ${ctx.label}: ${ctx.parsed.toLocaleString()} (${t ? ((ctx.parsed/t)*100).toFixed(1) : 0}%)`;
}}};

// ── Lazy init tracking ────────────────────────────────────
const rendered = {};

function initTab(tabId) {
    if (rendered[tabId]) return;
    rendered[tabId] = true;
    if (tabId === 'tab-all') {
        initAllTab();
    } else {
        const id = tabId.replace('tab-', '');
        initBrgyTab(id);
    }
}

// ── Init "All Barangays" charts ───────────────────────────
function initAllTab() {
    // Comparison bar
    new Chart(document.getElementById('brgyCompareChart'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($brgyNames) ?>,
            datasets: [{
                label: 'Active Residents',
                data: <?= json_encode($brgyResCounts) ?>,
                backgroundColor: '#2563eb',
                borderRadius: 6,
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                x: { ...tt, ticks: { ...tt.ticks, maxRotation: 30 } },
                y: { ...tt, beginAtZero: true }
            }
        }
    });

    // 12-month trend (all barangays)
    new Chart(document.getElementById('allTrendChart'), {
        type: 'line',
        data: {
            labels: <?= json_encode($allMonthLabels) ?>,
            datasets: [
                { label:'New Residents', data:<?= json_encode($allMonthNewRes) ?>,   borderColor:'#1a4f7a', backgroundColor:'rgba(26,79,122,0.08)',  tension:.4, fill:true, pointRadius:3, pointBackgroundColor:'#1a4f7a' },
                { label:'Certificates',  data:<?= json_encode($allMonthCerts) ?>,    borderColor:'#43a047', backgroundColor:'rgba(67,160,71,0.07)',   tension:.4, fill:true, pointRadius:3, pointBackgroundColor:'#43a047' },
                { label:'Blotter Cases', data:<?= json_encode($allMonthBlotter) ?>,  borderColor:'#e53935', backgroundColor:'rgba(229,57,53,0.06)',   tension:.4, fill:true, pointRadius:3, pointBackgroundColor:'#e53935' }
            ]
        },
        options: {
            responsive: true,
            plugins: { legend: { position:'top', labels:{ font:{ size:11 }, boxWidth:12 }}},
            scales: { x: tt, y: { ...tt, beginAtZero: true } }
        }
    });
}

// ── Init individual barangay charts ───────────────────────
function initBrgyTab(id) {
    const cd = window.brgyChartData[id];
    if (!cd) return;
    const p = 'brgy-' + id + '-';

    // Trend
    new Chart(document.getElementById(p + 'trend'), {
        type: 'line',
        data: {
            labels: cd.monthLabels,
            datasets: [
                { label:'New Residents', data: cd.monthNewRes,  borderColor:'#1a4f7a', backgroundColor:'rgba(26,79,122,0.08)',  tension:.4, fill:true, pointRadius:3, pointBackgroundColor:'#1a4f7a' },
                { label:'Certificates',  data: cd.monthCerts,   borderColor:'#43a047', backgroundColor:'rgba(67,160,71,0.07)',   tension:.4, fill:true, pointRadius:3, pointBackgroundColor:'#43a047' },
                { label:'Blotter Cases', data: cd.monthBlotter, borderColor:'#e53935', backgroundColor:'rgba(229,57,53,0.06)',   tension:.4, fill:true, pointRadius:3, pointBackgroundColor:'#e53935' }
            ]
        },
        options: {
            responsive: true,
            plugins: { legend: { position:'top', labels:{ font:{ size:11 }, boxWidth:12 }}},
            scales: { x: tt, y: { ...tt, beginAtZero: true } }
        }
    });

    // Gender
    new Chart(document.getElementById(p + 'gender'), {
        type: 'doughnut',
        data: { labels:['Male','Female'], datasets:[{ data:[cd.genderMale, cd.genderFemale], backgroundColor:['#2176ae','#e91e8c'], borderWidth:2, borderColor:'#fff', hoverOffset:8 }]},
        options: { responsive: true, plugins: { legend:{ position:'bottom', labels:{ font:{size:10}, boxWidth:10 }}, tooltip: pctTip }}
    });

    // Age
    new Chart(document.getElementById(p + 'age'), {
        type: 'doughnut',
        data: { labels: cd.ageLabels, datasets:[{ data: cd.ageData, backgroundColor:['#1a4f7a','#2176ae','#43a047','#fb8c00','#8e24aa'], borderWidth:2, borderColor:'#fff', hoverOffset:8 }]},
        options: { responsive: true, plugins: { legend:{ position:'bottom', labels:{ font:{size:10}, boxWidth:10 }}, tooltip: pctTip }}
    });

    // Voter
    new Chart(document.getElementById(p + 'voter'), {
        type: 'doughnut',
        data: { labels:['Registered','Not Registered'], datasets:[{ data:[cd.voterReg, cd.voterNoReg], backgroundColor:['#1a4f7a','#e0e0e0'], borderWidth:2, borderColor:'#fff', hoverOffset:8 }]},
        options: { responsive: true, plugins: { legend:{ position:'bottom', labels:{ font:{size:10}, boxWidth:10 }}, tooltip: pctTip }}
    });

    // Resident Status
    new Chart(document.getElementById(p + 'resstatus'), {
        type: 'doughnut',
        data: { labels: cd.resStatusLabels, datasets:[{ data: cd.resStatusData, backgroundColor:['#43a047','#64748b','#2176ae','#e53935'], borderWidth:2, borderColor:'#fff', hoverOffset:8 }]},
        options: { responsive: true, plugins: { legend:{ position:'bottom', labels:{ font:{size:10}, boxWidth:10 }}, tooltip: pctTip }}
    });

    // Employment
    new Chart(document.getElementById(p + 'emp'), {
        type: 'bar',
        data: { labels: cd.empLabels, datasets:[{ data: cd.empData, backgroundColor: palette, borderRadius:6, borderWidth:0 }]},
        options: { responsive: true, plugins:{ legend:{ display:false }}, scales:{ x: tt, y:{ ...tt, beginAtZero:true }}}
    });

    // Sectors
    new Chart(document.getElementById(p + 'sector'), {
        type: 'bar',
        data: { labels: cd.sectorLabels, datasets:[{ data: cd.sectorData, backgroundColor:['#8e24aa','#fb8c00','#e53935','#00897b'], borderRadius:6, borderWidth:0 }]},
        options: { responsive: true, indexAxis:'y', plugins:{ legend:{ display:false }}, scales:{ x:{ ...tt, beginAtZero:true }, y: tt }}
    });

    // Certificates
    new Chart(document.getElementById(p + 'certs'), {
        type: 'bar',
        data: { labels: cd.certLabels, datasets:[{ data: cd.certData, backgroundColor: palette, borderRadius:6, borderWidth:0 }]},
        options: { responsive: true, plugins:{ legend:{ display:false }}, scales:{ x:{ ...tt, ticks:{ ...tt.ticks, maxRotation:30 }}, y:{ ...tt, beginAtZero:true }}}
    });

    // Blotter
    new Chart(document.getElementById(p + 'blotter'), {
        type: 'doughnut',
        data: { labels: cd.blotterLabels, datasets:[{ data: cd.blotterData, backgroundColor:['#fb8c00','#1a4f7a','#43a047','#e53935','#64748b'], borderWidth:2, borderColor:'#fff', hoverOffset:8 }]},
        options: { responsive: true, plugins: { legend:{ position:'bottom', labels:{ font:{size:9}, boxWidth:9 }}, tooltip: pctTip }}
    });

    // Income
    new Chart(document.getElementById(p + 'income'), {
        type: 'bar',
        data: { labels: cd.incomeLabels, datasets:[{ data: cd.incomeData, backgroundColor:'rgba(26,79,122,0.75)', borderRadius:6, borderWidth:0, hoverBackgroundColor:'#1a4f7a' }]},
        options: { responsive: true, plugins:{ legend:{ display:false }}, scales:{ x:{ ...tt, ticks:{ ...tt.ticks, maxRotation:30 }}, y:{ ...tt, beginAtZero:true }}}
    });

    // Officials
    new Chart(document.getElementById(p + 'officials'), {
        type: 'bar',
        data: { labels: cd.officialLabels, datasets:[{ data: cd.officialData, backgroundColor: palette, borderRadius:6, borderWidth:0 }]},
        options: { responsive: true, indexAxis:'y', plugins:{ legend:{ display:false }}, scales:{ x:{ ...tt, beginAtZero:true }, y: tt }}
    });
}

// ── Bootstrap tab event listeners ────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    // Init the initially active tab
    initTab('tab-all');

    // Init others on first show
    document.querySelectorAll('#brgyTabs button[data-bs-toggle="tab"]').forEach(function (btn) {
        btn.addEventListener('shown.bs.tab', function (e) {
            const paneId = e.target.getAttribute('data-bs-target').slice(1);
            initTab(paneId);
        });
    });
});
</script>
</body>
</html>
