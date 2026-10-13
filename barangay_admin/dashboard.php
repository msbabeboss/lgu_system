<?php
require_once __DIR__ . '/../config/config.php';
requireRole('barangay_admin');

$db = Database::getInstance()->getConnection();
$u  = currentUser();
$bid = (int)$u['barangay_id'];

$brgy = $db->prepare("SELECT * FROM barangays WHERE id=?");
$brgy->execute([$bid]); $brgy = $brgy->fetch();

// ── Overview ──────────────────────────────────────────────
$q = fn($sql,$p=[]) => (function() use($db,$sql,$p){ $s=$db->prepare($sql);$s->execute($p);return $s->fetchColumn(); })();
$stats['households']  = $q("SELECT COUNT(*) FROM households WHERE barangay_id=? AND status='Active'",[$bid]);
$stats['blotter']     = $q("SELECT COUNT(*) FROM blotter_cases WHERE barangay_id=? AND status != 'Archived'",[$bid]);
$stats['certs']       = $q("SELECT COUNT(*) FROM certificates WHERE barangay_id=?",[$bid]);

// ── Consolidated residents counts (replaces 8 individual queries) ─────
$resCountStmt = $db->prepare(
    "SELECT
        SUM(CASE WHEN resident_status='Active'       THEN 1 ELSE 0 END) AS active,
        SUM(CASE WHEN resident_status='Inactive'     THEN 1 ELSE 0 END) AS inactive,
        SUM(CASE WHEN resident_status='Transferred'  THEN 1 ELSE 0 END) AS transferred,
        SUM(CASE WHEN is_pwd=1         AND resident_status='Active' THEN 1 ELSE 0 END) AS pwd,
        SUM(CASE WHEN is_senior=1      AND resident_status='Active' THEN 1 ELSE 0 END) AS senior,
        SUM(CASE WHEN is_solo_parent=1 AND resident_status='Active' THEN 1 ELSE 0 END) AS solo,
        SUM(CASE WHEN is_4ps=1         AND resident_status='Active' THEN 1 ELSE 0 END) AS fours
     FROM residents
     WHERE barangay_id=?"
);
$resCountStmt->execute([$bid]);
$rc = $resCountStmt->fetch();

$stats['residents']       = (int)$rc['active'];
$stats['res_active']      = $stats['residents'];
$stats['res_inactive']    = (int)$rc['inactive'];
$stats['res_transferred'] = (int)$rc['transferred'];
$stats['pwd']             = (int)$rc['pwd'];
$stats['senior']          = (int)$rc['senior'];
$stats['solo']            = (int)$rc['solo'];
$stats['4ps']             = (int)$rc['fours'];

// Deceased = residents with status 'Deceased' + legacy archived records where archive_reason indicates deceased
$stats['res_deceased'] = $q(
    "SELECT COUNT(*) FROM (
        SELECT r.id FROM residents r WHERE r.barangay_id=? AND r.resident_status='Deceased'
        UNION
        SELECT a.record_id FROM archives a
        INNER JOIN residents r ON r.id=a.record_id AND r.barangay_id=?
        WHERE a.table_name='residents'
          AND r.resident_status='Inactive'
          AND a.data_json LIKE '%Deceased%'
    ) AS deceased_combined",
    [$bid, $bid]
);

// ── Officials ─────────────────────────────────────────────
$stats['officials_active']  = $q("SELECT COUNT(*) FROM officials WHERE barangay_id=? AND status='active'",[$bid]);
$stats['officials_expired'] = $q("SELECT COUNT(*) FROM officials WHERE barangay_id=? AND status='active' AND term_end < CURDATE()",[$bid]);

// ── Blotter by Status ─────────────────────────────────────
$blotterStatusCounts = ['Open'=>0,'Under Investigation'=>0,'Settled'=>0,'Escalated'=>0,'Closed'=>0];
$bsQ = $db->prepare("SELECT status, COUNT(*) as cnt FROM blotter_cases WHERE barangay_id=? AND status != 'Archived' GROUP BY status");
$bsQ->execute([$bid]);
foreach ($bsQ->fetchAll() as $r) { if (isset($blotterStatusCounts[$r['status']])) $blotterStatusCounts[$r['status']] = (int)$r['cnt']; }

// ── Households ────────────────────────────────────────────
$stats['households_active']   = $stats['households'];
$stats['households_archived'] = $q("SELECT COUNT(*) FROM households WHERE barangay_id=? AND status='Archived'",[$bid]);
$stats['households_members']  = $q("SELECT COALESCE(SUM(total_members),0) FROM households WHERE barangay_id=? AND status='Active'",[$bid]);

// Household income distribution — batch query instead of 5 individual queries
$incomeRanges = ['Below ₱10,000','₱10,000 - ₱20,000','₱20,001 - ₱40,000','₱40,001 - ₱60,000','Above ₱60,000'];
$incomeStmt = $db->prepare(
    "SELECT income_range, COUNT(*) AS cnt
     FROM households
     WHERE barangay_id=? AND status='Active' AND income_range IS NOT NULL
     GROUP BY income_range"
);
$incomeStmt->execute([$bid]);
$incomeMap = array_column($incomeStmt->fetchAll(), 'cnt', 'income_range');
$incomeCounts = array_map(fn($r) => (int)($incomeMap[$r] ?? 0), $incomeRanges);

// ── Pending Requests ──────────────────────────────────────
$stats['requests_pending'] = $q("SELECT COUNT(*) FROM user_requests WHERE barangay_id=? AND status='pending'",[$bid]);

// ── Demographics ──────────────────────────────────────────
$gQ = $db->prepare("SELECT gender, COUNT(*) as cnt FROM residents WHERE barangay_id=? AND resident_status='Active' GROUP BY gender");
$gQ->execute([$bid]);
$genderData = ['Male'=>0,'Female'=>0];
foreach ($gQ->fetchAll() as $r) $genderData[$r['gender']] = $r['cnt'];

$vQ = $db->prepare("SELECT voter_status, COUNT(*) as cnt FROM residents WHERE barangay_id=? AND resident_status='Active' GROUP BY voter_status");
$vQ->execute([$bid]);
$voterData = ['Registered'=>0,'Not Registered'=>0];
foreach ($vQ->fetchAll() as $r) $voterData[$r['voter_status']] = $r['cnt'];

// ── Employment — batch query instead of 5 individual queries ──────────
$empStmt = $db->prepare(
    "SELECT occupation_status, COUNT(*) AS cnt
     FROM residents
     WHERE barangay_id=? AND resident_status='Active'
     GROUP BY occupation_status"
);
$empStmt->execute([$bid]);
$empMap = array_column($empStmt->fetchAll(), 'cnt', 'occupation_status');
$empStatuses = ['Employed','Unemployed','Self-Employed','Student','Retired'];
$empCounts   = array_map(fn($s) => (int)($empMap[$s] ?? 0), $empStatuses);

