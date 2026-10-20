<?php
require_once __DIR__ . '/../config/config.php';
requireRole('super_admin');

$db = Database::getInstance()->getConnection();
$u  = currentUser();

// ── Safe migration ─────────────────────────────────────────────────────────
try {
    $db->exec("ALTER TABLE notifications ADD COLUMN IF NOT EXISTS category VARCHAR(50) NOT NULL DEFAULT 'system' AFTER type");
} catch (Throwable $e) {}

// ── Auto-delete older than 3 months ───────────────────────────────────────
$db->prepare("DELETE FROM notifications WHERE user_id = ? AND created_at < NOW() - INTERVAL 3 MONTH")
   ->execute([$u['id']]);

// ── Mark all as read ───────────────────────────────────────────────────────
$db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0")
   ->execute([$u['id']]);

// ── Get user email ─────────────────────────────────────────────────────────
$userEmail = $u['email'] ?? '';
if (!$userEmail) {
    $userEmail = $db->query("SELECT email FROM users WHERE id = " . (int)$u['id'])->fetchColumn() ?: '';
}

// ── Pagination ─────────────────────────────────────────────────────────────
$perPage = 10;
$page    = max(1, (int)($_GET['page'] ?? 1));

$countStmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ?");
$countStmt->execute([$u['id']]);
$totalCount = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalCount / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT " . (int)$perPage . " OFFSET " . (int)$offset);
$stmt->execute([$u['id']]);
$notifList = $stmt->fetchAll();

// ── Icon per category/type ─────────────────────────────────────────────────
function notifIcon(string $cat, string $type): string {
    return match($cat) {
        'announcement' => 'bi-megaphone-fill text-primary',
        'certificate'  => 'bi-file-earmark-text-fill text-success',
        'resident'     => 'bi-person-fill text-info',
        'transaction'  => 'bi-arrow-left-right text-warning',
        default        => match($type) {
            'success' => 'bi-check-circle-fill text-success',
            'warning' => 'bi-exclamation-triangle-fill text-warning',
            'danger'  => 'bi-x-circle-fill text-danger',
            default   => 'bi-bell-fill text-secondary',
        },
    };
}

$pageIcon     = 'bi-bell-fill';
$pageTitle    = 'Notifications';
$pageSubtitle = '';
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
        .notif-row {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 10px 14px;
            border-bottom: 1px solid #f0f0f0;
            background: #fff;
        }
        .notif-row:last-child { border-bottom: none; }
        .notif-row:hover { background: #fafbff; }
        .notif-row.unread { border-left: 3px solid #3b82f6; background: #f8faff; }
        .notif-row.unread:hover { background: #f0f5ff; }
        .notif-icon {
            font-size: 1rem;
            margin-top: 2px;
            flex-shrink: 0;
        }
        .notif-msg {
            font-size: 0.8rem;
            line-height: 1.45;
            color: #1e293b;
            margin-bottom: 2px;
        }
        .notif-meta {
            font-size: 0.68rem;
            color: #94a3b8;
        }
        .notif-meta .sep { margin: 0 4px; }
        .empty-state { padding: 50px 20px; text-align: center; color: #9ca3af; }
        .empty-state i { font-size: 2rem; margin-bottom: 8px; display: block; }
        .pagination .page-link {
            border-radius: 6px !important;
            margin: 0 1px; min-width: 30px;
            text-align: center; font-size: 0.78rem; padding: 3px 8px;
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/sidebar_super.php'; ?>
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<div class="main-content">

    <!-- Header -->
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
        <div>
            <h6 class="fw-bold mb-0">Notifications</h6>
        </div>
        <span class="badge bg-secondary px-2 py-1" style="font-size:0.72rem;"><?= number_format($totalCount) ?> total</span>
    </div>

    <!-- List -->
    <div class="card border-0 shadow-sm" style="border-radius:10px;overflow:hidden;">
    <?php if (empty($notifList)): ?>
        <div class="empty-state">
            <i class="bi bi-bell-slash text-muted"></i>
            <p class="fw-semibold mb-1 small">No notifications yet</p>
            <small class="text-muted">You have no notifications in the last 3 months.</small>
        </div>
    <?php else: ?>
        <?php foreach ($notifList as $n):
            $cat     = $n['category'] ?? 'system';
            $type    = $n['type'] ?? 'info';
            $isRead  = (bool)$n['is_read'];
            $safeMsg = strip_tags($n['message'], '<strong><b><em><i><br>');
            $ts      = strtotime($n['created_at']);
        ?>
        <div class="notif-row <?= $isRead ? '' : 'unread' ?>">
            <i class="bi <?= notifIcon($cat, $type) ?> notif-icon"></i>
            <div>
                <div class="notif-msg"><?= $safeMsg ?></div>
                <div class="notif-meta">
                    <i class="bi bi-envelope me-1"></i><?= htmlspecialchars($userEmail) ?>
                    <span class="sep">·</span>
                    <i class="bi bi-clock me-1"></i><?= date('M d, Y h:i A', $ts) ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
    </div>

    <!-- Pagination -->
    <?php $paginationBase = '?'; $paginationLabel = 'notification'; include __DIR__ . '/../includes/pagination.php'; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
