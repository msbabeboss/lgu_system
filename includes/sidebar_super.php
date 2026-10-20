<?php
$currentPage = basename($_SERVER['PHP_SELF']);
$u = currentUser();
$settingsOpen = in_array($currentPage, ['settings.php', 'audit_log.php', 'archive.php']);
$brSubPages   = ['barangay_records_barangays.php','barangay_records_residents.php',
                  'barangay_records_households.php','barangay_records_officials.php',
                  'barangay_records_reports.php','barangay_records_certificates.php'];
$brActive     = ($currentPage === 'barangay_records.php' || in_array($currentPage, $brSubPages));
?>
<aside class="sidebar" id="sidebar">
    <!-- Map background overlay -->
    <div class="sidebar-map-bg"></div>

    <div class="sidebar-brand">
        <div class="brand-card">
            <div class="brand-inner">
                <div class="brand-logo-wrap">
                    <picture>
                        <source srcset="<?= BASE_URL ?>/assets/img/logo1.webp" type="image/webp">
                        <img src="<?= BASE_URL ?>/assets/img/logo 1.jpg" alt="Municipality Logo" class="brand-logo" width="42" height="42">
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
        <a href="<?= BASE_URL ?>/super_admin/dashboard.php"
           class="nav-link <?= $currentPage === 'dashboard.php' ? 'active' : '' ?>"
           data-bs-toggle="tooltip" data-bs-placement="right" title="Dashboard">
            <i class="bi bi-speedometer2"></i> <span class="nav-link-text">Dashboard</span>
        </a>
        <a href="<?= BASE_URL ?>/super_admin/forecasting.php"
           class="nav-link <?= $currentPage === 'forecasting.php' ? 'active' : '' ?>"
           data-bs-toggle="tooltip" data-bs-placement="right" title="Forecasting">
            <i class="bi bi-cpu-fill"></i> <span class="nav-link-text">Forecasting</span>
        </a>
        <a href="<?= BASE_URL ?>/super_admin/analytics.php"
           class="nav-link <?= $currentPage === 'analytics.php' ? 'active' : '' ?>"
           data-bs-toggle="tooltip" data-bs-placement="right" title="Analytics">
            <i class="bi bi-bar-chart-line-fill"></i> <span class="nav-link-text">Analytics</span>
        </a>

        <div class="nav-section-title">Management</div>
        <a href="<?= BASE_URL ?>/super_admin/barangays.php"
           class="nav-link <?= $currentPage === 'barangays.php' ? 'active' : '' ?>"
           data-bs-toggle="tooltip" data-bs-placement="right" title="Barangay List">
            <i class="bi bi-geo-alt-fill"></i> <span class="nav-link-text">Barangay List</span>
        </a>
        <a href="<?= BASE_URL ?>/super_admin/barangay_records.php"
           class="nav-link <?= $brActive ? 'active' : '' ?>"
           data-bs-toggle="tooltip" data-bs-placement="right" title="Barangay Records">
            <i class="bi bi-journal-bookmark-fill"></i> <span class="nav-link-text">Barangay Records</span>
        </a>
        <a href="<?= BASE_URL ?>/super_admin/users.php"
           class="nav-link <?= $currentPage === 'users.php' ? 'active' : '' ?>"
           data-bs-toggle="tooltip" data-bs-placement="right" title="Users">
            <i class="bi bi-people-fill"></i> <span class="nav-link-text">Users</span>
        </a>
        <a href="<?= BASE_URL ?>/super_admin/notifications.php"
           class="nav-link <?= $currentPage === 'notifications.php' ? 'active' : '' ?>"
           data-bs-toggle="tooltip" data-bs-placement="right" title="Notifications">
            <i class="bi bi-bell-fill"></i> <span class="nav-link-text">Notifications</span>
        </a>
        <a href="<?= BASE_URL ?>/super_admin/notices.php"
           class="nav-link <?= $currentPage === 'notices.php' ? 'active' : '' ?>"
           data-bs-toggle="tooltip" data-bs-placement="right" title="Announcements">
            <i class="bi bi-megaphone-fill"></i> <span class="nav-link-text">Announcements</span>
        </a>
        <a href="<?= BASE_URL ?>/super_admin/reports.php"
           class="nav-link <?= $currentPage === 'reports.php' ? 'active' : '' ?>"
           data-bs-toggle="tooltip" data-bs-placement="right" title="Reports">
            <i class="bi bi-file-earmark-bar-graph-fill"></i> <span class="nav-link-text">Reports</span>
        </a>
        <a href="<?= BASE_URL ?>/super_admin/requests.php"
           class="nav-link <?= $currentPage === 'requests.php' ? 'active' : '' ?>"
           data-bs-toggle="tooltip" data-bs-placement="right" title="Requests">
            <i class="bi bi-inbox-fill"></i> <span class="nav-link-text">Requests</span>
            <?php
            try {
                $pendingReqs = (int)$db->query("SELECT COUNT(*) FROM user_requests WHERE status='pending'")->fetchColumn();
                $pendingTr   = (int)$db->query("SELECT COUNT(*) FROM resident_transfer_requests WHERE status='pending'")->fetchColumn();
                $totalBadge  = $pendingReqs + $pendingTr;
                if ($totalBadge > 0): ?>
                    <span class="badge bg-danger ms-auto" style="font-size:0.6rem;"><?= $totalBadge ?></span>
                <?php endif;
            } catch (Exception $e) {}
            ?>
        </a>

        <div class="nav-section-title">System</div>
        <a href="<?= BASE_URL ?>/super_admin/activity_log.php"
           class="nav-link <?= $currentPage === 'activity_log.php' ? 'active' : '' ?>"
           data-bs-toggle="tooltip" data-bs-placement="right" title="Activity Log">
            <i class="bi bi-clock-history"></i> <span class="nav-link-text">Activity Log</span>
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
                <a href="<?= BASE_URL ?>/super_admin/settings.php"
                   class="nav-link nav-child-link <?= $currentPage === 'settings.php' ? 'active' : '' ?>">
                    <i class="bi bi-sliders"></i>
                    <span class="nav-link-text">System Settings</span>
                </a>
                <a href="<?= BASE_URL ?>/super_admin/audit_log.php"
                   class="nav-link nav-child-link <?= $currentPage === 'audit_log.php' ? 'active' : '' ?>">
                    <i class="bi bi-shield-lock-fill"></i>
                    <span class="nav-link-text">Audit Log</span>
                </a>
                <a href="<?= BASE_URL ?>/super_admin/archive.php"
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
            <div class="user-role">Super Admin</div>
        </div>
        <a href="<?= BASE_URL ?>/logout.php" class="sidebar-logout" title="Logout">
            <i class="bi bi-box-arrow-right"></i>
        </a>
    </div>
</aside>
