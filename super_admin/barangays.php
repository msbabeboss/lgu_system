<?php
require_once __DIR__ . '/../config/config.php';
requireRole('super_admin');

$db = Database::getInstance()->getConnection();
$u  = currentUser();
$isAjaxRequest = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

if (!$isAjaxRequest) {
    // Apply legacy schema changes only when they are missing.
    $barangayColumns = $db->query("SHOW COLUMNS FROM barangays")->fetchAll(PDO::FETCH_COLUMN);
    foreach ([
        'region' => 'VARCHAR(100) NULL',
        'province' => 'VARCHAR(100) NULL',
        'zip_code' => 'VARCHAR(10) NULL',
    ] as $column => $definition) {
        if (!in_array($column, $barangayColumns, true)) {
            $db->exec("ALTER TABLE barangays ADD COLUMN `$column` $definition");
        }
    }

    $barangayIndexes = $db->query("SHOW INDEX FROM barangays")->fetchAll(PDO::FETCH_ASSOC);
    $hasNameMunicipalityIndex = false;
    foreach ($barangayIndexes as $index) {
        if ($index['Key_name'] === 'uq_barangays_name_municipality') {
            $hasNameMunicipalityIndex = true;
            break;
        }
    }
    if (!$hasNameMunicipalityIndex) {
        $db->exec("ALTER TABLE barangays ADD CONSTRAINT uq_barangays_name_municipality UNIQUE (barangay_name, municipality)");
    }
}

// ── POST Actions ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrAbort();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name     = sanitize($_POST['barangay_name']);
        $muni     = sanitize($_POST['municipality']);
        $region   = sanitize($_POST['region']   ?? '');
        $province = sanitize($_POST['province'] ?? '');
        $zip      = sanitize($_POST['zip_code'] ?? '');

        // ── Duplicate check: same name + municipality (case-insensitive) ──
        $dupChk = $db->prepare(
            "SELECT id FROM barangays
             WHERE LOWER(barangay_name) = LOWER(?)
               AND LOWER(municipality)  = LOWER(?)
             LIMIT 1"
        );
        $dupChk->execute([$name, $muni]);
        if ($dupChk->fetchColumn() !== false) {
            flash('brgy', "A barangay named <strong>" . htmlspecialchars($name) . "</strong> already exists in <strong>" . htmlspecialchars($muni) . "</strong>. No duplicate was created.", 'danger');
            redirect(BASE_URL . '/super_admin/barangays.php');
        }

        $db->prepare("INSERT INTO barangays (barangay_name, municipality, region, province, zip_code) VALUES (?,?,?,?,?)")
           ->execute([$name, $muni, $region ?: null, $province ?: null, $zip ?: null]);
        logActivity($u['id'], 'ADD_BARANGAY', 'Barangays', "Added: $name");
        flash('brgy', 'Barangay added successfully.', 'success');
        redirect(BASE_URL . '/super_admin/barangays.php');
    }

    if ($action === 'edit') {
        $id       = (int)$_POST['brgy_id'];
        $name     = sanitize($_POST['barangay_name']);
        $muni     = sanitize($_POST['municipality']);
        $stat     = $_POST['status'];
        $region   = sanitize($_POST['region']   ?? '');
        $province = sanitize($_POST['province'] ?? '');
        $zip      = sanitize($_POST['zip_code'] ?? '');

        // ── Duplicate check: another row with the same name + municipality ──
        $dupChk = $db->prepare(
            "SELECT id FROM barangays
             WHERE LOWER(barangay_name) = LOWER(?)
               AND LOWER(municipality)  = LOWER(?)
               AND id != ?
             LIMIT 1"
        );
        $dupChk->execute([$name, $muni, $id]);
        if ($dupChk->fetchColumn() !== false) {
            flash('brgy', "Another barangay named <strong>" . htmlspecialchars($name) . "</strong> already exists in <strong>" . htmlspecialchars($muni) . "</strong>. Update was not saved.", 'danger');
            redirect(BASE_URL . '/super_admin/barangays.php');
        }

        $db->prepare("UPDATE barangays SET barangay_name=?, municipality=?, status=?, region=?, province=?, zip_code=? WHERE id=?")
           ->execute([$name, $muni, $stat, $region ?: null, $province ?: null, $zip ?: null, $id]);
        logActivity($u['id'], 'EDIT_BARANGAY', 'Barangays', "Updated barangay details for $name");
        flash('brgy', 'Barangay updated.', 'success');
        redirect(BASE_URL . '/super_admin/barangays.php');
    }

    if ($action === 'archive') {
        $id  = (int)$_POST['brgy_id'];
        $row = $db->prepare("SELECT * FROM barangays WHERE id=?");
        $row->execute([$id]);
        $data = $row->fetch();
        if ($data) {
            $db->prepare("INSERT INTO archives (table_name,record_id,data_json,archived_by) VALUES ('barangays',?,?,?)")
               ->execute([$id, json_encode($data), $u['id']]);
            $db->prepare("UPDATE barangays SET status='inactive' WHERE id=?")->execute([$id]);
            logActivity($u['id'], 'ARCHIVE_BARANGAY', 'Barangays', "Archived: {$data['barangay_name']}");
            flash('brgy', "Barangay \"{$data['barangay_name']}\" archived.", 'warning');
        }
        redirect(BASE_URL . '/super_admin/barangays.php');
    }
}

