<?php
require_once __DIR__ . '/../config/config.php';
requireRole('super_admin');

$db = Database::getInstance()->getConnection();
$u  = currentUser();

// All types visible to super admin — LGU level only (no barangay-level records)
$superAdminTypes = ['users', 'barangays', 'notices', 'requests'];
$typeList        = implode("','", $superAdminTypes);

// ── POST Actions ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrAbort();
    $action    = $_POST['action']     ?? '';
    $archiveId = (int)($_POST['archive_id'] ?? 0);

    $stmt = $db->prepare("SELECT * FROM archives WHERE id = ? AND table_name IN ('$typeList')");
    $stmt->execute([$archiveId]);
    $archive = $stmt->fetch();

    if (!$archive) {
        flash('arch', 'Archive record not found.', 'danger');
        redirect(BASE_URL . '/super_admin/archive.php');
    }

    if ($action === 'restore') {
        $t  = $archive['table_name'];
        $rid = $archive['record_id'];

        if ($t === 'users') {
            $db->prepare("UPDATE users SET status='active' WHERE id=?")->execute([$rid]);
        } elseif ($t === 'barangays') {
            $db->prepare("UPDATE barangays SET status='active' WHERE id=?")->execute([$rid]);
        } elseif ($t === 'notices') {
            $db->prepare("UPDATE notices SET status='Active' WHERE id=?")->execute([$rid]);
        } elseif ($t === 'requests') {
            $db->prepare("UPDATE user_requests SET status='pending' WHERE id=?")->execute([$rid]);
        }

        $db->prepare("DELETE FROM archives WHERE id=?")->execute([$archiveId]);
        logActivity($u['id'], 'RESTORE_ARCHIVE', 'Archive', "Restored a $t record from archive");
        flash('arch', ucfirst($t) . ' record restored successfully.', 'success');
        redirect(BASE_URL . '/super_admin/archive.php');
    }
}

// ── Filters ───────────────────────────────────────────────
$search     = sanitize($_GET['search'] ?? '');
$typeFilter = sanitize($_GET['type']   ?? '');

$query = "SELECT a.*, u.first_name AS arch_by_first, u.last_name AS arch_by_last
          FROM archives a
          LEFT JOIN users u ON a.archived_by = u.id
          WHERE a.table_name IN ('$typeList')";
if ($search)                                          $query .= " AND (a.table_name LIKE '%$search%' OR a.data_json LIKE '%$search%')";
if ($typeFilter && in_array($typeFilter, $superAdminTypes)) $query .= " AND a.table_name = '$typeFilter'";
$query .= " ORDER BY a.archived_at DESC";

// Pagination
$perPage     = 10;
$archivePage = max(1, (int)($_GET['page'] ?? 1));
$countQuery  = "SELECT COUNT(*) FROM archives a WHERE a.table_name IN ('$typeList')";
if ($search)                                          $countQuery .= " AND (a.table_name LIKE '%$search%' OR a.data_json LIKE '%$search%')";
if ($typeFilter && in_array($typeFilter, $superAdminTypes)) $countQuery .= " AND a.table_name = '$typeFilter'";
$totalRows   = (int)$db->query($countQuery)->fetchColumn();
$totalPages  = max(1, (int)ceil($totalRows / $perPage));
$archivePage = min($archivePage, $totalPages);
$offset      = ($archivePage - 1) * $perPage;

$archives = $db->query($query . " LIMIT $perPage OFFSET $offset")->fetchAll();

$flash     = getFlash('arch');
$pageTitle = 'Archive';

// Badge colors per type
$typeBadge = [
    'users'     => 'primary',
    'barangays' => 'success',
    'notices'   => 'info',
    'requests'  => 'secondary',
];
?>

