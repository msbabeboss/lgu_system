<?php
$u = currentUser();
$notifCount = countUnreadNotifications($u['id']);
$notifications = getAllNotifications($u['id'], 50);

// Pusher JS config — passed to the client safely (public key + cluster only)
$pusherKey     = defined('PUSHER_KEY')     ? PUSHER_KEY     : '';
$pusherCluster = defined('PUSHER_CLUSTER') ? PUSHER_CLUSTER : 'mt1';
$pusherEnabled = $pusherKey !== '';

// Idle timeout settings from system_settings (global — super admin controls, applies to all roles)
$_idleEnabled = '1';
$_idleMinutes = '5';
$_idleUnit    = 'minutes'; // 'minutes' or 'seconds'
try {
    $db = Database::getInstance()->getConnection();
    // Ensure table and defaults exist for both roles
    $db->exec("CREATE TABLE IF NOT EXISTS system_settings (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) NOT NULL UNIQUE,
        setting_val TEXT,
        updated_by INT UNSIGNED NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    $db->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_val) VALUES (?,?)")->execute(['idle_timeout_enabled','1']);
    $db->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_val) VALUES (?,?)")->execute(['idle_timeout_minutes','5']);
    $db->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_val) VALUES (?,?)")->execute(['idle_timeout_unit','minutes']);
    $stmt = $db->query("SELECT setting_key, setting_val FROM system_settings WHERE setting_key IN ('idle_timeout_enabled','idle_timeout_minutes','idle_timeout_unit')");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($row['setting_key'] === 'idle_timeout_enabled') $_idleEnabled = $row['setting_val'];
        if ($row['setting_key'] === 'idle_timeout_minutes') $_idleMinutes = $row['setting_val'];
        if ($row['setting_key'] === 'idle_timeout_unit')    $_idleUnit    = $row['setting_val'];
    }
} catch (Exception $e) {}
?>
<!-- CSRF meta tag — available to all fetch() calls on authenticated pages -->
<meta name="csrf-token" content="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES) ?>">
<nav class="topbar">
    <!-- Page title — set $pageIcon, $pageTitle, $pageSubtitle before including topbar -->
    <?php if (!empty($pageTitle)): ?>
    <div class="topbar-page-title">
        <?php if (!empty($pageIcon)): ?>
        <div class="topbar-page-icon">
            <i class="bi <?= htmlspecialchars($pageIcon) ?>"></i>
        </div>
        <?php endif; ?>
        <div class="topbar-page-text">
            <div class="topbar-page-name"><?= htmlspecialchars($pageTitle) ?></div>
            <?php if (!empty($pageSubtitle)): ?>
            <div class="topbar-page-sub"><?= $pageSubtitle ?></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Right side: Bell + User -->
    <div class="d-flex align-items-center gap-2" <?= empty($pageTitle) ? 'style="margin-left:auto;"' : '' ?>>

        <!-- Notification Bell -->
        <div class="dropdown" id="notifDropdown">
            <button class="btn-icon position-relative" data-bs-toggle="dropdown" title="Notifications" id="notifBellBtn">
                <i class="bi bi-bell-fill"></i>
                <span class="notification-badge<?= $notifCount === 0 ? ' d-none' : '' ?>" id="notifBadge">
                    <?= $notifCount > 9 ? '9+' : $notifCount ?>
                </span>
            </button>
            <div class="dropdown-menu dropdown-menu-end shadow" style="width:320px; border-radius:12px;">
                <div class="px-3 py-2 border-bottom d-flex justify-content-between align-items-center">
                    <strong class="small">Notifications</strong>
                    <a href="#" id="markAllReadBtn" class="small text-primary text-decoration-none<?= $notifCount === 0 ? ' d-none' : '' ?>">Mark all read</a>
                </div>
                <div id="notifList" style="max-height:320px;overflow-y:auto;overflow-x:hidden;">
                <?php if (empty($notifications)): ?>
                    <div id="notifEmpty" class="px-3 py-3 text-center text-muted small">No notifications yet</div>
                <?php else: ?>
                    <?php foreach ($notifications as $n):
                        $isRead   = !empty($n['is_read']);
                        $safeMsg  = strip_tags($n['message'], '<strong><em><b><i><br>');
                        $category = $n['category'] ?? 'system';

                        // Category-first icon mapping — clean monochrome professional icons
                        $iconClass = match($category) {
                            'announcement' => 'bi-megaphone text-secondary',
                            'certificate'  => 'bi-file-earmark-text text-secondary',
                            'resident'     => 'bi-person text-secondary',
                            'transaction'  => 'bi-arrow-left-right text-secondary',
                            default        => match($n['type'] ?? 'info') {
                                'success' => 'bi-check-circle text-secondary',
                                'warning' => 'bi-exclamation-circle text-secondary',
                                'danger'  => 'bi-x-circle text-secondary',
                                default   => 'bi-bell text-secondary',
                            },
                        };

                        // Category label pill
                        $catLabel = match($category) {
                            'announcement' => ['text' => 'Announcement', 'bg' => '#dbeafe', 'color' => '#1a4f7a'],
                            'certificate'  => ['text' => 'Certificate',  'bg' => '#dcfce7', 'color' => '#166534'],
                            'resident'     => ['text' => 'Resident',     'bg' => '#e0f2fe', 'color' => '#0369a1'],
                            'transaction'  => ['text' => 'Transaction',  'bg' => '#fef9c3', 'color' => '#854d0e'],
                            default        => ['text' => 'System',       'bg' => '#f1f5f9', 'color' => '#475569'],
                        };

                        $itemClass = $isRead ? 'notif-read' : 'notif-unread';
                    ?>
                        <div class="dropdown-item notif-item py-2 border-bottom <?= $itemClass ?>" style="white-space:normal;">
                            <div class="d-flex gap-2 align-items-start">
                                <i class="bi <?= $iconClass ?> mt-1 flex-shrink-0" style="font-size:0.95rem;"></i>
                                <div class="flex-grow-1">
                                    <div class="small"><?= $safeMsg ?></div>
                                    <div class="d-flex align-items-center gap-2 mt-1 flex-wrap">
                                        <span style="font-size:0.62rem;background:<?= $catLabel['bg'] ?>;color:<?= $catLabel['color'] ?>;padding:1px 7px;border-radius:20px;font-weight:600;letter-spacing:0.03em;"><?= $catLabel['text'] ?></span>
                                        <div class="text-muted" style="font-size:0.68rem;"><?= date('M d, Y h:i A', strtotime($n['created_at'])) ?></div>
                                        <span class="notif-read-badge" style="display:<?= $isRead ? 'inline-block' : 'none' ?>;font-size:0.62rem;background:#e8f5e9;color:#2e7d32;padding:1px 6px;border-radius:20px;font-weight:600;letter-spacing:0.02em;"><i class="bi bi-check2-all me-1"></i>Read</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- User Menu -->
        <div class="dropdown">
            <button class="btn btn-sm d-flex align-items-center gap-2 border rounded-pill px-3 py-1" data-bs-toggle="dropdown">
                <?php
                $tbPhoto = $_SESSION['user_photo'] ?? null;
                if (!$tbPhoto) {
                    try {
                        $tbStmt = $db->prepare("SELECT profile_photo FROM users WHERE id=?");
                        $tbStmt->execute([$u['id']]);
                        $tbPhoto = $tbStmt->fetchColumn() ?: null;
                        if ($tbPhoto) $_SESSION['user_photo'] = $tbPhoto;
                    } catch (Exception $e) {}
                }
                ?>
                <?php if ($tbPhoto): ?>
                    <img src="<?= htmlspecialchars(UPLOAD_URL . 'profile_photos/' . $tbPhoto) ?>"
                         alt="Profile"
                         style="width:28px;height:28px;border-radius:50%;object-fit:cover;flex-shrink:0;">
                <?php else: ?>
                    <i class="bi bi-person-circle fs-5"></i>
                <?php endif; ?>
                <span class="d-none d-md-inline small fw-semibold"><?= htmlspecialchars($u['name']) ?></span>
                <i class="bi bi-chevron-down small"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow" style="border-radius:10px;">
                <li><h6 class="dropdown-header"><?= htmlspecialchars($u['name']) ?></h6></li>
                <?php if (($u['role'] ?? '') === 'super_admin'): ?>
                <li><a class="dropdown-item" href="<?= BASE_URL ?>/super_admin/settings.php?tab=profile"><i class="bi bi-person-circle me-2"></i>My Profile</a></li>
                <?php endif; ?>
                <li>
                    <button class="dropdown-item d-flex align-items-center justify-content-between" onclick="toggleDarkMode(event)">
                        <span><i class="bi bi-moon-stars me-2"></i>Dark Mode</span>
                        <div class="dark-mode-switch">
                            <div class="dark-mode-thumb"></div>
                        </div>
                    </button>
                </li>
                <li>
                    <div class="dropdown-item d-flex align-items-center justify-content-between">
                        <span><i class="bi bi-fonts me-2"></i>Font Size</span>
                        <div class="d-flex align-items-center gap-1">
                            <button class="font-size-btn" onclick="changeFontSize(-1, event)" title="Decrease">−</button>
                            <span id="fontSizeLabel" class="font-size-label">100%</span>
                            <button class="font-size-btn" onclick="changeFontSize(1, event)" title="Increase">+</button>
                        </div>
                    </div>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="<?= BASE_URL ?>/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
            </ul>
        </div>

    </div><!-- end right side -->
