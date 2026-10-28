<?php
require_once __DIR__ . '/../config/config.php';
requireRole('barangay_admin');

$db = Database::getInstance()->getConnection();
$u = currentUser();
$bid = (int)$u['barangay_id'];

// Safe migrations — ensure address columns exist
try { $db->exec("ALTER TABLE addresses ADD COLUMN region VARCHAR(100) NULL AFTER id"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE addresses ADD COLUMN zip_code VARCHAR(10) NULL"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE barangays ADD COLUMN region VARCHAR(100) NULL"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE barangays ADD COLUMN province VARCHAR(100) NULL"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE barangays ADD COLUMN zip_code VARCHAR(10) NULL"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE households ADD COLUMN status VARCHAR(20) DEFAULT 'Active'"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE households ADD COLUMN archive_reason VARCHAR(255) NULL"); } catch (PDOException $e) {}

// Fetch barangay info for address auto-fill
$brgyInfoStmt = $db->prepare("SELECT id, barangay_name, municipality, region, province, zip_code FROM barangays WHERE id=?");
$brgyInfoStmt->execute([$bid]);
$hhBrgyInfo = $brgyInfoStmt->fetch() ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrAbort();
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        // Add/edit now handled by add_household.php and edit_household.php
        redirect(BASE_URL . '/barangay_admin/households.php');
    }

    // ── Member management actions ──────────────────────────────────────────
    if ($action === 'add_member') {
        $hhId        = (int)$_POST['household_id'];
        $residentId  = (int)$_POST['resident_id'];
        $relationship = sanitize($_POST['relationship'] ?? 'Member');

        // Verify household belongs to this barangay
        $hhCheck = $db->prepare("SELECT id FROM households WHERE id=? AND barangay_id=? AND status='Active'");
        $hhCheck->execute([$hhId, $bid]);
        if (!$hhCheck->fetch()) {
            flash('hh', 'Household not found.', 'danger');
            redirect(BASE_URL . '/barangay_admin/households.php');
        }
        // Check resident isn't already in this household
        $dupChk = $db->prepare("SELECT id FROM household_members WHERE household_id=? AND resident_id=?");
        $dupChk->execute([$hhId, $residentId]);
        if ($dupChk->fetch()) {
            flash('hh', 'This resident is already a member of this household.', 'warning');
            redirect(BASE_URL . '/barangay_admin/households.php');
        }
        $db->prepare("INSERT INTO household_members (household_id, resident_id, relationship) VALUES (?,?,?)")
           ->execute([$hhId, $residentId, $relationship]);
        // Update member counts
        $db->prepare("UPDATE households SET total_members = total_members + 1 WHERE id=?")->execute([$hhId]);
        $isSeniorChk = $db->prepare("SELECT is_senior, is_pwd FROM residents WHERE id=?");
        $isSeniorChk->execute([$residentId]);
        $rr = $isSeniorChk->fetch();
        if ($rr && $rr['is_senior']) $db->prepare("UPDATE households SET total_senior = total_senior + 1 WHERE id=?")->execute([$hhId]);
        if ($rr && $rr['is_pwd'])    $db->prepare("UPDATE households SET total_pwd = total_pwd + 1 WHERE id=?")->execute([$hhId]);

        $resName = $db->prepare("SELECT CONCAT(first_name,' ',last_name) FROM residents WHERE id=?");
        $resName->execute([$residentId]);
        $resName = $resName->fetchColumn() ?: 'Resident';
        logActivity($u['id'], 'ADD_HH_MEMBER', 'Households', "Added $resName to household #$hhId as $relationship");
        flash('hh', "$resName added to household.", 'success');
        redirect(BASE_URL . '/barangay_admin/households.php');
    }

    if ($action === 'update_member') {
        $memberId    = (int)$_POST['member_id'];
        $relationship = sanitize($_POST['relationship'] ?? 'Member');
        // Verify ownership via join
        $chk = $db->prepare(
            "SELECT hm.id FROM household_members hm
             JOIN households h ON hm.household_id = h.id
             WHERE hm.id=? AND h.barangay_id=?"
        );
        $chk->execute([$memberId, $bid]);
        if (!$chk->fetch()) {
            flash('hh', 'Member record not found.', 'danger');
            redirect(BASE_URL . '/barangay_admin/households.php');
        }
        $db->prepare("UPDATE household_members SET relationship=? WHERE id=?")->execute([$relationship, $memberId]);
        logActivity($u['id'], 'EDIT_HH_MEMBER', 'Households', "Updated member #$memberId relationship to $relationship");
        flash('hh', 'Member relationship updated.', 'success');
        redirect(BASE_URL . '/barangay_admin/households.php');
    }

    if ($action === 'remove_member') {
        $memberId = (int)$_POST['member_id'];
        $hhId     = (int)$_POST['household_id'];
        // Verify ownership
        $chk = $db->prepare(
            "SELECT hm.resident_id FROM household_members hm
             JOIN households h ON hm.household_id = h.id
             WHERE hm.id=? AND hm.household_id=? AND h.barangay_id=?"
        );
        $chk->execute([$memberId, $hhId, $bid]);
        $row = $chk->fetch();
        if (!$row) {
            flash('hh', 'Member record not found.', 'danger');
            redirect(BASE_URL . '/barangay_admin/households.php');
        }
        $resId = $row['resident_id'];
        $db->prepare("DELETE FROM household_members WHERE id=?")->execute([$memberId]);
        // Decrement counts (floor at 0)
        $db->prepare("UPDATE households SET total_members = GREATEST(total_members - 1, 0) WHERE id=?")->execute([$hhId]);
        $isSeniorChk = $db->prepare("SELECT is_senior, is_pwd FROM residents WHERE id=?");
        $isSeniorChk->execute([$resId]);
        $rr = $isSeniorChk->fetch();
        if ($rr && $rr['is_senior']) $db->prepare("UPDATE households SET total_senior = GREATEST(total_senior - 1, 0) WHERE id=?")->execute([$hhId]);
        if ($rr && $rr['is_pwd'])    $db->prepare("UPDATE households SET total_pwd = GREATEST(total_pwd - 1, 0) WHERE id=?")->execute([$hhId]);

        $resName = $db->prepare("SELECT CONCAT(first_name,' ',last_name) FROM residents WHERE id=?");
        $resName->execute([$resId]);
        $resName = $resName->fetchColumn() ?: 'Resident';
        logActivity($u['id'], 'REMOVE_HH_MEMBER', 'Households', "Removed $resName from household #$hhId");
        flash('hh', "$resName removed from household.", 'info');
        redirect(BASE_URL . '/barangay_admin/households.php');
    }
    // ───────────────────────────────────────────────────────────────────────

    if ($action === 'archive') {
        $id = (int)$_POST['household_id'];
        $reason = sanitize($_POST['archive_reason'] ?? '');
        
        $row = $db->prepare("SELECT * FROM households WHERE id=? AND barangay_id=?");
        $row->execute([$id, $bid]);
        $household = $row->fetch();
        
        if ($household) {
            archiveRecord($u['id'], 'households', $id, $household, $reason);
            $db->prepare("UPDATE households SET status='Archived', archive_reason=? WHERE id=? AND barangay_id=?")->execute([$reason, $id, $bid]);
            flash('hh', 'Household archived successfully.', 'success');
        }
        redirect(BASE_URL . '/barangay_admin/households.php');
    }
}

