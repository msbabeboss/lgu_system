<?php
require_once __DIR__ . '/../config/config.php';
requireRole('barangay_admin');

$db  = Database::getInstance()->getConnection();
$u   = currentUser();
$bid = (int)$u['barangay_id'];

// ── Auto-create manual_reports table ─────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS manual_reports (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    barangay_id     INT UNSIGNED NOT NULL,
    created_by      INT UNSIGNED NOT NULL,
    report_title    VARCHAR(255) NOT NULL,
    report_body     LONGTEXT NOT NULL,
    report_type     VARCHAR(80) NOT NULL DEFAULT 'General',
    status          ENUM('draft','sent') NOT NULL DEFAULT 'draft',
    sent_at         DATETIME NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_barangay (barangay_id),
    INDEX idx_created  (created_by),
    INDEX idx_status   (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Handle Submit Summary to LGU ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_summary') {
    verifyCsrfOrAbort();
    $period = sanitize($_POST['period'] ?? 'monthly');
    if ($period === 'daily') {
        $sf = $st = date('Y-m-d'); $label = 'Daily — ' . date('M d, Y');
    } elseif ($period === 'yearly') {
        $sf = date('Y-01-01'); $st = date('Y-12-31'); $label = 'Yearly — ' . date('Y');
    } else {
        $sf = date('Y-m-01'); $st = date('Y-m-d'); $label = 'Monthly — ' . date('F Y');
    }
    $sr   = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND resident_status='Active'")->fetchColumn();
    $sh   = (int)$db->query("SELECT COUNT(*) FROM households WHERE barangay_id=$bid")->fetchColumn();
    $sd   = (int)$db->query("SELECT COUNT(*) FROM certificates WHERE barangay_id=$bid")->fetchColumn();
    $sb   = (int)$db->query("SELECT COUNT(*) FROM blotter_cases WHERE barangay_id=$bid")->fetchColumn();
    $sm   = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND gender='Male' AND resident_status='Active'")->fetchColumn();
    $sf2  = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND gender='Female' AND resident_status='Active'")->fetchColumn();
    $sv   = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND voter_status='Registered' AND resident_status='Active'")->fetchColumn();
    $spwd = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND is_pwd=1 AND resident_status='Active'")->fetchColumn();
    $ssen = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND is_senior=1 AND resident_status='Active'")->fetchColumn();
    // Additional fields for full sync
    $snv      = $sr - $sv;
    $s4ps     = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND is_4ps=1 AND resident_status='Active'")->fetchColumn();
    $ssolo    = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND is_solo_parent=1 AND resident_status='Active'")->fetchColumn();
    $semp     = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND occupation_status='Employed' AND resident_status='Active'")->fetchColumn();
    $sunemp   = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND occupation_status='Unemployed' AND resident_status='Active'")->fetchColumn();
    $sself    = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND occupation_status='Self-Employed' AND resident_status='Active'")->fetchColumn();
    $sstud    = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND occupation_status='Student' AND resident_status='Active'")->fetchColumn();
    $sret     = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND occupation_status='Retired' AND resident_status='Active'")->fetchColumn();
    $sbopen   = (int)$db->query("SELECT COUNT(*) FROM blotter_cases WHERE barangay_id=$bid AND status='Open'")->fetchColumn();
    $sbsett   = (int)$db->query("SELECT COUNT(*) FROM blotter_cases WHERE barangay_id=$bid AND status='Settled'")->fetchColumn();
    $sbesc    = (int)$db->query("SELECT COUNT(*) FROM blotter_cases WHERE barangay_id=$bid AND status='Escalated'")->fetchColumn();
    $sbclosed = (int)$db->query("SELECT COUNT(*) FROM blotter_cases WHERE barangay_id=$bid AND status='Closed'")->fetchColumn();
    $stopcert = (string)($db->query("SELECT certificate_type FROM certificates WHERE barangay_id=$bid GROUP BY certificate_type ORDER BY COUNT(*) DESC LIMIT 1")->fetchColumn() ?: '—');
    $stin     = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND resident_status='Active' AND transfer_from_barangay IS NOT NULL AND transfer_from_barangay != ''")->fetchColumn();
    $stout    = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND resident_status='Transferred'")->fetchColumn();
    $stoutside= (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND resident_status='Transferred' AND transfer_outside=1")->fetchColumn();
    // Period-scoped counts
    $srnew    = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND resident_status='Active' AND DATE(created_at) BETWEEN '$sf' AND '$st'")->fetchColumn();
    $sdperiod = (int)$db->query("SELECT COUNT(*) FROM certificates WHERE barangay_id=$bid AND DATE(date_issued) BETWEEN '$sf' AND '$st'")->fetchColumn();
    $sbperiod = (int)$db->query("SELECT COUNT(*) FROM blotter_cases WHERE barangay_id=$bid AND DATE(created_at) BETWEEN '$sf' AND '$st'")->fetchColumn();
    $stinp    = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND resident_status='Active' AND transfer_from_barangay IS NOT NULL AND transfer_from_barangay != '' AND DATE(created_at) BETWEEN '$sf' AND '$st'")->fetchColumn();
    $stoutp   = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND resident_status='Transferred' AND DATE(created_at) BETWEEN '$sf' AND '$st'")->fetchColumn();

    $notes = "[$label]"
        . " Total Residents: $sr"
        . " | New Residents (period): $srnew"
        . " | Households: $sh"
        . " | Total Certificates: $sd"
        . " | Certificates (period): $sdperiod"
        . " | Top Certificate: $stopcert"
        . " | Total Blotter: $sb"
        . " | Blotter (period): $sbperiod"
        . " | Blotter Open: $sbopen"
        . " | Blotter Settled: $sbsett"
        . " | Blotter Escalated: $sbesc"
        . " | Blotter Closed: $sbclosed"
        . " | Male: $sm"
        . " | Female: $sf2"
        . " | Registered Voters: $sv"
        . " | Non-Voters: $snv"
        . " | PWD: $spwd"
        . " | Senior Citizens: $ssen"
        . " | 4Ps: $s4ps"
        . " | Solo Parents: $ssolo"
        . " | Employed: $semp"
        . " | Unemployed: $sunemp"
        . " | Self-Employed: $sself"
        . " | Student: $sstud"
        . " | Retired: $sret"
        . " | Transferred In (Total): $stin"
        . " | Transferred In (period): $stinp"
        . " | Transferred Out (Total): $stout"
        . " | Transferred Out (period): $stoutp"
        . " | Transferred Outside LGU: $stoutside";
    $reportType = $period === 'monthly' ? 'monthly' : 'accomplishment';
    $monthNum = (int)date('n'); $yearNum = (int)date('Y');
    $ex = $db->prepare("SELECT id FROM reports WHERE barangay_id=? AND report_type=? AND month=? AND year=? AND notes LIKE ?");
    $ex->execute([$bid, $reportType, $monthNum, $yearNum, '[' . $label . ']%']);
    $ex = $ex->fetch();
    if ($ex) {
        $db->prepare("UPDATE reports SET status='submitted', notes=?, submitted_by=? WHERE id=?")->execute([$notes, $u['id'], $ex['id']]);
    } else {
        $db->prepare("INSERT INTO reports (barangay_id, report_type, month, year, status, submitted_by, notes) VALUES (?,?,?,?,?,?,?)")->execute([$bid, $reportType, $monthNum, $yearNum, 'submitted', $u['id'], $notes]);
    }
    $brgyName = $db->query("SELECT barangay_name FROM barangays WHERE id=$bid")->fetchColumn();
    sendNotification($u['id'], "You submitted your <strong>$period summary report</strong> to the LGU. ($label)", 'success', 'transaction');
    foreach ($db->query("SELECT id FROM users WHERE role='super_admin' AND status='active'")->fetchAll() as $adm) {
        sendNotification($adm['id'], "Barangay <strong>$brgyName</strong> sent a <strong>$period summary report</strong>. ($label)", 'info', 'transaction');
    }
    logActivity($u['id'], 'SUBMIT_REPORT', 'Reports', "Submitted $period summary to LGU");
    flash('rep', "✅ $label summary report submitted to LGU.", 'success');
    redirect(BASE_URL . '/barangay_admin/reports.php');
}

// ── Fetch manual reports ──────────────────────────────────
$mrStmt = $db->prepare("SELECT mr.*, CONCAT(u.first_name,' ',u.last_name) as author_name FROM manual_reports mr LEFT JOIN users u ON mr.created_by=u.id WHERE mr.barangay_id=? ORDER BY mr.updated_at DESC");
$mrStmt->execute([$bid]);
$manualReports = $mrStmt->fetchAll();

// ── Auto-create attachments table & fetch attachments per report ──
$db->exec("CREATE TABLE IF NOT EXISTS manual_report_attachments (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_id       INT UNSIGNED NOT NULL,
    barangay_id     INT UNSIGNED NOT NULL,
    uploaded_by     INT UNSIGNED NOT NULL,
    original_name   VARCHAR(255) NOT NULL,
    stored_name     VARCHAR(255) NOT NULL,
    file_path       VARCHAR(500) NOT NULL,
    file_size       INT UNSIGNED NOT NULL DEFAULT 0,
    mime_type       VARCHAR(120) NOT NULL DEFAULT '',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_report   (report_id),
    INDEX idx_barangay (barangay_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$mrAttachments = []; // keyed by report_id
if (!empty($manualReports)) {
    $rIds = implode(',', array_map(fn($r)=>(int)$r['id'], $manualReports));
    $attRows = $db->query("SELECT id, report_id, original_name, file_path, file_size, mime_type FROM manual_report_attachments WHERE report_id IN ($rIds) AND barangay_id=$bid ORDER BY created_at ASC")->fetchAll();
    foreach ($attRows as $att) {
        $mrAttachments[(int)$att['report_id']][] = $att;
    }
}

// ── Filter params ─────────────────────────────────────────
$category     = 'summary'; // always show summary in Data Reports
$dateFrom     = sanitize($_GET['date_from']    ?? '');
$dateTo       = sanitize($_GET['date_to']      ?? '');
$activePreset = sanitize($_GET['date_preset']  ?? '');
if ($activePreset && $activePreset !== 'custom') {
    $today = date('Y-m-d');
    switch ($activePreset) {
        case 'today':    $dateFrom = $today; $dateTo = $today; break;
        case 'last_day': $dateFrom = date('Y-m-d', strtotime('-1 day')); $dateTo = $today; break;
        case 'week':     $dateFrom = date('Y-m-d', strtotime('-7 days')); $dateTo = $today; break;
        case 'month':    $dateFrom = date('Y-m-d', strtotime('-30 days')); $dateTo = $today; break;
        case 'quarter':  $dateFrom = date('Y-m-d', strtotime('-90 days')); $dateTo = $today; break;
        case 'year':     $dateFrom = date('Y-01-01'); $dateTo = $today; break;
    }
}

function rptDateClause(string $col, string $from, string $to): array {
    if ($from && $to) return ['clause' => "AND DATE($col) BETWEEN ? AND ?", 'params' => [$from, $to]];
    if ($from)        return ['clause' => "AND DATE($col) >= ?",             'params' => [$from]];
    if ($to)          return ['clause' => "AND DATE($col) <= ?",             'params' => [$to]];
    return ['clause' => '', 'params' => []];
}

$_dc = rptDateClause('created_at', $dateFrom, $dateTo);
$s = $db->prepare("SELECT COUNT(*) FROM residents WHERE barangay_id=? AND resident_status='Active' {$_dc['clause']}"); $s->execute(array_merge([$bid], $_dc['params'])); $totalResidents = (int)$s->fetchColumn();
$s = $db->prepare("SELECT COUNT(*) FROM certificates WHERE barangay_id=? {$_dc['clause']}"); $s->execute(array_merge([$bid], $_dc['params'])); $totalDocs = (int)$s->fetchColumn();
$s = $db->prepare("SELECT COUNT(*) FROM blotter_cases WHERE barangay_id=? {$_dc['clause']}"); $s->execute(array_merge([$bid], $_dc['params'])); $totalBlotter = (int)$s->fetchColumn();
$totalHouseholds = (int)$db->query("SELECT COUNT(*) FROM households WHERE barangay_id=$bid")->fetchColumn();
$s = $db->prepare("SELECT COUNT(*) FROM residents WHERE barangay_id=? AND resident_status='Transferred' {$_dc['clause']}"); $s->execute(array_merge([$bid], $_dc['params'])); $totalTransferredOut = (int)$s->fetchColumn();
$s = $db->prepare("SELECT COUNT(*) FROM residents WHERE barangay_id=? AND resident_status='Active' AND transfer_from_barangay IS NOT NULL AND transfer_from_barangay != '' {$_dc['clause']}"); $s->execute(array_merge([$bid], $_dc['params'])); $totalTransferredIn = (int)$s->fetchColumn();
$s = $db->prepare("SELECT COUNT(*) FROM residents WHERE barangay_id=? AND resident_status='Transferred' AND transfer_outside=1 {$_dc['clause']}"); $s->execute(array_merge([$bid], $_dc['params'])); $totalTransferredOutside = (int)$s->fetchColumn();

if (in_array($category, ['residents','summary'])) { $_rdc = rptDateClause('r.created_at',$dateFrom,$dateTo); $stmt = $db->prepare("SELECT r.*,a.purok,a.barangay as addr_brgy FROM residents r LEFT JOIN addresses a ON r.address_id=a.id WHERE r.barangay_id=? AND r.resident_status='Active' {$_rdc['clause']} ORDER BY r.created_at DESC"); $stmt->execute(array_merge([$bid],$_rdc['params'])); $residentRows=$stmt->fetchAll(); }
if (in_array($category, ['documents','summary'])) { $_ddc = rptDateClause('c.date_issued',$dateFrom,$dateTo); $stmt = $db->prepare("SELECT c.*,CONCAT(r.last_name,', ',r.first_name) as resident_name,CONCAT(u.first_name,' ',u.last_name) as issued_by_name FROM certificates c LEFT JOIN residents r ON c.resident_id=r.id LEFT JOIN users u ON c.issued_by=u.id WHERE c.barangay_id=? {$_ddc['clause']} ORDER BY c.date_issued DESC"); $stmt->execute(array_merge([$bid],$_ddc['params'])); $docRows=$stmt->fetchAll(); }
if (in_array($category, ['blotter','summary']))   { $_bdc = rptDateClause('bc.created_at',$dateFrom,$dateTo); $stmt = $db->prepare("SELECT bc.*,CONCAT(c.last_name,', ',c.first_name) as complainant_name,CONCAT(r.last_name,', ',r.first_name) as respondent_name FROM blotter_cases bc LEFT JOIN residents c ON bc.complainant_id=c.id LEFT JOIN residents r ON bc.respondent_id=r.id WHERE bc.barangay_id=? {$_bdc['clause']} ORDER BY bc.created_at DESC"); $stmt->execute(array_merge([$bid],$_bdc['params'])); $blotterRows=$stmt->fetchAll(); }
if (in_array($category, ['activity','summary','audit'])) { $_adc = rptDateClause('al.created_at',$dateFrom,$dateTo); $stmt = $db->prepare("SELECT al.*,CONCAT(u.first_name,' ',u.last_name) as user_name,u.role FROM activity_logs al LEFT JOIN users u ON al.user_id=u.id WHERE al.user_id=? {$_adc['clause']} ORDER BY al.created_at DESC LIMIT 200"); $stmt->execute(array_merge([$u['id']],$_adc['params'])); $activityRows=$stmt->fetchAll(); }
if (in_array($category, ['households','summary'])) { $stmt = $db->prepare("SELECT h.*,a.purok,a.street,a.barangay as addr_brgy,CONCAT(r.last_name,', ',r.first_name) as head_name FROM households h LEFT JOIN addresses a ON h.address_id=a.id LEFT JOIN residents r ON h.head_resident_id=r.id WHERE h.barangay_id=? ORDER BY h.created_at DESC"); $stmt->execute([$bid]); $householdRows=$stmt->fetchAll(); }
if (in_array($category, ['officials','summary']))  { $stmt = $db->prepare("SELECT * FROM officials WHERE barangay_id=? AND status='active' ORDER BY position,full_name"); $stmt->execute([$bid]); $officialRows=$stmt->fetchAll(); }
if ($category === 'audit') { $_audc = rptDateClause('al.created_at',$dateFrom,$dateTo); $stmt = $db->prepare("SELECT al.*,CONCAT(u.first_name,' ',u.last_name) as user_name,u.username,u.role FROM activity_logs al LEFT JOIN users u ON al.user_id=u.id WHERE al.user_id=? {$_audc['clause']} ORDER BY al.created_at DESC"); $stmt->execute(array_merge([$u['id']],$_audc['params'])); $auditRows=$stmt->fetchAll(); }
if (in_array($category, ['transfers','summary'])) {
    $_tout = rptDateClause('r.created_at',$dateFrom,$dateTo); $stmt = $db->prepare("SELECT r.first_name,r.middle_name,r.last_name,r.suffix,r.gender,r.age,r.transfer_to_barangay,r.transfer_from_barangay,r.transfer_outside,r.transfer_reason,r.created_at,'OUT' as transfer_direction,a.purok,a.barangay as addr_brgy FROM residents r LEFT JOIN addresses a ON r.address_id=a.id WHERE r.barangay_id=? AND r.resident_status='Transferred' {$_tout['clause']} ORDER BY r.created_at DESC"); $stmt->execute(array_merge([$bid],$_tout['params'])); $transferOutRows=$stmt->fetchAll();
    $_tin  = rptDateClause('r.created_at',$dateFrom,$dateTo); $stmt = $db->prepare("SELECT r.first_name,r.middle_name,r.last_name,r.suffix,r.gender,r.age,r.transfer_to_barangay,r.transfer_from_barangay,r.transfer_outside,r.transfer_reason,r.created_at,'IN' as transfer_direction,a.purok,a.barangay as addr_brgy FROM residents r LEFT JOIN addresses a ON r.address_id=a.id WHERE r.barangay_id=? AND r.resident_status='Active' AND r.transfer_from_barangay IS NOT NULL AND r.transfer_from_barangay != '' {$_tin['clause']} ORDER BY r.created_at DESC"); $stmt->execute(array_merge([$bid],$_tin['params'])); $transferInRows=$stmt->fetchAll();
}
$_ddbc = rptDateClause('date_issued',$dateFrom,$dateTo); $s=$db->prepare("SELECT certificate_type,COUNT(*) as cnt FROM certificates WHERE barangay_id=? {$_ddbc['clause']} GROUP BY certificate_type ORDER BY cnt DESC"); $s->execute(array_merge([$bid],$_ddbc['params'])); $docBreakdown=$s->fetchAll();
$_bdbc = rptDateClause('created_at',$dateFrom,$dateTo); $s=$db->prepare("SELECT status,COUNT(*) as cnt FROM blotter_cases WHERE barangay_id=? {$_bdbc['clause']} GROUP BY status"); $s->execute(array_merge([$bid],$_bdbc['params'])); $blotterBreakdown=$s->fetchAll();
$trendLabels=$trendResidents=$trendDocs=$trendBlotter=[];
for ($i=5;$i>=0;$i--) { $m=date('Y-m',strtotime("-$i months")); $trendLabels[]=date('M Y',strtotime("-$i months")); $trendResidents[]=(int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND DATE_FORMAT(created_at,'%Y-%m')='$m'")->fetchColumn(); $trendDocs[]=(int)$db->query("SELECT COUNT(*) FROM certificates WHERE barangay_id=$bid AND DATE_FORMAT(date_issued,'%Y-%m')='$m'")->fetchColumn(); $trendBlotter[]=(int)$db->query("SELECT COUNT(*) FROM blotter_cases WHERE barangay_id=$bid AND DATE_FORMAT(created_at,'%Y-%m')='$m'")->fetchColumn(); }

$flash        = getFlash('rep');
$pageIcon     = 'bi-file-earmark-bar-graph-fill';
$pageTitle    = 'Reports Module';
$pageSubtitle = 'Generate, compose, and send barangay reports.';
$authorName   = htmlspecialchars($u['name'] ?? ($u['first_name'].' '.$u['last_name']));

$typeMap = [
    'General'       => ['bg'=>'#e8f0fe','color'=>'#1a4f7a','icon'=>'📋'],
    'Incident'      => ['bg'=>'#fee2e2','color'=>'#991b1b','icon'=>'🚨'],
    'Health'        => ['bg'=>'#d1fae5','color'=>'#065f46','icon'=>'🏥'],
    'Financial'     => ['bg'=>'#fef3c7','color'=>'#854d0e','icon'=>'💰'],
    'Infrastructure'=> ['bg'=>'#ede9fe','color'=>'#4c1d95','icon'=>'🏗️'],
    'Census'        => ['bg'=>'#e0f2fe','color'=>'#0f4c75','icon'=>'📊'],
    'Other'         => ['bg'=>'#f3f4f6','color'=>'#374151','icon'=>'📄'],
];
$sentList  = array_filter($manualReports, fn($r)=>$r['status']==='sent');
$draftList = array_filter($manualReports, fn($r)=>$r['status']==='draft');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favcon.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=2.1">
    <style>
    /* ══ DATA REPORTS SECTION ══════════════════════════════ */
    .data-reports-section {
        background: #fff;
        border-radius: 16px;
        box-shadow: 0 2px 16px rgba(0,0,0,.07);
        border: 1px solid #e2eaf2;
        margin-bottom: 1.5rem;
        overflow: hidden;
    }
    .data-reports-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 12px 18px;
        background: linear-gradient(135deg,#1a4f7a 0%,#2d6fa3 100%);
        flex-wrap: wrap;
        gap: 8px;
    }
    .data-reports-header .section-title {
        font-size: .88rem;
        font-weight: 700;
        color: #fff;
        display: flex;
        align-items: center;
        gap: 7px;
        margin: 0;
    }
    /* download boxes */
    .dl-boxes {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        align-items: center;
    }
    .dl-box {
        display: flex;
        align-items: center;
        gap: 6px;
        padding: 6px 14px;
        border-radius: 8px;
        font-size: .78rem;
        font-weight: 600;
        text-decoration: none;
        border: none;
        cursor: pointer;
        transition: opacity .15s, transform .12s;
        white-space: nowrap;
    }
    .dl-box:hover { opacity: .88; transform: translateY(-1px); text-decoration: none; }
    .dl-box-excel { background: #16a34a; color: #fff; }
    .dl-box-pdf   { background: #dc2626; color: #fff; }
    .dl-box-send  { background: rgba(255,255,255,.18); color: #fff; border: 1.5px solid rgba(255,255,255,.4); }
    .dl-box-send:hover { background: rgba(255,255,255,.28); color: #fff; }

    /* filter bar compact */
    .data-filter-bar {
        padding: 10px 18px;
        border-bottom: 1px solid #e2eaf2;
        background: #f8fafc;
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 8px;
    }

    /* stat mini cards */
    .stat-mini-row {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        padding: 12px 18px;
        border-bottom: 1px solid #f0f4f8;
    }
    .stat-mini {
        display: flex;
        align-items: center;
        gap: 8px;
        background: #f8fafc;
        border: 1px solid #e8eef5;
        border-radius: 10px;
        padding: 7px 14px;
        flex: 1;
        min-width: 130px;
    }
    .stat-mini-icon {
        width: 32px; height: 32px;
        border-radius: 8px;
        display: flex; align-items: center; justify-content: center;
        font-size: .95rem; flex-shrink: 0;
    }
    .stat-mini-val  { font-size: 1.05rem; font-weight: 700; color: #1a2e3f; line-height: 1; }
    .stat-mini-lbl  { font-size: .68rem; color: #64748b; margin-top: 1px; }

    /* data content area */
    .data-content { padding: 14px 18px; }

    /* ══ MANUAL REPORTS SHELL (notices-style) ══════════════ */
    .rpt-shell {
        display: flex;
        height: 560px;
        min-height: 480px;
        background: #fff;
        border-radius: 16px;
        box-shadow: 0 2px 20px rgba(0,0,0,.08);
        border: 1px solid #e2eaf2;
    }
    .rpt-section-label {
        font-size: .78rem;
        font-weight: 700;
        color: #1a4f7a;
        display: flex;
        align-items: center;
        gap: 6px;
        padding: 10px 0 6px;
        text-transform: uppercase;
        letter-spacing: .05em;
    }

    /* LEFT LIST PANEL */
    .rpt-list {
        width: 320px; min-width: 260px; flex-shrink: 0;
        display: flex; flex-direction: column;
        border-right: 1px solid #e2eaf2;
        background: #f8fafc;
        border-radius: 16px 0 0 16px;
        overflow: hidden;
    }
    .rpt-list-head {
        padding: 10px 12px; background: #fff;
        border-bottom: 1px solid #e2eaf2;
        display: flex; align-items: center; justify-content: space-between;
        flex-shrink: 0;
    }
    .rpt-list-body { flex: 1; overflow-y: auto; }
    .rpt-list-body::-webkit-scrollbar { width: 4px; }
    .rpt-list-body::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

    .rpt-item {
        display: flex; align-items: flex-start; gap: 10px;
        padding: 11px 14px; border-bottom: 1px solid #eef2f7;
        cursor: pointer; background: #fff; transition: background .12s;
    }
    .rpt-item:hover  { background: #f0f6ff; }
    .rpt-item.active { background: #e8f0fe; border-left: 3px solid #1a4f7a; }
    .rpt-item-icon   { width: 36px; height: 36px; border-radius: 10px; flex-shrink: 0;
                        display: flex; align-items: center; justify-content: center; font-size: 1.1rem; }
    .rpt-item-body   { flex: 1; min-width: 0; }
    .rpt-item-title  { font-size: .82rem; font-weight: 600; color: #1a2e3f;
                        white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .rpt-item-preview{ font-size: .72rem; color: #64748b;
                        white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 1px; }
    .rpt-item-meta   { font-size: .65rem; color: #94a3b8; margin-top: 3px; }

    /* RIGHT PANEL */
    .rpt-right {
        flex: 1; display: flex; flex-direction: column;
        overflow: hidden;
        border-radius: 0 16px 16px 0;
    }
    .rpt-empty { flex: 1; display: flex; flex-direction: column;
                 align-items: center; justify-content: center; color: #94a3b8; gap: 10px; }
    .rpt-header {
        padding: 16px 22px 12px; border-bottom: 1px solid #e2eaf2; flex-shrink: 0;
    }
    .rpt-body   { flex: 1; overflow-y: auto; padding: 18px 22px; display: flex; flex-direction: column; }
    .rpt-body::-webkit-scrollbar { width: 4px; }
    .rpt-body::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
    .rpt-footer {
        padding: 10px 18px; border-top: 1px solid #e2eaf2;
        background: #f8fafc; display: flex; align-items: center; gap: 8px; flex-shrink: 0;
    }
    .rpt-content {
        background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px;
        padding: 16px 18px; line-height: 1.8; font-size: .9rem; color: #334155;
        min-height: 180px; flex: 1;
    }
    div.rpt-content  { white-space: pre-wrap; }
    textarea.rpt-content {
        resize: none; outline: none; width: 100%;
        font-family: 'Segoe UI', system-ui, sans-serif;
        transition: border-color .15s, box-shadow .15s;
    }
    textarea.rpt-content:focus {
        border-color: #1a4f7a;
        box-shadow: 0 0 0 3px rgba(26,79,122,.08);
        background: #fff;
    }
    .rpt-pane       { display: none; flex-direction: column; flex: 1; min-height: 0; overflow: hidden; }
    .rpt-pane.show  { display: flex; }
    #paneEmpty.rpt-pane.show { align-items: center; justify-content: center; }

    /* ── Attachment chips ──────────────────────────────── */
    .att-bar {
        padding: 6px 18px 0;
        display: flex; flex-wrap: wrap; gap: 6px;
        min-height: 0;
    }
    .att-bar:empty { padding: 0; }
    .att-chip {
        display: inline-flex; align-items: center; gap: 5px;
        background: #f0f6ff; border: 1px solid #c3d8f5;
        border-radius: 20px; padding: 3px 10px;
        font-size: .72rem; color: #1a4f7a; max-width: 220px;
    }
    .att-chip .att-name {
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        max-width: 140px;
    }
    .att-chip .att-del {
        background: none; border: none; padding: 0 0 0 4px;
        color: #94a3b8; cursor: pointer; font-size: .75rem; line-height: 1;
        flex-shrink: 0;
    }
    .att-chip .att-del:hover { color: #dc2626; }
    /* read-only chips in detail pane */
    .att-chip-ro {
        display: inline-flex; align-items: center; gap: 5px;
        background: #f0f6ff; border: 1px solid #c3d8f5;
        border-radius: 20px; padding: 4px 12px;
        font-size: .75rem; color: #1a4f7a; text-decoration: none;
        transition: background .12s;
    }
    .att-chip-ro:hover { background: #dbeafe; color: #1a4f7a; text-decoration: none; }
    /* attach toolbar inside composer body */
    .att-toolbar {
        display: flex; align-items: center; gap: 8px;
        padding: 6px 0 2px; flex-shrink: 0;
    }
    .att-toolbar label {
        display: inline-flex; align-items: center; gap: 5px;
        font-size: .75rem; font-weight: 600; color: #1a4f7a;
        cursor: pointer; padding: 4px 12px; border-radius: 8px;
        border: 1.5px dashed #94bcdf; background: #f0f6ff;
        transition: background .12s, border-color .12s;
    }
    .att-toolbar label:hover { background: #dbeafe; border-color: #1a4f7a; }
    .att-toolbar .att-hint { font-size: .68rem; color: #94a3b8; }
    html.dark-mode .att-chip, html.dark-mode .att-chip-ro { background:#0f2a45;border-color:#1e3a5f;color:#7da0ca; }
    html.dark-mode .att-toolbar label { background:#0a1929;border-color:#2d5a8a;color:#7da0ca; }
    html.dark-mode .att-toolbar label:hover { background:#0f2a45; }

    /* dark mode */
    html.dark-mode .data-reports-section { background: #0f1f35; border-color: #1e3a5f; }
    html.dark-mode .data-filter-bar { background: #0a1929; border-color: #1e3a5f; }
    html.dark-mode .stat-mini { background: #0a1929; border-color: #1a3050; }
    html.dark-mode .stat-mini-val { color: #c1e8ff; }
    html.dark-mode .rpt-shell   { background: #0f1f35; border-color: #1e3a5f; }
    html.dark-mode .rpt-list    { background: #0a1929; border-color: #1e3a5f; }
    html.dark-mode .rpt-list-head { background: #0f1f35; border-color: #1e3a5f; }
    html.dark-mode .rpt-item    { background: #0f1f35; border-color: #1a3050; }
    html.dark-mode .rpt-item:hover   { background: #162844; }
    html.dark-mode .rpt-item.active  { background: #1a3a6e; border-left-color: #7da0ca; }
    html.dark-mode .rpt-item-title   { color: #c1e8ff; }
    html.dark-mode .rpt-right   { background: #0f1f35; }
    html.dark-mode .rpt-header, html.dark-mode .rpt-footer { border-color: #1e3a5f; }
    html.dark-mode .rpt-footer  { background: #0a1929; }
    html.dark-mode .rpt-content { background: #0a1929; border-color: #1e3a5f; color: #a8c8e8; }
    html.dark-mode textarea.rpt-content:focus { background: #081525; }

    @media(max-width:768px) {
        .rpt-shell { flex-direction: column; height: auto; }
        .rpt-list  { width: 100%; max-width: 100%; border-right: none; border-bottom: 1px solid #e2eaf2;
                     max-height: 260px; border-radius: 16px 16px 0 0; }
        .rpt-right { border-radius: 0 0 16px 16px; min-height: 320px; }
        .data-reports-header { flex-direction: column; align-items: flex-start; }
    }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/sidebar_barangay.php'; ?>
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<div class="main-content">

<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show mb-3">
    <?= $flash['message'] ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ════════════════════════════════════════════════════════
     SECTION 1 — DATA REPORTS
     ════════════════════════════════════════════════════════ -->
<div class="data-reports-section">

    <!-- Header row: title + download boxes -->
    <div class="data-reports-header">
        <h6 class="section-title">
            <i class="bi bi-bar-chart-fill"></i> Data Reports
        </h6>
        <div class="dl-boxes">
            <a href="export_report.php?category=<?= urlencode($category) ?>&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>&format=excel"
               class="dl-box dl-box-excel">
                <i class="bi bi-file-earmark-excel-fill"></i> Download Excel
            </a>
            <a href="export_report.php?category=<?= urlencode($category) ?>&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>&format=pdf"
               target="_blank" class="dl-box dl-box-pdf">
                <i class="bi bi-file-earmark-pdf-fill"></i> Download PDF
            </a>
            <button type="button" class="dl-box dl-box-send" data-bs-toggle="modal" data-bs-target="#modalSubmitLGU">
                <i class="bi bi-send-fill"></i> Send to LGU
            </button>
        </div>
    </div>

    <!-- Filter bar -->
    <div class="data-filter-bar">
        <form method="GET" class="d-flex flex-wrap align-items-center gap-2 w-100" id="filterForm">
            <input type="hidden" name="category" value="summary">
            <span class="fw-semibold small text-muted">📊 Summary Review</span>
            <div class="d-flex align-items-center gap-2">
                <?php
                $drpId='reports'; $drpDateFrom=$dateFrom; $drpDateTo=$dateTo;
                $drpPreset=$activePreset??''; $drpMinDate=''; $drpMaxDate=date('Y-m-d');
                include __DIR__.'/../includes/date_range_picker.php';
                ?>
                <a href="reports.php" class="btn btn-outline-secondary btn-sm" title="Clear filters"><i class="bi bi-x-lg"></i></a>
            </div>
        </form>
    </div>

    <!-- Stat mini row -->
    <div class="stat-mini-row">
        <?php
        $miniCards = [
            ['icon'=>'bi-people-fill',           'bg'=>'#e8f0fe','color'=>'#1a4f7a', 'val'=>$totalResidents,     'lbl'=>'New Residents'],
            ['icon'=>'bi-file-earmark-text-fill', 'bg'=>'#e8f4fb','color'=>'#1a4f7a', 'val'=>$totalDocs,          'lbl'=>'Docs Issued'],
            ['icon'=>'bi-shield-exclamation',     'bg'=>'#ffebee','color'=>'#c62828', 'val'=>$totalBlotter,       'lbl'=>'Blotter Cases'],
            ['icon'=>'bi-house-door-fill',        'bg'=>'#fff3e0','color'=>'#e65100', 'val'=>$totalHouseholds,    'lbl'=>'Households'],
            ['icon'=>'bi-box-arrow-in-down-right','bg'=>'#e8faf0','color'=>'#16a34a', 'val'=>$totalTransferredIn, 'lbl'=>'Transferred In'],
            ['icon'=>'bi-box-arrow-up-right',     'bg'=>'#fff0f0','color'=>'#dc2626', 'val'=>$totalTransferredOut,'lbl'=>'Transferred Out'],
        ];
        foreach ($miniCards as $c): ?>
        <div class="stat-mini">
            <div class="stat-mini-icon" style="background:<?= $c['bg'] ?>;color:<?= $c['color'] ?>;"><i class="bi <?= $c['icon'] ?>"></i></div>
            <div><div class="stat-mini-val"><?= number_format($c['val']) ?></div><div class="stat-mini-lbl"><?= $c['lbl'] ?></div></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Category content -->
    <div class="data-content">

    <?php if ($category==='summary'): ?>
    <div class="row g-3 mb-3">
        <div class="col-md-8"><div class="card h-100"><div class="card-header py-2 small fw-semibold"><i class="bi bi-graph-up me-2"></i>6-Month Trend</div><div class="card-body p-2"><canvas id="trendChart" height="90"></canvas></div></div></div>
        <div class="col-md-4"><div class="card h-100"><div class="card-header py-2 small fw-semibold"><i class="bi bi-pie-chart-fill me-2"></i>Documents by Type</div><div class="card-body py-2 px-3">
            <?php if (empty($docBreakdown)): ?><p class="text-muted small text-center mt-3">No documents.</p>
            <?php else: foreach ($docBreakdown as $d): ?>
            <div class="d-flex justify-content-between align-items-center mb-2"><span class="small"><?= htmlspecialchars($d['certificate_type']) ?></span><span class="badge bg-primary"><?= $d['cnt'] ?></span></div>
            <?php endforeach; endif; ?>
        </div></div></div>
    </div>
    <?php if (!empty($blotterBreakdown)): ?>
    <div class="card mb-3"><div class="card-header py-2 small fw-semibold"><i class="bi bi-shield-exclamation me-2"></i>Blotter by Status</div><div class="card-body py-2"><div class="d-flex gap-3 flex-wrap">
        <?php foreach ($blotterBreakdown as $b): ?><div class="text-center px-3 py-2 rounded" style="background:rgba(26,79,122,.06);"><div class="fw-bold fs-5"><?= $b['cnt'] ?></div><span class="badge bg-secondary"><?= $b['status'] ?></span></div><?php endforeach; ?>
    </div></div></div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($category==='residents'): ?>
    <div class="card"><div class="card-header py-2 small fw-semibold"><i class="bi bi-people-fill me-2"></i>Residents (<?= count($residentRows) ?>)</div><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>#</th><th>Full Name</th><th>Gender</th><th>Age</th><th>Civil Status</th><th>Address</th><th>Voter</th><th>Tags</th><th>Date</th></tr></thead><tbody>
    <?php if (empty($residentRows)): ?><tr><td colspan="9" class="text-center text-muted py-4">No records.</td></tr>
    <?php else: foreach ($residentRows as $i=>$r): ?><tr><td><?= $i+1 ?></td><td class="fw-semibold"><?= htmlspecialchars($r['last_name'].', '.$r['first_name'].($r['middle_name']?' '.$r['middle_name'][0].'.':'')) ?></td><td><span class="badge bg-secondary"><?= $r['gender'] ?></span></td><td><?= $r['age'] ?></td><td><?= $r['civil_status'] ?></td><td class="small text-muted"><?= htmlspecialchars(($r['purok']?'Purok '.$r['purok'].', ':'').($r['addr_brgy']??'')) ?></td><td><?= $r['voter_status']==='Registered'?'<span class="badge bg-secondary">Yes</span>':'<span class="badge bg-secondary">No</span>' ?></td><td><?= $r['is_pwd']?'<span class="badge bg-secondary">PWD</span> ':'' ?><?= $r['is_senior']?'<span class="badge bg-secondary">Senior</span> ':'' ?></td><td class="small"><?= date('M d, Y',strtotime($r['created_at'])) ?></td></tr>
    <?php endforeach; endif; ?></tbody></table></div></div></div>
    <?php endif; ?>

    <?php if ($category==='documents'): ?>
    <div class="row g-2 mb-3"><?php foreach ($docBreakdown as $d): ?><div class="col-6 col-md-2"><div class="card text-center py-2"><div class="fw-bold fs-5"><?= $d['cnt'] ?></div><div class="small text-muted"><?= htmlspecialchars($d['certificate_type']) ?></div></div></div><?php endforeach; ?></div>
    <div class="card"><div class="card-header py-2 small fw-semibold"><i class="bi bi-file-earmark-text-fill me-2"></i>Documents (<?= count($docRows) ?>)</div><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>#</th><th>Resident</th><th>Type</th><th>Purpose</th><th>Issued By</th><th>Date</th></tr></thead><tbody>
    <?php if (empty($docRows)): ?><tr><td colspan="6" class="text-center text-muted py-4">No records.</td></tr>
    <?php else: foreach ($docRows as $i=>$d): ?><tr><td><?= $i+1 ?></td><td class="fw-semibold"><?= htmlspecialchars($d['resident_name']) ?></td><td><span class="badge bg-secondary"><?= $d['certificate_type'] ?></span></td><td class="small text-muted"><?= htmlspecialchars($d['purpose']) ?></td><td class="small"><?= htmlspecialchars($d['issued_by_name']??'') ?></td><td class="small"><?= date('M d, Y',strtotime($d['date_issued'])) ?></td></tr>
    <?php endforeach; endif; ?></tbody></table></div></div></div>
    <?php endif; ?>

    <?php if ($category==='blotter'): ?>
    <div class="row g-2 mb-3"><?php foreach ($blotterBreakdown as $b): ?><div class="col-6 col-md-2"><div class="card text-center py-2"><div class="fw-bold fs-5"><?= $b['cnt'] ?></div><span class="badge bg-secondary"><?= $b['status'] ?></span></div></div><?php endforeach; ?></div>
    <div class="card"><div class="card-header py-2 small fw-semibold"><i class="bi bi-shield-exclamation me-2"></i>Blotter (<?= count($blotterRows) ?>)</div><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>#</th><th>Case No.</th><th>Complainant</th><th>Respondent</th><th>Incident Date</th><th>Location</th><th>Status</th></tr></thead><tbody>
    <?php if (empty($blotterRows)): ?><tr><td colspan="7" class="text-center text-muted py-4">No records.</td></tr>
    <?php else: foreach ($blotterRows as $i=>$b): ?><tr><td><?= $i+1 ?></td><td><code><?= htmlspecialchars($b['case_number']) ?></code></td><td><?= htmlspecialchars($b['complainant_name']??'N/A') ?></td><td><?= htmlspecialchars($b['respondent_name']??'N/A') ?></td><td class="small"><?= date('M d, Y',strtotime($b['incident_date'])) ?></td><td class="small"><?= htmlspecialchars($b['location']) ?></td><td><span class="badge bg-secondary"><?= $b['status'] ?></span></td></tr>
    <?php endforeach; endif; ?></tbody></table></div></div></div>
    <?php endif; ?>

    <?php if ($category==='households'): ?>
    <div class="card"><div class="card-header py-2 small fw-semibold"><i class="bi bi-house-door-fill me-2"></i>Households (<?= count($householdRows) ?>)</div><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>#</th><th>Code</th><th>Head of Family</th><th>Address</th><th>Members</th><th>Senior</th><th>PWD</th><th>Income</th><th>Date</th></tr></thead><tbody>
    <?php if (empty($householdRows)): ?><tr><td colspan="9" class="text-center text-muted py-4">No records.</td></tr>
    <?php else: foreach ($householdRows as $i=>$h): ?><tr><td><?= $i+1 ?></td><td><code><?= htmlspecialchars($h['household_code']) ?></code></td><td class="fw-semibold"><?= htmlspecialchars($h['head_name']??'N/A') ?></td><td class="small text-muted"><?= htmlspecialchars(($h['purok']?'Purok '.$h['purok'].', ':'').($h['addr_brgy']??'')) ?></td><td><span class="badge bg-secondary"><?= $h['total_members'] ?></span></td><td><?= $h['total_senior'] ?></td><td><?= $h['total_pwd'] ?></td><td class="small"><?= htmlspecialchars($h['income_range']??'—') ?></td><td class="small"><?= date('M d, Y',strtotime($h['created_at'])) ?></td></tr>
    <?php endforeach; endif; ?></tbody></table></div></div></div>
    <?php endif; ?>

    <?php if ($category==='officials'): ?>
    <div class="card"><div class="card-header py-2 small fw-semibold"><i class="bi bi-person-badge-fill me-2"></i>Officials (<?= count($officialRows) ?>)</div><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>#</th><th>Full Name</th><th>Position</th><th>Contact</th><th>Term Start</th><th>Term End</th></tr></thead><tbody>
    <?php if (empty($officialRows)): ?><tr><td colspan="6" class="text-center text-muted py-4">No records.</td></tr>
    <?php else: foreach ($officialRows as $i=>$o): ?><tr><td><?= $i+1 ?></td><td class="fw-semibold"><?= htmlspecialchars($o['full_name']??'') ?></td><td><span class="badge bg-secondary"><?= htmlspecialchars($o['position']??'') ?></span></td><td class="small"><?= htmlspecialchars($o['contact']??'—') ?></td><td class="small"><?= $o['term_start']?date('M d, Y',strtotime($o['term_start'])):'—' ?></td><td class="small"><?= $o['term_end']?date('M d, Y',strtotime($o['term_end'])):'—' ?></td></tr>
    <?php endforeach; endif; ?></tbody></table></div></div></div>
    <?php endif; ?>

    <?php if ($category==='audit'): ?>
    <div class="card"><div class="card-header py-2 small fw-semibold"><i class="bi bi-shield-check me-2"></i>Audit Log (<?= count($auditRows) ?>)</div><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>ID</th><th>Action</th><th>Module</th><th>Description</th><th>IP</th><th>Date &amp; Time</th></tr></thead><tbody>
    <?php if (empty($auditRows)): ?><tr><td colspan="6" class="text-center text-muted py-4">No records.</td></tr>
    <?php else: foreach ($auditRows as $a): ?><tr><td><code class="small">#<?= $a['id'] ?></code></td><td><span class="badge bg-secondary"><?= htmlspecialchars($a['action']) ?></span></td><td><span class="badge bg-light text-dark border"><?= htmlspecialchars($a['module']??'—') ?></span></td><td class="small text-muted"><?= htmlspecialchars($a['description']??'—') ?></td><td><code class="small"><?= htmlspecialchars($a['ip_address']??'—') ?></code></td><td class="small"><?= date('M d, Y h:i A',strtotime($a['created_at'])) ?></td></tr>
    <?php endforeach; endif; ?></tbody></table></div></div></div>
    <?php endif; ?>

    <?php if ($category==='activity'): ?>
    <div class="card"><div class="card-header py-2 small fw-semibold"><i class="bi bi-clock-history me-2"></i>Activity (<?= count($activityRows) ?>)</div><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>#</th><th>Action</th><th>Module</th><th>Description</th><th>IP</th><th>Date</th></tr></thead><tbody>
    <?php if (empty($activityRows)): ?><tr><td colspan="6" class="text-center text-muted py-4">No records.</td></tr>
    <?php else: foreach ($activityRows as $i=>$a): ?><tr><td><?= $i+1 ?></td><td><span class="badge bg-secondary"><?= htmlspecialchars($a['action']) ?></span></td><td><span class="badge bg-light text-dark"><?= htmlspecialchars($a['module']??'') ?></span></td><td class="small text-muted"><?= htmlspecialchars($a['description']??'') ?></td><td><code class="small"><?= htmlspecialchars($a['ip_address']??'') ?></code></td><td class="small"><?= date('M d, Y h:i A',strtotime($a['created_at'])) ?></td></tr>
    <?php endforeach; endif; ?></tbody></table></div></div></div>
    <?php endif; ?>

    <?php if ($category==='transfers'): ?>
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-3"><div class="card text-center py-2" style="border-left:4px solid #16a34a;"><div class="fw-bold fs-5 text-success"><?= number_format($totalTransferredIn) ?></div><div class="small text-muted">Transferred IN</div></div></div>
        <div class="col-6 col-md-3"><div class="card text-center py-2" style="border-left:4px solid #dc2626;"><div class="fw-bold fs-5 text-danger"><?= number_format($totalTransferredOut) ?></div><div class="small text-muted">Transferred OUT</div></div></div>
        <div class="col-6 col-md-3"><div class="card text-center py-2" style="border-left:4px solid #d97706;"><div class="fw-bold fs-5 text-warning"><?= number_format($totalTransferredOutside) ?></div><div class="small text-muted">Outside LGU</div></div></div>
        <div class="col-6 col-md-3"><div class="card text-center py-2" style="border-left:4px solid #1a4f7a;"><div class="fw-bold fs-5" style="color:#1a4f7a;"><?= number_format($totalTransferredIn-$totalTransferredOut) ?></div><div class="small text-muted">Net Movement</div></div></div>
    </div>
    <div class="card mb-3"><div class="card-header d-flex gap-2 py-2 small fw-semibold" style="background:#e8faf0;border-bottom:2px solid #16a34a;"><span class="badge bg-success">IN</span> Transferred INTO This Barangay (<?= count($transferInRows??[]) ?>)</div><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>#</th><th>Full Name</th><th>Gender</th><th>Age</th><th>From</th><th>Reason</th><th>Date</th></tr></thead><tbody>
    <?php if (empty($transferInRows)): ?><tr><td colspan="7" class="text-center text-muted py-4">No records.</td></tr>
    <?php else: foreach ($transferInRows as $i=>$tr): ?><tr><td><?= $i+1 ?></td><td class="fw-semibold"><?= htmlspecialchars($tr['last_name'].', '.$tr['first_name'].($tr['middle_name']?' '.$tr['middle_name'][0].'.':'')) ?></td><td><span class="badge bg-secondary"><?= $tr['gender'] ?></span></td><td><?= $tr['age'] ?></td><td class="small"><?= htmlspecialchars($tr['transfer_from_barangay']?:'—') ?></td><td class="small text-muted"><?= htmlspecialchars($tr['transfer_reason']?:'—') ?></td><td class="small"><?= date('M d, Y',strtotime($tr['created_at'])) ?></td></tr>
    <?php endforeach; endif; ?></tbody></table></div></div></div>
    <div class="card"><div class="card-header d-flex gap-2 py-2 small fw-semibold" style="background:#fff5f5;border-bottom:2px solid #dc2626;"><span class="badge bg-danger">OUT</span> Transferred OUT of This Barangay (<?= count($transferOutRows??[]) ?>)</div><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>#</th><th>Full Name</th><th>Gender</th><th>Age</th><th>To</th><th>Outside LGU</th><th>Reason</th><th>Date</th></tr></thead><tbody>
    <?php if (empty($transferOutRows)): ?><tr><td colspan="8" class="text-center text-muted py-4">No records.</td></tr>
    <?php else: foreach ($transferOutRows as $i=>$tr): ?><tr><td><?= $i+1 ?></td><td class="fw-semibold"><?= htmlspecialchars($tr['last_name'].', '.$tr['first_name'].($tr['middle_name']?' '.$tr['middle_name'][0].'.':'')) ?></td><td><span class="badge bg-secondary"><?= $tr['gender'] ?></span></td><td><?= $tr['age'] ?></td><td class="small"><?= htmlspecialchars($tr['transfer_to_barangay']?:'—') ?></td><td><?= $tr['transfer_outside']?'<span class="badge bg-warning text-dark">Yes</span>':'<span class="badge bg-light text-dark">No</span>' ?></td><td class="small text-muted"><?= htmlspecialchars($tr['transfer_reason']?:'—') ?></td><td class="small"><?= date('M d, Y',strtotime($tr['created_at'])) ?></td></tr>
    <?php endforeach; endif; ?></tbody></table></div></div></div>
    <?php endif; ?>

    </div><!-- /data-content -->
</div><!-- /data-reports-section -->


<!-- ════════════════════════════════════════════════════════
     SECTION 2 — MANUAL REPORTS (notices-style shell)
     ════════════════════════════════════════════════════════ -->
<div class="rpt-section-label">
    <i class="bi bi-pencil-square"></i> Manual Reports
    <span class="badge ms-1" style="background:#e8f0fe;color:#1a4f7a;font-size:.65rem;"><?= count($sentList) ?> sent</span>
</div>

<div class="rpt-shell">

    <!-- ── LEFT: list ── -->
    <div class="rpt-list">
        <div class="rpt-list-head">
            <div>
                <span class="small fw-semibold text-muted" style="font-size:.71rem;text-transform:uppercase;letter-spacing:.05em;">Reports</span>
                <span class="badge bg-secondary ms-1" style="font-size:.63rem;"><?= count($sentList) ?></span>
            </div>
            <button class="btn btn-primary btn-sm" style="font-size:.73rem;padding:3px 10px;" id="btnNewReport">
                <i class="bi bi-plus-lg me-1"></i>New
            </button>
        </div>

        <div class="rpt-list-body" id="rptListBody">
            <?php foreach ($sentList as $mr):
                $tc = $typeMap[$mr['report_type']] ?? $typeMap['Other'];
                $dj = htmlspecialchars(json_encode([
                    'id'=>$mr['id'],'title'=>$mr['report_title'],'body'=>$mr['report_body'],
                    'type'=>$mr['report_type'],'author'=>$mr['author_name'],
                    'sentAt'=>$mr['sent_at']?date('M d, Y h:i A',strtotime($mr['sent_at'])):'',
                    'date'=>date('M d, Y',strtotime($mr['created_at'])),
                    'seenAt'=>!empty($mr['seen_at'])?date('M d, Y h:i A',strtotime($mr['seen_at'])):'',
                    'attachments'=>$mrAttachments[(int)$mr['id']] ?? [],
                ],JSON_UNESCAPED_UNICODE),ENT_QUOTES);
            ?>
            <div class="rpt-item" data-rpt="<?= $dj ?>" onclick="openReport(this)">
                <div class="rpt-item-icon" style="background:<?= $tc['bg'] ?>;"><?= $tc['icon'] ?></div>
                <div class="rpt-item-body">
                    <div class="d-flex align-items-center gap-1">
                        <div class="rpt-item-title flex-fill"><?= htmlspecialchars($mr['report_title']) ?></div>
                        <i class="bi bi-send-check-fill text-success" style="font-size:.65rem;flex-shrink:0;" title="Sent to LGU"></i>
                        <?php if (!empty($mr['seen_at'])): ?>
                        <i class="bi bi-eye-fill text-info" style="font-size:.65rem;flex-shrink:0;" title="Seen by LGU on <?= date('M d, Y h:i A',strtotime($mr['seen_at'])) ?>"></i>
                        <?php endif; ?>
                    </div>
                    <div class="rpt-item-preview"><?= htmlspecialchars(mb_substr(strip_tags($mr['report_body']),0,65)) ?>…</div>
                    <div class="rpt-item-meta">
                        <span style="background:<?= $tc['bg'] ?>;color:<?= $tc['color'] ?>;padding:1px 6px;border-radius:20px;font-size:.6rem;font-weight:600;"><?= htmlspecialchars($mr['report_type']) ?></span>
                        &nbsp;·&nbsp;<?= $mr['sent_at']?date('M d, Y',strtotime($mr['sent_at'])):date('M d, Y',strtotime($mr['created_at'])) ?>
                        <?php if (!empty($mr['seen_at'])): ?>
                        &nbsp;<span style="color:#0369a1;font-size:.6rem;font-weight:600;"><i class="bi bi-eye-fill me-1"></i>Seen by LGU</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>

            <?php if (!count($sentList)): ?>
            <div class="text-center text-muted small" style="padding:32px 16px;">
                <i class="bi bi-send" style="font-size:2rem;opacity:.25;display:block;margin-bottom:8px;"></i>
                No reports sent yet.
            </div>
            <?php endif; ?>

            <?php if (count($draftList)): ?>
            <div style="padding:6px 14px;border-top:1px solid #e2eaf2;background:#fff;font-size:.68rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;">Drafts</div>
            <?php foreach ($draftList as $mr):
                $dj = htmlspecialchars(json_encode([
                    'id'=>$mr['id'],'title'=>$mr['report_title'],'body'=>$mr['report_body'],
                    'type'=>$mr['report_type'],'author'=>$mr['author_name'],'sentAt'=>'',
                    'date'=>date('M d, Y',strtotime($mr['created_at'])),
                    'attachments'=>$mrAttachments[(int)$mr['id']] ?? [],
                ],JSON_UNESCAPED_UNICODE),ENT_QUOTES);
            ?>
            <div class="rpt-item" data-rpt="<?= $dj ?>" onclick="openDraft(this)">
                <div class="rpt-item-icon" style="background:#f1f5f9;">📝</div>
                <div class="rpt-item-body">
                    <div class="rpt-item-title"><?= htmlspecialchars($mr['report_title']) ?></div>
                    <div class="rpt-item-preview"><?= htmlspecialchars(mb_substr(strip_tags($mr['report_body']),0,65)) ?>…</div>
                    <div class="rpt-item-meta">
                        <span style="background:#f1f5f9;color:#64748b;padding:1px 6px;border-radius:20px;font-size:.6rem;font-weight:600;">Draft</span>
                        &nbsp;·&nbsp;<?= date('M d, Y',strtotime($mr['created_at'])) ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div><!-- /rpt-list-body -->
    </div><!-- /rpt-list -->

    <!-- ── RIGHT: composer + detail ── -->
    <div class="rpt-right" id="rptRight">

        <!-- Empty state -->
        <div class="rpt-pane show" id="paneEmpty">
            <div class="rpt-empty">
                <i class="bi bi-file-earmark-ruled" style="font-size:3rem;opacity:.2;"></i>
                <span class="small fw-semibold">Select a report or create a new one</span>
                <button class="btn btn-primary btn-sm mt-1" id="btnNewReport2"><i class="bi bi-pencil-square me-1"></i>Compose Report</button>
            </div>
        </div>

        <!-- COMPOSER -->
        <div class="rpt-pane" id="paneCompose">
            <div class="rpt-header">
                <div class="d-flex align-items-start gap-3">
                    <div id="cmpIcon" style="width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.4rem;flex-shrink:0;background:#e8f0fe;">📋</div>
                    <div class="flex-fill" style="min-width:0;">
                        <input type="text" id="cmpTitle" class="form-control form-control-sm fw-bold mb-1"
                               placeholder="Report title…"
                               style="font-size:1rem;color:#1a2e3f;border:none;border-bottom:2px solid #e2eaf2;border-radius:0;padding:0 0 4px 0;background:transparent;box-shadow:none;">
                        <div class="d-flex flex-wrap align-items-center gap-2 mt-1">
                            <select id="cmpType" class="form-select form-select-sm" style="width:auto;font-size:.75rem;padding:2px 8px;border-radius:20px;" onchange="updateCmpIcon()">
                                <option value="General">📋 General</option>
                                <option value="Incident">🚨 Incident</option>
                                <option value="Health">🏥 Health</option>
                                <option value="Financial">💰 Financial</option>
                                <option value="Infrastructure">🏗️ Infrastructure</option>
                                <option value="Census">📊 Census</option>
                                <option value="Other">📄 Other</option>
                            </select>
                            <span class="text-muted small"><i class="bi bi-person me-1"></i><?= $authorName ?></span>
                            <span class="text-muted small"><i class="bi bi-clock me-1"></i><?= date('M d, Y') ?></span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="rpt-body">
                <textarea id="cmpBody" class="rpt-content"
                          placeholder="Write your report here…&#10;&#10;• Summary of events or activities&#10;• Statistical observations&#10;• Recommendations&#10;• Any information relevant to the LGU"></textarea>
                <!-- attach toolbar -->
                <div class="att-toolbar mt-2">
                    <label for="cmpFileInput">
                        <i class="bi bi-paperclip"></i> Attach File
                    </label>
                    <input type="file" id="cmpFileInput" multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.txt" style="display:none;" onchange="handleFileSelect(this)">
                    <span class="att-hint">Images, PDF, Word, Excel, TXT · max 10 MB · up to 5 files</span>
                </div>
                <!-- pending-upload chips (before save/send) -->
                <div class="att-bar mt-1" id="cmpPendingChips"></div>
                <!-- already-saved attachment chips (for drafts being edited) -->
                <div class="att-bar mt-1" id="cmpSavedChips"></div>
            </div>
            <div class="rpt-footer">
                <input type="hidden" id="cmpEditId" value="">
                <button class="btn btn-outline-secondary btn-sm" onclick="saveDraft()"><i class="bi bi-floppy me-1"></i>Save Draft</button>
                <span class="small ms-2" id="cmpStatus"></span>
                <button class="btn btn-outline-secondary btn-sm ms-auto" onclick="closeCompose()">Cancel</button>
                <button class="btn btn-primary btn-sm fw-semibold" onclick="sendReport()"><i class="bi bi-send me-1"></i>Send to LGU</button>
            </div>
        </div><!-- /paneCompose -->

        <!-- DETAIL (read-only) -->
        <div class="rpt-pane" id="paneDetail">
            <div class="rpt-header">
                <div class="d-flex align-items-start gap-3">
                    <div id="dvIcon" style="width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.4rem;flex-shrink:0;background:#e8f0fe;">📋</div>
                    <div class="flex-fill" style="min-width:0;">
                        <h5 class="fw-bold mb-1" id="dvTitle" style="font-size:1rem;color:#1a2e3f;"></h5>
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <span id="dvBadge"></span>
                            <span class="text-muted small"><i class="bi bi-person me-1"></i><span id="dvAuthor"></span></span>
                            <span class="text-muted small"><i class="bi bi-clock me-1"></i><span id="dvDate"></span></span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="rpt-body">
                <div class="rpt-content" id="dvBody"></div>
                <!-- attachments row (read-only) -->
                <div id="dvAttachments" class="att-bar mt-3" style="padding-left:0;padding-right:0;"></div>
            </div>
            <div class="rpt-footer">
                <span class="text-muted small"><i class="bi bi-send-check-fill text-success me-1"></i>Sent to LGU</span>
                <span id="dvSeenStatus" class="ms-3 small"></span>
                <span class="ms-auto text-muted small" id="dvMeta"></span>
            </div>
        </div><!-- /paneDetail -->

    </div><!-- /rpt-right -->
</div><!-- /rpt-shell -->

</div><!-- /main-content -->


<!-- ════ MODALS ════ -->

<!-- Submit data summary to LGU -->
<div class="modal fade" id="modalSubmitLGU" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="submit_summary">
            <input type="hidden" name="period" id="inpPeriod" value="monthly">
            <div class="modal-content" style="border-radius:16px;overflow:hidden;">
                <div class="d-flex" style="min-height:280px;">
                    <div class="d-flex flex-column align-items:center justify-content:center px-4 py-5 text-white"
                         style="background:linear-gradient(160deg,#1a4f7a,#3a7fa8);min-width:190px;width:190px;align-items:center;justify-content:center;">
                        <div style="width:68px;height:68px;background:rgba(255,255,255,.15);border-radius:50%;display:flex;align-items:center;justify-content:center;border:3px solid rgba(255,255,255,.3);">
                            <i class="bi bi-send-fill text-white" style="font-size:2rem;"></i>
                        </div>
                        <h5 class="fw-bold mt-3 mb-1 text-white text-center">Send to LGU</h5>
                        <p class="text-white-50 small text-center mb-0">Data Summary</p>
                    </div>
                    <div class="flex-fill p-4 d-flex flex-direction-column justify-content-between" style="flex-direction:column;">
                        <div>
                            <p class="fw-semibold mb-3" style="color:#1a4f7a;">Select report period:</p>
                            <div class="d-flex flex-column gap-2 mb-3">
                                <label class="form-check p-3 rounded border" style="cursor:pointer;">
                                    <input class="form-check-input" type="radio" name="period_ui" value="daily" onchange="document.getElementById('inpPeriod').value='daily'">
                                    <span class="fw-semibold ms-1"><i class="bi bi-calendar-day me-1 text-primary"></i>Daily — <?= date('M d, Y') ?></span>
                                </label>
                                <label class="form-check p-3 rounded border" style="cursor:pointer;">
                                    <input class="form-check-input" type="radio" name="period_ui" value="monthly" checked onchange="document.getElementById('inpPeriod').value='monthly'">
                                    <span class="fw-semibold ms-1"><i class="bi bi-calendar-month me-1 text-success"></i>Monthly — <?= date('F Y') ?></span>
                                </label>
                                <label class="form-check p-3 rounded border" style="cursor:pointer;">
                                    <input class="form-check-input" type="radio" name="period_ui" value="yearly" onchange="document.getElementById('inpPeriod').value='yearly'">
                                    <span class="fw-semibold ms-1"><i class="bi bi-calendar-range me-1 text-warning"></i>Yearly — <?= date('Y') ?></span>
                                </label>
                            </div>
                            <div class="alert alert-info py-2 small mb-0"><i class="bi bi-info-circle me-1"></i>Compiles residents, households, documents, blotter, and demographic data.</div>
                        </div>
                        <div class="d-flex gap-2 mt-3">
                            <button type="button" class="btn btn-outline-secondary flex-fill" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary flex-fill fw-semibold"><i class="bi bi-send me-1"></i>Confirm &amp; Submit</button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Confirm send manual report -->
<div class="modal fade" id="modalSendReport" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;overflow:hidden;">
            <div class="d-flex" style="min-height:180px;">
                <div class="d-flex flex-column align-items-center justify-content-center px-4 py-4"
                     style="background:linear-gradient(160deg,#1a4f7a,#3a7fa8);min-width:130px;width:130px;">
                    <i class="bi bi-send-fill text-white" style="font-size:2rem;"></i>
                    <p class="text-white fw-bold small text-center mt-2 mb-0">Send</p>
                </div>
                <div class="flex-fill p-4 d-flex flex-column justify-content-between">
                    <div>
                        <p class="fw-semibold mb-1" style="color:#1a4f7a;">Send this report to the LGU?</p>
                        <p class="text-muted small mb-2" id="modalSendTitle"></p>
                        <div class="alert alert-warning py-2 small"><i class="bi bi-exclamation-triangle me-1"></i>Once sent, the report cannot be edited.</div>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-outline-secondary flex-fill btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button class="btn btn-primary flex-fill btn-sm fw-semibold" id="btnConfirmSend"><i class="bi bi-send me-1"></i>Yes, Send</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
function toggleSidebar(){ document.getElementById('sidebar').classList.toggle('show'); }

const CSRF  = document.querySelector('meta[name="csrf-token"]')?.content || '';
const BURL  = '<?= BASE_URL ?>';

/* ── type maps ── */
const TI = {General:'📋',Incident:'🚨',Health:'🏥',Financial:'💰',Infrastructure:'🏗️',Census:'📊',Other:'📄'};
const TB = {General:'#e8f0fe',Incident:'#fee2e2',Health:'#d1fae5',Financial:'#fef3c7',Infrastructure:'#ede9fe',Census:'#e0f2fe',Other:'#f3f4f6'};
const TC = {General:'#1a4f7a',Incident:'#991b1b',Health:'#065f46',Financial:'#854d0e',Infrastructure:'#4c1d95',Census:'#0f4c75',Other:'#374151'};

/* ── panel switcher ── */
function showPane(name){
    ['paneEmpty','paneCompose','paneDetail'].forEach(id=>{
        const el=document.getElementById(id);
        if(el) el.classList.remove('show');
    });
    const t=document.getElementById(name);
    if(t) t.classList.add('show');
}

/* ── New button ── */
function startCompose(title,body,type,editId,existingAtts){
    document.getElementById('cmpTitle').value  = title||'';
    document.getElementById('cmpBody').value   = body||'';
    document.getElementById('cmpEditId').value = editId||'';
    document.getElementById('cmpStatus').textContent='';
    document.getElementById('cmpPendingChips').innerHTML='';
    document.getElementById('cmpFileInput').value='';
    pendingFiles = [];
    const sel=document.getElementById('cmpType');
    sel.value = type||'General';
    updateCmpIcon();
    document.querySelectorAll('.rpt-item').forEach(e=>e.classList.remove('active'));
    // Render saved attachments for existing drafts
    renderSavedChips(existingAtts||[]);
    showPane('paneCompose');
    setTimeout(()=>document.getElementById('cmpTitle').focus(),50);
}

document.getElementById('btnNewReport').addEventListener('click',()=>startCompose());
document.getElementById('btnNewReport2').addEventListener('click',()=>startCompose());

function closeCompose(){
    document.querySelectorAll('.rpt-item').forEach(e=>e.classList.remove('active'));
    pendingFiles = [];
    document.getElementById('cmpPendingChips').innerHTML='';
    document.getElementById('cmpSavedChips').innerHTML='';
    document.getElementById('cmpFileInput').value='';
    showPane('paneEmpty');
}

function updateCmpIcon(){
    const t=document.getElementById('cmpType').value;
    const el=document.getElementById('cmpIcon');
    el.textContent=TI[t]||'📄';
    el.style.background=TB[t]||'#f1f5f9';
}

/* ── open sent report ── */
function openReport(el){
    document.querySelectorAll('.rpt-item').forEach(e=>e.classList.remove('active'));
    el.classList.add('active');
    const r=JSON.parse(el.dataset.rpt);
    document.getElementById('dvIcon').textContent=TI[r.type]||'📄';
    document.getElementById('dvIcon').style.background=TB[r.type]||'#f1f5f9';
    document.getElementById('dvTitle').textContent=r.title;
    document.getElementById('dvAuthor').textContent=r.author;
    document.getElementById('dvDate').textContent=r.sentAt||r.date;
    document.getElementById('dvBody').textContent=r.body;
    document.getElementById('dvMeta').textContent='Submitted: '+(r.sentAt||r.date);
    document.getElementById('dvBadge').innerHTML=`<span class="badge rounded-pill" style="font-size:.7rem;padding:3px 10px;background:${TB[r.type]||'#f1f5f9'};color:${TC[r.type]||'#374151'};">${TI[r.type]||'📄'} ${r.type}</span>`;
    const seenEl=document.getElementById('dvSeenStatus');
    if(seenEl){
        seenEl.innerHTML=r.seenAt
            ?`<i class="bi bi-eye-fill text-info me-1"></i><span style="color:#0369a1;">Seen by LGU: ${r.seenAt}</span>`
            :'<span style="color:#94a3b8;font-size:.78rem;"><i class="bi bi-eye me-1"></i>Not yet seen by LGU</span>';
    }
    renderDetailAttachments(r.attachments||[]);
    showPane('paneDetail');
}

/* ── open draft ── */
function openDraft(el){
    document.querySelectorAll('.rpt-item').forEach(e=>e.classList.remove('active'));
    el.classList.add('active');
    const r=JSON.parse(el.dataset.rpt);
    startCompose(r.title,r.body,r.type,r.id,r.attachments||[]);
}

/* ── save draft ── */
async function saveDraft(){
    const title=document.getElementById('cmpTitle').value.trim();
    const body =document.getElementById('cmpBody').value.trim();
    const type =document.getElementById('cmpType').value;
    const eid  =document.getElementById('cmpEditId').value;
    if(!title){document.getElementById('cmpTitle').focus();setStatus('Enter a report title.','danger');return;}
    if(!body) {document.getElementById('cmpBody').focus(); setStatus('Write some content first.','danger');return;}
    setStatus('Saving…','secondary');
    const fd=new FormData();
    fd.append('action','save_draft');fd.append('csrf_token',CSRF);
    fd.append('report_title',title);fd.append('report_body',body);fd.append('report_type',type);
    if(eid) fd.append('edit_id',eid);
    pendingFiles.forEach(f=>fd.append('attachments[]',f));
    const d=await fetch(BURL+'/ajax/manual_report.php',{method:'POST',body:fd}).then(r=>r.json());
    if(d.ok){
        document.getElementById('cmpEditId').value=d.id;
        pendingFiles=[];
        document.getElementById('cmpPendingChips').innerHTML='';
        document.getElementById('cmpFileInput').value='';
        renderSavedChips(d.attachments||[]);
        if(d.errors && d.errors.length) setStatus('⚠ '+d.errors.join(' | '),'warning');
        else setStatus('✓ Draft saved — '+new Date().toLocaleTimeString(),'success');
        refreshListItem(d.id,title,body,type,'draft',null,d.attachments||[]);
    } else {
        setStatus(d.error||'Error saving.','danger');
    }
}

/* ── send report ── */
function sendReport(){
    const title=document.getElementById('cmpTitle').value.trim();
    const body =document.getElementById('cmpBody').value.trim();
    if(!title){setStatus('Enter a report title.','danger');return;}
    if(!body) {setStatus('Write some content first.','danger');return;}
    document.getElementById('modalSendTitle').textContent='"'+title+'"';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalSendReport')).show();
}

document.getElementById('btnConfirmSend').addEventListener('click', async()=>{
    const title=document.getElementById('cmpTitle').value.trim();
    const body =document.getElementById('cmpBody').value.trim();
    const type =document.getElementById('cmpType').value;
    const eid  =document.getElementById('cmpEditId').value;
    const btn  =document.getElementById('btnConfirmSend');
    btn.disabled=true;
    btn.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span>Sending…';
    const fd=new FormData();
    fd.append('action','send_report');fd.append('csrf_token',CSRF);
    fd.append('report_title',title);fd.append('report_body',body);fd.append('report_type',type);
    if(eid) fd.append('edit_id',eid);
    pendingFiles.forEach(f=>fd.append('attachments[]',f));
    const d=await fetch(BURL+'/ajax/manual_report.php',{method:'POST',body:fd}).then(r=>r.json());
    btn.disabled=false;
    btn.innerHTML='<i class="bi bi-send me-1"></i>Yes, Send';
    bootstrap.Modal.getInstance(document.getElementById('modalSendReport')).hide();
    if(d.ok){
        pendingFiles=[];
        document.getElementById('cmpPendingChips').innerHTML='';
        document.getElementById('cmpFileInput').value='';
        refreshListItem(d.id,title,body,type,'sent',d.sent_at,d.attachments||[]);
        setStatus('','');
        if(d.errors && d.errors.length) toast('Report sent but some files failed: '+d.errors.join(', '),'warning');
        else toast('Report sent to LGU successfully!','success');
        document.getElementById('dvIcon').textContent=TI[type]||'📄';
        document.getElementById('dvIcon').style.background=TB[type]||'#f1f5f9';
        document.getElementById('dvTitle').textContent=title;
        document.getElementById('dvAuthor').textContent='<?= addslashes($authorName) ?>';
        document.getElementById('dvDate').textContent=d.sent_at;
        document.getElementById('dvBody').textContent=body;
        document.getElementById('dvMeta').textContent='Submitted: '+d.sent_at;
        document.getElementById('dvBadge').innerHTML=`<span class="badge rounded-pill" style="font-size:.7rem;padding:3px 10px;background:${TB[type]||'#f1f5f9'};color:${TC[type]||'#374151'};">${TI[type]||'📄'} ${type}</span>`;
        renderDetailAttachments(d.attachments||[]);
        const seenEl=document.getElementById('dvSeenStatus');
        if(seenEl) seenEl.innerHTML='<span style="color:#94a3b8;font-size:.78rem;"><i class="bi bi-eye me-1"></i>Not yet seen by LGU</span>';
        showPane('paneDetail');
    } else {
        toast(d.error||'Error sending report.','danger');
    }
});

function setStatus(msg,type){
    const el=document.getElementById('cmpStatus');
    el.textContent=msg; el.className='small ms-2 text-'+type;
}

function refreshListItem(id,title,body,type,status,sentAt,atts){
    document.querySelectorAll('.rpt-item').forEach(el=>{
        try{ const d=JSON.parse(el.dataset.rpt); if(d.id==id) el.remove(); }catch(e){}
    });
    const icon=TI[type]||'📄', bg=TB[type]||'#f1f5f9', color=TC[type]||'#374151';
    const dateStr=sentAt||new Date().toLocaleDateString('en-PH',{month:'short',day:'2-digit',year:'numeric'});
    const rj=JSON.stringify({id,title,body,type,author:'<?= addslashes($authorName) ?>',sentAt:sentAt||'',date:dateStr,attachments:atts||[]});
    const el=document.createElement('div');
    el.className='rpt-item active';
    el.dataset.rpt=rj;
    const attBadge=(atts&&atts.length)?`<i class="bi bi-paperclip text-secondary" style="font-size:.65rem;" title="${atts.length} file(s)"></i>`:'';
    if(status==='sent'){
        el.onclick=function(){openReport(this);};
        el.innerHTML=`<div class="rpt-item-icon" style="background:${bg};">${icon}</div>
        <div class="rpt-item-body">
          <div class="d-flex align-items-center gap-1"><div class="rpt-item-title flex-fill">${esc(title)}</div>${attBadge}<i class="bi bi-send-check-fill text-success" style="font-size:.65rem;"></i></div>
          <div class="rpt-item-preview">${esc(body.substring(0,65))}…</div>
          <div class="rpt-item-meta"><span style="background:${bg};color:${color};padding:1px 6px;border-radius:20px;font-size:.6rem;font-weight:600;">${esc(type)}</span> · ${esc(dateStr)}</div>
        </div>`;
        document.getElementById('rptListBody').prepend(el);
    } else {
        el.onclick=function(){openDraft(this);};
        el.innerHTML=`<div class="rpt-item-icon" style="background:#f1f5f9;">📝</div>
        <div class="rpt-item-body">
          <div class="d-flex align-items-center gap-1"><div class="rpt-item-title flex-fill">${esc(title)}</div>${attBadge}</div>
          <div class="rpt-item-preview">${esc(body.substring(0,65))}…</div>
          <div class="rpt-item-meta"><span style="background:#f1f5f9;color:#64748b;padding:1px 6px;border-radius:20px;font-size:.6rem;font-weight:600;">Draft</span> · ${esc(dateStr)}</div>
        </div>`;
        document.getElementById('rptListBody').append(el);
    }
}

/* ══ ATTACHMENT HELPERS ════════════════════════════════════ */

let pendingFiles = [];   // File objects staged for upload

/* mime → icon */
function attIcon(mime){
    if(mime.startsWith('image/'))        return 'bi-file-earmark-image-fill';
    if(mime==='application/pdf')         return 'bi-file-earmark-pdf-fill';
    if(mime.includes('word'))            return 'bi-file-earmark-word-fill';
    if(mime.includes('excel')||mime.includes('spreadsheet')) return 'bi-file-earmark-excel-fill';
    return 'bi-file-earmark-fill';
}

function fmtSize(bytes){
    if(bytes<1024) return bytes+'B';
    if(bytes<1048576) return (bytes/1024).toFixed(0)+'KB';
    return (bytes/1048576).toFixed(1)+'MB';
}

/* Handle file input change — stage files locally */
function handleFileSelect(input){
    const MAX_TOTAL = 5;
    const MAX_SIZE  = 10*1024*1024;
    const ALLOWED   = ['image/jpeg','image/png','image/gif','image/webp',
                       'application/pdf','application/msword',
                       'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                       'application/vnd.ms-excel',
                       'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                       'text/plain'];
    const savedCount = document.getElementById('cmpSavedChips').querySelectorAll('.att-chip').length;
    const files = Array.from(input.files);

    for(const f of files){
        if(pendingFiles.length + savedCount >= MAX_TOTAL){
            toast(`Maximum ${MAX_TOTAL} attachments allowed.`,'warning'); break;
        }
        if(f.size > MAX_SIZE){
            toast(`"${esc(f.name)}" exceeds 10 MB limit.`,'warning'); continue;
        }
        if(!ALLOWED.includes(f.type) && f.type !== ''){
            toast(`"${esc(f.name)}" file type not allowed.`,'warning'); continue;
        }
        pendingFiles.push(f);
    }
    input.value='';
    renderPendingChips();
}

/* Render staged (not-yet-uploaded) chips */
function renderPendingChips(){
    const container=document.getElementById('cmpPendingChips');
    container.innerHTML='';
    pendingFiles.forEach((f,idx)=>{
        const chip=document.createElement('span');
        chip.className='att-chip';
        chip.innerHTML=`<i class="bi ${attIcon(f.type)}"></i>
            <span class="att-name" title="${esc(f.name)}">${esc(f.name)}</span>
            <span style="color:#94a3b8;font-size:.65rem;">(${fmtSize(f.size)})</span>
            <button class="att-del" type="button" title="Remove" onclick="removePending(${idx})"><i class="bi bi-x-lg"></i></button>`;
        container.appendChild(chip);
    });
}

function removePending(idx){
    pendingFiles.splice(idx,1);
    renderPendingChips();
}

/* Render already-saved chips for a draft being edited (with delete capability) */
function renderSavedChips(atts){
    const container=document.getElementById('cmpSavedChips');
    container.innerHTML='';
    (atts||[]).forEach(att=>{
        const chip=document.createElement('span');
        chip.className='att-chip';
        chip.dataset.attId=att.id;
        chip.innerHTML=`<i class="bi ${attIcon(att.mime_type||'')}"></i>
            <span class="att-name" title="${esc(att.original_name)}">${esc(att.original_name)}</span>
            <span style="color:#94a3b8;font-size:.65rem;">(${fmtSize(att.file_size||0)})</span>
            <button class="att-del" type="button" title="Remove" onclick="deleteSavedAtt(${att.id},this.closest('.att-chip'))"><i class="bi bi-x-lg"></i></button>`;
        container.appendChild(chip);
    });
}

/* Delete an already-uploaded attachment from the draft */
async function deleteSavedAtt(attId, chipEl){
    const fd=new FormData();
    fd.append('action','delete_attachment');
    fd.append('csrf_token',CSRF);
    fd.append('attachment_id',attId);
    chipEl.style.opacity='.4';
    const d=await fetch(BURL+'/ajax/manual_report.php',{method:'POST',body:fd}).then(r=>r.json());
    if(d.ok){
        chipEl.remove();
        // Update the data-rpt on the currently active list item
        const active=document.querySelector('.rpt-item.active');
        if(active){ try{ const rpt=JSON.parse(active.dataset.rpt); rpt.attachments=(d.attachments||[]); active.dataset.rpt=JSON.stringify(rpt); }catch(e){} }
    } else {
        chipEl.style.opacity='1';
        toast(d.error||'Could not delete attachment.','danger');
    }
}

/* Render read-only attachment chips in the detail pane */
function renderDetailAttachments(atts){
    const container=document.getElementById('dvAttachments');
    if(!container) return;
    container.innerHTML='';
    if(!atts||!atts.length) return;
    // label
    const lbl=document.createElement('div');
    lbl.style.cssText='width:100%;font-size:.68rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;';
    lbl.innerHTML=`<i class="bi bi-paperclip me-1"></i>Attachments (${atts.length})`;
    container.appendChild(lbl);
    atts.forEach(att=>{
        const a=document.createElement('a');
        a.className='att-chip-ro';
        a.href=BURL+'/uploads/'+esc(att.file_path);
        a.target='_blank';
        a.rel='noopener';
        a.innerHTML=`<i class="bi ${attIcon(att.mime_type||'')}"></i>
            <span style="max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="${esc(att.original_name)}">${esc(att.original_name)}</span>
            <span style="color:#94a3b8;font-size:.65rem;flex-shrink:0;">${fmtSize(att.file_size||0)}</span>`;
        container.appendChild(a);
    });
}

/* ══ END ATTACHMENT HELPERS ══════════════════════════════════ */

function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function toast(msg,type){
    const d=document.createElement('div');
    d.style.cssText='position:fixed;bottom:24px;right:24px;z-index:9999;min-width:260px;';
    d.innerHTML=`<div class="alert alert-${type} alert-dismissible shadow-lg fade show mb-0 d-flex align-items-center gap-2">
        <i class="bi bi-${type==='success'?'check-circle-fill':'exclamation-circle-fill'}"></i>
        <span>${esc(msg)}</span>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
    </div>`;
    document.body.appendChild(d);
    setTimeout(()=>{try{bootstrap.Alert.getOrCreateInstance(d.querySelector('.alert')).close();}catch(e){d.remove();}},4000);
}

/* ── trend chart (summary category only) ── */
<?php if ($category==='summary'): ?>
new Chart(document.getElementById('trendChart'),{
    type:'line',
    data:{
        labels:<?= json_encode($trendLabels) ?>,
        datasets:[
            {label:'Residents',data:<?= json_encode($trendResidents) ?>,borderColor:'#1a4f7a',backgroundColor:'rgba(26,79,122,.08)',tension:.4,fill:true,pointRadius:4},
            {label:'Documents',data:<?= json_encode($trendDocs) ?>,borderColor:'#16a34a',backgroundColor:'rgba(22,163,74,.08)',tension:.4,fill:true,pointRadius:4},
            {label:'Blotter',  data:<?= json_encode($trendBlotter) ?>,borderColor:'#c62828',backgroundColor:'rgba(198,40,40,.08)',tension:.4,fill:true,pointRadius:4}
        ]
    },
    options:{responsive:true,plugins:{legend:{position:'top'}},scales:{y:{beginAtZero:true}}}
});
<?php endif; ?>
</script>
</body>
</html>
