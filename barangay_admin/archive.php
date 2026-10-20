<?php
require_once __DIR__ . '/../config/config.php';
requireRole('barangay_admin');

$db  = Database::getInstance()->getConnection();
$u   = currentUser();
$bid = (int)$u['barangay_id'];

// Safe migration
$db->exec("CREATE TABLE IF NOT EXISTS notice_dismissals (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    notice_id    INT UNSIGNED NOT NULL,
    user_id      INT UNSIGNED NOT NULL,
    dismissed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_notice_user (notice_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── POST Actions ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrAbort();
    $action = $_POST['action'] ?? '';

    if ($action === 'restore') {
        $archiveId = (int)$_POST['archive_id'];
        $row = $db->prepare("SELECT * FROM archives WHERE id=?");
        $row->execute([$archiveId]);
        $archive = $row->fetch();
        if ($archive) {
            $data      = json_decode($archive['data_json'], true);
            $recordBid = $data['barangay_id'] ?? null;
            if ($recordBid && (int)$recordBid !== $bid) {
                flash('arch', 'Unauthorized: This record does not belong to your barangay.', 'danger');
                redirect(BASE_URL . '/barangay_admin/archive.php');
            }
            if ($archive['table_name'] === 'residents') {
                $db->prepare("UPDATE residents SET resident_status='Active' WHERE id=? AND barangay_id=?")->execute([$archive['record_id'], $bid]);
            } elseif ($archive['table_name'] === 'officials') {
                $db->prepare("UPDATE officials SET status='active' WHERE id=? AND barangay_id=?")->execute([$archive['record_id'], $bid]);
            } elseif ($archive['table_name'] === 'households') {
                $db->prepare("UPDATE households SET status='Active', archive_reason=NULL WHERE id=? AND barangay_id=?")->execute([$archive['record_id'], $bid]);
            } elseif ($archive['table_name'] === 'blotter_cases') {
                $db->prepare("UPDATE blotter_cases SET status='Closed' WHERE id=? AND barangay_id=?")->execute([$archive['record_id'], $bid]);
            } elseif ($archive['table_name'] === 'certificates') {
                // Restore: re-insert the certificate row
                $d = json_decode($archive['data_json'], true);
                $db->prepare("INSERT INTO certificates (id,barangay_id,resident_id,certificate_type,purpose,issued_by,date_issued,created_at) VALUES (?,?,?,?,?,?,?,?)")
                   ->execute([$d['id'],$d['barangay_id'],$d['resident_id'],$d['certificate_type'],$d['purpose'],$d['issued_by'],$d['date_issued'],$d['created_at']]);
            }
            $db->prepare("DELETE FROM archives WHERE id=?")->execute([$archiveId]);
            logActivity($u['id'], 'RESTORE_ARCHIVE', 'Archive', "Restored {$archive['table_name']} record for " . ucfirst($archive['table_name']));
            flash('arch', 'Record restored successfully.', 'success');
        }
        redirect(BASE_URL . '/barangay_admin/archive.php');
    }

    if ($action === 'restore_notice') {
        $nid = (int)$_POST['notice_id'];
        // Simply remove the personal dismissal — notice reappears in LGU Notices if still Active
        $db->prepare("DELETE FROM notice_dismissals WHERE notice_id=? AND user_id=?")->execute([$nid, $u['id']]);
        logActivity($u['id'], 'RESTORE_NOTICE', 'Archive', "Restored a dismissed LGU notice");
        flash('arch', 'Notice restored. It will appear in LGU Notices if still active.', 'success');
        redirect(BASE_URL . '/barangay_admin/archive.php?type=notices');
    }

    // (notice permanent delete not available for barangay admin)
}

$search     = sanitize($_GET['search'] ?? '');
$typeFilter = sanitize($_GET['type']   ?? '');
$perPage    = 10;
$page       = max(1, (int)($_GET['page'] ?? 1));

$recordTypes = ['residents', 'officials', 'households', 'blotter_cases', 'certificates'];
$rtList      = implode("','", $recordTypes);

// Count queries for pagination
$recordBaseWhere = "FROM archives a LEFT JOIN users u ON a.archived_by = u.id WHERE a.table_name IN ('$rtList') AND (a.data_json LIKE '%\"barangay_id\":\"$bid\"%' OR a.data_json LIKE '%\"barangay_id\":$bid%' OR a.data_json LIKE '%\"barangay_id\": $bid%' OR a.data_json LIKE '%\"barangay_id\": \"$bid\"%')";
if ($search)                                            $recordBaseWhere .= " AND (a.table_name LIKE '%$search%' OR a.data_json LIKE '%$search%')";
if ($typeFilter && in_array($typeFilter, $recordTypes)) $recordBaseWhere .= " AND a.table_name='$typeFilter'";

// Show all notices this user has personally dismissed (no status filter — LGU controls that)
$noticeBaseWhere = "FROM notices n
                    LEFT JOIN users u ON n.created_by = u.id
                    INNER JOIN notice_dismissals nd ON nd.notice_id = n.id AND nd.user_id = {$u['id']}
                    WHERE 1=1";
if ($search) $noticeBaseWhere .= " AND (n.title LIKE '%$search%' OR n.content LIKE '%$search%')";

$showRecords = ($typeFilter !== 'notices');
$showNotices = ($typeFilter === '' || $typeFilter === 'notices');

$recordCountTotal = $showRecords ? (int)$db->query("SELECT COUNT(*) $recordBaseWhere")->fetchColumn() : 0;
$noticeCountTotal = $showNotices ? (int)$db->query("SELECT COUNT(*) $noticeBaseWhere")->fetchColumn() : 0;
$totalCount = $recordCountTotal + $noticeCountTotal;
$totalPages = max(1, (int)ceil($totalCount / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

// Fetch records with pagination
// Notices always come first, then records — paginate combined via offset logic
$noticeOffset  = $offset;
$noticeLimit   = $perPage;
$recordOffset  = 0;
$recordLimit   = 0;

if ($showNotices && $showRecords) {
    // Mixed: notices fill first, records fill remainder
    $noticeLimit  = min($noticeLimit, max(0, $noticeCountTotal - $noticeOffset));
    $recordOffset = max(0, $offset - $noticeCountTotal);
    $recordLimit  = $perPage - $noticeLimit;
} elseif ($showNotices && !$showRecords) {
    $noticeLimit  = $perPage;
    $recordLimit  = 0;
} elseif ($showRecords && !$showNotices) {
    $noticeOffset = 0;
    $noticeLimit  = 0;
    $recordOffset = $offset;
    $recordLimit  = $perPage;
}

$recordQuery = "SELECT a.*, u.first_name, u.last_name $recordBaseWhere ORDER BY a.archived_at DESC" . ($recordLimit > 0 ? " LIMIT $recordLimit OFFSET $recordOffset" : " LIMIT 0");
$archives = ($showRecords && $recordLimit > 0) ? $db->query($recordQuery)->fetchAll() : [];

$noticeQuery = "SELECT n.*, u.first_name, u.last_name, nd.dismissed_at $noticeBaseWhere ORDER BY nd.dismissed_at DESC" . ($noticeLimit > 0 ? " LIMIT $noticeLimit OFFSET $noticeOffset" : " LIMIT 0");
$archivedNotices = ($showNotices && $noticeLimit > 0) ? $db->query($noticeQuery)->fetchAll() : [];

$flash     = getFlash('arch');
$pageTitle = 'Archive';

$typeBadge = [
    'residents'     => ['bg'=>'rgba(26,79,122,0.1)',   'color'=>'#1a4f7a',  'icon'=>'bi-people-fill'],
    'officials'     => ['bg'=>'rgba(220,38,38,0.1)',   'color'=>'#dc2626',  'icon'=>'bi-person-badge-fill'],
    'households'    => ['bg'=>'rgba(5,150,105,0.1)',   'color'=>'#065f46',  'icon'=>'bi-house-fill'],
    'blotter_cases' => ['bg'=>'rgba(217,119,6,0.1)',   'color'=>'#92400e',  'icon'=>'bi-shield-fill'],
    'certificates'  => ['bg'=>'rgba(30,64,175,0.1)',   'color'=>'#1a6b30',  'icon'=>'bi-file-earmark-text-fill'],
];
?>


<?php
$pageIcon     = 'bi-archive-fill';
$pageTitle    = 'Archived Records';
$pageSubtitle = 'Inactive or removed records.';
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
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=3.0">
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

    

    <!-- Filters -->
    <div style="background:#fff;border-radius:14px;box-shadow:0 2px 12px rgba(0,0,0,0.06);border:1px solid #e0f0e4;padding:16px 20px;margin-bottom:16px;">
        <form method="GET" class="d-flex gap-2 flex-wrap align-items-center" id="searchForm">
            <div class="input-group input-group-sm ac-wrapper" style="width:260px;">
                <span class="input-group-text bg-white border-end-0" style="border-color:#dee2e6;">
                    <i class="bi bi-search text-muted" style="font-size:0.8rem;"></i>
                </span>
                <input type="text" id="archiveSearchInput" name="search" class="form-control form-control-sm border-start-0 border-end-0 ps-0"
                       style="border-color:#dee2e6;" placeholder="Search records..."
                       autocomplete="off" value="<?= htmlspecialchars($search) ?>">
                <span class="input-group-text bg-white border-start-0 p-0" style="border-color:#dee2e6;">
                    <span id="archiveSearchClear" title="Clear search"
                          style="width:28px;height:28px;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;color:#9ca3af;border-radius:4px;<?= $search ? '' : 'visibility:hidden;' ?>"
                          onmouseover="this.style.color='#dc2626';this.style.background='#fee2e2';"
                          onmouseout="this.style.color='#9ca3af';this.style.background='transparent';">
                        <i class="bi bi-x-lg" style="font-size:0.75rem;"></i>
                    </span>
                </span>
                <div class="ac-list" id="archiveAcList"></div>
            </div>
            <select name="type" class="form-select form-select-sm" style="width:160px;border-color:#e0f0e4;background:#f8fafc;">
                <option value="">All Categories</option>
                <option value="residents"     <?= $typeFilter==='residents'    ?'selected':'' ?>>Residents</option>
                <option value="officials"     <?= $typeFilter==='officials'    ?'selected':'' ?>>Officials</option>
                <option value="households"    <?= $typeFilter==='households'   ?'selected':'' ?>>Households</option>
                <option value="blotter_cases" <?= $typeFilter==='blotter_cases'?'selected':'' ?>>Blotter</option>
                <option value="certificates"  <?= $typeFilter==='certificates' ?'selected':'' ?>>Certificates</option>
                <option value="notices"       <?= $typeFilter==='notices'      ?'selected':'' ?>>Notices</option>
            </select>
            <button class="btn btn-sm btn-primary px-3" style="border-radius:8px;">
                <i class="bi bi-funnel me-1"></i>Filter
            </button>
            <?php if ($search || $typeFilter): ?>
            <a href="?" class="btn btn-sm btn-outline-secondary px-2" style="border-radius:8px;" title="Clear filters">
                <i class="bi bi-x-lg"></i>
            </a>
            <?php endif; ?>
        </form>
    </div>

    <?php
    $totalShown = count($archives) + count($archivedNotices);
    ?>

    <!-- Archive Table -->
    <div style="background:#fff;border-radius:18px;box-shadow:0 2px 16px rgba(0,0,0,0.07);border:1px solid #e0f0e4;overflow:hidden;">
        <?php if ($totalShown === 0): ?>
        <div class="text-center text-muted py-5">
            <i class="bi bi-inbox" style="font-size:2.5rem;opacity:0.3;display:block;margin-bottom:10px;"></i>
            No archived records found.
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table mb-0" style="font-size:0.85rem;">
                <thead>
                    <tr style="border-bottom:1px solid #e8f4fb;">
                        <th class="ps-4 py-3" style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#3a7fa8;background:transparent;border:none;">Record</th>
                        <th class="py-3" style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#3a7fa8;background:transparent;border:none;">Category</th>
                        <th class="py-3" style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#3a7fa8;background:transparent;border:none;">Details</th>
                        <th class="py-3" style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#3a7fa8;background:transparent;border:none;">Archived By</th>
                        <th class="py-3" style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#3a7fa8;background:transparent;border:none;">Archive Date</th>
                        <th class="pe-4 py-3" style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#3a7fa8;background:transparent;border:none;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                // Notices first
                if ($showNotices):
                    foreach ($archivedNotices as $n):
                        $ext     = $n['file_path'] ? strtolower(pathinfo($n['file_path'], PATHINFO_EXTENSION)) : '';
                        $isImage = in_array($ext, ['jpg','jpeg','png','gif','webp']);
                ?>
                    <tr style="border-bottom:1px solid #f8fafc;transition:background 0.15s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                        <td class="ps-4 py-3" style="border:none;">
                            <div class="d-flex align-items-center gap-3">
                                <div style="width:36px;height:36px;background:linear-gradient(135deg,#0369a1,#0ea5e9);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:0.8rem;flex-shrink:0;">
                                    <i class="bi bi-megaphone-fill"></i>
                                </div>
                                <div>
                                    <div style="font-weight:600;color:#1a2e1f;"><?= htmlspecialchars($n['title']) ?></div>
                                    <div style="font-size:0.72rem;color:#3a7fa8;max-width:200px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;"><?= htmlspecialchars(substr($n['content'],0,60)) ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="py-3" style="border:none;">
                            <span style="background:rgba(14,165,233,0.1);color:#0369a1;font-size:0.72rem;font-weight:600;padding:4px 12px;border-radius:20px;">
                                <i class="bi bi-megaphone-fill me-1"></i>LGU Notice
                            </span>
                        </td>
                        <td class="py-3" style="border:none;max-width:200px;">
                            <?php if ($n['file_path']): ?>
                                <span class="badge bg-light text-dark border"><i class="bi bi-<?= $isImage ? 'image' : 'file-earmark-pdf' ?> me-1"></i>Has attachment</span>
                            <?php else: ?>
                                <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="py-3" style="border:none;">
                            <div style="font-size:0.82rem;color:#2a5070;font-weight:500;">
                                <?= htmlspecialchars(trim(($n['first_name']??'').' '.($n['last_name']??''))) ?>
                            </div>
                        </td>
                        <td class="py-3" style="border:none;">
                            <div style="font-size:0.8rem;color:#2a5070;"><?= date('M d, Y', strtotime($n['dismissed_at'])) ?></div>
                            <div style="font-size:0.72rem;color:#94a3b8;"><?= date('h:i A', strtotime($n['dismissed_at'])) ?></div>
                        </td>
                        <td class="pe-4 py-3" style="border:none;">
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-sm"
                                        style="font-size:0.78rem;border-radius:8px;padding:4px 12px;background:#e0f2fb;color:#0d3a5c;border:1px solid #b8e8f0;"
                                        onclick="openRestoreNoticeModal(<?= $n['id'] ?>, '<?= htmlspecialchars(addslashes($n['title'])) ?>')">
                                    <i class="bi bi-arrow-counterclockwise me-1"></i>Restore
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php
                    endforeach;
                endif;

                // Records
                if ($showRecords):
                    foreach ($archives as $a):
                        $data = json_decode($a['data_json'], true) ?? [];
                        $tc   = $typeBadge[$a['table_name']] ?? ['bg'=>'rgba(107,114,128,0.1)', 'color'=>'#374151', 'icon'=>'bi-inbox-fill'];
                ?>
                    <tr style="border-bottom:1px solid #f8fafc;transition:background 0.15s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                        <td class="ps-4 py-3" style="border:none;">
                            <div class="d-flex align-items-center gap-3">
                                <div style="width:36px;height:36px;background:<?= $tc['bg'] ?>;border-radius:50%;display:flex;align-items:center;justify-content:center;color:<?= $tc['color'] ?>;font-size:0.8rem;flex-shrink:0;">
                                    <i class="bi <?= $tc['icon'] ?>"></i>
                                </div>
                                <div>
                                    <?php
                                    $name = '—';
                                    if (isset($data['first_name'])) $name = htmlspecialchars(($data['first_name']??'').' '.($data['last_name']??''));
                                    elseif (isset($data['full_name'])) $name = htmlspecialchars($data['full_name']);
                                    elseif (isset($data['household_code'])) $name = htmlspecialchars($data['household_code']);
                                    elseif (isset($data['case_number'])) $name = htmlspecialchars($data['case_number']);
                                    ?>
                                    <div style="font-weight:600;color:#1a2e1f;"><?= $name ?></div>
                                    <div style="font-size:0.72rem;color:#3a7fa8;">ID #<?= $a['record_id'] ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="py-3" style="border:none;">
                            <span style="background:<?= $tc['bg'] ?>;color:<?= $tc['color'] ?>;font-size:0.72rem;font-weight:600;padding:4px 12px;border-radius:20px;">
                                <?= ucfirst(str_replace('_',' ',$a['table_name'])) ?>
                            </span>
                        </td>
                        <td class="py-3" style="border:none;max-width:200px;">
                            <?php if (isset($data['archive_reason'])): ?>
                                <span class="badge bg-light text-dark border"><?= htmlspecialchars($data['archive_reason']) ?></span>
                            <?php else: ?>
                                <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="py-3" style="border:none;">
                            <div style="font-size:0.82rem;color:#2a5070;font-weight:500;">
                                <?= htmlspecialchars(trim(($a['first_name']??'').' '.($a['last_name']??''))) ?: 'System' ?>
                            </div>
                        </td>
                        <td class="py-3" style="border:none;">
                            <div style="font-size:0.8rem;color:#2a5070;"><?= date('M d, Y', strtotime($a['archived_at'])) ?></div>
                            <div style="font-size:0.72rem;color:#94a3b8;"><?= date('h:i A', strtotime($a['archived_at'])) ?></div>
                        </td>
                        <td class="pe-4 py-3" style="border:none;">
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-sm"
                                        style="font-size:0.78rem;border-radius:8px;padding:4px 12px;background:#e0f2fb;color:#0d3a5c;border:1px solid #b8e8f0;"
                                        onclick="openRestoreModal(<?= $a['id'] ?>, '<?= htmlspecialchars(addslashes($a['table_name'])) ?>', <?= htmlspecialchars(json_encode($data)) ?>)">
                                    <i class="bi bi-arrow-counterclockwise me-1"></i>Restore
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php
                    endforeach;
                endif;
                ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php
$paginationBase = '?' . http_build_query(array_filter(['search'=>$search,'type'=>$typeFilter]));
$paginationBase = ($paginationBase === '?') ? '?' : $paginationBase . '&';
?>
<?php if ($totalPages > 1): ?>
<?php $paginationBase = $paginationBase; $paginationLabel = 'archived record'; include __DIR__ . '/../includes/pagination.php'; ?>
<?php endif; ?>

<!-- Hidden Forms -->
<form method="POST" id="archiveRestoreForm">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="restore">
    <input type="hidden" name="archive_id" id="restoreArchiveId">
</form>
<form method="POST" id="noticeRestoreForm">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="restore_notice">
    <input type="hidden" name="notice_id" id="restoreNoticeId">
</form>

<!-- ── Restore Record Modal ───────────────────────────────── -->
<div class="modal fade" id="restoreArchiveModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;overflow:hidden;">
            <div class="d-flex">
                <div class="d-flex flex-column align-items-center justify-content-center px-4 py-5"
                     style="background:linear-gradient(160deg,#065f46 0%,#059669 100%);min-width:220px;width:220px;align-self:stretch;flex-shrink:0;">
                    <div style="width:80px;height:80px;background:rgba(255,255,255,0.15);border-radius:50%;display:flex;align-items:center;justify-content:center;border:3px solid rgba(255,255,255,0.3);">
                        <i class="bi bi-arrow-counterclockwise text-white" style="font-size:2.2rem;"></i>
                    </div>
                    <h5 class="fw-bold mt-3 mb-1 text-white text-center">Restore Record</h5>
                    <p class="text-white-50 small text-center mb-0">This action is reversible</p>
                </div>
                <div class="flex-fill d-flex flex-column justify-content-between p-4">
                    <div>
                        <p class="text-muted small mb-1">You are about to restore:</p>
                        <p class="fw-bold fs-6 mb-3" id="restoreRecordName" style="color:#065f46;"></p>
                        <div class="alert alert-success py-2 small mb-0">
                            <i class="bi bi-check-circle me-1"></i>
                            This record will be restored to its original state and become active again.
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-4">
                        <button type="button" class="btn btn-outline-secondary flex-fill" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn fw-semibold flex-fill" style="background:#059669;color:#fff;"
                                onclick="submitRestore()">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Confirm Restore
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── Delete Record Modal ────────────────────────────────── -->
<!-- ── Restore Notice Modal ───────────────────────────────── -->
<div class="modal fade" id="restoreNoticeModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;overflow:hidden;">
            <div class="d-flex">
                <div class="d-flex flex-column align-items-center justify-content-center px-4 py-5"
                     style="background:linear-gradient(160deg,#065f46 0%,#059669 100%);min-width:220px;width:220px;align-self:stretch;flex-shrink:0;">
                    <div style="width:80px;height:80px;background:rgba(255,255,255,0.15);border-radius:50%;display:flex;align-items:center;justify-content:center;border:3px solid rgba(255,255,255,0.3);">
                        <i class="bi bi-arrow-counterclockwise text-white" style="font-size:2.2rem;"></i>
                    </div>
                    <h5 class="fw-bold mt-3 mb-1 text-white text-center">Restore Notice</h5>
                    <p class="text-white-50 small text-center mb-0">This action is reversible</p>
                </div>
                <div class="flex-fill d-flex flex-column justify-content-between p-4">
                    <div>
                        <p class="text-muted small mb-1">You are about to restore:</p>
                        <p class="fw-bold fs-6 mb-3" id="restoreNoticeName" style="color:#065f46;"></p>
                        <div class="alert alert-success py-2 small mb-0">
                            <i class="bi bi-check-circle me-1"></i>
                            This notice will reappear in your <strong>LGU Notices</strong> feed if it is still active.
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-4">
                        <button type="button" class="btn btn-outline-secondary flex-fill" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn fw-semibold flex-fill" style="background:#059669;color:#fff;"
                                onclick="submitRestoreNotice()">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Confirm Restore
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

function getRecordLabel(type, data) {
    if (data.first_name) return (data.first_name + ' ' + (data.last_name || '')).trim();
    if (data.full_name)  return data.full_name;
    if (data.household_code) return data.household_code;
    if (data.case_number)    return data.case_number;
    return type.replace('_', ' ') + ' #' + (data.id || '');
}

// ── Record Restore ─────────────────────────────────────────
function openRestoreModal(archiveId, type, data) {
    document.getElementById('restoreArchiveId').value        = archiveId;
    document.getElementById('restoreRecordName').textContent = getRecordLabel(type, data);
    bootstrap.Modal.getOrCreateInstance(document.getElementById('restoreArchiveModal')).show();
}
function submitRestore() {
    bootstrap.Modal.getInstance(document.getElementById('restoreArchiveModal')).hide();
    document.getElementById('archiveRestoreForm').submit();
}

// ── Notice Restore ─────────────────────────────────────────
function openRestoreNoticeModal(noticeId, title) {
    document.getElementById('restoreNoticeId').value         = noticeId;
    document.getElementById('restoreNoticeName').textContent = title;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('restoreNoticeModal')).show();
}
function submitRestoreNotice() {
    bootstrap.Modal.getInstance(document.getElementById('restoreNoticeModal')).hide();
    document.getElementById('noticeRestoreForm').submit();
}
</script>
<style>.btn-xs { padding: 2px 8px; font-size: 0.75rem; }</style>

<script src="<?= BASE_URL ?>/assets/js/autocomplete.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    initAutocomplete('archiveSearchInput', 'archiveAcList', '<?= BASE_URL ?>/ajax/search_archive.php', 'archiveSearchClear');
});
</script>
</body>
</html>

