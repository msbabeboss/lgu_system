<?php
require_once __DIR__ . '/../config/config.php';
requireRole('super_admin');

$db = Database::getInstance()->getConnection();
$u  = currentUser();

$barangays    = $db->query("SELECT * FROM barangays WHERE status='active' ORDER BY barangay_name")->fetchAll();
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

// Deceased = residents with status 'Deceased' + legacy archived records
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

// ── Employment ──────────────────────────────────────────────
$empSQL  = "SELECT occupation_status, COUNT(*) AS cnt
            FROM residents r $where
            AND r.occupation_status IN ('Employed','Unemployed','Self-Employed','Student','Retired')
            AND r.resident_status='Active'
            GROUP BY occupation_status";
$empMap  = array_column($db->query($empSQL)->fetchAll(), 'cnt', 'occupation_status');
$empStatuses = ['Employed','Unemployed','Self-Employed','Student','Retired'];
$empValues   = array_map(fn($s) => (int)($empMap[$s] ?? 0), $empStatuses);

// ── Population Trend (12 months) ─────────────────────────
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

// ── Blotter Trend (6 months) ──────────────────────────────
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

// ── Top Barangays ─────────────────────────────────────────
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

// ── Recent Residents ──────────────────────────────────────
$recentResidents = $db->query("SELECT r.*, b.barangay_name FROM residents r LEFT JOIN barangays b ON b.id=r.barangay_id ORDER BY r.created_at DESC LIMIT 5")->fetchAll();

// ── Recent Blotter Cases ──────────────────────────────────
$recentBlotter = $db->query("SELECT * FROM blotter_cases" . ($selectedBrgy ? " WHERE barangay_id=$selectedBrgy" : "") . " ORDER BY created_at DESC LIMIT 5")->fetchAll();

// ── Blotter Status Counts ─────────────────────────────────
$blotterStatusCounts = ['Open'=>0,'Under Investigation'=>0,'Settled'=>0,'Escalated'=>0,'Closed'=>0];
$bsQ = $db->query("SELECT status, COUNT(*) as cnt FROM blotter_cases WHERE status != 'Archived'" . ($selectedBrgy ? " AND barangay_id=$selectedBrgy" : '') . " GROUP BY status");
foreach ($bsQ->fetchAll() as $r) { if (isset($blotterStatusCounts[$r['status']])) $blotterStatusCounts[$r['status']] = (int)$r['cnt']; }

// ── Latest Notices ────────────────────────────────────────
$notices = $db->query("SELECT * FROM notices WHERE status != 'Archived' ORDER BY created_at DESC LIMIT 3")->fetchAll();

// ── Current Month Stats ───────────────────────────────────
$curFrom = date('Y-m-01');
$curTo   = date('Y-m-d');
$mnthRes = (int)$db->query("SELECT COUNT(*) FROM residents r $where AND DATE(r.created_at) BETWEEN '$curFrom' AND '$curTo'")->fetchColumn();
$mnthCert = (int)$db->query("SELECT COUNT(*) FROM certificates" . ($selectedBrgy ? " WHERE barangay_id=$selectedBrgy AND" : " WHERE") . " date_issued BETWEEN '$curFrom' AND '$curTo'")->fetchColumn();
$mnthBlotter = (int)$db->query("SELECT COUNT(*) FROM blotter_cases" . ($selectedBrgy ? " WHERE barangay_id=$selectedBrgy AND" : " WHERE") . " status != 'Archived' AND DATE(created_at) BETWEEN '$curFrom' AND '$curTo'")->fetchColumn();

