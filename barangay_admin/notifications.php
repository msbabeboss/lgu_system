<?php
require_once __DIR__ . '/../config/config.php';
requireRole('barangay_admin');

$db = Database::getInstance()->getConnection();
$u  = currentUser();

// ── Safe migration ─────────────────────────────────────────────────────────
try {
    $db->exec("ALTER TABLE notifications ADD COLUMN IF NOT EXISTS category VARCHAR(50) NOT NULL DEFAULT 'system' AFTER type");
} catch (Throwable $e) {}

// ── Shared helper: build WHERE clauses from filter params ─────────────────
function buildNotifWhere(array $base, string $cat, string $read): array {
    $wc = $base['clauses'];
    $wp = $base['params'];

    if ($cat === 'request') {
        $wc[] = "category = 'transaction'";
        $wc[] = "message LIKE '%request%'";
    } elseif ($cat === 'blotter') {
        $wc[] = "category = 'transaction'";
        $wc[] = "message LIKE '%blotter%'";
    } elseif ($cat !== 'all') {
        $wc[] = 'category = ?';
        $wp[] = $cat;
    }

    if ($read === 'unread')   { $wc[] = 'is_read = 0'; }
    elseif ($read === 'read') { $wc[] = 'is_read = 1'; }

    return ['sql' => implode(' AND ', $wc), 'params' => $wp];
}

