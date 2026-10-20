<?php
require_once __DIR__ . '/../config/config.php';
requireRole('super_admin');

$db = Database::getInstance()->getConnection();
$u  = currentUser();

// Add seen_at column if not exists (safe migration)
try {
    $db->exec("ALTER TABLE reports ADD COLUMN seen_at TIMESTAMP NULL DEFAULT NULL");
} catch (PDOException $e) { /* already exists */ }

// Safe migration: extend reports status enum
try {
    $db->exec("ALTER TABLE reports MODIFY COLUMN status ENUM('draft','submitted','approved','archived','rejected','done') DEFAULT 'draft'");
} catch (PDOException $e) { /* already up to date */ }

// Safe migration: add reject_reason column
try {
    $db->exec("ALTER TABLE reports ADD COLUMN reject_reason VARCHAR(500) NULL DEFAULT NULL");
} catch (PDOException $e) { /* already exists */ }

// ── POST Actions ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrAbort();
    $action = $_POST['action'] ?? '';

    if ($action === 'approve') {
        $id  = (int)$_POST['report_id'];
        $db->prepare("UPDATE reports SET status='approved', seen_at=COALESCE(seen_at,NOW()) WHERE id=?")->execute([$id]);

        $rep = $db->prepare("SELECT r.*, b.barangay_name FROM reports r LEFT JOIN barangays b ON r.barangay_id=b.id WHERE r.id=?");
        $rep->execute([$id]);
        $rep = $rep->fetch();
        if ($rep && $rep['submitted_by']) {
            sendNotification($rep['submitted_by'],
                "✅ Your report from Barangay {$rep['barangay_name']} has been approved by the LGU.", 'success', 'transaction');
        }
        logActivity($u['id'], 'APPROVE_REPORT', 'Reports', "Approved report from Barangay {$rep['barangay_name']}");
        flash('rep', 'Report approved and barangay notified.', 'success');
        redirect(BASE_URL . '/super_admin/reports.php');
    }

    if ($action === 'reject') {
        $id     = (int)$_POST['report_id'];
        $reason = sanitize($_POST['reject_reason'] ?? '');
        $db->prepare("UPDATE reports SET status='rejected', reject_reason=?, seen_at=COALESCE(seen_at,NOW()) WHERE id=?")
           ->execute([$reason, $id]);
        $rep = $db->prepare("SELECT r.*, b.barangay_name FROM reports r LEFT JOIN barangays b ON r.barangay_id=b.id WHERE r.id=?");
        $rep->execute([$id]);
        $rep = $rep->fetch();
        if ($rep && $rep['submitted_by']) {
            $msg = "❌ Your report from Barangay {$rep['barangay_name']} was rejected by the LGU.";
            if ($reason) $msg .= " Reason: $reason";
            sendNotification($rep['submitted_by'], $msg, 'danger', 'transaction');
        }
        logActivity($u['id'], 'REJECT_REPORT', 'Reports', "Rejected report from Barangay {$rep['barangay_name']}" . ($reason ? " — $reason" : ''));
        flash('rep', 'Report rejected and barangay notified.', 'warning');
        redirect(BASE_URL . '/super_admin/reports.php');
    }

    if ($action === 'mark_done') {
        $id = (int)$_POST['report_id'];
        $db->prepare("UPDATE reports SET status='done' WHERE id=?")->execute([$id]);
        $rep = $db->prepare("SELECT r.*, b.barangay_name FROM reports r LEFT JOIN barangays b ON r.barangay_id=b.id WHERE r.id=?");
        $rep->execute([$id]);
        $rep = $rep->fetch();
        if ($rep && $rep['submitted_by']) {
            sendNotification($rep['submitted_by'],
                "🏁 Your report from Barangay {$rep['barangay_name']} has been marked as Done by the LGU.", 'success', 'transaction');
        }
        logActivity($u['id'], 'DONE_REPORT', 'Reports', "Marked report from Barangay {$rep['barangay_name']} as done");
        flash('rep', 'Report marked as done.', 'success');
        redirect(BASE_URL . '/super_admin/reports.php');
    }

    if ($action === 'mark_seen') {
        $id = (int)$_POST['report_id'];
        $db->prepare("UPDATE reports SET seen_at=NOW() WHERE id=? AND seen_at IS NULL")->execute([$id]);

        // Notify barangay admin that LGU has received/seen the report
        $rep = $db->prepare("SELECT r.*, b.barangay_name FROM reports r LEFT JOIN barangays b ON r.barangay_id=b.id WHERE r.id=?");
        $rep->execute([$id]);
        $rep = $rep->fetch();
        if ($rep && $rep['submitted_by']) {
            sendNotification($rep['submitted_by'],
                "👁️ The LGU has received and reviewed your report from Barangay {$rep['barangay_name']}.", 'info', 'transaction');
        }
        redirect(BASE_URL . '/super_admin/reports.php');
    }

    if ($action === 'archive_report') {
        $id = (int)$_POST['report_id'];
        $row = $db->prepare("SELECT r.*, b.barangay_name FROM reports r LEFT JOIN barangays b ON r.barangay_id=b.id WHERE r.id=?");
        $row->execute([$id]);
        $data = $row->fetch();
        if ($data) {
            $db->prepare("INSERT INTO archives (table_name,record_id,data_json,archived_by) VALUES ('reports',?,?,?)")
               ->execute([$id, json_encode($data), $u['id']]);
            $db->prepare("UPDATE reports SET status='archived' WHERE id=?")->execute([$id]);
            logActivity($u['id'], 'ARCHIVE_REPORT', 'Reports', "Archived report from Barangay {$data['barangay_name']}");
            flash('rep', 'Report archived.', 'info');
        }
        redirect(BASE_URL . '/super_admin/reports.php');
    }
}

// ── Main section tab: data_reports | manual_reports ───────
$mainTab = sanitize($_GET['tab'] ?? 'data_reports');

// ── Manual Reports (barangay-typed) ───────────────────────
$mrBrgyFilter  = (int)($_GET['mr_barangay'] ?? 0);
$mrTypeFilter  = sanitize($_GET['mr_type']  ?? '');
$mrSearch      = sanitize($_GET['mr_search']?? '');

$mrWhere = "WHERE mr.status='sent'";
if ($mrBrgyFilter) $mrWhere .= " AND mr.barangay_id=$mrBrgyFilter";
if ($mrTypeFilter) $mrWhere .= " AND mr.report_type=" . $db->quote($mrTypeFilter);
if ($mrSearch)     $mrWhere .= " AND (b.barangay_name LIKE " . $db->quote("%$mrSearch%") . " OR mr.report_title LIKE " . $db->quote("%$mrSearch%") . ")";

