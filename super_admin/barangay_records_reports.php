<?php
require_once __DIR__ . '/../config/config.php';
requireRole('super_admin');

$db = Database::getInstance()->getConnection();
$u  = currentUser();

// Require barangay selection
$brgFilter = (int)($_GET['barangay_id'] ?? 0);
if (!$brgFilter) { redirect(BASE_URL . '/super_admin/barangay_records.php'); }
$brgyRow = $db->prepare("SELECT barangay_name, municipality FROM barangays WHERE id=? AND status='active'");
$brgyRow->execute([$brgFilter]);
$brgyRow = $brgyRow->fetch();
if (!$brgyRow) { redirect(BASE_URL . '/super_admin/barangay_records.php'); }
$brgyLabel = htmlspecialchars($brgyRow['barangay_name'] . ($brgyRow['municipality'] ? ' — '.$brgyRow['municipality'] : ''));

// Filters
$search    = trim(strip_tags($_GET['search']    ?? ''));
$statusF   = trim(strip_tags($_GET['status']   ?? ''));
$categoryF = trim(strip_tags($_GET['category'] ?? ''));
$dateFrom  = trim(strip_tags($_GET['date_from']?? ''));
$dateTo    = trim(strip_tags($_GET['date_to']  ?? ''));
$sort      = trim(strip_tags($_GET['sort']     ?? 'date'));
$perPage   = 20;
$page      = max(1, (int)($_GET['page'] ?? 1));

$allBarangays = []; // locked — barangay pre-selected from hub

$tab = trim(strip_tags($_GET['tab'] ?? 'data')); // data | manual