$pageIcon     = 'bi-speedometer2';
$pageTitle    = 'Dashboard Overview';
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
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=3.0">
<style>
/* ── Layout ─────────────────────────────────────────────── */
body{background:#0f172a;}
.main-content{background:#0f172a;margin-top:80px !important;padding:12px 20px 20px 22px;}
.topbar{height:80px !important;}
.sidebar-brand{height:80px !important;}

/* ── Section Titles ─────────────────────────────────────── */
.sec-title{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;margin-bottom:8px;display:flex;align-items:center;gap:6px;}
.sec-title::after{content:'';flex:1;height:1px;background:#334155;}

/* ── KPI / Stat Cards ───────────────────────────────────── */
.pcard{border-radius:14px;padding:14px 14px 10px;display:flex;flex-direction:column;gap:9px;height:100%;transition:transform .15s,box-shadow .15s;border:1.5px solid rgba(255,255,255,0.08);box-shadow:0 2px 8px rgba(0,0,0,0.25);}
.pcard:hover{transform:translateY(-2px);box-shadow:0 6px 18px rgba(0,0,0,.35);}
.pcard-icon{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1rem;}
.pcard-val{font-size:1.4rem;font-weight:800;color:#fff;line-height:1.1;}
.pcard-lbl{font-size:.7rem;color:rgba(255,255,255,0.75);font-weight:500;line-height:1.3;}

/* ── Chart Cards ────────────────────────────────────────── */
.chart-card{background:#1e293b;border:1.5px solid #334155;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.2);}
.chart-card .ch{padding:8px 14px;font-size:.75rem;font-weight:600;color:#e2e8f0;background:#0f172a;border-bottom:1.5px solid #334155;display:flex;align-items:center;gap:6px;justify-content:space-between;}
.chart-card .cb{padding:10px 12px;}
.table{--bs-table-bg:transparent;--bs-table-color:#e2e8f0;--bs-table-border-color:#334155;}
.table th{font-size:.68rem;text-transform:uppercase;color:#94a3b8;font-weight:600;}
.table td{font-size:.75rem;color:#cbd5e1;}
.badge{font-size:.63rem;}
.btn-xs{padding:1px 7px;font-size:.7rem;}

/* ── Download Buttons ───────────────────────────────────── */
.dl-box{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:8px;font-size:.78rem;font-weight:600;border:none;cursor:pointer;transition:opacity .15s,transform .12s;white-space:nowrap;}
.dl-box:hover{opacity:.88;transform:translateY(-1px);text-decoration:none;}
.dl-box-excel{background:#16a34a;color:#fff;}.dl-box-excel:hover{color:#fff;}
.dl-box-pdf{background:#dc2626;color:#fff;}.dl-box-pdf:hover{color:#fff;}

/* ── Quick Links ────────────────────────────────────────── */
.qlink-card{background:#1e293b;border:1.5px solid #334155;border-radius:12px;padding:14px 12px;display:flex;flex-direction:column;align-items:center;text-align:center;min-height:85px;gap:8px;text-decoration:none;transition:transform .15s,background .15s,box-shadow .15s;}
.qlink-card:hover{transform:translateY(-2px);background:#0f172a;box-shadow:0 6px 18px rgba(0,0,0,.4);}
.qlink-icon{width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,0.12);display:flex;align-items:center;justify-content:center;font-size:1.1rem;color:#fff;flex-shrink:0;}
.qlink-lbl{font-size:.72rem;font-weight:700;color:#e2e8f0;line-height:1.2;}

/* ── Welcome Banner ─────────────────────────────────────── */
.welcome-banner{background-image:url('<?= BASE_URL ?>/assets/img/bgimage.webp');background-image:-webkit-image-set(url('<?= BASE_URL ?>/assets/img/bgimage.webp') 1x);background-image:url('<?= BASE_URL ?>/assets/img/bgimage.jpg');background-size:cover;background-position:center;border-radius:18px;padding:22px 28px 20px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;overflow:hidden;position:relative;min-height:140px;box-shadow:0 4px 20px rgba(2,16,36,0.22);}
.welcome-banner::before{content:'';position:absolute;inset:0;background:linear-gradient(to right,rgba(5,38,89,0.88) 0%,rgba(10,60,140,0.70) 50%,rgba(5,38,89,0.15) 100%);z-index:0;pointer-events:none;}
.welcome-banner-img{position:absolute;top:0;right:0;bottom:0;width:55%;background-size:cover;background-position:center right;background-repeat:no-repeat;-webkit-mask-image:linear-gradient(to right,transparent 0%,rgba(0,0,0,0.18) 30%,rgba(0,0,0,0.32) 100%);mask-image:linear-gradient(to right,transparent 0%,rgba(0,0,0,0.18) 30%,rgba(0,0,0,0.32) 100%);opacity:0.38;pointer-events:none;}
.welcome-banner-text{position:relative;z-index:1;}
.welcome-banner-title{font-size:1.5rem;font-weight:800;color:#fff;letter-spacing:-0.01em;margin-bottom:5px;line-height:1.2;}
.welcome-banner-sub{font-size:0.85rem;color:rgba(255,255,255,0.85);display:flex;align-items:center;gap:7px;flex-wrap:wrap;}
.welcome-banner-sub strong{color:#fff;font-weight:700;}
.banner-dot{width:4px;height:4px;background:rgba(255,255,255,0.5);border-radius:50%;display:inline-block;flex-shrink:0;}
.welcome-banner-icon{position:relative;z-index:1;font-size:3.2rem;color:rgba(255,255,255,0.15);flex-shrink:0;line-height:1;}
html.dark-mode .welcome-banner{box-shadow:0 4px 20px rgba(0,0,0,0.5);}

/* ── Filter bar dark override ───────────────────────────── */
.filter-bar{background:#1e293b;border:1.5px solid #334155;border-radius:12px;padding:10px 16px;box-shadow:0 1px 4px rgba(0,0,0,0.2);}
.filter-bar .form-select{background:#0f172a;color:#e2e8f0;border-color:#334155;font-size:.78rem;}
.filter-bar .form-select option{background:#0f172a;}
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
    $bannerBg = '';
    try {
        $lcRow = $db->query("SELECT banner_path FROM landing_content WHERE id=1")->fetch();
        if (!empty($lcRow['banner_path'])) {
            $bannerUrl = UPLOAD_URL . $lcRow['banner_path'];
            $bannerBg  = htmlspecialchars($bannerUrl, ENT_QUOTES);
        }
    } catch (\Throwable $e) {}
  ?>

  <!-- ① WELCOME BANNER ───────────────────────────────────── -->
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

  <!-- ② FILTER BAR + DOWNLOAD BUTTONS ────────────────────── -->
  <div class="filter-bar mb-3 d-flex justify-content-end align-items-center gap-2 flex-wrap">
    <form method="GET" style="display:flex;align-items:center;gap:8px;">
      <select name="barangay_id" class="form-select form-select-sm" style="width:200px;" onchange="this.form.submit()">
        <option value="">All Barangays</option>
        <?php foreach ($barangays as $b): ?>
          <option value="<?= $b['id'] ?>" <?= $selectedBrgy==$b['id']?'selected':'' ?>><?= htmlspecialchars($b['barangay_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <a href="export_dashboard_records.php?format=excel<?= $selectedBrgy ? '&barangay_id='.$selectedBrgy : '' ?>"
       class="dl-box dl-box-excel"><i class="bi bi-file-earmark-excel-fill"></i> Download Excel</a>
    <a href="export_dashboard_records.php?format=pdf<?= $selectedBrgy ? '&barangay_id='.$selectedBrgy : '' ?>"
       target="_blank" class="dl-box dl-box-pdf"><i class="bi bi-file-earmark-pdf-fill"></i> Download PDF</a>
  </div>

  <!-- ③ KPI CARDS — PRIMARY ───────────────────────────────── -->
  <div class="sec-title"><i class="bi bi-speedometer2 text-info"></i>Consolidated Overview</div>
  <div class="row g-2 mb-2">
    <div class="col-6 col-md-3">
      <a href="barangay_records.php" style="text-decoration:none;">
        <div class="pcard" style="background:#1e3a5f;">
          <div class="d-flex align-items-center gap-2">
            <div class="pcard-icon" style="background:#2d5a8e;"><i class="bi bi-people-fill" style="color:#fff;"></i></div>
            <div><div class="pcard-val"><?= number_format($stats['total_residents']) ?></div><div class="pcard-lbl">Total Residents</div></div>
          </div>
        </div>
      </a>
    </div>
    <div class="col-6 col-md-3">
      <a href="barangay_records.php" style="text-decoration:none;">
        <div class="pcard" style="background:#14532d;">
          <div class="d-flex align-items-center gap-2">
            <div class="pcard-icon" style="background:#166534;"><i class="bi bi-house-fill" style="color:#fff;"></i></div>
            <div><div class="pcard-val"><?= number_format($stats['total_households']) ?></div><div class="pcard-lbl">Total Households</div></div>
          </div>
        </div>
      </a>
    </div>
    <div class="col-6 col-md-3">
      <a href="barangay_records.php" style="text-decoration:none;">
        <div class="pcard" style="background:#7f1d1d;">
          <div class="d-flex align-items-center gap-2">
            <div class="pcard-icon" style="background:#991b1b;"><i class="bi bi-shield-exclamation" style="color:#fff;"></i></div>
            <div><div class="pcard-val"><?= number_format($stats['total_blotter']) ?></div><div class="pcard-lbl">Total Blotter Cases</div></div>
          </div>
        </div>
      </a>
    </div>
    <div class="col-6 col-md-3">
      <a href="barangay_records.php" style="text-decoration:none;">
        <div class="pcard" style="background:#4c1d95;">
          <div class="d-flex align-items-center gap-2">
            <div class="pcard-icon" style="background:#5b21b6;"><i class="bi bi-file-earmark-check-fill" style="color:#fff;"></i></div>
            <div><div class="pcard-val"><?= number_format($stats['total_certs']) ?></div><div class="pcard-lbl">Certificates Issued</div></div>
          </div>
        </div>
      </a>
    </div>
  </div>

  <!-- ③b KPI CARDS — RESIDENT STATUS ─────────────────────── -->
  <div class="sec-title mt-2"><i class="bi bi-person-lines-fill text-primary"></i>Resident Status</div>
  <div class="row g-2 mb-2">
    <div class="col-6 col-md-3">
      <div class="pcard" style="background:#1e3a5f;">
        <div class="d-flex align-items-center gap-2">
          <div class="pcard-icon" style="background:#2d5a8e;"><i class="bi bi-person-check-fill" style="color:#fff;"></i></div>
          <div><div class="pcard-val"><?= number_format($stats['res_active']) ?></div><div class="pcard-lbl">Active</div></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="pcard" style="background:#1e293b;">
        <div class="d-flex align-items-center gap-2">
          <div class="pcard-icon" style="background:#334155;"><i class="bi bi-person-dash-fill" style="color:#fff;"></i></div>
          <div><div class="pcard-val"><?= number_format($stats['res_inactive']) ?></div><div class="pcard-lbl">Inactive</div></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="pcard" style="background:#1e3a5f;">
        <div class="d-flex align-items-center gap-2">
          <div class="pcard-icon" style="background:#2d5a8e;"><i class="bi bi-person-walking" style="color:#fff;"></i></div>
          <div><div class="pcard-val"><?= number_format($stats['res_transferred']) ?></div><div class="pcard-lbl">Transferred</div></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="pcard" style="background:#7f1d1d;">
        <div class="d-flex align-items-center gap-2">
          <div class="pcard-icon" style="background:#991b1b;"><i class="bi bi-person-x-fill" style="color:#fff;"></i></div>
          <div><div class="pcard-val"><?= number_format($stats['res_deceased']) ?></div><div class="pcard-lbl">Deceased</div></div>
        </div>
      </div>
    </div>
  </div>

  <!-- ③c TRANSFERRED SUMMARY ──────────────────────────────── -->
  <div class="row g-2 mb-3">
    <div class="col-6 col-md-3">
      <div class="pcard" style="background:#1e3a5f;">
        <div class="d-flex align-items-center gap-2">
          <div class="pcard-icon" style="background:#2d5a8e;"><i class="bi bi-person-walking" style="color:#fff;"></i></div>
          <div><div class="pcard-val"><?= number_format($stats['res_transferred']) ?></div><div class="pcard-lbl">Total Transferred</div></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="pcard" style="background:#9d174d;">
        <div class="d-flex align-items-center gap-2">
          <div class="pcard-icon" style="background:#be185d;"><i class="bi bi-arrow-up-right-circle-fill" style="color:#fff;"></i></div>
          <div><div class="pcard-val"><?= number_format(array_sum(array_column($transferredPerBrgy,'outside_transfer'))) ?></div><div class="pcard-lbl">Outside Municipality</div></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="pcard" style="background:#1e3a5f;">
        <div class="d-flex align-items-center gap-2">
          <div class="pcard-icon" style="background:#2d5a8e;"><i class="bi bi-arrow-left-right" style="color:#fff;"></i></div>
          <div><div class="pcard-val"><?= number_format(array_sum(array_column($transferredPerBrgy,'local_transfer'))) ?></div><div class="pcard-lbl">Local Transfer</div></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="pcard" style="background:#78350f;">
        <div class="d-flex align-items-center gap-2">
          <div class="pcard-icon" style="background:#92400e;"><i class="bi bi-buildings-fill" style="color:#fff;"></i></div>
          <div><div class="pcard-val"><?= count($transferredPerBrgy) ?></div><div class="pcard-lbl">Barangays Affected</div></div>
        </div>
      </div>
    </div>
  </div>

  <!-- ④ POPULATION OVERVIEW ───────────────────────────────── -->
  <div class="sec-title"><i class="bi bi-graph-up text-success"></i>Population Overview — Last 12 Months</div>
  <div class="row g-2 mb-3">
    <!-- Left: Line Chart -->
    <div class="col-md-7">
      <div class="chart-card h-100">
        <div class="ch" style="background:#0f172a;color:#e2e8f0;">
          <span><i class="bi bi-graph-up me-1"></i>New Residents — Last 12 Months</span>
        </div>
        <div class="cb">
          <canvas id="popTrendChart" style="max-height:160px;"></canvas>
        </div>
      </div>
    </div>
    <!-- Right: Sector 2x2 -->
    <div class="col-md-5">
      <div class="row g-2 h-100">
        <div class="col-6">
          <div class="pcard" style="background:#5b21b6;">
            <div class="d-flex align-items-center gap-2">
              <div class="pcard-icon" style="background:#6d28d9;"><i class="bi bi-person-wheelchair" style="color:#fff;"></i></div>
              <div><div class="pcard-val"><?= number_format($stats['total_pwd']) ?></div><div class="pcard-lbl">PWD</div></div>
            </div>
          </div>
        </div>
        <div class="col-6">
          <div class="pcard" style="background:#9a3412;">
            <div class="d-flex align-items-center gap-2">
              <div class="pcard-icon" style="background:#c2410c;"><i class="bi bi-person-fill" style="color:#fff;"></i></div>
              <div><div class="pcard-val"><?= number_format($stats['total_senior']) ?></div><div class="pcard-lbl">Senior Citizens</div></div>
            </div>
          </div>
        </div>
        <div class="col-6">
          <div class="pcard" style="background:#9d174d;">
            <div class="d-flex align-items-center gap-2">
              <div class="pcard-icon" style="background:#be185d;"><i class="bi bi-person-heart" style="color:#fff;"></i></div>
              <div><div class="pcard-val"><?= number_format($stats['total_solo']) ?></div><div class="pcard-lbl">Solo Parents</div></div>
            </div>
          </div>
        </div>
        <div class="col-6">
          <div class="pcard" style="background:#065f46;">
            <div class="d-flex align-items-center gap-2">
              <div class="pcard-icon" style="background:#047857;"><i class="bi bi-heart-fill" style="color:#fff;"></i></div>
              <div><div class="pcard-val"><?= number_format($stats['total_4ps']) ?></div><div class="pcard-lbl">4Ps Beneficiaries</div></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ⑤ QUICK LINKS ──────────────────────────────────────── -->
  <div class="sec-title"><i class="bi bi-lightning-charge-fill" style="color:#fbbf24;"></i>Quick Links</div>
  <div class="row g-2 mb-3">
    <?php
    $quickLinks = [
      ['label'=>'Barangay List',    'icon'=>'bi-geo-alt-fill',               'url'=>BASE_URL.'/super_admin/barangays.php'],
      ['label'=>'Barangay Records', 'icon'=>'bi-journal-bookmark-fill',       'url'=>BASE_URL.'/super_admin/barangay_records.php'],
      ['label'=>'Users',            'icon'=>'bi-people-fill',                 'url'=>BASE_URL.'/super_admin/users.php'],
      ['label'=>'Notifications',    'icon'=>'bi-bell-fill',                   'url'=>BASE_URL.'/super_admin/notifications.php'],
      ['label'=>'Announcements',    'icon'=>'bi-megaphone-fill',              'url'=>BASE_URL.'/super_admin/notices.php'],
      ['label'=>'Reports',          'icon'=>'bi-file-earmark-bar-graph-fill', 'url'=>BASE_URL.'/super_admin/reports.php'],
      ['label'=>'Requests',         'icon'=>'bi-inbox-fill',                  'url'=>BASE_URL.'/super_admin/requests.php'],
      ['label'=>'Activity Log',     'icon'=>'bi-clock-history',               'url'=>BASE_URL.'/super_admin/activity_log.php'],
      ['label'=>'Analytics',        'icon'=>'bi-bar-chart-line-fill',         'url'=>BASE_URL.'/super_admin/analytics.php'],
      ['label'=>'Forecasting',      'icon'=>'bi-cpu-fill',                    'url'=>BASE_URL.'/super_admin/forecasting.php'],
      ['label'=>'Audit Log',        'icon'=>'bi-shield-lock-fill',            'url'=>BASE_URL.'/super_admin/audit_log.php'],
      ['label'=>'Settings',         'icon'=>'bi-gear-fill',                   'url'=>BASE_URL.'/super_admin/settings.php'],
    ];
    foreach ($quickLinks as $ql): ?>
    <div class="col-6 col-sm-4 col-md-2">
      <a href="<?= $ql['url'] ?>" class="qlink-card">
        <div class="qlink-icon"><i class="bi <?= $ql['icon'] ?>"></i></div>
        <div class="qlink-lbl"><?= $ql['label'] ?></div>
      </a>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- ⑥ RECENT ACTIVITIES ─────────────────────────────────── -->
  <div class="sec-title"><i class="bi bi-clock-history text-warning"></i>Recent Activities</div>
  <div class="row g-2 mb-3">
    <!-- Left column -->
    <div class="col-md-8">
      <!-- Recent Residents -->
      <div class="chart-card mb-2">
        <div class="ch">
          <span><i class="bi bi-people-fill me-1"></i>Recent Residents</span>
        </div>
        <div class="cb p-0">
          <table class="table table-sm mb-0">
            <thead>
              <tr>
                <th>Name</th>
                <th>Barangay</th>
                <th>Date Registered</th>
              </tr>
            </thead>
            <tbody>
            <?php if (empty($recentResidents)): ?>
              <tr><td colspan="3" class="text-center" style="color:#64748b;font-size:.75rem;padding:12px;">No records found.</td></tr>
            <?php else: ?>
              <?php foreach ($recentResidents as $rr): ?>
              <tr>
                <td><?= htmlspecialchars(($rr['last_name'] ?? '') . ', ' . ($rr['first_name'] ?? '')) ?></td>
                <td><?= htmlspecialchars($rr['barangay_name'] ?? '—') ?></td>
                <td><?= !empty($rr['created_at']) ? date('M d, Y', strtotime($rr['created_at'])) : '—' ?></td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      <!-- Top 5 Barangays -->
      <div class="chart-card">
        <div class="ch">
          <span><i class="bi bi-bar-chart-fill me-1"></i>Top 5 Barangays by Population</span>
          <span class="badge" style="background:#334155;color:#94a3b8;"><?= count($barangays) ?> total barangays</span>
        </div>
        <div class="cb p-0">
          <table class="table table-sm mb-0">
            <thead>
              <tr>
                <th>Barangay Name</th>
                <th>Residents</th>
                <th>Households</th>
                <th>Blotter</th>
              </tr>
            </thead>
            <tbody>
            <?php if (empty($brgyStats)): ?>
              <tr><td colspan="4" class="text-center" style="color:#64748b;font-size:.75rem;padding:12px;">No data available.</td></tr>
            <?php else: ?>
              <?php foreach ($brgyStats as $bs): ?>
              <tr>
                <td style="font-weight:600;"><?= htmlspecialchars($bs['barangay_name']) ?></td>
                <td><span class="badge bg-primary"><?= number_format($bs['residents']) ?></span></td>
                <td><span class="badge bg-success"><?= number_format($bs['households']) ?></span></td>
                <td><span class="badge bg-danger"><?= $bs['blotter'] ?></span></td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <!-- Right column: Recent Blotter -->
    <div class="col-md-4">
      <div class="chart-card h-100">
        <div class="ch">
          <span><i class="bi bi-shield-exclamation me-1"></i>Recent Blotter Cases</span>
          <a href="barangay_records.php" class="btn-xs btn text-info p-0" style="font-size:.68rem;">View All</a>
        </div>
        <div class="cb p-0">
          <table class="table table-sm mb-0">
            <thead>
              <tr>
                <th>Case No.</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
            <?php if (empty($recentBlotter)): ?>
              <tr><td colspan="2" class="text-center" style="color:#64748b;font-size:.75rem;padding:12px;">No cases found.</td></tr>
            <?php else: ?>
              <?php foreach ($recentBlotter as $bc): ?>
              <?php
                $bsColor = match($bc['status'] ?? '') {
                    'Open'                 => 'warning',
                    'Under Investigation'  => 'info',
                    'Settled'              => 'success',
                    'Escalated'            => 'danger',
                    'Closed'               => 'secondary',
                    default                => 'secondary'
                };
              ?>
              <tr>
                <td><?= htmlspecialchars($bc['case_number'] ?? $bc['id']) ?></td>
                <td><span class="badge bg-<?= $bsColor ?>"><?= htmlspecialchars($bc['status'] ?? '—') ?></span></td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- ⑦ BLOTTER STATUS BREAKDOWN ───────────────────────────── -->
  <div class="sec-title"><i class="bi bi-shield-fill-exclamation text-danger"></i>Blotter Status Breakdown</div>
  <div class="row g-2 mb-3">
    <div class="col-6 col-md">
      <div class="pcard" style="background:#78350f;">
        <div class="d-flex align-items-center gap-2">
          <div class="pcard-icon" style="background:#92400e;"><i class="bi bi-exclamation-circle-fill" style="color:#fff;"></i></div>
          <div><div class="pcard-val"><?= number_format($blotterStatusCounts['Open']) ?></div><div class="pcard-lbl">Open</div></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-md">
      <div class="pcard" style="background:#1e3a5f;">
        <div class="d-flex align-items-center gap-2">
          <div class="pcard-icon" style="background:#2d5a8e;"><i class="bi bi-search" style="color:#fff;"></i></div>
          <div><div class="pcard-val"><?= number_format($blotterStatusCounts['Under Investigation']) ?></div><div class="pcard-lbl">Under Investigation</div></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-md">
      <div class="pcard" style="background:#14532d;">
        <div class="d-flex align-items-center gap-2">
          <div class="pcard-icon" style="background:#166534;"><i class="bi bi-check-circle-fill" style="color:#fff;"></i></div>
          <div><div class="pcard-val"><?= number_format($blotterStatusCounts['Settled']) ?></div><div class="pcard-lbl">Settled</div></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-md">
      <div class="pcard" style="background:#7f1d1d;">
        <div class="d-flex align-items-center gap-2">
          <div class="pcard-icon" style="background:#991b1b;"><i class="bi bi-arrow-up-circle-fill" style="color:#fff;"></i></div>
          <div><div class="pcard-val"><?= number_format($blotterStatusCounts['Escalated']) ?></div><div class="pcard-lbl">Escalated</div></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-md">
      <div class="pcard" style="background:#1e293b;">
        <div class="d-flex align-items-center gap-2">
          <div class="pcard-icon" style="background:#334155;"><i class="bi bi-lock-fill" style="color:#fff;"></i></div>
          <div><div class="pcard-val"><?= number_format($blotterStatusCounts['Closed']) ?></div><div class="pcard-lbl">Closed</div></div>
        </div>
      </div>
    </div>
  </div>

  <!-- ⑧ ANNOUNCEMENTS & REPORTS OVERVIEW ──────────────────── -->
  <div class="sec-title"><i class="bi bi-megaphone-fill text-warning"></i>Announcements &amp; Reports Overview</div>
  <div class="row g-2 mb-4">
    <!-- Latest Notices -->
    <div class="col-md-6">
      <div class="chart-card h-100" style="background:#1e293b;">
        <div class="ch" style="background:#0f172a;">
          <span><i class="bi bi-megaphone-fill me-1"></i>Latest LGU Notices</span>
          <a href="notices.php" class="btn-xs btn text-info p-0" style="font-size:.68rem;">View All</a>
        </div>
        <div class="cb">
          <?php if (empty($notices)): ?>
            <div style="color:#64748b;font-size:.75rem;text-align:center;padding:16px 0;">No notices available.</div>
          <?php else: ?>
            <?php foreach ($notices as $notice): ?>
            <div style="border-bottom:1px solid #334155;padding:8px 0;">
              <div style="font-size:.8rem;font-weight:600;color:#e2e8f0;"><?= htmlspecialchars($notice['title']) ?></div>
              <div style="font-size:.65rem;color:#94a3b8;margin:2px 0;"><?= !empty($notice['created_at']) ? date('M d, Y', strtotime($notice['created_at'])) : '' ?></div>
              <div style="font-size:.72rem;color:#94a3b8;"><?= htmlspecialchars(mb_strimwidth($notice['content'] ?? '', 0, 80, '…')) ?></div>
            </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <!-- Monthly Quick Stats -->
    <div class="col-md-6">
      <div class="chart-card h-100" style="background:#1e293b;">
        <div class="ch" style="background:#0f172a;">
          <span><i class="bi bi-calendar-check-fill me-1"></i>This Month — Quick Stats</span>
          <a href="reports.php" class="btn-xs btn text-info p-0" style="font-size:.68rem;">Full Report →</a>
        </div>
        <div class="cb">
          <div style="display:flex;flex-direction:column;gap:10px;">
            <div style="display:flex;align-items:center;justify-content:space-between;background:#0f172a;border-radius:10px;padding:10px 14px;">
              <div style="display:flex;align-items:center;gap:8px;">
                <div style="width:32px;height:32px;border-radius:8px;background:#2d5a8e;display:flex;align-items:center;justify-content:center;"><i class="bi bi-person-plus-fill" style="color:#fff;font-size:.9rem;"></i></div>
                <div style="font-size:.78rem;font-weight:600;color:#e2e8f0;">New Residents</div>
              </div>
              <div style="font-size:1.3rem;font-weight:800;color:#38bdf8;"><?= number_format($mnthRes) ?></div>
            </div>
            <div style="display:flex;align-items:center;justify-content:space-between;background:#0f172a;border-radius:10px;padding:10px 14px;">
              <div style="display:flex;align-items:center;gap:8px;">
                <div style="width:32px;height:32px;border-radius:8px;background:#5b21b6;display:flex;align-items:center;justify-content:center;"><i class="bi bi-file-earmark-check-fill" style="color:#fff;font-size:.9rem;"></i></div>
                <div style="font-size:.78rem;font-weight:600;color:#e2e8f0;">Certificates</div>
              </div>
              <div style="font-size:1.3rem;font-weight:800;color:#a78bfa;"><?= number_format($mnthCert) ?></div>
            </div>
            <div style="display:flex;align-items:center;justify-content:space-between;background:#0f172a;border-radius:10px;padding:10px 14px;">
              <div style="display:flex;align-items:center;gap:8px;">
                <div style="width:32px;height:32px;border-radius:8px;background:#991b1b;display:flex;align-items:center;justify-content:center;"><i class="bi bi-shield-exclamation" style="color:#fff;font-size:.9rem;"></i></div>
                <div style="font-size:.78rem;font-weight:600;color:#e2e8f0;">Blotter Cases</div>
              </div>
              <div style="font-size:1.3rem;font-weight:800;color:#f87171;"><?= number_format($mnthBlotter) ?></div>
            </div>
          </div>
          <div class="mt-3 text-center">
            <a href="reports.php" class="dl-box dl-box-excel" style="font-size:.72rem;padding:5px 16px;">
              <i class="bi bi-file-earmark-bar-graph-fill"></i> Full Report →
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>

</div><!-- /main-content -->

<script defer src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script defer src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script defer>
document.addEventListener('DOMContentLoaded', function() {

// ── Population Trend Chart (mini, dark) ───────────────────
new Chart(document.getElementById('popTrendChart'), {
  type: 'line',
  data: {
    labels: <?= json_encode($popLabels) ?>,
    datasets: [{
      label: 'New Residents',
      data: <?= json_encode($popValues) ?>,
      borderColor: '#38bdf8',
      backgroundColor: 'rgba(56,189,248,0.12)',
      tension: 0.4,
      fill: true,
      pointRadius: 3,
      pointBackgroundColor: '#38bdf8',
      pointHoverRadius: 6,
      pointHoverBackgroundColor: '#38bdf8',
      pointHoverBorderColor: '#fff',
      pointHoverBorderWidth: 2,
      borderWidth: 2
    }]
  },
  options: {
    responsive: true,
    plugins: { legend: { display: false } },
    scales: {
      x: { ticks: { color: '#94a3b8', font: { size: 9 } }, grid: { color: '#1e293b' } },
      y: { ticks: { color: '#94a3b8', font: { size: 9 } }, grid: { color: '#1e293b' }, beginAtZero: true }
    }
  }
});

// ── toggleSidebar ─────────────────────────────────────────
function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('show');
}

// ── Real-time banner clock ─────────────────────────────────
(function() {
    const days   = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    const months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    function tick() {
        const now    = new Date();
        const manila = new Date(now.toLocaleString('en-US', { timeZone: 'Asia/Manila' }));
        const hour   = manila.getHours();
        let greet, icon;
        if (hour >= 5 && hour < 12)       { greet = 'Good Morning';   icon = 'bi-sunrise-fill'; }
        else if (hour >= 12 && hour < 18) { greet = 'Good Afternoon'; icon = 'bi-sun-fill'; }
        else                              { greet = 'Good Evening';   icon = 'bi-moon-stars-fill'; }
        const greetEl = document.getElementById('bannerGreet');
        const iconEl  = document.getElementById('bannerGreetIcon');
        if (greetEl) greetEl.textContent = greet;
        if (iconEl)  iconEl.className    = 'bi ' + icon;
        const el = document.getElementById('bannerClock');
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
