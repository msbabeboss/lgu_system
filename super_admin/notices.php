<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/mailer.php';
requireRole('super_admin');

$db = Database::getInstance()->getConnection();
$u  = currentUser();

// ── Safe migration ─────────────────────────────────────────
try {
    $db->exec("ALTER TABLE notices ADD COLUMN notice_type VARCHAR(50) NOT NULL DEFAULT 'Announcement' AFTER title");
} catch (PDOException $e) {}
try {
    $db->exec("ALTER TABLE notices ADD COLUMN status ENUM('Active','Expired','Archived') NOT NULL DEFAULT 'Active'");
} catch (PDOException $e) {}
try {
    $db->exec("ALTER TABLE notices ADD COLUMN target_barangay_id INT NULL DEFAULT NULL");
} catch (PDOException $e) {}

// ── POST handlers ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrAbort();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $title        = sanitize($_POST['title']            ?? '');
        $notice_type  = sanitize($_POST['notice_type']      ?? 'Advisory');
        $content      = sanitize($_POST['content']          ?? '');
        $targetBrgyId = (int)($_POST['target_barangay_id'] ?? 0);
        $sendMode     = $_POST['send_mode'] ?? 'broadcast';
        $filePath     = null;

        $allowedTypes = ['Announcement','Advisory','Emergency','Health','Community Program','Event','Administrative'];
        if (!in_array($notice_type, $allowedTypes)) $notice_type = 'Advisory';

        if (!empty($_FILES['file']['name'])) {
            $ext     = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
            $allowed = ['pdf','jpg','jpeg','png'];
            if (in_array($ext, $allowed)) {
                $dir = UPLOAD_PATH . 'notices/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $fname = 'notice_' . time() . '.' . $ext;
                move_uploaded_file($_FILES['file']['tmp_name'], $dir . $fname);
                $filePath = 'notices/' . $fname;
            }
        }

        if ($sendMode === 'specific' && $targetBrgyId <= 0) {
            flash('notices', 'Please select a barangay to send to.', 'danger');
            redirect(BASE_URL . '/super_admin/notices.php');
        }

        $storedBrgyId = ($sendMode === 'specific') ? $targetBrgyId : null;

        // ── Save to DB ──────────────────────────────────────────
        $db->prepare("INSERT INTO notices (title, notice_type, content, file_path, created_by, target_barangay_id) VALUES (?,?,?,?,?,?)")
           ->execute([$title, $notice_type, $content, $filePath, $u['id'], $storedBrgyId]);
        $newNoticeId = (int)$db->lastInsertId();

        $brgyLabel = ($sendMode === 'specific' && $targetBrgyId > 0)
            ? ($db->prepare("SELECT barangay_name FROM barangays WHERE id=?") && ($bq = $db->prepare("SELECT barangay_name FROM barangays WHERE id=?")) && $bq->execute([$targetBrgyId]) ? ($bq->fetchColumn() ?: "Barangay #$targetBrgyId") : "Barangay #$targetBrgyId")
            : 'All Barangays';

        // Re-fetch brgyLabel cleanly
        if ($sendMode === 'specific' && $targetBrgyId > 0) {
            $bq = $db->prepare("SELECT barangay_name FROM barangays WHERE id=?");
            $bq->execute([$targetBrgyId]);
            $brgyLabel = $bq->fetchColumn() ?: "Barangay #$targetBrgyId";
        } else {
            $brgyLabel = 'All Barangays';
        }

        logActivity($u['id'], 'ADD_NOTICE', 'Notices', "Created [{$notice_type}]: {$title} → {$brgyLabel}");

        // ── Store pending send info in session so page can trigger background send ──
        $_SESSION['notice_pending_send'] = [
            'notice_id' => $newNoticeId,
            'label'     => $brgyLabel,
        ];

        // ── Redirect immediately — no waiting for emails ────────
        redirect(BASE_URL . '/super_admin/notices.php?new=' . $newNoticeId);
    }

    if ($action === 'delete') {
        $id  = (int)$_POST['notice_id'];
        $row = $db->prepare("SELECT * FROM notices WHERE id=?");
        $row->execute([$id]);
        $data = $row->fetch();
        if ($data) {
            $db->prepare("INSERT INTO archives (table_name,record_id,data_json,archived_by) VALUES ('notices',?,?,?)")
               ->execute([$id, json_encode($data), $u['id']]);
            $db->prepare("UPDATE notices SET status='Archived' WHERE id=?")->execute([$id]);
            logActivity($u['id'], 'ARCHIVE_NOTICE', 'Notices', "Archived: {$data['title']}");
            flash('notices', 'Announcement archived.', 'info');
        }
        redirect(BASE_URL . '/super_admin/notices.php');
    }
}

// ── Fetch ALL active notices for client-side filtering ─────
// (no server-side type/search filtering — done in JS to avoid page reloads)
$stmt = $db->prepare("SELECT n.*, u.first_name, u.last_name FROM notices n LEFT JOIN users u ON n.created_by=u.id WHERE n.status != 'Archived' ORDER BY n.created_at DESC");
$stmt->execute();
$notices    = $stmt->fetchAll();
$totalCount = count($notices);

$flash = getFlash('notices');

// ── Check for pending background send (set after redirect) ─
$pendingSend = null;
if (!empty($_SESSION['notice_pending_send'])) {
    $pendingSend = $_SESSION['notice_pending_send'];
    unset($_SESSION['notice_pending_send']); // consume it
}
$newNoticeId = (int)($_GET['new'] ?? 0);

// ── Barangay list for typeahead ────────────────────────────
$allBarangays = $db->query("SELECT id, barangay_name FROM barangays WHERE status='active' ORDER BY barangay_name")->fetchAll(PDO::FETCH_ASSOC);

$pageIcon     = 'bi-megaphone-fill';
$pageTitle    = 'Announcements';
$pageSubtitle = '';

$typeConfig = [
    'Announcement'     => ['icon'=>'bi-megaphone',        'color'=>'#374151', 'bg'=>'#f3f4f6', 'label'=>'Announcement'],
    'Advisory'         => ['icon'=>'bi-exclamation-circle','color'=>'#374151', 'bg'=>'#f3f4f6', 'label'=>'Advisory'],
    'Emergency'        => ['icon'=>'bi-shield-exclamation','color'=>'#374151', 'bg'=>'#f3f4f6', 'label'=>'Emergency'],
    'Health'           => ['icon'=>'bi-heart-pulse',       'color'=>'#374151', 'bg'=>'#f3f4f6', 'label'=>'Health'],
    'Community Program'=> ['icon'=>'bi-people',            'color'=>'#374151', 'bg'=>'#f3f4f6', 'label'=>'Community Program'],
    'Event'            => ['icon'=>'bi-calendar-event',    'color'=>'#374151', 'bg'=>'#f3f4f6', 'label'=>'Event'],
    'Administrative'   => ['icon'=>'bi-file-text',         'color'=>'#374151', 'bg'=>'#f3f4f6', 'label'=>'Administrative'],
];