$search = sanitize($_GET['search'] ?? '');
$perPage = 10;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$baseWhere = "FROM households h LEFT JOIN addresses a ON h.address_id=a.id LEFT JOIN residents r ON h.head_resident_id=r.id WHERE h.barangay_id=$bid AND h.status != 'Archived'";
if ($search) {
    $s = $db->quote('%' . $search . '%');
    $baseWhere .= " AND (
        h.household_code LIKE $s OR
        r.first_name LIKE $s OR r.last_name LIKE $s OR r.middle_name LIKE $s OR
        CONCAT(r.first_name,' ',r.last_name) LIKE $s OR
        CONCAT(r.last_name,' ',r.first_name) LIKE $s OR
        CONCAT(r.first_name,' ',r.middle_name,' ',r.last_name) LIKE $s OR
        CONCAT(r.last_name,', ',r.first_name,' ',r.middle_name) LIKE $s OR
        CONCAT(r.last_name,', ',r.first_name) LIKE $s
    )";
}

$totalCount = (int)$db->query("SELECT COUNT(*) $baseWhere")->fetchColumn();
$totalPages = max(1, (int)ceil($totalCount / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$query = "SELECT h.*, a.purok, a.street, a.zone, a.region as addr_region, a.province as addr_province, a.city as addr_city, a.barangay as addr_barangay, a.zip_code as addr_zip, CONCAT(r.last_name,', ',r.first_name) as head_name,
    (SELECT COUNT(*) FROM household_members hm2 JOIN residents r2 ON hm2.resident_id=r2.id WHERE hm2.household_id=h.id) AS live_members,
    (SELECT COUNT(*) FROM household_members hm2 JOIN residents r2 ON hm2.resident_id=r2.id WHERE hm2.household_id=h.id AND r2.is_senior=1) AS live_senior,
    (SELECT COUNT(*) FROM household_members hm2 JOIN residents r2 ON hm2.resident_id=r2.id WHERE hm2.household_id=h.id AND r2.is_pwd=1) AS live_pwd,
    (SELECT COUNT(*) FROM household_members hm2 JOIN residents r2 ON hm2.resident_id=r2.id WHERE hm2.household_id=h.id AND r2.resident_status='Active') AS live_active
    $baseWhere ORDER BY h.created_at DESC LIMIT $perPage OFFSET $offset";
$households = $db->query($query)->fetchAll();

$residents = $db->prepare(
    "SELECT r.id,
            CONCAT(r.last_name,', ',r.first_name) as full_name,
            r.contact_number,
            a.purok, a.street, a.zone
     FROM residents r
     LEFT JOIN addresses a ON r.address_id = a.id
     WHERE r.barangay_id = ? AND r.resident_status = 'Active'
     ORDER BY r.last_name, r.first_name"
);
$residents->execute([$bid]);
$residents = $residents->fetchAll();


$flash = getFlash('hh');
?>

<?php
$pageIcon     = 'bi-house-fill';
$pageTitle    = 'Household Management';
$pageSubtitle = 'Manage household records and family groupings in your barangay.';
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

    <div class="card mb-3">
        <div class="card-body py-2">
            <div class="d-flex gap-2 flex-wrap align-items-center">
                <form method="GET" class="d-flex gap-2 align-items-center flex-fill" id="searchForm">
                    <div class="input-group input-group-sm ac-wrapper" style="width:240px;">
                        <span class="input-group-text bg-white border-end-0" style="border-color:#dee2e6;">
                            <i class="bi bi-search text-muted" style="font-size:0.8rem;"></i>
                        </span>
                        <input type="text" id="hhSearchInput" name="search" class="form-control form-control-sm border-start-0 border-end-0 ps-0"
                               placeholder="Search code/head..." autocomplete="off"
                               value="<?= htmlspecialchars($search) ?>" style="border-color:#dee2e6;">
                        <span class="input-group-text bg-white border-start-0 p-0" style="border-color:#dee2e6;">
                            <span id="hhSearchClear" title="Clear search"
                                  style="width:28px;height:28px;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;color:#9ca3af;border-radius:4px;<?= $search ? '' : 'visibility:hidden;' ?>"
                                  onmouseover="this.style.color='#dc2626';this.style.background='#fee2e2';"
                                  onmouseout="this.style.color='#9ca3af';this.style.background='transparent';">
                                <i class="bi bi-x-lg" style="font-size:0.75rem;"></i>
                            </span>
                        </span>
                        <div class="ac-list" id="hhAcList"></div>
                    </div>
                    <span class="text-muted small"><?= $totalCount ?> households</span>
                </form>
                <div class="d-flex gap-2 ms-auto">
                    <button type="button" id="hhDownloadSelectedBtn"
                            class="btn btn-sm btn-outline-success fw-semibold"
                            onclick="downloadHouseholdsExcel('selected')"
                            disabled
                            title="Download selected households as Excel">
                        <i class="bi bi-file-earmark-excel me-1"></i>
                        Download Selected
                        <span id="hhSelectedBadge" class="badge bg-success ms-1" style="display:none;font-size:0.65rem;">0</span>
                    </button>
                    <button type="button"
                            class="btn btn-sm btn-outline-primary fw-semibold"
                            onclick="downloadHouseholdsExcel('all')"
                            title="Download ALL households across all pages">
                        <i class="bi bi-file-earmark-excel-fill me-1"></i>
                        Download All
                    </button>
                    <a href="<?= BASE_URL ?>/barangay_admin/add_household.php"
                       class="btn btn-sm btn-success fw-semibold">
                        <i class="bi bi-house-add-fill me-1"></i>Add Household
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div style="background:#fff;border-radius:18px;box-shadow:0 2px 16px rgba(0,0,0,0.07);border:1px solid #e0f0e4;overflow:hidden;">
        <!-- Persistent selection bar -->
        <div id="hhSelectionBar" style="display:none;background:#eef6ff;border-bottom:1.5px solid #bfdbfe;padding:8px 20px;font-size:0.82rem;color:#1a4f7a;align-items:center;gap:10px;flex-wrap:wrap;">
            <i class="bi bi-check2-square me-1"></i>
            <span id="hhSelBarCount" style="font-weight:700;"></span> household(s) selected across pages.
            <button type="button" onclick="clearAllHHSelection()" class="btn btn-sm btn-outline-danger ms-auto" style="font-size:0.72rem;padding:2px 10px;">
                <i class="bi bi-x-lg me-1"></i>Clear All
            </button>
        </div>
        <div class="table-responsive">
            <table class="table mb-0" style="font-size:0.85rem;">
                <thead>
                    <tr style="background:#f4f7fa;border-bottom:2px solid #d0ecd6;">
                        <th class="ps-3 py-3" style="width:44px;border:none;">
                            <input type="checkbox" class="form-check-input mt-0" id="hhSelectAll" title="Select all on this page" style="cursor:pointer;">
                        </th>
                        <th class="ps-1 py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">#</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">Code</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">Head of Family</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">Address</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">Members</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">Senior</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">PWD</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">Income Range</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">Status</th>
                        <th class="pe-4 py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($households)): ?>
                    <tr><td colspan="11" class="text-center text-muted py-5"><i class="bi bi-inbox" style="font-size:2.5rem;opacity:0.3;display:block;margin-bottom:10px;"></i>No households found.</td></tr>
                <?php else: ?>
                    <?php foreach ($households as $i => $h): ?>
                    <tr style="border-bottom:1px solid #e8f4fb;transition:background 0.15s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                        <td class="ps-3 py-3" style="border:none;vertical-align:middle;">
                            <input type="checkbox" class="form-check-input mt-0 hh-row-check" data-id="<?= $h['id'] ?>" style="cursor:pointer;">
                        </td>
                        <td class="ps-1 py-3" style="border:none;color:#3a7fa8;"><?= $offset + $i+1 ?></td>
                        <td class="py-3" style="border:none;"><code style="font-size:0.78rem;color:#1a4f7a;background:rgba(26,79,122,0.07);padding:2px 6px;border-radius:4px;"><?= htmlspecialchars($h['household_code']) ?></code></td>
                        <td class="py-3" style="border:none;font-weight:600;color:#1a2e1f;"><?= htmlspecialchars($h['head_name'] ?? 'N/A') ?></td>
                        <td class="py-3" style="border:none;font-size:0.8rem;color:#3a7fa8;"><?= htmlspecialchars(($h['purok'] ? $h['purok'].', ' : '').($h['addr_barangay']??'')) ?></td>
                        <td class="py-3" style="border:none;"><span style="background:rgba(26,79,122,0.1);color:#1a4f7a;font-size:0.72rem;font-weight:700;padding:3px 10px;border-radius:20px;"><?= $h['live_members'] ?></span></td>
                        <td class="py-3" style="border:none;color:#2a5070;font-size:0.82rem;"><?= $h['live_senior'] ?></td>
                        <td class="py-3" style="border:none;color:#2a5070;font-size:0.82rem;"><?= $h['live_pwd'] ?></td>
                        <td class="py-3" style="border:none;font-size:0.8rem;color:#2a5070;"><?= htmlspecialchars($h['income_range'] ?? '—') ?></td>
                        <td class="py-3" style="border:none;">
                            <?php
                            $hhStatus = $h['status'] ?? 'Active';
                            $sBg    = $hhStatus === 'Active' ? '#d1fae5' : '#f3f4f6';
                            $sColor = $hhStatus === 'Active' ? '#065f46' : '#6b7280';
                            $sDot   = $hhStatus === 'Active' ? '#10b981' : '#9ca3af';
                            ?>
                            <span style="background:<?= $sBg ?>;color:<?= $sColor ?>;font-size:0.7rem;font-weight:700;padding:3px 9px;border-radius:20px;display:inline-flex;align-items:center;gap:5px;">
                                <span style="width:6px;height:6px;border-radius:50%;background:<?= $sDot ?>;display:inline-block;"></span>
                                <?= htmlspecialchars($hhStatus) ?>
                            </span>
                        </td>
                        <td class="pe-4 py-3" style="border:none;">
                            <div class="d-flex gap-1">
                                <button class="btn btn-xs btn-outline-info" onclick="viewHH(<?= htmlspecialchars(json_encode($h)) ?>)"><i class="bi bi-eye"></i></button>
                                <a href="<?= BASE_URL ?>/barangay_admin/edit_household.php?id=<?= $h['id'] ?>" class="btn btn-xs btn-outline-primary" title="Edit"><i class="bi bi-pencil"></i></a>
                                <button class="btn btn-xs btn-outline-danger" onclick="archiveHH(<?= $h['id'] ?>, '<?= htmlspecialchars($h['household_code']) ?>')"><i class="bi bi-archive"></i></button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3 p-2 bg-white rounded">
        <?php
        $paginationBase = '?' . http_build_query(array_filter(['search' => $search]));
        $paginationBase = ($paginationBase === '?') ? '?' : $paginationBase . '&';
        $paginationLabel = 'household';
        include __DIR__ . '/../includes/pagination.php';
        ?>
    </div>
</div>

<!-- View Modal -->
<div class="modal fade" id="viewHHModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;overflow:hidden;">
            <div class="modal-header" style="background:linear-gradient(90deg,#1a4f7a,#2d7db3);border:none;padding:14px 20px;">
                <div class="d-flex align-items-center gap-3">
                    <div style="width:38px;height:38px;background:rgba(255,255,255,0.15);border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="bi bi-house-fill text-white" style="font-size:1rem;"></i>
                    </div>
                    <div>
                        <h6 class="modal-title text-white fw-bold mb-0">Household Details</h6>
                        <div style="font-size:0.72rem;color:rgba(255,255,255,0.7);">View Information</div>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div id="hhDetails" style="overflow-y:auto;max-height:72vh;"></div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>


<!-- Archive Modal -->
<div class="modal fade" id="archiveHHModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST" id="archiveHHForm">
            <input type="hidden" name="action" value="archive">
            <?= csrfField() ?>
            <input type="hidden" name="household_id" id="archiveHHId">
            <div class="modal-content" style="border-radius:16px; overflow:hidden;">
                <div class="d-flex">
                    <!-- LEFT PANEL -->
                    <div class="d-flex flex-column align-items-center justify-content-center px-4 py-5"
                         style="background:linear-gradient(160deg,#c62828 0%,#e53935 100%); min-width:200px; width:200px; align-self:stretch;">
                        <div style="width:72px;height:72px;background:rgba(255,255,255,0.15);border-radius:50%;display:flex;align-items:center;justify-content:center;border:3px solid rgba(255,255,255,0.3);">
                            <i class="bi bi-archive-fill text-white" style="font-size:2rem;"></i>
                        </div>
                        <h5 class="fw-bold mt-3 mb-1 text-white text-center">Archive Household</h5>
                        <p class="text-white-50 small text-center mb-0">This action is reversible</p>
                    </div>
                    <!-- RIGHT PANEL -->
                    <div class="flex-fill d-flex flex-column justify-content-between p-4">
                        <div>
                            <p>Are you sure you want to archive household <strong id="archiveHHCode"></strong>?</p>
                            <label class="form-label">Archive Reason (Required)</label>
                            <select name="archive_reason" class="form-select" required>
                                <option value="">— Select a reason —</option>
                                <option value="Relocated / Moved Out">Relocated / Moved Out</option>
                                <option value="Deceased (All Members)">Deceased (All Members)</option>
                                <option value="Duplicate Record">Duplicate Record</option>
                                <option value="Merged Household">Merged Household</option>
                                <option value="Inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="d-flex gap-2 mt-3">
                            <button type="button" class="btn btn-outline-secondary flex-fill" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-danger flex-fill fw-semibold">
                                <i class="bi bi-check-lg me-1"></i>Archive
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

function hhInfoGrid(h, memberRows) {
    const addr = [h.purok, h.addr_barangay].filter(Boolean).join(', ') || 'N/A';

    const membersHtml = memberRows != null
        ? `<div style="margin-top:14px;">
            <div style="font-size:0.75rem;font-weight:700;color:#1a4f7a;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:6px;">Household Members</div>
            <div style="border:1px solid #d0ecd6;border-radius:8px;overflow:hidden;">
              <table style="width:100%;border-collapse:collapse;font-size:0.82rem;">
                <thead>
                  <tr style="background:#e8f4fb;">
                    <th style="padding:7px 10px;font-size:0.68rem;font-weight:700;color:#3a7fa8;text-transform:uppercase;letter-spacing:0.06em;border:none;width:32px;">#</th>
                    <th style="padding:7px 10px;font-size:0.68rem;font-weight:700;color:#3a7fa8;text-transform:uppercase;letter-spacing:0.06em;border:none;">Name</th>
                    <th style="padding:7px 10px;font-size:0.68rem;font-weight:700;color:#3a7fa8;text-transform:uppercase;letter-spacing:0.06em;border:none;">Relationship</th>
                    <th style="padding:7px 10px;font-size:0.68rem;font-weight:700;color:#3a7fa8;text-transform:uppercase;letter-spacing:0.06em;border:none;">Age</th>
                    <th style="padding:7px 10px;font-size:0.68rem;font-weight:700;color:#3a7fa8;text-transform:uppercase;letter-spacing:0.06em;border:none;">Gender</th>
                    <th style="padding:7px 10px;font-size:0.68rem;font-weight:700;color:#3a7fa8;text-transform:uppercase;letter-spacing:0.06em;border:none;">Civil Status</th>
                    <th style="padding:7px 10px;font-size:0.68rem;font-weight:700;color:#3a7fa8;text-transform:uppercase;letter-spacing:0.06em;border:none;">Employment Status</th>
                    <th style="padding:7px 10px;font-size:0.68rem;font-weight:700;color:#3a7fa8;text-transform:uppercase;letter-spacing:0.06em;border:none;">Status</th>
                  </tr>
                </thead>
                <tbody>${memberRows}</tbody>
              </table>
            </div>
           </div>`
        : '';

    return `
        <table style="width:100%;border-collapse:collapse;font-size:0.8rem;margin-bottom:14px;">
            <tbody>
                <tr style="border-bottom:1px solid #f0f4f8;">
                    <td style="padding:5px 10px;width:50%;vertical-align:top;">
                        <div style="font-size:0.67rem;font-weight:700;color:#3a7fa8;text-transform:uppercase;letter-spacing:0.05em;">Household Code</div>
                        <div style="font-weight:700;color:#e83e8c;">${h.household_code}</div>
                    </td>
                    <td style="padding:5px 10px;width:50%;vertical-align:top;">
                        <div style="font-size:0.67rem;font-weight:700;color:#3a7fa8;text-transform:uppercase;letter-spacing:0.05em;">Head of Family</div>
                        <div style="font-weight:600;color:#1a2e1f;">${h.head_name||'N/A'}</div>
                    </td>
                </tr>
                <tr style="border-bottom:1px solid #f0f4f8;">
                    <td style="padding:5px 10px;vertical-align:top;">
                        <div style="font-size:0.67rem;font-weight:700;color:#3a7fa8;text-transform:uppercase;letter-spacing:0.05em;">Contact</div>
                        <div style="color:#1a2e1f;">${h.contact_number||'—'}</div>
                    </td>
                    <td style="padding:5px 10px;vertical-align:top;">
                        <div style="font-size:0.67rem;font-weight:700;color:#3a7fa8;text-transform:uppercase;letter-spacing:0.05em;">Income Range</div>
                        <div style="color:#1a2e1f;">${h.income_range||'—'}</div>
                    </td>
                </tr>
                <tr style="border-bottom:1px solid #f0f4f8;">
                    <td style="padding:5px 10px;vertical-align:top;">
                        <div style="font-size:0.67rem;font-weight:700;color:#3a7fa8;text-transform:uppercase;letter-spacing:0.05em;">Length of Stay</div>
                        <div style="color:#1a2e1f;">${h.length_of_stay ? h.length_of_stay+' yrs' : '—'}</div>
                    </td>
                    <td style="padding:5px 10px;vertical-align:top;">
                        <div style="font-size:0.67rem;font-weight:700;color:#3a7fa8;text-transform:uppercase;letter-spacing:0.05em;">Address</div>
                        <div style="color:#1a2e1f;">${addr}</div>
                    </td>
                </tr>
                <tr>
                    <td colspan="2" style="padding:5px 10px;">
                        <div style="display:flex;gap:24px;flex-wrap:wrap;">
                            <div>
                                <div style="font-size:0.67rem;font-weight:700;color:#3a7fa8;text-transform:uppercase;letter-spacing:0.05em;">Members</div>
                                <div style="font-weight:700;color:#1a4f7a;">${h.live_members ?? h.total_members}</div>
                            </div>
                            <div>
                                <div style="font-size:0.67rem;font-weight:700;color:#3a7fa8;text-transform:uppercase;letter-spacing:0.05em;">Active</div>
                                <div style="font-weight:700;color:#198754;">${h.live_active ?? '—'}</div>
                            </div>
                            <div>
                                <div style="font-size:0.67rem;font-weight:700;color:#3a7fa8;text-transform:uppercase;letter-spacing:0.05em;">Senior</div>
                                <div style="color:#1a2e1f;">${h.live_senior ?? h.total_senior}</div>
                            </div>
                            <div>
                                <div style="font-size:0.67rem;font-weight:700;color:#3a7fa8;text-transform:uppercase;letter-spacing:0.05em;">PWD</div>
                                <div style="color:#1a2e1f;">${h.live_pwd ?? h.total_pwd}</div>
                            </div>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
        ${membersHtml}`;
}

function viewHH(h) {
    document.getElementById('hhDetails').innerHTML =
        '<div class="text-center text-muted py-3"><span class="spinner-border spinner-border-sm me-2"></span>Loading…</div>';
    new bootstrap.Modal(document.getElementById('viewHHModal')).show();

    fetch('<?= BASE_URL ?>/ajax/get_household_members.php?household_id=' + h.id)
        .then(r => r.json())
        .then(members => {
            let memberRows = members.length
                ? members.map((m, i) => {
                    const relColors = { Head:'#1a4f7a', Spouse:'#5a7fa8', Son:'#3a7fa8', Daughter:'#3a7fa8',
                        Father:'#5a6f7a', Mother:'#5a6f7a', Brother:'#6c757d', Sister:'#6c757d' };
                    const c = relColors[m.relationship] || '#6c757d';
                    const relBadge = `<span style="background:${c};color:#fff;font-size:0.7rem;padding:2px 8px;border-radius:20px;font-weight:600;">${m.relationship||'—'}</span>`;
                    const statusColor = m.resident_status === 'Active' ? '#198754' : '#6c757d';
                    return `<tr style="border-top:1px solid #e8f4fb;">
                        <td style="padding:7px 10px;color:#3a7fa8;font-weight:600;border:none;">${i+1}</td>
                        <td style="padding:7px 10px;font-weight:600;color:#1a2e1f;border:none;">${m.full_name}</td>
                        <td style="padding:7px 10px;border:none;">${relBadge}</td>
                        <td style="padding:7px 10px;color:#374151;border:none;">${m.age||'—'}</td>
                        <td style="padding:7px 10px;color:#374151;border:none;">${m.gender||'—'}</td>
                        <td style="padding:7px 10px;color:#374151;border:none;">${m.civil_status||'—'}</td>
                        <td style="padding:7px 10px;color:#374151;border:none;">${m.occupation_status||'—'}</td>
                        <td style="padding:7px 10px;border:none;"><span style="color:${statusColor};font-weight:600;">${m.resident_status||'—'}</span></td>
                    </tr>`;
                }).join('')
                : `<tr><td colspan="8" style="text-align:center;padding:12px;color:#6c757d;font-size:0.82rem;border:none;">No members linked yet.</td></tr>`;
            document.getElementById('hhDetails').innerHTML = hhInfoGrid(h, memberRows);
        })
        .catch(() => {
            document.getElementById('hhDetails').innerHTML = hhInfoGrid(h, null);
        });
}

function archiveHH(id, code) {
    document.getElementById('archiveHHId').value = id;
    document.getElementById('archiveHHCode').textContent = code;
    new bootstrap.Modal(document.getElementById('archiveHHModal')).show();
}

// ── Searchable Resident Dropdown ─────────────────────────────────────────
const _allResidents = <?= json_encode(array_map(fn($r) => ['id' => $r['id'], 'name' => $r['full_name']], $residents)) ?>;
let _residentDropdownOpen = false;

function renderResidentList(items) {
    const dd = document.getElementById('residentDropdown');
    if (!items.length) {
        dd.innerHTML = '<div style="padding:10px 14px;font-size:0.82rem;color:#6c757d;text-align:center;">No residents found.</div>';
        return;
    }
    dd.innerHTML = items.map(r =>
        `<div class="resident-opt" data-id="${r.id}" data-name="${escHtml(r.name)}"
              style="padding:7px 14px;font-size:0.83rem;cursor:pointer;color:#1a2e1f;border-bottom:1px solid #f0f4f8;transition:background 0.1s;"
              onmouseover="this.style.background='#e8f4fb'" onmouseout="this.style.background=''"
              onclick="selectResident(${r.id}, '${escHtml(r.name)}')">${escHtml(r.name)}</div>`
    ).join('');
}

function openResidentDropdown() {
    const dd = document.getElementById('residentDropdown');
    const q  = document.getElementById('residentSearchInput').value.trim();
    renderResidentList(q ? filterList(q) : _allResidents);
    dd.style.display = 'block';
    _residentDropdownOpen = true;
    document.getElementById('residentSearchBox').style.borderColor = '#86b7fe';
    document.getElementById('residentSearchBox').style.boxShadow = '0 0 0 3px rgba(13,110,253,0.15)';
}

function filterList(q) {
    const lq = q.toLowerCase();
    return _allResidents.filter(r => r.name.toLowerCase().includes(lq));
}

function filterResidents(q) {
    const results = q.trim() ? filterList(q) : _allResidents;
    renderResidentList(results);
    const dd = document.getElementById('residentDropdown');
    dd.style.display = 'block';
    _residentDropdownOpen = true;
    // Clear selection if user is typing again
    document.getElementById('mmResidentSelect').value = '';
    document.getElementById('residentClearBtn').style.display = q ? 'inline' : 'none';
    // Highlight match in results
    if (q.trim()) {
        dd.querySelectorAll('.resident-opt').forEach(el => {
            const name = el.dataset.name;
            const idx  = name.toLowerCase().indexOf(q.toLowerCase());
            if (idx >= 0) {
                el.innerHTML = escHtml(name.substring(0, idx))
                    + `<strong style="color:#1a4f7a;">${escHtml(name.substring(idx, idx + q.length))}</strong>`
                    + escHtml(name.substring(idx + q.length));
            }
        });
    }
}

function selectResident(id, name) {
    document.getElementById('mmResidentSelect').value = id;
    document.getElementById('residentSearchInput').value = name;
    document.getElementById('residentClearBtn').style.display = 'inline';
    document.getElementById('residentDropdown').style.display = 'none';
    document.getElementById('residentSearchBox').style.borderColor = '#198754';
    document.getElementById('residentSearchBox').style.boxShadow = '0 0 0 3px rgba(25,135,84,0.15)';
    _residentDropdownOpen = false;
}

function clearResidentSearch(e) {
    e.stopPropagation();
    document.getElementById('mmResidentSelect').value = '';
    document.getElementById('residentSearchInput').value = '';
    document.getElementById('residentClearBtn').style.display = 'none';
    document.getElementById('residentDropdown').style.display = 'none';
    document.getElementById('residentSearchBox').style.borderColor = '#ced4da';
    document.getElementById('residentSearchBox').style.boxShadow = 'none';
    document.getElementById('residentSearchInput').focus();
    _residentDropdownOpen = false;
}

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
    if (!document.getElementById('residentSearchWrap')?.contains(e.target)) {
        document.getElementById('residentDropdown').style.display = 'none';
        document.getElementById('residentSearchBox').style.borderColor = '#ced4da';
        document.getElementById('residentSearchBox').style.boxShadow = 'none';
        _residentDropdownOpen = false;
    }
});