</nav>

<!-- ── Realtime Notification Toast Container ─────────────────────────────── -->
<div id="notifToastContainer" aria-live="polite" aria-atomic="true"
     style="position:fixed;top:72px;right:18px;z-index:9999;display:flex;flex-direction:column;gap:8px;pointer-events:none;max-width:340px;width:calc(100vw - 36px);">
</div>

<style>
/* ── Toast card ── */
.notif-toast {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 6px 32px rgba(0,0,0,0.15), 0 1.5px 6px rgba(0,0,0,0.08);
    border: 1px solid #e2e8f0;
    padding: 12px 14px 12px 14px;
    display: flex;
    align-items: flex-start;
    gap: 10px;
    pointer-events: all;
    animation: notifToastIn 0.32s cubic-bezier(.21,1.02,.73,1) forwards;
    position: relative;
    overflow: hidden;
}
.notif-toast::before {
    content: '';
    position: absolute;
    left: 0; top: 0; bottom: 0;
    width: 4px;
    border-radius: 12px 0 0 12px;
    background: var(--toast-accent, #3b82f6);
}
.notif-toast.toast-out {
    animation: notifToastOut 0.28s ease-in forwards;
}
.notif-toast-icon {
    font-size: 1.1rem;
    flex-shrink: 0;
    margin-top: 1px;
}
.notif-toast-body { flex: 1; min-width: 0; }
.notif-toast-title {
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: #64748b;
    margin-bottom: 2px;
}
.notif-toast-msg {
    font-size: 0.82rem;
    color: #1e293b;
    line-height: 1.45;
    word-break: break-word;
}
.notif-toast-time {
    font-size: 0.67rem;
    color: #94a3b8;
    margin-top: 3px;
}
.notif-toast-close {
    background: none;
    border: none;
    padding: 0 0 0 4px;
    cursor: pointer;
    color: #94a3b8;
    font-size: 1rem;
    line-height: 1;
    flex-shrink: 0;
    align-self: flex-start;
}
.notif-toast-close:hover { color: #475569; }

/* ── Progress bar that auto-dismisses ── */
.notif-toast-progress {
    position: absolute;
    bottom: 0; left: 0; right: 0;
    height: 3px;
    background: var(--toast-accent, #3b82f6);
    opacity: 0.35;
    transform-origin: left;
    animation: notifToastProgress var(--toast-duration, 5s) linear forwards;
}

@keyframes notifToastIn {
    from { opacity: 0; transform: translateX(60px) scale(0.94); }
    to   { opacity: 1; transform: translateX(0)     scale(1);    }
}
@keyframes notifToastOut {
    from { opacity: 1; transform: translateX(0)     scale(1);    max-height: 120px; margin-bottom: 0; }
    to   { opacity: 0; transform: translateX(60px)  scale(0.94); max-height: 0;     margin-bottom: -8px; }
}
@keyframes notifToastProgress {
    from { transform: scaleX(1); }
    to   { transform: scaleX(0); }
}

/* Dark mode adjustments */
.dark-mode .notif-toast {
    background: #1e293b;
    border-color: #334155;
    box-shadow: 0 6px 32px rgba(0,0,0,0.4);
}
.dark-mode .notif-toast-msg  { color: #e2e8f0; }
.dark-mode .notif-toast-title { color: #94a3b8; }
</style>

<?php if ($pusherEnabled): ?>
<!-- Pusher JS SDK (loaded only when credentials are configured) -->
<script src="https://js.pusher.com/8.4/pusher.min.js"></script>
<?php endif; ?>

<!-- Typeable Date Input Enhancer — makes every input[type=date/datetime-local]
     accept MM/DD/YYYY typed input while keeping calendar-pick support -->
<script src="<?= BASE_URL ?>/assets/js/datepicker.js?v=2.0"></script>

<script>
// ── Sidebar collapse/expand ───────────────────────────────
function toggleSidebar() {
    const body    = document.body;
    const sidebar = document.getElementById('sidebar');
    const isMobile = window.innerWidth < 769;

    if (isMobile) {
        // Mobile: slide in/out (existing behaviour)
        if (sidebar) sidebar.classList.toggle('show');
    } else {
        // Desktop: collapse to icon-only rail
        body.classList.toggle('sidebar-collapsed');
        const collapsed = body.classList.contains('sidebar-collapsed');
        localStorage.setItem('sidebarCollapsed', collapsed ? '1' : '0');
        // Update icon
        const icon = document.getElementById('sidebarToggleIcon');
        if (icon) {
            icon.className = collapsed ? 'bi bi-layout-sidebar' : 'bi bi-list';
        }
        // Refresh Bootstrap tooltips on nav links
        if (collapsed) {
            document.querySelectorAll('.sidebar-nav .nav-link').forEach(function(el) {
                if (!el._bsTooltip) {
                    el._bsTooltip = new bootstrap.Tooltip(el, { placement: 'right', trigger: 'hover' });
                }
            });
        } else {
            document.querySelectorAll('.sidebar-nav .nav-link').forEach(function(el) {
                if (el._bsTooltip) { el._bsTooltip.dispose(); el._bsTooltip = null; }
            });
        }
    }
}

// Restore sidebar state on page load
(function() {
    if (localStorage.getItem('sidebarCollapsed') === '1' && window.innerWidth >= 769) {
        document.body.classList.add('sidebar-collapsed');
        document.addEventListener('DOMContentLoaded', function() {
            const icon = document.getElementById('sidebarToggleIcon');
            if (icon) icon.className = 'bi bi-layout-sidebar';
            // Init tooltips
            document.querySelectorAll('.sidebar-nav .nav-link').forEach(function(el) {
                if (!el._bsTooltip) {
                    el._bsTooltip = new bootstrap.Tooltip(el, { placement: 'right', trigger: 'hover' });
                }
            });
        });
    }
})();

function toggleDarkMode(e) {
    e.preventDefault();
    e.stopPropagation();
    document.documentElement.classList.toggle('dark-mode');
    const isDark = document.documentElement.classList.contains('dark-mode');
    localStorage.setItem('darkMode', isDark ? '1' : '0');
    patchTableRowHovers(isDark);
}

// Patch inline onmouseover/onmouseout on table rows so they respect dark mode
function patchTableRowHovers(isDark) {
    document.querySelectorAll('table tbody tr[onmouseover]').forEach(function(tr) {
        if (isDark) {
            tr.setAttribute('data-orig-over',  tr.getAttribute('onmouseover') || '');
            tr.setAttribute('data-orig-out',   tr.getAttribute('onmouseout')  || '');
            tr.setAttribute('data-orig-bg',    tr.style.background || '');
            tr.onmouseover = function() { this.style.background = '#162844'; };
            tr.onmouseout  = function() {
                const orig = tr.getAttribute('data-orig-bg');
                this.style.background = orig || '';
            };
        } else {
            const origOver = tr.getAttribute('data-orig-over');
            const origOut  = tr.getAttribute('data-orig-out');
            if (origOver !== null) {
                tr.setAttribute('onmouseover', origOver);
                tr.setAttribute('onmouseout',  origOut);
                tr.removeAttribute('data-orig-over');
                tr.removeAttribute('data-orig-out');
                tr.removeAttribute('data-orig-bg');
            }
        }
    });
}

// Font size (steps: 80, 90, 100, 110, 120, 130)
const fontSteps = [80, 90, 100, 110, 120, 130];
let fontIndex = fontSteps.indexOf(parseInt(localStorage.getItem('fontSize') || '100'));
if (fontIndex === -1) fontIndex = 2;

function applyFontSize() {
    document.documentElement.style.fontSize = fontSteps[fontIndex] + '%';
    const lbl = document.getElementById('fontSizeLabel');
    if (lbl) lbl.textContent = fontSteps[fontIndex] + '%';
    localStorage.setItem('fontSize', fontSteps[fontIndex]);
}

function changeFontSize(dir, e) {
    e.preventDefault();
    e.stopPropagation();
    fontIndex = Math.max(0, Math.min(fontSteps.length - 1, fontIndex + dir));
    applyFontSize();
}

// Apply on load
if (localStorage.getItem('darkMode') === '1') {
    document.documentElement.classList.add('dark-mode');
}
applyFontSize();

// Apply table row hover patch after DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    if (document.documentElement.classList.contains('dark-mode')) {
        patchTableRowHovers(true);
    }
});
</script>

<script>
// ─────────────────────────────────────────────────────────
//  Notification helpers
// ─────────────────────────────────────────────────────────
(function () {
    // Cached DOM refs
    const badge      = () => document.getElementById('notifBadge');
    const markAllBtn = () => document.getElementById('markAllReadBtn');
    const notifList  = () => document.getElementById('notifList');

    // Current unread count (synced with server on load)
    let unreadCount = <?= (int)$notifCount ?>;

    // ── Update badge display ──────────────────────────────
    function refreshBadge() {
        const el = badge();
        if (!el) return;
        if (unreadCount <= 0) {
            el.classList.add('d-none');
            el.textContent = '';
        } else {
            el.classList.remove('d-none');
            el.textContent = unreadCount > 9 ? '9+' : unreadCount;
        }
    }

    // ── Build a single notification item HTML ────────────
    function buildNotifItem(notif) {
        // Category-first icon mapping — clean monochrome professional icons
        const catIconMap = {
            announcement : 'bi-megaphone text-secondary',
            certificate  : 'bi-file-earmark-text text-secondary',
            resident     : 'bi-person text-secondary',
            transaction  : 'bi-arrow-left-right text-secondary',
        };
        const typeIconMap = {
            success : 'bi-check-circle text-secondary',
            warning : 'bi-exclamation-circle text-secondary',
            danger  : 'bi-x-circle text-secondary',
            info    : 'bi-bell text-secondary',
        };
        const category = notif.category || 'system';
        const icon = catIconMap[category] || typeIconMap[notif.type] || typeIconMap.info;

        // Category label config
        const catLabels = {
            announcement : { text: 'Announcement', bg: '#dbeafe', color: '#1a4f7a' },
            certificate  : { text: 'Certificate',  bg: '#dcfce7', color: '#166534' },
            resident     : { text: 'Resident',     bg: '#e0f2fe', color: '#0369a1' },
            transaction  : { text: 'Transaction',  bg: '#fef9c3', color: '#854d0e' },
            system       : { text: 'System',       bg: '#f1f5f9', color: '#475569' },
        };
        const lbl = catLabels[category] || catLabels.system;

        // Sanitise message — allow safe inline tags (<strong>,<em>,<b>,<i>,<br>)
        const safeMsg = (notif.message || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '\x00LT\x00')
            .replace(/>/g, '\x00GT\x00')
            .replace(/\x00LT\x00(\/?(strong|em|b|i|br))\x00GT\x00/gi, '<$1>')
            .replace(/\x00LT\x00/g, '&lt;')
            .replace(/\x00GT\x00/g, '&gt;');

        // Format date
        const d   = new Date(notif.created_at.replace(' ', 'T'));
        const fmt = isNaN(d) ? notif.created_at
                              : d.toLocaleString('en-PH', {
                                    month:'short', day:'2-digit', year:'numeric',
                                    hour:'2-digit', minute:'2-digit', hour12:true
                                });
        const div = document.createElement('div');
        div.className = 'dropdown-item notif-item py-2 border-bottom notif-unread';
        div.style.whiteSpace = 'normal';
        div.innerHTML = `
            <div class="d-flex gap-2 align-items-start">
                <i class="bi ${icon} mt-1 flex-shrink-0" style="font-size:0.95rem;"></i>
                <div class="flex-grow-1">
                    <div class="small">${safeMsg}</div>
                    <div class="d-flex align-items-center gap-2 mt-1 flex-wrap">
                        <span style="font-size:0.62rem;background:${lbl.bg};color:${lbl.color};padding:1px 7px;border-radius:20px;font-weight:600;letter-spacing:0.03em;">${lbl.text}</span>
                        <div class="text-muted" style="font-size:0.68rem;">${fmt}</div>
                        <span class="notif-read-badge" style="display:none;font-size:0.62rem;background:#e8f5e9;color:#2e7d32;padding:1px 6px;border-radius:20px;font-weight:600;letter-spacing:0.02em;"><i class="bi bi-check2-all me-1"></i>Read</span>
                    </div>
                </div>
            </div>`;
        return div;
    }

    // Track seen notification IDs to prevent duplicates (e.g. Pusher firing twice)
    const _seenNotifIds = new Set();

    // ── Inject a new notification at the top of the list ─
    function prependNotif(notif) {
        // Deduplicate — skip if we've already handled this notification ID
        const nid = notif.id || notif.notification_id;
        if (nid) {
            if (_seenNotifIds.has(nid)) return;
            _seenNotifIds.add(nid);
        }

        const list = notifList();
        if (!list) return;

        // Remove "No new notifications" placeholder if present
        const empty = list.querySelector('#notifEmpty');
        if (empty) empty.remove();

        list.insertBefore(buildNotifItem(notif), list.firstChild);
        unreadCount++;
        refreshBadge();

        // Show "Mark all read" button
        const btn = markAllBtn();
        if (btn) btn.classList.remove('d-none');

        // Flash the bell icon
        const bell = document.getElementById('notifBellBtn');
        if (bell) {
            bell.classList.add('notif-bell-pulse');
            setTimeout(() => bell.classList.remove('notif-bell-pulse'), 1000);
        }

        // Browser notification (if permission granted) — strip HTML tags for plain text
        if (window.Notification && Notification.permission === 'granted') {
            const _tmp = document.createElement('div');
            _tmp.innerHTML = notif.message || '';
            const plainMsg = _tmp.textContent || _tmp.innerText || '';
            new Notification('New Notification', { body: plainMsg, icon: '<?= BASE_URL ?>/assets/img/logo 1.jpg' });
        }
    }

    // ── Mark all read ─────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        const btn = markAllBtn();
        if (!btn) return;
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            markAllRead();
        });

        // Auto-mark as read when the dropdown is opened
        const dropdownEl = document.getElementById('notifDropdown');
        if (dropdownEl) {
            dropdownEl.addEventListener('show.bs.dropdown', function () {
                if (unreadCount > 0) markAllRead();
            });
        }

        // Request browser notification permission on first load
        if (window.Notification && Notification.permission === 'default') {
            Notification.requestPermission();
        }
    });

    function markAllRead() {
        fetch('<?= BASE_URL ?>/ajax/mark_notifications_read.php', {
            method: 'POST',
            headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || '' }
        })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    // Mark all items as read visually — keep them visible, just remove the unread highlight
                    const list = notifList();
                    if (list) {
                        list.querySelectorAll('.notif-item.notif-unread').forEach(function (item) {
                            item.classList.remove('notif-unread');
                            item.classList.add('notif-read');
                            const badge = item.querySelector('.notif-read-badge');
                            if (badge) badge.style.display = 'inline-block';
                        });
                    }
                    unreadCount = 0;
                    refreshBadge();
                    const btn = markAllBtn();
                    if (btn) btn.classList.add('d-none');
                    // Keep the meta csrf-token in sync with the server session token
                    // so any subsequent form submit picks up the correct value.
                    if (data.csrf_token) {
                        const meta = document.querySelector('meta[name="csrf-token"]');
                        if (meta) meta.content = data.csrf_token;
                    }
                }
            })
            .catch(() => {});
    }

    // ── Pusher real-time subscription ────────────────────
    <?php if ($pusherEnabled): ?>
    // Prevent duplicate subscriptions if topbar.php is included multiple times
    if (!window._pusherNotifInitialized) {
        window._pusherNotifInitialized = true;
        
        document.addEventListener('DOMContentLoaded', function () {
            const pusher = new Pusher('<?= htmlspecialchars($pusherKey, ENT_QUOTES) ?>', {
                cluster:   '<?= htmlspecialchars($pusherCluster, ENT_QUOTES) ?>',
                authEndpoint: '<?= BASE_URL ?>/ajax/pusher_auth.php',
                auth: {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                }
            });

            const channel = pusher.subscribe('private-user-<?= (int)$u['id'] ?>');

            channel.bind('new-notification', function (data) {
                prependNotif(data);
            });

            // Optional: log connection state changes during development
            // pusher.connection.bind('state_change', s => console.log('[Pusher]', s.previous, '→', s.current));
        });
    }
    <?php endif; ?>

})();

