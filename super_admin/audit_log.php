<?php
require_once __DIR__ . '/../config/config.php';
requireRole('super_admin');

$db = Database::getInstance()->getConnection();
$u  = currentUser();

$search     = sanitize($_GET['search']    ?? '');
$dateFrom   = sanitize($_GET['date_from'] ?? '');
$dateTo     = sanitize($_GET['date_to']   ?? '');
$userFilter = (int)($_GET['user_id'] ?? 0);

// Audit = CRUD/write actions only (Who changed what?)
$auditActions = [
    'ADD_USER','EDIT_USER','ARCHIVE_USER','DELETE_USER','BLOCK_USER',
    'RESET_PASSWORD','CHANGE_PASSWORD',
    'ADD_BARANGAY','EDIT_BARANGAY','DELETE_BARANGAY',
    'ADD_NOTICE','DELETE_NOTICE','EDIT_NOTICE',
    'ADD_REPORT','SUBMIT_REPORT','DELETE_REPORT',
    'ADD_RESIDENT','EDIT_RESIDENT','ARCHIVE_RESIDENT','DELETE_RESIDENT',
    'ADD_HOUSEHOLD','EDIT_HOUSEHOLD','DELETE_HOUSEHOLD',
    'ADD_BLOTTER','EDIT_BLOTTER','DELETE_BLOTTER',
    'ISSUE_CERTIFICATE','DELETE_CERTIFICATE',
    'RESTORE_ARCHIVE','PERMANENT_DELETE',
];
$placeholders = implode(',', array_fill(0, count($auditActions), '?'));

// 2-year retention
$retentionDate = date('Y-m-d', strtotime('-2 years'));

// Audit log for super admin shows only super_admin actions (not barangay admin actions)
$baseWhere = "WHERE u.role = 'super_admin'
              AND al.action IN ($placeholders)
              AND DATE(al.created_at) >= '$retentionDate'";

if ($search)     $baseWhere .= " AND (al.action LIKE '%$search%' OR al.description LIKE '%$search%' OR u.email LIKE '%$search%' OR u.username LIKE '%$search%' OR u.first_name LIKE '%$search%' OR u.last_name LIKE '%$search%' OR CONCAT(u.first_name,' ',u.last_name) LIKE '%$search%' OR CONCAT(u.last_name,' ',u.first_name) LIKE '%$search%' OR CONCAT(u.last_name,', ',u.first_name) LIKE '%$search%')";
if ($dateFrom)   $baseWhere .= " AND DATE(al.created_at) >= '$dateFrom'";
if ($dateTo)     $baseWhere .= " AND DATE(al.created_at) <= '$dateTo'";
if ($userFilter) $baseWhere .= " AND al.user_id = $userFilter";

// Pagination
$perPage     = 10;
$currentPage = max(1, (int)($_GET['page'] ?? 1));

