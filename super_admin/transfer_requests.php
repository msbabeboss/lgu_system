<?php
require_once __DIR__ . '/../config/config.php';
requireRole('super_admin');

$db = Database::getInstance()->getConnection();
$u  = currentUser();

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

// Safe column migrations — idempotent, handle old table schemas
try { $db->exec("ALTER TABLE resident_transfer_requests ADD COLUMN notes TEXT NULL"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE resident_transfer_requests ADD COLUMN reviewed_by INT UNSIGNED NULL"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE resident_transfer_requests ADD COLUMN reviewed_at DATETIME NULL"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE resident_transfer_requests ADD COLUMN admin_notes TEXT NULL"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE resident_transfer_requests ADD COLUMN verified_by INT UNSIGNED NULL"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE resident_transfer_requests ADD COLUMN verified_at DATETIME NULL"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE resident_transfer_requests MODIFY COLUMN status ENUM('pending','approved','rejected','verified') NOT NULL DEFAULT 'pending'"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE resident_transfer_requests MODIFY COLUMN reason ENUM('Employment','Residence Change','Family','Other') NOT NULL DEFAULT 'Other'"); } catch (PDOException $e) {}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrAbort();

    $action = $_POST['action'] ?? '';
    $reqId  = (int)($_POST['request_id'] ?? 0);

    if (!$reqId || !in_array($action, ['approve','reject'])) {
        flash('tr', 'Invalid action.', 'danger');
        redirect(BASE_URL . '/super_admin/transfer_requests.php');
    }

    // Load the request
    $stmt = $db->prepare("
        SELECT r.*,
               b1.barangay_name AS origin_name,
               b2.barangay_name AS dest_name,
               CONCAT(u.first_name,' ',u.last_name) AS requester_name
        FROM resident_transfer_requests r
        LEFT JOIN barangays b1 ON r.origin_barangay_id      = b1.id
        LEFT JOIN barangays b2 ON r.destination_barangay_id = b2.id
        LEFT JOIN users u      ON r.requester_user_id       = u.id
        WHERE r.id = ?
    ");
    $stmt->execute([$reqId]);
    $req = $stmt->fetch();

    if (!$req || $req['status'] !== 'pending') {
        flash('tr', 'Request not found or already processed.', 'warning');
        redirect(BASE_URL . '/super_admin/transfer_requests.php');
    }

    $adminNotes = sanitize($_POST['admin_notes'] ?? '');
    $newStatus  = ($action === 'approve') ? 'approved' : 'rejected';

    $db->prepare("UPDATE resident_transfer_requests
        SET status=?, reviewed_by=?, reviewed_at=NOW(), admin_notes=?
        WHERE id=?")
       ->execute([$newStatus, $u['id'], $adminNotes, $reqId]);

    if ($action === 'approve') {
        // Update origin resident record: mark as Transferred
        $db->prepare("UPDATE residents
            SET resident_status='Transferred',
                transfer_to_barangay=?,
                transfer_reason=?
            WHERE id=? AND barangay_id=?")
           ->execute([$req['dest_name'], $req['reason'], $req['resident_id'], $req['origin_barangay_id']]);

        // ── Auto-inactivate official if this resident is a linked official ──
        // Match priority: 1) official_id FK  2) name match  3) contact number fallback
        // Only sets status to inactive — no auto archive. Admin archives manually.
        $resRowStmt = $db->prepare("SELECT first_name, middle_name, last_name, contact_number, official_id FROM residents WHERE id=?");
        $resRowStmt->execute([$req['resident_id']]);
        $resData = $resRowStmt->fetch();

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
                "⚠️ Official <strong>{$matchedOff['full_name']}</strong> of barangay #{$req['origin_barangay_id']} auto-inactivated due to resident transfer #{$reqId}.",
                'warning', 'system'
            );
        }

        // Notify origin barangay admin
        sendNotification(
            (int)$req['requester_user_id'],
            "✅ Your transfer request for <strong>{$req['resident_name']}</strong> to <strong>{$req['dest_name']}</strong> has been <strong>approved</strong> by the LGU." .
            ($adminNotes ? " Notes: {$adminNotes}" : ''),
            'success', 'transaction'
        );

        // Notify ALL admins of destination barangay
        notifyBarangayAdmins(
            (int)$req['destination_barangay_id'],
            "📋 <strong>Resident Transfer</strong>: <strong>{$req['resident_name']}</strong> from <strong>{$req['origin_name']}</strong> has been approved for transfer to your barangay. Reason: {$req['reason']}. Once the resident appears personally, verify the transfer in your <strong>Requests</strong> page.",
            'info', 'transaction'
        );

        logActivity($u['id'], 'APPROVE_TRANSFER', 'Resident Transfer',
            "Approved transfer #{$reqId}: {$req['resident_name']} → {$req['dest_name']}");
        flash('tr', "Transfer approved. <strong>{$req['dest_name']}</strong> has been notified.", 'success');

    } else {
        // Notify origin barangay
        sendNotification(
            (int)$req['requester_user_id'],
            "❌ Your transfer request for <strong>{$req['resident_name']}</strong> to <strong>{$req['dest_name']}</strong> has been <strong>rejected</strong> by the LGU." .
            ($adminNotes ? " Reason: {$adminNotes}" : ''),
            'danger', 'transaction'
        );

        // Clear pending transfer fields on resident record
        $db->prepare("UPDATE residents SET transfer_to_barangay=NULL, transfer_reason=NULL WHERE id=? AND barangay_id=?")
           ->execute([$req['resident_id'], $req['origin_barangay_id']]);

        logActivity($u['id'], 'REJECT_TRANSFER', 'Resident Transfer',
            "Rejected transfer #{$reqId}: {$req['resident_name']}");
        flash('tr', "Transfer request rejected.", 'warning');
    }

    redirect(BASE_URL . '/super_admin/transfer_requests.php');
}

// ── Filters ─────────────────────────────────────────────────────────────────
$statusFilter = sanitize($_GET['status']    ?? '');
$brgyFilter   = (int)($_GET['barangay_id']  ?? 0);
$search       = sanitize($_GET['search']    ?? '');

$where = "WHERE 1=1";
if ($statusFilter) $where .= " AND r.status = " . $db->quote($statusFilter);
if ($brgyFilter)   $where .= " AND (r.origin_barangay_id=$brgyFilter OR r.destination_barangay_id=$brgyFilter)";
if ($search)       $where .= " AND r.resident_name LIKE " . $db->quote("%$search%");

$totalCount = (int)$db->query("SELECT COUNT(*) FROM resident_transfer_requests r
    LEFT JOIN barangays b1 ON r.origin_barangay_id=b1.id
    LEFT JOIN barangays b2 ON r.destination_barangay_id=b2.id
    $where")->fetchColumn();

$perPage    = 10;
$page       = max(1, (int)($_GET['page'] ?? 1));
$totalPages = max(1, (int)ceil($totalCount / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$requests = $db->query("
    SELECT r.*,
           b1.barangay_name AS origin_name,
           b2.barangay_name AS dest_name,
           CONCAT(ru.first_name,' ',ru.last_name) AS requester_name,
           CONCAT(rv.first_name,' ',rv.last_name) AS reviewed_by_name,
           CONCAT(vf.first_name,' ',vf.last_name) AS verified_by_name
    FROM resident_transfer_requests r
    LEFT JOIN barangays b1 ON r.origin_barangay_id      = b1.id
    LEFT JOIN barangays b2 ON r.destination_barangay_id = b2.id
    LEFT JOIN users ru     ON r.requester_user_id       = ru.id
    LEFT JOIN users rv     ON r.reviewed_by             = rv.id
    LEFT JOIN users vf     ON r.verified_by             = vf.id
    $where
    ORDER BY FIELD(r.status,'pending','approved','verified','rejected'), r.created_at DESC
    LIMIT $perPage OFFSET $offset
")->fetchAll();

$pendingCount = (int)$db->query("SELECT COUNT(*) FROM resident_transfer_requests WHERE status='pending'")->fetchColumn();
$barangays    = $db->query("SELECT id, barangay_name FROM barangays WHERE status='active' ORDER BY barangay_name")->fetchAll();
$flash        = getFlash('tr');

$statusCfg = [
    'pending'  => ['label'=>'Pending LGU Approval',        'color'=>'warning', 'icon'=>'bi-hourglass-split'],
    'approved' => ['label'=>'Approved – Awaiting Appearance','color'=>'info',   'icon'=>'bi-check-circle'],
    'rejected' => ['label'=>'Rejected',                     'color'=>'danger',  'icon'=>'bi-x-circle-fill'],
    'verified' => ['label'=>'Transfer Verified',            'color'=>'success', 'icon'=>'bi-patch-check-fill'],
];

$pageIcon     = 'bi-arrow-left-right';
$pageTitle    = 'Resident Transfers';
$pageSubtitle = 'Review and approve resident transfer requests from barangays.';
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
<?php include __DIR__ . '/../includes/sidebar_super.php'; ?>
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<div class="main-content">

    <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show">
        <?= $flash['message'] ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Stats row -->
    <div class="row g-3 mb-3">
        <?php
        $stats = [
            ['s'=>'pending',  'label'=>'Pending Review',      'icon'=>'bi-hourglass-split', 'color'=>'warning'],
            ['s'=>'approved', 'label'=>'Approved / In Progress','icon'=>'bi-check-circle',  'color'=>'info'],
            ['s'=>'verified', 'label'=>'Transfer Verified',   'icon'=>'bi-patch-check-fill','color'=>'success'],
            ['s'=>'rejected', 'label'=>'Rejected',            'icon'=>'bi-x-circle',        'color'=>'danger'],
        ];
        foreach ($stats as $st):
            $cnt = (int)$db->query("SELECT COUNT(*) FROM resident_transfer_requests WHERE status='{$st['s']}'")->fetchColumn();
        ?>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-3 d-flex align-items-center gap-3">
                    <div style="width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;"
                         class="bg-<?= $st['color'] ?> bg-opacity-10">
                        <i class="bi <?= $st['icon'] ?> text-<?= $st['color'] ?>" style="font-size:1.3rem;"></i>
                    </div>
                    <div>
                        <div class="fw-bold fs-5 lh-1"><?= $cnt ?></div>
                        <div class="text-muted small"><?= $st['label'] ?></div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Filters -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="GET" class="d-flex gap-2 flex-wrap align-items-center" id="filterForm">
                <div class="input-group" style="width:210px;">
                    <span class="input-group-text"><i class="bi bi-search" style="font-size:.85rem;"></i></span>
                    <input type="text" name="search" class="form-control form-control-sm"
                           data-search-form="filterForm"
                           placeholder="Search resident name..."
                           value="<?= htmlspecialchars($search) ?>">
                </div>
                <select name="status" class="form-select form-select-sm" style="width:200px;">
                    <option value="">All Status</option>
                    <?php foreach ($statusCfg as $k => $v): ?>
                    <option value="<?= $k ?>" <?= $statusFilter===$k?'selected':'' ?>><?= $v['label'] ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="barangay_id" class="form-select form-select-sm" style="width:175px;">
                    <option value="">All Barangays</option>
                    <?php foreach ($barangays as $b): ?>
                    <option value="<?= $b['id'] ?>" <?= $brgyFilter==$b['id']?'selected':'' ?>><?= htmlspecialchars($b['barangay_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-sm btn-primary px-3"><i class="bi bi-funnel me-1"></i>Filter</button>
                <?php if ($search||$statusFilter||$brgyFilter): ?>
                <a href="?" class="btn btn-sm btn-outline-secondary px-3"><i class="bi bi-x me-1"></i>Clear</a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Table -->
    <div class="card border-0 shadow-sm" style="border-radius:12px;overflow:hidden;">
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:.85rem;">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4 py-3" style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;">#</th>
                        <th class="py-3" style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;">Resident</th>
                        <th class="py-3" style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;">From</th>
                        <th class="py-3" style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;">To</th>
                        <th class="py-3" style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;">Reason</th>
                        <th class="py-3" style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;">Requested By</th>
                        <th class="py-3" style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;">Status</th>
                        <th class="py-3" style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;">Date Filed</th>
                        <th class="pe-4 py-3" style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;width:100px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($requests)): ?>
                <tr>
                    <td colspan="9" class="text-center text-muted py-5">
                        <i class="bi bi-arrow-left-right" style="font-size:2.5rem;opacity:.3;display:block;margin-bottom:10px;"></i>
                        No transfer requests found.
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($requests as $i => $r):
                    $sc = $statusCfg[$r['status']] ?? ['label'=>$r['status'],'color'=>'secondary','icon'=>'bi-circle'];
                ?>
                <tr>
                    <td class="ps-4 py-3 text-muted small">
                        <?= $offset+$i+1 ?>
                        <?php if ($r['status']==='pending'): ?>
                        <span class="badge bg-danger ms-1" style="font-size:.6rem;">NEW</span>
                        <?php endif; ?>
                    </td>
                    <td class="py-3 fw-semibold"><?= htmlspecialchars($r['resident_name']) ?></td>
                    <td class="py-3 small"><?= htmlspecialchars($r['origin_name'] ?? '—') ?></td>
                    <td class="py-3 small"><?= htmlspecialchars($r['dest_name'] ?? '—') ?></td>
                    <td class="py-3 small"><?= htmlspecialchars($r['reason']) ?></td>
                    <td class="py-3 small"><?= htmlspecialchars($r['requester_name'] ?? '—') ?></td>
                    <td class="py-3">
                        <span class="badge bg-<?= $sc['color'] ?>">
                            <i class="bi <?= $sc['icon'] ?> me-1"></i><?= $sc['label'] ?>
                        </span>
                    </td>
                    <td class="py-3 small text-nowrap">
                        <?= date('M d, Y', strtotime($r['created_at'])) ?><br>
                        <span class="text-muted"><?= date('h:i A', strtotime($r['created_at'])) ?></span>
                    </td>
                    <td class="pe-4 py-3">
                        <button class="btn btn-xs btn-outline-primary mb-1 w-100"
                                onclick='openDetail(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)'>
                            <i class="bi bi-eye me-1"></i>View
                        </button>
                        <?php if ($r['status'] === 'pending'): ?>
                        <button class="btn btn-xs btn-success mb-1 w-100"
                                onclick='openAction(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>, "approve")'>
                            <i class="bi bi-check-lg me-1"></i>Approve
                        </button>
                        <button class="btn btn-xs btn-danger w-100"
                                onclick='openAction(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>, "reject")'>
                            <i class="bi bi-x-lg me-1"></i>Reject
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
        $paginationBase  = '?' . http_build_query(array_filter(['search'=>$search,'status'=>$statusFilter,'barangay_id'=>$brgyFilter?:null]));
        $paginationBase  = ($paginationBase === '?') ? '?' : $paginationBase . '&';
        $paginationLabel = 'request';
        include __DIR__ . '/../includes/pagination.php';
    ?>

</div>

<!-- ── DETAIL MODAL ──────────────────────────────────────── -->
<div class="modal fade" id="detailModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;overflow:hidden;">
            <div class="d-flex">
                <div class="d-flex flex-column align-items-center justify-content-center px-4 py-5 flex-shrink-0"
                     style="background:linear-gradient(160deg,#1a4f7a,#3a7fa8);width:180px;min-width:180px;">
                    <div style="width:60px;height:60px;background:rgba(255,255,255,.15);border-radius:50%;
                                display:flex;align-items:center;justify-content:center;border:3px solid rgba(255,255,255,.3);">
                        <i class="bi bi-arrow-left-right text-white" style="font-size:1.6rem;"></i>
                    </div>
                    <h6 class="fw-bold mt-3 mb-0 text-white text-center small">Transfer Details</h6>
                    <div id="dm_status_badge" class="mt-2"></div>
                </div>
                <div class="flex-fill p-4">
                    <table class="table table-bordered table-sm mb-0" style="font-size:.83rem;">
                        <tbody>
                            <tr><td class="bg-light fw-semibold" style="width:38%;">Resident</td><td id="dm_name" class="fw-bold"></td></tr>
                            <tr><td class="bg-light fw-semibold">From Barangay</td><td id="dm_from"></td></tr>
                            <tr><td class="bg-light fw-semibold">To Barangay</td><td id="dm_to"></td></tr>
                            <tr><td class="bg-light fw-semibold">Reason</td><td id="dm_reason"></td></tr>
                            <tr><td class="bg-light fw-semibold">Requested By</td><td id="dm_reqby"></td></tr>
                            <tr><td class="bg-light fw-semibold">Date Filed</td><td id="dm_date"></td></tr>
                            <tr id="dm_notes_row" style="display:none;"><td class="bg-light fw-semibold align-top">Requester Notes</td><td id="dm_notes" class="small text-muted" style="white-space:pre-wrap;"></td></tr>
                            <tr id="dm_admin_notes_row" style="display:none;"><td class="bg-light fw-semibold align-top">LGU Notes</td><td id="dm_admin_notes" class="small" style="white-space:pre-wrap;"></td></tr>
                            <tr id="dm_verified_row" style="display:none;"><td class="bg-light fw-semibold">Verified By / At</td><td id="dm_verified" class="small"></td></tr>
                        </tbody>
                    </table>
                    <div class="mt-3 d-flex gap-2">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="button" class="btn btn-success" id="dm_approve_btn" style="display:none;"
                                onclick="openActionFromDetail('approve')">
                            <i class="bi bi-check-lg me-1"></i>Approve
                        </button>
                        <button type="button" class="btn btn-danger"  id="dm_reject_btn" style="display:none;"
                                onclick="openActionFromDetail('reject')">
                            <i class="bi bi-x-lg me-1"></i>Reject
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── ACTION MODAL ──────────────────────────────────────── -->
<div class="modal fade" id="actionModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST">
            <input type="hidden" name="action"     id="act_action" value="">
            <input type="hidden" name="request_id" id="act_req_id" value="">
            <?= csrfField() ?>
            <div class="modal-content" style="border-radius:14px;overflow:hidden;">
                <div class="d-flex">
                    <div class="d-flex flex-column align-items-center justify-content-center px-4 py-5 flex-shrink-0"
                         id="act_panel"
                         style="width:160px;min-width:160px;">
                        <i id="act_icon" class="text-white" style="font-size:2.2rem;"></i>
                        <h6 id="act_title" class="fw-bold mt-3 mb-0 text-white text-center small"></h6>
                    </div>
                    <div class="flex-fill p-4">
                        <p class="small mb-2">
                            Resident: <strong id="act_resident"></strong><br>
                            Transfer: <span id="act_route" class="text-muted"></span>
                        </p>
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Notes <span id="act_req_label" class="text-muted">(optional)</span></label>
                            <textarea name="admin_notes" id="act_notes" class="form-control form-control-sm" rows="3"
                                      placeholder="Add notes..."></textarea>
                        </div>
                        <div id="act_approve_info" class="alert alert-success py-2 small" style="display:none;">
                            <i class="bi bi-info-circle me-1"></i>
                            The <strong>destination barangay</strong> will be notified to expect this resident.
                            The resident's status will be updated to <strong>Transferred</strong>.
                        </div>
                        <div id="act_reject_info" class="alert alert-danger py-2 small" style="display:none;">
                            <i class="bi bi-info-circle me-1"></i>
                            The origin barangay will be notified of the rejection.
                        </div>
                        <div class="d-flex gap-2 mt-3">
                            <button type="button" class="btn btn-outline-secondary flex-fill" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" id="act_submit_btn" class="btn flex-fill fw-semibold">Confirm</button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleSidebar(){document.getElementById('sidebar').classList.toggle('show');}

const statusCfg = <?= json_encode(array_map(fn($v)=>['label'=>$v['label'],'color'=>$v['color'],'icon'=>$v['icon']], $statusCfg)) ?>;

let _current = null;

function openDetail(r) {
    _current = r;
    document.getElementById('dm_name').textContent   = r.resident_name;
    document.getElementById('dm_from').textContent   = r.origin_name  || '—';
    document.getElementById('dm_to').textContent     = r.dest_name    || '—';
    document.getElementById('dm_reason').textContent = r.reason;
    document.getElementById('dm_reqby').textContent  = r.requester_name || '—';

    const d = new Date(r.created_at.replace(' ','T'));
    document.getElementById('dm_date').textContent = isNaN(d) ? r.created_at
        : d.toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'})
          + ' ' + d.toLocaleTimeString('en-PH',{hour:'2-digit',minute:'2-digit'});

    const sc = statusCfg[r.status] || {label:r.status,color:'secondary',icon:'bi-circle'};
    document.getElementById('dm_status_badge').innerHTML =
        `<span class="badge bg-${sc.color} small">${sc.label}</span>`;

    const notesRow = document.getElementById('dm_notes_row');
    if (r.notes) { document.getElementById('dm_notes').textContent=r.notes; notesRow.style.display=''; }
    else notesRow.style.display='none';

    const anRow = document.getElementById('dm_admin_notes_row');
    if (r.admin_notes) { document.getElementById('dm_admin_notes').textContent=r.admin_notes; anRow.style.display=''; }
    else anRow.style.display='none';

    const vRow = document.getElementById('dm_verified_row');
    if (r.verified_by_name) {
        document.getElementById('dm_verified').textContent =
            r.verified_by_name + (r.verified_at ? ' — ' + r.verified_at.substring(0,10) : '');
        vRow.style.display='';
    } else vRow.style.display='none';

    const isPending = r.status === 'pending';
    document.getElementById('dm_approve_btn').style.display = isPending ? '' : 'none';
    document.getElementById('dm_reject_btn').style.display  = isPending ? '' : 'none';

    new bootstrap.Modal(document.getElementById('detailModal')).show();
}

function openActionFromDetail(action) {
    bootstrap.Modal.getInstance(document.getElementById('detailModal'))?.hide();
    setTimeout(() => openAction(_current, action), 300);
}

function openAction(r, action) {
    _current = r;
    document.getElementById('act_action').value     = action;
    document.getElementById('act_req_id').value     = r.id;
    document.getElementById('act_resident').textContent = r.resident_name;
    document.getElementById('act_route').textContent    = (r.origin_name||'?') + ' → ' + (r.dest_name||'?');
    document.getElementById('act_notes').value = '';

    const isApprove = action === 'approve';
    const panel     = document.getElementById('act_panel');
    panel.style.background = isApprove
        ? 'linear-gradient(160deg,#1a7a2e,#3aa852)'
        : 'linear-gradient(160deg,#c62828,#e53935)';
    document.getElementById('act_icon').className = isApprove
        ? 'bi bi-check-circle-fill text-white'
        : 'bi bi-x-circle-fill text-white';
    document.getElementById('act_title').textContent = isApprove ? 'Approve Transfer' : 'Reject Transfer';
    document.getElementById('act_approve_info').style.display = isApprove ? '' : 'none';
    document.getElementById('act_reject_info').style.display  = isApprove ? 'none' : '';
    document.getElementById('act_req_label').textContent = '(optional)';

    const btn = document.getElementById('act_submit_btn');
    btn.className = isApprove ? 'btn btn-success flex-fill fw-semibold' : 'btn btn-danger flex-fill fw-semibold';
    btn.textContent = isApprove ? 'Confirm Approval' : 'Confirm Rejection';

    new bootstrap.Modal(document.getElementById('actionModal')).show();
}
</script>
<style>.btn-xs{padding:2px 8px;font-size:.75rem;}</style>
</body>
</html>