// ── Contact Number Validation — shared across all pages ───
let _contactTimers = {};
function validateContact(input, errId) {
    // Strip any non-digit characters immediately (handles paste too)
    const stripped = input.value.replace(/[^0-9]/g, '');
    if (input.value !== stripped) input.value = stripped;
    const val = input.value;
    const err = errId ? document.getElementById(errId) : null;
    if (!val) {
        input.classList.remove('is-invalid', 'is-valid');
        if (err) err.style.display = 'none';
        return;
    }
    if (/^09\d{9}$/.test(val)) {
        input.classList.remove('is-invalid');
        input.classList.add('is-valid');
        if (err) err.style.display = 'none';
        const table     = input.dataset.checkTable;
        const excludeId = input.dataset.excludeId || 0;
        if (table && errId) {
            clearTimeout(_contactTimers[errId]);
            _contactTimers[errId] = setTimeout(() => {
                fetch(`<?= BASE_URL ?>/ajax/check_contact.php?contact=${encodeURIComponent(val)}&table=${table}&exclude_id=${excludeId}`)
                    .then(r => r.json())
                    .then(d => {
                        if (d.taken) {
                            input.classList.remove('is-valid');
                            input.classList.add('is-invalid');
                            if (err) {
                                err.innerHTML = `<i class="bi bi-exclamation-circle-fill me-1"></i>Already in use by <strong>${d.name}</strong>. Choose a different number.`;
                                err.style.display = 'block';
                            }
                        }
                    });
            }, 400);
        }
    } else {
        input.classList.add('is-invalid');
        input.classList.remove('is-valid');
        if (err) {
            err.style.display = 'block';
            if (!/^09/.test(val))          err.innerHTML = '<i class="bi bi-x-circle me-1"></i>Must start with 09.';
            else if (val.length < 11)      err.innerHTML = '<i class="bi bi-x-circle me-1"></i>Must be 11 digits. ' + (11 - val.length) + ' more needed.';
            else if (val.length > 11)      err.innerHTML = '<i class="bi bi-x-circle me-1"></i>Too many digits — must be exactly 11.';
            else                           err.innerHTML = '<i class="bi bi-x-circle me-1"></i>Invalid format. Use 09XXXXXXXXX.';
        }
    }
}