// ── Population Trend (12 months) — 1 query instead of 12 ─────────────
$trendStart = date('Y-m-01', strtotime('-11 months'));
$popTrendStmt = $db->prepare(
    "SELECT DATE_FORMAT(created_at,'%Y-%m') AS ym, COUNT(*) AS cnt
     FROM residents
     WHERE barangay_id=? AND created_at >= ?
     GROUP BY ym"
);
$popTrendStmt->execute([$bid, $trendStart]);
$popMap = array_column($popTrendStmt->fetchAll(), 'cnt', 'ym');
$popLabels = []; $popValues = [];
for ($i=11; $i>=0; $i--) {
    $ym = date('Y-m', strtotime("-$i months"));
    $popLabels[] = date('M Y', strtotime("-$i months"));
    $popValues[] = (int)($popMap[$ym] ?? 0);
}

// ── Blotter Trend (6 months) — 1 query instead of 6 ─────────────────
$blotterStart = date('Y-m-01', strtotime('-5 months'));
$blotterTrendStmt = $db->prepare(
    "SELECT DATE_FORMAT(created_at,'%Y-%m') AS ym, COUNT(*) AS cnt
     FROM blotter_cases
     WHERE barangay_id=? AND created_at >= ? AND status != 'Archived'
     GROUP BY ym"
);
$blotterTrendStmt->execute([$bid, $blotterStart]);
$blotterMap = array_column($blotterTrendStmt->fetchAll(), 'cnt', 'ym');
$blotterLabels = []; $blotterValues = [];
for ($i=5; $i>=0; $i--) {
    $ym = date('Y-m', strtotime("-$i months"));
    $blotterLabels[] = date('M Y', strtotime("-$i months"));
    $blotterValues[] = (int)($blotterMap[$ym] ?? 0);
}

// ── Certificates by type ──────────────────────────────────
$certQ = $db->prepare("SELECT certificate_type, COUNT(*) as cnt FROM certificates WHERE barangay_id=? GROUP BY certificate_type ORDER BY cnt DESC");
$certQ->execute([$bid]);
$certTypes = []; $certCounts = [];
foreach ($certQ->fetchAll() as $r) { $certTypes[] = $r['certificate_type']; $certCounts[] = $r['cnt']; }
$topCert = !empty($certTypes) ? $certTypes[0] : '—';

// ── Report Module Quick Stats (date-filtered) ─────────────
$rpt_from = sanitize($_GET['rpt_from'] ?? '');
$rpt_to   = sanitize($_GET['rpt_to']   ?? '');
if (!$rpt_from && !$rpt_to) {
    // Default: current month
    $rpt_from = date('Y-m-01');
    $rpt_to   = date('Y-m-d');
}
// ── Report queries — prepared statements, no string interpolation ─────
$rptResStmt = $db->prepare(
    "SELECT COUNT(*) FROM residents
     WHERE barangay_id=? AND resident_status='Active'
       AND DATE(created_at) BETWEEN ? AND ?"
);
$rptResStmt->execute([$bid, $rpt_from, $rpt_to]);
$rpt_new_residents = (int)$rptResStmt->fetchColumn();

$rptCertStmt = $db->prepare(
    "SELECT COUNT(*) FROM certificates
     WHERE barangay_id=? AND date_issued BETWEEN ? AND ?"
);
$rptCertStmt->execute([$bid, $rpt_from, $rpt_to]);
$rpt_docs_issued = (int)$rptCertStmt->fetchColumn();

$rptBlotterStmt = $db->prepare(
    "SELECT COUNT(*) FROM blotter_cases
     WHERE barangay_id=? AND status != 'Archived'
       AND DATE(created_at) BETWEEN ? AND ?"
);
$rptBlotterStmt->execute([$bid, $rpt_from, $rpt_to]);
$rpt_blotter = (int)$rptBlotterStmt->fetchColumn();

// rpt_households = same as active households already fetched
$rpt_households = (int)$stats['households'];

// Certificate breakdown for period
$rptCertBrkStmt = $db->prepare(
    "SELECT certificate_type, COUNT(*) as cnt
     FROM certificates
     WHERE barangay_id=? AND date_issued BETWEEN ? AND ?
     GROUP BY certificate_type ORDER BY cnt DESC"
);
$rptCertBrkStmt->execute([$bid, $rpt_from, $rpt_to]);
$rpt_certBreakdown = $rptCertBrkStmt->fetchAll();

// Pending certificates (requested but not yet issued) within date range
$rptPendingStmt = $db->prepare(
    "SELECT COUNT(*) FROM user_requests
     WHERE barangay_id=? AND status='pending' AND request_type LIKE '%certificate%'
       AND DATE(created_at) BETWEEN ? AND ?"
);
$rptPendingStmt->execute([$bid, $rpt_from, $rpt_to]);
$rpt_pending_certs = (int)$rptPendingStmt->fetchColumn();

// ── Batch request counts — 2 queries instead of 10 ──────────────────
$reqTypes = ['password_change'=>'Password Change','edit_information'=>'Edit Info','new_user'=>'New User','access_request'=>'Access','reset_2fa'=>'Reset 2FA'];

$reqTotalStmt = $db->prepare(
    "SELECT request_type, COUNT(*) AS cnt
     FROM user_requests
     WHERE barangay_id=?
     GROUP BY request_type"
);
$reqTotalStmt->execute([$bid]);
$reqTotalMap = array_column($reqTotalStmt->fetchAll(), 'cnt', 'request_type');

$reqPendingStmt = $db->prepare(
    "SELECT request_type, COUNT(*) AS cnt
     FROM user_requests
     WHERE barangay_id=? AND status='pending'
     GROUP BY request_type"
);
$reqPendingStmt->execute([$bid]);
$reqPendingMap = array_column($reqPendingStmt->fetchAll(), 'cnt', 'request_type');

// ── Recent ────────────────────────────────────────────────
$rRes = $db->prepare("SELECT * FROM residents WHERE barangay_id=? ORDER BY created_at DESC LIMIT 5");
$rRes->execute([$bid]); $recentResidents = $rRes->fetchAll();

$rBlt = $db->prepare("SELECT * FROM blotter_cases WHERE barangay_id=? ORDER BY created_at DESC LIMIT 5");
$rBlt->execute([$bid]); $recentBlotter = $rBlt->fetchAll();

$notices = $db->query("SELECT * FROM notices ORDER BY created_at DESC LIMIT 3")->fetchAll();

?>

