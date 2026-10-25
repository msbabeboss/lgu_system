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

// ── Consolidated residents counts ─────────────────────────
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

// ── Employment ────────────────────────────────────────────
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

// ── Population Trend (12 months) ─────────────────────────
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

// ── Blotter Trend (6 months) ─────────────────────────────
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

// ── Report Module Quick Stats (current month defaults) ────
$rpt_from = sanitize($_GET['rpt_from'] ?? '');
$rpt_to   = sanitize($_GET['rpt_to']   ?? '');
if (!$rpt_from && !$rpt_to) {
    $rpt_from = date('Y-m-01');
    $rpt_to   = date('Y-m-d');
}
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

$rpt_households = (int)$stats['households'];

$rptCertBrkStmt = $db->prepare(
    "SELECT certificate_type, COUNT(*) as cnt
     FROM certificates
     WHERE barangay_id=? AND date_issued BETWEEN ? AND ?
     GROUP BY certificate_type ORDER BY cnt DESC"
);
$rptCertBrkStmt->execute([$bid, $rpt_from, $rpt_to]);
$rpt_certBreakdown = $rptCertBrkStmt->fetchAll();

$rptPendingStmt = $db->prepare(
    "SELECT COUNT(*) FROM user_requests
     WHERE barangay_id=? AND status='pending' AND request_type LIKE '%certificate%'
       AND DATE(created_at) BETWEEN ? AND ?"
);
$rptPendingStmt->execute([$bid, $rpt_from, $rpt_to]);
$rpt_pending_certs = (int)$rptPendingStmt->fetchColumn();

