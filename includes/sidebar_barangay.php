<?php
$currentPage = basename($_SERVER['PHP_SELF']);
$u = currentUser();
$settingsOpen = in_array($currentPage, ['settings.php', 'audit_log.php', 'archive.php']);
?>
<aside class="sidebar" id="sidebar">
    <!-- Map background overlay -->
    <div class="sidebar-map-bg"></div>

    <div class="sidebar-brand">
        <div class="brand-card">
            <div class="brand-inner">
                <div class="brand-logo-wrap">
                    <picture>
                        <source srcset="<?= BASE_URL ?>/assets/img/logo2.webp" type="image/webp">
                        <img src="<?= BASE_URL ?>/assets/img/logo 2.jpg" alt="Barangay Logo" class="brand-logo" width="42" height="42">
                    </picture>
                </div>
                <div class="brand-text">
                    CENTRALIZED LGU<br>INFORMATION<br>MANAGEMENT SYSTEM
                </div>
            </div>
        </div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section-title">Main</div>
        <a href="<?= BASE_URL ?>/barangay_admin/dashboard.php"
           class="nav-link <?= $currentPage === 'dashboard.php' ? 'active' : '' ?>"
           data-bs-toggle="tooltip" data-bs-placement="right" title="Dashboard">
            <i class="bi bi-speedometer2"></i> <span class="nav-link-text">Dashboard</span>
        </a>
        <a href="<?= BASE_URL ?>/barangay_admin/forecasting.php"
           class="nav-link <?= $currentPage === 'forecasting.php' ? 'active' : '' ?>"
           data-bs-toggle="tooltip" data-bs-placement="right" title="Forecasting">
            <i class="bi bi-cpu-fill"></i> <span class="nav-link-text">Forecasting</span>
        </a>
        <a href="<?= BASE_URL ?>/barangay_admin/analytics.php"
           class="nav-link <?= $currentPage === 'analytics.php' ? 'active' : '' ?>"
           data-bs-toggle="tooltip" data-bs-placement="right" title="Analytics">
            <i class="bi bi-bar-chart-line-fill"></i> <span class="nav-link-text">Analytics</span>
        </a>

        <div class="nav-section-title">Records</div>
        <a href="<?= BASE_URL ?>/barangay_admin/residents.php"
           class="nav-link <?= $currentPage === 'residents.php' ? 'active' : '' ?>"
           data-bs-toggle="tooltip" data-bs-placement="right" title="Residents">
            <i class="bi bi-people-fill"></i> <span class="nav-link-text">Residents</span>
        </a>
        <a href="<?= BASE_URL ?>/barangay_admin/households.php"
           class="nav-link <?= $currentPage === 'households.php' ? 'active' : '' ?>"
           data-bs-toggle="tooltip" data-bs-placement="right" title="Households">
            <i class="bi bi-house-door-fill"></i> <span class="nav-link-text">Households</span>
        </a>
        <a href="<?= BASE_URL ?>/barangay_admin/officials.php"
           class="nav-link <?= $currentPage === 'officials.php' ? 'active' : '' ?>"
           data-bs-toggle="tooltip" data-bs-placement="right" title="Officials">
            <i class="bi bi-person-badge-fill"></i> <span class="nav-link-text">Officials</span>
        </a>

        <div class="nav-section-title">Services</div>
        <a href="<?= BASE_URL ?>/barangay_admin/certificates.php"
           class="nav-link <?= $currentPage === 'certificates.php' ? 'active' : '' ?>"
           data-bs-toggle="tooltip" data-bs-placement="right" title="Certificates">
            <i class="bi bi-file-earmark-text-fill"></i> <span class="nav-link-text">Certificates</span>
        </a>
        <a href="<?= BASE_URL ?>/barangay_admin/blotter.php"
           class="nav-link <?= $currentPage === 'blotter.php' ? 'active' : '' ?>"
           data-bs-toggle="tooltip" data-bs-placement="right" title="Blotter">
            <i class="bi bi-shield-exclamation"></i> <span class="nav-link-text">Blotter</span>
        </a>
        <a href="<?= BASE_URL ?>/barangay_admin/reports.php"
           class="nav-link <?= $currentPage === 'reports.php' ? 'active' : '' ?>"
           data-bs-toggle="tooltip" data-bs-placement="right" title="Reports">
            <i class="bi bi-file-earmark-bar-graph-fill"></i> <span class="nav-link-text">Reports</span>
        </a>
        <a href="<?= BASE_URL ?>/barangay_admin/notifications.php"
           class="nav-link <?= $currentPage === 'notifications.php' ? 'active' : '' ?>"
           data-bs-toggle="tooltip" data-bs-placement="right" title="Notifications">
            <i class="bi bi-bell-fill"></i> <span class="nav-link-text">Notifications</span>
        </a>
        <a href="<?= BASE_URL ?>/barangay_admin/notices.php"
           class="nav-link <?= $currentPage === 'notices.php' ? 'active' : '' ?>"
           data-bs-toggle="tooltip" data-bs-placement="right" title="Announcements">
            <i class="bi bi-megaphone-fill"></i> <span class="nav-link-text">Announcements</span>
        </a>

        <div class="nav-section-title">System</div>
        <a href="<?= BASE_URL ?>/barangay_admin/activity_log.php"
           class="nav-link <?= $currentPage === 'activity_log.php' ? 'active' : '' ?>"
           data-bs-toggle="tooltip" data-bs-placement="right" title="Activity Log">
            <i class="bi bi-clock-history"></i> <span class="nav-link-text">Activity Log</span>
        </a>
        <a href="<?= BASE_URL ?>/barangay_admin/requests.php"
           class="nav-link <?= $currentPage === 'requests.php' ? 'active' : '' ?>"
           data-bs-toggle="tooltip" data-bs-placement="right" title="Requests">
            <i class="bi bi-inbox-fill"></i> <span class="nav-link-text">Requests</span>
            <?php
            try {
                $_reqUid = (int)($u['id'] ?? 0);
                if ($_reqUid > 0) {
                    $_reqCount = (int)$db->query("SELECT COUNT(*) FROM user_requests WHERE requester_id=$_reqUid AND status='pending'")->fetchColumn();
                    if ($_reqCount > 0): ?>
                        <span class="badge bg-warning text-dark ms-auto" style="font-size:0.6rem;"><?= $_reqCount ?></span>
                    <?php endif;
                }
            } catch (Exception $e) {}
            ?>
        </a>

        <div class="nav-section-title">Configuration</div>
        <!-- Settings: pure dropdown toggle, no navigation -->
        <?php $settingsOpen = in_array($currentPage, ['settings.php', 'audit_log.php', 'archive.php']); ?>
        <div class="nav-has-sub <?= $settingsOpen ? 'nav-sub-open' : '' ?>">
            <a class="nav-link nav-parent-link <?= $settingsOpen ? 'active' : '' ?>"
               href="#" onclick="sidebarSubToggle(event, this); return false;">
                <i class="bi bi-gear-fill"></i>
                <span class="nav-link-text">Settings</span>
                <i class="bi bi-chevron-down nav-sub-arrow"></i>
            </a>
            <div class="nav-sub-items">
                <a href="<?= BASE_URL ?>/barangay_admin/settings.php"
                   class="nav-link nav-child-link <?= $currentPage === 'settings.php' ? 'active' : '' ?>">
                    <i class="bi bi-sliders"></i>
                    <span class="nav-link-text">System Settings</span>
                </a>
                <a href="<?= BASE_URL ?>/barangay_admin/audit_log.php"
                   class="nav-link nav-child-link <?= $currentPage === 'audit_log.php' ? 'active' : '' ?>">
                    <i class="bi bi-shield-check"></i>
                    <span class="nav-link-text">Audit Log</span>
                </a>
                <a href="<?= BASE_URL ?>/barangay_admin/archive.php"
                   class="nav-link nav-child-link <?= $currentPage === 'archive.php' ? 'active' : '' ?>">
                    <i class="bi bi-archive-fill"></i>
                    <span class="nav-link-text">Archive</span>
                </a>
            </div>
        </div>
    </nav>

    <div class="sidebar-user">
        <?php
        $sidebarPhoto = $_SESSION['user_photo'] ?? null;
        if (!$sidebarPhoto) {
            try {
                $spStmt = $db->prepare("SELECT profile_photo FROM users WHERE id=?");
                $spStmt->execute([$u['id']]);
                $sidebarPhoto = $spStmt->fetchColumn() ?: null;
                if ($sidebarPhoto) $_SESSION['user_photo'] = $sidebarPhoto;
            } catch (Exception $e) {}
        }
        ?>
        <div class="sidebar-user-avatar" style="overflow:hidden;padding:0;<?= $sidebarPhoto ? 'background:none;' : '' ?>">
            <?php if ($sidebarPhoto): ?>
                <img src="<?= htmlspecialchars(UPLOAD_URL . 'profile_photos/' . $sidebarPhoto) ?>"
                     alt="Profile" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
            <?php else: ?>
                <?= strtoupper(substr($u['name'], 0, 2)) ?>
            <?php endif; ?>
        </div>
        <div class="user-info">
            <div class="user-name"><?= htmlspecialchars($u['name']) ?></div>
            <div class="user-role">Barangay Admin</div>
        </div>
        <a href="<?= BASE_URL ?>/logout.php" class="sidebar-logout" title="Logout">
            <i class="bi bi-box-arrow-right"></i>
        </a>
    </div>
</aside>