// Keyboard nav: Escape closes dropdown
document.getElementById('residentSearchInput')?.addEventListener('keydown', function(e) {
    const dd = document.getElementById('residentDropdown');
    const items = dd.querySelectorAll('.resident-opt');
    if (e.key === 'Escape') {
        dd.style.display = 'none';
        _residentDropdownOpen = false;
    } else if (e.key === 'ArrowDown' && items.length) {
        e.preventDefault();
        items[0].focus();
    }
});

// ── Relationship custom dropdown ─────────────────────────────────────────
function toggleRelDropdown() {
    const dd = document.getElementById('relDropdown');
    dd.style.display = dd.style.display === 'none' ? 'block' : 'none';
}
function pickRelationship(val) {
    document.getElementById('mmRelationshipVal').value = val;
    document.getElementById('relSearchLabel').textContent = val;
    document.getElementById('relSearchLabel').style.color = '#212529';
    document.getElementById('relDropdown').style.display = 'none';
}
// close rel dropdown on outside click
document.addEventListener('click', function(e) {
    const wrap = document.getElementById('relSearchWrap');
    if (wrap && !wrap.contains(e.target)) {
        document.getElementById('relDropdown').style.display = 'none';
    }
});

// ── Cross-page selection store for households ────────────────────────────
const HH_STORE_KEY = 'hh_selected_<?= $bid ?>';

