<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/mailer.php';
requireRole('super_admin');

$db = Database::getInstance()->getConnection();
$u  = currentUser();

// ── Ensure user_requests table exists ──────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS user_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    requester_id INT UNSIGNED NOT NULL,
    barangay_id INT UNSIGNED,
    request_type ENUM('password_change','edit_information','new_user','access_request','username_change','reset_2fa') NOT NULL,
    subject VARCHAR(200) NOT NULL,
    details TEXT NOT NULL,
    status ENUM('pending','viewed','processing','approved','rejected','done','archived') DEFAULT 'pending',
    admin_notes TEXT NULL,
    reviewed_by INT UNSIGNED NULL,
    reviewed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (requester_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

try { $db->exec("ALTER TABLE user_requests MODIFY COLUMN status ENUM('pending','viewed','processing','approved','rejected','done','archived') DEFAULT 'pending'"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE user_requests MODIFY COLUMN request_type ENUM('password_change','edit_information','new_user','access_request','username_change','reset_2fa') NOT NULL"); } catch (PDOException $e) {}

// ── POST Actions ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrAbort();
    $action = $_POST['action'] ?? '';
    $reqId  = (int)($_POST['request_id'] ?? 0);

    // Mark as viewed (AJAX)
    if ($action === 'mark_viewed' && $reqId) {
        $db->prepare("UPDATE user_requests SET status='viewed', reviewed_by=?, reviewed_at=NOW() WHERE id=? AND status='pending'")
           ->execute([$u['id'], $reqId]);
        $req = $db->prepare("SELECT r.*, u.email as requester_email, u.username as requester_username, CONCAT(u.first_name,' ',u.last_name) as req_name FROM user_requests r LEFT JOIN users u ON r.requester_id=u.id WHERE r.id=?");
        $req->execute([$reqId]);
        $req = $req->fetch();
        if ($req) {
            sendNotification($req['requester_id'],
                "👁️ Your request \"{$req['subject']}\" has been viewed by the LGU. ({$req['requester_username']} · {$req['requester_email']})",
                'info', 'transaction');
            if ($req['requester_email']) {
                sendRequestStatusUpdateEmail($req['requester_email'], $req['req_name'],
                    $req['requester_username'], $req['subject'], 'viewed', '');
            }
        }
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'archive_request' && $reqId) {
        $row = $db->prepare("SELECT r.*, CONCAT(u.first_name,' ',u.last_name) as requester_name, b.barangay_name FROM user_requests r LEFT JOIN users u ON r.requester_id=u.id LEFT JOIN barangays b ON r.barangay_id=b.id WHERE r.id=?");
        $row->execute([$reqId]);
        $data = $row->fetch();
        if ($data) {
            $db->prepare("INSERT INTO archives (table_name,record_id,data_json,archived_by) VALUES ('requests',?,?,?)")
               ->execute([$reqId, json_encode($data), $u['id']]);
            $db->prepare("UPDATE user_requests SET status='archived' WHERE id=?")->execute([$reqId]);
            logActivity($u['id'], 'ARCHIVE_REQUEST', 'Requests', "Archived request: {$data['subject']}");
            flash('req', 'Request archived.', 'info');
        }
        redirect(BASE_URL . '/super_admin/requests.php');
    }

    if (in_array($action, ['approve','reject','processing','done']) && $reqId) {
        $statusMap = ['approve'=>'approved','reject'=>'rejected','processing'=>'processing','done'=>'done'];
        $newStatus = $statusMap[$action];
        $notes     = sanitize($_POST['admin_notes'] ?? '');

        $db->prepare("UPDATE user_requests SET status=?, admin_notes=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?")
           ->execute([$newStatus, $notes, $u['id'], $reqId]);

        $req = $db->prepare("SELECT r.*, u.email AS requester_email, u.username AS requester_username, CONCAT(u.first_name,' ',u.last_name) AS req_name FROM user_requests r LEFT JOIN users u ON r.requester_id = u.id WHERE r.id = ?");
        $req->execute([$reqId]);
        $req = $req->fetch();

        if ($req) {
            $msgs = [
                'approved'   => "✅ Your request \"{$req['subject']}\" has been approved by the LGU. ({$req['requester_username']} · {$req['requester_email']})",
                'rejected'   => "❌ Your request \"{$req['subject']}\" has been rejected by the LGU." . ($notes ? " Reason: $notes" : '') . " ({$req['requester_username']} · {$req['requester_email']})",
                'processing' => "⚙️ Your request \"{$req['subject']}\" is now being processed by the LGU. ({$req['requester_username']} · {$req['requester_email']})",
                'done'       => "🎉 Your request \"{$req['subject']}\" has been completed by the LGU. ({$req['requester_username']} · {$req['requester_email']})",
            ];
            $types = ['approved'=>'success','rejected'=>'danger','processing'=>'info','done'=>'success'];
            sendNotification($req['requester_id'], $msgs[$newStatus], $types[$newStatus], 'transaction');

            if ($req['requester_email']) {
                sendRequestStatusUpdateEmail($req['requester_email'], $req['req_name'],
                    $req['requester_username'], $req['subject'], $newStatus, $notes);
            }

            $emailNote = $req['requester_email']
                ? " Email sent to <strong>{$req['req_name']}</strong> ({$req['requester_username']}) &lt;{$req['requester_email']}&gt;."
                : " (No email on file for this user.)";
            sendNotification($u['id'],
                "✅ Request \"{$req['subject']}\" marked as " . ucfirst($newStatus) . ".{$emailNote}",
                'success', 'transaction');
        }

        logActivity($u['id'], strtoupper($action).'_REQUEST', 'Requests', "Request ID $reqId set to $newStatus");
        flash('req', "Request marked as " . ucfirst($newStatus) . ".", 'success');
        redirect(BASE_URL . '/super_admin/requests.php');
    }
}

// ── Auto-archive: requests older than 90 days ─────────────
$oldRequests = $db->query("
    SELECT r.*, CONCAT(u.first_name,' ',u.last_name) as requester_name, b.barangay_name
    FROM user_requests r
    LEFT JOIN users u ON r.requester_id = u.id
    LEFT JOIN barangays b ON r.barangay_id = b.id
    WHERE r.status NOT IN ('archived')
      AND r.created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)
")->fetchAll();
foreach ($oldRequests as $old) {
    $db->prepare("INSERT INTO archives (table_name,record_id,data_json,archived_by) VALUES ('requests',?,?,?)")
       ->execute([$old['id'], json_encode($old), null]);
    $db->prepare("UPDATE user_requests SET status='archived' WHERE id=?")->execute([$old['id']]);
}

// ── Filters ────────────────────────────────────────────────
$statusFilter = sanitize($_GET['status']       ?? '');
$typeFilter   = sanitize($_GET['request_type'] ?? '');
$brgyFilter   = (int)($_GET['barangay_id']     ?? 0);
$search       = sanitize($_GET['search']       ?? '');

$where = "WHERE 1=1";
if ($statusFilter) $where .= " AND r.status='$statusFilter'";
if ($typeFilter)   $where .= " AND r.request_type='$typeFilter'";
if ($brgyFilter)   $where .= " AND r.barangay_id=$brgyFilter";
if ($search)       $where .= " AND (r.subject LIKE '%$search%' OR r.details LIKE '%$search%'
    OR u.first_name LIKE '%$search%' OR u.last_name LIKE '%$search%'
    OR CONCAT(u.first_name,' ',u.last_name) LIKE '%$search%'
    OR CONCAT(u.last_name,', ',u.first_name) LIKE '%$search%')";
$where .= " AND r.status != 'archived'";

$requests = $db->query("
    SELECT r.*,
           CONCAT(u.first_name,' ',u.last_name) as requester_name,
           u.username,
           u.email as requester_email,
           b.barangay_name,
           CONCAT(rv.first_name,' ',rv.last_name) as reviewed_by_name
    FROM user_requests r
    LEFT JOIN users u  ON r.requester_id = u.id
    LEFT JOIN barangays b ON r.barangay_id = b.id
    LEFT JOIN users rv ON r.reviewed_by = rv.id
    $where
    ORDER BY r.created_at DESC
")->fetchAll();

$perPage    = 10;
$page       = max(1, (int)($_GET['page'] ?? 1));
$totalCount = count($requests);
$totalPages = max(1, (int)ceil($totalCount / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;
$requests   = array_slice($requests, $offset, $perPage);

$pendingCount = (int)$db->query("SELECT COUNT(*) FROM user_requests WHERE status='pending'")->fetchColumn();
$barangays    = $db->query("SELECT * FROM barangays WHERE status='active' ORDER BY barangay_name")->fetchAll();
$flash        = getFlash('req');

$typeLabels = [
    'password_change'  => ['label'=>'Password Change',  'icon'=>'bi-key-fill',        'color'=>'warning'],
    'edit_information' => ['label'=>'Edit Information', 'icon'=>'bi-pencil-square',    'color'=>'info'],
    'new_user'         => ['label'=>'New User',         'icon'=>'bi-person-plus-fill', 'color'=>'primary'],
    'access_request'   => ['label'=>'Access Request',   'icon'=>'bi-shield-check',     'color'=>'secondary'],
    'reset_2fa'        => ['label'=>'Reset 2FA',        'icon'=>'bi-shield-lock-fill', 'color'=>'danger'],
];
$statusColors = [
    'pending'    => 'warning',
    'viewed'     => 'info',
    'processing' => 'primary',
    'approved'   => 'success',
    'rejected'   => 'danger',
    'done'       => 'success',
];
$statusLabels = [
    'pending'    => 'Pending (Not Opened)',
    'viewed'     => 'Viewed',
    'processing' => 'Processing',
    'approved'   => 'Approved',
    'rejected'   => 'Rejected',
    'done'       => 'Done',
];

$pageIcon     = 'bi-inbox-fill';
$pageTitle    = 'Request Management';
$pageSubtitle = 'Manage barangay requests.';
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
    <style>.btn-xs { padding: 2px 8px; font-size: 0.75rem; }</style>
</head>
<body>
<?php include __DIR__ . '/../includes/sidebar_super.php'; ?>
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<div class="main-content">

    <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show">
        <?= $flash['message'] ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="alert alert-info d-flex gap-2 align-items-center py-2 mb-3 small" style="border-radius:10px;">
        <i class="bi bi-info-circle-fill flex-shrink-0"></i>
        <span>Requests older than <strong>3 months</strong> are automatically archived and can be found in the Archive section.</span>
    </div>

    <!-- Filters -->
    <div class="card mb-3">
        <div class="card-body py-2">
            <form method="GET" class="d-flex gap-2 flex-wrap align-items-center" id="searchForm">
                <div class="input-group" style="width:220px;">
                    <span class="input-group-text"><i class="bi bi-search" style="font-size:0.85rem;"></i></span>
                    <input type="text" name="search" data-search-form="searchForm"
                           class="form-control form-control-sm"
                           placeholder="Search subject, name..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <select name="request_type" class="form-select form-select-sm" style="width:160px;">
                    <option value="">All Types</option>
                    <?php foreach ($typeLabels as $t => $ti): ?>
                        <option value="<?= $t ?>" <?= $typeFilter===$t?'selected':'' ?>><?= $ti['label'] ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="status" class="form-select form-select-sm" style="width:150px;">
                    <option value="">All Status</option>
                    <option value="pending"    <?= $statusFilter==='pending'   ?'selected':'' ?>>Pending</option>
                    <option value="viewed"     <?= $statusFilter==='viewed'    ?'selected':'' ?>>Viewed</option>
                    <option value="processing" <?= $statusFilter==='processing'?'selected':'' ?>>Processing</option>
                    <option value="approved"   <?= $statusFilter==='approved'  ?'selected':'' ?>>Approved</option>
                    <option value="rejected"   <?= $statusFilter==='rejected'  ?'selected':'' ?>>Rejected</option>
                    <option value="done"       <?= $statusFilter==='done'      ?'selected':'' ?>>Done</option>
                </select>
                <select name="barangay_id" class="form-select form-select-sm" style="width:160px;">
                    <option value="">All Barangays</option>
                    <?php foreach ($barangays as $b): ?>
                        <option value="<?= $b['id'] ?>" <?= $brgyFilter==$b['id']?'selected':'' ?>><?= htmlspecialchars($b['barangay_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-sm btn-primary px-3"><i class="bi bi-funnel me-1"></i>Filter</button>
                <?php if ($search || $statusFilter || $typeFilter || $brgyFilter): ?>
                <a href="?" class="btn btn-sm btn-outline-secondary px-3"><i class="bi bi-x me-1"></i>Clear</a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Requests Table -->
    <div class="card border-0 shadow-sm" style="border-radius:12px;overflow:hidden;">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4 py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;">#</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;">Requester</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;">Email</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;">Barangay</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;">Type</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;">Subject</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;">Details</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;">Status</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;">Date</th>
                        <th class="pe-4 py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;width:130px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($requests)): ?>
                    <tr><td colspan="10" class="text-center text-muted py-5">
                        <i class="bi bi-inbox" style="font-size:2.5rem;opacity:0.3;display:block;margin-bottom:10px;"></i>
                        No requests found.
                    </td></tr>
                <?php else: ?>
                <?php foreach ($requests as $i => $req):
                    $ti        = $typeLabels[$req['request_type']] ?? ['label'=>$req['request_type'],'icon'=>'bi-question','color'=>'secondary'];
                    $isPending = $req['status'] === 'pending';
                ?>
                <tr>
                    <td class="ps-4 py-3">
                        <?= $offset+$i+1 ?>
                        <?php if ($isPending): ?>
                        <span class="badge bg-danger ms-1" style="font-size:0.6rem;">NEW</span>
                        <?php endif; ?>
                    </td>
                    <td class="py-3"><div class="fw-semibold small"><?= htmlspecialchars($req['requester_name']) ?></div></td>
                    <td class="py-3">
                        <?php if (!empty($req['requester_email'])): ?>
                        <a href="mailto:<?= htmlspecialchars($req['requester_email']) ?>" style="font-size:0.8rem;"><?= htmlspecialchars($req['requester_email']) ?></a>
                        <?php else: ?><span class="text-muted" style="font-size:0.8rem;">—</span><?php endif; ?>
                    </td>
                    <td class="py-3 small"><?= htmlspecialchars($req['barangay_name'] ?? '—') ?></td>
                    <td class="py-3">
                        <span class="badge bg-<?= $ti['color'] ?>">
                            <i class="bi <?= $ti['icon'] ?> me-1"></i><?= $ti['label'] ?>
                        </span>
                    </td>
                    <td class="py-3 fw-semibold small"><?= htmlspecialchars($req['subject']) ?></td>
                    <td class="py-3 small text-muted" style="max-width:200px;"><?= htmlspecialchars($req['details']) ?></td>
                    <td class="py-3">
                        <span class="badge bg-<?= $statusColors[$req['status']] ?? 'secondary' ?>">
                            <?= $statusLabels[$req['status']] ?? ucfirst(str_replace('_',' ',$req['status'])) ?>
                        </span>
                        <?php if ($req['reviewed_at']): ?>
                        <div class="text-muted" style="font-size:0.65rem;">by <?= htmlspecialchars($req['reviewed_by_name'] ?? '') ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="py-3 small text-nowrap">
                        <?= date('M d, Y', strtotime($req['created_at'])) ?><br>
                        <span class="text-muted"><?= date('h:i A', strtotime($req['created_at'])) ?></span>
                    </td>
                    <td class="pe-4 py-3">
                        <button class="btn btn-xs btn-outline-primary mb-1 w-100"
                                onclick="viewRequest(<?= htmlspecialchars(json_encode($req)) ?>)">
                            <i class="bi bi-eye me-1"></i>View
                        </button>
                        <?php if (in_array($req['status'], ['pending','viewed','processing'])): ?>
                        <div class="d-flex gap-1 mb-1">
                            <?php if ($req['status'] !== 'processing'): ?>
                            <form method="POST" class="flex-fill">
                                <input type="hidden" name="action" value="processing">
                                <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                                <?= csrfField() ?>
                                <button class="btn btn-xs btn-outline-primary w-100" title="Mark as Processing"><i class="bi bi-gear"></i></button>
                            </form>
                            <?php endif; ?>
                            <form method="POST" class="flex-fill">
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                                <?= csrfField() ?>
                                <button class="btn btn-xs btn-success w-100" title="Approve"><i class="bi bi-check-lg"></i></button>
                            </form>
                            <button class="btn btn-xs btn-danger flex-fill"
                                    onclick="openRejectModal(<?= $req['id'] ?>, <?= htmlspecialchars(json_encode($req['subject']), ENT_QUOTES) ?>)"
                                    title="Reject"><i class="bi bi-x-lg"></i></button>
                        </div>
                        <?php endif; ?>
                        <?php if ($req['status'] === 'approved'): ?>
                        <form method="POST" class="mb-1">
                            <input type="hidden" name="action" value="done">
                            <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                            <?= csrfField() ?>
                            <button class="btn btn-xs btn-outline-success w-100"><i class="bi bi-check2-all me-1"></i>Done</button>
                        </form>
                        <?php endif; ?>
                        <?php if (in_array($req['status'], ['approved','rejected','done'])): ?>
                        <button class="btn btn-xs btn-outline-secondary w-100 mt-1"
                                onclick="openRequestArchiveModal(<?= $req['id'] ?>, <?= htmlspecialchars(json_encode($req['subject']), ENT_QUOTES) ?>)">
                            <i class="bi bi-archive me-1"></i>Archive
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php
    $paginationBase = '?' . http_build_query(array_filter(['search'=>$search,'request_type'=>$typeFilter,'status'=>$statusFilter,'barangay_id'=>$brgyFilter?:null]));
    $paginationBase = ($paginationBase === '?') ? '?' : $paginationBase . '&';
    if ($totalPages > 1): $paginationLabel = 'request'; include __DIR__ . '/../includes/pagination.php'; endif;
    ?>

</div><!-- /.main-content -->

<!-- View Request Modal -->
<div class="modal fade" id="viewRequestModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;overflow:hidden;">
            <div class="d-flex">
                <div class="d-flex flex-column align-items-center justify-content-center px-4 py-5"
                     style="background:linear-gradient(160deg,#1a4f7a 0%,#3a7fa8 100%);min-width:190px;width:190px;align-self:stretch;">
                    <div style="width:72px;height:72px;background:rgba(255,255,255,0.15);border-radius:50%;display:flex;align-items:center;justify-content:center;border:3px solid rgba(255,255,255,0.3);">
                        <i class="bi bi-inbox-fill text-white" style="font-size:2rem;"></i>
                    </div>
                    <h5 class="fw-bold mt-3 mb-1 text-white text-center" style="font-size:1rem;">Request Details</h5>
                    <p class="text-white-50 small text-center mb-0">LGU Request Review</p>
                </div>
                <div class="flex-fill d-flex flex-column justify-content-between p-4">
                    <table class="table table-bordered table-sm mb-3" style="font-size:0.85rem;">
                        <tbody>
                            <tr><td class="text-muted fw-semibold bg-light" style="width:35%;">Requester</td><td class="fw-bold" id="vreq_name"></td></tr>
                            <tr><td class="text-muted fw-semibold bg-light">Username / Email</td><td id="vreq_identity" style="font-size:0.82rem;"></td></tr>
                            <tr><td class="text-muted fw-semibold bg-light">Barangay</td><td id="vreq_brgy"></td></tr>
                            <tr><td class="text-muted fw-semibold bg-light">Request Type</td><td id="vreq_type"></td></tr>
                            <tr><td class="text-muted fw-semibold bg-light">Subject</td><td class="fw-semibold" id="vreq_subject"></td></tr>
                            <tr><td class="text-muted fw-semibold bg-light">Status</td><td id="vreq_status"></td></tr>
                            <tr><td class="text-muted fw-semibold bg-light">Date Submitted</td><td id="vreq_date"></td></tr>
                            <tr><td class="text-muted fw-semibold bg-light align-top">Details</td><td><div id="vreq_details" class="small" style="line-height:1.7;white-space:pre-wrap;"></div></td></tr>
                            <tr id="vreq_notes_wrap" style="display:none;"><td class="text-muted fw-semibold bg-light align-top">LGU Notes</td><td><div id="vreq_notes" class="small text-muted" style="background:#fff3cd;padding:6px 8px;border-radius:4px;"></div></td></tr>
                        </tbody>
                    </table>
                    <div class="d-flex gap-2 mt-2">
                        <button type="button" class="btn btn-outline-secondary flex-fill" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="request_id" id="rejectReqId">
            <?= csrfField() ?>
            <div class="modal-content" style="border-radius:16px;overflow:hidden;">
                <div class="d-flex">
                    <div class="d-flex flex-column align-items-center justify-content-center px-4 py-5"
                         style="background:linear-gradient(160deg,#c62828 0%,#e53935 100%);min-width:190px;width:190px;align-self:stretch;">
                        <div style="width:72px;height:72px;background:rgba(255,255,255,0.15);border-radius:50%;display:flex;align-items:center;justify-content:center;border:3px solid rgba(255,255,255,0.3);">
                            <i class="bi bi-x-circle-fill text-white" style="font-size:2rem;"></i>
                        </div>
                        <h5 class="fw-bold mt-3 mb-1 text-white text-center" style="font-size:1rem;">Reject Request</h5>
                        <p class="text-white-50 small text-center mb-0">Provide a reason</p>
                    </div>
                    <div class="flex-fill d-flex flex-column justify-content-between p-4">
                        <div>
                            <p class="small text-muted mb-2">Rejecting: <strong id="rejectReqSubject"></strong></p>
                            <label class="form-label fw-semibold small">Reason / Notes <span class="text-danger">*</span></label>
                            <textarea name="admin_notes" class="form-control" rows="4"
                                      placeholder="Provide a reason for rejection..." required></textarea>
                        </div>
                        <div class="d-flex gap-2 mt-3">
                            <button type="button" class="btn btn-outline-secondary flex-fill" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-danger flex-fill fw-semibold">
                                <i class="bi bi-x-circle me-1"></i>Confirm Reject
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Archive Request Modal -->
<form method="POST" id="archiveRequestForm" style="display:none;">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="archive_request">
    <input type="hidden" name="request_id" id="archiveRequestId">
</form>
<div class="modal fade" id="archiveRequestModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;overflow:hidden;">
            <div class="d-flex">
                <div class="d-flex flex-column align-items-center justify-content-center px-4 py-5"
                     style="background:linear-gradient(160deg,#1a4f7a 0%,#3a7fa8 100%);min-width:200px;width:200px;align-self:stretch;">
                    <div style="width:72px;height:72px;background:rgba(255,255,255,0.15);border-radius:50%;display:flex;align-items:center;justify-content:center;border:3px solid rgba(255,255,255,0.3);">
                        <i class="bi bi-archive-fill text-white" style="font-size:2rem;"></i>
                    </div>
                    <h5 class="fw-bold mt-3 mb-1 text-white text-center">Archive Request</h5>
                    <p class="text-white-50 small text-center mb-0">This action is reversible</p>
                </div>
                <div class="flex-fill d-flex flex-column justify-content-between p-4">
                    <div>
                        <p class="mb-1">You are about to archive:</p>
                        <p class="fw-bold fs-6" id="archiveRequestName"></p>
                        <div class="alert alert-warning small py-2 mb-0">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            This request will be moved to the archive. It can be restored from the Archive page.
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-3">
                        <button type="button" class="btn btn-outline-secondary flex-fill" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary flex-fill fw-semibold" onclick="submitRequestArchive()">
                            <i class="bi bi-check-lg me-1"></i>Confirm Archive
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('show'); }

const typeLabels = {
    password_change:  { label: 'Password Change',  color: 'warning' },
    edit_information: { label: 'Edit Information', color: 'info' },
    new_user:         { label: 'New User',          color: 'primary' },
    access_request:   { label: 'Access Request',    color: 'secondary' },
    reset_2fa:        { label: 'Reset 2FA',         color: 'danger' },
};
const statusColors = { pending:'warning', viewed:'info', processing:'primary', approved:'success', rejected:'danger', done:'success' };
const statusLabels = { pending:'Pending (Not Opened)', viewed:'Viewed', processing:'Processing', approved:'Approved', rejected:'Rejected', done:'Done' };

function escHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function viewRequest(r) {
    document.getElementById('vreq_name').textContent    = r.requester_name || '—';
    document.getElementById('vreq_brgy').textContent    = r.barangay_name  || '—';
    document.getElementById('vreq_subject').textContent = r.subject;
    document.getElementById('vreq_details').textContent = r.details;

    const identEl = document.getElementById('vreq_identity');
    let identHtml = '';
    if (r.username)        identHtml += '<code>' + escHtml(r.username) + '</code>';
    if (r.requester_email) identHtml += (identHtml ? ' &nbsp;·&nbsp; ' : '') +
        '<a href="mailto:' + escHtml(r.requester_email) + '" style="color:#1a4f7a;">' + escHtml(r.requester_email) + '</a>';
    identEl.innerHTML = identHtml || '—';

    const d = new Date(r.created_at);
    document.getElementById('vreq_date').textContent = d.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'})
        + ' ' + d.toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit'});

    const ti = typeLabels[r.request_type] || { label: r.request_type, color: 'secondary' };
    document.getElementById('vreq_type').innerHTML   = '<span class="badge bg-' + ti.color + '">' + ti.label + '</span>';

    const sc = statusColors[r.status] || 'secondary';
    const sl = statusLabels[r.status] || r.status.replace('_',' ');
    document.getElementById('vreq_status').innerHTML = '<span class="badge bg-' + sc + '">' + sl + '</span>';

    const notesWrap = document.getElementById('vreq_notes_wrap');
    if (r.admin_notes) { document.getElementById('vreq_notes').textContent = r.admin_notes; notesWrap.style.display = ''; }
    else notesWrap.style.display = 'none';

    if (r.status === 'pending') {
        fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=mark_viewed&request_id=' + r.id
                + '&csrf_token=' + encodeURIComponent(document.querySelector('meta[name="csrf-token"]')?.content || '')
        });
    }

    new bootstrap.Modal(document.getElementById('viewRequestModal')).show();
}

function openRejectModal(id, subject) {
    document.getElementById('rejectReqId').value            = id;
    document.getElementById('rejectReqSubject').textContent = subject;
    new bootstrap.Modal(document.getElementById('rejectModal')).show();
}

function openRequestArchiveModal(id, subject) {
    document.getElementById('archiveRequestId').value         = id;
    document.getElementById('archiveRequestName').textContent = subject;
    new bootstrap.Modal(document.getElementById('archiveRequestModal')).show();
}
function submitRequestArchive() {
    bootstrap.Modal.getInstance(document.getElementById('archiveRequestModal')).hide();
    document.getElementById('archiveRequestForm').submit();
}
</script>
</body>
</html>