$manualReports = $db->query("
    SELECT mr.*, b.barangay_name,
           CONCAT(u.first_name,' ',u.last_name) as author_name,
           u.email as author_email
    FROM manual_reports mr
    LEFT JOIN barangays b ON mr.barangay_id = b.id
    LEFT JOIN users u ON mr.created_by = u.id
    $mrWhere
    ORDER BY mr.sent_at DESC
")->fetchAll();

$manualReportCount = count($manualReports);

// ── Filters ────────────────────────────────────────────────
// category tab: all | pending | viewed | approved | rejected | done
$categoryTab  = sanitize($_GET['category']     ?? 'all');
$brgyFilter   = (int)($_GET['barangay_id']     ?? 0);
$typeFilter   = sanitize($_GET['report_type']  ?? '');
$search       = sanitize($_GET['search']       ?? '');

$where = "WHERE r.status != 'archived'";
if ($brgyFilter) $where .= " AND r.barangay_id=$brgyFilter";
if ($typeFilter) $where .= " AND r.report_type='$typeFilter'";
if ($search)     $where .= " AND (b.barangay_name LIKE '%$search%' OR r.notes LIKE '%$search%')";

// Category tab maps to status filter
switch ($categoryTab) {
    case 'pending':  $where .= " AND r.status='submitted' AND r.seen_at IS NULL"; break;
    case 'viewed':   $where .= " AND r.status='submitted' AND r.seen_at IS NOT NULL"; break;
    case 'approved': $where .= " AND r.status='approved'"; break;
    case 'rejected': $where .= " AND r.status='rejected'"; break;
    case 'done':     $where .= " AND r.status='done'"; break;
    default:         break; // all
}

$reports = $db->query("
    SELECT r.*, b.barangay_name,
           CONCAT(u.first_name,' ',u.last_name) as submitted_by_name,
           u.email as submitted_by_email
    FROM reports r
    LEFT JOIN barangays b ON r.barangay_id = b.id
    LEFT JOIN users u ON r.submitted_by = u.id
    $where
    ORDER BY r.created_at DESC
")->fetchAll();

// Pagination
$perPage     = 10;
$currentPage = max(1, (int)($_GET['page'] ?? 1));
$countWhere  = $where; // reuse same WHERE
$totalRows   = (int)$db->query("SELECT COUNT(*) FROM reports r LEFT JOIN barangays b ON r.barangay_id=b.id LEFT JOIN users u ON r.submitted_by=u.id $countWhere")->fetchColumn();
$totalPages  = max(1, (int)ceil($totalRows / $perPage));
$currentPage = min($currentPage, $totalPages);
$offset      = ($currentPage - 1) * $perPage;

$reports = $db->query("
    SELECT r.*, b.barangay_name,
           CONCAT(u.first_name,' ',u.last_name) as submitted_by_name,
           u.email as submitted_by_email
    FROM reports r
    LEFT JOIN barangays b ON r.barangay_id = b.id
    LEFT JOIN users u ON r.submitted_by = u.id
    $where
    ORDER BY r.created_at DESC
    LIMIT $perPage OFFSET $offset
")->fetchAll();

// Tab counts
$tabCounts = [];
$tabCounts['all']      = (int)$db->query("SELECT COUNT(*) FROM reports r WHERE r.status NOT IN ('archived')")->fetchColumn();
$tabCounts['pending']  = (int)$db->query("SELECT COUNT(*) FROM reports r WHERE r.status='submitted' AND r.seen_at IS NULL")->fetchColumn();
$tabCounts['viewed']   = (int)$db->query("SELECT COUNT(*) FROM reports r WHERE r.status='submitted' AND r.seen_at IS NOT NULL")->fetchColumn();
$tabCounts['approved'] = (int)$db->query("SELECT COUNT(*) FROM reports r WHERE r.status='approved'")->fetchColumn();
$tabCounts['rejected'] = (int)$db->query("SELECT COUNT(*) FROM reports r WHERE r.status='rejected'")->fetchColumn();
$tabCounts['done']     = (int)$db->query("SELECT COUNT(*) FROM reports r WHERE r.status='done'")->fetchColumn();
$unseenCount = $tabCounts['pending'];

$barangays = $db->query("SELECT * FROM barangays WHERE status='active' ORDER BY barangay_name")->fetchAll();
$flash     = getFlash('rep');
$pageTitle = 'Reports Management';
$months    = ['','January','February','March','April','May','June','July','August','September','October','November','December'];
?>

<?php
$pageIcon     = 'bi-file-earmark-bar-graph-fill';
$pageTitle    = 'Reports Management';
$pageSubtitle = 'Review, approve, and manage barangay reports.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favcon.png">
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favcon.png">
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=3.0">
</head>
<style>
/* dl-box buttons — matches barangay_admin/reports.php exactly */
.dl-box { display:inline-flex; align-items:center; gap:6px; padding:6px 14px; border-radius:8px; font-size:.78rem; font-weight:600; text-decoration:none; border:none; cursor:pointer; transition:opacity .15s,transform .12s; white-space:nowrap; }
.dl-box:hover { opacity:.88; transform:translateY(-1px); text-decoration:none; }
.dl-box-excel { background:#16a34a; color:#fff; }
.dl-box-excel:hover { color:#fff; }
.dl-box-pdf   { background:#dc2626; color:#fff; }
.dl-box-pdf:hover { color:#fff; }
</style>
<body>
<?php include __DIR__ . '/../includes/sidebar_super.php'; ?>
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<div class="main-content">

    

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show">
            <?= $flash['message'] ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- ── Main tab switcher ──────────────────────────────── -->
    <div style="display:flex;gap:4px;background:#f1f5f9;border-radius:12px;padding:4px;margin-bottom:1.25rem;">
        <a href="?tab=data_reports&category=<?= urlencode($categoryTab) ?>"
           style="flex:1;padding:8px 16px;border-radius:9px;font-size:0.82rem;font-weight:600;text-decoration:none;display:flex;align-items:center;justify-content:center;gap:6px;transition:all .18s;
                  background:<?= $mainTab==='data_reports'?'#fff':'transparent' ?>;
                  color:<?= $mainTab==='data_reports'?'#1a4f7a':'#64748b' ?>;
                  box-shadow:<?= $mainTab==='data_reports'?'0 1px 6px rgba(0,0,0,.10)':'none' ?>;">
            <i class="bi bi-bar-chart-fill"></i> Data Reports
        </a>
        <a href="?tab=manual_reports"
           style="flex:1;padding:8px 16px;border-radius:9px;font-size:0.82rem;font-weight:600;text-decoration:none;display:flex;align-items:center;justify-content:center;gap:6px;transition:all .18s;
                  background:<?= $mainTab==='manual_reports'?'#fff':'transparent' ?>;
                  color:<?= $mainTab==='manual_reports'?'#1a4f7a':'#64748b' ?>;
                  box-shadow:<?= $mainTab==='manual_reports'?'0 1px 6px rgba(0,0,0,.10)':'none' ?>;">
            <i class="bi bi-pencil-square"></i> Manual Reports
            <?php if ($manualReportCount > 0): ?>
            <span style="background:#1a4f7a;color:#fff;border-radius:20px;padding:1px 8px;font-size:0.7rem;font-weight:700;"><?= $manualReportCount ?></span>
            <?php endif; ?>
        </a>
    </div>

    <?php if ($mainTab === 'manual_reports'): ?>
    <!-- ════════════════════════════════════════════════════
         MANUAL REPORTS from Barangays
    ════════════════════════════════════════════════════ -->
    <?php
    $mrTypeMap = [
        'General'       => ['bg'=>'#e8f0fe','color'=>'#1a4f7a', 'icon'=>'📋'],
        'Incident'      => ['bg'=>'#fee2e2','color'=>'#991b1b', 'icon'=>'🚨'],
        'Health'        => ['bg'=>'#d1fae5','color'=>'#065f46', 'icon'=>'🏥'],
        'Financial'     => ['bg'=>'#fef3c7','color'=>'#854d0e', 'icon'=>'💰'],
        'Infrastructure'=> ['bg'=>'#ede9fe','color'=>'#4c1d95', 'icon'=>'🏗️'],
        'Census'        => ['bg'=>'#e0f2fe','color'=>'#0f4c75', 'icon'=>'📊'],
        'Other'         => ['bg'=>'#f3f4f6','color'=>'#374151', 'icon'=>'📄'],
    ];
    ?>

    <!-- Filter bar -->
    <div class="card mb-3">
        <div class="card-body py-2">
            <form method="GET" class="d-flex gap-2 flex-wrap align-items-center">
                <input type="hidden" name="tab" value="manual_reports">
                <div class="input-group" style="width:200px;">
                    <span class="input-group-text" style="background:#f8fafc;"><i class="bi bi-search" style="color:#3a7fa8;font-size:.85rem;"></i></span>
                    <input type="text" name="mr_search" class="form-control form-control-sm" placeholder="Search title or barangay…" value="<?= htmlspecialchars($mrSearch) ?>">
                </div>
                <select name="mr_barangay" class="form-select form-select-sm" style="width:170px;">
                    <option value="">All Barangays</option>
                    <?php foreach ($barangays as $b): ?>
                    <option value="<?= $b['id'] ?>" <?= $mrBrgyFilter==$b['id']?'selected':'' ?>><?= htmlspecialchars($b['barangay_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="mr_type" class="form-select form-select-sm" style="width:150px;">
                    <option value="">All Types</option>
                    <?php foreach (array_keys($mrTypeMap) as $t): ?>
                    <option value="<?= $t ?>" <?= $mrTypeFilter===$t?'selected':'' ?>><?= $t ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-primary btn-sm px-3"><i class="bi bi-funnel me-1"></i>Filter</button>
                <?php if ($mrSearch||$mrTypeFilter||$mrBrgyFilter): ?>
                <a href="?tab=manual_reports" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x me-1"></i>Clear</a>
                <?php endif; ?>
                <span class="ms-auto text-muted small"><?= count($manualReports) ?> report<?= count($manualReports)!==1?'s':'' ?></span>
            </form>
        </div>
    </div>

    <!-- Notices-style split layout -->
    <style>
    .sa-mr-shell { display:flex; height:calc(100vh - var(--topbar-height) - 210px); min-height:500px; background:#fff; border-radius:16px; box-shadow:0 2px 20px rgba(0,0,0,.08); border:1px solid #e2eaf2; }
    .sa-mr-list  { width:360px; min-width:300px; flex-shrink:0; display:flex; flex-direction:column; border-right:1px solid #e2eaf2; background:#f8fafc; border-radius:16px 0 0 16px; }
    .sa-mr-list-head { padding:10px 12px; background:#fff; border-bottom:1px solid #e2eaf2; flex-shrink:0; display:flex; align-items:center; justify-content:space-between; }
    .sa-mr-list-body { flex:1; overflow-y:auto; }
    .sa-mr-list-body::-webkit-scrollbar { width:4px; }
    .sa-mr-list-body::-webkit-scrollbar-thumb { background:#cbd5e1; border-radius:4px; }
    .sa-mr-item { display:flex; align-items:flex-start; gap:10px; padding:11px 14px; border-bottom:1px solid #eef2f7; cursor:pointer; background:#fff; transition:background .12s; }
    .sa-mr-item:hover  { background:#f0f6ff; }
    .sa-mr-item.active { background:#e8f0fe; border-left:3px solid #1a4f7a; }
    .sa-mr-right { flex:1; display:flex; flex-direction:column; border-radius:0 16px 16px 0; background:#fff; min-width:0; }
    .sa-mr-empty { flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center; color:#94a3b8; gap:10px; }
    .sa-mr-detail { display:none; flex-direction:column; flex:1; min-height:0; }
    .sa-mr-detail.show { display:flex; }
    .sa-mr-dheader { padding:18px 24px 14px; border-bottom:1px solid #e2eaf2; flex-shrink:0; }
    .sa-mr-dbody   { flex:1; overflow-y:auto; padding:20px 24px; }
    .sa-mr-dbody::-webkit-scrollbar { width:4px; }
    .sa-mr-dbody::-webkit-scrollbar-thumb { background:#cbd5e1; border-radius:4px; }
    .sa-mr-dfooter { padding:12px 24px; border-top:1px solid #e2eaf2; background:#f8fafc; display:flex; align-items:center; flex-shrink:0; }
    .sa-mr-content { background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:18px 20px; white-space:pre-wrap; line-height:1.8; font-size:.9rem; color:#334155; min-height:180px; }
    </style>

    <div class="sa-mr-shell">

        <!-- LEFT: list -->
        <div class="sa-mr-list">
            <div class="sa-mr-list-head">
                <span style="font-size:.71rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#64748b;">Manual Reports</span>
                <span style="background:#e2eaf2;color:#1a4f7a;border-radius:20px;padding:1px 8px;font-size:.65rem;font-weight:700;"><?= count($manualReports) ?></span>
            </div>
            <div class="sa-mr-list-body" id="mrListBody">
                <?php if (empty($manualReports)): ?>
                <div style="text-align:center;padding:40px 16px;color:#94a3b8;">
                    <i class="bi bi-inbox" style="font-size:2rem;opacity:.3;display:block;margin-bottom:8px;"></i>
                    <span style="font-size:.82rem;">No manual reports yet.</span>
                </div>
                <?php endif; ?>
                <?php foreach ($manualReports as $idx => $mr):
                    $tc = $mrTypeMap[$mr['report_type']] ?? $mrTypeMap['Other'];
                    $dj = htmlspecialchars(json_encode([
                        'id'       => $mr['id'],
                        'title'    => $mr['report_title'],
                        'body'     => $mr['report_body'],
                        'type'     => $mr['report_type'],
                        'author'   => $mr['author_name'],
                        'email'    => $mr['author_email'] ?? '',
                        'barangay' => $mr['barangay_name'],
                        'sentAt'   => $mr['sent_at'] ? date('M d, Y h:i A', strtotime($mr['sent_at'])) : '',
                        'date'     => date('M d, Y', strtotime($mr['created_at'])),
                        'seenAt'   => !empty($mr['seen_at']) ? date('M d, Y h:i A', strtotime($mr['seen_at'])) : '',
                        '_seen'    => !empty($mr['seen_at']),
                    ], JSON_UNESCAPED_UNICODE), ENT_QUOTES);
                ?>
                <div class="sa-mr-item" data-mr="<?= $dj ?>" onclick="openMR(this)">
                    <div style="width:36px;height:36px;border-radius:10px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:1.1rem;background:<?= $tc['bg'] ?>;"><?= $tc['icon'] ?></div>
                    <div style="flex:1;min-width:0;">
                        <div style="font-size:.82rem;font-weight:600;color:#1a2e3f;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($mr['report_title']) ?></div>
                        <div style="font-size:.72rem;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:1px;"><?= htmlspecialchars($mr['barangay_name']??'') ?> · <?= htmlspecialchars(mb_substr(strip_tags($mr['report_body']),0,40)) ?>…</div>
                        <div style="font-size:.65rem;color:#94a3b8;margin-top:3px;">
                            <span style="background:<?= $tc['bg'] ?>;color:<?= $tc['color'] ?>;padding:1px 6px;border-radius:20px;font-size:.6rem;font-weight:600;"><?= htmlspecialchars($mr['report_type']) ?></span>
                            &nbsp;·&nbsp;<?= $mr['sent_at']?date('M d, Y',strtotime($mr['sent_at'])):date('M d, Y',strtotime($mr['created_at'])) ?>
                            &nbsp;<i class="bi bi-send-check-fill text-success" style="font-size:.6rem;"></i>
                            <?php if (!empty($mr['seen_at'])): ?>
                            &nbsp;<i class="bi bi-eye-fill text-info" title="Seen by LGU on <?= date('M d, Y h:i A',strtotime($mr['seen_at'])) ?>" style="font-size:.6rem;"></i>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div><!-- /left -->

        <!-- RIGHT: detail -->
        <div class="sa-mr-right">

            <div class="sa-mr-empty" id="mrEmpty">
                <i class="bi bi-envelope-open" style="font-size:3rem;opacity:.2;"></i>
                <span style="font-size:.82rem;">Click a report to read it</span>
            </div>

            <div class="sa-mr-detail" id="mrDetail">
                <div class="sa-mr-dheader">
                    <div style="display:flex;align-items:flex-start;gap:14px;">
                        <div id="mrIcon" style="width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.4rem;flex-shrink:0;background:#e8f0fe;">📋</div>
                        <div style="flex:1;min-width:0;">
                            <h5 id="mrTitle" style="font-weight:700;margin-bottom:4px;font-size:1rem;color:#1a2e3f;"></h5>
                            <div style="display:flex;flex-wrap:wrap;align-items:center;gap:8px;">
                                <span id="mrBadge"></span>
                                <span style="font-size:.82rem;color:#64748b;"><i class="bi bi-building me-1"></i><span id="mrBarangay"></span></span>
                                <span style="font-size:.82rem;color:#64748b;"><i class="bi bi-person me-1"></i><span id="mrAuthor"></span></span>
                                <span id="mrEmailWrap" style="font-size:.82rem;color:#3a7fa8;display:none;"><i class="bi bi-envelope me-1"></i><a id="mrEmail" href="#" style="color:#3a7fa8;text-decoration:none;"></a></span>
                                <span style="font-size:.82rem;color:#64748b;"><i class="bi bi-clock me-1"></i><span id="mrDate"></span></span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="sa-mr-dbody">
                    <div class="sa-mr-content" id="mrBody"></div>
                </div>
                <div class="sa-mr-dfooter">
                    <span style="font-size:.8rem;color:#16a34a;"><i class="bi bi-send-check-fill me-1"></i>Received from Barangay</span>
                    <span class="ms-auto text-muted small" id="mrMeta"></span>
                    <span id="mrSeenStatus" style="font-size:.78rem;margin-left:12px;"></span>
                </div>
            </div>

        </div><!-- /right -->
    </div><!-- /sa-mr-shell -->

    <?php else: /* data_reports tab */ ?>

    <!-- Category Tabs -->
    <?php
    $tabs = [
        'all'      => ['label'=>'All',          'icon'=>'bi-list-ul',              'color'=>'#1a4f7a'],
        'pending'  => ['label'=>'Pending',       'icon'=>'bi-hourglass-split',      'color'=>'#d97706'],
        'viewed'   => ['label'=>'Viewed',        'icon'=>'bi-eye-fill',             'color'=>'#0369a1'],
        'approved' => ['label'=>'Approved',      'icon'=>'bi-check-circle-fill',    'color'=>'#16a34a'],
        'rejected' => ['label'=>'Rejected',      'icon'=>'bi-x-circle-fill',        'color'=>'#dc2626'],
        'done'     => ['label'=>'Done',          'icon'=>'bi-flag-fill',            'color'=>'#7c3aed'],
    ];
    ?>
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px;">
        <?php foreach ($tabs as $key => $tab):
            $active = ($categoryTab === $key);
            $cnt    = $tabCounts[$key];
        ?>
        <a href="?category=<?= $key ?><?= $brgyFilter?"&barangay_id=$brgyFilter":'' ?><?= $typeFilter?"&report_type=".urlencode($typeFilter):'' ?><?= $search?"&search=".urlencode($search):'' ?>"
           style="display:inline-flex;align-items:center;gap:7px;padding:7px 16px;border-radius:30px;font-size:0.8rem;font-weight:600;text-decoration:none;border:2px solid <?= $active ? $tab['color'] : '#d0ecd6' ?>;background:<?= $active ? $tab['color'] : '#fff' ?>;color:<?= $active ? '#fff' : '#3a7fa8' ?>;transition:all 0.15s;">
            <i class="bi <?= $tab['icon'] ?>"></i>
            <?= $tab['label'] ?>
            <span style="background:<?= $active ? 'rgba(255,255,255,0.25)' : '#e8f4fb' ?>;color:<?= $active ? '#fff' : '#3a7fa8' ?>;border-radius:20px;padding:1px 8px;font-size:0.72rem;font-weight:700;"><?= $cnt ?></span>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Search / Type Filters -->
    <div style="background:#fff;border-radius:14px;box-shadow:0 2px 12px rgba(0,0,0,0.06);border:1px solid #e0f0e4;margin-bottom:16px;overflow:hidden;">
        <!-- Header bar with gradient — same as brgy reports -->
        <div style="background:linear-gradient(135deg,#1a4f7a 0%,#2d6fa3 100%);padding:10px 16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
            <h6 style="font-size:.88rem;font-weight:700;color:#fff;display:flex;align-items:center;gap:7px;margin:0;">
                <i class="bi bi-bar-chart-fill"></i> Data Reports
            </h6>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <span class="text-white small" id="selectedCount" style="display:none;opacity:.8;">
                    <span id="selectedNum">0</span> selected
                </span>
                <button type="button" class="dl-box dl-box-excel" onclick="exportSelected('excel')">
                    <i class="bi bi-file-earmark-excel-fill"></i> Export Excel
                </button>
                <button type="button" class="dl-box dl-box-pdf" onclick="exportSelected('pdf')">
                    <i class="bi bi-file-earmark-pdf-fill"></i> Export PDF
                </button>
            </div>
        </div>
        <!-- Barangay filter row -->
        <form method="GET" class="d-flex gap-2 flex-wrap align-items-center" id="searchForm" style="padding:10px 16px;">
            <input type="hidden" name="category" value="<?= htmlspecialchars($categoryTab) ?>">
            <div class="d-flex align-items-center gap-1">
                <select name="barangay_id" id="brgySelect" class="form-select form-select-sm" style="width:200px;border-color:#e0f0e4;background:#f8fafc;" onchange="this.form.submit()">
                    <option value="">All Barangays</option>
                    <?php foreach ($barangays as $b): ?>
                        <option value="<?= $b['id'] ?>" <?= $brgyFilter==$b['id']?'selected':'' ?>>
                            <?= htmlspecialchars($b['barangay_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($brgyFilter): ?>
                <a href="?category=<?= htmlspecialchars($categoryTab) ?>" class="btn btn-sm btn-outline-secondary" style="border-radius:7px;padding:3px 8px;" title="Clear barangay filter">
                    <i class="bi bi-x-lg" style="font-size:.75rem;"></i>
                </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Reports Table -->
    <div id="logs-table" style="background:#fff;border-radius:18px;box-shadow:0 2px 16px rgba(0,0,0,0.07);border:1px solid #e0f0e4;overflow:hidden;">
        <div class="table-responsive">
            <table class="table mb-0" id="reportsTable">
                <thead>
                    <tr style="background:#f4f7fa;border-bottom:2px solid #d0ecd6;">
                        <th class="ps-4 py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;width:36px;">
                            <input type="checkbox" id="selectAll" class="form-check-input" title="Select all">
                        </th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">Barangay</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">Type / Period</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">Submitted By</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">Date Submitted</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">Status</th>
                        <th class="pe-4 py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;width:150px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($reports)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-5"><i class="bi bi-inbox" style="font-size:2.5rem;opacity:0.3;display:block;margin-bottom:10px;"></i>No reports found.</td></tr>
                <?php else: ?>
                    <?php foreach ($reports as $i => $r):
                        $isNew     = ($r['status'] === 'submitted' && !$r['seen_at']);
                        $sc        = ['draft'=>'secondary','submitted'=>'warning','approved'=>'success'];
                        $typeLabel = str_starts_with($r['report_type'], 'accomplishment') ? 'Summary' : ucfirst($r['report_type']);
                        preg_match('/^\[([^\]]+)\]/', $r['notes'] ?? '', $periodMatch);
                        $periodLabel  = $periodMatch[1] ?? ($months[$r['month']] ?? '') . ' ' . $r['year'];
                        $notesDisplay = preg_replace('/^\[[^\]]+\]\s*/', '', $r['notes'] ?? '');
                    ?>
                    <tr style="border-bottom:1px solid #e8f4fb;transition:background 0.15s;<?= $isNew ? 'background:#fffbeb;' : '' ?>" onmouseover="this.style.background='<?= $isNew ? '#fef9c3' : '#f8fafc' ?>'" onmouseout="this.style.background='<?= $isNew ? '#fffbeb' : '' ?>'">
                        <!-- Checkbox -->
                        <td class="ps-4 py-3" style="border:none;">
                            <input type="checkbox" class="form-check-input row-check"
                                   value="<?= $r['id'] ?>"
                                   data-brgy="<?= htmlspecialchars($r['barangay_name'] ?? '') ?>"
                                   onchange="updateSelectedCount()">
                        </td>
                        <td class="py-3" style="border:none;">
                            <div class="fw-semibold"><?= htmlspecialchars($r['barangay_name'] ?? '—') ?></div>
                            <?php if ($isNew): ?>
                                <span class="badge bg-danger" style="font-size:0.6rem;">NEW</span>
                            <?php endif; ?>
                        </td>
                        <td class="py-3" style="border:none;">
                            <span class="badge bg-primary"><?= $typeLabel ?></span>
                            <div class="small text-muted mt-1"><?= htmlspecialchars($periodLabel) ?></div>
                        </td>
                        <td class="py-3 small" style="border:none;">
                            <?= htmlspecialchars($r['submitted_by_name'] ?? '—') ?>
                            <?php if (!empty($r['submitted_by_email'])): ?>
                                <div class="text-muted" style="font-size:0.72rem;">
                                    <i class="bi bi-envelope me-1"></i><?= htmlspecialchars($r['submitted_by_email']) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="py-3 small text-nowrap" style="border:none;">
                            <?= date('M d, Y', strtotime($r['created_at'])) ?><br>
                            <span class="text-muted"><?= date('h:i A', strtotime($r['created_at'])) ?></span>
                            <?php if ($r['seen_at']): ?>
                                <div class="text-success" style="font-size:0.65rem;">
                                    <i class="bi bi-eye-fill me-1"></i>Seen
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="py-3" style="border:none;">
                            <span class="badge bg-<?= $sc[$r['status']] ?? 'secondary' ?>">
                                <?= ucfirst($r['status']) ?>
                            </span>
                        </td>
                        <td class="pe-4 py-3" style="border:none;">
                            <div class="d-flex gap-1 align-items-center">
                                <!-- View only -->
                                <button class="btn btn-xs btn-outline-primary"
                                        onclick="viewReport(<?= htmlspecialchars(json_encode($r)) ?>)"
                                        title="View Report">
                                    <i class="bi bi-eye"></i>
                                </button>

                                <!-- Approve check button -->
                                <?php if ($r['status'] === 'submitted'): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="action" value="approve">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="report_id" value="<?= $r['id'] ?>">
                                    <button class="btn btn-xs btn-success" title="Approve this report">
                                        <i class="bi bi-check-lg"></i>
                                    </button>
                                </form>
                                <?php elseif ($r['status'] === 'approved'): ?>
                                    <i class="bi bi-check-circle-fill text-success" title="Approved" style="font-size:1rem;"></i>
                                <?php endif; ?>
                                <!-- Archive button -->
                                <button class="btn btn-xs btn-outline-warning"
                                        onclick="openReportArchiveModal(<?= $r['id'] ?>, '<?= htmlspecialchars(addslashes($r['barangay_name'] ?? '')) ?>', '<?= htmlspecialchars(addslashes($typeLabel)) ?>')"
                                        title="Archive">
                                    <i class="bi bi-archive"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <?php
        $queryParams = $_GET; unset($queryParams['page']);
        $baseQuery   = http_build_query($queryParams);
        $baseUrl     = '?' . ($baseQuery ? $baseQuery . '&' : '');
        $window = 2;
    ?>
    <?php $page = $currentPage; $totalCount = $totalRows; $offset = ($page-1)*$perPage; $paginationBase = $baseUrl; $paginationLabel = 'report'; include __DIR__ . '/../includes/pagination.php'; ?>
    <?php endif; ?>

    <?php endif; /* end mainTab === data_reports */ ?>
</div>

<!-- View Report Modal — styled like Change Password modal -->
<form method="POST" id="markSeenForm" style="display:none;">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="mark_seen">
    <input type="hidden" name="report_id" id="viewReportId">
</form>

<div id="viewReportModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.5);backdrop-filter:blur(4px);align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;width:100%;max-width:860px;max-height:82vh;box-shadow:0 16px 50px rgba(0,0,0,0.28);overflow:hidden;margin:16px;display:flex;flex-direction:column;">

        <!-- Header -->
        <div style="background:linear-gradient(135deg,#1a4f7a,#3a7fa8);padding:12px 20px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
            <div class="d-flex align-items-center gap-2">
                <div style="width:32px;height:32px;background:rgba(255,255,255,0.15);border-radius:8px;display:flex;align-items:center;justify-content:center;">
                    <i class="bi bi-file-earmark-text-fill text-white" style="font-size:.95rem;"></i>
                </div>
                <div>
                    <div class="fw-bold text-white" style="font-size:.88rem;">Report Details</div>
                    <div style="font-size:0.68rem;color:rgba(255,255,255,0.7);">Barangay Summary Report</div>
                </div>
            </div>
            <button onclick="closeViewReport()" style="background:rgba(255,255,255,0.15);border:none;border-radius:6px;width:28px;height:28px;color:#fff;font-size:1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <!-- Meta strip -->
        <div style="background:#f4f7fa;border-bottom:1px solid #e0edf5;padding:8px 20px;display:flex;flex-wrap:wrap;gap:16px;flex-shrink:0;">
            <div><span style="font-size:.62rem;text-transform:uppercase;letter-spacing:.05em;color:#3a7fa8;font-weight:600;">Barangay</span><br><span style="font-size:.82rem;font-weight:700;color:#1a2e3f;" id="vr_barangay"></span></div>
            <div><span style="font-size:.62rem;text-transform:uppercase;letter-spacing:.05em;color:#3a7fa8;font-weight:600;">Period</span><br><span style="font-size:.82rem;font-weight:700;color:#1a2e3f;" id="vr_period"></span></div>
            <div><span style="font-size:.62rem;text-transform:uppercase;letter-spacing:.05em;color:#3a7fa8;font-weight:600;">Type</span><br><span id="vr_type"></span></div>
            <div><span style="font-size:.62rem;text-transform:uppercase;letter-spacing:.05em;color:#3a7fa8;font-weight:600;">Status</span><br><span id="vr_status"></span></div>
            <div><span style="font-size:.62rem;text-transform:uppercase;letter-spacing:.05em;color:#3a7fa8;font-weight:600;">Submitted By</span><br><span style="font-size:.8rem;font-weight:600;color:#1a2e3f;" id="vr_submitted_by"></span> <span style="font-size:.72rem;color:#6b7280;" id="vr_submitted_by_email"></span></div>
            <div><span style="font-size:.62rem;text-transform:uppercase;letter-spacing:.05em;color:#3a7fa8;font-weight:600;">Date</span><br><span style="font-size:.8rem;font-weight:600;color:#1a2e3f;" id="vr_date"></span></div>
        </div>

        <!-- Body — scrollable -->
        <div style="padding:14px 20px;overflow-y:auto;flex:1;" id="vr_notes"></div>

        <!-- Footer -->
        <div style="padding:10px 20px;border-top:1px solid #e0f0e4;flex-shrink:0;display:flex;gap:8px;align-items:center;">
            <a id="vr_export_excel" href="#" class="dl-box dl-box-excel">
                <i class="bi bi-file-earmark-excel-fill"></i> Download Excel
            </a>
            <a id="vr_export_pdf" href="#" target="_blank" class="dl-box dl-box-pdf">
                <i class="bi bi-file-earmark-pdf-fill"></i> Download PDF
            </a>
            <button onclick="closeViewReport()" class="btn btn-outline-secondary btn-sm ms-auto" style="border-radius:7px;font-size:.78rem;">Close</button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('show'); }

document.querySelectorAll('.page-nav').forEach(function(link) {
    link.addEventListener('click', function(e) {
        if (this.parentElement.classList.contains('disabled')) return;
        e.preventDefault();
        var t = document.getElementById('logs-table');
        if (t) t.scrollIntoView({ behavior:'smooth', block:'start' });
        var href = this.getAttribute('href');
        setTimeout(function(){ window.location.href = href; }, 350);
    });
});
if (window.location.hash === '#logs-table') {
    setTimeout(function(){ var t=document.getElementById('logs-table'); if(t) t.scrollIntoView({behavior:'smooth',block:'start'}); }, 80);
}

function openReportArchiveModal(id, barangay, type) {
    document.getElementById('archiveReportId').value        = id;
    document.getElementById('archiveReportName').textContent = barangay + ' — ' + type;
    new bootstrap.Modal(document.getElementById('archiveReportModal')).show();
}
function submitReportArchive() {
    bootstrap.Modal.getInstance(document.getElementById('archiveReportModal')).hide();
    document.getElementById('archiveReportForm').submit();
}

// ── Checkbox select all — only on data reports tab ────────
var _selAll = document.getElementById('selectAll');
if (_selAll) {
    _selAll.addEventListener('change', function() {
        document.querySelectorAll('.row-check').forEach(c => { c.checked = this.checked; });
        updateSelectedCount();
    });
}

function updateSelectedCount() {
    const checked = document.querySelectorAll('.row-check:checked');
    const countEl = document.getElementById('selectedCount');
    const numEl   = document.getElementById('selectedNum');
    if (numEl)   numEl.textContent     = checked.length;
    if (countEl) countEl.style.display = checked.length > 0 ? 'inline' : 'none';
}

// ── Export selected ────────────────────────────────────────
function exportSelected(fmt) {
    const checked = document.querySelectorAll('.row-check:checked');
    if (checked.length === 0) {
        // Export all if none selected
        const ids = Array.from(document.querySelectorAll('.row-check')).map(c => c.value);
        if (ids.length === 0) { alert('No reports to export.'); return; }
        const url = 'export_report_lgu.php?report_ids=' + ids.join(',') + '&format=' + fmt;
        fmt === 'pdf' ? window.open(url, '_blank') : window.location.href = url;
    } else {
        const ids = Array.from(checked).map(c => c.value);
        const url = 'export_report_lgu.php?report_ids=' + ids.join(',') + '&format=' + fmt;
        fmt === 'pdf' ? window.open(url, '_blank') : window.location.href = url;
    }
}

const months = ['','January','February','March','April','May','June','July','August','September','October','November','December'];

function viewReport(r) {
    // Populate modal
    document.getElementById('viewReportId').value = r.id;
    document.getElementById('vr_barangay').textContent     = r.barangay_name || '—';
    document.getElementById('vr_submitted_by').textContent = r.submitted_by_name || '—';
    var emailEl = document.getElementById('vr_submitted_by_email');
    if (r.submitted_by_email) {
        emailEl.innerHTML = '<i class="bi bi-envelope me-1"></i>' + r.submitted_by_email;
    } else {
        emailEl.textContent = '';
    }
    document.getElementById('vr_date').textContent         = new Date(r.created_at).toLocaleDateString('en-US', {month:'short',day:'numeric',year:'numeric'});

    // Type label
    const typeLabel = r.report_type === 'accomplishment' ? 'Summary' : r.report_type.charAt(0).toUpperCase() + r.report_type.slice(1);
    document.getElementById('vr_type').innerHTML = '<span class="badge bg-primary">' + typeLabel + '</span>';

    // Period from notes
    const periodMatch = (r.notes || '').match(/^\[([^\]]+)\]/);
    document.getElementById('vr_period').textContent = periodMatch ? periodMatch[1] : ((months[r.month] || '') + ' ' + r.year);

    // Status badge
    const sc = {draft:'secondary', submitted:'warning', approved:'success'};
    document.getElementById('vr_status').innerHTML = '<span class="badge bg-' + (sc[r.status]||'secondary') + '">' + r.status.charAt(0).toUpperCase() + r.status.slice(1) + '</span>';

    // Notes — structured dashboard-style sections
    const notesRaw = (r.notes || '').replace(/^\[[^\]]+\]\s*/, '');
    const parts = {};
    notesRaw.split(' | ').forEach(p => {
        // Support both "Key: Value" (new) and legacy "Key:Value" (old) formats
        const idx = p.indexOf(': ');
        if (idx > -1) {
            parts[p.substring(0, idx).trim()] = p.substring(idx + 2).trim();
        } else {
            const idx2 = p.indexOf(':');
            if (idx2 > -1) parts[p.substring(0, idx2).trim()] = p.substring(idx2 + 1).trim();
        }
    });

    // ── Compact table renderer ─────────────────────────────
    const tblSection = (title, icon, color, rows) => {
        let rows_html = rows.map(([label, key]) => {
            const val = parts[key] ?? '—';
            return `<tr><td style="font-size:.75rem;color:#374151;padding:4px 10px;border:1px solid #e8eef5;width:55%;">${label}</td><td style="font-size:.75rem;font-weight:700;color:#1a2e3f;padding:4px 10px;border:1px solid #e8eef5;text-align:right;">${val}</td></tr>`;
        }).join('');
        return `<div style="margin-bottom:10px;">
            <div style="font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:${color};margin-bottom:4px;display:flex;align-items:center;gap:5px;">
                <i class="bi ${icon}"></i>${title}
            </div>
            <table style="width:100%;border-collapse:collapse;border-radius:8px;overflow:hidden;">
                <tbody>${rows_html}</tbody>
            </table>
        </div>`;
    };

    let html = '';
    // Two-column layout
    const col1 = tblSection('Overview', 'bi-grid-1x2-fill', '#1a4f7a', [
        ['Total Residents',       'Total Residents'],
        ['New (Period)',           'New Residents (period)'],
        ['Households',            'Households'],
        ['Certificates (Period)', 'Certificates (period)'],
        ['Total Certificates',    'Total Certificates'],
        ['Top Certificate',       'Top Certificate'],
    ]) + tblSection('Blotter & Safety', 'bi-shield-fill-exclamation', '#c62828', [
        ['Total Blotter',    'Total Blotter'],
        ['Blotter (Period)', 'Blotter (period)'],
        ['Open',             'Blotter Open'],
        ['Settled',          'Blotter Settled'],
        ['Escalated',        'Blotter Escalated'],
        ['Closed',           'Blotter Closed'],
    ]);
    const col2 = tblSection('Demographics', 'bi-bar-chart-fill', '#1a4f7a', [
        ['Male',               'Male'],
        ['Female',             'Female'],
        ['Registered Voters',  'Registered Voters'],
        ['Non-Voters',         'Non-Voters'],
    ]) + tblSection('Sectors', 'bi-people-fill', '#e65100', [
        ['PWD',              'PWD'],
        ['Senior Citizens',  'Senior Citizens'],
        ['4Ps Beneficiaries','4Ps'],
        ['Solo Parents',     'Solo Parents'],
    ]) + tblSection('Employment', 'bi-briefcase-fill', '#0369a1', [
        ['Employed',      'Employed'],
        ['Unemployed',    'Unemployed'],
        ['Self-Employed', 'Self-Employed'],
        ['Student',       'Student'],
        ['Retired',       'Retired'],
    ]);
    html = `<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">${col1}${col2}</div>`;
    // transfers appended after two-column grid
    // ── Transfers section ──────────────────────────────────
    const tInTotal   = parts['Transferred In (Total)']    ?? parts['Transferred In (period)']   ?? '—';
    const tOutTotal  = parts['Transferred Out (Total)']   ?? parts['Transferred Out (period)']  ?? '—';
    const tInPeriod  = parts['Transferred In (period)']   ?? tInTotal;
    const tOutPeriod = parts['Transferred Out (period)']  ?? tOutTotal;
    const tOutside   = parts['Transferred Outside LGU']   ?? '—';
    const tNetRaw    = (tInTotal !== '—' && tOutTotal !== '—')
        ? (parseInt(tInTotal, 10) - parseInt(tOutTotal, 10))
        : null;
    const tNetLabel  = tNetRaw !== null ? (tNetRaw >= 0 ? '+' : '') + tNetRaw : '—';
    const tNetColor  = tNetRaw === null ? '#1a4f7a' : (tNetRaw >= 0 ? '#16a34a' : '#dc2626');

    html += `<div style="margin-top:10px;">
        <div style="font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#15803d;margin-bottom:4px;display:flex;align-items:center;gap:5px;">
            <i class="bi bi-arrow-left-right"></i>Population Transfers (Movement)
        </div>
        <table style="width:100%;border-collapse:collapse;">
            <tbody>
                <tr><td style="font-size:.75rem;color:#374151;padding:4px 10px;border:1px solid #e8eef5;width:55%;">Transferred IN (All-Time)</td><td style="font-size:.75rem;font-weight:700;color:#15803d;padding:4px 10px;border:1px solid #e8eef5;text-align:right;">${tInTotal}</td></tr>
                <tr><td style="font-size:.75rem;color:#374151;padding:4px 10px;border:1px solid #e8eef5;">Transferred IN (Period)</td><td style="font-size:.75rem;font-weight:700;color:#15803d;padding:4px 10px;border:1px solid #e8eef5;text-align:right;">${tInPeriod}</td></tr>
                <tr><td style="font-size:.75rem;color:#374151;padding:4px 10px;border:1px solid #e8eef5;">Transferred OUT (All-Time)</td><td style="font-size:.75rem;font-weight:700;color:#dc2626;padding:4px 10px;border:1px solid #e8eef5;text-align:right;">${tOutTotal}</td></tr>
                <tr><td style="font-size:.75rem;color:#374151;padding:4px 10px;border:1px solid #e8eef5;">Transferred OUT (Period)</td><td style="font-size:.75rem;font-weight:700;color:#dc2626;padding:4px 10px;border:1px solid #e8eef5;text-align:right;">${tOutPeriod}</td></tr>
                <tr><td style="font-size:.75rem;color:#374151;padding:4px 10px;border:1px solid #e8eef5;">Outside LGU / Municipality</td><td style="font-size:.75rem;font-weight:700;color:#92400e;padding:4px 10px;border:1px solid #e8eef5;text-align:right;">${tOutside}</td></tr>
                <tr><td style="font-size:.75rem;color:#374151;padding:4px 10px;border:1px solid #e8eef5;">Net Movement (In − Out)</td><td style="font-size:.75rem;font-weight:700;padding:4px 10px;border:1px solid #e8eef5;text-align:right;color:${tNetColor};">${tNetLabel}</td></tr>
            </tbody>
        </table>
    </div>`;

    document.getElementById('vr_notes').innerHTML = html || notesRaw || '—';
    // Export links
    document.getElementById('vr_export_excel').href = 'export_report_lgu.php?report_id=' + r.id + '&format=excel';
    document.getElementById('vr_export_pdf').href   = 'export_report_lgu.php?report_id=' + r.id + '&format=pdf';

    // Show modal
    document.getElementById('viewReportModal').style.display = 'flex';

    // Mark as seen after modal opens (if not already seen)
    if (!r.seen_at) {
        setTimeout(() => {
            document.getElementById('markSeenForm').submit();
        }, 1500);
    }
}

function closeViewReport() {
    document.getElementById('viewReportModal').style.display = 'none';
}

// Close on backdrop click — guard if element exists (not rendered on manual_reports tab)
var _vrm = document.getElementById('viewReportModal');
if (_vrm) {
    _vrm.addEventListener('click', function(e) {
        if (e.target === this) closeViewReport();
    });
}

// ── Manual Reports list interaction ───────────────────────
const mrTypeIcons  = {General:'📋',Incident:'🚨',Health:'🏥',Financial:'💰',Infrastructure:'🏗️',Census:'📊',Other:'📄'};
const mrTypeBg     = {General:'#e8f0fe',Incident:'#fee2e2',Health:'#d1fae5',Financial:'#fef3c7',Infrastructure:'#ede9fe',Census:'#e0f2fe',Other:'#f3f4f6'};
const mrTypeColor  = {General:'#1a4f7a',Incident:'#991b1b',Health:'#065f46',Financial:'#854d0e',Infrastructure:'#4c1d95',Census:'#0f4c75',Other:'#374151'};

function openMR(el) {
    // Remove active from all items
    document.querySelectorAll('.sa-mr-item').forEach(function(e) {
        e.classList.remove('active');
    });
    el.classList.add('active');

    const r     = JSON.parse(el.dataset.mr);
    const icon  = mrTypeIcons[r.type]  || '📄';
    const bg    = mrTypeBg[r.type]     || '#f1f5f9';
    const color = mrTypeColor[r.type]  || '#374151';

    document.getElementById('mrIcon').textContent       = icon;
    document.getElementById('mrIcon').style.background  = bg;
    document.getElementById('mrTitle').textContent      = r.title;
    document.getElementById('mrBarangay').textContent   = r.barangay || '—';
    document.getElementById('mrAuthor').textContent     = r.author   || '—';
    document.getElementById('mrDate').textContent       = r.sentAt   || r.date;
    document.getElementById('mrBody').textContent       = r.body;
    document.getElementById('mrMeta').textContent       = 'Submitted: ' + (r.sentAt || r.date);
    document.getElementById('mrBadge').innerHTML        =
        `<span style="font-size:.7rem;padding:2px 10px;border-radius:20px;font-weight:600;background:${bg};color:${color};">${icon} ${r.type}</span>`;

    // Show email if available
    var emailWrap = document.getElementById('mrEmailWrap');
    var emailEl   = document.getElementById('mrEmail');
    if (emailWrap && emailEl) {
        if (r.email) {
            emailEl.textContent = r.email;
            emailEl.href        = 'mailto:' + r.email;
            emailWrap.style.display = '';
        } else {
            emailWrap.style.display = 'none';
        }
    }

    document.getElementById('mrEmpty').style.display    = 'none';
    const det = document.getElementById('mrDetail');
    det.classList.add('show');

    // Show pre-existing seen status
    const seenEl = document.getElementById('mrSeenStatus');
    if (seenEl) {
        if (r.seenAt) {
            seenEl.innerHTML = `<i class="bi bi-eye-fill text-info me-1"></i><span style="color:#0369a1;">Seen by LGU: ${r.seenAt}</span>`;
        } else {
            seenEl.innerHTML = '<span style="color:#94a3b8;font-size:.75rem;"><i class="bi bi-eye me-1"></i>Not yet seen</span>';
        }
    }

    // Mark as seen by LGU (fire-and-forget)
    if (!r._seen) {
        const fd = new FormData();
        fd.append('report_id',  r.id);
        fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]')?.content || '');
        fetch('<?= BASE_URL ?>/ajax/mark_manual_report_seen.php', { method:'POST', body:fd })
            .then(res => res.json())
            .then(data => {
                if (data.ok && !data.already_seen) {
                    // Update seen badge on the list item
                    const activeItem = document.querySelector('.sa-mr-item.active');
                    if (activeItem) {
                        const metaDiv = activeItem.querySelector('[style*="margin-top:3px"]');
                        if (metaDiv && !metaDiv.querySelector('.mr-seen-badge')) {
                            const badge = document.createElement('span');
                            badge.className = 'mr-seen-badge';
                            badge.innerHTML = ' <i class="bi bi-eye-fill text-info" style="font-size:.6rem;"></i>';
                            metaDiv.appendChild(badge);
                        }
                    }
                    // Update footer seen status
                    if (seenEl) {
                        seenEl.innerHTML = `<i class="bi bi-eye-fill text-info me-1"></i><span style="color:#0369a1;">Seen by LGU: ${data.seen_at}</span>`;
                    }
                }
            })
            .catch(() => {});
        r._seen = true;
    }
}

// Auto-open first item on manual reports tab
document.addEventListener('DOMContentLoaded', function() {
    const first = document.querySelector('.sa-mr-item');
    if (first) openMR(first);
});
</script>
<style>.btn-xs { padding: 2px 8px; font-size: 0.75rem; }</style>

<!-- Hidden archive form -->
<form method="POST" id="archiveReportForm" style="display:none;">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="archive_report">
    <input type="hidden" name="report_id" id="archiveReportId">
</form>

<!-- Archive Report Modal -->
<div class="modal fade" id="archiveReportModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px; overflow:hidden;">
            <div class="d-flex">
                <!-- LEFT PANEL -->
                <div class="d-flex flex-column align-items-center justify-content-center px-4 py-5"
                     style="background:linear-gradient(160deg,#1a4f7a 0%,#3a7fa8 100%); min-width:200px; width:200px; align-self:stretch;">
                    <div style="width:72px;height:72px;background:rgba(255,255,255,0.15);border-radius:50%;display:flex;align-items:center;justify-content:center;border:3px solid rgba(255,255,255,0.3);">
                        <i class="bi bi-archive-fill text-white" style="font-size:2rem;"></i>
                    </div>
                    <h5 class="fw-bold mt-3 mb-1 text-white text-center">Archive Report</h5>
                    <p class="text-white-50 small text-center mb-0">This action is reversible</p>
                </div>
                <!-- RIGHT PANEL -->
                <div class="flex-fill d-flex flex-column justify-content-between p-4">
                    <div>
                        <p class="mb-1">You are about to archive:</p>
                        <p class="fw-bold fs-6" id="archiveReportName"></p>
                        <div class="alert alert-warning small py-2 mb-0">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            This report will be moved to the archive. It can be restored from the Archive page.
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-3">
                        <button type="button" class="btn btn-outline-secondary flex-fill" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary flex-fill fw-semibold" onclick="submitReportArchive()">
                            <i class="bi bi-check-lg me-1"></i>Confirm Archive
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>