<?php
$pageIcon     = 'bi-archive-fill';
$pageTitle    = 'Archived Records';
$pageSubtitle = 'Inactive or removed records.';
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
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <style>
        .archive-pagination {
            display: flex; justify-content: center;
            padding: 12px; background: #f1f6fb;
            border-top: 1px solid #cbd5e1;
        }
        .pagination .page-link {
            border-radius: 6px !important; margin: 0 1px; min-width: 30px;
            text-align: center; font-size: 0.78rem; padding: 3px 8px;
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/sidebar_super.php'; ?>
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<form method="POST" id="archiveRestoreForm">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="restore">
    <input type="hidden" name="archive_id" id="restoreArchiveId">
</form>

<div class="modal fade" id="restoreArchiveModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;overflow:hidden;">
            <div class="d-flex">
                <div class="d-flex flex-column align-items-center justify-content-center px-4 py-5"
                     style="background:linear-gradient(160deg,#065f46 0%,#059669 100%);min-width:220px;width:220px;align-self:stretch;flex-shrink:0;">
                    <div style="width:80px;height:80px;background:rgba(255,255,255,0.15);border-radius:50%;display:flex;align-items:center;justify-content:center;border:3px solid rgba(255,255,255,0.3);">
                        <i class="bi bi-arrow-counterclockwise text-white" style="font-size:2.2rem;"></i>
                    </div>
                    <h5 class="fw-bold mt-3 mb-1 text-white text-center">Restore Record</h5>
                    <p class="text-white-50 small text-center mb-0">This action is reversible</p>
                </div>
                <div class="flex-fill d-flex flex-column justify-content-between p-4">
                    <div>
                        <p class="text-muted small mb-1">You are about to restore:</p>
                        <p class="fw-bold fs-6 mb-3" id="restoreRecordName" style="color:#065f46;"></p>
                        <div class="alert alert-success py-2 small mb-0">
                            <i class="bi bi-check-circle me-1"></i>
                            This record will be restored to its original state and become active again.
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-4">
                        <button type="button" class="btn btn-outline-secondary flex-fill" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn fw-semibold flex-fill" style="background:#059669;color:#fff;"
                                onclick="submitRestore()">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Confirm Restore
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
function openRestoreModal(archiveId, label) {
    document.getElementById('restoreArchiveId').value = archiveId;
    document.getElementById('restoreRecordName').textContent = label;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('restoreArchiveModal')).show();
}

function submitRestore() {
    bootstrap.Modal.getInstance(document.getElementById('restoreArchiveModal')).hide();
    document.getElementById('archiveRestoreForm').submit();
}
</script>