// ── Block non-numeric keystrokes on all contact number fields ──
document.addEventListener('DOMContentLoaded', function () {
    const _allowedKeys = new Set(['Backspace','Delete','ArrowLeft','ArrowRight','ArrowUp','ArrowDown','Tab','Home','End','Enter']);
    document.querySelectorAll('[oninput*="validateContact"], [data-check-table]').forEach(function (el) {
        el.addEventListener('keydown', function (e) {
            if (_allowedKeys.has(e.key)) return;
            if (e.ctrlKey || e.metaKey) return; // allow Ctrl+C, Ctrl+V, etc.
            if (/^[0-9]$/.test(e.key)) return;
            e.preventDefault();
        });
    });
});

// ── Debounced search form submit ──────────────────────────
(function () {
    let _searchTimer = null;
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('input[data-search-form]').forEach(function (input) {
            input.addEventListener('input', function () {
                clearTimeout(_searchTimer);
                const formId = input.getAttribute('data-search-form');
                const form   = document.getElementById(formId);
                if (!form) return;
                if (input.value === '') {
                    form.submit();
                } else {
                    _searchTimer = setTimeout(function () { form.submit(); }, 350);
                }
            });
        });
    });
})();
</script>

<script>
// ── Name Field Auto-Capitalization & No Leading Spaces ────────────────────
// Applies to every text input whose name suggests a person's name.
// Rules:
//   1. No leading whitespace — first character typed must be a letter.
//   2. First letter of every word is auto-capitalized.
// Uses event delegation on document so it works for dynamically added
// inputs (modals, wizard steps, etc.) without needing re-init.
(function () {
    // Selectors that identify name fields by their `name` attribute
    var NAME_PATTERN = /first.?name|last.?name|middle.?name|full.?name|suffix/i;

    function isNameField(el) {
        if (!el || el.tagName !== 'INPUT' || el.type !== 'text') return false;
        var n = el.name || el.id || '';
        return NAME_PATTERN.test(n);
    }

    // Title-case the value: capitalize first letter of each word
    function toTitleCase(str) {
        return str.replace(/\b([a-záàâäãéèêëíìîïóòôöõúùûüýÿñ])/gi, function (c) {
            return c.toUpperCase();
        });
    }

    document.addEventListener('input', function (e) {
        var el = e.target;
        if (!isNameField(el)) return;

        var val = el.value;

        // 1. Strip leading spaces — user cannot start with a space
        if (val.length > 0 && val[0] === ' ') {
            el.value = val.trimStart();
            val = el.value;
        }

        // 2. Auto-capitalize first letter of every word
        var titled = toTitleCase(val);
        if (titled !== val) {
            // Preserve caret position
            var pos = el.selectionStart;
            el.value = titled;
            try { el.setSelectionRange(pos, pos); } catch(e2) {}
        }
    }, true); // capture phase so it fires before other listeners

})();
</script>

