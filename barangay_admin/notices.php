<?php
require_once __DIR__ . '/../config/config.php';
requireRole('barangay_admin');

$db = Database::getInstance()->getConnection();
$u  = currentUser();

// ── Safe migration ─────────────────────────────────────────
try {
    $db->exec("ALTER TABLE notices ADD COLUMN notice_type VARCHAR(50) NOT NULL DEFAULT 'Announcement' AFTER title");
} catch (PDOException $e) {}

$db->exec("CREATE TABLE IF NOT EXISTS notice_dismissals (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    notice_id    INT UNSIGNED NOT NULL,
    user_id      INT UNSIGNED NOT NULL,
    dismissed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_notice_user (notice_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Fetch ──────────────────────────────────────────────────
$filterType = sanitize($_GET['type']   ?? '');
$search     = sanitize($_GET['search'] ?? '');
$perPage    = 15;
$page       = max(1, (int)($_GET['page'] ?? 1));

$where  = "FROM notices n
           LEFT JOIN users u  ON n.created_by = u.id
           WHERE n.status = 'Active'";
$params = [];
if ($filterType) { $where .= " AND n.notice_type = ?"; $params[] = $filterType; }
if ($search)     { $where .= " AND (n.title LIKE ? OR n.content LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }

$countStmt = $db->prepare("SELECT COUNT(*) $where");
$countStmt->execute($params);
$totalCount = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalCount / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$stmt = $db->prepare("SELECT n.*, u.first_name, u.last_name $where ORDER BY n.created_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$notices = $stmt->fetchAll();

$flash = getFlash('notices');

$pageIcon     = 'bi-megaphone-fill';
$pageTitle    = 'LGU Announcements & Advisories';
$pageSubtitle = 'Official announcements and advisories from the LGU.';

$typeConfig = [
    'Announcement'     => ['icon'=>'📢', 'color'=>'#1a4f7a', 'bg'=>'#dbeafe'],
    'Advisory'         => ['icon'=>'⚠️',  'color'=>'#854d0e', 'bg'=>'#fef3c7'],
    'Emergency'        => ['icon'=>'🚨', 'color'=>'#991b1b', 'bg'=>'#fee2e2'],
    'Health'           => ['icon'=>'🏥', 'color'=>'#065f46', 'bg'=>'#d1fae5'],
    'Community Program'=> ['icon'=>'🏘️', 'color'=>'#4c1d95', 'bg'=>'#ede9fe'],
    'Event'            => ['icon'=>'📅', 'color'=>'#0f4c75', 'bg'=>'#e0f2fe'],
    'Administrative'   => ['icon'=>'📄', 'color'=>'#374151', 'bg'=>'#f3f4f6'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favcon.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=3.0">
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

        /* ── Responsive ────────────────────────────────────── */
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
<?php include __DIR__ . '/../includes/sidebar_barangay.php'; ?>
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<div class="main-content">

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show mb-3">
            <?= $flash['message'] ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Toolbar: filter chips + search -->
    <div class="card mb-3">
        <div class="card-body py-2">
            <form method="GET" class="d-flex flex-wrap gap-2 align-items-center">
                <div class="d-flex flex-wrap gap-1 me-2">
                    <a href="?<?= $search ? 'search='.urlencode($search) : '' ?>"
                       class="badge rounded-pill text-decoration-none <?= !$filterType ? 'bg-primary text-white' : 'bg-light text-dark border' ?>"
                       style="font-size:0.75rem;padding:6px 12px;">All</a>
                    <?php foreach ($typeConfig as $key => $tc): ?>
                        <a href="?type=<?= urlencode($key) ?><?= $search ? '&search='.urlencode($search) : '' ?>"
                           class="badge rounded-pill text-decoration-none <?= $filterType===$key ? 'text-white' : 'bg-light text-dark border' ?>"
                           style="font-size:0.75rem;padding:6px 12px;<?= $filterType===$key ? "background:{$tc['color']}!important;" : '' ?>">
                            <?= $tc['icon'] ?> <?= htmlspecialchars($key) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="type" value="<?= htmlspecialchars($filterType) ?>">
                <input type="text" name="search" class="form-control form-control-sm" style="width:200px;"
                       placeholder="Search notices..." value="<?= htmlspecialchars($search) ?>">
                <?php if ($search || $filterType): ?>
                    <a href="?" class="btn btn-sm btn-outline-secondary" title="Clear"><i class="bi bi-x-lg"></i></a>
                <?php endif; ?>
                <span class="ms-auto text-muted small"><?= $totalCount ?> result<?= $totalCount !== 1 ? 's' : '' ?></span>
            </form>
        </div>
    </div>

    <!-- ── Email-client shell ─────────────────────────────── -->
    <div class="notices-shell">

        <!-- LEFT: list -->
        <div class="notices-list-panel">
            <div class="notices-list-toolbar">
                <span class="small fw-semibold text-muted" style="font-size:0.72rem;text-transform:uppercase;letter-spacing:0.05em;">
                    Announcements
                </span>
                <span class="badge bg-secondary ms-2" style="font-size:0.65rem;"><?= $totalCount ?></span>
            </div>
            <div class="notices-list-scroll" id="noticeListScroll">
            <?php if (empty($notices)): ?>
                <div class="text-center py-5 text-muted small">
                    <i class="bi bi-megaphone" style="font-size:2rem;opacity:0.3;display:block;margin-bottom:8px;"></i>
                    No active LGU notices at the moment.
                </div>
            <?php else: ?>
                <?php foreach ($notices as $idx => $n):
                    $tc         = $typeConfig[$n['notice_type']] ?? $typeConfig['Announcement'];
                    $daysAgo    = (int)floor((time() - strtotime($n['created_at'])) / 86400);
                    $ext        = $n['file_path'] ? strtolower(pathinfo($n['file_path'], PATHINFO_EXTENSION)) : '';
                    $isImage    = in_array($ext, ['jpg','jpeg','png','gif','webp']);
                    $noticeJson = htmlspecialchars(json_encode([
                        'id'       => $n['id'],
                        'title'    => $n['title'],
                        'type'     => $n['notice_type'] ?? 'Announcement',
                        'content'  => $n['content'],
                        'author'   => $n['first_name'] . ' ' . $n['last_name'],
                        'date'     => date('M d, Y h:i A', strtotime($n['created_at'])),
                        'filePath' => $n['file_path'] ?? '',
                        'isImage'  => $isImage,
                        'daysAgo'  => $daysAgo,
                    ], JSON_UNESCAPED_UNICODE), ENT_QUOTES);
                ?>
                <div class="notice-list-item <?= $idx === 0 ? 'active' : '' ?>"
                     data-notice="<?= $noticeJson ?>"
                     onclick="openNotice(this)">
                    <div class="item-icon" style="background:<?= $tc['bg'] ?>;">
                        <?= $tc['icon'] ?>
                    </div>
                    <div class="item-body">
                        <div class="d-flex align-items-center gap-1">
                            <div class="item-title flex-fill"><?= htmlspecialchars($n['title']) ?></div>
                            <?php if ($daysAgo === 0): ?>
                                <span class="badge bg-success" style="font-size:0.55rem;flex-shrink:0;">New</span>
                            <?php elseif ($daysAgo <= 7): ?>
                                <span class="badge bg-info text-dark" style="font-size:0.55rem;flex-shrink:0;"><?= $daysAgo ?>d</span>
                            <?php endif; ?>
                        </div>
                        <div class="item-preview"><?= htmlspecialchars(mb_substr(strip_tags($n['content']), 0, 70)) ?>…</div>
                        <div class="item-meta">
                            <span style="background:<?= $tc['bg'] ?>;color:<?= $tc['color'] ?>;padding:1px 6px;border-radius:20px;font-size:0.62rem;font-weight:600;">
                                <?= htmlspecialchars($n['notice_type'] ?? 'Announcement') ?>
                            </span>
                            &nbsp;·&nbsp;<?= date('M d, Y', strtotime($n['created_at'])) ?>
                            <?php if ($n['file_path']): ?>
                                &nbsp;<i class="bi bi-paperclip" style="font-size:0.65rem;"></i>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
            </div>

            <!-- Pagination inside list panel -->
            <?php if ($totalPages > 1):
                $qBase = '?' . http_build_query(array_filter(['type'=>$filterType,'search'=>$search]));
                $qBase = ($qBase === '?') ? '?' : $qBase . '&';
            ?>
            <div style="padding:8px 12px;border-top:1px solid #e2eaf2;background:#fff;flex-shrink:0;">
                <?php $paginationBase = $qBase; $paginationLabel = 'announcement'; include __DIR__ . '/../includes/pagination.php'; ?>
            </div>
            <?php endif; ?>
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
                             style="width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.4rem;flex-shrink:0;background:#dbeafe;">
                            📢
                        </div>
                        <div class="flex-fill" style="min-width:0;">
                            <h5 class="fw-bold mb-1" id="detailTitle" style="color:#1a2e1f;font-size:1rem;"></h5>
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <span id="detailTypeBadge"></span>
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
                                 style="max-width:100%;max-height:320px;border-radius:10px;border:1px solid #e2e8f0;object-fit:contain;">
                        </div>
                        <div class="d-flex gap-2">
                            <a id="detailFileLink" href="#" target="_blank" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-eye me-1"></i>View File
                            </a>
                            <a id="detailDownloadLink" href="#" download class="btn btn-sm btn-outline-success">
                                <i class="bi bi-download me-1"></i>Download
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Footer actions -->
                <div class="detail-footer">
                    <span class="ms-auto text-muted small" id="detailFooterMeta"></span>
                </div>

            </div>
        </div>
    </div><!-- /notices-shell -->

</div><!-- /main-content -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('show'); }

const typeConfig = <?= json_encode($typeConfig) ?>;
const BASE_URL   = '<?= BASE_URL ?>';

window._activeNoticeId    = null;
window._activeNoticeTitle = '';

// ── Open a notice in the detail panel ─────────────────────
function openNotice(el) {
    document.querySelectorAll('.notice-list-item').forEach(r => r.classList.remove('active'));
    el.classList.add('active');

    const n  = JSON.parse(el.dataset.notice);
    const tc = typeConfig[n.type] || typeConfig['Announcement'];

    window._activeNoticeId    = n.id;
    window._activeNoticeTitle = n.title;

    // Header
    document.getElementById('detailTypeIcon').textContent      = tc.icon;
    document.getElementById('detailTypeIcon').style.background = tc.bg;
    document.getElementById('detailTitle').textContent         = n.title;
    document.getElementById('detailAuthor').textContent        = n.author;
    document.getElementById('detailDate').textContent          = n.date;
    document.getElementById('detailTypeBadge').innerHTML =
        `<span class="badge rounded-pill" style="font-size:0.7rem;padding:3px 10px;background:${tc.bg};color:${tc.color};">${tc.icon} ${n.type}</span>`;

    // Content
    document.getElementById('detailContent').textContent = n.content;

    // Footer meta
    document.getElementById('detailFooterMeta').textContent = 'Posted: ' + n.date;

    // Attachment
    const attachWrap   = document.getElementById('detailAttach');
    const imgWrap      = document.getElementById('detailImgWrap');
    const img          = document.getElementById('detailImg');
    const fileLink     = document.getElementById('detailFileLink');
    const downloadLink = document.getElementById('detailDownloadLink');

    if (n.filePath) {
        const url      = BASE_URL + '/uploads/' + n.filePath;
        const filename = n.filePath.split('/').pop();
        attachWrap.classList.remove('d-none');
        fileLink.href      = url;
        downloadLink.href  = url;
        downloadLink.setAttribute('download', filename);
        if (n.isImage) {
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

// Open first item automatically on load
document.addEventListener('DOMContentLoaded', function () {
    const first = document.querySelector('.notice-list-item');
    if (first) openNotice(first);
});

</script>
<style>.btn-xs { padding:2px 8px; font-size:0.75rem; }</style>
</body>
</html>