<div class="main-content">

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show">
            <?= $flash['message'] ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Filters -->
    <div style="background:#fff;border-radius:14px;box-shadow:0 2px 12px rgba(0,0,0,0.06);border:1px solid #e0f0e4;padding:16px 20px;margin-bottom:16px;">
        <form method="GET" class="d-flex gap-2 flex-wrap align-items-center" id="searchForm">
            <div class="input-group" style="width:260px;">
                <span class="input-group-text" style="background:#f8fafc;border-color:#e0f0e4;"><i class="bi bi-search" style="color:#3a7fa8;font-size:0.85rem;"></i></span>
                <input type="text" name="search" data-search-form="searchForm"
                       class="form-control form-control-sm" style="border-color:#e0f0e4;background:#f8fafc;"
                       placeholder="Search records..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <select name="type" class="form-select form-select-sm" style="width:160px;border-color:#e0f0e4;background:#f8fafc;">
                <option value="">All Categories</option>
                <option value="users"     <?= $typeFilter==='users'    ?'selected':'' ?>>Users</option>
                <option value="barangays" <?= $typeFilter==='barangays'?'selected':'' ?>>Barangays</option>
                <option value="notices"   <?= $typeFilter==='notices'  ?'selected':'' ?>>Notices</option>
                <option value="requests"  <?= $typeFilter==='requests' ?'selected':'' ?>>Requests</option>
            </select>
            <button class="btn btn-sm btn-primary px-3" style="border-radius:8px;">
                <i class="bi bi-funnel me-1"></i>Filter
            </button>
            <?php if ($search || $typeFilter): ?>
            <a href="?" class="btn btn-sm btn-outline-secondary px-3" style="border-radius:8px;">
                <i class="bi bi-x me-1"></i>Clear
            </a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Archive Table -->
    <div id="logs-table" style="background:#fff;border-radius:18px;box-shadow:0 2px 16px rgba(0,0,0,0.07);border:1px solid #e0f0e4;overflow:hidden;">

        <!-- Table -->
        <?php if (empty($archives)): ?>
        <div class="text-center text-muted py-5">
            <i class="bi bi-inbox" style="font-size:2.5rem;opacity:0.3;display:block;margin-bottom:10px;"></i>
            No archived records found.
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table mb-0" style="font-size:0.85rem;">
                <thead>
                    <tr style="border-bottom:1px solid #e8f4fb;">
                        <th class="ps-4 py-3" style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#3a7fa8;background:transparent;border:none;">User / Record</th>
                        <th class="py-3" style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#3a7fa8;background:transparent;border:none;">Category</th>
                        <th class="py-3" style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#3a7fa8;background:transparent;border:none;">Details</th>
                        <th class="py-3" style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#3a7fa8;background:transparent;border:none;">Archived By</th>
                        <th class="py-3" style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#3a7fa8;background:transparent;border:none;">Archive Date</th>
                        <th class="pe-4 py-3" style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#3a7fa8;background:transparent;border:none;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($archives as $a):
                    $data  = json_decode($a['data_json'], true) ?? [];
                    if ($a['table_name'] === 'users') {
                        $restoreLabel = trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''));
                    } elseif ($a['table_name'] === 'barangays') {
                        $restoreLabel = $data['barangay_name'] ?? '—';
                    } elseif ($a['table_name'] === 'notices') {
                        $restoreLabel = $data['title'] ?? '—';
                    } else {
                        $restoreLabel = $data['subject'] ?? ($data['title'] ?? 'Record #' . ($data['id'] ?? ''));
                    }
                    $typeColors = [
                        'users'     => ['bg'=>'rgba(26,79,122,0.1)',   'color'=>'#1a4f7a'],
                        'barangays' => ['bg'=>'rgba(5,150,105,0.1)',   'color'=>'#065f46'],
                        'notices'   => ['bg'=>'rgba(14,165,233,0.1)',  'color'=>'#0369a1'],
                        'requests'  => ['bg'=>'rgba(107,114,128,0.1)', 'color'=>'#374151'],
                    ];
                    $tc = $typeColors[$a['table_name']] ?? $typeColors['requests'];
                ?>
                    <tr style="border-bottom:1px solid #f8fafc;transition:background 0.15s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">

                        <!-- User / Record -->
                        <td class="ps-4 py-3" style="border:none;">
                            <?php if ($a['table_name'] === 'users'): ?>
                            <div class="d-flex align-items-center gap-3">
                                <div style="width:36px;height:36px;background:linear-gradient(135deg,<?= $data['role']==='super_admin'?'#06111e,#1a4f7a':'#3a7fa8,#60a5fa' ?>);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:0.72rem;font-weight:700;flex-shrink:0;">
                                    <?= strtoupper(substr($data['first_name']??'',0,1).substr($data['last_name']??'',0,1)) ?>
                                </div>
                                <div>
                                    <div style="font-weight:600;color:#1a2e1f;"><?= htmlspecialchars(trim(($data['first_name']??'').' '.($data['last_name']??''))) ?></div>
                                    <div style="font-size:0.72rem;color:#3a7fa8;"><?= htmlspecialchars($data['email']??'') ?></div>
                                </div>
                            </div>
                            <?php elseif ($a['table_name'] === 'barangays'): ?>
                            <div class="d-flex align-items-center gap-3">
                                <div style="width:36px;height:36px;background:linear-gradient(135deg,#065f46,#059669);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:0.8rem;flex-shrink:0;">
                                    <i class="bi bi-geo-alt-fill"></i>
                                </div>
                                <div>
                                    <div style="font-weight:600;color:#1a2e1f;"><?= htmlspecialchars($data['barangay_name']??'—') ?></div>
                                    <div style="font-size:0.72rem;color:#3a7fa8;"><?= htmlspecialchars($data['municipality']??'') ?></div>
                                </div>
                            </div>
                            <?php elseif ($a['table_name'] === 'notices'): ?>
                            <div class="d-flex align-items-center gap-3">
                                <div style="width:36px;height:36px;background:linear-gradient(135deg,#0369a1,#0ea5e9);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:0.8rem;flex-shrink:0;">
                                    <i class="bi bi-megaphone-fill"></i>
                                </div>
                                <div>
                                    <div style="font-weight:600;color:#1a2e1f;"><?= htmlspecialchars($data['title']??'—') ?></div>
                                    <div style="font-size:0.72rem;color:#3a7fa8;max-width:180px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;"><?= htmlspecialchars(substr($data['content']??'',0,60)) ?></div>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="d-flex align-items-center gap-3">
                                <div style="width:36px;height:36px;background:linear-gradient(135deg,#374151,#6b7280);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:0.8rem;flex-shrink:0;">
                                    <i class="bi bi-inbox-fill"></i>
                                </div>
                                <div>
                                    <div style="font-weight:600;color:#1a2e1f;"><?= htmlspecialchars($data['subject']??($data['title']??'—')) ?></div>
                                    <div style="font-size:0.72rem;color:#3a7fa8;"><?= htmlspecialchars($data['requester_name']??'') ?></div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </td>

                        <!-- Category -->
                        <td class="py-3" style="border:none;">
                            <span style="background:<?= $tc['bg'] ?>;color:<?= $tc['color'] ?>;font-size:0.72rem;font-weight:600;padding:4px 12px;border-radius:20px;">
                                <?= ucfirst($a['table_name']) ?>
                            </span>
                            <div style="font-size:0.7rem;color:#94a3b8;margin-top:3px;">ID #<?= $a['record_id'] ?></div>
                        </td>

                        <!-- Details -->
                        <td class="py-3" style="border:none;max-width:200px;">
                            <?php if ($a['table_name'] === 'users'): ?>
                                <div style="font-size:0.8rem;color:#2a5070;"><code style="font-size:0.75rem;"><?= htmlspecialchars($data['username']??'') ?></code></div>
                                <span style="background:<?= ($data['role']??'')==='super_admin'?'rgba(26,79,122,0.1)':'rgba(58,127,168,0.1)' ?>;color:<?= ($data['role']??'')==='super_admin'?'#1a4f7a':'#3a7fa8' ?>;font-size:0.68rem;font-weight:600;padding:2px 8px;border-radius:20px;">
                                    <?= ($data['role']??'')==='super_admin'?'Super Admin':'Brgy Admin' ?>
                                </span>
                            <?php elseif ($a['table_name'] === 'barangays'): ?>
                                <div style="font-size:0.8rem;color:#2a5070;"><?= htmlspecialchars($data['region']??'—') ?></div>
                                <div style="font-size:0.72rem;color:#94a3b8;"><?= htmlspecialchars($data['province']??'') ?></div>
                            <?php elseif ($a['table_name'] === 'notices'): ?>
                                <div style="font-size:0.72rem;color:#2a5070;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;"><?= htmlspecialchars(substr($data['content']??'',0,80)) ?></div>
                            <?php else: ?>
                                <span style="background:rgba(107,114,128,0.1);color:#374151;font-size:0.68rem;font-weight:600;padding:2px 8px;border-radius:20px;">
                                    <?= ucfirst(str_replace('_',' ',$data['request_type']??'Request')) ?>
                                </span>
                            <?php endif; ?>
                        </td>

                        <!-- Archived By -->
                        <td class="py-3" style="border:none;">
                            <div style="font-size:0.82rem;color:#2a5070;font-weight:500;">
                                <?= htmlspecialchars(trim(($a['arch_by_first']??'').' '.($a['arch_by_last']??'')) ?: 'System') ?>
                            </div>
                        </td>

                        <!-- Archive Date -->
                        <td class="py-3" style="border:none;">
                            <div style="font-size:0.8rem;color:#2a5070;"><?= date('M d, Y', strtotime($a['archived_at'])) ?></div>
                            <div style="font-size:0.72rem;color:#94a3b8;"><?= date('h:i A', strtotime($a['archived_at'])) ?></div>
                        </td>

                        <!-- Actions -->
                        <td class="pe-4 py-3" style="border:none;">
                            <button type="button" class="btn btn-sm"
                                    onclick="openRestoreModal(<?= (int)$a['id'] ?>, this.dataset.label)"
                                    data-label="<?= htmlspecialchars($restoreLabel, ENT_QUOTES, 'UTF-8') ?>"
                                    style="font-size:0.78rem;border-radius:8px;padding:4px 12px;background:#e0f2fb;color:#0d3a5c;border:1px solid #b8e8f0;">
                                <i class="bi bi-arrow-counterclockwise me-1"></i>Restore
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php
            $queryParams = $_GET;
            unset($queryParams['page']);
            $baseQuery = http_build_query($queryParams);
            $baseUrl = '?' . ($baseQuery ? $baseQuery . '&' : '');
            $pageStart = max(1, $archivePage - 2);
            $pageEnd = min($totalPages, $archivePage + 2);
        ?>
        <nav class="archive-pagination" aria-label="Archive table pagination">
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item <?= $archivePage <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link page-nav" href="<?= htmlspecialchars($baseUrl . 'page=' . max(1, $archivePage - 1), ENT_QUOTES, 'UTF-8') ?>#logs-table" aria-label="Previous page">&laquo;</a>
                </li>
                <?php for ($pageNumber = $pageStart; $pageNumber <= $pageEnd; $pageNumber++): ?>
                <li class="page-item <?= $pageNumber === $archivePage ? 'active' : '' ?>">
                    <a class="page-link page-nav" href="<?= htmlspecialchars($baseUrl . 'page=' . $pageNumber, ENT_QUOTES, 'UTF-8') ?>#logs-table" <?= $pageNumber === $archivePage ? 'aria-current="page"' : '' ?>><?= $pageNumber ?></a>
                </li>
                <?php endfor; ?>
                <li class="page-item <?= $archivePage >= $totalPages ? 'disabled' : '' ?>">
                    <a class="page-link page-nav" href="<?= htmlspecialchars($baseUrl . 'page=' . min($totalPages, $archivePage + 1), ENT_QUOTES, 'UTF-8') ?>#logs-table" aria-label="Next page">&raquo;</a>
                </li>
            </ul>
        </nav>
    </div>
</div>

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

function submitRestore() {
    bootstrap.Modal.getInstance(document.getElementById('restoreArchiveModal')).hide();
    document.getElementById('archiveRestoreForm').submit();
}
</script>
</body>
</html>