// Distinct categories
$categories = [];
try {
    $categories = $db->query("SELECT DISTINCT report_type FROM reports WHERE report_type IS NOT NULL AND report_type != '' ORDER BY report_type")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

// ── Data Reports WHERE ────────────────────────────────────────
$where = "WHERE rp.barangay_id = " . (int)$brgFilter;
if ($statusF)   $where .= " AND rp.status = " . $db->quote($statusF);
if ($categoryF) $where .= " AND rp.report_type = " . $db->quote($categoryF);
if ($dateFrom)  $where .= " AND rp.created_at >= " . $db->quote($dateFrom . ' 00:00:00');
if ($dateTo)    $where .= " AND rp.created_at <= " . $db->quote($dateTo   . ' 23:59:59');
if ($search)    $where .= " AND (rp.notes LIKE " . $db->quote("%$search%") .
                          " OR rp.report_type LIKE " . $db->quote("%$search%") . ")";

$orderBy = match($sort) {
    'status'   => "rp.status ASC, rp.created_at DESC",
    default    => "rp.created_at DESC",
};

$totalDataCount = (int)$db->query("SELECT COUNT(*) FROM reports rp $where")->fetchColumn();

$reports = [];
if ($tab === 'data') {
    $totalCount = $totalDataCount;
    $totalPages = max(1, (int)ceil($totalCount / $perPage));
    $page       = min($page, $totalPages);
    $offset     = ($page - 1) * $perPage;
    $reports = $db->query("
        SELECT rp.id, rp.report_type, rp.month, rp.year, rp.notes,
               rp.status, rp.created_at,
               CONCAT(u.first_name,' ',u.last_name) AS submitted_by_name
        FROM reports rp
        LEFT JOIN users u ON u.id = rp.submitted_by
        $where
        ORDER BY $orderBy
        LIMIT $perPage OFFSET $offset
    ")->fetchAll();
}

// ── Manual Reports ────────────────────────────────────────────
$mrWhere = "WHERE mr.barangay_id = " . (int)$brgFilter;
if ($statusF)  $mrWhere .= " AND mr.status = " . $db->quote($statusF);
if ($dateFrom) $mrWhere .= " AND mr.created_at >= " . $db->quote($dateFrom . ' 00:00:00');
if ($dateTo)   $mrWhere .= " AND mr.created_at <= " . $db->quote($dateTo   . ' 23:59:59');
if ($search)   $mrWhere .= " AND (mr.report_title LIKE " . $db->quote("%$search%") .
                           " OR mr.report_type LIKE " . $db->quote("%$search%") . ")";

$totalManualCount = 0;
$manualReports    = [];
try {
    $totalManualCount = (int)$db->query("SELECT COUNT(*) FROM manual_reports mr $mrWhere")->fetchColumn();
    if ($tab === 'manual') {
        $totalCount = $totalManualCount;
        $totalPages = max(1, (int)ceil($totalCount / $perPage));
        $page       = min($page, $totalPages);
        $offset     = ($page - 1) * $perPage;
        $manualReports = $db->query("
            SELECT mr.id, mr.report_title, mr.report_type, mr.status, mr.sent_at, mr.created_at, mr.updated_at,
                   CONCAT(u.first_name,' ',u.last_name) AS author_name,
                   u.email AS author_email
            FROM manual_reports mr
            LEFT JOIN users u ON u.id = mr.created_by
            $mrWhere
            ORDER BY mr.created_at DESC
            LIMIT $perPage OFFSET $offset
        ")->fetchAll();
    }
} catch (Exception $e) {}

if ($tab !== 'manual') {
    $totalCount = $totalDataCount;
    $totalPages = max(1, (int)ceil($totalCount / $perPage));
    $page       = min($page, $totalPages);
    $offset     = ($page - 1) * $perPage;
}

$statusColors = [
    'draft'     => ['bg'=>'#f1f5f9','text'=>'#475569'],
    'submitted' => ['bg'=>'#dbeafe','text'=>'#1e40af'],
    'approved'  => ['bg'=>'#dcfce7','text'=>'#166534'],
    'archived'  => ['bg'=>'#fef3c7','text'=>'#92400e'],
    'rejected'  => ['bg'=>'#fee2e2','text'=>'#991b1b'],
    'done'      => ['bg'=>'#d1fae5','text'=>'#065f46'],
];

$pageTitle    = 'Barangay Reports';
$pageIcon     = 'bi-file-earmark-bar-graph-fill';
$pageSubtitle = 'Reports submitted by ' . htmlspecialchars($brgyRow['barangay_name']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favcon.png">
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=3.0">
    <style>
        @media print { .sidebar, .topbar, .no-print { display:none; } .main-content { margin:0; } }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/sidebar_super.php'; ?>
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<div class="main-content">

    <!-- Page Header -->
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <div>
            <nav aria-label="breadcrumb" class="mb-1">
                <ol class="breadcrumb mb-0" style="font-size:0.78rem;">
                    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/super_admin/barangay_records.php">Barangay Records</a></li>
                    <li class="breadcrumb-item active">Reports</li>
                </ol>
            </nav>
            <h4 class="fw-bold mb-0" style="color:#1a4f7a;">
                <i class="bi bi-file-earmark-bar-graph-fill me-2"></i><?= $pageTitle ?>
            </h4>
            <p class="text-muted small mb-0"><?= $pageSubtitle ?></p>
        </div>
        <div class="d-flex gap-2 no-print">
            <a href="<?= BASE_URL ?>/super_admin/export_br_reports.php?<?= http_build_query(array_filter(['search'=>$search,'barangay_id'=>$brgFilter?:null,'status'=>$statusF,'category'=>$categoryF?:null,'date_from'=>$dateFrom,'date_to'=>$dateTo])) ?>"
               class="btn btn-success btn-sm fw-semibold">
                <i class="bi bi-file-earmark-excel me-1"></i>Download CSV
            </a>

        </div>
    </div>

    <!-- Filters -->
    <div style="background:#fff;border-radius:14px;box-shadow:0 2px 12px rgba(0,0,0,0.06);border:1px solid #e0f0e4;padding:14px 18px;margin-bottom:14px;" class="no-print">
        <form method="GET" class="d-flex gap-2 flex-wrap align-items-center">
            <!-- Locked barangay label -->
            <a href="<?= BASE_URL ?>/super_admin/barangay_records.php?barangay_id=<?= $brgFilter ?>" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Back
            </a>
            <span style="background:#e0f2fe;color:#0369a1;padding:5px 14px;border-radius:20px;font-size:0.82rem;font-weight:700;">
                <i class="bi bi-geo-alt-fill me-1"></i><?= $brgyLabel ?>
            </span>
            <input type="hidden" name="barangay_id" value="<?= $brgFilter ?>">
            <select name="status" class="form-select form-select-sm" style="width:130px;" onchange="this.form.submit()">
                <option value="">All Status</option>
                <?php foreach (array_keys($statusColors) as $st): ?>
                    <option value="<?= $st ?>" <?= $statusF===$st?'selected':'' ?>><?= ucfirst($st) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (!empty($categories)): ?>
            <select name="category" class="form-select form-select-sm" style="width:155px;" onchange="this.form.submit()">
                <option value="">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= htmlspecialchars($cat) ?>" <?= $categoryF===$cat?'selected':'' ?>><?= htmlspecialchars($cat) ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <select name="sort" class="form-select form-select-sm" style="width:140px;" onchange="this.form.submit()">
                <option value="date"     <?= $sort==='date'    ?'selected':'' ?>>Sort: Date</option>
                <option value="barangay" <?= $sort==='barangay'?'selected':'' ?>>Sort: Barangay</option>
                <option value="status"   <?= $sort==='status'  ?'selected':'' ?>>Sort: Status</option>
            </select>
            <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
            <input type="date" name="date_from" class="form-control form-control-sm" style="width:140px;" value="<?= htmlspecialchars($dateFrom) ?>" title="From date">
            <input type="date" name="date_to"   class="form-control form-control-sm" style="width:140px;" value="<?= htmlspecialchars($dateTo)   ?>" title="To date">
            <input type="text" name="search" class="form-control form-control-sm" style="width:200px;"
                   placeholder="Search title, type..." value="<?= htmlspecialchars($search) ?>">
            <button class="btn btn-primary btn-sm px-3"><i class="bi bi-search me-1"></i>Search</button>
            <a href="?barangay_id=<?= $brgFilter ?>&tab=<?= $tab ?>" class="btn btn-outline-secondary btn-sm px-3"><i class="bi bi-x me-1"></i>Clear</a>
            <span class="ms-auto text-muted small"><?= number_format($totalCount) ?> report<?= $totalCount!==1?'s':'' ?></span>
        </form>
    </div>

    <!-- Info note -->
    <div class="alert alert-info py-2 px-3 no-print" style="font-size:0.82rem;border-radius:10px;">
        <i class="bi bi-info-circle-fill me-2"></i>
        This shows report <strong>submissions and status</strong> only. To view the full content of a report, go to
        <a href="<?= BASE_URL ?>/super_admin/reports.php" class="fw-semibold">Reports</a> in the main sidebar.
    </div>

    <!-- Tab switcher -->
    <div class="d-flex gap-2 mb-3">
        <a href="?barangay_id=<?= $brgFilter ?>&tab=data&<?= http_build_query(array_filter(['search'=>$search,'status'=>$statusF,'category'=>$categoryF,'date_from'=>$dateFrom,'date_to'=>$dateTo])) ?>"
           class="btn btn-sm <?= $tab==='data' ? 'btn-primary' : 'btn-outline-primary' ?> fw-semibold">
            <i class="bi bi-file-earmark-bar-graph me-1"></i>Data Reports
            <span class="badge <?= $tab==='data'?'bg-white text-primary':'bg-primary' ?> ms-1"><?= number_format($totalDataCount) ?></span>
        </a>
        <a href="?barangay_id=<?= $brgFilter ?>&tab=manual&<?= http_build_query(array_filter(['search'=>$search,'status'=>$statusF,'date_from'=>$dateFrom,'date_to'=>$dateTo])) ?>"
           class="btn btn-sm <?= $tab==='manual' ? 'btn-primary' : 'btn-outline-primary' ?> fw-semibold">
            <i class="bi bi-pencil-square me-1"></i>Manual Reports
            <span class="badge <?= $tab==='manual'?'bg-white text-primary':'bg-primary' ?> ms-1"><?= number_format($totalManualCount) ?></span>
        </a>
    </div>

    <!-- Table -->
    <?php if ($tab === 'data'): ?>
    <div style="background:#fff;border-radius:18px;box-shadow:0 2px 16px rgba(0,0,0,0.07);border:1px solid #e0f0e4;overflow:hidden;">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr style="background:#f4f7fa;border-bottom:2px solid #d0ecd6;">
                        <?php foreach (['#','Title','Barangay','Category','Status','Submitted By','Date Submitted','Last Updated'] as $th): ?>
                        <th class="py-3 px-3" style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;white-space:nowrap;"><?= $th ?></th>
                        <?php endforeach; ?>
                        <th class="py-3 px-3 no-print" style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">View</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($reports)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-5">
                        <i class="bi bi-inbox" style="font-size:2.5rem;opacity:0.3;display:block;margin-bottom:8px;"></i>
                        No reports found.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($reports as $i => $rp):
                        $rowNum   = $offset + $i + 1;
                        $sc       = $statusColors[$rp['status']] ?? ['bg'=>'#f1f5f9','text'=>'#64748b'];
                    ?>
                    <tr style="border-bottom:1px solid #f0f6fb;" onmouseover="this.style.background='#f8fbff'" onmouseout="this.style.background=''">
                        <td class="px-3 py-2 text-muted small" style="border:none;"><?= $rowNum ?></td>
                        <td class="px-3 py-2 fw-semibold" style="border:none;font-size:0.88rem;max-width:220px;">
                            <?= htmlspecialchars(ucfirst($rp['report_type'] ?? '—') . ($rp['month'] ? ' — ' . date('F', mktime(0,0,0,$rp['month'],1)) : '') . ($rp['year'] ? ' '.$rp['year'] : '')) ?>
                        </td>
                        <td class="px-3 py-2" style="border:none;">
                            <span style="background:#e0f2fe;color:#0369a1;padding:2px 8px;border-radius:20px;font-size:0.72rem;font-weight:600;">
                                <?= htmlspecialchars($rp['barangay_name'] ?? '—') ?>
                            </span>
                        </td>
                        <td class="px-3 py-2 small" style="border:none;"><?= htmlspecialchars(ucfirst($rp['report_type'] ?? '—')) ?></td>
                        <td class="px-3 py-2" style="border:none;">
                            <span style="background:<?= $sc['bg'] ?>;color:<?= $sc['text'] ?>;padding:2px 9px;border-radius:20px;font-size:0.72rem;font-weight:600;">
                                <?= ucfirst($rp['status'] ?? '—') ?>
                            </span>
                        </td>
                        <td class="px-3 py-2 small" style="border:none;"><?= htmlspecialchars($rp['submitted_by_name'] ?? '—') ?></td>
                        <td class="px-3 py-2 small text-nowrap text-muted" style="border:none;"><?= date('M d, Y', strtotime($rp['created_at'])) ?></td>
                        <td class="px-3 py-2 small text-nowrap text-muted" style="border:none;">—</td>
                        <td class="px-3 py-2 no-print" style="border:none;">
                            <button class="btn btn-xs btn-outline-info"
                                    onclick='viewReport(<?= htmlspecialchars(json_encode($rp), ENT_QUOTES) ?>)'
                                    title="View Details">
                                <i class="bi bi-eye"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; /* data tab */ ?>

    <?php if ($tab === 'manual'): ?>
    <div style="background:#fff;border-radius:18px;box-shadow:0 2px 16px rgba(0,0,0,0.07);border:1px solid #e0f0e4;overflow:hidden;">
        <div class="table-responsive">
            <table class="table mb-0 table-sm">
                <thead>
                    <tr style="background:#f4f7fa;border-bottom:2px solid #d0ecd6;">
                        <?php foreach (['#','Title','Type','Status','Author','Sent At','Date Created'] as $th): ?>
                        <th class="py-2 px-2" style="font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#3a7fa8;border:none;white-space:nowrap;"><?= $th ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($manualReports)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-5">
                        <i class="bi bi-inbox" style="font-size:2rem;opacity:0.3;display:block;margin-bottom:6px;"></i>
                        No manual reports found.
                    </td></tr>
                <?php else: ?>
                    <?php
                    $mrStatusColors = [
                        'draft' => ['bg'=>'#f1f5f9','text'=>'#475569'],
                        'sent'  => ['bg'=>'#dcfce7','text'=>'#166534'],
                    ];
                    foreach ($manualReports as $i => $mr):
                        $rowNum = $offset + $i + 1;
                        $sc     = $mrStatusColors[$mr['status']] ?? ['bg'=>'#f1f5f9','text'=>'#64748b'];
                    ?>
                    <tr style="border-bottom:1px solid #f0f6fb;" onmouseover="this.style.background='#f8fbff'" onmouseout="this.style.background=''">
                        <td class="px-2 py-1 text-muted" style="border:none;font-size:0.75rem;white-space:nowrap;"><?= $rowNum ?></td>
                        <td class="px-2 py-1 fw-semibold" style="border:none;font-size:0.82rem;max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                            title="<?= htmlspecialchars($mr['report_title']) ?>">
                            <?= htmlspecialchars($mr['report_title']) ?>
                        </td>
                        <td class="px-2 py-1 small" style="border:none;white-space:nowrap;"><?= htmlspecialchars($mr['report_type'] ?? '—') ?></td>
                        <td class="px-2 py-1" style="border:none;white-space:nowrap;">
                            <span style="background:<?= $sc['bg'] ?>;color:<?= $sc['text'] ?>;padding:1px 7px;border-radius:20px;font-size:0.68rem;font-weight:600;">
                                <?= ucfirst($mr['status']) ?>
                            </span>
                        </td>
                        <td class="px-2 py-1" style="border:none;white-space:nowrap;">
                            <div style="font-size:0.8rem;font-weight:600;"><?= htmlspecialchars($mr['author_name'] ?? '—') ?></div>
                            <?php if (!empty($mr['author_email'])): ?>
                            <div class="text-muted" style="font-size:0.7rem;"><?= htmlspecialchars($mr['author_email']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-2 py-1 small text-nowrap text-muted" style="border:none;">
                            <?= $mr['sent_at'] ? date('M d, Y h:i A', strtotime($mr['sent_at'])) : '—' ?>
                        </td>
                        <td class="px-2 py-1 small text-nowrap text-muted" style="border:none;">
                            <?= date('M d, Y', strtotime($mr['created_at'])) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; /* manual tab */ ?>

</div><!-- /main-content -->

<?php
$paginationBase  = '?' . http_build_query(array_filter([
    'tab'         => $tab,
    'search'      => $search,
    'barangay_id' => $brgFilter ?: null,
    'status'      => $statusF,
    'category'    => $tab === 'data' ? $categoryF : null,
    'date_from'   => $dateFrom,
    'date_to'     => $dateTo,
    'sort'        => $sort,
])) . '&';
$paginationLabel = 'report';
include __DIR__ . '/../includes/pagination.php';
?>

<!-- View Details Modal -->
<div class="modal fade" id="viewReportModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;overflow:hidden;">
            <div class="modal-header" style="background:linear-gradient(135deg,#dc2626,#ef4444);border:none;">
                <h5 class="modal-title text-white fw-bold"><i class="bi bi-file-earmark-bar-graph-fill me-2"></i>Report Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="row g-3">
                    <div class="col-12"><small class="text-muted d-block">Title</small><strong id="vrp_title"></strong></div>
                    <div class="col-md-6"><small class="text-muted d-block">Barangay</small><span id="vrp_brgy"></span></div>
                    <div class="col-md-6"><small class="text-muted d-block">Category</small><span id="vrp_cat"></span></div>
                    <div class="col-md-6"><small class="text-muted d-block">Status</small><span id="vrp_status"></span></div>
                    <div class="col-md-6"><small class="text-muted d-block">Submitted By</small><span id="vrp_by"></span></div>
                    <div class="col-md-6"><small class="text-muted d-block">Date Submitted</small><span id="vrp_created"></span></div>
                    <div class="col-md-6"><small class="text-muted d-block">Last Updated</small><span id="vrp_updated"></span></div>
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function viewReport(rp) {
    document.getElementById('vrp_title').textContent   = rp.title              || '—';
    document.getElementById('vrp_brgy').textContent    = rp.barangay_name      || '—';
    document.getElementById('vrp_cat').textContent     = rp.category           || '—';
    document.getElementById('vrp_status').textContent  = rp.status             || '—';
    document.getElementById('vrp_by').textContent      = rp.submitted_by_name  || '—';
    document.getElementById('vrp_created').textContent = rp.created_at         || '—';
    document.getElementById('vrp_updated').textContent = rp.updated_at         || '—';
    new bootstrap.Modal(document.getElementById('viewReportModal')).show();
}
</script>
</body>
</html>
