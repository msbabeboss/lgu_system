<?php
require_once __DIR__ . '/../config/config.php';
requireRole('super_admin');

$db = Database::getInstance()->getConnection();
$u  = currentUser();

$barangays   = $db->query("SELECT * FROM barangays WHERE status='active' ORDER BY barangay_name")->fetchAll();
$selectedBrgy = isset($_GET['barangay_id']) ? (int)$_GET['barangay_id'] : 0;

$where  = $selectedBrgy ? "WHERE r.barangay_id=$selectedBrgy" : "WHERE 1=1";
$whereB = $selectedBrgy ? "WHERE barangay_id=$selectedBrgy"   : "WHERE 1=1";
$andB   = $selectedBrgy ? " AND r.barangay_id=$selectedBrgy"  : "";

// ── Overview ──────────────────────────────────────────────
$stats['total_households'] = $db->query("SELECT COUNT(*) FROM households $whereB")->fetchColumn();
$stats['total_blotter']    = $db->query("SELECT COUNT(*) FROM blotter_cases $whereB")->fetchColumn();
$stats['total_certs']      = $db->query("SELECT COUNT(*) FROM certificates" . ($selectedBrgy ? " WHERE barangay_id=$selectedBrgy" : ""))->fetchColumn();

// ── Consolidated resident counts — 1 query instead of 9 ─────────────
$resSQL = "SELECT
    SUM(CASE WHEN r.resident_status='Active'       THEN 1 ELSE 0 END) AS active,
    SUM(CASE WHEN r.resident_status='Inactive'     THEN 1 ELSE 0 END) AS inactive,
    SUM(CASE WHEN r.resident_status='Transferred'  THEN 1 ELSE 0 END) AS transferred,
    SUM(CASE WHEN r.is_pwd=1         AND r.resident_status='Active' THEN 1 ELSE 0 END) AS pwd,
    SUM(CASE WHEN r.is_senior=1      AND r.resident_status='Active' THEN 1 ELSE 0 END) AS senior,
    SUM(CASE WHEN r.is_solo_parent=1 AND r.resident_status='Active' THEN 1 ELSE 0 END) AS solo,
    SUM(CASE WHEN r.is_4ps=1         AND r.resident_status='Active' THEN 1 ELSE 0 END) AS fours
FROM residents r $where";
$rc = $db->query($resSQL)->fetch();

$stats['total_residents']  = (int)$rc['active'];
$stats['res_active']       = $stats['total_residents'];
$stats['res_inactive']     = (int)$rc['inactive'];
$stats['res_transferred']  = (int)$rc['transferred'];
$stats['total_pwd']        = (int)$rc['pwd'];
$stats['total_senior']     = (int)$rc['senior'];
$stats['total_solo']       = (int)$rc['solo'];
$stats['total_4ps']        = (int)$rc['fours'];

// Deceased = residents with status 'Deceased' + legacy archived records (matches brgy admin logic)
// Uses a prepared statement with bound parameters to eliminate string-interpolation hazard.
if ($selectedBrgy) {
    $deceasedStmt = $db->prepare("SELECT COUNT(*) FROM (
        SELECT r.id FROM residents r
        WHERE r.resident_status='Deceased' AND r.barangay_id=?
        UNION
        SELECT a.record_id FROM archives a
        INNER JOIN residents r ON r.id=a.record_id AND r.barangay_id=?
        WHERE a.table_name='residents'
          AND r.resident_status='Inactive'
          AND a.data_json LIKE '%Deceased%'
    ) AS deceased_combined");
    $deceasedStmt->execute([$selectedBrgy, $selectedBrgy]);
} else {
    $deceasedStmt = $db->prepare("SELECT COUNT(*) FROM (
        SELECT r.id FROM residents r
        WHERE r.resident_status='Deceased'
        UNION
        SELECT a.record_id FROM archives a
        INNER JOIN residents r ON r.id=a.record_id
        WHERE a.table_name='residents'
          AND r.resident_status='Inactive'
          AND a.data_json LIKE '%Deceased%'
    ) AS deceased_combined");
    $deceasedStmt->execute();
}
$stats['res_deceased'] = (int)$deceasedStmt->fetchColumn();

