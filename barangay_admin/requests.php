<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/mailer.php';
requireRole('barangay_admin');

$db  = Database::getInstance()->getConnection();
$u   = currentUser();
$bid = (int)$u['barangay_id'];

// ── Ensure user_requests table exists ──────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS user_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    requester_id INT UNSIGNED NOT NULL,
    barangay_id INT UNSIGNED,
    request_type ENUM('password_change','edit_information','new_user','access_request','username_change','reset_2fa') NOT NULL,
    subject VARCHAR(200) NOT NULL,
    details TEXT NOT NULL,
    status ENUM('pending','in_review','approved','rejected') DEFAULT 'pending',
    admin_notes TEXT NULL,
    reviewed_by INT UNSIGNED NULL,
    reviewed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (requester_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

try {
    $db->exec("ALTER TABLE user_requests MODIFY COLUMN status ENUM('pending','viewed','processing','approved','rejected','done','archived') DEFAULT 'pending'");
} catch (PDOException $e) {}

// ── POST: Submit new request ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_request') {
    verifyCsrfOrAbort();
    $type    = $_POST['request_type'] ?? '';
    $subject = sanitize($_POST['subject'] ?? '');
    $details = sanitize($_POST['details'] ?? '');

    $allowed = ['password_change','edit_information','new_user','access_request','username_change','reset_2fa'];
    if (!in_array($type, $allowed) || !$subject || !$details) {
        flash('req', 'Please fill in all required fields.', 'danger');
        redirect(BASE_URL . '/barangay_admin/requests.php');
    }

    $db->prepare("INSERT INTO user_requests (requester_id, barangay_id, request_type, subject, details) VALUES (?,?,?,?,?)")
       ->execute([$u['id'], $bid, $type, $subject, $details]);

    $admins   = $db->query("SELECT id, email, CONCAT(first_name,' ',last_name) as name FROM users WHERE role='super_admin' AND status='active'")->fetchAll();
    $brgyName = $db->query("SELECT barangay_name FROM barangays WHERE id=$bid")->fetchColumn();
    $typeLabel = [
        'password_change'  => 'Password Change Request',
        'edit_information' => 'Edit Information Request',
        'new_user'         => 'New User Request',
        'access_request'   => 'Access Request',
        'reset_2fa'        => 'Reset 2FA Request',
    ][$type] ?? ucfirst(str_replace('_', ' ', $type));

    $requesterRow = $db->prepare("SELECT email, username FROM users WHERE id=?");
    $requesterRow->execute([$u['id']]);
    $requesterRow      = $requesterRow->fetch();
    $requesterEmail    = $requesterRow['email']    ?? '';
    $requesterUsername = $requesterRow['username'] ?? $u['name'];
    $requesterDisplay  = "{$u['name']} <{$requesterUsername}>" . ($requesterEmail ? " {$requesterEmail}" : '');

    foreach ($admins as $admin) {
        sendNotification($admin['id'],
            "📋 New {$typeLabel} from Brgy. {$brgyName} — {$requesterDisplay}: {$subject}",
            'info', 'transaction');
    }

    $adminEmailList = array_filter($admins, fn($a) => !empty($a['email']));
    if (!empty($adminEmailList)) {
        sendNewRequestEmailToAdmins($adminEmailList, $u['name'], $requesterUsername,
            $requesterEmail, $brgyName ?: 'N/A', $type, $subject, $details);
    }

    logActivity($u['id'], 'SUBMIT_REQUEST', 'Requests', "Submitted $type: $subject");
    flash('req', 'Your request has been submitted to the LGU. You will be notified once it is reviewed.', 'success');
    redirect(BASE_URL . '/barangay_admin/requests.php');
}

// ── Fetch my requests ──────────────────────────────────────
$countStmt = $db->prepare("SELECT COUNT(*) FROM user_requests WHERE requester_id = ?");
$countStmt->execute([$u['id']]);
$totalRows = (int)$countStmt->fetchColumn();

$perPage     = 10;
$requestPage = max(1, (int)($_GET['page'] ?? 1));
$totalPages  = max(1, (int)ceil($totalRows / $perPage));
$requestPage = min($requestPage, $totalPages);
$offset      = ($requestPage - 1) * $perPage;

