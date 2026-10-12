<?php
require_once __DIR__ . '/../config/config.php';
requireRole('barangay_admin');

$db  = Database::getInstance()->getConnection();
$u   = currentUser();
$bid = (int)$u['barangay_id'];

// ── Safe bootstrap ──────────────────────────────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS resident_transfer_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    requester_user_id INT UNSIGNED NOT NULL,
    origin_barangay_id INT UNSIGNED NOT NULL,
    resident_id INT UNSIGNED NOT NULL,
    resident_name VARCHAR(150) NOT NULL,
    destination_barangay_id INT UNSIGNED NOT NULL,
    reason ENUM('Employment','Residence Change','Family','Other') NOT NULL DEFAULT 'Other',
    notes TEXT NULL,
    status ENUM('pending','approved','rejected','verified') NOT NULL DEFAULT 'pending',
    reviewed_by INT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    admin_notes TEXT NULL,
    verified_by INT UNSIGNED NULL,
    verified_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (requester_user_id)       REFERENCES users(id)     ON DELETE CASCADE,
    FOREIGN KEY (origin_barangay_id)      REFERENCES barangays(id) ON DELETE CASCADE,
    FOREIGN KEY (resident_id)             REFERENCES residents(id)  ON DELETE CASCADE,
    FOREIGN KEY (destination_barangay_id) REFERENCES barangays(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by)             REFERENCES users(id)     ON DELETE SET NULL,
    FOREIGN KEY (verified_by)             REFERENCES users(id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── POST: Submit ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit') {
    verifyCsrfOrAbort();

    $residentId = (int)($_POST['resident_id'] ?? 0);
    $destId     = (int)($_POST['destination_barangay_id'] ?? 0);
    $reason     = in_array($_POST['reason'] ?? '', ['Employment','Residence Change','Family','Other'])
                  ? $_POST['reason'] : 'Other';
    $notes      = sanitize($_POST['notes'] ?? '');

    if (!$residentId || !$destId) {
        flash('tr', 'Please select a resident and a destination barangay.', 'danger');
        redirect(BASE_URL . '/barangay_admin/transfer_requests.php');
    }
    if ($destId === $bid) {
        flash('tr', 'Destination barangay cannot be the same as the origin.', 'danger');
        redirect(BASE_URL . '/barangay_admin/transfer_requests.php');
    }

    // Resident must belong to this barangay and be Active
    $resStmt = $db->prepare("
        SELECT id, TRIM(CONCAT(first_name,' ',COALESCE(NULLIF(middle_name,''),''),' ',last_name)) AS full_name, resident_status
        FROM residents WHERE id=? AND barangay_id=?
    ");
    $resStmt->execute([$residentId, $bid]);
    $resident = $resStmt->fetch();

    if (!$resident) {
        flash('tr', 'Resident not found in your barangay.', 'danger');
        redirect(BASE_URL . '/barangay_admin/transfer_requests.php');
    }
    if ($resident['resident_status'] !== 'Active') {
        flash('tr', 'Only Active residents can be transferred.', 'danger');
        redirect(BASE_URL . '/barangay_admin/transfer_requests.php');
    }

    // No duplicate active request
    $dupStmt = $db->prepare("SELECT id FROM resident_transfer_requests WHERE resident_id=? AND status IN ('pending','approved')");
    $dupStmt->execute([$residentId]);
    if ($dupStmt->fetch()) {
        flash('tr', 'This resident already has an ongoing transfer request.', 'warning');
        redirect(BASE_URL . '/barangay_admin/transfer_requests.php');
    }

    $residentName = preg_replace('/\s+/', ' ', trim($resident['full_name']));
    $destName     = $db->prepare("SELECT barangay_name FROM barangays WHERE id=?")->execute([$destId])
                    ? $db->query("SELECT barangay_name FROM barangays WHERE id=$destId")->fetchColumn()
                    : "Barangay #$destId";
    $brgyName     = $db->query("SELECT barangay_name FROM barangays WHERE id=$bid")->fetchColumn();

    $db->prepare("INSERT INTO resident_transfer_requests
        (requester_user_id, origin_barangay_id, resident_id, resident_name, destination_barangay_id, reason, notes)
        VALUES (?,?,?,?,?,?,?)")
       ->execute([$u['id'], $bid, $residentId, $residentName, $destId, $reason, $notes]);

    $newId = (int)$db->lastInsertId();

    // Mark resident record: pending transfer to destination
    $db->prepare("UPDATE residents SET transfer_to_barangay=?, transfer_reason=? WHERE id=? AND barangay_id=?")
       ->execute([$destName, $reason, $residentId, $bid]);

    // Notify LGU super admins
    notifySuperAdmins(
        "🔄 New Resident Transfer Request from <strong>Brgy. {$brgyName}</strong> — <strong>{$residentName}</strong> → <strong>{$destName}</strong>. Awaiting your approval.",
        'info', 'transaction'
    );

    logActivity($u['id'], 'SUBMIT_TRANSFER_REQUEST', 'Resident Transfer',
        "Submitted transfer #{$newId}: {$residentName} → {$destName}");

    flash('tr', "Transfer request for <strong>{$residentName}</strong> submitted. The LGU will review it shortly.", 'success');
    redirect(BASE_URL . '/barangay_admin/transfer_requests.php');
}

// ── Page data ───────────────────────────────────────────────────────────────
// Active residents with no pending/approved transfer
$myResidents = $db->query("
    SELECT id,
           TRIM(CONCAT(last_name,', ',first_name,
               IF(middle_name IS NOT NULL AND middle_name != '', CONCAT(' ',middle_name), ''))) AS display_name
    FROM residents
    WHERE barangay_id=$bid AND resident_status='Active'
      AND id NOT IN (
          SELECT resident_id FROM resident_transfer_requests WHERE status IN ('pending','approved')
      )
    ORDER BY last_name, first_name
")->fetchAll();

$otherBarangays = $db->query("
    SELECT id, barangay_name FROM barangays
    WHERE status='active' AND id != $bid
    ORDER BY barangay_name
")->fetchAll();

// My submitted requests
$statusFilter = sanitize($_GET['status'] ?? '');
$search       = sanitize($_GET['search'] ?? '');

$where = "WHERE r.origin_barangay_id = $bid";
if ($statusFilter) $where .= " AND r.status = " . $db->quote($statusFilter);
if ($search)       $where .= " AND r.resident_name LIKE " . $db->quote("%$search%");

$totalCount = (int)$db->query("SELECT COUNT(*) FROM resident_transfer_requests r $where")->fetchColumn();
$perPage    = 10;
$page       = max(1, (int)($_GET['page'] ?? 1));
$totalPages = max(1, (int)ceil($totalCount / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$requests = $db->query("
    SELECT r.*,
           b2.barangay_name AS dest_name,
           CONCAT(rv.first_name,' ',rv.last_name) AS reviewed_by_name
    FROM resident_transfer_requests r
    LEFT JOIN barangays b2 ON r.destination_barangay_id = b2.id
    LEFT JOIN users rv     ON r.reviewed_by = rv.id
    $where
    ORDER BY r.created_at DESC
    LIMIT $perPage OFFSET $offset
")->fetchAll();

$flash = getFlash('tr');

$statusCfg = [
    'pending'  => ['label'=>'Pending LGU Approval',       'color'=>'warning', 'icon'=>'bi-hourglass-split'],
    'approved' => ['label'=>'Approved – Awaiting Appearance','color'=>'info',  'icon'=>'bi-check-circle'],
    'rejected' => ['label'=>'Rejected',                    'color'=>'danger',  'icon'=>'bi-x-circle-fill'],
    'verified' => ['label'=>'Transfer Verified',           'color'=>'success', 'icon'=>'bi-patch-check-fill'],
];

$pageIcon     = 'bi-arrow-left-right';
$pageTitle    = 'Resident Transfer';
$pageSubtitle = 'Request and track resident transfers to other barangays.';
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
</head>
<body>
<?php include __DIR__ . '/../includes/sidebar_barangay.php'; ?>
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<div class="main-content">

    <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show">
        <?= $flash['message'] ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Header -->
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
        <div>
            <h6 class="fw-bold mb-0">Resident Transfer Requests</h6>
            <small class="text-muted">Submit and track transfer requests for your barangay's residents.</small>
        </div>
        <button class="btn btn-primary btn-sm fw-semibold" data-bs-toggle="modal" data-bs-target="#newModal">
            <i class="bi bi-plus-circle me-1"></i>New Transfer Request
        </button>
    </div>

    <!-- Flow steps banner -->
    <div class="card border-0 mb-3" style="background:#f0f7ff;border-radius:10px;">
        <div class="card-body py-2 px-3">
            <div class="d-flex align-items-center gap-2 flex-wrap small fw-semibold" style="color:#1a4f7a;">
                <span><i class="bi bi-send me-1 text-warning"></i>Submit to LGU</span>
                <i class="bi bi-chevron-right text-muted"></i>
                <span><i class="bi bi-check-circle me-1 text-info"></i>LGU Approves</span>
                <i class="bi bi-chevron-right text-muted"></i>
                <span><i class="bi bi-person-walking me-1 text-primary"></i>Resident Appears at Destination</span>
                <i class="bi bi-chevron-right text-muted"></i>
                <span><i class="bi bi-patch-check-fill me-1 text-success"></i>Transfer Verified</span>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="GET" class="d-flex gap-2 flex-wrap align-items-center" id="filterForm">
                <div class="input-group input-group-sm ac-wrapper" style="width:240px;">
                    <span class="input-group-text bg-white border-end-0" style="border-color:#dee2e6;">
                        <i class="bi bi-search text-muted" style="font-size:0.8rem;"></i>
                    </span>
                    <input type="text" id="trSearchInput" name="search" class="form-control form-control-sm border-start-0 border-end-0 ps-0"
                           style="border-color:#dee2e6;"
                           placeholder="Search resident name..."
                           autocomplete="off"
                           value="<?= htmlspecialchars($search) ?>">
                    <span class="input-group-text bg-white border-start-0 p-0" style="border-color:#dee2e6;">
                        <span id="trSearchClear" title="Clear search"
                              style="width:28px;height:28px;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;color:#9ca3af;border-radius:4px;<?= $search ? '' : 'visibility:hidden;' ?>"
                              onmouseover="this.style.color='#dc2626';this.style.background='#fee2e2';"
                              onmouseout="this.style.color='#9ca3af';this.style.background='transparent';">
                            <i class="bi bi-x-lg" style="font-size:0.75rem;"></i>
                        </span>
                    </span>
                    <div class="ac-list" id="trAcList"></div>
                </div>
                <select name="status" class="form-select form-select-sm" style="width:200px;">
                    <option value="">All Status</option>
                    <?php foreach ($statusCfg as $k => $v): ?>
                    <option value="<?= $k ?>" <?= $statusFilter===$k?'selected':'' ?>><?= $v['label'] ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-sm btn-primary px-3"><i class="bi bi-funnel me-1"></i>Filter</button>
                <?php if ($search || $statusFilter): ?>
                <a href="?" class="btn btn-sm btn-outline-secondary px-3"><i class="bi bi-x me-1"></i>Clear</a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Requests table -->
    <div class="card border-0 shadow-sm" style="border-radius:12px;overflow:hidden;">
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:.85rem;">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4 py-3" style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;">#</th>
                        <th class="py-3" style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;">Resident</th>
                        <th class="py-3" style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;">Destination Barangay</th>
                        <th class="py-3" style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;">Reason</th>
                        <th class="py-3" style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;">Status</th>
                        <th class="py-3" style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;">LGU Notes</th>
                        <th class="pe-4 py-3" style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;">Date Filed</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($requests)): ?>
                <tr>
                    <td colspan="7" class="text-center text-muted py-5">
                        <i class="bi bi-arrow-left-right" style="font-size:2.5rem;opacity:.3;display:block;margin-bottom:10px;"></i>
                        No transfer requests yet.
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($requests as $i => $r):
                    $sc = $statusCfg[$r['status']] ?? ['label'=>$r['status'],'color'=>'secondary','icon'=>'bi-circle'];
                ?>
                <tr>
                    <td class="ps-4 py-3 text-muted small"><?= $offset+$i+1 ?></td>
                    <td class="py-3 fw-semibold"><?= htmlspecialchars($r['resident_name']) ?></td>
                    <td class="py-3 small"><?= htmlspecialchars($r['dest_name'] ?? '—') ?></td>
                    <td class="py-3 small"><?= htmlspecialchars($r['reason']) ?></td>
                    <td class="py-3">
                        <span class="badge bg-<?= $sc['color'] ?>">
                            <i class="bi <?= $sc['icon'] ?> me-1"></i><?= $sc['label'] ?>
                        </span>
                    </td>
                    <td class="py-3 small text-muted">
                        <?= $r['admin_notes'] ? htmlspecialchars($r['admin_notes']) : '—' ?>
                    </td>
                    <td class="pe-4 py-3 small text-nowrap">
                        <?= date('M d, Y', strtotime($r['created_at'])) ?><br>
                        <span class="text-muted"><?= date('h:i A', strtotime($r['created_at'])) ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($totalPages > 1):
        $paginationBase  = '?' . http_build_query(array_filter(['search'=>$search,'status'=>$statusFilter]));
        $paginationBase  = ($paginationBase === '?') ? '?' : $paginationBase . '&';
        $paginationLabel = 'request';
        include __DIR__ . '/../includes/pagination.php';
    endif; ?>

</div>

<!-- ── NEW TRANSFER MODAL ─────────────────────────────────── -->
<div class="modal fade" id="newModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST">
            <input type="hidden" name="action" value="submit">
            <?= csrfField() ?>
            <div class="modal-content" style="border-radius:16px;overflow:hidden;">
                <div class="d-flex">
                    <div class="d-flex flex-column align-items-center justify-content-center px-4 py-5 flex-shrink-0"
                         style="background:linear-gradient(160deg,#1a4f7a,#3a7fa8);width:190px;min-width:190px;">
                        <div style="width:68px;height:68px;background:rgba(255,255,255,.15);border-radius:50%;
                                    display:flex;align-items:center;justify-content:center;border:3px solid rgba(255,255,255,.3);">
                            <i class="bi bi-arrow-left-right text-white" style="font-size:1.9rem;"></i>
                        </div>
                        <h6 class="fw-bold mt-3 mb-1 text-white text-center">New Transfer</h6>
                        <p class="text-white-50 small text-center mb-0">Request to LGU</p>
                    </div>
                    <div class="flex-fill p-4">
                        <h6 class="fw-bold mb-3" style="color:#1a4f7a;">Transfer Details</h6>

                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Resident <span class="text-danger">*</span></label>
                            <select name="resident_id" class="form-select form-select-sm" required>
                                <option value="">— Select a resident —</option>
                                <?php foreach ($myResidents as $res): ?>
                                <option value="<?= $res['id'] ?>"><?= htmlspecialchars($res['display_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Only Active residents without an ongoing transfer are shown.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Destination Barangay <span class="text-danger">*</span></label>
                            <select name="destination_barangay_id" class="form-select form-select-sm" required>
                                <option value="">— Select destination —</option>
                                <?php foreach ($otherBarangays as $b): ?>
                                <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['barangay_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Reason <span class="text-danger">*</span></label>
                            <select name="reason" class="form-select form-select-sm">
                                <option value="Employment">Employment</option>
                                <option value="Residence Change">Residence Change</option>
                                <option value="Family">Family</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Additional Notes <span class="text-muted">(optional)</span></label>
                            <textarea name="notes" class="form-control form-control-sm" rows="2"
                                      placeholder="Any supporting details..."></textarea>
                        </div>

                        <div class="alert alert-warning py-2 small mb-0">
                            <i class="bi bi-info-circle me-1"></i>
                            Sent to <strong>LGU Superadmin</strong> for approval.
                            The destination barangay will only be notified <strong>after LGU approval</strong>.
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
<script>function toggleSidebar(){document.getElementById('sidebar').classList.toggle('show');}</script>

<script src="<?= BASE_URL ?>/assets/js/autocomplete.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    initAutocomplete('trSearchInput', 'trAcList', '<?= BASE_URL ?>/ajax/search_transfer_requests.php', 'trSearchClear');
});
</script>

<script src="<?= BASE_URL ?>/assets/js/autocomplete.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    initAutocomplete('trSearchInput', 'trAcList', '<?= BASE_URL ?>/ajax/search_transfer_requests.php', 'trSearchClear');
});
</script>
</body>
</html>

