<?php
$currentPage = basename($_SERVER['PHP_SELF']);
$u = currentUser();
?>
<aside class="sidebar" id="sidebar">
    <!-- Map background overlay -->
    <div class="sidebar-map-bg"></div>

    <div class="sidebar-brand">
        <div class="brand-card">
            <div class="brand-inner">
                <div class="brand-logo-wrap">
                    <img src="<?= BASE_URL ?>/assets/img/logo 2.jpg" alt="Barangay Logo" class="brand-logo">
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
           target="_self"
           class="nav-link <?= $currentPage === 'dashboard.php' ? 'active' : '' ?>"
           data-bs-toggle="tooltip" data-bs-placement="right" title="Dashboard">
            <i class="bi bi-speedometer2"></i> <span class="nav-link-text">Dashboard</span>
        </a>
        <a href="<?= BASE_URL ?>/barangay_admin/forecasting.php"
           target="_self"
           class="nav-link <?= $currentPage === 'forecasting.php' ? 'active' : '' ?>"
           data-bs-toggle="tooltip" data-bs-placement="right" title="Forecasting">
            <i class="bi bi-cpu-fill"></i> <span class="nav-link-text">Forecasting</span>
        </a>
        <a href="<?= BASE_URL ?>/barangay_admin/analytics.php"
           target="_self"
           class="nav-link <?= $currentPage === 'analytics.php' ? 'active' : '' ?>"
           data-bs-toggle="tooltip" data-bs-placement="right" title="Analytics">
            <i class="bi bi-bar-chart-line-fill"></i> <span class="nav-link-text">Analytics</span>
        </a>

        <div class="nav-section-title">Records</div>
        <a href="<?= BASE_URL ?>/barangay_admin/residents.php"
           target="_self"
           class="nav-link <?= $currentPage === 'residents.php' ? 'active' : '' ?>"
           data-bs-toggle="tooltip" data-bs-placement="right" title="Residents">
            <i class="bi bi-people-fill"></i> <span class="nav-link-text">Residents</span>
        </a>
        <a href="<?= BASE_URL ?>/barangay_admin/households.php"
           target="_self"
           class="nav-link <?= $currentPage === 'households.php' ? 'active' : '' ?>"
           data-bs-toggle="tooltip" data-bs-placement="right" title="Households">
            <i class="bi bi-house-door-fill"></i> <span class="nav-link-text">Households</span>
        </a>
        <a href="<?= BASE_URL ?>/barangay_admin/officials.php"
           target="_self"
           class="nav-link <?= $currentPage === 'officials.php' ? 'active' : '' ?>"
           data-bs-toggle="tooltip" data-bs-placement="right" title="Officials">
            <i class="bi bi-person-badge-fill"></i> <span class="nav-link-text">Officials</span>
        </a>

        <div class="nav-section-title">Services</div>
        <a href="<?= BASE_URL ?>/barangay_admin/certificates.php"
           target="_self"
           class="nav-link <?= $currentPage === 'certificates.php' ? 'active' : '' ?>"
           data-bs-toggle="tooltip" data-bs-placement="right" title="Certificates">
            <i class="bi bi-file-earmark-text-fill"></i> <span class="nav-link-text">Certificates</span>
        </a>
        <a href="<?= BASE_URL ?>/barangay_admin/blotter.php"
           target="_self"
           class="nav-link <?= $currentPage === 'blotter.php' ? 'active' : '' ?>"
           data-bs-toggle="tooltip" data-bs-placement="right" title="Blotter">
            <i class="bi bi-shield-exclamation"></i> <span class="nav-link-text">Blotter</span>
        </a>
        <a href="<?= BASE_URL ?>/barangay_admin/reports.php"
           target="_self"
           class="nav-link <?= $currentPage === 'reports.php' ? 'active' : '' ?>"
           data-bs-toggle="tooltip" data-bs-placement="right" title="Reports">
            <i class="bi bi-file-earmark-bar-graph-fill"></i> <span class="nav-link-text">Reports</span>
        </a>
        <a href="<?= BASE_URL ?>/barangay_admin/notifications.php"
           target="_self"
           class="nav-link <?= $currentPage === 'notifications.php' ? 'active' : '' ?>"
           data-bs-toggle="tooltip" data-bs-placement="right" title="Notifications">
            <i class="bi bi-bell-fill"></i> <span class="nav-link-text">Notifications</span>
        </a>
        <a href="<?= BASE_URL ?>/barangay_admin/notices.php"
           target="_self"
           class="nav-link <?= $currentPage === 'notices.php' ? 'active' : '' ?>"
           data-bs-toggle="tooltip" data-bs-placement="right" title="Announcements">
            <i class="bi bi-megaphone-fill"></i> <span class="nav-link-text">Announcements</span>
        </a>

        <div class="nav-section-title">System</div>
        <a href="<?= BASE_URL ?>/barangay_admin/activity_log.php"
           target="_self"
           class="nav-link <?= $currentPage === 'activity_log.php' ? 'active' : '' ?>"
           data-bs-toggle="tooltip" data-bs-placement="right" title="Activity Log">
            <i class="bi bi-clock-history"></i> <span class="nav-link-text">Activity Log</span>
        </a>
        <a href="<?= BASE_URL ?>/barangay_admin/audit_log.php"
           target="_self"
           class="nav-link <?= $currentPage === 'audit_log.php' ? 'active' : '' ?>"
           data-bs-toggle="tooltip" data-bs-placement="right" title="Audit Log">
            <i class="bi bi-shield-check"></i> <span class="nav-link-text">Audit Log</span>
        </a>
        <a href="<?= BASE_URL ?>/barangay_admin/requests.php"
           target="_self"
           class="nav-link <?= in_array($currentPage, ['requests.php']) ? 'active' : '' ?>"
           data-bs-toggle="tooltip" data-bs-placement="right" title="Requests">
            <i class="bi bi-inbox-fill"></i> <span class="nav-link-text">Requests</span>
            <?php
            try {
                $_reqBid = (int)($u['barangay_id'] ?? 0);
                $_reqUid = (int)($u['id'] ?? 0);
                if ($_reqUid > 0) {
                    $_reqCount = (int)$db->prepare("SELECT COUNT(*) FROM user_requests WHERE requester_id=? AND status='pending'")->execute([$_reqUid]) ? (int)$db->query("SELECT COUNT(*) FROM user_requests WHERE requester_id=$_reqUid AND status='pending'")->fetchColumn() : 0;
                    if ($_reqCount > 0): ?>
                        <span class="badge bg-warning text-dark ms-auto" style="font-size:0.6rem;"><?= $_reqCount ?></span>
                    <?php endif;
                }
            } catch (Exception $e) {}
            ?>
        </a>
        <a href="<?= BASE_URL ?>/barangay_admin/archive.php"
           target="_self"
           class="nav-link <?= $currentPage === 'archive.php' ? 'active' : '' ?>"
           data-bs-toggle="tooltip" data-bs-placement="right" title="Archive">
            <i class="bi bi-archive-fill"></i> <span class="nav-link-text">Archive</span>
        </a>
        <a href="<?= BASE_URL ?>/barangay_admin/settings.php"
           target="_self"
           class="nav-link <?= $currentPage === 'settings.php' ? 'active' : '' ?>"
           data-bs-toggle="tooltip" data-bs-placement="right" title="Settings">
            <i class="bi bi-gear-fill"></i> <span class="nav-link-text">Settings</span>
        </a>
    </nav>

    <div class="sidebar-user">
        <?php
        $sidebarPhoto = $_SESSION['user_photo'] ?? null;
        // Fallback: query DB if session key not set yet (first load after upload)
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