$myRequests = $db->prepare("SELECT r.*, CONCAT(u.first_name,' ',u.last_name) as reviewed_by_name
    FROM user_requests r
    LEFT JOIN users u ON r.reviewed_by = u.id
    WHERE r.requester_id = ?
    ORDER BY r.created_at DESC
    LIMIT $perPage OFFSET $offset");
$myRequests->execute([$u['id']]);
$myRequests = $myRequests->fetchAll();

$flash = getFlash('req');

$typeLabels = [
    'password_change'  => ['label'=>'Password Change',  'icon'=>'bi-key-fill',        'color'=>'warning',   'desc'=>'Request a password reset for your account.'],
    'edit_information' => ['label'=>'Edit Information', 'icon'=>'bi-pencil-square',    'color'=>'info',      'desc'=>'Request changes to your profile details.'],
    'reset_2fa'        => ['label'=>'Reset 2FA',        'icon'=>'bi-shield-lock-fill', 'color'=>'danger',    'desc'=>'Restore access if you lost your authenticator.'],
    'new_user'         => ['label'=>'New User',         'icon'=>'bi-person-plus-fill', 'color'=>'success',   'desc'=>'Request an account for a barangay staff member.'],
    'access_request'   => ['label'=>'Access Request',   'icon'=>'bi-shield-check',     'color'=>'secondary', 'desc'=>'Ask for access to a module or system feature.'],
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
    'pending'    => 'Pending',
    'viewed'     => 'Viewed',
    'processing' => 'Processing',
    'approved'   => 'Approved',
    'rejected'   => 'Rejected',
    'done'       => 'Done',
];

$pageIcon     = 'bi-inbox-fill';
$pageTitle    = 'Request Management';
$pageSubtitle = 'Submit and track requests to the LGU for processing.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favcon.png">
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=3.5">
    <style>
        .request-modal-content {
            border: 0;
            border-radius: 14px;
            box-shadow: 0 18px 48px rgba(2, 16, 36, 0.2);
        }

        .request-modal-dialog { max-width: 700px; }

        .req-type-card {
            display: flex;
            align-items: flex-start;
            gap: 0.65rem;
            min-height: 76px;
            margin: 0;
            padding: 0.75rem !important;
            border: 1px solid #d5dee8 !important;
            border-radius: 0.65rem;
            background-color: #fff;
            cursor: pointer;
            transition: border-color 0.15s ease, background-color 0.15s ease;
        }

        .req-type-card:hover { border-color: #8eb3d2 !important; }

        .req-type-card.is-selected {
            border-color: #0d6efd !important;
            background-color: #f2f7ff;
        }

        .req-type-card .form-check-input {
            float: none;
            flex: 0 0 1rem;
            width: 1rem;
            height: 1rem;
            margin: 0.1rem 0 0;
            border: 1.5px solid #94a3b8;
            background-color: #fff;
        }

        .req-type-card .form-check-input:checked {
            border-color: #0d6efd;
            background-color: #0d6efd;
        }

        .req-type-card .form-check-input:focus { box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.18); }
        .req-type-copy { display: block; min-width: 0; }
        .req-type-heading { display: block; color: #1f2937; font-size: 0.85rem; font-weight: 600; line-height: 1.25; }
        .req-type-description { display: block; margin-top: 0.3rem; color: #64748b; font-size: 0.75rem; line-height: 1.35; }

        .request-history-table {
            width: 100%;
            min-width: 760px;
            table-layout: fixed;
            font-size: 0.78rem !important;
        }

        .request-history-table th,
        .request-history-table td {
            padding: 0.5rem 0.55rem !important;
            vertical-align: middle;
        }

        .request-history-table th {
            white-space: normal !important;
            line-height: 1.25;
        }

        .request-history-table th:nth-child(1) { width: 5%; }
        .request-history-table th:nth-child(2) { width: 14%; }
        .request-history-table th:nth-child(3) { width: 15%; }
        .request-history-table th:nth-child(4) { width: 18%; }
        .request-history-table th:nth-child(5) { width: 11%; }
        .request-history-table th:nth-child(6) { width: 14%; }
        .request-history-table th:nth-child(7) { width: 12%; }
        .request-history-table th:nth-child(8) { width: 11%; }

        .request-history-table td {
            overflow-wrap: anywhere;
        }

        .request-history-table .badge {
            white-space: normal;
            line-height: 1.25;
            text-align: left;
        }

        @media (max-width: 767.98px) {
            .request-modal-dialog { margin: 0.5rem; }
            .request-modal-content { max-height: calc(100vh - 1rem); overflow-y: auto; }
            .request-history-table { min-width: 720px; }
        }

        @media (max-width: 420px) {
            .request-type-options > .col-6 { width: 100%; }
            .request-modal-header,
            .request-modal-body { padding-right: 1.25rem !important; padding-left: 1.25rem !important; }
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/sidebar_barangay.php'; ?>
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<div class="main-content">

    <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show">
        <?= $flash['message'] ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Request Type Cards -->
    <div class="row g-3 mb-4">
        <?php foreach ($typeLabels as $type => $info):
            $cnt = count(array_filter($myRequests, fn($r) => $r['request_type'] === $type));
        ?>
        <div class="col-6 col-md-4">
            <div class="card h-100 request-type-trigger" style="cursor:pointer;transition:transform 0.15s;"
                 data-request-type="<?= $type ?>"
                 role="button" tabindex="0" aria-label="Create <?= htmlspecialchars($info['label']) ?>"
                 onmouseover="this.style.transform='translateY(-3px)'"
                 onmouseout="this.style.transform=''">
                <div class="card-body py-3">
                    <div class="d-flex align-items-center gap-3 mb-2">
                        <div style="width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;"
                             class="bg-<?= $info['color'] ?> bg-opacity-10">
                            <i class="bi <?= $info['icon'] ?> text-<?= $info['color'] ?>" style="font-size:1.3rem;"></i>
                        </div>
                        <div>
                            <div class="fw-semibold small"><?= $info['label'] ?></div>
                            <div class="text-muted" style="font-size:0.72rem;"><?= $cnt ?> submitted</div>
                        </div>
                    </div>
                    <p class="text-muted mb-0" style="font-size:0.75rem;line-height:1.4;"><?= $info['desc'] ?></p>
                    <div class="small fw-semibold text-primary mt-2">
                        <i class="bi bi-plus-circle me-1"></i>Create Request
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- My Requests Table -->
    <div id="logs-table" style="background:#fff;border-radius:18px;box-shadow:0 2px 16px rgba(0,0,0,0.07);border:1px solid #e0f0e4;overflow:hidden;">
        <div style="padding:16px 22px;border-bottom:1px solid #e8f4fb;">
            <div style="font-weight:700;font-size:0.9rem;color:#1a2e1f;">
                <i class="bi bi-list-ul me-2"></i>My Requests (<?= number_format($totalRows) ?>)
            </div>
        </div>
        <div class="table-responsive">
            <table class="table mb-0 request-history-table">
                <thead>
                    <tr style="background:#f4f7fa;border-bottom:2px solid #d0ecd6;">
                        <th class="ps-4 py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">#</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">Type</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">Subject</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">Details</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">Status</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">LGU Notes</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">Date Submitted</th>
                        <th class="pe-4 py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">Reviewed</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($myRequests)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-5">
                        <i class="bi bi-inbox" style="font-size:2.5rem;opacity:0.3;display:block;margin-bottom:10px;"></i>
                        No requests submitted yet.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($myRequests as $i => $req):
                        $ti = $typeLabels[$req['request_type']] ?? ['label'=>$req['request_type'],'icon'=>'bi-question','color'=>'secondary'];
                        $icons = ['pending'=>'bi-hourglass','viewed'=>'bi-eye-fill','processing'=>'bi-gear-fill','approved'=>'bi-check-circle-fill','rejected'=>'bi-x-circle-fill','done'=>'bi-check2-all'];
                    ?>
                    <tr style="border-bottom:1px solid #e8f4fb;transition:background 0.15s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                        <td class="ps-4 py-3" style="border:none;color:#3a7fa8;"><?= $i+1 ?></td>
                        <td class="py-3" style="border:none;">
                            <span class="badge bg-<?= $ti['color'] ?>">
                                <i class="bi <?= $ti['icon'] ?> me-1"></i><?= $ti['label'] ?>
                            </span>
                        </td>
                        <td class="py-3" style="border:none;font-weight:600;color:#1a2e1f;"><?= htmlspecialchars($req['subject']) ?></td>
                        <td class="py-3" style="border:none;font-size:0.8rem;color:#3a7fa8;max-width:200px;"><?= htmlspecialchars($req['details']) ?></td>
                        <td class="py-3" style="border:none;">
                            <?php
                            $sc   = $statusColors[$req['status']] ?? 'secondary';
                            $sl   = $statusLabels[$req['status']] ?? ucfirst(str_replace('_',' ',$req['status']));
                            $icon = $icons[$req['status']] ?? 'bi-circle';
                            ?>
                            <span class="badge bg-<?= $sc ?>"><i class="bi <?= $icon ?> me-1"></i><?= $sl ?></span>
                        </td>
                        <td class="py-3" style="border:none;font-size:0.8rem;color:#3a7fa8;"><?= htmlspecialchars($req['admin_notes'] ?? '—') ?></td>
                        <td class="py-3" style="border:none;">
                            <div style="font-size:0.8rem;color:#2a5070;"><?= date('M d, Y', strtotime($req['created_at'])) ?></div>
                            <div style="font-size:0.72rem;color:#94a3b8;"><?= date('h:i A', strtotime($req['created_at'])) ?></div>
                        </td>
                        <td class="pe-4 py-3" style="border:none;">
                            <?php if ($req['reviewed_at']): ?>
                                <div style="font-size:0.8rem;color:#2a5070;"><?= htmlspecialchars($req['reviewed_by_name'] ?? '') ?></div>
                                <div style="font-size:0.72rem;color:#94a3b8;"><?= date('M d, Y', strtotime($req['reviewed_at'])) ?></div>
                            <?php else: ?>
                                <span style="color:#94a3b8;font-size:0.8rem;">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <?php
        $queryParams = $_GET; unset($queryParams['page']);
        $baseQuery   = http_build_query($queryParams);
        $baseUrl     = '?' . ($baseQuery ? $baseQuery . '&' : '');
        $page = $requestPage; $totalCount = $totalRows; $offset = ($page - 1) * $perPage;
        $paginationBase = $baseUrl; $paginationLabel = 'request';
        include __DIR__ . '/../includes/pagination.php';
    ?>

</div><!-- /.main-content -->

<!-- New Request Modal -->
<div class="modal fade" id="newRequestModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered request-modal-dialog">
        <form method="POST">
            <input type="hidden" name="action" value="submit_request">
            <?= csrfField() ?>
            <input type="hidden" name="request_type" id="modalRequestType" value="password_change">
            <div class="modal-content request-modal-content">
                <div class="modal-header request-modal-header border-0 px-4 pt-4 pb-2">
                    <div>
                        <h5 class="modal-title fw-bold">Create a Request</h5>
                        <p class="text-muted small mb-0">Choose a request type and provide the details for LGU review.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body request-modal-body px-4 pt-2">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Request Type <span class="text-danger">*</span></label>
                        <div class="row g-2 request-type-options">
                            <?php foreach ($typeLabels as $type => $info): ?>
                            <div class="col-6">
                                <label class="form-check req-type-card <?= $type==='password_change'?'is-selected':'' ?>"
                                       for="rt_<?= $type ?>">
                                    <input class="form-check-input" type="radio" name="request_type_ui"
                                           id="rt_<?= $type ?>" value="<?= $type ?>"
                                           <?= $type==='password_change'?'checked':'' ?>>
                                    <span class="req-type-copy">
                                        <span class="req-type-heading">
                                            <i class="bi <?= $info['icon'] ?> me-1 text-<?= $info['color'] ?>"></i>
                                            <?= $info['label'] ?>
                                        </span>
                                        <small class="req-type-description"><?= $info['desc'] ?></small>
                                    </span>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Subject <span class="text-danger">*</span></label>
                        <input type="text" name="subject" class="form-control form-control-sm"
                               placeholder="Brief description of your request" required maxlength="200">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Details <span class="text-danger">*</span></label>
                        <textarea name="details" class="form-control form-control-sm" rows="3"
                                  placeholder="Provide full details of your request..." required></textarea>
                    </div>
                    <div class="alert alert-info py-2 small mb-0">
                        <i class="bi bi-info-circle me-1"></i>
                        Your request will be sent to the LGU. You will receive a notification once it is reviewed.
                    </div>
                </div>
                <div class="modal-footer request-modal-footer border-0 px-4 pb-4 pt-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary fw-semibold">
                        <i class="bi bi-send me-1"></i>Submit Request
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('show'); }

document.querySelectorAll('.page-nav').forEach(function(link) {
    link.addEventListener('click', function(e) {
        if (this.closest('.table-pagination')) return;
        if (this.parentElement.classList.contains('disabled')) return;
        e.preventDefault();
        var t = document.getElementById('logs-table');
        if (t) t.scrollIntoView({ behavior:'smooth', block:'start' });
        var href = this.getAttribute('href');
        setTimeout(function(){ window.location.href = href; }, 350);
    });
});

function openRequestModal(type, trigger) {
    if (!document.getElementById('rt_' + type)) return;
    selectReqType(type);
    bootstrap.Modal.getOrCreateInstance(document.getElementById('newRequestModal')).show(trigger);
}

function selectReqType(type) {
    const radio = document.getElementById('rt_' + type);
    if (!radio) return;
    document.getElementById('modalRequestType').value = type;
    radio.checked = true;
    document.querySelectorAll('.req-type-card').forEach(c => c.classList.remove('is-selected'));
    radio.closest('.req-type-card').classList.add('is-selected');
}

document.querySelectorAll('input[name="request_type_ui"]').forEach(radio => {
    radio.addEventListener('change', () => selectReqType(radio.value));
});

document.querySelectorAll('.request-type-trigger').forEach(card => {
    card.addEventListener('click', () => openRequestModal(card.dataset.requestType, card));
    card.addEventListener('keydown', event => {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        event.preventDefault();
        openRequestModal(card.dataset.requestType, card);
    });
});
</script>
</body>
</html>