$countStmt = $db->prepare("
    SELECT COUNT(*)
    FROM activity_logs al
    LEFT JOIN users u ON al.user_id = u.id
    $baseWhere
");
$countStmt->execute($auditActions);
$totalRows   = (int)$countStmt->fetchColumn();
$totalPages  = max(1, (int)ceil($totalRows / $perPage));
$currentPage = min($currentPage, $totalPages);
$offset      = ($currentPage - 1) * $perPage;

$logStmt = $db->prepare("
    SELECT al.id, al.action, al.description, al.created_at,
           u.first_name, u.last_name, u.username, u.email, u.role
    FROM activity_logs al
    LEFT JOIN users u ON al.user_id = u.id
    $baseWhere
    ORDER BY al.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$logStmt->execute($auditActions);
$logs = $logStmt->fetchAll();

// All super_admin users for filter dropdown
$users = $db->query("
    SELECT DISTINCT u.id, u.first_name, u.last_name, u.username, u.role
    FROM activity_logs al
    LEFT JOIN users u ON al.user_id = u.id
    WHERE u.id IS NOT NULL AND u.role = 'super_admin'
    ORDER BY u.first_name
")->fetchAll();

$actionColors = [];  // no longer used — all actions rendered plain

$roleLabels = [
    'super_admin'    => ['label' => 'Super Admin', 'color' => '#1a4f7a'],
    'barangay_admin' => ['label' => 'Brgy Admin',  'color' => '#1a4f7a'],
];

$pageTitle = 'Audit Log';
$pageIcon  = 'bi-shield-lock-fill';
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
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=3.0">
</head>
<body>
<?php include __DIR__ . '/../includes/sidebar_super.php'; ?>
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<div class="main-content">

    <a href="<?= BASE_URL ?>/super_admin/settings.php" class="btn btn-sm btn-outline-secondary mb-3">
        <i class="bi bi-arrow-left me-1"></i>Back to Settings
    </a>

    <!-- Filters -->
    <div class="card mb-3">
        <div class="card-body py-2">
            <form method="GET" class="d-flex gap-2 flex-wrap align-items-end" id="filterForm">
                <div>
                    <label class="form-label small fw-semibold mb-1">Search</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" name="search" class="form-control"
                               placeholder="Name, email, action, description..."
                               value="<?= htmlspecialchars($search) ?>">
                    </div>
                </div>
                <div>
                    <label class="form-label small fw-semibold mb-1">User</label>
                    <select name="user_id" class="form-select form-select-sm" style="min-width:160px;">
                        <option value="">All Users</option>
                        <?php foreach ($users as $usr): ?>
                            <option value="<?= $usr['id'] ?>" <?= $userFilter == $usr['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($usr['first_name'] . ' ' . $usr['last_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label small fw-semibold mb-1">From</label>
                    <input type="date" name="date_from" class="form-control form-control-sm"
                           value="<?= $dateFrom ?>" min="<?= $retentionDate ?>" max="<?= date('Y-m-d') ?>">
                </div>
                <div>
                    <label class="form-label small fw-semibold mb-1">To</label>
                    <input type="date" name="date_to" class="form-control form-control-sm"
                           value="<?= $dateTo ?>" min="<?= $retentionDate ?>" max="<?= date('Y-m-d') ?>">
                </div>
                <div class="d-flex gap-2 align-self-end">
                    <button type="submit" class="btn btn-sm btn-primary px-3">
                        <i class="bi bi-funnel me-1"></i>Filter
                    </button>
                    <?php if ($search || $userFilter || $dateFrom || $dateTo): ?>
                        <a href="?" class="btn btn-sm btn-outline-secondary px-3">
                            <i class="bi bi-x me-1"></i>Clear
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Table -->
    <div id="logs-table" class="card border-0 shadow-sm" style="border-radius:12px;overflow:hidden;">
        <div class="card-header bg-white d-flex align-items-center justify-content-between py-2 px-3 border-bottom">
            <span class="fw-semibold small">
                Audit Entries
                <span class="badge bg-secondary ms-1" style="font-size:0.7rem;"><?= number_format($totalRows) ?></span>
            </span>
            <span class="text-muted" style="font-size:0.72rem;">Showing CRUD operations only</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:0.85rem;">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4 py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;width:60px;">#</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;">User</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;">Action</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;">Description</th>
                        <th class="pe-4 py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;white-space:nowrap;">Date &amp; Time</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-5">
                            <i class="bi bi-shield-check" style="font-size:2.5rem;opacity:0.25;display:block;margin-bottom:10px;"></i>
                            No audit records found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $log):
                        $fullName = trim(($log['first_name'] ?? '') . ' ' . ($log['last_name'] ?? '')) ?: 'System';
                        $initials = strtoupper(substr($log['first_name'] ?? 'S', 0, 1) . substr($log['last_name'] ?? '', 0, 1));
                        $roleInfo = $roleLabels[$log['role'] ?? ''] ?? ['label' => 'User', 'color' => '#64748b'];
                    ?>
                    <tr>
                        <td class="ps-4 py-3">
                            <code style="font-size:0.7rem;color:#94a3b8;">#<?= $log['id'] ?></code>
                        </td>
                        <td class="py-3">
                            <div class="d-flex align-items-center gap-2">
                                <div style="width:34px;height:34px;min-width:34px;background:linear-gradient(135deg,#1a4f7a,#3a7fa8);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:0.68rem;font-weight:700;">
                                    <?= $initials ?>
                                </div>
                                <div>
                                    <div style="font-weight:600;font-size:0.82rem;color:#1a2e1f;"><?= htmlspecialchars($fullName) ?></div>
                                    <?php if (!empty($log['email'])): ?>
                                        <div style="font-size:0.7rem;color:#64748b;"><?= htmlspecialchars($log['email']) ?></div>
                                    <?php endif; ?>
                                    <div style="font-size:0.67rem;font-weight:600;color:<?= $roleInfo['color'] ?>"><?= htmlspecialchars($roleInfo['label']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="py-3" style="white-space:nowrap;">
                            <span style="font-size:0.72rem;font-weight:600;color:#1a4f7a;background:#e8f0fe;padding:3px 10px;border-radius:6px;letter-spacing:0.03em;font-family:monospace;">
                                <?= htmlspecialchars($log['action']) ?>
                            </span>
                        </td>
                        <td class="py-3" style="max-width:340px;font-size:0.8rem;color:#334155;">
                            <?= htmlspecialchars($log['description'] ?? '—') ?>
                        </td>
                        <td class="pe-4 py-3" style="white-space:nowrap;">
                            <div style="font-size:0.8rem;font-weight:600;"><?= date('M d, Y', strtotime($log['created_at'])) ?></div>
                            <div style="font-size:0.72rem;color:#94a3b8;"><?= date('h:i A', strtotime($log['created_at'])) ?></div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1):
        $qp = $_GET; unset($qp['page']);
        $bq = http_build_query($qp);
        $bu = '?' . ($bq ? $bq . '&' : '');
        $page = (int)$currentPage; $totalCount = (int)$totalRows;
        $paginationBase = $bu; $paginationLabel = 'record';
        include __DIR__ . '/../includes/pagination.php';
    endif; ?>

</div><!-- /.main-content -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('show'); }
</script>
</body>
</html>