// ── Filters ────────────────────────────────────────────────
$search       = sanitize($_GET['search'] ?? '');
$statusFilter = sanitize($_GET['status'] ?? '');
$perPage      = 10;
$page         = max(1, (int)($_GET['page'] ?? 1));

$where = "WHERE 1=1";
if ($search)       $where .= " AND (b.barangay_name LIKE '%$search%' OR b.municipality LIKE '%$search%')";
if ($statusFilter) $where .= " AND b.status = '$statusFilter'";

if ($isAjaxRequest) {
    $totalCount = (int)$db->query("SELECT COUNT(*) FROM barangays b $where")->fetchColumn();
} else {
    $summary = $db->query("
        SELECT
            COUNT(*) AS total_barangays,
            COALESCE(SUM(CASE WHEN b.status = 'active' THEN 1 ELSE 0 END), 0) AS total_active,
            COALESCE(SUM(CASE WHEN b.status = 'inactive' THEN 1 ELSE 0 END), 0) AS total_inactive,
            COALESCE(SUM(
                (SELECT COUNT(*) FROM residents r
                 WHERE r.barangay_id = b.id AND r.resident_status = 'Active')
            ), 0) AS total_residents
        FROM barangays b
        $where
    ")->fetch();
    $totalCount = (int)$summary['total_barangays'];
}
$totalPages = max(1, (int)ceil($totalCount / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$barangays = $db->query("
    SELECT b.*,
        (SELECT COUNT(*) FROM users us       WHERE us.barangay_id=b.id AND us.status='active')        AS active_users,
        captain.full_name AS captain_name,
        captain.term_end AS captain_term_end
    FROM barangays b
    LEFT JOIN officials captain
        ON captain.id = (
            SELECT o.id
            FROM officials o
            WHERE o.barangay_id = b.id
              AND o.position = 'Barangay Captain'
              AND o.status = 'active'
            ORDER BY o.term_start DESC
            LIMIT 1
        )
    $where
    ORDER BY b.barangay_name
    LIMIT $perPage OFFSET $offset
")->fetchAll();

$paginationBase = '?' . http_build_query(array_filter(['search' => $search, 'status' => $statusFilter]));
$paginationBase = ($paginationBase === '?') ? '?' : $paginationBase . '&';
$paginationLabel = 'barangay';

if ($isAjaxRequest) {
    ob_start();
    include __DIR__ . '/../includes/barangay_table_rows.php';
    $rowsHtml = ob_get_clean();

    ob_start();
    include __DIR__ . '/../includes/pagination.php';
    $paginationHtml = ob_get_clean();

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'rowsHtml' => $rowsHtml,
        'paginationHtml' => $paginationHtml,
    ], JSON_THROW_ON_ERROR);
    exit;
}

$flash         = getFlash('brgy');
$pageTitle     = 'Barangay List';
$totalActive   = (int)$summary['total_active'];
$totalInactive = (int)$summary['total_inactive'];
$totalResidents = (int)$summary['total_residents'];
?>

<?php
$pageIcon     = 'bi-geo-alt-fill';
$pageTitle    = 'Barangay List';
$pageSubtitle = 'Manage all barangays in the system.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favcon.png">
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favcon.png">
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=3.3">
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

    <!-- Summary Cards -->
    <div class="d-flex gap-3 mb-3 flex-wrap justify-content-center">
        <div style="background:#fff;border-radius:14px;padding:12px 28px;box-shadow:0 4px 6px -1px rgba(0,0,0,0.07),0 2px 4px -1px rgba(0,0,0,0.05),0 0 0 1px rgba(0,0,0,0.04);min-width:170px;">
            <div style="font-size:1.25rem;font-weight:800;color:#1a4f7a;line-height:1;"><?= $totalCount ?></div>
            <div style="font-size:0.7rem;color:#3a7fa8;margin-top:2px;">Total Barangays</div>
        </div>
        <div style="background:#fff;border-radius:14px;padding:12px 28px;box-shadow:0 4px 6px -1px rgba(0,0,0,0.07),0 2px 4px -1px rgba(0,0,0,0.05),0 0 0 1px rgba(0,0,0,0.04);min-width:140px;">
            <div style="font-size:1.25rem;font-weight:800;color:#059669;line-height:1;"><?= $totalActive ?></div>
            <div style="font-size:0.7rem;color:#3a7fa8;margin-top:2px;">Active</div>
        </div>
        <div style="background:#fff;border-radius:14px;padding:12px 28px;box-shadow:0 4px 6px -1px rgba(0,0,0,0.07),0 2px 4px -1px rgba(0,0,0,0.05),0 0 0 1px rgba(0,0,0,0.04);min-width:140px;">
            <div style="font-size:1.25rem;font-weight:800;color:#64748b;line-height:1;"><?= $totalInactive ?></div>
            <div style="font-size:0.7rem;color:#3a7fa8;margin-top:2px;">Inactive</div>
        </div>
        <div style="background:#fff;border-radius:14px;padding:12px 28px;box-shadow:0 4px 6px -1px rgba(0,0,0,0.07),0 2px 4px -1px rgba(0,0,0,0.05),0 0 0 1px rgba(0,0,0,0.04);min-width:170px;">
            <div style="font-size:1.25rem;font-weight:800;color:#0369a1;line-height:1;"><?= number_format($totalResidents) ?></div>
            <div style="font-size:0.7rem;color:#3a7fa8;margin-top:2px;">Total Residents</div>
        </div>
    </div>

    <!-- Filters -->
    <div style="background:#fff;border-radius:14px;box-shadow:0 2px 12px rgba(0,0,0,0.06);border:1px solid #e0f0e4;padding:16px 20px;margin-bottom:16px;">
        <form method="GET" class="d-flex gap-2 flex-wrap align-items-center" id="searchForm">
                <input type="text" name="search" data-search-form="searchForm"
                       class="form-control form-control-sm" style="width:240px;border-color:#e0f0e4;background:#f8fafc;"
                       placeholder="Search barangay or municipality..."
                       value="<?= htmlspecialchars($search) ?>">
                <select name="status" class="form-select form-select-sm" style="width:140px;border-color:#e0f0e4;background:#f8fafc;"
                        onchange="this.form.submit()">
                    <option value="">All Status</option>
                    <option value="active"   <?= $statusFilter==='active'  ?'selected':'' ?>>Active</option>
                    <option value="inactive" <?= $statusFilter==='inactive'?'selected':'' ?>>Inactive</option>
                </select>
                <button class="btn btn-sm btn-primary px-3" style="border-radius:8px;"><i class="bi bi-funnel me-1"></i>Filter</button>
                <a href="?" class="btn btn-sm btn-outline-secondary px-3" style="border-radius:8px;"><i class="bi bi-x me-1"></i>Clear</a>
                <span class="ms-auto text-muted small"><?= $totalCount ?> result<?= $totalCount !== 1 ? 's' : '' ?></span>
                <a href="<?= BASE_URL ?>/super_admin/export_barangays.php" class="btn btn-success btn-sm" style="border-radius:8px;">
                    <i class="bi bi-file-earmark-excel me-1"></i>Export All
                </a>
                <button type="button" class="btn btn-success btn-sm fw-semibold" data-bs-toggle="modal" data-bs-target="#addBrgyModal">
                    <i class="bi bi-plus-lg me-1"></i>Add Barangay
                </button>
        </form>
    </div>

    <div id="barangayPaginationStatus" role="status" aria-live="polite"></div>

    <!-- Table -->
    <div style="background:#fff;border-radius:18px;box-shadow:0 2px 16px rgba(0,0,0,0.07);border:1px solid #e0f0e4;overflow:hidden;">
        <div class="table-responsive">
            <table class="table mb-0" id="brgyTable">
                <thead>
                    <tr style="background:#f4f7fa;border-bottom:2px solid #d0ecd6;">
                        <th class="ps-4 py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;width:36px;"><input type="checkbox" id="selectAll" class="form-check-input"></th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">Brgy ID</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">Barangay Name</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">City / Municipality</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">Barangay Captain</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">Status</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">Date Registered</th>
                        <th class="pe-4 py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;width:120px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php include __DIR__ . '/../includes/barangay_table_rows.php'; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/pagination.php'; ?>

<!-- ── View Modal ─────────────────────────────────────────── -->
<div class="modal fade" id="viewBrgyModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px; overflow:hidden;">
            <div class="d-flex">
                <!-- LEFT PANEL -->
                <div class="d-flex flex-column align-items-center justify-content-center px-4 py-5"
                     style="background:linear-gradient(160deg,#1a4f7a 0%,#3a7fa8 100%); min-width:190px; width:190px; align-self:stretch; flex-shrink:0;">
                    <div style="width:72px;height:72px;background:rgba(255,255,255,0.15);border-radius:50%;display:flex;align-items:center;justify-content:center;border:3px solid rgba(255,255,255,0.3);">
                        <i class="bi bi-geo-alt-fill text-white" style="font-size:2rem;"></i>
                    </div>
                    <h5 class="fw-bold mt-3 mb-1 text-white text-center" style="font-size:1rem;">Barangay Details</h5>
                    <p class="text-white-50 small text-center mb-0">View Information</p>
                </div>
                <!-- RIGHT PANEL -->
                <div class="flex-fill d-flex flex-column justify-content-between p-4">
                    <div>
                        <!-- Info table -->
                        <table class="table table-bordered table-sm mb-3" style="font-size:0.85rem;">
                            <tbody>
                                <tr>
                                    <td class="text-muted fw-semibold bg-light" style="width:38%;">Barangay ID</td>
                                    <td class="fw-bold" id="vb_id"></td>
                                </tr>
                                <tr>
                                    <td class="text-muted fw-semibold bg-light">Barangay Name</td>
                                    <td class="fw-bold" id="vb_name"></td>
                                </tr>
                                <tr>
                                    <td class="text-muted fw-semibold bg-light">City / Municipality</td>
                                    <td id="vb_muni"></td>
                                </tr>
                                <tr>
                                    <td class="text-muted fw-semibold bg-light">Barangay Captain</td>
                                    <td id="vb_captain"></td>
                                </tr>
                                <tr>
                                    <td class="text-muted fw-semibold bg-light">Status</td>
                                    <td id="vb_status"></td>
                                </tr>
                                <tr>
                                    <td class="text-muted fw-semibold bg-light">Date Registered</td>
                                    <td id="vb_created"></td>
                                </tr>
                            </tbody>
                        </table>

                        <div class="d-flex gap-2 mt-2">
                            <button type="button" class="btn btn-outline-secondary flex-fill" data-bs-dismiss="modal">Close</button>
                        </div>
                    </div><!-- /inner content -->
                </div><!-- /right panel -->
            </div><!-- /d-flex row -->
        </div><!-- /modal-content -->
    </div><!-- /modal-dialog -->
</div><!-- /viewBrgyModal -->

<!-- ── Add Modal ──────────────────────────────────────────── -->
<div class="modal fade" id="addBrgyModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <?= csrfField() ?>
            <div class="modal-content" style="border-radius:16px; overflow:hidden;">
                <div class="d-flex">
                    <!-- LEFT PANEL -->
                    <div class="d-flex flex-column align-items-center justify-content-center px-4 py-5"
                         style="background:linear-gradient(160deg,#1a4f7a 0%,#3a7fa8 100%); min-width:200px; width:200px; align-self:stretch;">
                        <div style="width:72px;height:72px;background:rgba(255,255,255,0.15);border-radius:50%;display:flex;align-items:center;justify-content:center;border:3px solid rgba(255,255,255,0.3);">
                            <i class="bi bi-plus-circle-fill text-white" style="font-size:2rem;"></i>
                        </div>
                        <h5 class="fw-bold mt-3 mb-1 text-white text-center">Add Barangay</h5>
                        <p class="text-white-50 small text-center mb-0">Register New Barangay</p>
                    </div>
                    <!-- RIGHT PANEL -->
                    <div class="flex-fill d-flex flex-column justify-content-between p-4">
                        <div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Municipality / City <span class="text-danger">*</span></label>
                                    <input type="text" name="municipality" class="form-control" required placeholder="e.g. Talavera">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Barangay Name <span class="text-danger">*</span></label>
                                    <input type="text" name="barangay_name" class="form-control" required placeholder="e.g. Poblacion Sur">
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label fw-semibold">Region</label>
                                    <select name="region" class="form-select">
                                        <option value="">— Select Region —</option>
                                        <option value="Region III - Central Luzon" selected>Region III — Central Luzon</option>
                                        <option value="Region I - Ilocos Region">Region I — Ilocos Region</option>
                                        <option value="Region II - Cagayan Valley">Region II — Cagayan Valley</option>
                                        <option value="Region IV-A - CALABARZON">Region IV-A — CALABARZON</option>
                                        <option value="NCR - National Capital Region">NCR — National Capital Region</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Province</label>
                                    <select name="province" class="form-select">
                                        <option value="">— Select Province —</option>
                                        <option value="Nueva Ecija">Nueva Ecija</option>
                                        <option value="Aurora">Aurora</option>
                                        <option value="Bataan">Bataan</option>
                                        <option value="Bulacan">Bulacan</option>
                                        <option value="Pampanga">Pampanga</option>
                                        <option value="Tarlac">Tarlac</option>
                                        <option value="Zambales">Zambales</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold">Zip Code</label>
                                    <input type="text" name="zip_code" class="form-control" placeholder="e.g. 3114" inputmode="numeric" maxlength="10" value="3114">
                                </div>
                            </div>
                        </div>
                        <div class="d-flex gap-2 mt-3">
                            <button type="button" class="btn btn-outline-secondary flex-fill" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary flex-fill fw-semibold">
                                <i class="bi bi-check-lg me-1"></i>Add Barangay
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ── Edit Modal ─────────────────────────────────────────── -->
<div class="modal fade" id="editBrgyModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered" style="max-width:680px;">
        <form method="POST">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="brgy_id" id="editBrgyId">
            <?= csrfField() ?>
            <div class="modal-content" style="border-radius:16px; overflow:hidden;">
                <div class="d-flex">
                    <!-- LEFT PANEL -->
                    <div class="d-flex flex-column align-items-center justify-content-center px-4 py-5"
                         style="background:linear-gradient(160deg,#1a4f7a 0%,#3a7fa8 100%); min-width:200px; width:200px; align-self:stretch; flex-shrink:0;">
                        <div style="width:72px;height:72px;background:rgba(255,255,255,0.15);border-radius:50%;display:flex;align-items:center;justify-content:center;border:3px solid rgba(255,255,255,0.3);">
                            <i class="bi bi-pencil-square text-white" style="font-size:2rem;"></i>
                        </div>
                        <h5 class="fw-bold mt-3 mb-1 text-white text-center">Edit Barangay</h5>
                        <p class="text-white-50 small text-center mb-0">Update Information</p>
                    </div>
                    <!-- RIGHT PANEL -->
                    <div class="flex-fill d-flex flex-column justify-content-between p-4">
                        <div>
                            <p class="mb-1 text-muted small">Editing: <strong id="editBrgyLabel"></strong></p>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Municipality / City <span class="text-danger">*</span></label>
                                    <input type="text" name="municipality" id="editMuni" class="form-control" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Barangay Name <span class="text-danger">*</span></label>
                                    <input type="text" name="barangay_name" id="editBrgyName" class="form-control" required>
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label">Region</label>
                                    <select name="region" id="editBrgyRegion" class="form-select">
                                        <option value="">— Select Region —</option>
                                        <option value="Region III - Central Luzon">Region III — Central Luzon</option>
                                        <option value="Region I - Ilocos Region">Region I — Ilocos Region</option>
                                        <option value="Region II - Cagayan Valley">Region II — Cagayan Valley</option>
                                        <option value="Region IV-A - CALABARZON">Region IV-A — CALABARZON</option>
                                        <option value="NCR - National Capital Region">NCR — National Capital Region</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Province</label>
                                    <select name="province" id="editBrgyProvince" class="form-select">
                                        <option value="">— Select Province —</option>
                                        <option value="Nueva Ecija">Nueva Ecija</option>
                                        <option value="Aurora">Aurora</option>
                                        <option value="Bataan">Bataan</option>
                                        <option value="Bulacan">Bulacan</option>
                                        <option value="Pampanga">Pampanga</option>
                                        <option value="Tarlac">Tarlac</option>
                                        <option value="Zambales">Zambales</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Zip Code</label>
                                    <input type="text" name="zip_code" id="editBrgyZip" class="form-control" placeholder="e.g. 3114" inputmode="numeric" maxlength="10">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Status</label>
                                    <select name="status" id="editBrgyStatus" class="form-select">
                                        <option value="active">Active</option>
                                        <option value="inactive">Inactive</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex gap-2 mt-3">
                            <button type="button" class="btn btn-outline-secondary flex-fill" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary flex-fill fw-semibold">
                                <i class="bi bi-check-lg me-1"></i>Save Changes
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ── Archive Confirm Modal ──────────────────────────────── -->
<div class="modal fade" id="archiveBrgyModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST">
            <input type="hidden" name="action" value="archive">
            <input type="hidden" name="brgy_id" id="archBrgyId">
            <?= csrfField() ?>
            <div class="modal-content" style="border-radius:16px; overflow:hidden;">
                <div class="d-flex">
                    <!-- LEFT PANEL -->
                    <div class="d-flex flex-column align-items-center justify-content-center px-4 py-5"
                         style="background:linear-gradient(160deg,#c62828 0%,#e53935 100%); min-width:200px; width:200px; align-self:stretch;">
                        <div style="width:72px;height:72px;background:rgba(255,255,255,0.15);border-radius:50%;display:flex;align-items:center;justify-content:center;border:3px solid rgba(255,255,255,0.3);">
                            <i class="bi bi-archive-fill text-white" style="font-size:2rem;"></i>
                        </div>
                        <h5 class="fw-bold mt-3 mb-1 text-white text-center">Archive Barangay</h5>
                        <p class="text-white-50 small text-center mb-0">This action is reversible</p>
                    </div>
                    <!-- RIGHT PANEL -->
                    <div class="flex-fill d-flex flex-column justify-content-between p-4">
                        <div>
                            <p class="mb-1">You are about to archive:</p>
                            <p class="fw-bold fs-6" id="archBrgyName"></p>
                            <div class="alert alert-warning small py-2 mb-0">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                This will set the barangay to inactive. It can be restored from the Archive page.
                            </div>
                        </div>
                        <div class="d-flex gap-2 mt-3">
                            <button type="button" class="btn btn-outline-secondary flex-fill" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-danger flex-fill fw-semibold">
                                <i class="bi bi-check-lg me-1"></i>Confirm Archive
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('show'); }

// Select all checkbox
document.getElementById('selectAll').addEventListener('change', function () {
    document.querySelectorAll('.row-check').forEach(c => c.checked = this.checked);
});

// ── View Modal ────────────────────────────────────────────
let _currentBrgy = null;
function viewBrgy(b) {
    _currentBrgy = b;
    document.getElementById('vb_id').textContent   = '#' + String(b.id).padStart(4, '0');
    document.getElementById('vb_name').textContent = b.barangay_name;
    document.getElementById('vb_muni').textContent = b.municipality;

    // Captain
    const captainEl = document.getElementById('vb_captain');
    if (b.captain_name) {
        let html = '<span class="fw-semibold">' + b.captain_name + '</span>';
        if (b.captain_term_end) {
            html += '<div class="small text-muted"><i class="bi bi-calendar me-1"></i>Until ' + b.captain_term_end + '</div>';
        }
        captainEl.innerHTML = html;
    } else {
        captainEl.innerHTML = '<span class="text-muted fst-italic small">Not assigned</span>';
    }

    // Status
    const sc = b.status === 'active' ? 'success' : 'secondary';
    document.getElementById('vb_status').innerHTML = '<span class="badge bg-' + sc + '">' + b.status.charAt(0).toUpperCase() + b.status.slice(1) + '</span>';

    // Date
    const d = new Date(b.created_at);
    document.getElementById('vb_created').textContent = d.toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'});

    bootstrap.Modal.getOrCreateInstance(document.getElementById('viewBrgyModal')).show();
}

// ── Edit Modal ────────────────────────────────────────────
function editBrgy(b) {
    document.getElementById('editBrgyId').value          = b.id;
    document.getElementById('editBrgyLabel').textContent = b.barangay_name;
    document.getElementById('editMuni').value            = b.municipality;
    document.getElementById('editBrgyName').value        = b.barangay_name;
    document.getElementById('editBrgyStatus').value      = b.status;
    document.getElementById('editBrgyRegion').value      = b.region   || '';
    document.getElementById('editBrgyProvince').value    = b.province || '';
    document.getElementById('editBrgyZip').value         = b.zip_code || '3114';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('editBrgyModal')).show();
}

