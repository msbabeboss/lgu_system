<?php
require_once __DIR__ . '/../config/config.php';
requireRole('barangay_admin');

$db  = Database::getInstance()->getConnection();
$u   = currentUser();
$bid = (int)$u['barangay_id'];

$search   = sanitize($_GET['search']    ?? '');
$dateFrom = sanitize($_GET['date_from'] ?? '');
$dateTo   = sanitize($_GET['date_to']   ?? '');
$userFilter = (int)($_GET['user_id'] ?? 0);

// Resolve date preset to actual from/to values
$activePreset = sanitize($_GET['date_preset'] ?? '');
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

// Audit = only CRUD/write actions (Who changed what?)
$auditActions = [
    'ADD_RESIDENT','EDIT_RESIDENT','ARCHIVE_RESIDENT','DELETE_RESIDENT',
    'ADD_HOUSEHOLD','EDIT_HOUSEHOLD','DELETE_HOUSEHOLD',
    'ADD_BLOTTER','EDIT_BLOTTER','DELETE_BLOTTER',
    'ISSUE_CERTIFICATE','DELETE_CERTIFICATE',
    'ADD_OFFICIAL','EDIT_OFFICIAL','ARCHIVE_OFFICIAL',
    'CHANGE_PASSWORD','RESTORE_ARCHIVE','PERMANENT_DELETE',
    'SAVE_REPORT','SUBMIT_REPORT','ADD_NOTICE','DELETE_NOTICE',
];
$placeholders = implode(',', array_fill(0, count($auditActions), '?'));

// 2-year retention: only show logs from last 2 years
$retentionDate = date('Y-m-d', strtotime('-2 years'));

$baseWhere = "WHERE u.barangay_id = $bid
              AND al.action IN ($placeholders)
              AND DATE(al.created_at) >= '$retentionDate'";

if ($search)     $baseWhere .= " AND (al.action LIKE '%$search%' OR al.description LIKE '%$search%' OR u.email LIKE '%$search%' OR u.username LIKE '%$search%' OR u.first_name LIKE '%$search%' OR u.last_name LIKE '%$search%' OR CONCAT(u.first_name,' ',u.last_name) LIKE '%$search%' OR CONCAT(u.last_name,' ',u.first_name) LIKE '%$search%' OR CONCAT(u.last_name,', ',u.first_name) LIKE '%$search%')";
if ($dateFrom)   $baseWhere .= " AND DATE(al.created_at) >= '$dateFrom'";
if ($dateTo)     $baseWhere .= " AND DATE(al.created_at) <= '$dateTo'";
if ($userFilter) $baseWhere .= " AND al.user_id = $userFilter";

// Pagination
$perPage     = 10;
$auditPage = max(1, (int)($_GET['page'] ?? 1));