function loadHHStore() {
    try { return new Set(JSON.parse(localStorage.getItem(HH_STORE_KEY) || '[]')); }
    catch(e) { return new Set(); }
}
function saveHHStore(set) {
    localStorage.setItem(HH_STORE_KEY, JSON.stringify([...set]));
}

let selectedHHIds = loadHHStore();

// ── Restore checkboxes on page load ──────────────────────────────────────
document.querySelectorAll('.hh-row-check').forEach(function(cb) {
    if (selectedHHIds.has(parseInt(cb.dataset.id))) {
        cb.checked = true;
        if (cb.closest('tr')) cb.closest('tr').style.background = '#eef6ff';
    }
});
updateHHExcelBtn();
updateHHSelectionBar();

// ── Select all (current page only) ───────────────────────────────────────
const hhSelectAll = document.getElementById('hhSelectAll');
if (hhSelectAll) {
    const allCbs     = document.querySelectorAll('.hh-row-check');
    const allChecked = [...allCbs].every(cb => cb.checked);
    hhSelectAll.checked       = allCbs.length > 0 && allChecked;
    hhSelectAll.indeterminate = !allChecked && [...allCbs].some(cb => cb.checked);

    hhSelectAll.addEventListener('change', function() {
        const check = this.checked;
        document.querySelectorAll('.hh-row-check').forEach(function(cb) {
            cb.checked = check;
            const id = parseInt(cb.dataset.id);
            if (check) selectedHHIds.add(id);
            else        selectedHHIds.delete(id);
            if (cb.closest('tr')) cb.closest('tr').style.background = check ? '#eef6ff' : '';
        });
        saveHHStore(selectedHHIds);
        updateHHExcelBtn();
        updateHHSelectionBar();
    });
}