// ── Batch request counts ──────────────────────────────────
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
  /* ── Layout ───────────────────────────────────────────── */
  .topbar{height:80px !important;}
  .main-content{margin-top:80px !important;padding:12px 20px 20px 22px;}
  .sidebar-brand{height:80px !important;}

  /* ── Section title ────────────────────────────────────── */
  .sec-title{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;margin-bottom:8px;display:flex;align-items:center;gap:6px;}
  .sec-title::after{content:'';flex:1;height:1px;background:#334155;}

  /* ── Download buttons ─────────────────────────────────── */
  .dl-box{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:8px;font-size:.78rem;font-weight:600;border:none;cursor:pointer;transition:opacity .15s,transform .12s;white-space:nowrap;}
  .dl-box:hover{opacity:.88;transform:translateY(-1px);text-decoration:none;}
  .dl-box-excel{background:#16a34a;color:#fff;}.dl-box-excel:hover{color:#fff;}

  /* ── KPI / stat cards ─────────────────────────────────── */
  .pcard{border-radius:14px;padding:14px 14px 10px;display:flex;flex-direction:column;gap:9px;height:100%;transition:transform .15s,box-shadow .15s;border:1.5px solid rgba(255,255,255,0.08);box-shadow:0 2px 8px rgba(0,0,0,0.25);}
  .pcard:hover{transform:translateY(-2px);box-shadow:0 6px 18px rgba(0,0,0,.35);}
  .pcard-icon{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1rem;}
  .pcard-val{font-size:1.4rem;font-weight:800;color:#fff;line-height:1.1;}
  .pcard-lbl{font-size:.7rem;color:rgba(255,255,255,0.75);font-weight:500;line-height:1.3;}

  /* ── Chart cards ──────────────────────────────────────── */
  .chart-card{background:#1e293b;border:1.5px solid #334155;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.2);}
  .chart-card .ch{padding:8px 14px;font-size:.75rem;font-weight:600;color:#e2e8f0;background:#0f172a;border-bottom:1.5px solid #334155;display:flex;align-items:center;gap:6px;justify-content:space-between;}
  .chart-card .cb{padding:10px 12px;}

  /* ── Table ────────────────────────────────────────────── */
  .table{--bs-table-bg:transparent;--bs-table-color:#e2e8f0;--bs-table-border-color:#334155;}
  .table th{font-size:.68rem;text-transform:uppercase;color:#94a3b8;font-weight:600;}
  .table td{font-size:.75rem;color:#cbd5e1;}
  .badge{font-size:.63rem;}
  .btn-xs{padding:1px 7px;font-size:.7rem;}

  /* ── Page background ──────────────────────────────────── */
  body{background:#0f172a;}
  .main-content{background:#0f172a;}
  html.dark-mode .welcome-banner{box-shadow:0 4px 20px rgba(0,0,0,0.5);}

  /* ── Welcome Banner ───────────────────────────────────── */
  .welcome-banner {
      background-image: url('<?= BASE_URL ?>/assets/img/bgimage.webp');
      background-image: -webkit-image-set(url('<?= BASE_URL ?>/assets/img/bgimage.webp') 1x);
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

  <!-- ① WELCOME BANNER ─────────────────────────────────── -->
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

  <!-- ② FILTER BAR + DOWNLOAD BUTTON ─────────────────── -->
  <div style="display:flex;justify-content:flex-end;gap:8px;margin-bottom:18px;">
    <a href="export_dashboard_records.php?format=excel" class="dl-box dl-box-excel" style="text-decoration:none;">
      <i class="bi bi-file-earmark-excel-fill"></i> Download All Records
    </a>
  </div>

  <!-- ③ KPI CARDS ROW 1 ───────────────────────────────── -->
  <div class="sec-title"><i class="bi bi-grid-1x2-fill" style="color:#38bdf8"></i>Overview</div>
  <div class="row g-2 mb-3">

    <div class="col-6 col-md-2">
      <a href="<?= BASE_URL ?>/barangay_admin/residents.php" style="text-decoration:none;">
        <div class="pcard" style="background:#1e3a5f">
          <div class="d-flex align-items-center gap-2">
            <div class="pcard-icon" style="background:#2d5a8e"><i class="bi bi-people-fill" style="color:#fff"></i></div>
            <div><div class="pcard-val"><?= number_format($stats['residents']) ?></div><div class="pcard-lbl">Total Residents</div></div>
          </div>
        </div>
      </a>
    </div>

    <div class="col-6 col-md-2">
      <a href="<?= BASE_URL ?>/barangay_admin/households.php" style="text-decoration:none;">
        <div class="pcard" style="background:#14532d">
          <div class="d-flex align-items-center gap-2">
            <div class="pcard-icon" style="background:#166534"><i class="bi bi-house-fill" style="color:#fff"></i></div>
            <div><div class="pcard-val"><?= number_format($stats['households']) ?></div><div class="pcard-lbl">Total Households</div></div>
          </div>
        </div>
      </a>
    </div>

    <div class="col-6 col-md-2">
      <a href="<?= BASE_URL ?>/barangay_admin/blotter.php" style="text-decoration:none;">
        <div class="pcard" style="background:#7f1d1d">
          <div class="d-flex align-items-center gap-2">
            <div class="pcard-icon" style="background:#991b1b"><i class="bi bi-shield-exclamation" style="color:#fff"></i></div>
            <div><div class="pcard-val"><?= number_format($stats['blotter']) ?></div><div class="pcard-lbl">Blotter Cases</div></div>
          </div>
        </div>
      </a>
    </div>

    <div class="col-6 col-md-2">
      <a href="<?= BASE_URL ?>/barangay_admin/certificates.php" style="text-decoration:none;">
        <div class="pcard" style="background:#4c1d95">
          <div class="d-flex align-items-center gap-2">
            <div class="pcard-icon" style="background:#5b21b6"><i class="bi bi-file-earmark-check-fill" style="color:#fff"></i></div>
            <div><div class="pcard-val"><?= number_format($stats['certs']) ?></div><div class="pcard-lbl">Certificates Issued</div></div>
          </div>
        </div>
      </a>
    </div>

    <div class="col-6 col-md-2">
      <a href="<?= BASE_URL ?>/barangay_admin/officials.php" style="text-decoration:none;">
        <div class="pcard" style="background:#1c3a2e">
          <div class="d-flex align-items-center gap-2">
            <div class="pcard-icon" style="background:#14532d"><i class="bi bi-person-badge-fill" style="color:#fff"></i></div>
            <div><div class="pcard-val"><?= number_format($stats['officials_active']) ?></div><div class="pcard-lbl">Active Officials</div></div>
          </div>
        </div>
      </a>
    </div>

    <div class="col-6 col-md-2">
      <a href="<?= BASE_URL ?>/barangay_admin/requests.php" style="text-decoration:none;">
        <div class="pcard" style="background:#78350f">
          <div class="d-flex align-items-center gap-2">
            <div class="pcard-icon" style="background:#92400e"><i class="bi bi-inbox-fill" style="color:#fff"></i></div>
            <div><div class="pcard-val"><?= number_format($stats['requests_pending']) ?></div><div class="pcard-lbl">Pending Requests</div></div>
          </div>
        </div>
      </a>
    </div>

  </div><!-- /row KPI row 1 -->

  <!-- ③ KPI CARDS ROW 2 — Resident Status ─────────────── -->
  <div class="row g-2 mb-3">

    <div class="col-6 col-md-3">
      <div class="pcard" style="background:#1e3a5f">
        <div class="d-flex align-items-center gap-2">
          <div class="pcard-icon" style="background:#2d5a8e"><i class="bi bi-person-check-fill" style="color:#fff"></i></div>
          <div><div class="pcard-val"><?= number_format($stats['res_active']) ?></div><div class="pcard-lbl">Active</div></div>
        </div>
      </div>
    </div>

    <div class="col-6 col-md-3">
      <div class="pcard" style="background:#1e293b">
        <div class="d-flex align-items-center gap-2">
          <div class="pcard-icon" style="background:#334155"><i class="bi bi-person-dash-fill" style="color:#fff"></i></div>
          <div><div class="pcard-val"><?= number_format($stats['res_inactive']) ?></div><div class="pcard-lbl">Inactive</div></div>
        </div>
      </div>
    </div>

    <div class="col-6 col-md-3">
      <div class="pcard" style="background:#1e3a5f">
        <div class="d-flex align-items-center gap-2">
          <div class="pcard-icon" style="background:#2d5a8e"><i class="bi bi-person-walking" style="color:#fff"></i></div>
          <div><div class="pcard-val"><?= number_format($stats['res_transferred']) ?></div><div class="pcard-lbl">Transferred</div></div>
        </div>
      </div>
    </div>

    <div class="col-6 col-md-3">
      <div class="pcard" style="background:#7f1d1d">
        <div class="d-flex align-items-center gap-2">
          <div class="pcard-icon" style="background:#991b1b"><i class="bi bi-person-x-fill" style="color:#fff"></i></div>
          <div><div class="pcard-val"><?= number_format($stats['res_deceased']) ?></div><div class="pcard-lbl">Deceased</div></div>
        </div>
      </div>
    </div>

  </div><!-- /row KPI row 2 -->

  <!-- ④ POPULATION OVERVIEW — compact ─────────────────── -->
  <div class="sec-title mt-2"><i class="bi bi-graph-up" style="color:#38bdf8"></i>Population Overview — Last 12 Months</div>
  <div class="row g-2 mb-3">

    <!-- LEFT: line chart -->
    <div class="col-md-7">
      <div class="chart-card h-100">
        <div class="ch" style="background:#0f172a;">
          <span><i class="bi bi-graph-up" style="color:#38bdf8"></i> New Residents — Last 12 Months</span>
        </div>
        <div class="cb"><canvas id="popTrendChart" style="max-height:160px"></canvas></div>
      </div>
    </div>

    <!-- RIGHT: 2x2 sector summary -->
    <div class="col-md-5">
      <div class="row g-2 h-100">

        <div class="col-6">
          <div class="pcard" style="background:#5b21b6;height:100%">
            <div class="d-flex align-items-center gap-2">
              <div class="pcard-icon" style="background:#6d28d9"><i class="bi bi-person-wheelchair" style="color:#fff"></i></div>
              <div><div class="pcard-val"><?= number_format($stats['pwd']) ?></div><div class="pcard-lbl">PWD</div></div>
            </div>
          </div>
        </div>

        <div class="col-6">
          <div class="pcard" style="background:#9a3412;height:100%">
            <div class="d-flex align-items-center gap-2">
              <div class="pcard-icon" style="background:#c2410c"><i class="bi bi-person-fill" style="color:#fff"></i></div>
              <div><div class="pcard-val"><?= number_format($stats['senior']) ?></div><div class="pcard-lbl">Senior Citizens</div></div>
            </div>
          </div>
        </div>

        <div class="col-6">
          <div class="pcard" style="background:#9d174d;height:100%">
            <div class="d-flex align-items-center gap-2">
              <div class="pcard-icon" style="background:#be185d"><i class="bi bi-person-heart" style="color:#fff"></i></div>
              <div><div class="pcard-val"><?= number_format($stats['solo']) ?></div><div class="pcard-lbl">Solo Parents</div></div>
            </div>
          </div>
        </div>

        <div class="col-6">
          <div class="pcard" style="background:#065f46;height:100%">
            <div class="d-flex align-items-center gap-2">
              <div class="pcard-icon" style="background:#047857"><i class="bi bi-heart-fill" style="color:#fff"></i></div>
              <div><div class="pcard-val"><?= number_format($stats['4ps']) ?></div><div class="pcard-lbl">4Ps Beneficiaries</div></div>
            </div>
          </div>
        </div>

      </div>
    </div>

  </div><!-- /Population Overview -->

  <!-- ⑤ QUICK LINKS ───────────────────────────────────── -->
  <div class="sec-title mt-2"><i class="bi bi-lightning-charge-fill" style="color:#fbbf24"></i>Quick Links</div>
  <div class="row g-2 mb-3">
    <?php
    $quickLinks = [
      ['label'=>'Residents',     'icon'=>'bi-people-fill',                'url'=>BASE_URL.'/barangay_admin/residents.php'],
      ['label'=>'Households',    'icon'=>'bi-house-fill',                 'url'=>BASE_URL.'/barangay_admin/households.php'],
      ['label'=>'Officials',     'icon'=>'bi-person-badge-fill',          'url'=>BASE_URL.'/barangay_admin/officials.php'],
      ['label'=>'Certificates',  'icon'=>'bi-file-earmark-text-fill',     'url'=>BASE_URL.'/barangay_admin/certificates.php'],
      ['label'=>'Blotter',       'icon'=>'bi-shield-exclamation',         'url'=>BASE_URL.'/barangay_admin/blotter.php'],
      ['label'=>'Reports',       'icon'=>'bi-file-earmark-bar-graph-fill','url'=>BASE_URL.'/barangay_admin/reports.php'],
      ['label'=>'Notifications', 'icon'=>'bi-bell-fill',                  'url'=>BASE_URL.'/barangay_admin/notifications.php'],
      ['label'=>'Announcements', 'icon'=>'bi-megaphone-fill',             'url'=>BASE_URL.'/barangay_admin/notices.php'],
      ['label'=>'Activity Log',  'icon'=>'bi-clock-history',              'url'=>BASE_URL.'/barangay_admin/activity_log.php'],
      ['label'=>'Requests',      'icon'=>'bi-inbox-fill',                 'url'=>BASE_URL.'/barangay_admin/requests.php'],
      ['label'=>'Analytics',     'icon'=>'bi-bar-chart-line-fill',        'url'=>BASE_URL.'/barangay_admin/analytics.php'],
      ['label'=>'Forecasting',   'icon'=>'bi-cpu-fill',                   'url'=>BASE_URL.'/barangay_admin/forecasting.php'],
    ];
    foreach ($quickLinks as $ql): ?>
    <div class="col-6 col-sm-4 col-md-3 col-lg-2">
      <a href="<?= $ql['url'] ?>" style="text-decoration:none;display:block;height:100%;">
        <div style="background:#1e293b;border-radius:12px;padding:14px 12px;display:flex;flex-direction:column;align-items:center;text-align:center;min-height:85px;height:100%;transition:background .15s,transform .15s;border:1.5px solid #334155;justify-content:center;gap:8px;"
             onmouseover="this.style.background='#0f172a';this.style.transform='translateY(-2px)';"
             onmouseout="this.style.background='#1e293b';this.style.transform='translateY(0)';">
          <div style="width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,0.12);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="bi <?= $ql['icon'] ?>" style="color:#fff;font-size:1.1rem;"></i>
          </div>
          <div style="font-size:.72rem;font-weight:700;color:#e2e8f0;line-height:1.2;"><?= $ql['label'] ?></div>
        </div>
      </a>
    </div>
    <?php endforeach; ?>
  </div><!-- /Quick Links -->

  <!-- ⑥ RECENT ACTIVITIES ─────────────────────────────── -->
  <div class="sec-title mt-2"><i class="bi bi-clock-history" style="color:#38bdf8"></i>Recent Activities</div>
  <div class="row g-2 mb-2">

    <!-- Recent Residents -->
    <div class="col-md-6">
      <div class="chart-card h-100">
        <div class="ch">
          <span><i class="bi bi-people-fill" style="color:#38bdf8"></i> Recent Residents</span>
        </div>
        <div class="cb p-0">
          <table class="table table-sm mb-0">
            <thead><tr><th>Name</th><th>Date Registered</th></tr></thead>
            <tbody>
            <?php foreach ($recentResidents as $res):
              $rName = '';
              if (!empty($res['last_name']) && !empty($res['first_name'])) {
                  $rName = htmlspecialchars($res['last_name'].', '.$res['first_name']);
              } elseif (!empty($res['full_name'])) {
                  $rName = htmlspecialchars($res['full_name']);
              } else {
                  $rName = '—';
              }
            ?>
              <tr>
                <td><?= $rName ?></td>
                <td><?= date('M d, Y', strtotime($res['created_at'])) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($recentResidents)): ?>
              <tr><td colspan="2" class="text-center" style="color:#94a3b8;font-size:.75rem;">No recent residents.</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Recent Blotter Cases -->
    <div class="col-md-6">
      <div class="chart-card h-100">
        <div class="ch">
          <span><i class="bi bi-shield-exclamation" style="color:#f87171"></i> Recent Blotter Cases</span>
          <a href="<?= BASE_URL ?>/barangay_admin/blotter.php" class="btn btn-xs btn-outline-danger" style="font-size:.65rem;padding:1px 8px;">View All</a>
        </div>
        <div class="cb p-0">
          <table class="table table-sm mb-0">
            <thead><tr><th>Case No.</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($recentBlotter as $b):
              $sc = ['Open'=>'warning','Settled'=>'success','Escalated'=>'danger','Closed'=>'secondary','Under Investigation'=>'info'];
            ?>
              <tr>
                <td><?= htmlspecialchars($b['case_number']) ?></td>
                <td><span class="badge bg-<?= $sc[$b['status']] ?? 'secondary' ?>"><?= htmlspecialchars($b['status']) ?></span></td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($recentBlotter)): ?>
              <tr><td colspan="2" class="text-center" style="color:#94a3b8;font-size:.75rem;">No recent cases.</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </div><!-- /recent 2-col -->

  <!-- Pending Requests mini banner -->
  <div class="mb-3" style="background:#78350f;color:#fff;border-radius:10px;padding:10px 16px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
    <i class="bi bi-inbox-fill" style="font-size:1rem;"></i>
    <span style="font-size:.82rem;">Pending Requests: <strong><?= number_format($stats['requests_pending']) ?></strong></span>
    <a href="<?= BASE_URL ?>/barangay_admin/requests.php" style="color:#fcd34d;font-size:.78rem;font-weight:600;margin-left:auto;">View All →</a>
  </div>

  <!-- ⑦ BLOTTER STATUS BREAKDOWN ───────────────────────── -->
  <div class="sec-title mt-2"><i class="bi bi-shield-fill-exclamation" style="color:#f87171"></i>Blotter Status Breakdown</div>
  <div class="row g-2 mb-3">

    <div class="col-6 col-md">
      <div class="pcard" style="background:#78350f">
        <div class="d-flex align-items-center gap-2">
          <div class="pcard-icon" style="background:#92400e"><i class="bi bi-exclamation-circle-fill" style="color:#fff"></i></div>
          <div><div class="pcard-val"><?= number_format($blotterStatusCounts['Open']) ?></div><div class="pcard-lbl">Open</div></div>
        </div>
      </div>
    </div>

    <div class="col-6 col-md">
      <div class="pcard" style="background:#1e3a5f">
        <div class="d-flex align-items-center gap-2">
          <div class="pcard-icon" style="background:#2d5a8e"><i class="bi bi-search" style="color:#fff"></i></div>
          <div><div class="pcard-val"><?= number_format($blotterStatusCounts['Under Investigation']) ?></div><div class="pcard-lbl">Under Investigation</div></div>
        </div>
      </div>
    </div>

    <div class="col-6 col-md">
      <div class="pcard" style="background:#14532d">
        <div class="d-flex align-items-center gap-2">
          <div class="pcard-icon" style="background:#166534"><i class="bi bi-check-circle-fill" style="color:#fff"></i></div>
          <div><div class="pcard-val"><?= number_format($blotterStatusCounts['Settled']) ?></div><div class="pcard-lbl">Settled</div></div>
        </div>
      </div>
    </div>

    <div class="col-6 col-md">
      <div class="pcard" style="background:#7f1d1d">
        <div class="d-flex align-items-center gap-2">
          <div class="pcard-icon" style="background:#991b1b"><i class="bi bi-arrow-up-circle-fill" style="color:#fff"></i></div>
          <div><div class="pcard-val"><?= number_format($blotterStatusCounts['Escalated']) ?></div><div class="pcard-lbl">Escalated</div></div>
        </div>
      </div>
    </div>

    <div class="col-6 col-md">
      <div class="pcard" style="background:#1e293b">
        <div class="d-flex align-items-center gap-2">
          <div class="pcard-icon" style="background:#334155"><i class="bi bi-lock-fill" style="color:#fff"></i></div>
          <div><div class="pcard-val"><?= number_format($blotterStatusCounts['Closed']) ?></div><div class="pcard-lbl">Closed</div></div>
        </div>
      </div>
    </div>

  </div><!-- /blotter status breakdown -->

  <!-- ⑧ ANNOUNCEMENTS & REPORTS OVERVIEW ──────────────── -->
  <div class="sec-title mt-2"><i class="bi bi-megaphone-fill" style="color:#fbbf24"></i>Announcements &amp; Reports Overview</div>
  <div class="row g-2 mb-3">

    <!-- Latest LGU Notices -->
    <div class="col-md-6">
      <div class="chart-card h-100">
        <div class="ch">
          <span><i class="bi bi-megaphone-fill" style="color:#fbbf24"></i> Latest LGU Notices</span>
        </div>
        <div class="cb">
          <?php if (!empty($notices)): ?>
            <?php foreach ($notices as $n): ?>
            <div style="margin-bottom:12px;padding-bottom:12px;border-bottom:1px solid #334155;">
              <div class="fw-semibold" style="font-size:.8rem;color:#e2e8f0;"><?= htmlspecialchars($n['title']) ?></div>
              <div style="font-size:.65rem;color:#94a3b8;margin-bottom:3px;"><?= date('M d, Y', strtotime($n['created_at'])) ?></div>
              <div style="font-size:.72rem;color:#94a3b8;"><?= htmlspecialchars(substr($n['content'], 0, 80)) ?><?= strlen($n['content']) > 80 ? '…' : '' ?></div>
            </div>
            <?php endforeach; ?>
          <?php else: ?>
            <p style="font-size:.78rem;color:#64748b;text-align:center;margin:16px 0;">No announcements yet.</p>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- This Month Quick Stats -->
    <div class="col-md-6">
      <div class="chart-card h-100">
        <div class="ch">
          <span><i class="bi bi-bar-chart-line-fill" style="color:#38bdf8"></i> This Month — Quick Stats</span>
          <a href="<?= BASE_URL ?>/barangay_admin/reports.php" style="color:#38bdf8;font-size:.7rem;font-weight:600;">Full Report →</a>
        </div>
        <div class="cb">
          <div style="display:flex;flex-direction:column;gap:12px;">

            <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 10px;background:#0f172a;border-radius:8px;">
              <span style="font-size:.78rem;color:#cbd5e1;"><i class="bi bi-people-fill me-2" style="color:#38bdf8"></i>New Residents this month</span>
              <strong style="font-size:.95rem;color:#fff;"><?= number_format($rpt_new_residents) ?></strong>
            </div>

            <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 10px;background:#0f172a;border-radius:8px;">
              <span style="font-size:.78rem;color:#cbd5e1;"><i class="bi bi-file-earmark-check-fill me-2" style="color:#a78bfa"></i>Certificates this month</span>
              <strong style="font-size:.95rem;color:#fff;"><?= number_format($rpt_docs_issued) ?></strong>
            </div>

            <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 10px;background:#0f172a;border-radius:8px;">
              <span style="font-size:.78rem;color:#cbd5e1;"><i class="bi bi-shield-exclamation me-2" style="color:#f87171"></i>Blotter Cases this month</span>
              <strong style="font-size:.95rem;color:#fff;"><?= number_format($rpt_blotter) ?></strong>
            </div>

          </div>
          <div style="margin-top:14px;text-align:right;">
            <a href="<?= BASE_URL ?>/barangay_admin/reports.php" class="dl-box dl-box-excel" style="font-size:.75rem;padding:5px 14px;text-decoration:none;">
              <i class="bi bi-file-earmark-bar-graph-fill"></i> Full Report →
            </a>
          </div>
        </div>
      </div>
    </div>

  </div><!-- /announcements & reports -->

</div><!-- /main-content -->

<script defer src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script defer src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script defer>
document.addEventListener('DOMContentLoaded', function() {

  // ── Population Trend Line Chart ──────────────────────────
  new Chart(document.getElementById('popTrendChart'), {
    type: 'line',
    data: {
      labels: <?= json_encode($popLabels) ?>,
      datasets: [{
        label: 'New Residents',
        data: <?= json_encode($popValues) ?>,
        borderColor: '#38bdf8',
        backgroundColor: 'rgba(56,189,248,0.12)',
        pointBackgroundColor: '#38bdf8',
        tension: 0.4,
        fill: true,
        pointRadius: 4
      }]
    },
    options: {
      responsive: true,
      plugins: { legend: { display: false } },
      scales: {
        x: { ticks: { font: { size: 10 }, color: '#94a3b8' }, grid: { color: '#1e293b' } },
        y: { ticks: { font: { size: 10 }, color: '#94a3b8' }, grid: { color: '#1e293b' }, beginAtZero: true }
      }
    }
  });

  // ── Sidebar toggle ────────────────────────────────────────
  function toggleSidebar() { document.getElementById('sidebar').classList.toggle('show'); }

  // ── Real-time banner clock ────────────────────────────────
  (function() {
    var days   = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    var months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    function tick() {
      var now    = new Date();
      var manila = new Date(now.toLocaleString('en-US', { timeZone: 'Asia/Manila' }));
      var hour   = manila.getHours();
      var greet, icon;
      if (hour >= 5 && hour < 12)       { greet = 'Good Morning';   icon = 'bi-sunrise-fill'; }
      else if (hour >= 12 && hour < 18) { greet = 'Good Afternoon'; icon = 'bi-sun-fill'; }
      else                              { greet = 'Good Evening';   icon = 'bi-moon-stars-fill'; }
      var greetEl = document.getElementById('bannerGreet');
      var iconEl  = document.getElementById('bannerGreetIcon');
      if (greetEl) greetEl.textContent = greet;
      if (iconEl)  iconEl.className    = 'bi ' + icon;
      var el   = document.getElementById('bannerClock');
      if (!el) return;
      var day  = days[manila.getDay()];
      var mon  = months[manila.getMonth()];
      var date = manila.getDate();
      var yr   = manila.getFullYear();
      var h    = hour;
      var m    = String(manila.getMinutes()).padStart(2, '0');
      var s    = String(manila.getSeconds()).padStart(2, '0');
      var ampm = h >= 12 ? 'PM' : 'AM';
      h = h % 12 || 12;
      el.textContent = day + ', ' + mon + ' ' + date + ', ' + yr + '  ' + h + ':' + m + ':' + s + ' ' + ampm;
    }
    tick();
    setInterval(tick, 1000);
  })();

}); // end DOMContentLoaded
</script>
</body>
</html>