<?php if ($_idleEnabled === '1'): ?>
<?php
// Compute idle milliseconds based on unit (seconds or minutes)
$_idleUnit    = in_array($_idleUnit, ['seconds','minutes']) ? $_idleUnit : 'minutes';
$_idleValue   = max(1, (int)$_idleMinutes);
$_idleMs      = $_idleUnit === 'seconds' ? $_idleValue * 1000 : $_idleValue * 60 * 1000;
$_loginUrl    = BASE_URL . '/index.php';
$_logoutUrl   = BASE_URL . '/logout.php';
?>
<!-- ── Idle Timeout Modal ─────────────────────────────────── -->
<div id="idleOverlay" style="display:none;position:fixed;inset:0;background:rgba(10,20,40,0.72);z-index:99999;align-items:center;justify-content:center;backdrop-filter:blur(4px);">
    <div style="background:#fff;border-radius:20px;padding:36px 40px;max-width:420px;width:90%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,0.35);">
        <div style="width:64px;height:64px;background:linear-gradient(135deg,#1a4f7a,#3a7fa8);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
            <i class="bi bi-lock-fill" style="color:#fff;font-size:1.6rem;"></i>
        </div>
        <h5 style="font-weight:700;color:#1a2e3f;margin-bottom:8px;">Session Timed Out</h5>
        <p style="font-size:0.875rem;color:#64748b;margin-bottom:6px;">
            You have been idle. For your security, your session has been locked.
        </p>
        <p style="font-size:0.8rem;color:#94a3b8;margin-bottom:24px;">
            Logging out automatically in <strong id="idleCountdown">10</strong>s…
        </p>
        <!-- Primary: go to login page -->
        <a href="<?= htmlspecialchars($_loginUrl) ?>" id="idleLoginBtn"
           style="display:inline-block;background:linear-gradient(90deg,#1a4f7a,#3a7fa8);color:#fff;border-radius:10px;padding:10px 32px;font-weight:600;text-decoration:none;font-size:0.9rem;margin-bottom:10px;">
            <i class="bi bi-box-arrow-in-right me-2"></i>Log In Now
        </a>
    </div>