// ── AJAX partial request ───────────────────────────────────────────────────
if (!empty($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=utf-8');

    $allowedCats  = ['all','resident','certificate','transaction','request','blotter','announcement','system'];
    $allowedReads = ['all','unread','read'];
    $filterCat  = in_array($_GET['cat']  ?? 'all', $allowedCats)  ? $_GET['cat']  : 'all';
    $filterRead = in_array($_GET['read'] ?? 'all', $allowedReads) ? $_GET['read'] : 'all';
    $page       = max(1, (int)($_GET['page'] ?? 1));
    $perPage    = 10;

    // Mark as read
    if ($filterRead !== 'unread') {
        $mBase = buildNotifWhere(['clauses'=>['user_id = ?','is_read = 0'],'params'=>[$u['id']]], $filterCat, 'all');
        $db->prepare("UPDATE notifications SET is_read = 1 WHERE {$mBase['sql']}")
           ->execute($mBase['params']);
    }

    // Build SELECT WHERE
    $w = buildNotifWhere(['clauses'=>['user_id = ?'],'params'=>[$u['id']]], $filterCat, $filterRead);

    $cntStmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE {$w['sql']}");
    $cntStmt->execute($w['params']);
    $total = (int)$cntStmt->fetchColumn();

    $totalPages = max(1, (int)ceil($total / $perPage));
    $page       = min($page, $totalPages);
    $offset     = ($page - 1) * $perPage;

    $stmt = $db->prepare("SELECT * FROM notifications WHERE {$w['sql']} ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
    $stmt->execute($w['params']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $userEmail = $u['email'] ?? '';

    $items = array_map(function($n) use ($userEmail) {
        $cat  = $n['category'] ?? 'system';
        $type = $n['type']     ?? 'info';
        $iconMap = [
            'announcement' => 'bi-megaphone-fill text-primary',
            'certificate'  => 'bi-file-earmark-text-fill text-success',
            'resident'     => 'bi-person-fill text-info',
            'transaction'  => 'bi-arrow-left-right text-warning',
        ];
        $typeMap = [
            'success' => 'bi-check-circle-fill text-success',
            'warning' => 'bi-exclamation-triangle-fill text-warning',
            'danger'  => 'bi-x-circle-fill text-danger',
        ];
        return [
            'icon'   => $iconMap[$cat] ?? ($typeMap[$type] ?? 'bi-bell-fill text-secondary'),
            'msg'    => strip_tags($n['message'], '<strong><b><em><i><br>'),
            'email'  => htmlspecialchars($userEmail),
            'time'   => date('M d, Y h:i A', strtotime($n['created_at'])),
            'unread' => !(bool)$n['is_read'],
        ];
    }, $rows);

    echo json_encode([
        'items'      => $items,
        'total'      => $total,
        'page'       => $page,
        'totalPages' => $totalPages,
    ]);
    exit;
}

// ── Full page load ─────────────────────────────────────────────────────────
// Auto-delete older than 2 months
$db->prepare("DELETE FROM notifications WHERE user_id = ? AND created_at < NOW() - INTERVAL 2 MONTH")
   ->execute([$u['id']]);

$cntStmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ?");
$cntStmt->execute([$u['id']]);
$totalAll = (int)$cntStmt->fetchColumn();

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
            display: flex; align-items: flex-start; gap: 10px;
            padding: 10px 14px; border-bottom: 1px solid #f0f0f0;
            background: #fff; transition: background 0.15s;
        }
        .notif-row:last-child { border-bottom: none; }
        .notif-row:hover      { background: #fafbff; }
        .notif-row.unread     { border-left: 3px solid #3b82f6; background: #f8faff; }
        .notif-row.unread:hover { background: #f0f5ff; }
        .notif-icon { font-size: 1rem; margin-top: 2px; flex-shrink: 0; }
        .notif-msg  { font-size: 0.8rem; line-height: 1.45; color: #1e293b; margin-bottom: 2px; }
        .notif-meta { font-size: 0.68rem; color: #94a3b8; }
        .notif-meta .sep { margin: 0 4px; }

        #notif-list-wrap { margin-top: 14px; transition: opacity 0.18s ease; }
        #notif-list-wrap.loading { opacity: 0.35; pointer-events: none; }

        .empty-state { padding: 50px 20px; text-align: center; color: #9ca3af; }
        .empty-state i { font-size: 2rem; margin-bottom: 8px; display: block; }
        .pagination .page-link {
            border-radius: 6px !important; margin: 0 1px; min-width: 30px;
            text-align: center; font-size: 0.78rem; padding: 3px 8px;
        }

        .notif-filter-row {
            display: flex; flex-wrap: wrap; gap: 8px; align-items: center;
            padding: 12px 16px; background: #fff; border-bottom: 1px solid #e2e8f0;
        }
        .notif-filter-row select {
            font-size: 0.78rem; border-radius: 8px; border: 1px solid #cbd5e1;
            padding: 4px 28px 4px 10px; color: #334155; background: #f8fafc;
            appearance: none; -webkit-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 16 16'%3E%3Cpath fill='%2364748b' d='M7.247 11.14L2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 8px center;
            cursor: pointer; transition: border-color 0.15s;
        }
        .notif-filter-row select:focus { outline: none; border-color: #3b82f6; }
        .notif-filter-row .read-pill {
            border-radius: 20px; font-size: 0.73rem; padding: 4px 14px;
            border: 1px solid #cbd5e1; background: #f8fafc; color: #475569;
            cursor: pointer; transition: background 0.15s, color 0.15s, border-color 0.15s;
            white-space: nowrap;
        }
        .notif-filter-row .read-pill.active {
            background: #1a4f7a; color: #fff; border-color: #1a4f7a;
        }
        .retention-note {
            font-size: 0.7rem; color: #f59e0b;
            background: #fffbeb; border: 1px solid #fde68a;
            border-radius: 6px; padding: 3px 10px;
            display: inline-flex; align-items: center; gap: 4px;
            white-space: nowrap;
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/sidebar_barangay.php'; ?>
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<div class="main-content">

    <div class="card border-0 shadow-sm" style="border-radius:12px;overflow:hidden;">

        <!-- ── Filter bar ────────────────────────────────── -->
        <div class="notif-filter-row">

            <!-- Category dropdown -->
            <select id="cat-select" title="Filter by category">
                <option value="all">📋 All Categories</option>
                <option value="resident">👤 Residents</option>
                <option value="certificate">📜 Certificates</option>
                <option value="request">📨 Requests</option>
                <option value="blotter">🚨 Blotter Cases</option>
                <option value="announcement">📢 Announcements</option>
                <option value="system">⚙️ System</option>
            </select>

            <!-- Read-status pills -->
            <div class="d-flex gap-1" id="read-pills">
                <button type="button" class="read-pill active" data-value="all">All</button>
                <button type="button" class="read-pill"        data-value="unread">🔵 Unread</button>
                <button type="button" class="read-pill"        data-value="read">✓ Read</button>
            </div>

            <div class="ms-auto d-flex align-items-center gap-2 flex-wrap">
                <span class="badge bg-secondary" id="notif-count-badge" style="font-size:0.7rem;">
                    <?= number_format($totalAll) ?> result<?= $totalAll !== 1 ? 's' : '' ?>
                </span>
                <span class="retention-note">
                    <i class="bi bi-clock-history"></i>
                    Auto-deleted after <strong>2 months</strong>
                </span>
            </div>
        </div>

        <!-- ── List area (AJAX) ──────────────────────────── -->
        <div id="notif-list-wrap">
            <div class="empty-state">
                <i class="bi bi-hourglass-split text-muted"></i>
                <p class="fw-semibold mb-1 small">Loading…</p>
            </div>
        </div>
    </div>

    <!-- Pagination -->
    <div id="notif-pagination" class="mt-3 d-flex justify-content-center"></div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    'use strict';

    var state = { cat: 'all', read: 'all', page: 1 };

    var wrap       = document.getElementById('notif-list-wrap');
    var pagWrap    = document.getElementById('notif-pagination');
    var countBadge = document.getElementById('notif-count-badge');
    var catSelect  = document.getElementById('cat-select');
    var readPills  = document.getElementById('read-pills');

    function esc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function load() {
        wrap.classList.add('loading');
        fetch('?ajax=1&cat=' + encodeURIComponent(state.cat)
            + '&read=' + encodeURIComponent(state.read)
            + '&page=' + state.page, { credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(d){
            wrap.classList.remove('loading');
            renderList(d);
            renderPagination(d);
            if (countBadge) countBadge.textContent = d.total.toLocaleString() + ' result' + (d.total !== 1 ? 's' : '');
            history.replaceState(null, '', window.location.pathname
                + '?cat=' + encodeURIComponent(state.cat)
                + '&read=' + encodeURIComponent(state.read)
                + '&page=' + state.page);
        })
        .catch(function(){
            wrap.classList.remove('loading');
            wrap.innerHTML = '<div class="empty-state"><i class="bi bi-exclamation-circle text-danger"></i><p class="fw-semibold mb-1 small">Failed to load.</p></div>';
        });
    }

    function renderList(d) {
        if (!d.items || !d.items.length) {
            var msg = (state.cat !== 'all' || state.read !== 'all') ? 'Try a different filter.' : 'No notifications in the last 2 months.';
            wrap.innerHTML = '<div class="empty-state"><i class="bi bi-bell-slash text-muted"></i>'
                + '<p class="fw-semibold mb-1 small">No notifications found</p>'
                + '<small class="text-muted">' + esc(msg) + '</small></div>';
            return;
        }
        wrap.innerHTML = d.items.map(function(n){
            return '<div class="notif-row' + (n.unread ? ' unread' : '') + '">'
                + '<i class="bi ' + esc(n.icon) + ' notif-icon"></i>'
                + '<div><div class="notif-msg">' + n.msg + '</div>'
                + '<div class="notif-meta"><i class="bi bi-envelope me-1"></i>' + n.email
                + '<span class="sep">·</span><i class="bi bi-clock me-1"></i>' + esc(n.time) + '</div>'
                + '</div></div>';
        }).join('');
    }

    function renderPagination(d) {
        if (d.totalPages <= 1) { pagWrap.innerHTML = ''; return; }
        var html = '<ul class="pagination pagination-sm mb-0">';
        html += '<li class="page-item'+(d.page<=1?' disabled':'')+'"><a class="page-link" href="#" data-p="'+(d.page-1)+'">&laquo;</a></li>';
        for (var p = Math.max(1,d.page-2); p <= Math.min(d.totalPages,d.page+2); p++) {
            html += '<li class="page-item'+(p===d.page?' active':'')+'"><a class="page-link" href="#" data-p="'+p+'">'+p+'</a></li>';
        }
        html += '<li class="page-item'+(d.page>=d.totalPages?' disabled':'')+'"><a class="page-link" href="#" data-p="'+(d.page+1)+'">&raquo;</a></li>';
        html += '</ul>';
        pagWrap.innerHTML = html;
        pagWrap.querySelectorAll('a[data-p]').forEach(function(a){
            a.addEventListener('click', function(e){
                e.preventDefault();
                var p = parseInt(this.dataset.p, 10);
                if (p < 1 || p > d.totalPages) return;
                state.page = p;
                load();
                wrap.closest('.card').scrollIntoView({behavior:'smooth',block:'start'});
            });
        });
    }

    catSelect.addEventListener('change', function(){
        state.cat  = this.value;
        state.page = 1;
        load();
    });

    readPills.addEventListener('click', function(e){
        var btn = e.target.closest('button[data-value]');
        if (!btn) return;
        readPills.querySelectorAll('button').forEach(function(b){ b.classList.remove('active'); });
        btn.classList.add('active');
        state.read = btn.dataset.value;
        state.page = 1;
        load();
    });

    // Init from URL
    var p     = new URLSearchParams(window.location.search);
    var cats  = ['all','resident','certificate','transaction','request','blotter','announcement','system'];
    var reads = ['all','unread','read'];
    state.cat  = cats.includes(p.get('cat'))  ? p.get('cat')  : 'all';
    state.read = reads.includes(p.get('read')) ? p.get('read') : 'all';
    state.page = Math.max(1, parseInt(p.get('page')||'1', 10));

    catSelect.value = state.cat;
    readPills.querySelectorAll('button').forEach(function(b){
        b.classList.toggle('active', b.dataset.value === state.read);
    });

    load();
})();
</script>
</body>
</html>