// ── Archive Modal ─────────────────────────────────────────
function openArchiveBrgy(id, name) {
    document.getElementById('archBrgyId').value         = id;
    document.getElementById('archBrgyName').textContent = name;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('archiveBrgyModal')).show();
}

let barangayPageLoading = false;
async function loadBarangayPage(url, addHistory = true) {
    if (barangayPageLoading) return;

    const tableBody = document.querySelector('#brgyTable tbody');
    const pagination = document.querySelector('.table-pagination');
    const status = document.getElementById('barangayPaginationStatus');
    if (!tableBody || !pagination || !status) return;

    barangayPageLoading = true;
    tableBody.setAttribute('aria-busy', 'true');
    status.textContent = 'Loading barangays...';
    status.className = 'small text-muted text-center py-2';

    try {
        const response = await fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (!response.ok) {
            throw new Error(`Page request failed (${response.status}).`);
        }

        const pageData = await response.json();
        if (typeof pageData.rowsHtml !== 'string' || typeof pageData.paginationHtml !== 'string') {
            throw new Error('The server response did not contain the barangay table.');
        }

        const paginationTemplate = document.createElement('template');
        paginationTemplate.innerHTML = pageData.paginationHtml;
        const newPagination = paginationTemplate.content.querySelector('.table-pagination');
        if (!newPagination) {
            throw new Error('The server response did not contain pagination controls.');
        }

        tableBody.innerHTML = pageData.rowsHtml;
        pagination.replaceWith(newPagination);
        document.getElementById('selectAll').checked = false;
        if (addHistory) {
            history.pushState({}, '', url);
        }
        status.textContent = '';
        status.className = '';
    } catch (error) {
        console.error('Unable to load the requested barangay page:', error);
        status.textContent = 'Hindi na-load ang page. Subukan ulit.';
        status.className = 'alert alert-danger py-2 mb-2';
    } finally {
        barangayPageLoading = false;
        tableBody.removeAttribute('aria-busy');
    }
}

document.addEventListener('click', event => {
    const link = event.target.closest('.table-pagination a.page-link');
    if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    loadBarangayPage(link.href);
});

window.addEventListener('popstate', () => loadBarangayPage(window.location.href, false));
</script>
<style>.btn-xs { padding: 2px 8px; font-size: 0.75rem; }</style>
</body>
</html>
