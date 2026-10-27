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

// Notices are loaded via AJAX after page paint (see get_notices_widget.php)

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
  .sec-title{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#3a7fa8;margin-bottom:8px;display:flex;align-items:center;gap:6px;}
  .sec-title::after{content:'';flex:1;height:1px;background:#d0e8f4;}

  /* ── Download buttons ─────────────────────────────────── */
  .dl-box{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:8px;font-size:.78rem;font-weight:600;border:none;cursor:pointer;transition:opacity .15s,transform .12s;white-space:nowrap;}
  .dl-box:hover{opacity:.88;transform:translateY(-1px);text-decoration:none;}
  .dl-box-excel{background:#16a34a;color:#fff;}.dl-box-excel:hover{color:#fff;}

  /* ── KPI / stat cards ─────────────────────────────────── */
  .pcard{background:#fff;border-radius:14px;padding:14px 14px 10px;display:flex;flex-direction:column;gap:9px;height:100%;transition:transform .15s,box-shadow .15s;border:1.5px solid #e2e8f0;box-shadow:0 2px 8px rgba(0,0,0,0.06);}
  .pcard:hover{transform:translateY(-2px);box-shadow:0 6px 18px rgba(0,0,0,.12);}
  .pcard-icon{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1rem;}
  .pcard-val{font-size:1.4rem;font-weight:800;color:#0f172a;line-height:1.1;}
  .pcard-lbl{font-size:.7rem;color:#64748b;font-weight:500;line-height:1.3;}

  /* ── Chart cards ──────────────────────────────────────── */
  .chart-card{background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.06);}
  .chart-card .ch{padding:8px 14px;font-size:.75rem;font-weight:600;color:#1e293b;background:#f8fafc;border-bottom:1.5px solid #e2e8f0;display:flex;align-items:center;gap:6px;justify-content:space-between;}
  .chart-card .cb{padding:10px 12px;}

  /* ── Table ────────────────────────────────────────────── */
  .table th{font-size:.68rem;text-transform:uppercase;color:#64748b;font-weight:600;}
  .table td{font-size:.75rem;color:#334155;}
  .badge{font-size:.63rem;}
  .btn-xs{padding:1px 7px;font-size:.7rem;}

  /* ── Page background ──────────────────────────────────── */
  body{background:#f0f6fb;}
  .main-content{background:#f0f6fb;}
  html.dark-mode .welcome-banner{box-shadow:0 4px 20px rgba(0,0,0,0.5);}

  /* ── Skeleton pulse animation ─────────────────────────── */
  @keyframes pulse {
    0%, 100% { opacity: 1; }
    50%       { opacity: 0.4; }
  }

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
  <div class="sec-title"><i class="bi bi-grid-1x2-fill text-primary"></i>Overview</div>
  <div class="row g-2 mb-3">

    <div class="col-6 col-md-2">
      <a href="<?= BASE_URL ?>/barangay_admin/residents.php" style="text-decoration:none;">
        <div class="pcard" style="background:#dbeafe">
          <div class="d-flex align-items-center gap-2">
            <div class="pcard-icon" style="background:#93c5fd"><i class="bi bi-people-fill" style="color:#1e40af"></i></div>
            <div><div class="pcard-val" style="color:#1e3a8a;"><?= number_format($stats['residents']) ?></div><div class="pcard-lbl">Total Residents</div></div>
          </div>
        </div>
      </a>
    </div>

    <div class="col-6 col-md-2">
      <a href="<?= BASE_URL ?>/barangay_admin/households.php" style="text-decoration:none;">
        <div class="pcard" style="background:#dcfce7">
          <div class="d-flex align-items-center gap-2">
            <div class="pcard-icon" style="background:#86efac"><i class="bi bi-house-fill" style="color:#166534"></i></div>
            <div><div class="pcard-val" style="color:#14532d;"><?= number_format($stats['households']) ?></div><div class="pcard-lbl">Total Households</div></div>
          </div>
        </div>
      </a>
    </div>

    <div class="col-6 col-md-2">
      <a href="<?= BASE_URL ?>/barangay_admin/blotter.php" style="text-decoration:none;">
        <div class="pcard" style="background:#fee2e2">
          <div class="d-flex align-items-center gap-2">
            <div class="pcard-icon" style="background:#fca5a5"><i class="bi bi-shield-exclamation" style="color:#991b1b"></i></div>
            <div><div class="pcard-val" style="color:#7f1d1d;"><?= number_format($stats['blotter']) ?></div><div class="pcard-lbl">Blotter Cases</div></div>
          </div>
        </div>
      </a>
    </div>

    <div class="col-6 col-md-2">
      <a href="<?= BASE_URL ?>/barangay_admin/certificates.php" style="text-decoration:none;">
        <div class="pcard" style="background:#ede9fe">
          <div class="d-flex align-items-center gap-2">
            <div class="pcard-icon" style="background:#c4b5fd"><i class="bi bi-file-earmark-check-fill" style="color:#5b21b6"></i></div>
            <div><div class="pcard-val" style="color:#4c1d95;"><?= number_format($stats['certs']) ?></div><div class="pcard-lbl">Certificates Issued</div></div>
          </div>
        </div>
      </a>
    </div>

    <div class="col-6 col-md-2">
      <a href="<?= BASE_URL ?>/barangay_admin/officials.php" style="text-decoration:none;">
        <div class="pcard" style="background:#d1fae5">
          <div class="d-flex align-items-center gap-2">
            <div class="pcard-icon" style="background:#6ee7b7"><i class="bi bi-person-badge-fill" style="color:#065f46"></i></div>
            <div><div class="pcard-val" style="color:#064e3b;"><?= number_format($stats['officials_active']) ?></div><div class="pcard-lbl">Active Officials</div></div>
          </div>
        </div>
      </a>
    </div>

    <div class="col-6 col-md-2">
      <a href="<?= BASE_URL ?>/barangay_admin/requests.php" style="text-decoration:none;">
        <div class="pcard" style="background:#fef3c7">
          <div class="d-flex align-items-center gap-2">
            <div class="pcard-icon" style="background:#fcd34d"><i class="bi bi-inbox-fill" style="color:#92400e"></i></div>
            <div><div class="pcard-val" style="color:#78350f;"><?= number_format($stats['requests_pending']) ?></div><div class="pcard-lbl">Pending Requests</div></div>
          </div>
        </div>
      </a>
    </div>

  </div><!-- /row KPI row 1 -->

  <!-- ③ KPI CARDS ROW 2 — Resident Status ─────────────── -->
  <div class="row g-2 mb-3">

    <div class="col-6 col-md-3">
      <div class="pcard" style="background:#e0f2fe">
        <div class="d-flex align-items-center gap-2">
          <div class="pcard-icon" style="background:#7dd3fc"><i class="bi bi-person-check-fill" style="color:#0c4a6e"></i></div>
          <div><div class="pcard-val" style="color:#0c4a6e;"><?= number_format($stats['res_active']) ?></div><div class="pcard-lbl">Active</div></div>
        </div>
      </div>
    </div>

    <div class="col-6 col-md-3">
      <div class="pcard" style="background:#f1f5f9">
        <div class="d-flex align-items-center gap-2">
          <div class="pcard-icon" style="background:#cbd5e1"><i class="bi bi-person-dash-fill" style="color:#475569"></i></div>
          <div><div class="pcard-val" style="color:#334155;"><?= number_format($stats['res_inactive']) ?></div><div class="pcard-lbl">Inactive</div></div>
        </div>
      </div>
    </div>

    <div class="col-6 col-md-3">
      <div class="pcard" style="background:#dbeafe">
        <div class="d-flex align-items-center gap-2">
          <div class="pcard-icon" style="background:#93c5fd"><i class="bi bi-person-walking" style="color:#1e40af"></i></div>
          <div><div class="pcard-val" style="color:#1e3a8a;"><?= number_format($stats['res_transferred']) ?></div><div class="pcard-lbl">Transferred</div></div>
        </div>
      </div>
    </div>

    <div class="col-6 col-md-3">
      <div class="pcard" style="background:#fef2f2">
        <div class="d-flex align-items-center gap-2">
          <div class="pcard-icon" style="background:#fecaca"><i class="bi bi-person-x-fill" style="color:#991b1b"></i></div>
          <div><div class="pcard-val" style="color:#7f1d1d;"><?= number_format($stats['res_deceased']) ?></div><div class="pcard-lbl">Deceased</div></div>
        </div>
      </div>
    </div>

  </div><!-- /row KPI row 2 -->

  <!-- ④ POPULATION OVERVIEW — compact ─────────────────── -->
  <div class="sec-title mt-2"><i class="bi bi-graph-up text-primary"></i>Population Overview — Last 12 Months</div>
  <div class="row g-2 mb-3">

    <!-- LEFT: line chart -->
    <div class="col-md-7">
      <div class="chart-card h-100">
        <div class="ch">
          <span><i class="bi bi-graph-up text-primary"></i> New Residents — Last 12 Months</span>
        </div>
        <div class="cb"><canvas id="popTrendChart" style="max-height:160px"></canvas></div>
      </div>
    </div>

    <!-- RIGHT: 2x2 sector summary -->
    <div class="col-md-5">
      <div class="row g-2 h-100">

        <div class="col-6">
          <div class="pcard" style="background:#ede9fe;height:100%">
            <div class="d-flex align-items-center gap-2">
              <div class="pcard-icon" style="background:#c4b5fd"><i class="bi bi-person-wheelchair" style="color:#5b21b6"></i></div>
              <div><div class="pcard-val" style="color:#4c1d95;"><?= number_format($stats['pwd']) ?></div><div class="pcard-lbl">PWD</div></div>
            </div>
          </div>
        </div>

        <div class="col-6">
          <div class="pcard" style="background:#ffedd5;height:100%">
            <div class="d-flex align-items-center gap-2">
              <div class="pcard-icon" style="background:#fdba74"><i class="bi bi-person-fill" style="color:#9a3412"></i></div>
              <div><div class="pcard-val" style="color:#7c2d12;"><?= number_format($stats['senior']) ?></div><div class="pcard-lbl">Senior Citizens</div></div>
            </div>
          </div>
        </div>

        <div class="col-6">
          <div class="pcard" style="background:#fce7f3;height:100%">
            <div class="d-flex align-items-center gap-2">
              <div class="pcard-icon" style="background:#f9a8d4"><i class="bi bi-person-heart" style="color:#9d174d"></i></div>
              <div><div class="pcard-val" style="color:#831843;"><?= number_format($stats['solo']) ?></div><div class="pcard-lbl">Solo Parents</div></div>
            </div>
          </div>
        </div>

        <div class="col-6">
          <div class="pcard" style="background:#d1fae5;height:100%">
            <div class="d-flex align-items-center gap-2">
              <div class="pcard-icon" style="background:#6ee7b7"><i class="bi bi-heart-fill" style="color:#065f46"></i></div>
              <div><div class="pcard-val" style="color:#064e3b;"><?= number_format($stats['4ps']) ?></div><div class="pcard-lbl">4Ps Beneficiaries</div></div>
            </div>
          </div>
        </div>

      </div>
    </div>

  </div><!-- /Population Overview -->

  <!-- ⑤ RECENT ACTIVITIES ─────────────────────────────── -->
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
  <div class="mb-3" style="background:#fef3c7;border:1.5px solid #fcd34d;color:#78350f;border-radius:10px;padding:10px 16px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
    <i class="bi bi-inbox-fill" style="font-size:1rem;color:#92400e;"></i>
    <span style="font-size:.82rem;font-weight:600;">Pending Requests: <strong><?= number_format($stats['requests_pending']) ?></strong></span>
    <a href="<?= BASE_URL ?>/barangay_admin/requests.php" style="color:#92400e;font-size:.78rem;font-weight:700;margin-left:auto;">View All →</a>
  </div>

  <!-- ⑦ BLOTTER STATUS BREAKDOWN ───────────────────────── -->
  <div class="sec-title mt-2"><i class="bi bi-shield-fill-exclamation text-danger"></i>Blotter Status Breakdown</div>
  <div class="row g-2 mb-3">

    <div class="col-6 col-md">
      <div class="pcard" style="background:#fef3c7;">
        <div class="d-flex align-items-center gap-2">
          <div class="pcard-icon" style="background:#fcd34d;"><i class="bi bi-exclamation-circle-fill" style="color:#92400e;"></i></div>
          <div><div class="pcard-val" style="color:#78350f;"><?= number_format($blotterStatusCounts['Open']) ?></div><div class="pcard-lbl">Open</div></div>
        </div>
      </div>
    </div>

    <div class="col-6 col-md">
      <div class="pcard" style="background:#dbeafe;">
        <div class="d-flex align-items-center gap-2">
          <div class="pcard-icon" style="background:#93c5fd;"><i class="bi bi-search" style="color:#1e40af;"></i></div>
          <div><div class="pcard-val" style="color:#1e3a8a;"><?= number_format($blotterStatusCounts['Under Investigation']) ?></div><div class="pcard-lbl">Under Investigation</div></div>
        </div>
      </div>
    </div>

    <div class="col-6 col-md">
      <div class="pcard" style="background:#dcfce7;">
        <div class="d-flex align-items-center gap-2">
          <div class="pcard-icon" style="background:#86efac;"><i class="bi bi-check-circle-fill" style="color:#166534;"></i></div>
          <div><div class="pcard-val" style="color:#14532d;"><?= number_format($blotterStatusCounts['Settled']) ?></div><div class="pcard-lbl">Settled</div></div>
        </div>
      </div>
    </div>

    <div class="col-6 col-md">
      <div class="pcard" style="background:#fee2e2;">
        <div class="d-flex align-items-center gap-2">
          <div class="pcard-icon" style="background:#fca5a5;"><i class="bi bi-arrow-up-circle-fill" style="color:#991b1b;"></i></div>
          <div><div class="pcard-val" style="color:#7f1d1d;"><?= number_format($blotterStatusCounts['Escalated']) ?></div><div class="pcard-lbl">Escalated</div></div>
        </div>
      </div>
    </div>

    <div class="col-6 col-md">
      <div class="pcard" style="background:#f1f5f9;">
        <div class="d-flex align-items-center gap-2">
          <div class="pcard-icon" style="background:#cbd5e1;"><i class="bi bi-lock-fill" style="color:#475569;"></i></div>
          <div><div class="pcard-val" style="color:#334155;"><?= number_format($blotterStatusCounts['Closed']) ?></div><div class="pcard-lbl">Closed</div></div>
        </div>
      </div>
    </div>

  </div><!-- /blotter status breakdown -->

  <!-- ⑧ ANNOUNCEMENTS & REPORTS OVERVIEW ──────────────── -->
  <div class="sec-title mt-2"><i class="bi bi-megaphone-fill text-warning"></i>Announcements &amp; Reports Overview</div>
  <div class="row g-2 mb-3">

    <!-- Latest LGU Notices (loaded via AJAX) -->
    <div class="col-md-6">
      <div class="chart-card h-100">
        <div class="ch">
          <span><i class="bi bi-megaphone-fill text-warning"></i> Latest LGU Notices</span>
        </div>
        <div class="cb" id="notices-container">
          <!-- Skeleton placeholder shown while AJAX loads -->
          <div id="notices-skeleton">
            <?php for ($i = 0; $i < 3; $i++): ?>
            <div style="margin-bottom:12px;padding-bottom:12px;border-bottom:1px solid #e2e8f0;">
              <div style="height:12px;background:#e2e8f0;border-radius:4px;width:70%;margin-bottom:6px;animation:pulse 1.4s ease-in-out infinite;"></div>
              <div style="height:9px;background:#e2e8f0;border-radius:4px;width:35%;margin-bottom:6px;animation:pulse 1.4s ease-in-out infinite;"></div>
              <div style="height:9px;background:#e2e8f0;border-radius:4px;width:90%;animation:pulse 1.4s ease-in-out infinite;"></div>
            </div>
            <?php endfor; ?>
          </div>
          <div id="notices-content" style="display:none;"></div>
        </div>
      </div>
    </div>

    <!-- This Month Quick Stats -->
    <div class="col-md-6">
      <div class="chart-card h-100">
        <div class="ch">
          <span><i class="bi bi-bar-chart-line-fill text-primary"></i> This Month — Quick Stats</span>
          <a href="<?= BASE_URL ?>/barangay_admin/reports.php" style="color:#2563eb;font-size:.7rem;font-weight:600;">Full Report →</a>
        </div>
        <div class="cb">
          <div style="display:flex;flex-direction:column;gap:10px;">

            <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 12px;background:#dbeafe;border-radius:8px;">
              <span style="font-size:.78rem;color:#1e3a8a;font-weight:500;"><i class="bi bi-people-fill me-2" style="color:#1e40af;"></i>New Residents this month</span>
              <strong style="font-size:1.1rem;color:#1e40af;"><?= number_format($rpt_new_residents) ?></strong>
            </div>

            <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 12px;background:#ede9fe;border-radius:8px;">
              <span style="font-size:.78rem;color:#4c1d95;font-weight:500;"><i class="bi bi-file-earmark-check-fill me-2" style="color:#5b21b6;"></i>Certificates this month</span>
              <strong style="font-size:1.1rem;color:#5b21b6;"><?= number_format($rpt_docs_issued) ?></strong>
            </div>

            <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 12px;background:#fee2e2;border-radius:8px;">
              <span style="font-size:.78rem;color:#7f1d1d;font-weight:500;"><i class="bi bi-shield-exclamation me-2" style="color:#991b1b;"></i>Blotter Cases this month</span>
              <strong style="font-size:1.1rem;color:#991b1b;"><?= number_format($rpt_blotter) ?></strong>
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
        borderColor: '#2563eb',
        backgroundColor: 'rgba(37,99,235,0.08)',
        pointBackgroundColor: '#2563eb',
        tension: 0.4,
        fill: true,
        pointRadius: 4
      }]
    },
    options: {
      responsive: true,
      plugins: { legend: { display: false } },
      scales: {
        x: { ticks: { font: { size: 10 }, color: '#64748b' }, grid: { color: '#f1f5f9' } },
        y: { ticks: { font: { size: 10 }, color: '#64748b' }, grid: { color: '#f1f5f9' }, beginAtZero: true }
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

  // ── Load Announcements via AJAX ──────────────────────────
  (function() {
    fetch('<?= BASE_URL ?>/ajax/get_notices_widget.php', { credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        var skeleton = document.getElementById('notices-skeleton');
        var content  = document.getElementById('notices-content');
        if (!skeleton || !content) return;

        if (data.success && data.notices && data.notices.length > 0) {
          var html = '';
          data.notices.forEach(function(n, i) {
            var border = i < data.notices.length - 1
              ? 'margin-bottom:12px;padding-bottom:12px;border-bottom:1px solid #e2e8f0;'
              : '';
            html += '<div style="' + border + '">'
              + '<div class="fw-semibold" style="font-size:.8rem;color:#0f172a;">' + escHtml(n.title) + '</div>'
              + '<div style="font-size:.65rem;color:#64748b;margin-bottom:3px;">' + n.date + '</div>'
              + '<div style="font-size:.72rem;color:#334155;">' + escHtml(n.excerpt) + '</div>'
              + '</div>';
          });
          content.innerHTML = html;
        } else {
          content.innerHTML = '<p style="font-size:.78rem;color:#64748b;text-align:center;margin:16px 0;">No announcements yet.</p>';
        }

        skeleton.style.display = 'none';
        content.style.display  = 'block';
      })
      .catch(function() {
        var skeleton = document.getElementById('notices-skeleton');
        var content  = document.getElementById('notices-content');
        if (skeleton) skeleton.style.display = 'none';
        if (content)  { content.innerHTML = '<p style="font-size:.78rem;color:#94a3b8;text-align:center;margin:16px 0;">Could not load announcements.</p>'; content.style.display = 'block'; }
      });

    function escHtml(str) {
      if (!str) return '';
      return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
  })();

}); // end DOMContentLoaded
</script>
</body>
</html>
