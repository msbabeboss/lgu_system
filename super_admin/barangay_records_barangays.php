<?php
require_once __DIR__ . '/../config/config.php';
requireRole('super_admin');

$db = Database::getInstance()->getConnection();
$u  = currentUser();

// Filters
$search       = trim(strip_tags($_GET['search'] ?? ''));
$statusFilter = trim(strip_tags($_GET['status'] ?? ''));
$sort         = trim(strip_tags($_GET['sort']   ?? 'name'));
$perPage      = 20;
$page         = max(1, (int)($_GET['page'] ?? 1));

// Build WHERE
$where = "WHERE 1=1";
if ($search)       $where .= " AND (b.barangay_name LIKE " . $db->quote("%$search%") . " OR b.municipality LIKE " . $db->quote("%$search%") . ")";
if ($statusFilter) $where .= " AND b.status = " . $db->quote($statusFilter);

// Sort
$orderBy = match($sort) {
    'municipality' => "b.municipality ASC, b.barangay_name ASC",
    'date'         => "b.created_at DESC",
    default        => "b.barangay_name ASC",
};

$totalCount = (int)$db->query("SELECT COUNT(*) FROM barangays b $where")->fetchColumn();
$totalPages = max(1, (int)ceil($totalCount / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$barangays = $db->query("
    SELECT b.*,
        (SELECT COUNT(*) FROM residents r    WHERE r.barangay_id=b.id AND r.resident_status='Active') AS total_residents,
        (SELECT COUNT(*) FROM households h   WHERE h.barangay_id=b.id)                                AS total_households,
        (SELECT COUNT(*) FROM officials o    WHERE o.barangay_id=b.id AND o.status='active')          AS total_officials,
        (SELECT COUNT(*) FROM certificates c WHERE c.barangay_id=b.id)                                AS total_certs,
        (SELECT o2.full_name FROM officials o2
         WHERE o2.barangay_id=b.id AND o2.position='Barangay Captain' AND o2.status='active'
         ORDER BY o2.term_start DESC LIMIT 1)                                                         AS captain_name
    FROM barangays b
    $where
    ORDER BY $orderBy
    LIMIT $perPage OFFSET $offset
")->fetchAll();

$pageTitle    = 'Barangay List';
$pageIcon     = 'bi-geo-alt-fill';
$pageSubtitle = 'All registered barangays with summary counts.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favcon.png">
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=2.1">
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
                    <li class="breadcrumb-item active">Barangay List</li>
                </ol>
            </nav>
            <h4 class="fw-bold mb-0" style="color:#1a4f7a;">
                <i class="bi bi-geo-alt-fill me-2"></i><?= $pageTitle ?>
            </h4>
            <p class="text-muted small mb-0"><?= $pageSubtitle ?></p>
        </div>
        <div class="d-flex gap-2 no-print">
            <a href="<?= BASE_URL ?>/super_admin/export_br_barangays.php?<?= http_build_query(array_filter(['search'=>$search,'status'=>$statusFilter])) ?>"
               class="btn btn-success btn-sm fw-semibold">
                <i class="bi bi-file-earmark-excel me-1"></i>Export CSV
            </a>
            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-printer me-1"></i>Print
            </button>
        </div>
    </div>

    <!-- Filters -->
    <div style="background:#fff;border-radius:14px;box-shadow:0 2px 12px rgba(0,0,0,0.06);border:1px solid #e0f0e4;padding:14px 18px;margin-bottom:14px;" class="no-print">
        <form method="GET" class="d-flex gap-2 flex-wrap align-items-center">
            <input type="text" name="search" class="form-control form-control-sm" style="width:220px;"
                   placeholder="Search name or municipality..." value="<?= htmlspecialchars($search) ?>">
            <select name="status" class="form-select form-select-sm" style="width:140px;" onchange="this.form.submit()">
                <option value="">All Status</option>
                <option value="active"   <?= $statusFilter==='active'  ?'selected':'' ?>>Active</option>
                <option value="inactive" <?= $statusFilter==='inactive'?'selected':'' ?>>Inactive</option>
            </select>
            <select name="sort" class="form-select form-select-sm" style="width:150px;" onchange="this.form.submit()">
                <option value="name"         <?= $sort==='name'        ?'selected':'' ?>>Sort: Name</option>
                <option value="municipality" <?= $sort==='municipality'?'selected':'' ?>>Sort: Municipality</option>
                <option value="date"         <?= $sort==='date'        ?'selected':'' ?>>Sort: Date</option>
            </select>
            <button class="btn btn-primary btn-sm px-3"><i class="bi bi-search me-1"></i>Search</button>
            <a href="?" class="btn btn-outline-secondary btn-sm px-3"><i class="bi bi-x me-1"></i>Clear</a>
            <span class="ms-auto text-muted small"><?= number_format($totalCount) ?> barangay<?= $totalCount!==1?'s':'' ?></span>
        </form>
    </div>

    <!-- Table -->
    <div style="background:#fff;border-radius:18px;box-shadow:0 2px 16px rgba(0,0,0,0.07);border:1px solid #e0f0e4;overflow:hidden;">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr style="background:#f4f7fa;border-bottom:2px solid #d0ecd6;">
                        <?php foreach (['#','Barangay Name','Municipality','Province','Status','Residents','Households','Officials','Certs','Captain','Registered'] as $th): ?>
                        <th class="py-3 px-3" style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;white-space:nowrap;"><?= $th ?></th>
                        <?php endforeach; ?>
                        <th class="py-3 px-3 no-print" style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">Details</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($barangays)): ?>
                    <tr><td colspan="12" class="text-center text-muted py-5">
                        <i class="bi bi-inbox" style="font-size:2.5rem;opacity:0.3;display:block;margin-bottom:8px;"></i>
                        No barangays found.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($barangays as $i => $b):
                        $rowNum = $offset + $i + 1;
                        $statusBg   = $b['status']==='active' ? '#dcfce7' : '#f1f5f9';
                        $statusTxt  = $b['status']==='active' ? '#166534' : '#475569';
                    ?>
                    <tr style="border-bottom:1px solid #f0f6fb;" onmouseover="this.style.background='#f8fbff'" onmouseout="this.style.background=''">
                        <td class="px-3 py-2 text-muted small" style="border:none;"><?= $rowNum ?></td>
                        <td class="px-3 py-2 fw-semibold" style="border:none;font-size:0.88rem;"><?= htmlspecialchars($b['barangay_name']) ?></td>
                        <td class="px-3 py-2 small" style="border:none;"><?= htmlspecialchars($b['municipality']) ?></td>
                        <td class="px-3 py-2 small" style="border:none;"><?= htmlspecialchars($b['province'] ?? '—') ?></td>
                        <td class="px-3 py-2" style="border:none;">
                            <span style="background:<?= $statusBg ?>;color:<?= $statusTxt ?>;padding:2px 9px;border-radius:20px;font-size:0.72rem;font-weight:600;">
                                <?= ucfirst($b['status']) ?>
                            </span>
                        </td>
                        <td class="px-3 py-2 small text-center" style="border:none;"><?= number_format($b['total_residents']) ?></td>
                        <td class="px-3 py-2 small text-center" style="border:none;"><?= number_format($b['total_households']) ?></td>
                        <td class="px-3 py-2 small text-center" style="border:none;"><?= number_format($b['total_officials']) ?></td>
                        <td class="px-3 py-2 small text-center" style="border:none;"><?= number_format($b['total_certs']) ?></td>
                        <td class="px-3 py-2 small" style="border:none;"><?= htmlspecialchars($b['captain_name'] ?? '—') ?></td>
                        <td class="px-3 py-2 small text-nowrap text-muted" style="border:none;"><?= date('M d, Y', strtotime($b['created_at'])) ?></td>
                        <td class="px-3 py-2 no-print" style="border:none;">
                            <button class="btn btn-xs btn-outline-info"
                                    onclick='viewBrgy(<?= htmlspecialchars(json_encode($b), ENT_QUOTES) ?>)'
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

</div><!-- /main-content -->

<?php
$paginationBase  = '?' . http_build_query(array_filter(['search'=>$search,'status'=>$statusFilter,'sort'=>$sort])) . '&';
$paginationLabel = 'barangay';
include __DIR__ . '/../includes/pagination.php';
?>

<!-- View Details Modal -->
<div class="modal fade" id="viewBrgyModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;overflow:hidden;">
            <div class="modal-header" style="background:linear-gradient(135deg,#0369a1,#0891b2);border:none;">
                <h5 class="modal-title text-white fw-bold"><i class="bi bi-geo-alt-fill me-2"></i>Barangay Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="row g-3">
                    <div class="col-md-6"><small class="text-muted d-block">Barangay Name</small><strong id="vb_name"></strong></div>
                    <div class="col-md-6"><small class="text-muted d-block">Municipality</small><span id="vb_muni"></span></div>
                    <div class="col-md-6"><small class="text-muted d-block">Province</small><span id="vb_province"></span></div>
                    <div class="col-md-6"><small class="text-muted d-block">Region</small><span id="vb_region"></span></div>
                    <div class="col-md-6"><small class="text-muted d-block">ZIP Code</small><span id="vb_zip"></span></div>
                    <div class="col-md-6"><small class="text-muted d-block">Status</small><span id="vb_status"></span></div>
                    <div class="col-md-6"><small class="text-muted d-block">Captain</small><span id="vb_captain"></span></div>
                    <div class="col-md-6"><small class="text-muted d-block">Date Registered</small><span id="vb_created"></span></div>
                    <div class="col-md-3"><small class="text-muted d-block">Residents</small><strong id="vb_res"></strong></div>
                    <div class="col-md-3"><small class="text-muted d-block">Households</small><strong id="vb_hh"></strong></div>
                    <div class="col-md-3"><small class="text-muted d-block">Officials</small><strong id="vb_off"></strong></div>
                    <div class="col-md-3"><small class="text-muted d-block">Certificates</small><strong id="vb_cert"></strong></div>
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
function viewBrgy(b) {
    document.getElementById('vb_name').textContent    = b.barangay_name || '—';
    document.getElementById('vb_muni').textContent    = b.municipality  || '—';
    document.getElementById('vb_province').textContent = b.province     || '—';
    document.getElementById('vb_region').textContent  = b.region        || '—';
    document.getElementById('vb_zip').textContent     = b.zip_code      || '—';
    document.getElementById('vb_status').textContent  = b.status        || '—';
    document.getElementById('vb_captain').textContent = b.captain_name  || '—';
    document.getElementById('vb_created').textContent = b.created_at    || '—';
    document.getElementById('vb_res').textContent     = b.total_residents  || '0';
    document.getElementById('vb_hh').textContent      = b.total_households || '0';
    document.getElementById('vb_off').textContent     = b.total_officials  || '0';
    document.getElementById('vb_cert').textContent    = b.total_certs      || '0';
    new bootstrap.Modal(document.getElementById('viewBrgyModal')).show();
}
</script>
</body>
</html>