$subCategories = [
    'Announcement'     => ['Public Advisory','Water Interruption','Power Interruption','Road Closure','Traffic Rerouting','Weather-Related Announcement'],
    'Advisory'         => ['Water Interruption','Power Interruption','Road Closure','Traffic Rerouting','Weather-Related Advisory','General Cleanliness Advisory'],
    'Emergency'        => ['Typhoon Warning','Evacuation Notice','Flood Warning','Emergency Preparedness','Relief Distribution'],
    'Health'           => ['Dengue Prevention','Vaccination Schedule','Medical Mission','Health Campaign','Sanitation Inspection'],
    'Community Program'=> ['Distribution of Ayuda/Assistance','Livelihood Program','Feeding Program','Scholarship Announcement','Senior Citizen/PWD Program','Clean-Up Drive'],
    'Event'            => ['Municipal/City Event','Sports Activity','Clean-Up Drive','Tree Planting','Community Celebration','Meeting & Coordination','Training/Seminar'],
    'Administrative'   => ['Deadline for Submission','Request for Documents','Compliance Reminder','Memorandum','Official Directive','Barangay Officials Meeting','LGU Consultation','Submission of Reports'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favcon.png">
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrfMeta() ?>">
    <title><?= $pageTitle ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=2.2">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <style>
        /* Select2 styling for barangay dropdown inside modal */
        #brgyTypeaheadWrap .select2-container { width: 100% !important; }
        #brgyTypeaheadWrap .select2-selection--single {
            height: 38px !important;
            border: 1px solid #d0dce8 !important;
            border-radius: 7px !important;
            display: flex; align-items: center; padding: 0 10px;
        }
        #brgyTypeaheadWrap .select2-selection__rendered {
            line-height: 36px !important; color: #212529; padding: 0 !important; font-size: 0.9rem;
        }
        #brgyTypeaheadWrap .select2-selection__arrow { height: 100% !important; right: 8px; }
        #brgyTypeaheadWrap .select2-container--default.select2-container--focus .select2-selection--single,
        #brgyTypeaheadWrap .select2-container--default.select2-container--open .select2-selection--single {
            border-color: #3a7fa8 !important;
            box-shadow: 0 0 0 3px rgba(58,127,168,0.12) !important;
        }
        .select2-dropdown { border-color: #3a7fa8; border-radius: 7px; font-size: 0.875rem; }
        .select2-container--default .select2-search--dropdown .select2-search__field {
            border-radius: 5px; border-color: #d0dce8; font-size: 0.875rem;
        }
        .select2-results__option--highlighted { background-color: #1a4f7a !important; }
    </style>
    <style>
        /* ── Email-client split layout ─────────────────────── */
        .notices-shell {
            display: flex;
            gap: 0;
            height: calc(100vh - var(--topbar-height) - 32px);
            min-height: 500px;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 2px 20px rgba(0,0,0,0.08);
            overflow: hidden;
            border: 1px solid #e2eaf2;
        }

        /* ── LEFT: list panel ──────────────────────────────── */
        .notices-list-panel {
            width: 360px;
            min-width: 300px;
            max-width: 400px;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            border-right: 1px solid #e2eaf2;
            background: #f8fafc;
        }
        .notices-list-toolbar {
            padding: 10px 12px;
            border-bottom: 1px solid #e2eaf2;
            background: #fff;
            flex-shrink: 0;
        }
        .notices-list-scroll {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
        }
        .notice-list-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 11px 14px;
            border-bottom: 1px solid #eef2f7;
            cursor: pointer;
            transition: background 0.12s;
            background: #fff;
            position: relative;
        }
        .notice-list-item:hover { background: #f0f6ff; }
        .notice-list-item.active {
            background: #e8f0fe;
            border-left: 3px solid #1a4f7a;
        }
        .notice-list-item .item-icon {
            width: 36px; height: 36px; flex-shrink: 0;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem;
        }
        .notice-list-item .item-body { flex: 1; min-width: 0; }
        .notice-list-item .item-title {
            font-size: 0.82rem; font-weight: 600;
            color: #1a2e1f; white-space: nowrap;
            overflow: hidden; text-overflow: ellipsis;
        }
        .notice-list-item .item-preview {
            font-size: 0.72rem; color: #64748b;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            margin-top: 1px;
        }
        .notice-list-item .item-meta {
            font-size: 0.65rem; color: #94a3b8; margin-top: 3px;
        }

        /* ── RIGHT: detail panel ───────────────────────────── */
        .notices-detail-panel {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            background: #fff;
        }
        .notices-detail-empty {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #94a3b8;
            gap: 10px;
        }
        .notices-detail-view {
            display: none;
            flex-direction: column;
            flex: 1;
            overflow: hidden;
        }
        .notices-detail-view.visible { display: flex; }

        .detail-header {
            padding: 18px 24px 14px;
            border-bottom: 1px solid #e2eaf2;
            flex-shrink: 0;
        }
        .detail-body {
            flex: 1;
            overflow-y: auto;
            padding: 20px 24px;
        }
        .detail-content-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 18px 20px;
            white-space: pre-wrap;
            line-height: 1.8;
            font-size: 0.9rem;
            color: #334155;
        }
        .detail-footer {
            padding: 12px 24px;
            border-top: 1px solid #e2eaf2;
            flex-shrink: 0;
            background: #f8fafc;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* ── Scrollbar styling ─────────────────────────────── */
        .notices-list-scroll::-webkit-scrollbar,
        .detail-body::-webkit-scrollbar { width: 4px; }
        .notices-list-scroll::-webkit-scrollbar-thumb,
        .detail-body::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

        /* ── Responsive: stack on narrow screens ───────────── */
        @media (max-width: 768px) {
            .notices-shell { flex-direction: column; height: auto; }
            .notices-list-panel { width: 100%; max-width: 100%; border-right: none; border-bottom: 1px solid #e2eaf2; max-height: 320px; }
            .notices-detail-panel { min-height: 300px; }
        }

        html.dark-mode .notices-shell        { background:#0f1f35; border-color:#1e3a5f; }
        html.dark-mode .notices-list-panel   { background:#0a1929; border-color:#1e3a5f; }
        html.dark-mode .notices-list-toolbar { background:#0f1f35; border-color:#1e3a5f; }
        html.dark-mode .notice-list-item     { background:#0f1f35; border-color:#1a3050; color:#c1e8ff; }
        html.dark-mode .notice-list-item:hover    { background:#162844; }
        html.dark-mode .notice-list-item.active   { background:#1a3a6e; border-left-color:#7da0ca; }
        html.dark-mode .notice-list-item .item-title   { color:#c1e8ff; }
        html.dark-mode .notices-detail-panel { background:#0f1f35; }
        html.dark-mode .detail-header        { border-color:#1e3a5f; }
        html.dark-mode .detail-body          { background:#0f1f35; }
        html.dark-mode .detail-content-box   { background:#0a1929; border-color:#1e3a5f; color:#a8c8e8; }
        html.dark-mode .detail-footer        { background:#0a1929; border-color:#1e3a5f; }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/sidebar_super.php'; ?>
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<div class="main-content">

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show mb-3">
            <?= $flash['message'] ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Toolbar: filter chips + search + create -->
    <div class="card mb-3">
        <div class="card-body py-2">
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <!-- Search -->
                <input type="text" id="noticeSearch" class="form-control form-control-sm" style="width:220px;"
                       placeholder="Search by title..." oninput="applyNoticeFilter()">
                <button type="button" id="noticeClearBtn" class="btn btn-sm btn-outline-secondary d-none" title="Clear"
                        onclick="clearNoticeFilter()"><i class="bi bi-x-lg"></i></button>
                <span class="text-muted small" id="noticeCountLabel"><?= $totalCount ?> result<?= $totalCount != 1 ? 's' : '' ?></span>

                <!-- Divider -->
                <div style="width:1px;height:20px;background:#e5e7eb;flex-shrink:0;"></div>

                <!-- Category chips -->
                <div class="d-flex flex-wrap gap-1 align-items-center" id="noticeTypeChips">
                    <button type="button"
                            class="badge rounded-pill border-0 bg-primary text-white"
                            style="font-size:0.75rem;padding:5px 12px;cursor:pointer;"
                            data-type=""
                            onclick="setNoticeType(this)">All</button>
                    <?php foreach ($typeConfig as $key => $tc): ?>
                    <?php if ($key === 'Announcement') continue; ?>
                    <button type="button"
                            class="badge rounded-pill border bg-light text-dark"
                            style="font-size:0.75rem;padding:5px 12px;cursor:pointer;"
                            data-type="<?= htmlspecialchars($key) ?>"
                            onclick="setNoticeType(this)">
                        <i class="bi <?= htmlspecialchars($tc['icon']) ?> me-1"></i><?= htmlspecialchars($key) ?>
                    </button>
                    <?php endforeach; ?>
                </div>

                <!-- Create button -->
                <button type="button" class="btn btn-sm btn-success fw-semibold ms-auto"
                        data-bs-toggle="modal" data-bs-target="#addNoticeModal">
                    <i class="bi bi-megaphone-fill me-1"></i>Create
                </button>
            </div>
        </div>
    </div>

    <!-- ── Email-client shell ─────────────────────────────── -->
    <div class="notices-shell">

        <!-- LEFT: list -->
        <div class="notices-list-panel">
            <div class="notices-list-toolbar">
                <span class="badge bg-secondary" style="font-size:0.65rem;" id="listPanelCount"><?= $totalCount ?> total</span>
            </div>
            <div class="notices-list-scroll" id="noticeListScroll">
            <?php if (empty($notices)): ?>
                <div class="text-center py-5 text-muted small">
                    <i class="bi bi-megaphone" style="font-size:2rem;opacity:0.3;display:block;margin-bottom:8px;"></i>
                    No announcements found.
                </div>
            <?php else: ?>
                <!-- JS filter empty state (hidden by default) -->
                <div id="noticeListEmpty" class="text-center py-5 text-muted small" style="display:none;">
                    <i class="bi bi-search" style="font-size:2rem;opacity:0.3;display:block;margin-bottom:8px;"></i>
                    No results match your filter.
                </div>
                <?php foreach ($notices as $idx => $n):
                    $tc         = $typeConfig[$n['notice_type']] ?? $typeConfig['Announcement'];
                    $noticeJson = htmlspecialchars(json_encode([
                        'title'    => $n['title'],
                        'type'     => $n['notice_type'] ?? 'Announcement',
                        'content'  => $n['content'],
                        'author'   => $n['first_name'] . ' ' . $n['last_name'],
                        'date'     => date('M d, Y h:i A', strtotime($n['created_at'])),
                        'filePath' => $n['file_path'] ?? '',
                        'id'       => $n['id'],
                        'status'   => $n['status'],
                    ], JSON_UNESCAPED_UNICODE), ENT_QUOTES);
                    $ext = $n['file_path'] ? strtolower(pathinfo($n['file_path'], PATHINFO_EXTENSION)) : '';
                ?>
                <div class="notice-list-item <?= $idx === 0 ? 'active' : '' ?>"
                     data-notice="<?= $noticeJson ?>"
                     data-type="<?= htmlspecialchars($n['notice_type'] ?? 'Announcement') ?>"
                     data-title="<?= htmlspecialchars(strtolower($n['title'])) ?>"
                     onclick="openNotice(this)">
                    <div class="item-icon" style="background:#f3f4f6;">
                        <i class="bi <?= htmlspecialchars($tc['icon']) ?>" style="font-size:1rem;color:#374151;"></i>
                    </div>
                    <div class="item-body">
                        <div class="item-title"><?= htmlspecialchars($n['title']) ?></div>
                        <div class="item-preview"><?= htmlspecialchars(mb_substr(strip_tags($n['content']), 0, 70)) ?>…</div>
                        <div class="item-meta">
                            <span style="background:#f3f4f6;color:#374151;border:1px solid #e5e7eb;padding:1px 6px;border-radius:20px;font-size:0.62rem;font-weight:600;">
                                <?= htmlspecialchars($n['notice_type'] ?? 'Announcement') ?>
                            </span>
                            &nbsp;·&nbsp;
                            <?= date('M d, Y', strtotime($n['created_at'])) ?>
                            <?php if ($n['file_path']): ?>
                                &nbsp;<i class="bi bi-paperclip" style="font-size:0.65rem;"></i>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
            </div>

        </div>

        <!-- RIGHT: detail -->
        <div class="notices-detail-panel">

            <!-- Empty state -->
            <div class="notices-detail-empty" id="detailEmpty" <?= !empty($notices) ? 'style="display:none;"' : '' ?>>
                <i class="bi bi-envelope-open" style="font-size:3rem;opacity:0.2;"></i>
                <span class="small">Select an announcement to read it</span>
            </div>

            <!-- Detail view -->
            <div class="notices-detail-view <?= !empty($notices) ? 'visible' : '' ?>" id="detailView">

                <!-- Header -->
                <div class="detail-header">
                    <div class="d-flex align-items-start gap-3">
                        <div id="detailTypeIcon"
                             style="width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0;background:#f3f4f6;">
                            <i class="bi bi-megaphone" style="color:#374151;"></i>
                        </div>
                        <div class="flex-fill" style="min-width:0;">
                            <h5 class="fw-bold mb-1" id="detailTitle" style="color:#1a2e1f;font-size:1rem;"></h5>
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <span id="detailTypeBadge"></span>
                                <span id="detailStatusBadge"></span>
                                <span class="text-muted small">
                                    <i class="bi bi-person me-1"></i><span id="detailAuthor"></span>
                                </span>
                                <span class="text-muted small">
                                    <i class="bi bi-clock me-1"></i><span id="detailDate"></span>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Body -->
                <div class="detail-body">
                    <div class="detail-content-box" id="detailContent"></div>

                    <!-- Attachment -->
                    <div id="detailAttach" class="mt-4 d-none">
                        <p class="small fw-semibold text-muted mb-2">
                            <i class="bi bi-paperclip me-1"></i>Attachment
                        </p>
                        <div id="detailImgWrap" class="d-none mb-3">
                            <img id="detailImg" src="" alt="Attachment"
                                 style="max-width:100%;max-height:300px;border-radius:10px;border:1px solid #e2e8f0;object-fit:contain;">
                        </div>
                        <div class="d-flex gap-2">
                            <a id="detailFileLink" href="#" target="_blank" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-eye me-1"></i>View File
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Footer actions -->
                <div class="detail-footer">
                    <button class="btn btn-sm btn-outline-warning"
                            id="detailArchiveBtn"
                            onclick="openArchiveModal(window._activeNoticeId, window._activeNoticeTitle)">
                        <i class="bi bi-archive me-1"></i>Archive
                    </button>
                    <span class="ms-auto text-muted small" id="detailFooterMeta"></span>
                </div>

            </div>
        </div>
    </div><!-- /notices-shell -->

</div><!-- /main-content -->

<!-- ═══════════════ CREATE MODAL ═══════════════ -->
<div class="modal fade" id="addNoticeModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add">
            <?= csrfField() ?>
            <div class="modal-content" style="border-radius:16px;overflow:hidden;">
                <div class="d-flex">
                    <div class="d-flex flex-column align-items-center justify-content-center px-4 py-5"
                         style="background:linear-gradient(160deg,#1a4f7a 0%,#3a7fa8 100%);min-width:220px;width:220px;align-self:stretch;flex-shrink:0;">
                        <div id="modalTypeIcon"
                             style="width:80px;height:80px;background:rgba(255,255,255,0.15);border-radius:50%;display:flex;align-items:center;justify-content:center;border:3px solid rgba(255,255,255,0.3);font-size:2rem;">
                            <i class="bi bi-exclamation-circle text-white"></i>
                        </div>
                        <h5 class="fw-bold mt-3 mb-1 text-white text-center">Create</h5>
                        <p id="modalTypeLabel" class="text-white-50 small text-center mb-0">Announcement</p>
                        <hr style="border-color:rgba(255,255,255,0.2);width:80%;margin:16px auto 12px;">
                        <p class="text-white-50 text-center mb-0" style="font-size:0.72rem;line-height:1.6;">
                            <i class="bi bi-broadcast me-1"></i>Sent instantly to all active barangay admins.
                        </p>
                    </div>
                    <div class="flex-fill d-flex flex-column p-4" style="min-width:0;">
                        <div style="overflow-y:auto;max-height:72vh;">
                            <div class="mb-2">
                                <label class="form-label fw-semibold small text-muted mb-1" style="text-transform:uppercase;letter-spacing:0.05em;font-size:0.7rem;">Notice Type <span class="text-danger">*</span></label>
                                <div class="d-flex flex-wrap gap-1">
                                    <?php $firstType = true; foreach ($typeConfig as $key => $tc): ?>
                                    <?php if ($key === 'Announcement') continue; ?>
                                    <input type="radio" class="btn-check" name="notice_type"
                                           id="type_<?= str_replace(' ','_',$key) ?>"
                                           value="<?= htmlspecialchars($key) ?>"
                                           <?= $firstType ? 'checked' : '' ?>
                                           onchange="onTypeChange('<?= htmlspecialchars($key) ?>', '<?= $tc['icon'] ?>')">
                                    <label class="btn btn-sm btn-outline-secondary py-1 px-2" for="type_<?= str_replace(' ','_',$key) ?>" style="font-size:0.75rem;">
                                        <i class="bi <?= htmlspecialchars($tc['icon']) ?> me-1"></i><?= htmlspecialchars($key) ?>
                                    </label>
                                    <?php $firstType = false; endforeach; ?>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold small text-muted" style="text-transform:uppercase;letter-spacing:0.05em;">Sub-Category / Template <span class="text-muted fw-normal">(optional)</span></label>
                                <select class="form-select form-select-sm" id="subCatSelect" onchange="applySubCat()">
                                    <option value="">— Choose a template —</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold small text-muted" style="text-transform:uppercase;letter-spacing:0.05em;">Title <span class="text-danger">*</span></label>
                                <input type="text" name="title" id="noticeTitle" class="form-control"
                                       placeholder="e.g. Water Interruption — Brgy. Sta. Ana, Sept 10" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold small text-muted" style="text-transform:uppercase;letter-spacing:0.05em;">Content <span class="text-danger">*</span></label>
                                <textarea name="content" id="noticeContent" class="form-control" rows="5"
                                          placeholder="Write the full announcement here..." required></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold small text-muted" style="text-transform:uppercase;letter-spacing:0.05em;">Attachment <span class="text-muted fw-normal">(PDF / Image — optional)</span></label>
                                <input type="file" name="file" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                                <div class="form-text">Accepted: PDF, JPG, PNG</div>
                            </div>
                            <!-- Send Mode / Action Row -->
                            <div class="mb-3">
                                <label class="form-label fw-semibold small text-muted" style="text-transform:uppercase;letter-spacing:0.05em;">Send To</label>
                                <div class="d-flex gap-2">
                                    <input type="hidden" name="send_mode" id="sendModeInput" value="broadcast">
                                    <input type="hidden" name="target_barangay_id" id="targetBrgyIdInput" value="">
                                    <button type="button" class="btn btn-outline-secondary btn-sm fw-semibold"
                                            data-bs-dismiss="modal" style="white-space:nowrap;">
                                        <i class="bi bi-x me-1"></i>Cancel
                                    </button>
                                    <button type="button" id="btnBroadcast"
                                            class="btn btn-primary btn-sm fw-semibold flex-fill"
                                            onclick="confirmBroadcast()">
                                        <i class="bi bi-broadcast me-1"></i>Broadcast to All
                                    </button>
                                    <button type="button" id="btnSpecific"
                                            class="btn btn-outline-success btn-sm fw-semibold flex-fill"
                                            onclick="setSendMode('specific')">
                                        <i class="bi bi-geo-alt-fill me-1"></i>Send to Specific Barangay
                                    </button>
                                </div>
                            </div>

                            <!-- Barangay Typeahead (shown only when specific is selected) -->
                            <div id="brgyTypeaheadWrap" style="display:none;" class="mb-3">
                                <label class="form-label fw-semibold small text-muted" style="text-transform:uppercase;letter-spacing:0.05em;">Select Barangay <span class="text-danger">*</span></label>
                                <select id="brgySelect" style="width:100%;">
                                    <option value="">— Choose a barangay —</option>
                                    <?php foreach ($allBarangays as $b): ?>
                                    <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['barangay_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="alert py-2 small mb-0" id="sendModeAlert" style="background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe;">
                                <i class="bi bi-broadcast me-1"></i>All active barangay admins will receive this notice as an in-system notification.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════ ARCHIVE MODAL ═══════════════ -->
<form method="POST" id="archiveNoticeForm" style="display:none;">
    <input type="hidden" name="action" value="delete">
    <?= csrfField() ?>
    <input type="hidden" name="notice_id" id="archiveNoticeId">
</form>
<div class="modal fade" id="archiveNoticeModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;overflow:hidden;">
            <div class="d-flex">
                <div class="d-flex flex-column align-items-center justify-content-center px-4 py-5"
                     style="background:linear-gradient(160deg,#92400e,#b45309);min-width:180px;width:180px;align-self:stretch;flex-shrink:0;">
                    <i class="bi bi-archive-fill text-white" style="font-size:2.2rem;"></i>
                    <h6 class="fw-bold mt-3 mb-0 text-white text-center">Archive</h6>
                </div>
                <div class="flex-fill d-flex flex-column justify-content-between p-4">
                    <div>
                        <p class="mb-1 small text-muted">You are about to archive:</p>
                        <p class="fw-bold mb-3" id="archiveNoticeName"></p>
                        <div class="alert alert-warning small py-2 mb-0">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            This notice will be hidden from barangay admins. It can be restored from the Archive page.
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-3">
                        <button type="button" class="btn btn-outline-secondary flex-fill" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-warning flex-fill fw-semibold text-dark"
                                onclick="bootstrap.Modal.getInstance(document.getElementById('archiveNoticeModal')).hide(); document.getElementById('archiveNoticeForm').submit()">
                            <i class="bi bi-check-lg me-1"></i>Confirm Archive
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('show'); }

const typeConfig  = <?= json_encode($typeConfig) ?>;

// ── Client-side filtering state ────────────────────────────
let _activeType   = '';   // '' = All
let _activeSearch = '';

function setNoticeType(btn) {
    _activeType = btn.dataset.type || '';
    // Update chip styles
    document.querySelectorAll('#noticeTypeChips button').forEach(b => {
        b.className = 'badge rounded-pill border bg-light text-dark';
        b.style.background = '';
        b.style.color      = '';
    });
    if (_activeType === '') {
        btn.className = 'badge rounded-pill border-0 bg-primary text-white';
    } else {
        btn.className = 'badge rounded-pill border-0 text-white';
        btn.style.background = btn.dataset.color || '#1a4f7a';
    }
    applyNoticeFilter();
}

function applyNoticeFilter() {
    _activeSearch = (document.getElementById('noticeSearch').value || '').trim().toLowerCase();
    // Toggle clear button
    const clearBtn = document.getElementById('noticeClearBtn');
    clearBtn.classList.toggle('d-none', !_activeSearch && !_activeType);

    const items = document.querySelectorAll('.notice-list-item');
    let visible = 0;
    let firstVisible = null;
    items.forEach(item => {
        const matchType   = !_activeType || item.dataset.type === _activeType;
        const matchSearch = !_activeSearch || item.dataset.title.includes(_activeSearch);
        const show = matchType && matchSearch;
        item.style.display = show ? '' : 'none';
        if (show) { visible++; if (!firstVisible) firstVisible = item; }
    });

    // Update counter
    document.getElementById('noticeCountLabel').textContent = visible + ' result' + (visible !== 1 ? 's' : '');

    // Show/hide empty state
    const emptyState = document.getElementById('noticeListEmpty');
    if (emptyState) emptyState.style.display = visible === 0 ? '' : 'none';

    // Auto-open first visible item
    if (firstVisible && !firstVisible.classList.contains('active')) {
        openNotice(firstVisible);
    } else if (visible === 0) {
        // Nothing matches — show empty detail
        document.querySelectorAll('.notice-list-item').forEach(r => r.classList.remove('active'));
        document.getElementById('detailEmpty').style.display = '';
        document.getElementById('detailView').classList.remove('visible');
    }
}

function clearNoticeFilter() {
    document.getElementById('noticeSearch').value = '';
    _activeSearch = '';
    // Reset to "All" chip
    const allChip = document.querySelector('#noticeTypeChips button[data-type=""]');
    if (allChip) setNoticeType(allChip);
    else applyNoticeFilter();
}
const subCats      = <?= json_encode($subCategories) ?>;
const BASE_URL     = '<?= BASE_URL ?>';
const allBarangays = <?= json_encode(array_values($allBarangays)) ?>;
const NEW_NOTICE_ID   = <?= $newNoticeId ?>;
const PENDING_SEND    = <?= json_encode($pendingSend) ?>;

window._activeNoticeId    = null;
window._activeNoticeTitle = '';

// ── Open a notice in the detail panel ─────────────────────
function openNotice(el) {
    // Highlight in list
    document.querySelectorAll('.notice-list-item').forEach(r => r.classList.remove('active'));
    el.classList.add('active');

    const n  = JSON.parse(el.dataset.notice);
    const tc = typeConfig[n.type] || typeConfig['Announcement'];

    window._activeNoticeId    = n.id;
    window._activeNoticeTitle = n.title;

    // Header
    document.getElementById('detailTypeIcon').innerHTML   = `<i class="bi ${tc.icon}" style="color:#374151;font-size:1.3rem;"></i>`;
    document.getElementById('detailTypeIcon').style.background = '#f3f4f6';
    document.getElementById('detailTitle').textContent       = n.title;
    document.getElementById('detailAuthor').textContent      = n.author;
    document.getElementById('detailDate').textContent        = n.date;
    document.getElementById('detailTypeBadge').innerHTML =
        `<span class="badge rounded-pill" style="font-size:0.7rem;padding:3px 10px;background:#f3f4f6;color:#374151;border:1px solid #e5e7eb;"><i class="bi ${tc.icon} me-1"></i>${n.type}</span>`;

    // Status badge
    const statusColors = {Active:['#e0f2fb','#0d3a5c'], Expired:['#fef9c3','#854d0e'], Archived:['#f1f5f9','#64748b']};
    const sc = statusColors[n.status] || statusColors['Active'];
    document.getElementById('detailStatusBadge').innerHTML =
        `<span style="background:${sc[0]};color:${sc[1]};font-size:0.68rem;font-weight:600;padding:2px 9px;border-radius:20px;">${n.status || 'Active'}</span>`;

    // Content
    document.getElementById('detailContent').textContent = n.content;

    // Footer meta
    document.getElementById('detailFooterMeta').textContent = n.date;

    // Attachment
    const attachWrap = document.getElementById('detailAttach');
    const imgWrap    = document.getElementById('detailImgWrap');
    const img        = document.getElementById('detailImg');
    const fileLink   = document.getElementById('detailFileLink');

    if (n.filePath) {
        const url = BASE_URL + '/uploads/' + n.filePath;
        const ext = n.filePath.split('.').pop().toLowerCase();
        attachWrap.classList.remove('d-none');
        fileLink.href = url;
        if (['jpg','jpeg','png','gif','webp'].includes(ext)) {
            imgWrap.classList.remove('d-none');
            img.src = url;
        } else {
            imgWrap.classList.add('d-none');
        }
    } else {
        attachWrap.classList.add('d-none');
    }

    // Show detail panel
    document.getElementById('detailEmpty').style.display = 'none';
    document.getElementById('detailView').classList.add('visible');
}

// Open first item automatically on load + trigger background send if new notice
document.addEventListener('DOMContentLoaded', function () {
    // If redirected after creating a new notice — open it and trigger background send
    if (NEW_NOTICE_ID && PENDING_SEND) {
        // Find and open the new notice item
        const newItem = document.querySelector(`.notice-list-item[data-notice*='"id":${NEW_NOTICE_ID}']`);
        if (newItem) {
            openNotice(newItem);
            // Flash highlight
            newItem.style.transition = 'background 0s';
            newItem.style.background = '#fef9c3';
            setTimeout(() => { newItem.style.transition = 'background 1.2s ease'; newItem.style.background = ''; }, 800);
            newItem.scrollIntoView({ block: 'nearest' });
        } else {
            const first = document.querySelector('.notice-list-item');
            if (first) openNotice(first);
        }

        // Show "Sending..." persistent toast
        const sendingToast = showSendingToast(PENDING_SEND.label);

        // Trigger background notifications + emails
        const fd = new FormData();
        fd.append('notice_id', NEW_NOTICE_ID);
        fetch(BASE_URL + '/ajax/send_notice_notifications.php', {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''
            },
            body: fd
        })
        .then(r => r.json())
        .then(data => {
            sendingToast.remove();
            const msg = data.doneMsg || ('✅ Sent to <strong>' + PENDING_SEND.label + '</strong> — ' + (data.sent || 0) + ' admin(s) notified.');
            showToast(msg, 'success');
        })
        .catch(() => {
            sendingToast.remove();
            showToast('✅ Notifications sent (email may be delayed).', 'success');
        });

    } else {
        const first = document.querySelector('.notice-list-item');
        if (first) openNotice(first);
    }
});

// ── Archive modal ──────────────────────────────────────────
function openArchiveModal(id, title) {
    document.getElementById('archiveNoticeId').value         = id;
    document.getElementById('archiveNoticeName').textContent = title;
    new bootstrap.Modal(document.getElementById('archiveNoticeModal')).show();
}

// ── Create modal helpers ───────────────────────────────────
const typeIcons = <?= json_encode(array_map(fn($t) => $t['icon'], $typeConfig)) ?>;

function onTypeChange(type, icon) {
    document.getElementById('modalTypeIcon').innerHTML = `<i class="bi ${icon} text-white" style="font-size:2rem;"></i>`;
    document.getElementById('modalTypeLabel').textContent = type;
    const sel = document.getElementById('subCatSelect');
    sel.innerHTML = '<option value="">— Choose a template —</option>';
    (subCats[type] || []).forEach(cat => {
        const opt = document.createElement('option');
        opt.value = opt.textContent = cat;
        sel.appendChild(opt);
    });
}
function applySubCat() {
    const val = document.getElementById('subCatSelect').value;
    if (val) document.getElementById('noticeTitle').value = val;
}
document.getElementById('addNoticeModal').addEventListener('show.bs.modal', function () {
    // Default to Advisory on open
    onTypeChange('Advisory', '⚠️');
    // Check the Advisory radio button
    const advRadio = document.getElementById('type_Advisory');
    if (advRadio) advRadio.checked = true;
    setSendMode('broadcast');
    clearBrgySelection();
    if ($('#brgySelect').length) $('#brgySelect').val('').trigger('change.select2');
    const btnS = document.getElementById('btnSpecific');
    btnS.type      = 'button';
    btnS.className = 'btn btn-outline-success btn-sm fw-semibold flex-fill';
    btnS.innerHTML = '<i class="bi bi-geo-alt-fill me-1"></i>Send to Specific Barangay';
    btnS.onclick   = function () { setSendMode('specific'); };
});

// ── Barangay Select2 init ──────────────────────────────────
$(document).ready(function () {
    $('#brgySelect').select2({
        placeholder: '— Choose a barangay —',
        allowClear: true,
        width: '100%',
        dropdownParent: $('#addNoticeModal')
    });

    $('#brgySelect').on('change', function () {
        const id   = $(this).val();
        const name = $(this).find('option:selected').text();
        if (id) {
            document.getElementById('targetBrgyIdInput').value = id;
            // Update Specific button to submit-ready
            const btnS = document.getElementById('btnSpecific');
            btnS.type      = 'button';
            btnS.className = 'btn btn-success btn-sm fw-semibold flex-fill';
            btnS.innerHTML = '<i class="bi bi-send-fill me-1"></i>Send to ' + name;
            btnS.onclick   = function () { submitSpecificAjax(); };
        } else {
            clearBrgySelection();
        }
    });
});

function submitSpecificAjax() {
    const id   = document.getElementById('targetBrgyIdInput').value;
    const name = $('#brgySelect').find('option:selected').text();
    if (!id) { alert('Please select a barangay first.'); return; }

    const title   = document.getElementById('noticeTitle').value.trim();
    const content = document.getElementById('noticeContent').value.trim();
    if (!title || !content) {
        document.querySelector('#addNoticeModal form').reportValidity();
        return;
    }

    // Show barangay name in confirm modal
    document.getElementById('specificConfirmBrgyName').textContent = name;

    // Hide create modal, show specific confirm modal
    bootstrap.Modal.getInstance(document.getElementById('addNoticeModal')).hide();
    setTimeout(() => { new bootstrap.Modal(document.getElementById('specificConfirmModal')).show(); }, 300);
}

function proceedSpecific() {
    bootstrap.Modal.getInstance(document.getElementById('specificConfirmModal')).hide();
    document.getElementById('sendModeInput').value = 'specific';
    document.querySelector('#addNoticeModal form').submit();
}

// ── Barangay typeahead helpers (kept for clearBrgySelection compatibility) ──
function filterBrgyTypeahead() {}   // no-op — handled by Select2

function selectBrgyFromTypeahead(id, name) {
    document.getElementById('targetBrgyIdInput').value = id;
    $('#brgySelect').val(id).trigger('change');
}

function clearBrgySelection() {
    document.getElementById('targetBrgyIdInput').value = '';
    if ($('#brgySelect').length) {
        $('#brgySelect').val('').trigger('change.select2');
    }
    // Reset Specific button back to non-submit
    const btnS = document.getElementById('btnSpecific');
    if (btnS) {
        btnS.type      = 'button';
        btnS.className = 'btn btn-outline-success btn-sm fw-semibold flex-fill';
        btnS.innerHTML = '<i class="bi bi-geo-alt-fill me-1"></i>Send to Specific Barangay';
        btnS.onclick   = function () { setSendMode('specific'); };
    }
}

// ── Send mode toggle ───────────────────────────────────────
function setSendMode(mode) {
    document.getElementById('sendModeInput').value = mode;
    const btnB  = document.getElementById('btnBroadcast');
    const btnS  = document.getElementById('btnSpecific');
    const wrap  = document.getElementById('brgyTypeaheadWrap');
    const alert = document.getElementById('sendModeAlert');

    if (mode === 'broadcast') {
        btnB.className = 'btn btn-primary btn-sm fw-semibold flex-fill';
        btnS.className = 'btn btn-outline-success btn-sm fw-semibold flex-fill';
        wrap.style.display = 'none';
        alert.innerHTML  = '<i class="bi bi-broadcast me-1"></i>All active barangay admins will receive this notice as an in-system notification.';
        alert.style.cssText = 'background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe;';
        clearBrgySelection();
    } else {
        btnS.className = 'btn btn-success btn-sm fw-semibold flex-fill';
        btnB.className = 'btn btn-outline-secondary btn-sm fw-semibold flex-fill';
        wrap.style.display = 'block';
        alert.innerHTML  = '<i class="bi bi-geo-alt-fill me-1"></i>Only the selected barangay\'s admin(s) will receive this notice.';
        alert.style.cssText = 'background:#dcfce7;color:#14532d;border:1px solid #bbf7d0;';
        setTimeout(() => { if ($('#brgySelect').length) $('#brgySelect').select2('open'); }, 100);
    }
}

// ── Broadcast confirmation ─────────────────────────────────
function confirmBroadcast() {
    const title   = document.getElementById('noticeTitle').value.trim();
    const content = document.getElementById('noticeContent').value.trim();
    if (!title || !content) {
        document.querySelector('#addNoticeModal form').reportValidity();
        return;
    }
    setSendMode('broadcast');
    bootstrap.Modal.getInstance(document.getElementById('addNoticeModal')).hide();
    new bootstrap.Modal(document.getElementById('broadcastConfirmModal')).show();
}

function proceedBroadcast() {
    bootstrap.Modal.getInstance(document.getElementById('broadcastConfirmModal')).hide();
    document.getElementById('sendModeInput').value = 'broadcast';
    document.querySelector('#addNoticeModal form').submit();
}

// ── Prepend a new notice to the list panel ─────────────────
function prependNotice(n) {
    const tc = typeConfig[n.notice_type] || typeConfig['Announcement'];

    const noticeJson = JSON.stringify({
        title:    n.title,
        type:     n.notice_type,
        content:  n.content,
        author:   n.author,
        date:     n.date,
        filePath: n.file_path || '',
        id:       n.id,
        status:   'Active',
    });

    const preview = n.content.replace(/<[^>]*>/g, '').substring(0, 70);

    const div = document.createElement('div');
    div.className = 'notice-list-item';
    div.dataset.notice = noticeJson;
    div.dataset.type   = n.notice_type;
    div.dataset.title  = n.title.toLowerCase();
    div.onclick = function () { openNotice(this); };
    div.innerHTML = `
        <div class="item-icon" style="background:#f3f4f6;"><i class="bi ${tc.icon}" style="font-size:1rem;color:#374151;"></i></div>
        <div class="item-body">
            <div class="item-title">${escHtml(n.title)}</div>
            <div class="item-preview">${escHtml(preview)}…</div>
            <div class="item-meta">
                <span style="background:#f3f4f6;color:#374151;border:1px solid #e5e7eb;padding:1px 6px;border-radius:20px;font-size:0.62rem;font-weight:600;">
                    ${escHtml(n.notice_type)}
                </span>
                &nbsp;·&nbsp;${escHtml(n.date.substring(0,12))}
            </div>
        </div>`;

    // Remove empty state if present
    const emptyState = document.getElementById('noticeListEmpty');
    if (emptyState) emptyState.style.display = 'none';

    // Prepend to scroll container
    const scroll = document.getElementById('noticeListScroll');
    scroll.insertBefore(div, scroll.firstChild);

    // Update count badge
    const allItems = scroll.querySelectorAll('.notice-list-item').length;
    const countLabel = document.getElementById('noticeCountLabel');
    const listCount  = document.getElementById('listPanelCount');
    if (countLabel) countLabel.textContent = allItems + ' result' + (allItems !== 1 ? 's' : '');
    if (listCount)  listCount.textContent  = allItems + ' total';

    // Auto-open the new notice + flash highlight
    openNotice(div);

    // Brief yellow highlight to draw attention to the new item
    div.style.transition = 'background 0s';
    div.style.background = '#fef9c3';
    setTimeout(() => {
        div.style.transition = 'background 1.2s ease';
        div.style.background = '';
    }, 800);
}

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Persistent "Sending..." toast ─────────────────────────
function showSendingToast(label) {
    const toast = document.createElement('div');
    toast.id = 'sendingToast';
    toast.style.cssText = `
        position:fixed;bottom:24px;right:24px;z-index:99999;
        background:#1a4f7a;color:#fff;
        padding:14px 20px;border-radius:10px;font-size:0.85rem;
        box-shadow:0 4px 20px rgba(0,0,0,0.18);
        display:flex;align-items:center;gap:10px;max-width:380px;
        animation:slideInToast .25s ease;
    `;
    toast.innerHTML = `
        <span class="spinner-border spinner-border-sm" style="flex-shrink:0;"></span>
        <span>Sending notifications to <strong>${label}</strong>…</span>
    `;
    document.body.appendChild(toast);
    return toast;
}

// ── Toast notification ─────────────────────────────────────
function showToast(message, type) {
    const colors = { success: '#1a4f7a', danger: '#dc2626', warning: '#d97706' };
    const toast  = document.createElement('div');
    toast.style.cssText = `
        position:fixed;bottom:24px;right:24px;z-index:99999;
        background:${colors[type] || colors.success};color:#fff;
        padding:14px 20px;border-radius:10px;font-size:0.85rem;
        box-shadow:0 4px 20px rgba(0,0,0,0.18);
        display:flex;align-items:center;gap:10px;
        animation:slideInToast .25s ease;max-width:380px;
    `;
    toast.innerHTML = `<i class="bi bi-check-circle-fill" style="font-size:1.1rem;flex-shrink:0;"></i><span>${message}</span>`;
    document.body.appendChild(toast);
    setTimeout(() => { toast.style.opacity = '0'; toast.style.transition = 'opacity .4s'; setTimeout(() => toast.remove(), 400); }, 4000);
}

</script>
<style>.btn-xs { padding:2px 8px; font-size:0.75rem; }
.brgy-ta-item:hover { background:#f0f6ff; }
@keyframes slideInToast {
    from { opacity:0; transform:translateY(16px); }
    to   { opacity:1; transform:translateY(0); }
}</style>

<!-- ═══════════════ BROADCAST CONFIRM MODAL ═══════════════ -->
<div class="modal fade" id="broadcastConfirmModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered" style="max-width:420px;">
        <div class="modal-content" style="border-radius:16px;overflow:hidden;border:none;box-shadow:0 8px 40px rgba(0,0,0,0.18);">
            <!-- Top accent bar -->
            <div style="height:5px;background:linear-gradient(90deg,#1a4f7a,#3a7fa8);"></div>
            <div class="modal-body p-4">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div style="width:52px;height:52px;background:#dbeafe;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1.5rem;">
                        📢
                    </div>
                    <div>
                        <div style="font-size:1rem;font-weight:700;color:#1a2e1f;">Broadcast to All?</div>
                        <div style="font-size:0.8rem;color:#64748b;margin-top:2px;">This will notify <strong>all active barangay admins</strong>.</div>
                    </div>
                </div>

                <div style="background:#f0f6ff;border:1px solid #bfdbfe;border-radius:10px;padding:12px 14px;font-size:0.82rem;color:#1e40af;">
                    <i class="bi bi-info-circle-fill me-2"></i>
                    Are you sure you want to broadcast this announcement? All barangay admins will receive an in-system notification and email.
                </div>
            </div>
            <div class="modal-footer border-0 pt-0 px-4 pb-4 gap-2">
                <button type="button" class="btn btn-outline-secondary flex-fill fw-semibold"
                        onclick="bootstrap.Modal.getInstance(document.getElementById('broadcastConfirmModal')).hide();
                                 setTimeout(()=>{ new bootstrap.Modal(document.getElementById('addNoticeModal')).show(); }, 300);">
                    <i class="bi bi-x-lg me-1"></i>Cancel
                </button>
                <button type="button" class="btn btn-primary flex-fill fw-semibold"
                        id="broadcastConfirmBtn"
                        onclick="proceedBroadcast()">
                    <i class="bi bi-broadcast me-1"></i>Yes, Broadcast
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════ SPECIFIC BARANGAY CONFIRM MODAL ═══════════════ -->
<div class="modal fade" id="specificConfirmModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered" style="max-width:420px;">
        <div class="modal-content" style="border-radius:16px;overflow:hidden;border:none;box-shadow:0 8px 40px rgba(0,0,0,0.18);">
            <div style="height:5px;background:linear-gradient(90deg,#16a34a,#22c55e);"></div>
            <div class="modal-body p-4">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div style="width:52px;height:52px;background:#dcfce7;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1.5rem;">
                        <i class="bi bi-geo-alt-fill" style="color:#16a34a;font-size:1.4rem;"></i>
                    </div>
                    <div>
                        <div style="font-size:1rem;font-weight:700;color:#1a2e1f;">Send to Specific Barangay?</div>
                        <div style="font-size:0.8rem;color:#64748b;margin-top:2px;">Selected: <strong id="specificConfirmBrgyName" style="color:#16a34a;"></strong></div>
                    </div>
                </div>
                <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:12px 14px;font-size:0.82rem;color:#14532d;">
                    <i class="bi bi-info-circle-fill me-2"></i>
                    Only the barangay admin(s) of the selected barangay will receive this notice.
                </div>
            </div>
            <div class="modal-footer border-0 pt-0 px-4 pb-4 gap-2">
                <button type="button" class="btn btn-outline-secondary flex-fill fw-semibold"
                        onclick="bootstrap.Modal.getInstance(document.getElementById('specificConfirmModal')).hide();
                                 setTimeout(()=>{ new bootstrap.Modal(document.getElementById('addNoticeModal')).show(); }, 300);">
                    <i class="bi bi-x-lg me-1"></i>Cancel
                </button>
                <button type="button" class="btn btn-success flex-fill fw-semibold"
                        id="specificConfirmBtn"
                        onclick="proceedSpecific()">
                    <i class="bi bi-send-fill me-1"></i>Yes, Send
                </button>
            </div>
        </div>
    </div>
</div>

</body>
</html>