</div>

<script>
(function () {
    const IDLE_MS    = <?= (int)$_idleMs ?>;
    const WARN_SEC   = 10;   // countdown seconds before auto-logout
    const LOGIN_URL  = '<?= addslashes($_loginUrl) ?>';
    const LOGOUT_URL = '<?= addslashes($_logoutUrl) ?>';

    let idleTimer, countdownTimer, countdownSec;
    const overlay = document.getElementById('idleOverlay');
    const cdEl    = document.getElementById('idleCountdown');
    const EVENTS  = ['mousemove','mousedown','keydown','touchstart','scroll','click'];

    function showLockScreen() {
        // Stop activity listeners so user can't accidentally dismiss overlay
        EVENTS.forEach(function (ev) {
            document.removeEventListener(ev, resetIdle);
        });

        overlay.style.display = 'flex';
        countdownSec = WARN_SEC;
        if (cdEl) cdEl.textContent = countdownSec;

        countdownTimer = setInterval(function () {
            countdownSec--;
            if (cdEl) cdEl.textContent = countdownSec;
            if (countdownSec <= 0) {
                clearInterval(countdownTimer);
                // Auto-logout: invalidate server session then redirect to login
                fetch(LOGOUT_URL, { method: 'POST', credentials: 'same-origin' })
                    .catch(function () {})
                    .finally(function () {
                        window.location.href = LOGIN_URL;
                    });
            }
        }, 1000);
    }

    function resetIdle() {
        clearTimeout(idleTimer);
        if (overlay.style.display === 'flex') return; // already showing — don't reset
        idleTimer = setTimeout(showLockScreen, IDLE_MS);
    }

    // Attach activity listeners
    EVENTS.forEach(function (ev) {
        document.addEventListener(ev, resetIdle, { passive: true });
    });

    // Start on page load
    resetIdle();
})();
</script>
<?php endif; ?>
