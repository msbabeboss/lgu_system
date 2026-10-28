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
$currentPage = max(1, (int)($_GET['page'] ?? 1));
$totalPages  = max(1, (int)ceil($totalRows / $perPage));
$currentPage = min($currentPage, $totalPages);
$offset      = ($currentPage - 1) * $perPage;

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
    'password_change'  => ['label'=>'Password Change',  'icon'=>'bi-key-fill',        'color'=>'warning',   'desc'=>'Request to reset or change your account password.'],
    'edit_information' => ['label'=>'Edit Information', 'icon'=>'bi-pencil-square',    'color'=>'info',      'desc'=>'Request to update your profile details (name, email, contact, position).'],
    'reset_2fa'        => ['label'=>'Reset 2FA',        'icon'=>'bi-shield-lock-fill', 'color'=>'danger',    'desc'=>'Request to reset your two-factor authentication (2FA) if you have lost access to your authenticator.'],
    'new_user'         => ['label'=>'New User',         'icon'=>'bi-person-plus-fill', 'color'=>'success',   'desc'=>'Request to create a new user account for your barangay.'],
    'access_request'   => ['label'=>'Access Request',   'icon'=>'bi-shield-check',     'color'=>'secondary', 'desc'=>'Request access to a specific module or feature you currently cannot use.'],
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
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=3.2">
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
            <div class="card h-100" style="cursor:pointer;transition:transform 0.15s;"
                 onclick="openRequestModal('<?= $type ?>')"
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
            <table class="table mb-0" style="font-size:0.85rem;">
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
        $page = $currentPage; $totalCount = $totalRows; $offset = ($page-1)*$perPage;
        $paginationBase = $baseUrl; $paginationLabel = 'request';
        include __DIR__ . '/../includes/pagination.php';
    ?>

</div><!-- /.main-content -->

<!-- New Request Modal -->
<div class="modal fade" id="newRequestModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST">
            <input type="hidden" name="action" value="submit_request">
            <?= csrfField() ?>
            <input type="hidden" name="request_type" id="modalRequestType" value="password_change">
            <div class="modal-content" style="border-radius:16px;overflow:hidden;">
                <div class="d-flex" style="min-height:300px;">
                    <!-- LEFT -->
                    <div class="d-flex flex-column align-items-center justify-content-center px-4 py-5"
                         style="background:linear-gradient(160deg,#1a4f7a 0%,#3a7fa8 100%);min-width:200px;width:200px;">
                        <div id="reqModalIcon"
                             style="width:72px;height:72px;background:rgba(255,255,255,0.15);border-radius:50%;display:flex;align-items:center;justify-content:center;border:3px solid rgba(255,255,255,0.3);">
                            <i class="bi bi-key-fill text-white" style="font-size:2rem;" id="reqModalIconI"></i>
                        </div>
                        <h5 class="fw-bold mt-3 mb-1 text-white text-center" id="reqModalTitle">Password Change</h5>
                        <p class="text-white-50 small text-center mb-0">Request to LGU</p>
                    </div>
                    <!-- RIGHT -->
                    <div class="flex-fill d-flex flex-column p-4">
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Request Type <span class="text-danger">*</span></label>
                            <div class="row g-2">
                                <?php foreach ($typeLabels as $type => $info): ?>
                                <div class="col-6">
                                    <div class="form-check border rounded p-2 req-type-card <?= $type==='password_change'?'border-primary bg-light':'' ?>"
                                         style="cursor:pointer;" onclick="selectReqType('<?= $type ?>')">
                                        <input class="form-check-input" type="radio" name="request_type_ui"
                                               id="rt_<?= $type ?>" value="<?= $type ?>"
                                               <?= $type==='password_change'?'checked':'' ?>>
                                        <label class="form-check-label small fw-semibold" for="rt_<?= $type ?>" style="cursor:pointer;">
                                            <i class="bi <?= $info['icon'] ?> me-1 text-<?= $info['color'] ?>"></i>
                                            <?= $info['label'] ?>
                                        </label>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Subject <span class="text-danger">*</span></label>
                            <input type="text" name="subject" class="form-control form-control-sm"
                                   placeholder="Brief description of your request" required maxlength="200">
                        </div>
                        <div class="mb-3 flex-fill">
                            <label class="form-label fw-semibold small">Details <span class="text-danger">*</span></label>
                            <textarea name="details" class="form-control form-control-sm" rows="4"
                                      placeholder="Provide full details of your request..." required></textarea>
                        </div>
                        <div class="alert alert-info py-2 small mb-0">
                            <i class="bi bi-info-circle me-1"></i>
                            Your request will be sent to the LGU. You will receive a notification once it is reviewed.
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 px-4 pb-4 pt-0">
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
        if (this.parentElement.classList.contains('disabled')) return;
        e.preventDefault();
        var t = document.getElementById('logs-table');
        if (t) t.scrollIntoView({ behavior:'smooth', block:'start' });
        var href = this.getAttribute('href');
        setTimeout(function(){ window.location.href = href; }, 350);
    });
});

const reqTypeInfo = {
    password_change:  { label: 'Password Change',  icon: 'bi-key-fill' },
    edit_information: { label: 'Edit Information', icon: 'bi-pencil-square' },
    reset_2fa:        { label: 'Reset 2FA',         icon: 'bi-shield-lock-fill' },
    new_user:         { label: 'New User',          icon: 'bi-person-plus-fill' },
    access_request:   { label: 'Access Request',    icon: 'bi-shield-check' },
};

function selectReqType(type) {
    document.getElementById('modalRequestType').value = type;
    document.getElementById('rt_' + type).checked = true;
    document.getElementById('reqModalIconI').className = 'bi ' + reqTypeInfo[type].icon + ' text-white';
    document.getElementById('reqModalTitle').textContent = reqTypeInfo[type].label;
    document.querySelectorAll('.req-type-card').forEach(c => c.classList.remove('border-primary','bg-light'));
    document.getElementById('rt_' + type).closest('.req-type-card').classList.add('border-primary','bg-light');
}

function openRequestModal(type) {
    selectReqType(type);
    new bootstrap.Modal(document.getElementById('newRequestModal')).show();
}
</script>
</body>
</html>
