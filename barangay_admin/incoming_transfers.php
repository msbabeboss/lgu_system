<?php
require_once __DIR__ . '/../config/config.php';
requireRole('barangay_admin');

$db  = Database::getInstance()->getConnection();
$u   = currentUser();
$bid = (int)$u['barangay_id'];

// ── Ensure table exists ─────────────────────────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS resident_transfer_requests (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    requester_user_id       INT UNSIGNED NOT NULL,
    origin_barangay_id      INT UNSIGNED NOT NULL,
    resident_id             INT UNSIGNED NULL,
    resident_name           VARCHAR(150) NOT NULL,
    transfer_type           ENUM('within_barangay','outside_barangay') NOT NULL DEFAULT 'outside_barangay',
    destination_barangay_id INT UNSIGNED NULL,
    reason                  ENUM('Employment','Residence Change','Family','Other') NOT NULL DEFAULT 'Other',
    additional_info         TEXT NULL,
    status                  ENUM('pending_lgu_approval','lgu_approved','pending_resident_appearance','pending_documentation','transfer_completed','rejected','revision_requested') NOT NULL DEFAULT 'pending_lgu_approval',
    lgu_reviewed_by         INT UNSIGNED NULL,
    lgu_reviewed_at         DATETIME NULL,
    lgu_notes               TEXT NULL,
    doc_personal_appearance TINYINT(1) DEFAULT 0,
    doc_credentials         TINYINT(1) DEFAULT 0,
    doc_info_verified       TINYINT(1) DEFAULT 0,
    doc_photo_captured      TINYINT(1) DEFAULT 0,
    doc_barangay_docs       TINYINT(1) DEFAULT 0,
    doc_completed_by        INT UNSIGNED NULL,
    doc_completed_at        DATETIME NULL,
    created_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (requester_user_id)       REFERENCES users(id)     ON DELETE CASCADE,
    FOREIGN KEY (origin_barangay_id)      REFERENCES barangays(id) ON DELETE CASCADE,
    FOREIGN KEY (resident_id)             REFERENCES residents(id)  ON DELETE SET NULL,
    FOREIGN KEY (destination_barangay_id) REFERENCES barangays(id) ON DELETE SET NULL,
    FOREIGN KEY (lgu_reviewed_by)         REFERENCES users(id)     ON DELETE SET NULL,
    FOREIGN KEY (doc_completed_by)        REFERENCES users(id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── POST: Update documentation checklist ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrAbort();
    $action = $_POST['action'] ?? '';
    $reqId  = (int)($_POST['request_id'] ?? 0);

    if (!$reqId) {
        flash('it', 'Invalid request.', 'danger');
        redirect(BASE_URL . '/barangay_admin/incoming_transfers.php');
    }

    // Verify this transfer is actually for our barangay
    $chk = $db->prepare("SELECT * FROM resident_transfer_requests WHERE id=? AND destination_barangay_id=?");
    $chk->execute([$reqId, $bid]);
    $req = $chk->fetch();

    if (!$req) {
        flash('it', 'Transfer request not found or not assigned to your barangay.', 'danger');
        redirect(BASE_URL . '/barangay_admin/incoming_transfers.php');
    }

    // ── Mark resident has appeared ────────────────────────────────────────
    if ($action === 'mark_appeared' && $req['status'] === 'lgu_approved') {
        $db->prepare("UPDATE resident_transfer_requests
            SET status='pending_resident_appearance', doc_personal_appearance=1
            WHERE id=?")
           ->execute([$reqId]);

        // Notify the origin barangay admin
        sendNotification(
            (int)$req['requester_user_id'],
            "👤 Resident <strong>{$req['resident_name']}</strong> has personally appeared at the destination barangay. Documentation is now being processed.",
            'info', 'transaction'
        );

        // Notify super admin
        notifySuperAdmins(
            "👤 Transfer #$reqId: <strong>{$req['resident_name']}</strong> has appeared at destination barangay. Documentation in progress.",
            'info', 'transaction'
        );

        logActivity($u['id'], 'TRANSFER_RESIDENT_APPEARED', 'Resident Transfer',
            "Resident {$req['resident_name']} appeared at destination barangay for transfer #{$reqId}");
        flash('it', "Resident appearance recorded. You can now complete the documentation checklist.", 'success');
        redirect(BASE_URL . '/barangay_admin/incoming_transfers.php');
    }

    // ── Update documentation checklist ───────────────────────────────────
    if ($action === 'update_docs' && in_array($req['status'], ['pending_resident_appearance','pending_documentation'])) {
        $docAppearance = isset($_POST['doc_personal_appearance']) ? 1 : 0;
        $docCreds      = isset($_POST['doc_credentials'])         ? 1 : 0;
        $docInfoVer    = isset($_POST['doc_info_verified'])        ? 1 : 0;
        $docPhoto      = isset($_POST['doc_photo_captured'])       ? 1 : 0;
        $docBrgyDocs   = isset($_POST['doc_barangay_docs'])        ? 1 : 0;

        // Determine new status
        $allDone = ($docAppearance && $docCreds && $docInfoVer && $docPhoto && $docBrgyDocs);

        if ($allDone) {
            $newStatus = 'transfer_completed';
        } elseif ($docAppearance) {
            // At least appeared — check if any doc work started
            $anyDoc = ($docCreds || $docInfoVer || $docPhoto || $docBrgyDocs);
            $newStatus = $anyDoc ? 'pending_documentation' : 'pending_resident_appearance';
        } else {
            $newStatus = 'pending_resident_appearance';
        }

        $db->prepare("UPDATE resident_transfer_requests
            SET doc_personal_appearance=?, doc_credentials=?, doc_info_verified=?,
                doc_photo_captured=?, doc_barangay_docs=?, status=?,
                doc_completed_by=IF(?,?,doc_completed_by),
                doc_completed_at=IF(?,NOW(),doc_completed_at)
            WHERE id=?")
           ->execute([
               $docAppearance, $docCreds, $docInfoVer, $docPhoto, $docBrgyDocs,
               $newStatus,
               $allDone, $u['id'],    // doc_completed_by conditional
               $allDone,              // doc_completed_at conditional
               $reqId
           ]);

        if ($allDone) {
            // Notify origin barangay admin — transfer complete
            sendNotification(
                (int)$req['requester_user_id'],
                "✅ Resident Transfer for <strong>{$req['resident_name']}</strong> is now <strong>completed</strong>. All documentation has been finalized by the destination barangay.",
                'success', 'transaction'
            );

            // Notify super admin — transfer complete
            notifySuperAdmins(
                "✅ Transfer #$reqId: <strong>{$req['resident_name']}</strong> — Transfer Completed. All documentation finalized by destination barangay.",
                'success', 'transaction'
            );

            // Update resident record status if linked
            if ($req['resident_id']) {
                $db->prepare("UPDATE residents
                    SET resident_status='Transferred',
                        transfer_to_barangay=(SELECT barangay_name FROM barangays WHERE id=?),
                        transfer_reason=?
                    WHERE id=? AND barangay_id=?")
                   ->execute([$bid, $req['reason'], $req['resident_id'], $req['origin_barangay_id']]);

                // ── Auto-inactivate official if this resident is a linked official ──
                // Match priority: 1) official_id FK  2) name match  3) contact number fallback
                // Only sets status to inactive — no auto archive. Admin archives manually.
                $resRow = $db->prepare("SELECT first_name, middle_name, last_name, contact_number, official_id FROM residents WHERE id=?");
                $resRow->execute([$req['resident_id']]);
                $resData = $resRow->fetch();

                $matchedOff = null;
                if ($resData) {
                    if (!empty($resData['official_id'])) {
                        // 1. Direct FK match — most reliable
                        $offStmt = $db->prepare(
                            "SELECT id, full_name, position FROM officials
                             WHERE id=? AND barangay_id=? AND status='active' LIMIT 1"
                        );
                        $offStmt->execute([$resData['official_id'], $req['origin_barangay_id']]);
                        $matchedOff = $offStmt->fetch() ?: null;
                    }

                    if (!$matchedOff) {
                        // 2. Name match — build "Last, First Middle" the same way officials.php stores it
                        $fn  = trim($resData['first_name']  ?? '');
                        $mn  = trim($resData['middle_name'] ?? '');
                        $ln  = trim($resData['last_name']   ?? '');
                        // Format: "LastName, FirstName MiddleName" (middle optional)
                        $builtName = trim($ln . ', ' . $fn . ($mn !== '' ? ' ' . $mn : ''));
                        if ($builtName !== ', ') {
                            $offStmt = $db->prepare(
                                "SELECT id, full_name, position FROM officials
                                 WHERE barangay_id=? AND status='active'
                                   AND TRIM(full_name) = TRIM(?) LIMIT 1"
                            );
                            $offStmt->execute([$req['origin_barangay_id'], $builtName]);
                            $matchedOff = $offStmt->fetch() ?: null;
                        }
                    }

                    if (!$matchedOff && !empty($resData['contact_number'])) {
                        // 3. Contact number fallback for legacy records
                        $offStmt = $db->prepare(
                            "SELECT id, full_name, position FROM officials
                             WHERE barangay_id=? AND contact=? AND status='active' LIMIT 1"
                        );
                        $offStmt->execute([$req['origin_barangay_id'], $resData['contact_number']]);
                        $matchedOff = $offStmt->fetch() ?: null;
                    }
                }

                if ($matchedOff) {
                    $db->prepare("UPDATE officials SET status='inactive' WHERE id=?")
                       ->execute([$matchedOff['id']]);

                    logActivity($u['id'], 'AUTO_INACTIVATE_OFFICIAL', 'Officials',
                        "Official {$matchedOff['full_name']} ({$matchedOff['position']}) auto-inactivated: linked resident transferred out of barangay");

                    notifyBarangayAdmins(
                        (int)$req['origin_barangay_id'],
                        "⚠️ Official <strong>{$matchedOff['full_name']}</strong> (<em>{$matchedOff['position']}</em>) has been automatically set to <strong>inactive</strong> because the linked resident was transferred out of the barangay. You may archive this official from the Officials page.",
                        'warning', 'system'
                    );
                    notifySuperAdmins(
                        "⚠️ Official <strong>{$matchedOff['full_name']}</strong> of barangay #{$req['origin_barangay_id']} auto-inactivated due to resident transfer #$reqId.",
                        'warning', 'system'
                    );
                }
            }

            logActivity($u['id'], 'TRANSFER_COMPLETED', 'Resident Transfer',
                "Completed all documentation for transfer #{$reqId} — {$req['resident_name']}");
            flash('it', "Transfer for <strong>{$req['resident_name']}</strong> is now <strong>completed</strong>. The origin barangay has been notified.", 'success');
        } else {
            logActivity($u['id'], 'UPDATE_TRANSFER_DOCS', 'Resident Transfer',
                "Updated documentation checklist for transfer #{$reqId} — {$req['resident_name']}");
            flash('it', 'Documentation checklist updated.', 'info');
        }

        redirect(BASE_URL . '/barangay_admin/incoming_transfers.php');
    }

    flash('it', 'Invalid action or transfer state.', 'danger');
    redirect(BASE_URL . '/barangay_admin/incoming_transfers.php');
}

// ── Filters ─────────────────────────────────────────────────────────────────
$statusFilter = sanitize($_GET['status'] ?? '');
$search       = sanitize($_GET['search'] ?? '');

$where = "WHERE r.destination_barangay_id = $bid";
if ($statusFilter) $where .= " AND r.status = " . $db->quote($statusFilter);
if ($search)       $where .= " AND (r.resident_name LIKE " . $db->quote("%$search%")
                           . " OR b1.barangay_name LIKE " . $db->quote("%$search%") . ")";

$totalCount = (int)$db->query("
    SELECT COUNT(*) FROM resident_transfer_requests r
    LEFT JOIN barangays b1 ON r.origin_barangay_id = b1.id
    $where
")->fetchColumn();

$perPage    = 10;
$page       = max(1, (int)($_GET['page'] ?? 1));
$totalPages = max(1, (int)ceil($totalCount / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$transfers = $db->query("
    SELECT r.*,
           b1.barangay_name AS origin_barangay_name,
           b2.barangay_name AS destination_barangay_name,
           CONCAT(ru.first_name,' ',ru.last_name) AS requester_name,
           CONCAT(dc.first_name,' ',dc.last_name) AS doc_completed_by_name
    FROM resident_transfer_requests r
    LEFT JOIN barangays b1 ON r.origin_barangay_id      = b1.id
    LEFT JOIN barangays b2 ON r.destination_barangay_id = b2.id
    LEFT JOIN users ru     ON r.requester_user_id       = ru.id
    LEFT JOIN users dc     ON r.doc_completed_by        = dc.id
    $where
    ORDER BY FIELD(r.status,'lgu_approved','pending_resident_appearance','pending_documentation','transfer_completed'), r.created_at DESC
    LIMIT $perPage OFFSET $offset
")->fetchAll();

// Count pending action (approved by LGU but not yet fully done)
$pendingAction = (int)$db->query("
    SELECT COUNT(*) FROM resident_transfer_requests
    WHERE destination_barangay_id=$bid
      AND status IN ('lgu_approved','pending_resident_appearance','pending_documentation')
")->fetchColumn();

$flash = getFlash('it');

$statusCfg = [
    'pending_lgu_approval'        => ['label'=>'Pending LGU Approval',      'color'=>'warning',   'icon'=>'bi-hourglass-split'],
    'lgu_approved'                => ['label'=>'LGU Approved – Action Needed','color'=>'danger',   'icon'=>'bi-bell-fill'],
    'pending_resident_appearance' => ['label'=>'Pending Resident Appearance','color'=>'primary',   'icon'=>'bi-person-walking'],
    'pending_documentation'       => ['label'=>'Pending Documentation',      'color'=>'secondary', 'icon'=>'bi-file-earmark-text'],
    'transfer_completed'          => ['label'=>'Transfer Completed',         'color'=>'success',   'icon'=>'bi-check2-all'],
    'rejected'                    => ['label'=>'Rejected',                   'color'=>'danger',    'icon'=>'bi-x-circle-fill'],
    'revision_requested'          => ['label'=>'Revision Requested',         'color'=>'orange',    'icon'=>'bi-pencil-square'],
];

$pageIcon     = 'bi-box-arrow-in-down-right';
$pageTitle    = 'Incoming Transfers';
$pageSubtitle = 'Residents approved by LGU to transfer into your barangay.';
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
    <style>
        .status-badge-orange { background-color:#fd7e14; color:#fff; }
        .doc-item { display:flex; align-items:center; gap:10px; padding:10px 14px;
                    border-bottom:1px solid #f0f4f8; font-size:0.88rem; }
        .doc-item:last-child { border-bottom:none; }
        .doc-item:hover { background:#f8faff; }
        .doc-item .form-check-input { width:1.1em; height:1.1em; cursor:pointer; }
        .step-dot { width:28px; height:28px; border-radius:50%; display:flex; align-items:center;
                    justify-content:center; flex-shrink:0; font-size:0.72rem; font-weight:700; }
        .step-done     { background:#198754; color:#fff; }
        .step-active   { background:#1a4f7a; color:#fff; }
        .step-pending  { background:#e9ecef; color:#6c757d; }
        .step-rejected { background:#dc3545; color:#fff; }
        .transfer-step { display:flex; align-items:flex-start; gap:10px; padding:8px 0;
                         border-bottom:1px solid #f0f0f0; }
        .transfer-step:last-child { border-bottom:none; }
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

    <!-- Header -->
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
        <div>
            <h6 class="fw-bold mb-0">Incoming Transfers</h6>
            <small class="text-muted">Residents approved by the LGU for transfer to your barangay.</small>
        </div>
        <?php if ($pendingAction > 0): ?>
        <span class="badge bg-danger px-3 py-2" style="font-size:0.82rem;">
            <i class="bi bi-bell-fill me-1"></i><?= $pendingAction ?> requiring action
        </span>
        <?php endif; ?>
    </div>

    <!-- Info banner -->
    <div class="alert alert-warning d-flex gap-2 align-items-start py-2 mb-3 small" style="border-radius:10px;">
        <i class="bi bi-person-walking flex-shrink-0 mt-1" style="font-size:1.1rem;"></i>
        <div>
            <strong>Important:</strong> After LGU approval, the resident must <strong>personally visit your barangay</strong>
            to complete credentials, verification, and photo capture before the transfer can be marked as completed.
        </div>
    </div>

    <!-- Filters -->
    <div class="card mb-3 border-0 shadow-sm">
        <div class="card-body py-2">
            <form method="GET" class="d-flex gap-2 flex-wrap align-items-center" id="filterForm">
                <div class="input-group input-group-sm ac-wrapper" style="width:280px;">
                    <span class="input-group-text bg-white border-end-0" style="border-color:#dee2e6;">
                        <i class="bi bi-search text-muted" style="font-size:0.8rem;"></i>
                    </span>
                    <input type="text" id="itSearchInput" name="search" class="form-control form-control-sm border-start-0 border-end-0 ps-0"
                           style="border-color:#dee2e6;"
                           placeholder="Search resident or origin barangay..."
                           autocomplete="off"
                           value="<?= htmlspecialchars($search) ?>">
                    <span class="input-group-text bg-white border-start-0 p-0" style="border-color:#dee2e6;">
                        <span id="itSearchClear" title="Clear search"
                              style="width:28px;height:28px;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;color:#9ca3af;border-radius:4px;<?= $search ? '' : 'visibility:hidden;' ?>"
                              onmouseover="this.style.color='#dc2626';this.style.background='#fee2e2';"
                              onmouseout="this.style.color='#9ca3af';this.style.background='transparent';">
                            <i class="bi bi-x-lg" style="font-size:0.75rem;"></i>
                        </span>
                    </span>
                    <div class="ac-list" id="itAcList"></div>
                </div>
                <select name="status" class="form-select form-select-sm" style="width:220px;">
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

    <!-- Table -->
    <div class="card border-0 shadow-sm" style="border-radius:12px;overflow:hidden;">
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:0.85rem;">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4 py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;">#</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;">Resident</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;">From Barangay</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;">Reason</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;">Documentation</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;">Status</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;">LGU Approved</th>
                        <th class="pe-4 py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($transfers)): ?>
                <tr>
                    <td colspan="8" class="text-center text-muted py-5">
                        <i class="bi bi-box-arrow-in-down-right" style="font-size:2.5rem;opacity:0.3;display:block;margin-bottom:10px;"></i>
                        No incoming transfers found.
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($transfers as $i => $tr):
                    $sc = $statusCfg[$tr['status']] ?? ['label'=>$tr['status'],'color'=>'secondary','icon'=>'bi-circle'];
                    $colorClass = $sc['color'] === 'orange' ? 'status-badge-orange' : 'bg-'.$sc['color'];

                    // Documentation progress bar
                    $docFields = ['doc_personal_appearance','doc_credentials','doc_info_verified','doc_photo_captured','doc_barangay_docs'];
                    $docDone   = array_sum(array_map(fn($f) => (int)$tr[$f], $docFields));
                    $docPct    = round(($docDone / 5) * 100);

                    $canProcess = in_array($tr['status'], ['lgu_approved','pending_resident_appearance','pending_documentation']);
                    $isApprovedNotYetAppeared = ($tr['status'] === 'lgu_approved');
                ?>
                <tr>
                    <td class="ps-4 py-3">
                        <?= $offset+$i+1 ?>
                        <?php if ($tr['status'] === 'lgu_approved'): ?>
                        <span class="badge bg-danger ms-1" style="font-size:0.6rem;">ACTION</span>
                        <?php endif; ?>
                    </td>
                    <td class="py-3 fw-semibold"><?= htmlspecialchars($tr['resident_name']) ?></td>
                    <td class="py-3 small"><?= htmlspecialchars($tr['origin_barangay_name'] ?? '—') ?></td>
                    <td class="py-3 small"><?= htmlspecialchars($tr['reason']) ?></td>
                    <td class="py-3" style="min-width:120px;">
                        <?php if ($tr['status'] === 'transfer_completed'): ?>
                            <span class="text-success small fw-semibold"><i class="bi bi-check2-all me-1"></i>All Done</span>
                        <?php elseif ($tr['status'] === 'lgu_approved'): ?>
                            <span class="text-muted small fst-italic">Awaiting appearance</span>
                        <?php elseif ($tr['status'] === 'pending_lgu_approval'): ?>
                            <span class="text-muted small fst-italic">Pending LGU</span>
                        <?php else: ?>
                            <div class="progress" style="height:6px;border-radius:4px;" title="<?= $docDone ?>/5 steps done">
                                <div class="progress-bar bg-<?= $docPct === 100 ? 'success' : 'primary' ?>"
                                     style="width:<?= $docPct ?>%;"></div>
                            </div>
                            <div class="text-muted mt-1" style="font-size:0.72rem;"><?= $docDone ?>/5 completed</div>
                        <?php endif; ?>
                    </td>
                    <td class="py-3">
                        <span class="badge <?= $colorClass ?>">
                            <i class="bi <?= $sc['icon'] ?> me-1"></i><?= $sc['label'] ?>
                        </span>
                    </td>
                    <td class="py-3 small text-nowrap">
                        <?php if ($tr['lgu_reviewed_at']): ?>
                            <?= date('M d, Y', strtotime($tr['lgu_reviewed_at'])) ?>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="pe-4 py-3">
                        <?php if ($canProcess): ?>
                        <button class="btn btn-xs btn-primary mb-1 w-100"
                                onclick='openDocsModal(<?= htmlspecialchars(json_encode($tr), ENT_QUOTES) ?>)'>
                            <i class="bi bi-clipboard-check me-1"></i>Process
                        </button>
                        <?php else: ?>
                        <button class="btn btn-xs btn-outline-secondary w-100"
                                onclick='openDocsModal(<?= htmlspecialchars(json_encode($tr), ENT_QUOTES) ?>)'>
                            <i class="bi bi-eye me-1"></i>View
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

    <!-- Pagination -->
    <?php
    $paginationBase  = '?' . http_build_query(array_filter(['search'=>$search,'status'=>$statusFilter]));
    $paginationBase  = ($paginationBase === '?') ? '?' : $paginationBase . '&';
    $paginationLabel = 'transfer';
    include __DIR__ . '/../includes/pagination.php';
    ?>

</div><!-- /main-content -->

<!-- ══════════════════════════════════════════════════════════
     DOCUMENTATION PROCESS MODAL
     ══════════════════════════════════════════════════════════ -->
<div class="modal fade" id="docsModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;overflow:hidden;">
            <div class="d-flex">
                <!-- LEFT PANEL -->
                <div class="d-flex flex-column align-items-center justify-content-center px-3 py-5 flex-shrink-0"
                     style="background:linear-gradient(160deg,#1a4f7a 0%,#3a7fa8 100%);width:190px;min-width:190px;">
                    <div style="width:68px;height:68px;background:rgba(255,255,255,0.15);border-radius:50%;display:flex;align-items:center;justify-content:center;border:3px solid rgba(255,255,255,0.3);">
                        <i class="bi bi-clipboard-check text-white" style="font-size:1.9rem;"></i>
                    </div>
                    <h6 class="fw-bold mt-3 mb-1 text-white text-center" style="font-size:0.95rem;">Documentation</h6>
                    <p class="text-white-50 small text-center mb-3">Incoming Transfer</p>

                    <!-- Progress circle placeholder -->
                    <div id="modal_progress_wrap" class="text-center">
                        <div class="fw-bold text-white" style="font-size:1.6rem;" id="modal_progress_pct">0%</div>
                        <div class="text-white-50 small">completed</div>
                    </div>
                </div>

                <!-- RIGHT PANEL -->
                <div class="flex-fill p-4">
                    <!-- Resident info -->
                    <div class="d-flex align-items-start gap-3 mb-3 pb-3 border-bottom">
                        <div style="width:46px;height:46px;border-radius:50%;background:#e8f0fe;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <i class="bi bi-person-fill text-primary" style="font-size:1.3rem;"></i>
                        </div>
                        <div>
                            <div class="fw-bold" id="dm_name"></div>
                            <div class="text-muted small" id="dm_origin"></div>
                            <div class="mt-1" id="dm_status_badge"></div>
                        </div>
                    </div>

                    <!-- Transfer info row -->
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <div class="small text-muted fw-semibold">Transfer Reason</div>
                            <div class="small fw-semibold" id="dm_reason"></div>
                        </div>
                        <div class="col-6">
                            <div class="small text-muted fw-semibold">LGU Approved On</div>
                            <div class="small fw-semibold" id="dm_lgu_date"></div>
                        </div>
                    </div>

                    <!-- Resident appearance notice (shown only when status = lgu_approved) -->
                    <div id="dm_appearance_notice" class="alert alert-danger py-2 small mb-3" style="display:none;">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>
                        <strong>Resident has not yet appeared.</strong> The resident must visit your barangay office personally before documentation can proceed.
                        <form method="POST" class="mt-2 d-inline-block">
                            <input type="hidden" name="action" value="mark_appeared">
                            <input type="hidden" name="request_id" id="dm_appeared_req_id" value="">
                            <?= csrfField() ?>
                            <button type="submit" class="btn btn-sm btn-danger fw-semibold">
                                <i class="bi bi-person-check me-1"></i>Mark Resident as Appeared
                            </button>
                        </form>
                    </div>

                    <!-- Documentation checklist form -->
                    <form method="POST" id="docsForm">
                        <input type="hidden" name="action" value="update_docs">
                        <input type="hidden" name="request_id" id="dm_req_id" value="">
                        <?= csrfField() ?>

                        <div class="fw-semibold small mb-2" style="color:#1a4f7a;">
                            <i class="bi bi-clipboard-check me-1"></i>Documentation Checklist
                        </div>
                        <div class="card border-0 shadow-sm mb-3" style="border-radius:10px;overflow:hidden;" id="dm_doc_card">

                            <div class="doc-item" id="di_1">
                                <input class="form-check-input flex-shrink-0" type="checkbox"
                                       name="doc_personal_appearance" id="chk_appearance"
                                       onchange="onDocChange()">
                                <div class="flex-fill">
                                    <label for="chk_appearance" class="fw-semibold" style="cursor:pointer;">
                                        <i class="bi bi-person-check-fill me-1 text-primary"></i>
                                        Personal appearance verified
                                    </label>
                                    <div class="text-muted small">Resident physically visited the barangay office</div>
                                </div>
                                <span class="doc-status-pill" id="pill_1"></span>
                            </div>

                            <div class="doc-item" id="di_2">
                                <input class="form-check-input flex-shrink-0" type="checkbox"
                                       name="doc_credentials" id="chk_credentials"
                                       onchange="onDocChange()">
                                <div class="flex-fill">
                                    <label for="chk_credentials" class="fw-semibold" style="cursor:pointer;">
                                        <i class="bi bi-card-text me-1 text-info"></i>
                                        Credentials submitted
                                    </label>
                                    <div class="text-muted small">Valid ID and supporting documents received</div>
                                </div>
                                <span class="doc-status-pill" id="pill_2"></span>
                            </div>

                            <div class="doc-item" id="di_3">
                                <input class="form-check-input flex-shrink-0" type="checkbox"
                                       name="doc_info_verified" id="chk_info_verified"
                                       onchange="onDocChange()">
                                <div class="flex-fill">
                                    <label for="chk_info_verified" class="fw-semibold" style="cursor:pointer;">
                                        <i class="bi bi-shield-check me-1 text-success"></i>
                                        Resident information verified
                                    </label>
                                    <div class="text-muted small">Personal details confirmed and recorded</div>
                                </div>
                                <span class="doc-status-pill" id="pill_3"></span>
                            </div>

                            <div class="doc-item" id="di_4">
                                <input class="form-check-input flex-shrink-0" type="checkbox"
                                       name="doc_photo_captured" id="chk_photo"
                                       onchange="onDocChange()">
                                <div class="flex-fill">
                                    <label for="chk_photo" class="fw-semibold" style="cursor:pointer;">
                                        <i class="bi bi-camera-fill me-1 text-warning"></i>
                                        Photo captured
                                    </label>
                                    <div class="text-muted small">Current photo taken and saved to records</div>
                                </div>
                                <span class="doc-status-pill" id="pill_4"></span>
                            </div>

                            <div class="doc-item" id="di_5">
                                <input class="form-check-input flex-shrink-0" type="checkbox"
                                       name="doc_barangay_docs" id="chk_brgy_docs"
                                       onchange="onDocChange()">
                                <div class="flex-fill">
                                    <label for="chk_brgy_docs" class="fw-semibold" style="cursor:pointer;">
                                        <i class="bi bi-file-earmark-check-fill me-1 text-secondary"></i>
                                        Barangay documentation completed
                                    </label>
                                    <div class="text-muted small">All barangay forms and registration completed</div>
                                </div>
                                <span class="doc-status-pill" id="pill_5"></span>
                            </div>

                        </div>

                        <!-- All done notice -->
                        <div id="dm_all_done_notice" class="alert alert-success py-2 small mb-3" style="display:none;">
                            <i class="bi bi-check2-all me-1"></i>
                            <strong>All items completed!</strong> Saving will mark this transfer as <strong>Transfer Completed</strong>.
                        </div>

                        <div id="dm_action_btns">
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-outline-secondary flex-fill" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-primary flex-fill fw-semibold" id="dm_save_btn">
                                    <i class="bi bi-save me-1"></i>Save Progress
                                </button>
                            </div>
                        </div>
                    </form>

                    <!-- View-only footer (completed transfers) -->
                    <div id="dm_readonly_footer" style="display:none;">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                    </div>

                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('show'); }

const statusCfg = <?= json_encode(array_map(fn($v) => ['label'=>$v['label'],'color'=>$v['color'],'icon'=>$v['icon']], $statusCfg)) ?>;

const docCheckboxIds = ['chk_appearance','chk_credentials','chk_info_verified','chk_photo','chk_brgy_docs'];
const docFieldKeys   = ['doc_personal_appearance','doc_credentials','doc_info_verified','doc_photo_captured','doc_barangay_docs'];

function openDocsModal(r) {
    const isCompleted  = r.status === 'transfer_completed';
    const canProcess   = ['lgu_approved','pending_resident_appearance','pending_documentation'].includes(r.status);
    const justApproved = r.status === 'lgu_approved';

    // Populate header info
    document.getElementById('dm_name').textContent   = r.resident_name || '—';
    document.getElementById('dm_origin').textContent = 'From: ' + (r.origin_barangay_name || '—');
    document.getElementById('dm_reason').textContent  = r.reason || '—';

    const sc = statusCfg[r.status] || { label: r.status, color:'secondary', icon:'bi-circle' };
    const cc = sc.color === 'orange' ? 'status-badge-orange' : 'bg-' + sc.color;
    document.getElementById('dm_status_badge').innerHTML =
        `<span class="badge ${cc} small"><i class="bi ${sc.icon} me-1"></i>${sc.label}</span>`;

    document.getElementById('dm_lgu_date').textContent = r.lgu_reviewed_at
        ? r.lgu_reviewed_at.substring(0, 10) : '—';

    document.getElementById('dm_req_id').value          = r.id;
    document.getElementById('dm_appeared_req_id').value = r.id;

    // Show/hide appearance notice
    document.getElementById('dm_appearance_notice').style.display = justApproved ? '' : 'none';

    // Populate checkboxes
    docFieldKeys.forEach(function(key, idx) {
        const chk = document.getElementById(docCheckboxIds[idx]);
        const val = parseInt(r[key] || 0);
        chk.checked  = val === 1;
        chk.disabled = isCompleted || justApproved; // disable if completed or not yet appeared
    });

    // Progress
    updateProgressDisplay();

    // Show/hide action buttons
    document.getElementById('dm_action_btns').style.display    = (!isCompleted && !justApproved) ? '' : 'none';
    document.getElementById('dm_readonly_footer').style.display = (isCompleted || justApproved)  ? '' : 'none';

    // Save button label
    const saveBtn = document.getElementById('dm_save_btn');
    if (saveBtn) saveBtn.innerHTML = '<i class="bi bi-save me-1"></i>Save Progress';

    new bootstrap.Modal(document.getElementById('docsModal')).show();
}

function onDocChange() {
    updateProgressDisplay();
}

function updateProgressDisplay() {
    let done = 0;
    docCheckboxIds.forEach(function(id) {
        if (document.getElementById(id)?.checked) done++;
    });
    const pct = Math.round((done / 5) * 100);
    document.getElementById('modal_progress_pct').textContent = pct + '%';

    const allDone = done === 5;
    document.getElementById('dm_all_done_notice').style.display = allDone ? '' : 'none';

    const saveBtn = document.getElementById('dm_save_btn');
    if (saveBtn) {
        saveBtn.innerHTML = allDone
            ? '<i class="bi bi-check2-all me-1"></i>Complete Transfer'
            : '<i class="bi bi-save me-1"></i>Save Progress';
        saveBtn.className = allDone
            ? 'btn btn-success flex-fill fw-semibold'
            : 'btn btn-primary flex-fill fw-semibold';
    }

    // Update pill indicators
    for (let i = 1; i <= 5; i++) {
        const pill = document.getElementById('pill_' + i);
        const chk  = document.getElementById(docCheckboxIds[i-1]);
        if (pill && chk) {
            pill.innerHTML = chk.checked
                ? '<i class="bi bi-check-circle-fill text-success" style="font-size:1.1rem;"></i>'
                : '<i class="bi bi-circle text-muted" style="font-size:1.1rem;opacity:0.4;"></i>';
        }
    }
}

// Initialise pills on load
document.addEventListener('DOMContentLoaded', function() {
    updateProgressDisplay();
});
</script>
<style>
.btn-xs { padding:2px 8px; font-size:0.75rem; }
</style>

<script src="<?= BASE_URL ?>/assets/js/autocomplete.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    initAutocomplete('itSearchInput', 'itAcList', '<?= BASE_URL ?>/ajax/search_incoming_transfers.php', 'itSearchClear');
});
</script>
</body>
</html>