// ── Per-row checkbox ──────────────────────────────────────────────────────
document.querySelectorAll('.hh-row-check').forEach(function(cb) {
    cb.addEventListener('change', function() {
        const id = parseInt(this.dataset.id);
        if (this.checked) selectedHHIds.add(id);
        else               selectedHHIds.delete(id);
        saveHHStore(selectedHHIds);
        if (this.closest('tr')) this.closest('tr').style.background = this.checked ? '#eef6ff' : '';

        if (hhSelectAll) {
            const allCbs     = document.querySelectorAll('.hh-row-check');
            const checkedCbs = document.querySelectorAll('.hh-row-check:checked');
            hhSelectAll.checked       = checkedCbs.length === allCbs.length;
            hhSelectAll.indeterminate = checkedCbs.length > 0 && checkedCbs.length < allCbs.length;
        }
        updateHHExcelBtn();
        updateHHSelectionBar();
    });
});

function updateHHExcelBtn() {
    const btn   = document.getElementById('hhDownloadSelectedBtn');
    const badge = document.getElementById('hhSelectedBadge');
    if (!btn) return;
    const count = selectedHHIds.size;
    if (count > 0) {
        btn.disabled = false;
        btn.classList.remove('btn-outline-success');
        btn.classList.add('btn-success');
        badge.style.display = 'inline-block';
        badge.textContent   = count;
    } else {
        btn.disabled = true;
        btn.classList.remove('btn-success');
        btn.classList.add('btn-outline-success');
        badge.style.display = 'none';
    }
}