$countStmt = $db->prepare("
    SELECT COUNT(*)
    FROM activity_logs al
    LEFT JOIN users u ON al.user_id = u.id
    $baseWhere
");
$countStmt->execute($auditActions);
$totalRows  = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$auditPage = min($auditPage, $totalPages);
$offset = ($auditPage - 1) * $perPage;

$stmt = $db->prepare("
    SELECT al.id, al.action, al.description, al.created_at,
           u.first_name, u.last_name, u.username, u.email
    FROM activity_logs al
    LEFT JOIN users u ON al.user_id = u.id
    $baseWhere
    ORDER BY al.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($auditActions);
$logs = $stmt->fetchAll();

// Users in this barangay for filter dropdown
$barangayUsers = $db->query("
    SELECT DISTINCT u.id, u.first_name, u.last_name, u.username
    FROM activity_logs al
    LEFT JOIN users u ON al.user_id = u.id
    WHERE u.barangay_id = $bid AND u.role = 'barangay_admin'
    ORDER BY u.first_name
")->fetchAll();

$actionColors = [
    'LOGIN'              => 'success',
    'LOGOUT'             => 'secondary',
    'ADD_RESIDENT'       => 'primary',
    'EDIT_RESIDENT'      => 'info',
    'ARCHIVE_RESIDENT'   => 'warning',
    'DELETE_RESIDENT'    => 'danger',
    'ADD_HOUSEHOLD'      => 'primary',
    'EDIT_HOUSEHOLD'     => 'info',
    'DELETE_HOUSEHOLD'   => 'danger',
    'ADD_BLOTTER'        => 'danger',
    'EDIT_BLOTTER'       => 'warning',
    'DELETE_BLOTTER'     => 'danger',
    'ISSUE_CERTIFICATE'  => 'success',
    'DELETE_CERTIFICATE' => 'danger',
    'ADD_OFFICIAL'       => 'primary',
    'EDIT_OFFICIAL'      => 'info',
    'ARCHIVE_OFFICIAL'   => 'warning',
    'CHANGE_PASSWORD'    => 'warning',
    'RESTORE_ARCHIVE'    => 'success',
    'PERMANENT_DELETE'   => 'danger',
    'SAVE_REPORT'        => 'info',
    'SUBMIT_REPORT'      => 'success',
    'ADD_NOTICE'         => 'primary',
    'DELETE_NOTICE'      => 'danger',
];

$pageTitle    = 'Audit Log';
$pageIcon     = 'bi-shield-lock-fill';
$pageSubtitle = 'Detailed log of all system events and data changes.';
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
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=3.2">
    <style>
        .audit-pagination {
            display: flex;
            justify-content: center;
            padding: 12px;
            background: #f1f6fb;
            border-top: 1px solid #e2e8f0;
        }
        .audit-pagination .page-link {
            border-radius: 6px !important;
            margin: 0 1px;
            min-width: 30px;
            text-align: center;
            font-size: 0.78rem;
            padding: 3px 8px;
        }
        .audit-pagination .page-item.disabled .page-link {
            background: #e9ecef;
            border-color: #dee2e6;
            color: #6c757d;
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/sidebar_barangay.php'; ?>
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<div class="main-content">

    <a href="<?= BASE_URL ?>/barangay_admin/settings.php" class="btn btn-sm btn-outline-secondary mb-3" style="border-radius:8px;">
        <i class="bi bi-arrow-left me-1"></i> Back to Settings
    </a>

    <!-- Filters -->
    <div class="card mb-3">
        <div class="card-body py-2">
            <form method="GET" class="d-flex gap-2 flex-wrap align-items-center" id="filterForm">
                <div class="input-group input-group-sm ac-wrapper" style="width:280px;">
                    <span class="input-group-text bg-white border-end-0" style="border-color:#dee2e6;">
                        <i class="bi bi-search text-muted" style="font-size:0.8rem;"></i>
                    </span>
                    <input type="text" id="auditSearchInput" name="search" class="form-control form-control-sm border-start-0 border-end-0 ps-0"
                           style="border-color:#dee2e6;"
                           placeholder="Name, email, action, description..."
                           autocomplete="off" value="<?= htmlspecialchars($search) ?>">
                    <span class="input-group-text bg-white border-start-0 p-0" style="border-color:#dee2e6;">
                        <span id="auditSearchClear" title="Clear search"
                              style="width:28px;height:28px;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;color:#9ca3af;border-radius:4px;<?= $search ? '' : 'visibility:hidden;' ?>"
                              onmouseover="this.style.color='#dc2626';this.style.background='#fee2e2';"
                              onmouseout="this.style.color='#9ca3af';this.style.background='transparent';">
                            <i class="bi bi-x-lg" style="font-size:0.75rem;"></i>
                        </span>
                    </span>
                    <div class="ac-list" id="auditAcList"></div>
                </div>
                <div>
                    <select name="user_id" class="form-select form-select-sm" style="min-width:160px;">
                        <option value="">All Users</option>
                        <?php foreach ($barangayUsers as $usr): ?>
                            <option value="<?= $usr['id'] ?>" <?= $userFilter==$usr['id']?'selected':'' ?>>
                                <?= htmlspecialchars($usr['first_name'].' '.$usr['last_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <?php
                    $drpId = 'audit'; $drpDateFrom = $dateFrom; $drpDateTo = $dateTo;
                    $drpPreset = $activePreset; $drpMinDate = $retentionDate; $drpMaxDate = date('Y-m-d');
                    include __DIR__ . '/../includes/date_range_picker.php';
                    ?>
                    <?php if ($search || $userFilter || $dateFrom || $dateTo): ?>
                    <a href="?" class="btn btn-sm btn-outline-secondary px-2" title="Clear filters">
                        <i class="bi bi-x-lg"></i>
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
                    ?>
                    <tr>
                        <td class="ps-4 py-3">
                            <code style="font-size:0.7rem;color:#94a3b8;">#<?= $log['id'] ?></code>
                        </td>
                        <td class="py-3">
                            <div class="d-flex align-items-center gap-2">
                                <div style="width:34px;height:34px;min-width:34px;background:#e2e8f0;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#475569;font-size:0.68rem;font-weight:700;">
                                    <?= $initials ?>
                                </div>
                                <div>
                                    <div style="font-weight:600;font-size:0.82rem;"><?= htmlspecialchars($fullName) ?></div>
                                    <?php if (!empty($log['email'])): ?>
                                    <div style="font-size:0.7rem;color:#64748b;"><?= htmlspecialchars($log['email']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td class="py-3" style="white-space:nowrap;">
                            <span style="font-size:0.72rem;font-weight:600;color:#1a4f7a;background:#e8f0fe;padding:3px 10px;border-radius:6px;letter-spacing:0.03em;font-family:monospace;">
                                <?= htmlspecialchars($log['action']) ?>
                            </span>
                        </td>
                        <td class="py-3" style="max-width:320px;font-size:0.8rem;color:#334155;">
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
        <!-- Pagination -->
    <?php
        $qp = $_GET; unset($qp['page']);
        $bq = http_build_query($qp);
        $bu = '?' . ($bq ? $bq . '&' : '');
        $pageStart = max(1, $auditPage - 1);
        $pageEnd = min($totalPages, $pageStart + 2);
        $pageStart = max(1, $pageEnd - 2);
    ?>
        <nav class="audit-pagination table-pagination mt-3" aria-label="Audit log table pagination">
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item <?= $auditPage <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= htmlspecialchars($bu . 'page=' . max(1, $auditPage - 1), ENT_QUOTES, 'UTF-8') ?>#logs-table" aria-label="Previous page"><i class="bi bi-chevron-left"></i></a>
                </li>
                <?php for ($pageNumber = $pageStart; $pageNumber <= $pageEnd; $pageNumber++): ?>
                <li class="page-item <?= $pageNumber === $auditPage ? 'active' : '' ?>">
                    <a class="page-link" href="<?= htmlspecialchars($bu . 'page=' . $pageNumber, ENT_QUOTES, 'UTF-8') ?>#logs-table" <?= $pageNumber === $auditPage ? 'aria-current="page"' : '' ?>><?= $pageNumber ?></a>
                </li>
                <?php endfor; ?>
                <li class="page-item <?= $auditPage >= $totalPages ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= htmlspecialchars($bu . 'page=' . min($totalPages, $auditPage + 1), ENT_QUOTES, 'UTF-8') ?>#logs-table" aria-label="Next page"><i class="bi bi-chevron-right"></i></a>
                </li>
            </ul>
        </nav>
    </div>

</div><!-- /.main-content -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('show'); }
</script>

<script src="<?= BASE_URL ?>/assets/js/autocomplete.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    initAutocomplete('auditSearchInput', 'auditAcList', '<?= BASE_URL ?>/ajax/search_audit_log.php', 'auditSearchClear');
});
</script>
</body>
</html>