// ── Transferred per Barangay ──────────────────────────────
$transferredPerBrgy = $db->query("
    SELECT b.barangay_name,
        COUNT(*) as total_transferred,
        SUM(CASE WHEN r.transfer_outside=1 THEN 1 ELSE 0 END) as outside_transfer,
        SUM(CASE WHEN r.transfer_outside=0 OR r.transfer_outside IS NULL THEN 1 ELSE 0 END) as local_transfer
    FROM residents r
    INNER JOIN barangays b ON b.id = r.barangay_id
    WHERE r.resident_status = 'Transferred'
    " . ($selectedBrgy ? " AND r.barangay_id=$selectedBrgy" : "") . "
    GROUP BY r.barangay_id, b.barangay_name
    ORDER BY total_transferred DESC
")->fetchAll();

// ── Demographics ──────────────────────────────────────────
$genderQ = $db->query("SELECT gender, COUNT(*) as cnt FROM residents r $where AND r.resident_status='Active' GROUP BY gender");
$genderData = ['Male'=>0,'Female'=>0];
foreach ($genderQ->fetchAll() as $r) $genderData[$r['gender']] = $r['cnt'];

$voterData = ['Registered'=>0,'Not Registered'=>0];
$voterQ = $db->query("SELECT voter_status, COUNT(*) as cnt FROM residents r $where AND r.resident_status='Active' GROUP BY voter_status");
foreach ($voterQ->fetchAll() as $r) $voterData[$r['voter_status']] = $r['cnt'];

// ── Employment — batch query instead of 5 individual queries ──────────
$empSQL  = "SELECT occupation_status, COUNT(*) AS cnt
            FROM residents r $where
            AND r.occupation_status IN ('Employed','Unemployed','Self-Employed','Student','Retired')
            AND r.resident_status='Active'
            GROUP BY occupation_status";
$empMap  = array_column($db->query($empSQL)->fetchAll(), 'cnt', 'occupation_status');
$empStatuses = ['Employed','Unemployed','Self-Employed','Student','Retired'];
$empValues   = array_map(fn($s) => (int)($empMap[$s] ?? 0), $empStatuses);

// ── Population Trend (12 months) — 1 query instead of 12 ─────────────
$trendStart = date('Y-m-01', strtotime('-11 months'));
$popSQL     = "SELECT DATE_FORMAT(r.created_at,'%Y-%m') AS ym, COUNT(*) AS cnt
               FROM residents r
               WHERE r.created_at >= '$trendStart'"
             . ($selectedBrgy ? " AND r.barangay_id=$selectedBrgy" : "")
             . " GROUP BY ym";
$popMap     = array_column($db->query($popSQL)->fetchAll(), 'cnt', 'ym');
$popLabels = []; $popValues = [];
for ($i=11; $i>=0; $i--) {
    $ym = date('Y-m', strtotime("-$i months"));
    $popLabels[] = date('M Y', strtotime("-$i months"));
    $popValues[] = (int)($popMap[$ym] ?? 0);
}

// ── Blotter Trend (6 months) — 1 query instead of 6 ─────────────────
$blotterStart = date('Y-m-01', strtotime('-5 months'));
$blotterSQL   = "SELECT DATE_FORMAT(created_at,'%Y-%m') AS ym, COUNT(*) AS cnt
                 FROM blotter_cases
                 WHERE created_at >= '$blotterStart'"
               . ($selectedBrgy ? " AND barangay_id=$selectedBrgy" : "")
               . " GROUP BY ym";
$blotterMap   = array_column($db->query($blotterSQL)->fetchAll(), 'cnt', 'ym');
$blotterLabels = []; $blotterValues = [];
for ($i=5; $i>=0; $i--) {
    $ym = date('Y-m', strtotime("-$i months"));
    $blotterLabels[] = date('M Y', strtotime("-$i months"));
    $blotterValues[] = (int)($blotterMap[$ym] ?? 0);
}

// ── Certificates by type ──────────────────────────────────
$certSQL = "SELECT certificate_type, COUNT(*) as cnt FROM certificates" . ($selectedBrgy ? " WHERE barangay_id=$selectedBrgy" : "") . " GROUP BY certificate_type ORDER BY cnt DESC";
$certQ = $db->query($certSQL)->fetchAll();
$certTypes = []; $certCounts = [];
foreach ($certQ as $r) { $certTypes[] = $r['certificate_type']; $certCounts[] = $r['cnt']; }
$topCert = !empty($certTypes) ? $certTypes[0] : '—';

// ── Top Barangays — JOIN instead of correlated subqueries ─────────────
// When a specific barangay is selected, scope each derived table to that barangay
// so MySQL can use the composite indexes directly instead of materialising full-table scans.
$brgySubFilter = $selectedBrgy ? " AND barangay_id=$selectedBrgy" : "";
$brgyStatsSQL = "SELECT b.barangay_name,
    COALESCE(r_cnt.residents, 0)  AS residents,
    COALESCE(h_cnt.households, 0) AS households,
    COALESCE(bl_cnt.blotter, 0)   AS blotter
FROM barangays b
LEFT JOIN (SELECT barangay_id, COUNT(*) AS residents FROM residents WHERE resident_status='Active'$brgySubFilter GROUP BY barangay_id) r_cnt ON r_cnt.barangay_id = b.id
LEFT JOIN (SELECT barangay_id, COUNT(*) AS households FROM households WHERE 1=1$brgySubFilter GROUP BY barangay_id) h_cnt ON h_cnt.barangay_id = b.id
LEFT JOIN (SELECT barangay_id, COUNT(*) AS blotter FROM blotter_cases WHERE 1=1$brgySubFilter GROUP BY barangay_id) bl_cnt ON bl_cnt.barangay_id = b.id
WHERE b.status='active'"
. ($selectedBrgy ? " AND b.id=$selectedBrgy" : "")
. " ORDER BY residents DESC LIMIT 5";
$brgyStats = $db->query($brgyStatsSQL)->fetchAll();

$pageTitle = '';
?>

<?php
$pageIcon     = 'bi-speedometer2';
$pageTitle    = 'Dashboard Overview';
$pageSubtitle = '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favcon.png">
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favcon.png">
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $pageTitle ?> — <?= APP_NAME ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=3.0">
<style>
/* ── Section Titles ───────────────────────── */
.sec-title {
    font-size: .75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: #052659;
    margin-bottom: 10px;
    margin-top: 4px;
    display: flex;
    align-items: center;
    gap: 6px;
    padding-bottom: 6px;
    border-bottom: 2px solid #e2eaf4;
}
.sec-title::after { display: none; }

/* ── Stat / Info Cards ────────────────────── */
.pcard {
    background: #ffffff;
    border-radius: 14px;
    padding: 18px 16px 14px;
    display: flex;
    flex-direction: column;
    gap: 10px;
    height: 100%;
    transition: transform .15s, box-shadow .15s;
    border: 1px solid #e8eef4;
    box-shadow: 0 2px 8px rgba(2,16,36,0.07);
}
.pcard:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 24px rgba(2,16,36,0.13);
    border-color: #c5d8e8;
}
.pcard-icon{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1rem;}
.pcard-val{font-size:1.25rem;font-weight:800;color:#021024;line-height:1.1;}
.pcard-lbl{font-size:.68rem;color:#052659;font-weight:500;line-height:1.3;}
.pcard-foot{border-top:1px solid rgba(0,0,0,.07);padding-top:7px;}
.pcard-foot a{font-size:.68rem;font-weight:600;text-decoration:none;display:flex;align-items:center;gap:4px;}

/* ── Chart Cards ──────────────────────────── */
.chart-card {
    background: #ffffff;
    border: 1px solid #e8eef4;
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(2,16,36,0.07);
}
.chart-card .ch {
    padding: 10px 16px;
    font-size: .78rem;
    font-weight: 600;
    color: #021024;
    background: #f8fafc;
    border-bottom: 1px solid #e8eef4;
    display: flex;
    align-items: center;
    gap: 6px;
}
.chart-card .cb { padding: 12px 14px; }

.table th,.table td{padding:4px 8px;font-size:.75rem;}
.table thead th{font-size:.68rem;text-transform:uppercase;color:#3a7fa8;}
.badge{font-size:.63rem;}
.btn-xs{padding:1px 7px;font-size:.7rem;}

/* ── Download Buttons ─────────────────────── */
.dl-box{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:8px;font-size:.78rem;font-weight:600;border:none;cursor:pointer;transition:opacity .15s,transform .12s;white-space:nowrap;}
.dl-box:hover{opacity:.88;transform:translateY(-1px);text-decoration:none;}
.dl-box-excel{background:#16a34a;color:#fff;}.dl-box-excel:hover{color:#fff;}
.dl-box-pdf{background:#dc2626;color:#fff;}.dl-box-pdf:hover{color:#fff;}

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
/* Hero image — right half only, faded into the gradient */
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
<?php include __DIR__.'/../includes/sidebar_super.php'; ?>
<?php include __DIR__.'/../includes/topbar.php'; ?>

<div class="main-content">

  <?php
    $fullName  = mb_convert_case(trim($u['name']), MB_CASE_TITLE, 'UTF-8');
    $hour      = (int)date('H');
    $greeting  = $hour < 12 ? 'Good Morning' : ($hour < 18 ? 'Good Afternoon' : 'Good Evening');
    $greetIcon = $hour < 12 ? 'bi-sunrise-fill' : ($hour < 18 ? 'bi-sun-fill' : 'bi-moon-stars-fill');
    // Fetch hero banner from landing_content
    $bannerBg = '';
    try {
        $lcRow = $db->query("SELECT banner_path FROM landing_content WHERE id=1")->fetch();
        if (!empty($lcRow['banner_path'])) {
            $bannerUrl = UPLOAD_URL . $lcRow['banner_path'];
            $bannerBg  = htmlspecialchars($bannerUrl, ENT_QUOTES);
        }
    } catch (\Throwable $e) {}
  ?>
  <div class="welcome-banner" <?= $bannerBg ? 'data-banner="'.$bannerBg.'"' : '' ?>>
    <?php if ($bannerBg): ?>
    <div class="welcome-banner-img" style="background-image:url('<?= $bannerBg ?>')"></div>
    <?php endif; ?>
    <div class="welcome-banner-text">
      <div class="welcome-banner-title">
        <i class="bi bi-hand-wave" style="color:rgba(255,220,80,0.9);font-size:1.1rem;vertical-align:middle;margin-right:6px;"></i>Welcome Back, <?= htmlspecialchars($fullName) ?>!
      </div>
      <div class="welcome-banner-sub">
        <i class="bi bi-sun-fill" id="bannerGreetIcon"></i>
        <span id="bannerGreet"></span>
        <span class="banner-dot"></span>
        <span id="bannerClock"></span>
      </div>
    </div>
    <div class="welcome-banner-icon">
      <i class="bi bi-speedometer2"></i>
    </div>
  </div>

  <!-- Barangay Filter -->
  <div style="margin-bottom:22px;display:flex;justify-content:flex-end;align-items:center;gap:10px;background:#fff;border:1px solid #e8eef4;border-radius:12px;padding:10px 16px;box-shadow:0 1px 4px rgba(2,16,36,0.05);">
    <form method="GET" style="display:flex;align-items:center;gap:8px;">
      <select name="barangay_id" class="form-select form-select-sm" style="width:200px;font-size:.78rem;" onchange="this.form.submit()">
        <option value="">All Barangays</option>
        <?php foreach ($barangays as $b): ?>
          <option value="<?= $b['id'] ?>" <?= $selectedBrgy==$b['id']?'selected':'' ?>><?= htmlspecialchars($b['barangay_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <a href="export_dashboard_records.php?format=excel<?= $selectedBrgy ? '&barangay_id='.$selectedBrgy : '' ?>"
       class="dl-box dl-box-excel" style="text-decoration:none;">
        <i class="bi bi-file-earmark-excel-fill"></i> Download Excel
    </a>
    <a href="export_dashboard_records.php?format=pdf<?= $selectedBrgy ? '&barangay_id='.$selectedBrgy : '' ?>"
       target="_blank" class="dl-box dl-box-pdf" style="text-decoration:none;">
        <i class="bi bi-file-earmark-pdf-fill"></i> Download PDF
    </a>
  </div>

  <!-- ① OVERVIEW ─────────────────────────────────────────── -->
  <div class="row g-2 mb-3">
    <?php
    $ov = [
      ['label'=>'Total Residents',    'value'=>$stats['total_residents'],  'icon'=>'bi-people-fill',            'link'=>'#','ic'=>'#2563eb','bg'=>'#ffffff','ibg'=>'#dbeafe'],
      ['label'=>'Total Households',   'value'=>$stats['total_households'], 'icon'=>'bi-house-fill',             'link'=>'#','ic'=>'#16a34a','bg'=>'#ffffff','ibg'=>'#dcfce7'],
      ['label'=>'Total Blotter Cases','value'=>$stats['total_blotter'],    'icon'=>'bi-shield-exclamation',     'link'=>'#','ic'=>'#ef4444','bg'=>'#ffffff','ibg'=>'#fee2e2'],
      ['label'=>'Certificates Issued','value'=>$stats['total_certs'],      'icon'=>'bi-file-earmark-check-fill','link'=>'#','ic'=>'#7c3aed','bg'=>'#ffffff','ibg'=>'#ede9fe'],
    ];
    foreach ($ov as $c): ?>
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
  <!-- Transferred Residents per Barangay ──────────────────── -->
  <?php if (!empty($transferredPerBrgy)): ?>
  <div class="sec-title"><i class="bi bi-person-walking text-primary"></i>Transferred Residents by Barangay</div>
  <div class="row g-2 mb-3">
    <!-- Summary cards -->
    <div class="col-6 col-md-3">
      <div class="pcard" style="background:#e8f4fb">
        <div class="d-flex align-items-center gap-2">
          <div class="pcard-icon" style="background:#b0d8f0"><i class="bi bi-person-walking" style="color:#1a4f7a"></i></div>
          <div><div class="pcard-val"><?= number_format($stats['res_transferred']) ?></div><div class="pcard-lbl">Total Transferred</div></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="pcard" style="background:#fce4ec">
        <div class="d-flex align-items-center gap-2">
          <div class="pcard-icon" style="background:#f8bbd0"><i class="bi bi-arrow-up-right-circle-fill" style="color:#ad1457"></i></div>
          <div>
            <div class="pcard-val"><?= number_format(array_sum(array_column($transferredPerBrgy,'outside_transfer'))) ?></div>
            <div class="pcard-lbl">Outside Municipality</div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="pcard" style="background:#e8f4fb">
        <div class="d-flex align-items-center gap-2">
          <div class="pcard-icon" style="background:#b0d8f0"><i class="bi bi-arrow-left-right" style="color:#1a4f7a"></i></div>
          <div>
            <div class="pcard-val"><?= number_format(array_sum(array_column($transferredPerBrgy,'local_transfer'))) ?></div>
            <div class="pcard-lbl">Local Transfer</div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="pcard" style="background:#fff3e0">
        <div class="d-flex align-items-center gap-2">
          <div class="pcard-icon" style="background:#ffd9a8"><i class="bi bi-buildings-fill" style="color:#e65100"></i></div>
          <div>
            <div class="pcard-val"><?= count($transferredPerBrgy) ?></div>
            <div class="pcard-lbl">Barangays Affected</div>
          </div>
        </div>
      </div>
    </div>
    <!-- Per-barangay table -->
    <div class="col-12">
      <div class="chart-card">
        <div class="ch d-flex justify-content-between align-items-center">
          <span><i class="bi bi-table me-1"></i>Transferred Records per Barangay</span>
          <span style="font-size:.7rem;color:#3a7fa8;"><?= $selectedBrgy ? 'Filtered view' : 'All barangays' ?></span>
        </div>
        <div class="cb p-0">
          <div style="max-height:260px;overflow-y:auto;">
            <table class="table table-sm mb-0">
              <thead style="position:sticky;top:0;background:#f4f7fa;z-index:1;">
                <tr>
                  <th style="font-size:.68rem;text-transform:uppercase;color:#3a7fa8;">#</th>
                  <th style="font-size:.68rem;text-transform:uppercase;color:#3a7fa8;">Barangay</th>
                  <th style="font-size:.68rem;text-transform:uppercase;color:#3a7fa8;text-align:center;">Total Transferred</th>
                  <th style="font-size:.68rem;text-transform:uppercase;color:#3a7fa8;text-align:center;">Local</th>
                  <th style="font-size:.68rem;text-transform:uppercase;color:#3a7fa8;text-align:center;">Outside</th>
                  <th style="font-size:.68rem;text-transform:uppercase;color:#3a7fa8;">Bar</th>
                </tr>
              </thead>
              <tbody>
              <?php
              $maxT = max(array_column($transferredPerBrgy,'total_transferred')) ?: 1;
              foreach ($transferredPerBrgy as $i => $tb): ?>
                <tr style="border-bottom:1px solid #e8f4fb;">
                  <td style="color:#94a3b8;font-size:.75rem;"><?= $i+1 ?></td>
                  <td style="font-weight:600;font-size:.78rem;color:#1a2e1f;"><?= htmlspecialchars($tb['barangay_name']) ?></td>
                  <td style="text-align:center;">
                    <span class="badge bg-primary" style="font-size:.68rem;"><?= $tb['total_transferred'] ?></span>
                  </td>
                  <td style="text-align:center;">
                    <span class="badge bg-success" style="font-size:.68rem;"><?= $tb['local_transfer'] ?></span>
                  </td>
                  <td style="text-align:center;">
                    <span class="badge bg-danger" style="font-size:.68rem;"><?= $tb['outside_transfer'] ?></span>
                  </td>
                  <td style="width:120px;vertical-align:middle;">
                    <div style="background:#e2e8f0;border-radius:4px;height:8px;overflow:hidden;">
                      <div style="background:#1a4f7a;height:100%;width:<?= round(($tb['total_transferred']/$maxT)*100) ?>%;border-radius:4px;"></div>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
              <tfoot style="background:#f8fafc;border-top:2px solid #d0ecd6;">
                <tr>
                  <td colspan="2" style="font-weight:700;font-size:.75rem;color:#1a2e1f;padding:6px 8px;">TOTAL</td>
                  <td style="text-align:center;font-weight:700;font-size:.75rem;">
                    <span class="badge bg-primary"><?= $stats['res_transferred'] ?></span>
                  </td>
                  <td style="text-align:center;font-weight:700;font-size:.75rem;">
                    <span class="badge bg-success"><?= array_sum(array_column($transferredPerBrgy,'local_transfer')) ?></span>
                  </td>
                  <td style="text-align:center;font-weight:700;font-size:.75rem;">
                    <span class="badge bg-danger"><?= array_sum(array_column($transferredPerBrgy,'outside_transfer')) ?></span>
                  </td>
                  <td></td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

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
      ['label'=>'PWD',              'value'=>$stats['total_pwd'],   'icon'=>'bi-person-wheelchair','link'=>'#','ic'=>'#6a1b9a','bg'=>'#ede7f6','ibg'=>'#d7c8ef'],
      ['label'=>'Senior Citizens',  'value'=>$stats['total_senior'],'icon'=>'bi-person-fill',      'link'=>'#','ic'=>'#e65100','bg'=>'#fff3e0','ibg'=>'#ffd9a8'],
      ['label'=>'Solo Parents',     'value'=>$stats['total_solo'],  'icon'=>'bi-person-heart',     'link'=>'#','ic'=>'#ad1457','bg'=>'#fce4ec','ibg'=>'#f8bbd0'],
      ['label'=>'4Ps Beneficiaries','value'=>$stats['total_4ps'],   'icon'=>'bi-heart-fill',       'link'=>'#','ic'=>'#00695c','bg'=>'#e0f2f1','ibg'=>'#b2dfdb'],
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
    <div class="col-md-8">
      <div class="chart-card h-100">
        <div class="ch"><i class="bi bi-graph-up-arrow"></i>Blotter Cases Trend (Last 6 Months)</div>
        <div class="cb"><canvas id="blotterTrendChart" style="max-height:160px"></canvas></div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="chart-card h-100">
        <div class="ch"><i class="bi bi-table"></i>Top Barangays by Population</div>
        <div class="cb p-0">
          <table class="table table-sm mb-0">
            <thead><tr><th>Barangay</th><th>Residents</th><th>Blotter</th></tr></thead>
            <tbody>
            <?php foreach ($brgyStats as $bs): ?>
              <tr>
                <td><?= htmlspecialchars($bs['barangay_name']) ?></td>
                <td><span class="badge bg-primary"><?= number_format($bs['residents']) ?></span></td>
                <td><span class="badge bg-danger"><?= $bs['blotter'] ?></span></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- ⑥ DOCUMENTS & SERVICES ─────────────────────────────── -->
  <div class="sec-title"><i class="bi bi-file-earmark-text-fill text-primary"></i>Documents &amp; Services</div>
  <div class="row g-2 mb-3">
    <div class="col-md-4">
      <div class="row g-2 h-100">
        <div class="col-12">
          <div class="pcard" style="background:#e8f4fb">
            <div class="d-flex align-items-center gap-2">
              <div class="pcard-icon" style="background:#b0d8f0"><i class="bi bi-file-earmark-check-fill" style="color:#1a4f7a"></i></div>
              <div><div class="pcard-val"><?= number_format($stats['total_certs']) ?></div><div class="pcard-lbl">Total Certificates Issued</div></div>
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
  data:{labels:['Male','Female'],datasets:[{data:[<?= $genderData['Male'] ?>,<?= $genderData['Female'] ?>],backgroundColor:['#3b9f5a','#e91e8c'],borderWidth:2,borderColor:'#fff',hoverBorderWidth:3,hoverBorderColor:['#1a5cb8','#c0006c'],hoverOffset:10}]},
  options:{responsive:true,plugins:{legend:{position:'bottom',labels:{font:{size:10},boxWidth:12}},tooltip:pctTip}}
});

// Voter
new Chart(document.getElementById('voterChart'),{
  type:'doughnut',
  data:{labels:['Registered','Not Registered'],datasets:[{data:[<?= $voterData['Registered'] ?>,<?= $voterData['Not Registered'] ?>],backgroundColor:['#1a4f7a','#e0e0e0'],borderWidth:2,borderColor:'#fff',hoverBorderWidth:3,hoverBorderColor:['#0d1f33','#9e9e9e'],hoverOffset:10}]},
  options:{responsive:true,plugins:{legend:{position:'bottom',labels:{font:{size:10},boxWidth:12}},tooltip:pctTip}}
});

// Employment Pie
new Chart(document.getElementById('empChart'),{
  type:'pie',
  data:{labels:<?= json_encode($empStatuses) ?>,datasets:[{data:<?= json_encode($empValues) ?>,backgroundColor:['#1a4f7a','#e53935','#43a047','#fb8c00','#8e24aa'],borderWidth:2,borderColor:'#fff',hoverBorderWidth:3,hoverBorderColor:['#0d1f33','#b71c1c','#1a4f7a','#e65100','#6a1b9a'],hoverOffset:10}]},
  options:{responsive:true,plugins:{legend:{position:'bottom',labels:{font:{size:9},boxWidth:10,padding:5}},tooltip:pctTip}}
});

// Employment Bar
new Chart(document.getElementById('empBarChart'),{
  type:'bar',
  data:{labels:<?= json_encode($empStatuses) ?>,datasets:[{data:<?= json_encode($empValues) ?>,backgroundColor:['#1a4f7a','#e53935','#43a047','#fb8c00','#8e24aa'],borderRadius:5,borderWidth:0,hoverBorderWidth:2,hoverBorderColor:['#0d1f33','#b71c1c','#1a4f7a','#e65100','#6a1b9a'],hoverBackgroundColor:['#2d5a8e','#ef5350','#66bb6a','#ffa726','#ab47bc']}]},
  options:{responsive:true,plugins:{legend:{display:false}},scales:{x:tt,y:{...tt,beginAtZero:true}}}
});

// Blotter Trend
new Chart(document.getElementById('blotterTrendChart'),{
  type:'line',
  data:{labels:<?= json_encode($blotterLabels) ?>,datasets:[{label:'Cases',data:<?= json_encode($blotterValues) ?>,borderColor:'#c62828',backgroundColor:'rgba(198,40,40,0.08)',tension:0.4,fill:true,pointRadius:4,pointBackgroundColor:'#c62828',pointHoverRadius:7,pointHoverBackgroundColor:'#c62828',pointHoverBorderColor:'#fff',pointHoverBorderWidth:2}]},
  options:{responsive:true,plugins:{legend:{display:false}},scales:{x:tt,y:{...tt,beginAtZero:true}}}
});

// Certificates by Type
new Chart(document.getElementById('certChart'),{
  type:'bar',
  data:{labels:<?= json_encode($certTypes ?: ['No Data']) ?>,datasets:[{data:<?= json_encode($certCounts ?: [0]) ?>,backgroundColor:['#1a4f7a','#1a4f7a','#e65100','#6a1b9a','#ad1457','#00695c'],borderRadius:5,borderWidth:0,hoverBorderWidth:2,hoverBorderColor:['#0d47a1','#1b5e20','#bf360c','#4a148c','#880e4f','#004d40'],hoverBackgroundColor:['#1976d2','#388e3c','#f4511e','#7b1fa2','#c2185b','#00796b']}]},
  options:{responsive:true,plugins:{legend:{display:false}},scales:{x:{...tt,ticks:{...tt.ticks,maxRotation:30}},y:{...tt,beginAtZero:true}}}
});

// Population Trend
new Chart(document.getElementById('popTrendChart'),{
  type:'line',
  data:{labels:<?= json_encode($popLabels) ?>,datasets:[{label:'New Residents',data:<?= json_encode($popValues) ?>,borderColor:'#1a4f7a',backgroundColor:'rgba(46,125,50,0.08)',tension:0.4,fill:true,pointRadius:4,pointBackgroundColor:'#1a4f7a',pointHoverRadius:7,pointHoverBackgroundColor:'#1a4f7a',pointHoverBorderColor:'#fff',pointHoverBorderWidth:2}]},
  options:{responsive:true,plugins:{legend:{display:false}},scales:{x:tt,y:{...tt,beginAtZero:true}}}
});

function toggleSidebar(){document.getElementById('sidebar').classList.toggle('show');}

// ── Real-time banner clock ─────────────────────────────────
(function() {
    const days   = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    const months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    function tick() {
        // Always use Manila time (Asia/Manila = UTC+8)
        const now     = new Date();
        const manila  = new Date(now.toLocaleString('en-US', { timeZone: 'Asia/Manila' }));
        const hour    = manila.getHours();

        // Greeting & icon
        let greet, icon;
        if (hour >= 5 && hour < 12)       { greet = 'Good Morning';   icon = 'bi-sunrise-fill'; }
        else if (hour >= 12 && hour < 18) { greet = 'Good Afternoon'; icon = 'bi-sun-fill'; }
        else                              { greet = 'Good Evening';   icon = 'bi-moon-stars-fill'; }

        const greetEl = document.getElementById('bannerGreet');
        const iconEl  = document.getElementById('bannerGreetIcon');
        if (greetEl) greetEl.textContent = greet;
        if (iconEl)  iconEl.className    = 'bi ' + icon;

        // Clock
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