<?php
$pageIcon     = 'bi-speedometer2';
$pageTitle    = 'Dashboard Overview';
$pageSubtitle = 'Summary of barangay population and activity.';
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
/* slim topbar */
.topbar{height:80px !important;}
.main-content{margin-top:80px !important;padding:12px 20px 20px 22px;}
.sidebar-brand{height:80px !important;}
.sec-title{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#3a7fa8;margin-bottom:8px;display:flex;align-items:center;gap:6px;}
.sec-title::after{content:'';flex:1;height:1px;background:#e0f0e4;}
/* dl-box download buttons */
.dl-box{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:8px;font-size:.78rem;font-weight:600;border:none;cursor:pointer;transition:opacity .15s,transform .12s;white-space:nowrap;}
.dl-box:hover{opacity:.88;transform:translateY(-1px);text-decoration:none;}
.dl-box-excel{background:#16a34a;color:#fff;}.dl-box-excel:hover{color:#fff;}
.dl-box-pdf{background:#dc2626;color:#fff;}.dl-box-pdf:hover{color:#fff;}
.pcard{border-radius:14px;padding:14px 14px 10px;display:flex;flex-direction:column;gap:9px;height:100%;transition:transform .15s,box-shadow .15s;cursor:default;border:1.5px solid rgba(0,0,0,0.10);box-shadow:0 1px 4px rgba(0,0,0,0.06);}
.pcard:hover{transform:translateY(-2px);box-shadow:0 6px 18px rgba(0,0,0,.13);border-color:rgba(0,0,0,0.18);}
.pcard-icon{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1rem;}
.pcard-val{font-size:1.25rem;font-weight:800;color:#1a2e1f;line-height:1.1;}
.pcard-lbl{font-size:.68rem;color:#2a5070;font-weight:500;line-height:1.3;}
.chart-card{background:#fff;border:1.5px solid #90c0d8;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(26,79,122,0.07);}
.chart-card .ch{padding:8px 12px;font-size:.75rem;font-weight:600;color:#1a2e1f;background:#f4f7fa;border-bottom:1.5px solid #90c0d8;display:flex;align-items:center;gap:6px;}
.chart-card .cb{padding:10px 12px;}
.table th,.table td{padding:4px 8px;font-size:.75rem;}
.table thead th{font-size:.68rem;text-transform:uppercase;color:#3a7fa8;}
.badge{font-size:.63rem;}
.btn-xs{padding:1px 7px;font-size:.7rem;}

/* ── Welcome Banner ───────────────────────── */
.welcome-banner {
    background-image: url('<?= BASE_URL ?>/assets/img/bgimage.jpg');
    background-size: cover;
    background-position: center;
    border-radius: 18px;
    padding: 22px 28px 20px;
    margin-bottom: 22px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    overflow: hidden;
    position: relative;
    min-height: 140px;
    box-shadow: 0 4px 20px rgba(2,16,36,0.22);
}
.welcome-banner::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(to right, rgba(5,38,89,0.88) 0%, rgba(10,60,140,0.70) 50%, rgba(5,38,89,0.15) 100%);
    z-index: 0;
    pointer-events: none;
}
.welcome-banner-img {
    position: absolute;
    top: 0; right: 0; bottom: 0;
    width: 55%;
    background-size: cover;
    background-position: center right;
    background-repeat: no-repeat;
    -webkit-mask-image: linear-gradient(to right, transparent 0%, rgba(0,0,0,0.18) 30%, rgba(0,0,0,0.32) 100%);
    mask-image: linear-gradient(to right, transparent 0%, rgba(0,0,0,0.18) 30%, rgba(0,0,0,0.32) 100%);
    opacity: 0.38;
    pointer-events: none;
}
.welcome-banner-text { position: relative; z-index: 1; }
.welcome-banner-title {
    font-size: 1.5rem;
    font-weight: 800;
    color: #fff;
    letter-spacing: -0.01em;
    margin-bottom: 5px;
    line-height: 1.2;
}
.welcome-banner-sub {
    font-size: 0.85rem;
    color: rgba(255,255,255,0.85);
    display: flex;
    align-items: center;
    gap: 7px;
    flex-wrap: wrap;
}
.welcome-banner-sub strong { color: #fff; font-weight: 700; }
.banner-dot {
    width: 4px; height: 4px;
    background: rgba(255,255,255,0.5);
    border-radius: 50%;
    display: inline-block;
    flex-shrink: 0;
}
.welcome-banner-icon {
    position: relative; z-index: 1;
    font-size: 3.2rem;
    color: rgba(255,255,255,0.15);
    flex-shrink: 0;
    line-height: 1;
}
html.dark-mode .welcome-banner {
    box-shadow: 0 4px 20px rgba(0,0,0,0.4);
}
</style>
</head>
<body>
<?php include __DIR__.'/../includes/sidebar_barangay.php'; ?>
<?php include __DIR__.'/../includes/topbar.php'; ?>

<div class="main-content">

  <?php
    $fullName  = mb_convert_case(trim($u['name']), MB_CASE_TITLE, 'UTF-8');
    $brgyName  = htmlspecialchars($brgy['barangay_name'] ?? 'Barangay');
    $hour      = (int)date('H');
    $greeting  = $hour < 12 ? 'Good Morning' : ($hour < 18 ? 'Good Afternoon' : 'Good Evening');
    $greetIcon = $hour < 12 ? 'bi-sunrise-fill' : ($hour < 18 ? 'bi-sun-fill' : 'bi-moon-stars-fill');
    $bannerBg  = '';
    try {
        $lcRow = $db->query("SELECT banner_path FROM landing_content WHERE id=1")->fetch();
        if (!empty($lcRow['banner_path'])) {
            $bannerBg = htmlspecialchars(UPLOAD_URL . $lcRow['banner_path'], ENT_QUOTES);
        }
    } catch (\Throwable $e) {}
  ?>
  <div class="welcome-banner" <?= $bannerBg ? 'data-banner="'.$bannerBg.'"' : '' ?>>
    <?php if ($bannerBg): ?>
    <div class="welcome-banner-img" style="background-image:url('<?= $bannerBg ?>')"></div>
    <?php endif; ?>
    <div class="welcome-banner-text">
      <div class="welcome-banner-title">
        <i class="bi bi-hand-wave" style="color:rgba(255,220,80,0.9);font-size:1.1rem;vertical-align:middle;margin-right:6px;"></i>Welcome Back, <?= $brgyName ?>!
      </div>
      <div class="welcome-banner-sub">
        <i class="bi bi-sun-fill" id="bannerGreetIcon"></i>
        <span id="bannerGreet"></span>, <?= htmlspecialchars($fullName) ?>
        <span class="banner-dot"></span>
        <span id="bannerClock"></span>
      </div>
    </div>
    <div class="welcome-banner-icon">
      <i class="bi bi-speedometer2"></i>
    </div>
  </div>

  <!-- Download All Records buttons -->
  <div style="display:flex;justify-content:flex-end;gap:8px;margin-bottom:18px;">
    <a href="export_dashboard_records.php?format=excel" class="dl-box dl-box-excel" style="text-decoration:none;">
      <i class="bi bi-file-earmark-excel-fill"></i> Download All Records
    </a>
  </div>

  <!-- ① OVERVIEW ─────────────────────────────────────────── -->
  <div class="sec-title mt-3"><i class="bi bi-grid-1x2-fill text-primary"></i>Overview</div>
  <div class="row g-2 mb-3">
    <?php
    $ov = [
      ['label'=>'Total Residents',   'value'=>$stats['residents'],  'icon'=>'bi-people-fill',            'link'=>BASE_URL.'/barangay_admin/residents.php',   'ic'=>'#3b9f5a','bg'=>'#d8edf7','ibg'=>'#90d0e8'],
      ['label'=>'Total Households',  'value'=>$stats['households'], 'icon'=>'bi-house-fill',             'link'=>BASE_URL.'/barangay_admin/households.php',  'ic'=>'#1a4f7a','bg'=>'#d8edf7','ibg'=>'#90d0e8'],
      ['label'=>'Blotter Cases',     'value'=>$stats['blotter'],    'icon'=>'bi-shield-exclamation',     'link'=>BASE_URL.'/barangay_admin/blotter.php',     'ic'=>'#c62828','bg'=>'#fde8e8','ibg'=>'#f8c4c4'],
      ['label'=>'Certificates Issued','value'=>$stats['certs'],     'icon'=>'bi-file-earmark-check-fill','link'=>BASE_URL.'/barangay_admin/certificates.php','ic'=>'#1a4f7a','bg'=>'#e8f4fb','ibg'=>'#b0d8f0'],
      ['label'=>'Active Officials',  'value'=>$stats['officials_active'], 'icon'=>'bi-person-badge-fill','link'=>BASE_URL.'/barangay_admin/officials.php',  'ic'=>'#6a1b9a','bg'=>'#ede7f6','ibg'=>'#d7c8ef'],
      ['label'=>'Pending Requests',  'value'=>$stats['requests_pending'], 'icon'=>'bi-inbox-fill',       'link'=>BASE_URL.'/barangay_admin/requests.php',   'ic'=>'#e65100','bg'=>'#fff3e0','ibg'=>'#ffd9a8'],
    ];
    foreach ($ov as $c): ?>
    <div class="col-6 col-md-2">
      <a href="<?= $c['link'] ?>" style="text-decoration:none;">
        <div class="pcard" style="background:<?= $c['bg'] ?>">
          <div class="d-flex align-items-center gap-2">
            <div class="pcard-icon" style="background:<?= $c['ibg'] ?>"><i class="bi <?= $c['icon'] ?>" style="color:<?= $c['ic'] ?>"></i></div>
            <div><div class="pcard-val"><?= number_format($c['value']) ?></div><div class="pcard-lbl"><?= $c['label'] ?></div></div>
          </div>
        </div>
      </a>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ② RESIDENT STATUS ──────────────────────────────────── -->
  <div class="sec-title"><i class="bi bi-person-lines-fill text-primary"></i>Resident Status</div>
  <div class="row g-2 mb-3">
    <?php
    $rs = [
      ['label'=>'Active',      'value'=>$stats['res_active'],      'icon'=>'bi-person-check-fill', 'ic'=>'#0d3a5c','bg'=>'#e0f2fb','ibg'=>'#b8e8f0'],
      ['label'=>'Inactive',    'value'=>$stats['res_inactive'],    'icon'=>'bi-person-dash-fill',  'ic'=>'#64748b','bg'=>'#f1f5f9','ibg'=>'#e2e8f0'],
      ['label'=>'Transferred', 'value'=>$stats['res_transferred'], 'icon'=>'bi-person-walking',    'ic'=>'#1a4f7a','bg'=>'#e8f4fb','ibg'=>'#b0d8f0'],
      ['label'=>'Deceased',    'value'=>$stats['res_deceased'],    'icon'=>'bi-person-x-fill',     'ic'=>'#7f1d1d','bg'=>'#fef2f2','ibg'=>'#fecaca'],
    ];
    foreach ($rs as $c): ?>
    <div class="col-6 col-md-3">
      <div class="pcard" style="background:<?= $c['bg'] ?>">
        <div class="d-flex align-items-center gap-2">
          <div class="pcard-icon" style="background:<?= $c['ibg'] ?>"><i class="bi <?= $c['icon'] ?>" style="color:<?= $c['ic'] ?>"></i></div>
          <div><div class="pcard-val"><?= number_format($c['value']) ?></div><div class="pcard-lbl"><?= $c['label'] ?></div></div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ③ DEMOGRAPHICS ─────────────────────────────────────── -->
  <div class="sec-title"><i class="bi bi-bar-chart-fill text-success"></i>Demographics</div>
  <div class="row g-2 mb-3">
    <div class="col-md-6">
      <div class="chart-card h-100">
        <div class="ch"><i class="bi bi-gender-ambiguous"></i>Male vs Female Distribution</div>
        <div class="cb d-flex align-items-center justify-content-center"><canvas id="genderChart" style="max-height:170px"></canvas></div>
      </div>
    </div>
    <div class="col-md-6">
      <div class="chart-card h-100">
        <div class="ch"><i class="bi bi-person-check-fill"></i>Voter Registration Status</div>
        <div class="cb d-flex align-items-center justify-content-center"><canvas id="voterChart" style="max-height:170px"></canvas></div>
      </div>
    </div>
  </div>

  <!-- ④ SECTORS ───────────────────────────────────────────── -->
  <div class="sec-title"><i class="bi bi-people-fill text-warning"></i>Sectors</div>
  <div class="row g-2 mb-3">
    <?php
    $sec = [
      ['label'=>'PWD',              'value'=>$stats['pwd'],   'icon'=>'bi-person-wheelchair','link'=>BASE_URL.'/barangay_admin/residents.php','ic'=>'#6a1b9a','bg'=>'#ede7f6','ibg'=>'#d7c8ef'],
      ['label'=>'Senior Citizens',  'value'=>$stats['senior'],'icon'=>'bi-person-fill',      'link'=>BASE_URL.'/barangay_admin/residents.php','ic'=>'#e65100','bg'=>'#fff3e0','ibg'=>'#ffd9a8'],
      ['label'=>'Solo Parents',     'value'=>$stats['solo'],  'icon'=>'bi-person-heart',     'link'=>BASE_URL.'/barangay_admin/residents.php','ic'=>'#ad1457','bg'=>'#fce4ec','ibg'=>'#f8bbd0'],
      ['label'=>'4Ps Beneficiaries','value'=>$stats['4ps'],   'icon'=>'bi-heart-fill',       'link'=>BASE_URL.'/barangay_admin/residents.php','ic'=>'#00695c','bg'=>'#e0f2f1','ibg'=>'#b2dfdb'],
    ];
    foreach ($sec as $c): ?>
    <div class="col-6 col-md-3">
      <div class="pcard" style="background:<?= $c['bg'] ?>">
        <div class="d-flex align-items-center gap-2">
          <div class="pcard-icon" style="background:<?= $c['ibg'] ?>"><i class="bi <?= $c['icon'] ?>" style="color:<?= $c['ic'] ?>"></i></div>
          <div><div class="pcard-val"><?= number_format($c['value']) ?></div><div class="pcard-lbl"><?= $c['label'] ?></div></div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ④ EMPLOYMENT STATUS ─────────────────────────────────── -->
  <div class="sec-title"><i class="bi bi-briefcase-fill text-info"></i>Employment Status</div>
  <div class="row g-2 mb-3">
    <div class="col-md-5">
      <div class="chart-card h-100">
        <div class="ch"><i class="bi bi-pie-chart-fill"></i>Employment Distribution</div>
        <div class="cb d-flex align-items-center justify-content-center"><canvas id="empChart" style="max-height:200px"></canvas></div>
      </div>
    </div>
    <div class="col-md-7">
      <div class="chart-card h-100">
        <div class="ch"><i class="bi bi-bar-chart-fill"></i>Employment Breakdown</div>
        <div class="cb"><canvas id="empBarChart" style="max-height:200px"></canvas></div>
      </div>
    </div>
  </div>

  <!-- ⑤ BLOTTER & PUBLIC SAFETY ───────────────────────────── -->
  <div class="sec-title"><i class="bi bi-shield-fill-exclamation text-danger"></i>Blotter &amp; Public Safety</div>
  <div class="row g-2 mb-3">
    <!-- Blotter status breakdown cards -->
    <?php
    $bsc = [
      ['label'=>'Open',               'value'=>$blotterStatusCounts['Open'],               'ic'=>'#e65100','bg'=>'#fff3e0','ibg'=>'#ffd9a8','icon'=>'bi-exclamation-circle-fill'],
      ['label'=>'Under Investigation','value'=>$blotterStatusCounts['Under Investigation'],'ic'=>'#1a4f7a','bg'=>'#e8f4fb','ibg'=>'#b0d8f0','icon'=>'bi-search'],
      ['label'=>'Settled',            'value'=>$blotterStatusCounts['Settled'],            'ic'=>'#1a4f7a','bg'=>'#d8edf7','ibg'=>'#90d0e8','icon'=>'bi-check-circle-fill'],
      ['label'=>'Escalated',          'value'=>$blotterStatusCounts['Escalated'],          'ic'=>'#c62828','bg'=>'#fde8e8','ibg'=>'#f8c4c4','icon'=>'bi-arrow-up-circle-fill'],
      ['label'=>'Closed',             'value'=>$blotterStatusCounts['Closed'],             'ic'=>'#64748b','bg'=>'#f1f5f9','ibg'=>'#e2e8f0','icon'=>'bi-lock-fill'],
    ];
    foreach ($bsc as $c): ?>
    <div class="col-6 col-md">
      <div class="pcard" style="background:<?= $c['bg'] ?>">
        <div class="d-flex align-items-center gap-2">
          <div class="pcard-icon" style="background:<?= $c['ibg'] ?>"><i class="bi <?= $c['icon'] ?>" style="color:<?= $c['ic'] ?>"></i></div>
          <div><div class="pcard-val"><?= number_format($c['value']) ?></div><div class="pcard-lbl"><?= $c['label'] ?></div></div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="row g-2 mb-3">
    <div class="col-md-8">
      <div class="chart-card h-100">
        <div class="ch"><i class="bi bi-graph-up-arrow"></i>Blotter Cases Trend (Last 6 Months)</div>
        <div class="cb"><canvas id="blotterTrendChart" style="max-height:160px"></canvas></div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="chart-card h-100">
        <div class="ch d-flex justify-content-between align-items-center">
          <span><i class="bi bi-shield-exclamation"></i> Recent Cases</span>
          <a href="<?= BASE_URL ?>/barangay_admin/blotter.php" class="btn btn-xs btn-outline-danger">All</a>
        </div>
        <div class="cb p-0">
          <table class="table table-sm mb-0">
            <thead><tr><th>Case No.</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($recentBlotter as $b):
              $sc=['Open'=>'warning','Settled'=>'success','Escalated'=>'danger','Closed'=>'secondary','Under Investigation'=>'info']; ?>
              <tr>
                <td><?= htmlspecialchars($b['case_number']) ?></td>
                <td><span class="badge bg-<?= $sc[$b['status']]??'secondary' ?>"><?= $b['status'] ?></span></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- ⑥ OFFICIALS ────────────────────────────────────────── -->
  <div class="sec-title"><i class="bi bi-person-badge-fill text-primary"></i>Barangay Officials</div>
  <div class="row g-2 mb-3">
    <div class="col-6 col-md-3">
      <a href="<?= BASE_URL ?>/barangay_admin/officials.php" style="text-decoration:none;">
        <div class="pcard" style="background:#ede7f6">
          <div class="d-flex align-items-center gap-2">
            <div class="pcard-icon" style="background:#d7c8ef"><i class="bi bi-person-badge-fill" style="color:#6a1b9a"></i></div>
            <div><div class="pcard-val"><?= number_format($stats['officials_active']) ?></div><div class="pcard-lbl">Active Officials</div></div>
          </div>
        </div>
      </a>
    </div>
    <?php if ($stats['officials_expired'] > 0): ?>
    <div class="col-6 col-md-3">
      <a href="<?= BASE_URL ?>/barangay_admin/officials.php" style="text-decoration:none;">
        <div class="pcard" style="background:#fef2f2">
          <div class="d-flex align-items-center gap-2">
            <div class="pcard-icon" style="background:#fecaca"><i class="bi bi-clock-history" style="color:#c62828"></i></div>
            <div><div class="pcard-val"><?= number_format($stats['officials_expired']) ?></div><div class="pcard-lbl">Expired Terms</div></div>
          </div>
        </div>
      </a>
    </div>
    <?php endif; ?>
    <div class="col-md-<?= $stats['officials_expired'] > 0 ? '6' : '9' ?>">
      <div class="chart-card h-100">
        <div class="ch"><i class="bi bi-person-badge-fill"></i>Officials by Position</div>
        <div class="cb p-0">
          <table class="table table-sm mb-0">
            <thead><tr><th>Position</th><th>Name</th><th>Status</th></tr></thead>
            <tbody>
            <?php
            $offList = $db->prepare("SELECT full_name, position, status, term_end FROM officials WHERE barangay_id=? AND status='active' ORDER BY FIELD(position,'Barangay Captain','Barangay Secretary','Barangay Treasurer','Barangay Kagawad','SK Chairperson') LIMIT 6");
            $offList->execute([$bid]);
            foreach ($offList->fetchAll() as $o):
                $isExp = $o['term_end'] < date('Y-m-d');
            ?>
              <tr>
                <td style="font-size:.72rem;"><?= htmlspecialchars($o['position']) ?></td>
                <td style="font-size:.72rem;font-weight:600;"><?= htmlspecialchars($o['full_name']) ?></td>
                <td><?php if ($isExp): ?><span class="badge bg-danger" style="font-size:.6rem;">Expired</span><?php else: ?><span class="badge bg-success" style="font-size:.6rem;">Active</span><?php endif; ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- ⑦ HOUSEHOLDS ────────────────────────────────────────── -->
  <div class="sec-title"><i class="bi bi-house-fill text-success"></i>Households</div>
  <div class="row g-2 mb-3">
    <div class="col-6 col-md-3">
      <div class="pcard" style="background:#d8edf7">
        <div class="d-flex align-items-center gap-2">
          <div class="pcard-icon" style="background:#90d0e8"><i class="bi bi-house-fill" style="color:#1a4f7a"></i></div>
          <div><div class="pcard-val"><?= number_format($stats['households_active']) ?></div><div class="pcard-lbl">Active Households</div></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="pcard" style="background:#f1f5f9">
        <div class="d-flex align-items-center gap-2">
          <div class="pcard-icon" style="background:#e2e8f0"><i class="bi bi-archive-fill" style="color:#64748b"></i></div>
          <div><div class="pcard-val"><?= number_format($stats['households_archived']) ?></div><div class="pcard-lbl">Archived</div></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="pcard" style="background:#e8f4fb">
        <div class="d-flex align-items-center gap-2">
          <div class="pcard-icon" style="background:#b0d8f0"><i class="bi bi-people-fill" style="color:#1a4f7a"></i></div>
          <div><div class="pcard-val"><?= number_format($stats['households_members']) ?></div><div class="pcard-lbl">Total Members</div></div>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="pcard" style="background:#fff8e1">
        <div class="d-flex align-items-center gap-2">
          <div class="pcard-icon" style="background:#ffe082"><i class="bi bi-currency-exchange" style="color:#f57f17"></i></div>
          <div>
            <?php
            $topIncomeIdx = array_search(max($incomeCounts), $incomeCounts);
            $topIncome = $incomeCounts[$topIncomeIdx] > 0 ? $incomeRanges[$topIncomeIdx] : '—';
            ?>
            <div class="pcard-val" style="font-size:.75rem;"><?= htmlspecialchars($topIncome) ?></div>
            <div class="pcard-lbl">Most Common Income</div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-12">
      <div class="chart-card">
        <div class="ch"><i class="bi bi-bar-chart-fill"></i>Household Income Distribution</div>
        <div class="cb"><canvas id="incomeChart" style="max-height:140px"></canvas></div>
      </div>
    </div>
  </div>

  <!-- ⑧ REQUESTS ───────────────────────────────────────────── -->
  <div class="sec-title"><i class="bi bi-inbox-fill text-warning"></i>Requests to LGU</div>
  <div class="row g-2 mb-3">
    <?php
    $reqColors = ['password_change'=>['#e65100','#fff3e0','#ffd9a8'],'edit_information'=>['#1a4f7a','#e8f4fb','#b0d8f0'],'new_user'=>['#1a4f7a','#d8edf7','#90d0e8'],'access_request'=>['#64748b','#f1f5f9','#e2e8f0'],'reset_2fa'=>['#c62828','#fce4ec','#f48fb1']];
    foreach ($reqTypes as $rt => $rl):
        $cnt = (int)($reqTotalMap[$rt]   ?? 0);
        $pnd = (int)($reqPendingMap[$rt] ?? 0);
        [$ic,$bg,$ibg] = $reqColors[$rt];
    ?>
    <div class="col-6 col-md">
      <div class="pcard" style="background:<?= $bg ?>">
        <div class="d-flex align-items-center gap-2">
          <div class="pcard-icon" style="background:<?= $ibg ?>"><i class="bi bi-inbox-fill" style="color:<?= $ic ?>"></i></div>
          <div>
            <div class="pcard-val"><?= number_format($cnt) ?></div>
            <div class="pcard-lbl"><?= $rl ?></div>
            <?php if ($pnd > 0): ?><div style="font-size:.62rem;color:<?= $ic ?>;font-weight:700;"><?= $pnd ?> pending</div><?php endif; ?>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ⑨ DOCUMENTS & SERVICES ─────────────────────────────── -->
  <div class="sec-title"><i class="bi bi-file-earmark-text-fill text-primary"></i>Documents &amp; Services</div>
  <div class="row g-2 mb-3">
    <div class="col-md-4">
      <div class="row g-2 h-100">
        <div class="col-12">
          <div class="pcard" style="background:#e8f4fb">
            <div class="d-flex align-items-center gap-2">
              <div class="pcard-icon" style="background:#b0d8f0"><i class="bi bi-file-earmark-check-fill" style="color:#1a4f7a"></i></div>
              <div><div class="pcard-val"><?= number_format($stats['certs']) ?></div><div class="pcard-lbl">Total Certificates Issued</div></div>
            </div>
          </div>
        </div>
        <div class="col-12">
          <div class="pcard" style="background:#fff8e1">
            <div class="d-flex align-items-center gap-2">
              <div class="pcard-icon" style="background:#ffe082"><i class="bi bi-star-fill" style="color:#f57f17"></i></div>
              <div><div class="pcard-val" style="font-size:1rem;"><?= htmlspecialchars($topCert) ?></div><div class="pcard-lbl">Most Requested Document</div></div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-md-8">
      <div class="chart-card h-100">
        <div class="ch"><i class="bi bi-bar-chart-fill"></i>Certificates by Type</div>
        <div class="cb"><canvas id="certChart" style="max-height:180px"></canvas></div>
      </div>
    </div>
  </div>

  <!-- ⑩ REPORT MODULE QUICK VIEW ─────────────────────────── -->
  <div class="sec-title"><i class="bi bi-file-earmark-bar-graph-fill text-primary"></i>Reports Module
    <a href="<?= BASE_URL ?>/barangay_admin/reports.php" class="btn btn-xs btn-outline-primary ms-2" style="font-size:.65rem;padding:1px 8px;">Full Report →</a>
  </div>

  <!-- Date filter for report stats -->
  <form method="GET" class="d-flex align-items-center gap-2 mb-2 flex-wrap" id="rptFilterForm">
    <?php foreach ($_GET as $k => $v): if ($k === 'rpt_from' || $k === 'rpt_to') continue; ?>
      <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars($v) ?>">
    <?php endforeach; ?>
    <label class="small fw-semibold mb-0" style="font-size:.72rem;white-space:nowrap;">Date Range:</label>
    <input type="date" name="rpt_from" class="form-control form-control-sm" style="width:auto;font-size:.75rem;" value="<?= htmlspecialchars($rpt_from) ?>" max="<?= date('Y-m-d') ?>">
    <span class="small text-muted">–</span>
    <input type="date" name="rpt_to" class="form-control form-control-sm" style="width:auto;font-size:.75rem;" value="<?= htmlspecialchars($rpt_to) ?>" max="<?= date('Y-m-d') ?>">
    <button type="submit" class="btn btn-primary btn-sm" style="font-size:.72rem;"><i class="bi bi-funnel-fill"></i> Filter</button>
    <a href="<?= BASE_URL ?>/barangay_admin/dashboard.php" class="btn btn-outline-secondary btn-sm" style="font-size:.72rem;" title="Reset to current month"><i class="bi bi-x-lg"></i></a>
    <a href="<?= BASE_URL ?>/barangay_admin/export_report.php?category=summary&date_from=<?= urlencode($rpt_from) ?>&date_to=<?= urlencode($rpt_to) ?>&format=excel"
       class="btn btn-success btn-sm ms-auto" style="font-size:.72rem;">
      <i class="bi bi-file-earmark-excel me-1"></i>Download Excel
    </a>
    <button type="button" class="btn btn-primary btn-sm" style="font-size:.72rem;" onclick="window.location='<?= BASE_URL ?>/barangay_admin/reports.php'">
      <i class="bi bi-send me-1"></i>Send to LGU
    </button>
  </form>

  <!-- Report Stat Cards -->
  <div class="row g-2 mb-2">
    <div class="col-6 col-md-3">
      <a href="<?= BASE_URL ?>/barangay_admin/reports.php?category=residents&date_from=<?= urlencode($rpt_from) ?>&date_to=<?= urlencode($rpt_to) ?>" style="text-decoration:none;">
        <div class="pcard" style="background:#e8f0fe">
          <div class="d-flex align-items-center gap-2">
            <div class="pcard-icon" style="background:#b3c8f5"><i class="bi bi-people-fill" style="color:#1a4f7a"></i></div>
            <div><div class="pcard-val"><?= number_format($rpt_new_residents) ?></div><div class="pcard-lbl">New Residents</div></div>
          </div>
        </div>
      </a>
    </div>
    <div class="col-6 col-md-3">
      <a href="<?= BASE_URL ?>/barangay_admin/reports.php?category=documents&date_from=<?= urlencode($rpt_from) ?>&date_to=<?= urlencode($rpt_to) ?>" style="text-decoration:none;">
        <div class="pcard" style="background:#e8f4fb">
          <div class="d-flex align-items-center gap-2">
            <div class="pcard-icon" style="background:#90d0e8"><i class="bi bi-file-earmark-text-fill" style="color:#1a4f7a"></i></div>
            <div><div class="pcard-val"><?= number_format($rpt_docs_issued) ?></div><div class="pcard-lbl">Documents Issued</div></div>
          </div>
        </div>
      </a>
    </div>
    <div class="col-6 col-md-3">
      <a href="<?= BASE_URL ?>/barangay_admin/reports.php?category=blotter&date_from=<?= urlencode($rpt_from) ?>&date_to=<?= urlencode($rpt_to) ?>" style="text-decoration:none;">
        <div class="pcard" style="background:#ffebee">
          <div class="d-flex align-items-center gap-2">
            <div class="pcard-icon" style="background:#f8c4c4"><i class="bi bi-shield-exclamation" style="color:#c62828"></i></div>
            <div><div class="pcard-val"><?= number_format($rpt_blotter) ?></div><div class="pcard-lbl">Blotter Cases</div></div>
          </div>
        </div>
      </a>
    </div>
    <div class="col-6 col-md-3">
      <a href="<?= BASE_URL ?>/barangay_admin/reports.php?category=households" style="text-decoration:none;">
        <div class="pcard" style="background:#fff3e0">
          <div class="d-flex align-items-center gap-2">
            <div class="pcard-icon" style="background:#ffd9a8"><i class="bi bi-house-door-fill" style="color:#e65100"></i></div>
            <div><div class="pcard-val"><?= number_format($rpt_households) ?></div><div class="pcard-lbl">Total Households</div></div>
          </div>
        </div>
      </a>
    </div>
  </div>

  <!-- Certificates Box -->
  <div class="row g-2 mb-3">
    <div class="col-md-5">
      <div class="chart-card h-100">
        <div class="ch d-flex justify-content-between align-items-center">
          <span><i class="bi bi-file-earmark-check-fill me-1"></i>Certificates Issued</span>
          <a href="<?= BASE_URL ?>/barangay_admin/certificates.php" class="btn btn-xs btn-outline-primary" style="font-size:.63rem;padding:1px 7px;">View All</a>
        </div>
        <div class="cb">
          <div class="d-flex align-items-center gap-3 mb-2">
            <div class="text-center">
              <div style="font-size:1.6rem;font-weight:800;color:#1a4f7a;"><?= number_format($rpt_docs_issued) ?></div>
              <div style="font-size:.65rem;color:#64748b;">This Period</div>
            </div>
            <div class="text-center">
              <div style="font-size:1.6rem;font-weight:800;color:#1a4f7a;"><?= number_format($stats['certs']) ?></div>
              <div style="font-size:.65rem;color:#64748b;">All Time</div>
            </div>
            <?php if (!empty($certTypes)): ?>
            <div class="ms-auto text-end">
              <div style="font-size:.68rem;color:#64748b;">Most Requested</div>
              <div style="font-size:.78rem;font-weight:700;color:#1a4f7a;"><?= htmlspecialchars($topCert) ?></div>
            </div>
            <?php endif; ?>
          </div>
          <?php if (!empty($rpt_certBreakdown)): ?>
          <div style="max-height:120px;overflow-y:auto;">
            <?php foreach ($rpt_certBreakdown as $cd): ?>
            <div class="d-flex justify-content-between align-items-center mb-1">
              <span style="font-size:.72rem;"><?= htmlspecialchars($cd['certificate_type']) ?></span>
              <span class="badge" style="background:#1a4f7a;font-size:.63rem;"><?= $cd['cnt'] ?></span>
            </div>
            <div class="progress mb-1" style="height:4px;">
              <div class="progress-bar" style="width:<?= $rpt_docs_issued > 0 ? round(($cd['cnt']/$rpt_docs_issued)*100) : 0 ?>%;background:#1a4f7a;"></div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php else: ?>
          <p class="text-muted text-center small mt-2">No certificates in this period.</p>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="col-md-7">
      <div class="chart-card h-100">
        <div class="ch"><i class="bi bi-bar-chart-fill me-1"></i>Certificate Types Breakdown</div>
        <div class="cb d-flex align-items-center justify-content-center">
          <?php if (!empty($certTypes)): ?>
          <canvas id="dashCertChart" style="max-height:160px"></canvas>
          <?php else: ?>
          <p class="text-muted small text-center mt-3">No certificate data available.</p>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- ⑦ POPULATION TRENDS ─────────────────────────────────── -->
  <div class="sec-title"><i class="bi bi-graph-up text-success"></i>Population Trends</div>
  <div class="row g-2 mb-3">
    <div class="col-12">
      <div class="chart-card">
        <div class="ch"><i class="bi bi-graph-up"></i>Population Growth (Last 12 Months)</div>
        <div class="cb"><canvas id="popTrendChart" style="max-height:160px"></canvas></div>
      </div>
    </div>
  </div>

  <!-- Notices -->
  <?php if (!empty($notices)): ?>
  <div class="sec-title"><i class="bi bi-megaphone-fill text-warning"></i>Latest Notices from LGU</div>
  <div class="row g-2 mb-3">
    <?php foreach ($notices as $n): ?>
    <div class="col-md-4">
      <div style="background:#fff;border:1px solid #e0f0e4;border-radius:12px;padding:12px;">
        <div class="fw-semibold" style="font-size:.8rem;color:#1a2e1f;"><?= htmlspecialchars($n['title']) ?></div>
        <p class="text-muted mb-1" style="font-size:.72rem;"><?= htmlspecialchars(substr($n['content'],0,90)) ?>...</p>
        <small class="text-muted" style="font-size:.65rem;"><?= date('M d, Y',strtotime($n['created_at'])) ?></small>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>


</div><!-- /main-content -->

<script defer src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script defer src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script defer>
document.addEventListener('DOMContentLoaded', function() {
const tt = { ticks:{font:{size:10}}, grid:{color:'#f0f0f0'} };
const pctTip = { callbacks:{ label: ctx => { const t=ctx.dataset.data.reduce((a,b)=>a+b,0); return ` ${ctx.label}: ${ctx.parsed.toLocaleString()} (${t?((ctx.parsed/t)*100).toFixed(1):0}%)`; } } };

// Gender
new Chart(document.getElementById('genderChart'),{
  type:'doughnut',
  data:{labels:['Male','Female'],datasets:[{data:[<?= $genderData['Male'] ?>,<?= $genderData['Female'] ?>],backgroundColor:['#3b9f5a','#e91e8c'],borderWidth:3,borderColor:'#fff'}]},
  options:{responsive:true,plugins:{legend:{position:'bottom',labels:{font:{size:10},boxWidth:12}},tooltip:pctTip}}
});

// Voter
new Chart(document.getElementById('voterChart'),{
  type:'doughnut',
  data:{labels:['Registered','Not Registered'],datasets:[{data:[<?= $voterData['Registered'] ?>,<?= $voterData['Not Registered'] ?>],backgroundColor:['#1a4f7a','#e0e0e0'],borderWidth:3,borderColor:'#fff'}]},
  options:{responsive:true,plugins:{legend:{position:'bottom',labels:{font:{size:10},boxWidth:12}},tooltip:pctTip}}
});

// Employment Pie
new Chart(document.getElementById('empChart'),{
  type:'pie',
  data:{labels:<?= json_encode($empStatuses) ?>,datasets:[{data:<?= json_encode($empCounts) ?>,backgroundColor:['#1a4f7a','#e53935','#43a047','#fb8c00','#8e24aa'],borderWidth:2,borderColor:'#fff'}]},
  options:{responsive:true,plugins:{legend:{position:'bottom',labels:{font:{size:9},boxWidth:10,padding:5}},tooltip:pctTip}}
});

// Employment Bar
new Chart(document.getElementById('empBarChart'),{
  type:'bar',
  data:{labels:<?= json_encode($empStatuses) ?>,datasets:[{data:<?= json_encode($empCounts) ?>,backgroundColor:['#1a4f7a','#e53935','#43a047','#fb8c00','#8e24aa'],borderRadius:5}]},
  options:{responsive:true,plugins:{legend:{display:false}},scales:{x:tt,y:{...tt,beginAtZero:true}}}
});

// Blotter Trend
new Chart(document.getElementById('blotterTrendChart'),{
  type:'line',
  data:{labels:<?= json_encode($blotterLabels) ?>,datasets:[{label:'Cases',data:<?= json_encode($blotterValues) ?>,borderColor:'#c62828',backgroundColor:'rgba(198,40,40,0.08)',tension:0.4,fill:true,pointRadius:4,pointBackgroundColor:'#c62828'}]},
  options:{responsive:true,plugins:{legend:{display:false}},scales:{x:tt,y:{...tt,beginAtZero:true}}}
});

// Certificates by Type
new Chart(document.getElementById('certChart'),{
  type:'bar',
  data:{labels:<?= json_encode($certTypes ?: ['No Data']) ?>,datasets:[{data:<?= json_encode($certCounts ?: [0]) ?>,backgroundColor:['#1a4f7a','#1a4f7a','#e65100','#6a1b9a','#ad1457','#00695c'],borderRadius:5}]},
  options:{responsive:true,plugins:{legend:{display:false}},scales:{x:{...tt,ticks:{...tt.ticks,maxRotation:30}},y:{...tt,beginAtZero:true}}}
});

// Population Trend
new Chart(document.getElementById('popTrendChart'),{
  type:'line',
  data:{labels:<?= json_encode($popLabels) ?>,datasets:[{label:'New Residents',data:<?= json_encode($popValues) ?>,borderColor:'#1a4f7a',backgroundColor:'rgba(46,125,50,0.08)',tension:0.4,fill:true,pointRadius:4,pointBackgroundColor:'#1a4f7a'}]},
  options:{responsive:true,plugins:{legend:{display:false}},scales:{x:tt,y:{...tt,beginAtZero:true}}}
});

// Household Income Distribution
new Chart(document.getElementById('incomeChart'),{
  type:'bar',
  data:{
    labels:<?= json_encode(array_map(function($r){ return str_replace(['Below ','Above ','₱'],['<','>','P'],$r); }, $incomeRanges)) ?>,
    datasets:[{data:<?= json_encode($incomeCounts) ?>,backgroundColor:['#1a4f7a','#1a4f7a','#e65100','#6a1b9a','#1a4f7a'],borderRadius:5}]
  },
  options:{responsive:true,plugins:{legend:{display:false}},scales:{x:tt,y:{...tt,beginAtZero:true}}}
});

// Dashboard Certificates Chart
<?php if (!empty($certTypes)): ?>
new Chart(document.getElementById('dashCertChart'),{
  type:'bar',
  data:{
    labels:<?= json_encode($certTypes) ?>,
    datasets:[{
      data:<?= json_encode($certCounts) ?>,
      backgroundColor:['#1a4f7a','#1565c0','#e65100','#6a1b9a','#ad1457','#00695c','#1a4f7a'],
      borderRadius:6
    }]
  },
  options:{
    responsive:true,
    plugins:{legend:{display:false}},
    scales:{
      x:{...tt,ticks:{...tt.ticks,maxRotation:30}},
      y:{...tt,beginAtZero:true}
    }
  }
});
<?php endif; ?>

function toggleSidebar(){document.getElementById('sidebar').classList.toggle('show');}

// ── Real-time banner clock ─────────────────────────────────
(function() {
    const days   = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    const months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    function tick() {
        const now     = new Date();
        const manila  = new Date(now.toLocaleString('en-US', { timeZone: 'Asia/Manila' }));
        const hour    = manila.getHours();
        let greet, icon;
        if (hour >= 5 && hour < 12)       { greet = 'Good Morning';   icon = 'bi-sunrise-fill'; }
        else if (hour >= 12 && hour < 18) { greet = 'Good Afternoon'; icon = 'bi-sun-fill'; }
        else                              { greet = 'Good Evening';   icon = 'bi-moon-stars-fill'; }
        const greetEl = document.getElementById('bannerGreet');
        const iconEl  = document.getElementById('bannerGreetIcon');
        if (greetEl) greetEl.textContent = greet;
        if (iconEl)  iconEl.className    = 'bi ' + icon;
        const el   = document.getElementById('bannerClock');
        if (!el) return;
        const day  = days[manila.getDay()];
        const mon  = months[manila.getMonth()];
        const date = manila.getDate();
        const yr   = manila.getFullYear();
        let   h    = hour;
        const m    = String(manila.getMinutes()).padStart(2,'0');
        const s    = String(manila.getSeconds()).padStart(2,'0');
        const ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;
        el.textContent = `${day}, ${mon} ${date}, ${yr}  ${h}:${m}:${s} ${ampm}`;
    }
    tick();
    setInterval(tick, 1000);
})();
}); // end DOMContentLoaded
</script>
</body>
</html>