function updateHHSelectionBar() {
    const bar   = document.getElementById('hhSelectionBar');
    const label = document.getElementById('hhSelBarCount');
    if (!bar) return;
    if (selectedHHIds.size > 0) {
        bar.style.display = 'flex';
        label.textContent = selectedHHIds.size;
    } else {
        bar.style.display = 'none';
    }
}

function clearAllHHSelection() {
    selectedHHIds.clear();
    saveHHStore(selectedHHIds);
    document.querySelectorAll('.hh-row-check').forEach(cb => {
        cb.checked = false;
        if (cb.closest('tr')) cb.closest('tr').style.background = '';
    });
    if (hhSelectAll) { hhSelectAll.checked = false; hhSelectAll.indeterminate = false; }
    updateHHExcelBtn();
    updateHHSelectionBar();
}

function downloadHouseholdsExcel(mode) {
    if (mode === 'selected' && selectedHHIds.size === 0) return;

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '<?= BASE_URL ?>/barangay_admin/export_households_selected.php';
    form.style.display = 'none';

    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    if (csrfMeta) {
        const ci = document.createElement('input');
        ci.type = 'hidden'; ci.name = 'csrf_token'; ci.value = csrfMeta.content;
        form.appendChild(ci);
    }

    const mi = document.createElement('input');
    mi.type = 'hidden'; mi.name = 'mode'; mi.value = mode;
    form.appendChild(mi);

    if (mode === 'selected') {
        selectedHHIds.forEach(function(id) {
            const inp = document.createElement('input');
            inp.type = 'hidden'; inp.name = 'ids[]'; inp.value = id;
            form.appendChild(inp);
        });
    }

    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
}

</script>
<style>.btn-xs { padding: 2px 8px; font-size: 0.75rem; }</style>

<script src="<?= BASE_URL ?>/assets/js/autocomplete.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    initAutocomplete('hhSearchInput', 'hhAcList', '<?= BASE_URL ?>/ajax/search_households.php', 'hhSearchClear');
});
</script>
</body>
</html>
