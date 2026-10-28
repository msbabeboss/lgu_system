<?php
require_once __DIR__ . '/../config/config.php';
requireRole('barangay_admin');

$db  = Database::getInstance()->getConnection();
$u   = currentUser();
$bid = (int)$u['barangay_id'];

// ── Safe migrations ────────────────────────────────────────
try { $db->exec("ALTER TABLE barangays ADD COLUMN IF NOT EXISTS region VARCHAR(100) NULL"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE barangays ADD COLUMN IF NOT EXISTS province VARCHAR(100) NULL"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE barangays ADD COLUMN IF NOT EXISTS zip_code VARCHAR(10) NULL"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE barangays ADD COLUMN IF NOT EXISTS logo_path VARCHAR(255) NULL"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS profile_photo VARCHAR(255) NULL"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS totp_secret VARCHAR(64) NULL"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS two_fa_enabled TINYINT(1) DEFAULT 0"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS login_attempts TINYINT UNSIGNED DEFAULT 0"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS locked_until DATETIME NULL"); } catch (PDOException $e) {}

// ── Ensure system_settings table exists ───────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS system_settings (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) NOT NULL UNIQUE,
        setting_val TEXT,
        updated_by INT UNSIGNED NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
} catch (PDOException $e) {}

// Seed defaults if not present
$defaults = [
    'login_max_attempts'     => '5',
    'login_lockout_seconds'  => '300',
    'idle_timeout_enabled'   => '1',
    'idle_timeout_minutes'   => '5',
    'idle_timeout_unit'      => 'minutes',
    'session_timeout_minutes'=> '60',
    'archive_auto_enabled'   => '0',
    'archive_after_months'   => '3',
    'archive_residents'      => '1',
    'archive_requests'       => '1',
    'archive_notices'        => '1',
    'notif_request_approved' => '1',
    'notif_request_rejected' => '1',
    'notif_request_done'     => '1',
    'notif_system_alerts'    => '1',
    'notif_email_enabled'    => '0',
];
foreach ($defaults as $k => $v) {
    $db->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_val) VALUES (?,?)")->execute([$k, $v]);
}

// ── Helpers ────────────────────────────────────────────────
function baSetting(PDO $db, string $key, string $default = ''): string {
    $s = $db->prepare("SELECT setting_val FROM system_settings WHERE setting_key=?");
    $s->execute([$key]);
    $r = $s->fetchColumn();
    return $r !== false ? $r : $default;
}
function baSaveSetting(PDO $db, string $key, string $val, int $userId): void {
    $db->prepare("INSERT INTO system_settings (setting_key, setting_val, updated_by)
        VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_val=VALUES(setting_val), updated_by=VALUES(updated_by)")
       ->execute([$key, $val, $userId]);
}

// ── Active tab ─────────────────────────────────────────────
$tab = $_GET['tab'] ?? 'profile';
$validTabs = ['profile','security','archive','authenticator'];
if (!in_array($tab, $validTabs)) $tab = 'profile';

$isFrame = isset($_GET['frame']) && $_GET['frame'] === '1';
$isAjax  = !empty($_GET['ajax'])  && $_GET['ajax']  === '1' && strtoupper($_SERVER['REQUEST_METHOD']) === 'GET';
$autoOpenChangePw = isset($_GET['action']) && $_GET['action'] === 'change_pw';

$flash = getFlash('ba_settings');

// ── Fresh self row ─────────────────────────────────────────
$selfStmt = $db->prepare("SELECT * FROM users WHERE id=?");
$selfStmt->execute([$u['id']]);
$self = $selfStmt->fetch();

$brgyStmt = $db->prepare("SELECT * FROM barangays WHERE id=?");
$brgyStmt->execute([$bid]);
$brgy = $brgyStmt->fetch() ?: [];

// ── POST Handlers ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrAbort();
    $action = $_POST['action'] ?? '';

    // ── Profile ────────────────────────────────────────────
    if ($action === 'save_profile') {
        $fname   = sanitize($_POST['first_name']  ?? '');
        $lname   = sanitize($_POST['last_name']   ?? '');
        $mname   = sanitize($_POST['middle_name'] ?? '');
        $email   = sanitize($_POST['email']       ?? '');
        $contact = sanitize($_POST['contact']     ?? '');
        $pos     = sanitize($_POST['position']    ?? '');
        if ($contact && !preg_match('/^09\d{9}$/', $contact)) {
            flash('ba_settings', 'Contact number must be 11 digits starting with 09.', 'danger');
        } else {
            $db->prepare("UPDATE users SET first_name=?,middle_name=?,last_name=?,email=?,contact_number=?,position=? WHERE id=?")
               ->execute([$fname, $mname, $lname, $email, $contact, $pos, $u['id']]);
            $_SESSION['user_name']  = "$fname $lname";
            $_SESSION['user_email'] = $email;
            logActivity($u['id'], 'UPDATE_PROFILE', 'Settings', 'Updated own profile');
            flash('ba_settings', 'Profile updated successfully.', 'success');
        }
        redirect(BASE_URL . '/barangay_admin/settings.php?tab=profile');
    }

    // ── Profile Photo Upload ───────────────────────────────
    if ($action === 'save_profile_photo') {
        $photoDir = UPLOAD_PATH . 'profile_photos/';
        if (!is_dir($photoDir)) mkdir($photoDir, 0755, true);

        if (($_POST['remove_photo'] ?? '0') === '1') {
            // Remove existing photo
            $cur = $db->prepare("SELECT profile_photo FROM users WHERE id=?");
            $cur->execute([$u['id']]);
            $old = $cur->fetchColumn();
            if ($old && file_exists($photoDir . $old)) @unlink($photoDir . $old);
            $db->prepare("UPDATE users SET profile_photo=NULL WHERE id=?")->execute([$u['id']]);
            $_SESSION['user_photo'] = null;
            logActivity($u['id'], 'REMOVE_PROFILE_PHOTO', 'Settings', 'Removed profile photo');
            flash('ba_settings', 'Profile photo removed.', 'info');
        } elseif (!empty($_FILES['profile_photo']['name']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
            $file    = $_FILES['profile_photo'];
            $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','jfif','png','gif','webp'];
            $finfo   = finfo_open(FILEINFO_MIME_TYPE);
            $mime    = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            $allowedMime = ['image/jpeg','image/png','image/gif','image/webp'];

            if (!in_array($ext, $allowed) || !in_array($mime, $allowedMime)) {
                flash('ba_settings', 'Invalid file type. Only JPG, PNG, GIF, and WEBP are allowed.', 'danger');
            } elseif ($file['size'] > 2 * 1024 * 1024) {
                flash('ba_settings', 'File too large. Maximum allowed size is 2 MB.', 'danger');
            } else {
                // Delete old photo
                $cur = $db->prepare("SELECT profile_photo FROM users WHERE id=?");
                $cur->execute([$u['id']]);
                $old = $cur->fetchColumn();
                if ($old && file_exists($photoDir . $old)) @unlink($photoDir . $old);

                // Resize to 200×200px max and save as WebP (falls back to JPEG if WebP unavailable)
                $baseName = 'user_' . $u['id'] . '_' . time();
                try {
                    $fname = resizeAndSaveImage($file['tmp_name'], $mime, $photoDir, $baseName, 200, 200, 85);
                    $db->prepare("UPDATE users SET profile_photo=? WHERE id=?")->execute([$fname, $u['id']]);
                    $_SESSION['user_photo'] = $fname;
                    logActivity($u['id'], 'UPDATE_PROFILE_PHOTO', 'Settings', 'Updated profile photo');
                    flash('ba_settings', 'Profile photo updated successfully.', 'success');
                } catch (\Throwable $e) {
                    flash('ba_settings', 'Failed to save the uploaded file. Please try again.', 'danger');
                }
            }
        }
        redirect(BASE_URL . '/barangay_admin/settings.php?tab=profile');
    }

    // ── Change Password (AJAX) ─────────────────────────────
    if ($action === 'change_password_ajax') {
        header('Content-Type: application/json');
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        if (!$current || !$new || !$confirm) {
            echo json_encode(['ok'=>false,'msg'=>'All fields are required.']); exit;
        }
        if ($new !== $confirm) {
            echo json_encode(['ok'=>false,'msg'=>'New passwords do not match.']); exit;
        }
        $policyErr = validatePasswordPolicy($db, $new);
        if ($policyErr) {
            echo json_encode(['ok'=>false,'msg'=>$policyErr]); exit;
        }
        $row = $db->prepare("SELECT password FROM users WHERE id=?");
        $row->execute([$u['id']]); $row = $row->fetch();
        if (!$row || !password_verify($current, $row['password'])) {
            echo json_encode(['ok'=>false,'msg'=>'Current password is incorrect.']); exit;
        }
        $db->prepare("UPDATE users SET password=?,must_change_password=0 WHERE id=?")
           ->execute([password_hash($new, PASSWORD_BCRYPT, ['cost'=>12]), $u['id']]);
        logActivity($u['id'], 'CHANGE_PASSWORD', 'Auth', 'Password changed via Settings');
        echo json_encode(['ok'=>true,'msg'=>'Password changed successfully. You will be logged out.']);
        exit;
    }

    // ── Security ───────────────────────────────────────────
    if ($action === 'save_security') {
        $maxAttempts   = max(1,  min(20,    (int)($_POST['login_max_attempts']    ?? 5)));
        $lockoutSecs   = max(30, min(86400, (int)($_POST['login_lockout_seconds'] ?? 300)));
        $idleEnabled   = isset($_POST['idle_timeout_enabled']) ? '1' : '0';
        $idleUnit      = in_array($_POST['idle_timeout_unit'] ?? '', ['seconds','minutes'])
                            ? $_POST['idle_timeout_unit'] : 'minutes';
        $idleVal       = (int)($_POST['idle_timeout_minutes'] ?? 5);
        // Clamp based on unit
        if ($idleUnit === 'seconds') {
            $idleVal = max(10, min(300, $idleVal));
        } else {
            $idleVal = max(1,  min(120, $idleVal));
        }

        baSaveSetting($db, 'login_max_attempts',    (string)$maxAttempts, $u['id']);
        baSaveSetting($db, 'login_lockout_seconds', (string)$lockoutSecs, $u['id']);
        baSaveSetting($db, 'idle_timeout_enabled',   $idleEnabled,         $u['id']);
        baSaveSetting($db, 'idle_timeout_minutes',   (string)$idleVal,     $u['id']);
        baSaveSetting($db, 'idle_timeout_unit',      $idleUnit,            $u['id']);

        logActivity($u['id'], 'UPDATE_SETTINGS', 'Settings', 'Updated Security settings');
        flash('ba_settings', 'Security settings saved.', 'success');
        redirect(BASE_URL . '/barangay_admin/settings.php?tab=security');
    }

    // ── Archive ────────────────────────────────────────────
    if ($action === 'save_archive') {
        baSaveSetting($db, 'archive_auto_enabled', isset($_POST['archive_auto_enabled']) ? '1' : '0', $u['id']);
        $months = max(1, min(60, (int)($_POST['archive_after_months'] ?? 3)));
        baSaveSetting($db, 'archive_after_months', (string)$months, $u['id']);
        foreach (['archive_residents','archive_requests','archive_notices'] as $k) {
            baSaveSetting($db, $k, isset($_POST[$k]) ? '1' : '0', $u['id']);
        }
        logActivity($u['id'], 'UPDATE_SETTINGS', 'Settings', 'Updated Archive settings');
        flash('ba_settings', 'Archive settings saved.', 'success');
        redirect(BASE_URL . '/barangay_admin/settings.php?tab=archive');
    }

    // ── Preferences ────────────────────────────────────────
    if ($action === 'save_preferences') {
        $keys = ['notif_request_approved','notif_request_rejected','notif_request_done',
                 'notif_system_alerts','notif_email_enabled'];
        foreach ($keys as $k) {
            baSaveSetting($db, $k, isset($_POST[$k]) ? '1' : '0', $u['id']);
        }
        logActivity($u['id'], 'UPDATE_SETTINGS', 'Settings', 'Updated notification preferences');
        flash('ba_settings', 'Notification preferences saved.', 'success');
        redirect(BASE_URL . '/barangay_admin/settings.php?tab=preferences');
    }

    // ── 2FA: Generate secret ───────────────────────────────
    if ($action === 'generate_2fa') {
        require_once BASE_PATH . '/helpers/totp.php';
        $secret = TOTP::generateSecret();
        $db->prepare("UPDATE users SET totp_secret=? WHERE id=?")->execute([$secret, $u['id']]);
        flash('ba_settings', 'Scan the QR code with your authenticator app, then verify to enable 2FA.', 'info');
        redirect(BASE_URL . '/barangay_admin/settings.php?tab=authenticator');
    }

    // ── 2FA: Enable (verify code) ──────────────────────────
    if ($action === 'enable_2fa') {
        require_once BASE_PATH . '/helpers/totp.php';
        // Re-fetch fresh
        $fresh = $db->prepare("SELECT * FROM users WHERE id=?");
        $fresh->execute([$u['id']]); $fresh = $fresh->fetch();
        $code   = trim($_POST['code'] ?? '');
        $secret = $fresh['totp_secret'] ?? '';
        if (!$secret) {
            flash('ba_settings', 'No secret found. Please generate a QR code first.', 'danger');
        } elseif (!TOTP::verify($secret, $code)) {
            flash('ba_settings', 'Invalid code. Make sure your authenticator app is synced.', 'danger');
        } else {
            $db->prepare("UPDATE users SET two_fa_enabled=1 WHERE id=?")->execute([$u['id']]);
            logActivity($u['id'], 'ENABLE_2FA', 'Security', '2FA enabled');
            flash('ba_settings', '✅ Two-Factor Authentication has been enabled on your account.', 'success');
        }
        redirect(BASE_URL . '/barangay_admin/settings.php?tab=authenticator');
    }

    // ── 2FA: Disable ──────────────────────────────────────
    if ($action === 'disable_2fa') {
        $db->prepare("UPDATE users SET two_fa_enabled=0, totp_secret=NULL WHERE id=?")->execute([$u['id']]);
        logActivity($u['id'], 'DISABLE_2FA', 'Security', '2FA disabled');
        flash('ba_settings', '2FA has been disabled on your account.', 'warning');
        redirect(BASE_URL . '/barangay_admin/settings.php?tab=authenticator');
    }

    // ── Barangay Logo Upload ───────────────────────────────
    if ($action === 'save_barangay_logo') {
        $logoDir = UPLOAD_PATH . 'barangay_logos/';
        if (!is_dir($logoDir)) mkdir($logoDir, 0755, true);

        if (!empty($_FILES['barangay_logo']['name']) && $_FILES['barangay_logo']['error'] === UPLOAD_ERR_OK) {
            $file    = $_FILES['barangay_logo'];
            $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','gif','webp'];

            // MIME check
            $finfo    = finfo_open(FILEINFO_MIME_TYPE);
            $mime     = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            $allowedMime = ['image/jpeg','image/png','image/gif','image/webp'];

            if (!in_array($ext, $allowed) || !in_array($mime, $allowedMime)) {
                flash('ba_settings', 'Invalid file type. Only JPG, PNG, GIF, and WEBP are allowed.', 'danger');
            } elseif ($file['size'] > 2 * 1024 * 1024) {
                flash('ba_settings', 'File too large. Maximum allowed size is 2 MB.', 'danger');
            } else {
                // Delete old logo if exists
                $oldLogo = $brgy['logo_path'] ?? '';
                if ($oldLogo && file_exists($logoDir . $oldLogo)) {
                    @unlink($logoDir . $oldLogo);
                }

                $fname = 'brgy_' . $bid . '_' . time() . '.' . $ext;
                if (move_uploaded_file($file['tmp_name'], $logoDir . $fname)) {
                    $db->prepare("UPDATE barangays SET logo_path=? WHERE id=?")
                       ->execute([$fname, $bid]);
                    logActivity($u['id'], 'UPDATE_BARANGAY_LOGO', 'Settings', 'Updated barangay logo');
                    flash('ba_settings', 'Barangay logo updated successfully.', 'success');
                } else {
                    flash('ba_settings', 'Failed to save the uploaded file. Please try again.', 'danger');
                }
            }
        } elseif (($_POST['remove_logo'] ?? '0') === '1') {
            $oldLogo = $brgy['logo_path'] ?? '';
            if ($oldLogo && file_exists($logoDir . $oldLogo)) {
                @unlink($logoDir . $oldLogo);
            }
            $db->prepare("UPDATE barangays SET logo_path=NULL WHERE id=?")->execute([$bid]);
            logActivity($u['id'], 'REMOVE_BARANGAY_LOGO', 'Settings', 'Removed barangay logo');
            flash('ba_settings', 'Barangay logo removed.', 'info');
        }

        // Re-fetch brgy after update
        $brgyStmt->execute([$bid]);
        $brgy = $brgyStmt->fetch() ?: [];
        redirect(BASE_URL . '/barangay_admin/settings.php?tab=profile');
    }
}

// ── Re-fetch self after POST ───────────────────────────────
$selfStmt->execute([$u['id']]);
$self = $selfStmt->fetch();

// ── Shell: output full-page shell with iframe, then exit ──────────────
if (!$isFrame && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $curTab = in_array($tab, $validTabs) ? $tab : 'profile';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favcon.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(generateCsrfToken()) ?>">
    <title>Settings — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=3.0">
    <style>
        .main-content { animation: none !important; contain: none !important; padding: 20px 24px 0 22px !important; overflow: hidden; }
        .settings-shell-wrap { display: flex; gap: 24px; align-items: flex-start; height: calc(100vh - var(--topbar-height, 80px) - 20px); overflow-y: auto; }
        .settings-sidebar { width: 220px; min-width: 220px; flex-shrink: 0; background: rgba(255,255,255,0.7); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); border: 1px solid rgba(255,255,255,0.8); border-radius: 16px; padding: 12px 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); align-self: flex-start; position: sticky; top: 0; max-height: calc(100vh - var(--topbar-height, 80px) - 20px); overflow-y: auto; transform: translateZ(0); backface-visibility: hidden; isolation: isolate; }
        .settings-sidebar .s-title { font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #3a7fa8; padding: 6px 12px 4px; }
        .settings-sidebar .s-link { display: flex; align-items: center; gap: 10px; padding: 9px 14px; border-radius: 10px; font-size: 0.875rem; font-weight: 500; color: #2a5070; text-decoration: none; transition: background 0.15s, color 0.15s; margin-bottom: 2px; }
        .settings-sidebar .s-link i { font-size: 1rem; width: 20px; text-align: center; }
        .settings-sidebar .s-link:hover { background: rgba(26,79,122,0.07); color: #1a4f7a; }
        .settings-sidebar .s-link.active { background: linear-gradient(90deg, #1a4f7a 0%, #3a7fa8 100%); color: #fff; box-shadow: 0 3px 10px rgba(26,79,122,0.25); }
        .settings-sidebar .s-link.active i { color: #fff; }
        #settingsFrame { flex: 1; border: none; min-width: 0; width: 100%; height: calc(100vh - var(--topbar-height, 80px) - 20px); background: transparent; display: block; overflow: hidden; }
        @media (max-width: 768px) { .settings-shell-wrap { flex-direction: column; height: auto; } .settings-sidebar { position: static; width: 100%; max-height: unset; } #settingsFrame { height: 80vh; } }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/sidebar_barangay.php'; ?>
<?php include __DIR__ . '/../includes/topbar.php'; ?>
<div class="main-content">
    <div class="settings-shell-wrap">
        <nav class="settings-sidebar">
            <div class="s-title">Settings</div>
            <?php
            $shellNavItems = [
                'profile'       => ['bi-person-fill',       'Profile'],
                'security'      => ['bi-shield-lock-fill',  'Security'],
                'archive'       => ['bi-archive-fill',      'Archive'],
                'authenticator' => ['bi-shield-check-fill', 'Authenticator'],
            ];
            foreach ($shellNavItems as $t => [$icon, $label]):
            ?>
            <a href="#" class="s-link <?= $curTab === $t ? 'active' : '' ?>" onclick="switchTab('<?= $t ?>');return false;">
                <i class="bi <?= $icon ?>"></i> <?= $label ?>
            </a>
            <?php endforeach; ?>
            <div class="s-title mt-3">Account</div>
            <a href="#" class="s-link" onclick="openCPModal();return false;">
                <i class="bi bi-key-fill"></i> Change Password
            </a>
            <a href="<?= BASE_URL ?>/barangay_admin/audit_log.php" class="s-link">
                <i class="bi bi-shield-check-fill"></i> Audit Log
            </a>
        </nav>
        <iframe id="settingsFrame"
                src="<?= BASE_URL ?>/barangay_admin/settings.php?tab=<?= urlencode($curTab) ?>&frame=1"
                frameborder="0"
                scrolling="yes">
        </iframe>
    </div>
</div>

<!-- ══ Change Password Modal (shell) ══════════════════════ -->
<div id="changePasswordModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.5);backdrop-filter:blur(4px);align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:20px;width:100%;max-width:460px;box-shadow:0 20px 60px rgba(0,0,0,0.3);overflow:hidden;margin:16px;">
        <div style="background:linear-gradient(135deg,#1a4f7a,#3a7fa8);padding:22px 28px;display:flex;align-items:center;justify-content:space-between;">
            <div class="d-flex align-items-center gap-3">
                <div style="width:40px;height:40px;background:rgba(255,255,255,0.15);border-radius:10px;display:flex;align-items:center;justify-content:center;">
                    <i class="bi bi-key-fill text-white fs-5"></i>
                </div>
                <div>
                    <div class="fw-bold text-white" style="font-size:1rem;">Change Password</div>
                    <div style="font-size:0.75rem;color:rgba(255,255,255,0.7);">Update your account password</div>
                </div>
            </div>
            <button onclick="closeCPModal()" style="background:rgba(255,255,255,0.15);border:none;border-radius:8px;width:32px;height:32px;color:#fff;font-size:1.1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div style="padding:28px;">
            <div id="cpAlert" style="display:none;padding:10px 14px;border-radius:8px;font-size:0.85rem;margin-bottom:16px;align-items:center;gap:8px;"></div>
            <div class="mb-3">
                <label class="form-label fw-semibold small">Current Password <span class="text-danger">*</span></label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                    <input type="password" id="cpCurrent" class="form-control" placeholder="Enter current password">
                    <button type="button" class="btn btn-outline-secondary" onclick="toggleCPVis('cpCurrent','cpEye1')"><i class="bi bi-eye" id="cpEye1"></i></button>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold small">New Password <span class="text-danger">*</span></label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                    <input type="password" id="cpNew" class="form-control" placeholder="Min 8 chars, letters + numbers" oninput="cpStrength(this.value)">
                    <button type="button" class="btn btn-outline-secondary" onclick="toggleCPVis('cpNew','cpEye2')"><i class="bi bi-eye" id="cpEye2"></i></button>
                </div>
                <div style="background:#e9ecef;border-radius:2px;height:4px;margin-top:6px;"><div id="cpStrBar" style="height:4px;border-radius:2px;width:0%;transition:width .3s,background .3s;"></div></div>
                <small id="cpStrLabel" style="font-size:0.72rem;color:#6c757d;"></small>
            </div>
            <div class="mb-4">
                <label class="form-label fw-semibold small">Confirm New Password <span class="text-danger">*</span></label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                    <input type="password" id="cpConfirm" class="form-control" placeholder="Repeat new password">
                    <button type="button" class="btn btn-outline-secondary" onclick="toggleCPVis('cpConfirm','cpEye3')"><i class="bi bi-eye" id="cpEye3"></i></button>
                </div>
            </div>
            <div id="cpConfirmStep" style="display:none;background:#fff8e1;border:1px solid #ffe082;border-radius:10px;padding:14px 16px;margin-bottom:16px;">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <i class="bi bi-exclamation-triangle-fill text-warning"></i>
                    <span class="fw-semibold small">Are you sure you want to change your password?</span>
                </div>
                <p class="text-muted small mb-3">You will be logged out automatically after the password is changed.</p>
                <div class="d-flex gap-2">
                    <button class="btn btn-danger btn-sm px-4" onclick="doChangePassword()"><i class="bi bi-check-lg me-1"></i>Yes, Change It</button>
                    <button class="btn btn-outline-secondary btn-sm" onclick="document.getElementById('cpConfirmStep').style.display='none';document.getElementById('cpSubmitBtn').style.display='block';">Cancel</button>
                </div>
            </div>
            <button id="cpSubmitBtn" class="btn btn-primary w-100 fw-semibold" onclick="showCPConfirm()"><i class="bi bi-key me-2"></i>Change Password</button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('show'); }

var _curTab = '<?= $curTab ?>';
function switchTab(tab) {
    _curTab = tab;
    document.querySelectorAll('.settings-sidebar .s-link[onclick]').forEach(function(el) {
        var m = el.getAttribute('onclick').match(/switchTab\('([^']+)'\)/);
        if (m) el.classList.toggle('active', m[1] === tab);
    });
    document.getElementById('settingsFrame').src = '<?= BASE_URL ?>/barangay_admin/settings.php?tab=' + encodeURIComponent(tab) + '&frame=1';
}

function autoResizeFrame() {
    var frame = document.getElementById('settingsFrame');
    try {
        var h = frame.contentWindow.document.documentElement.scrollHeight;
        if (h > 200) frame.style.height = h + 'px';
    } catch(e) {}
}
document.getElementById('settingsFrame').addEventListener('load', function() {
    setTimeout(autoResizeFrame, 80);
});

function openCPModal()  { document.getElementById('changePasswordModal').style.display = 'flex'; }
function closeCPModal() {
    document.getElementById('changePasswordModal').style.display = 'none';
    ['cpCurrent','cpNew','cpConfirm'].forEach(function(id){ document.getElementById(id).value = ''; });
    document.getElementById('cpAlert').style.display = 'none';
    document.getElementById('cpConfirmStep').style.display = 'none';
    document.getElementById('cpSubmitBtn').style.display = 'block';
    document.getElementById('cpStrBar').style.width = '0%';
    document.getElementById('cpStrLabel').textContent = '';
}
document.getElementById('changePasswordModal').addEventListener('click', function(e) { if (e.target === this) closeCPModal(); });
function toggleCPVis(inputId, iconId) {
    var el = document.getElementById(inputId), ic = document.getElementById(iconId);
    el.type = el.type === 'password' ? 'text' : 'password';
    ic.className = el.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
}
function cpStrength(val) {
    var bar = document.getElementById('cpStrBar'), lbl = document.getElementById('cpStrLabel');
    var score = 0;
    if (val.length >= 8) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;
    if (val.length >= 12) score++;
    var lvls = [{w:'20%',bg:'#ef4444',t:'Very weak'},{w:'40%',bg:'#f97316',t:'Weak'},{w:'60%',bg:'#eab308',t:'Fair'},{w:'80%',bg:'#22c55e',t:'Strong'},{w:'100%',bg:'#16a34a',t:'Very strong'}];
    var lvl = lvls[Math.min(score - 1, 4)];
    if (val && lvl) { bar.style.width = lvl.w; bar.style.background = lvl.bg; lbl.textContent = lvl.t; lbl.style.color = lvl.bg; }
    else { bar.style.width = '0%'; lbl.textContent = ''; }
}
function showCPConfirm() {
    var cur = document.getElementById('cpCurrent').value;
    var nw  = document.getElementById('cpNew').value;
    var cf  = document.getElementById('cpConfirm').value;
    if (!cur || !nw || !cf) { showCPAlert('All fields are required.', 'danger'); return; }
    if (nw !== cf) { showCPAlert('New passwords do not match.', 'danger'); return; }
    document.getElementById('cpAlert').style.display = 'none';
    document.getElementById('cpSubmitBtn').style.display = 'none';
    document.getElementById('cpConfirmStep').style.display = 'block';
}
function showCPAlert(msg, type) {
    var al = document.getElementById('cpAlert');
    var colors = {success:'#d1fae5',danger:'#fee2e2',warning:'#fef3c7',info:'#dbeafe'};
    var textColors = {success:'#065f46',danger:'#991b1b',warning:'#92400e',info:'#1e40af'};
    al.style.cssText = 'display:flex;background:'+colors[type]+';color:'+textColors[type]+';border:1px solid '+textColors[type]+'33;padding:10px 14px;border-radius:8px;font-size:0.85rem;margin-bottom:16px;align-items:center;gap:8px;';
    al.innerHTML = '<i class="bi bi-'+(type==='success'?'check-circle':'exclamation-triangle')+'-fill"></i>'+msg;
}
function doChangePassword() {
    var btn = document.querySelector('#cpConfirmStep .btn-danger');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving…';
    fetch('<?= BASE_URL ?>/barangay_admin/settings.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=change_password_ajax'
            + '&current_password=' + encodeURIComponent(document.getElementById('cpCurrent').value)
            + '&new_password='     + encodeURIComponent(document.getElementById('cpNew').value)
            + '&confirm_password=' + encodeURIComponent(document.getElementById('cpConfirm').value)
            + '&csrf_token='       + encodeURIComponent(document.querySelector('meta[name="csrf-token"]')?.content || '')
    })
    .then(function(r){ return r.json(); })
    .then(function(data) {
        if (data.ok) {
            showCPAlert(data.msg, 'success');
            document.getElementById('cpConfirmStep').style.display = 'none';
            setTimeout(function(){ window.location.href = '<?= BASE_URL ?>/logout.php'; }, 1800);
        } else {
            showCPAlert(data.msg, 'danger');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Yes, Change It';
            document.getElementById('cpConfirmStep').style.display = 'none';
            document.getElementById('cpSubmitBtn').style.display = 'block';
        }
    })
    .catch(function() {
        showCPAlert('An error occurred. Please try again.', 'danger');
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Yes, Change It';
    });
}
</script>
</body>
</html>
<?php
    exit;
}

$pageIcon     = 'bi-gear-fill';
$pageTitle    = 'Settings';
$pageSubtitle = 'Manage your account and barangay configuration.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favcon.png">
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favcon.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=3.0">
    <style>
        .settings-wrap {
            display: flex;
            gap: 24px;
            align-items: flex-start;
        }
        .settings-sidebar {
            width: 220px;
            min-width: 220px;
            background: rgba(255,255,255,0.7);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255,255,255,0.8);
            border-radius: 16px;
            padding: 12px 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            position: sticky;
            top: 96px;
        }
        .settings-sidebar .s-title {
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #3a7fa8;
            padding: 6px 12px 4px;
        }
        .settings-sidebar .s-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 14px;
            border-radius: 10px;
            font-size: 0.875rem;
            font-weight: 500;
            color: #2a5070;
            text-decoration: none;
            transition: background 0.15s, color 0.15s;
            margin-bottom: 2px;
        }
        .settings-sidebar .s-link i { font-size: 1rem; width: 20px; text-align: center; }
        .settings-sidebar .s-link:hover { background: rgba(26,79,122,0.07); color: #1a4f7a; }
        .settings-sidebar .s-link.active {
            background: linear-gradient(90deg, #1a4f7a 0%, #3a7fa8 100%);
            color: #fff;
            box-shadow: 0 3px 10px rgba(26,79,122,0.25);
        }
        .settings-sidebar .s-link.active i { color: #fff; }
        .settings-content { flex: 1; min-width: 0; }
        .settings-card {
            background: rgba(255,255,255,0.72);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            border: 1px solid rgba(255,255,255,0.8);
            border-radius: 16px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.09);
            margin-bottom: 20px;
            overflow: hidden;
        }
        .settings-card .sc-header {
            padding: 16px 22px 14px;
            border-bottom: 1px solid rgba(0,0,0,0.06);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .settings-card .sc-header .sc-icon {
            width: 36px; height: 36px;
            background: linear-gradient(135deg, #1a4f7a, #3a7fa8);
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-size: 1rem;
            flex-shrink: 0;
        }
        .settings-card .sc-header h6 { margin: 0; font-weight: 700; font-size: 0.95rem; }
        .settings-card .sc-header p  { margin: 0; font-size: 0.75rem; color: #3a7fa8; }
        .settings-card .sc-body { padding: 22px; }
        .toggle-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid rgba(0,0,0,0.05);
        }
        .toggle-row:last-child { border-bottom: none; }
        .toggle-row .tr-label { font-size: 0.875rem; font-weight: 500; }
        .toggle-row .tr-desc  { font-size: 0.75rem; color: #3a7fa8; }
        .form-check-input:checked { background-color: #1a4f7a; border-color: #1a4f7a; }
        @media (max-width: 768px) {
            .settings-wrap { flex-direction: column; }
            .settings-sidebar { width: 100%; min-width: unset; position: static; }
        }
        <?php if ($isFrame): ?>
        html { background: linear-gradient(160deg, #daeeff 0%, #c8e4f8 40%, #b8d8f0 70%, #a4caea 100%) fixed !important; min-height: 100%; }
        body { background: transparent !important; overflow-x: hidden; overflow-y: auto; }
        .sidebar, .topbar { display: none !important; }
        .main-content { margin-left: 0 !important; margin-top: 0 !important; padding: 20px 22px 32px 22px !important; min-height: 100vh; background: transparent !important; overflow: visible !important; }
        .settings-sidebar { display: none !important; }
        .settings-wrap { display: block !important; }
        .settings-content { display: block !important; margin-left: 0 !important; }
        <?php endif; ?>
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/sidebar_barangay.php'; ?>
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<div class="main-content">

    <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show mb-3">
        <i class="bi bi-<?= $flash['type']==='success'?'check-circle':'exclamation-triangle' ?>-fill me-2"></i>
        <?= $flash['message'] ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="settings-wrap">

        <!-- ── Settings Sidebar ──────────────────────────── -->
        <nav class="settings-sidebar">
            <div class="s-title">Settings</div>
            <?php
            $navItems = [
                'profile'       => ['bi-person-fill',       'Profile'],
                'security'      => ['bi-shield-lock-fill',  'Security'],
                'archive'       => ['bi-archive-fill',       'Archive'],
                'authenticator' => ['bi-shield-check-fill', 'Authenticator'],
                'preferences'   => ['bi-bell-fill',         'Preferences'],
            ];
            foreach ($navItems as $t => [$icon, $label]):
            ?>
            <a href="?tab=<?= $t ?>" class="s-link <?= $tab===$t ? 'active' : '' ?>">
                <i class="bi <?= $icon ?>"></i> <?= $label ?>
            </a>
            <?php endforeach; ?>

            <div class="s-title mt-3">Account</div>
            <a href="#" class="s-link" onclick="openCPModal();return false;">
                <i class="bi bi-key-fill"></i> Change Password
            </a>
        </nav>

        <!-- ── Settings Content ─────────────────────────── -->
        <div class="settings-content">

<?php /* ══════════════════════════════════════════
   TAB: PROFILE
══════════════════════════════════════════ */ ?>
<?php if ($tab === 'profile'): ?>

            <!-- Identity Card -->
            <div class="settings-card">
                <div class="sc-header">
                    <div class="sc-icon"><i class="bi bi-person-fill"></i></div>
                    <div>
                        <h6>My Profile</h6>
                        <p>View and update your personal information</p>
                    </div>
                </div>
                <div class="sc-body">
                    <?php
                    $myPhoto    = $self['profile_photo'] ?? '';
                    $myPhotoUrl = $myPhoto ? UPLOAD_URL . 'profile_photos/' . $myPhoto : '';
                    ?>
                    <!-- Avatar strip with inline photo upload -->
                    <div class="d-flex align-items-center gap-4 mb-4 p-3 rounded-3" style="background:rgba(26,79,122,0.05);">

                        <!-- Photo + edit overlay — wraps both the upload form AND the remove form -->
                        <form method="POST" enctype="multipart/form-data" id="photoUploadForm">
                            <input type="hidden" name="action" value="save_profile_photo">
                            <?= csrfField() ?>
                            <!-- Hidden file input — triggered by the pencil button -->
                            <input type="file" name="profile_photo" id="profilePhotoInput"
                                   accept="image/jpeg,.jfif,image/png,image/gif,image/webp"
                                   style="display:none;"
                                   onchange="autoSubmitPhoto(this)">

                            <div style="position:relative;width:90px;height:90px;flex-shrink:0;">
                                <!-- Photo circle -->
                                <div id="photoPreviewWrap" style="width:90px;height:90px;border-radius:12px;overflow:hidden;border:2px solid rgba(26,79,122,0.2);background:#e8f0f7;">
                                    <?php if ($myPhotoUrl): ?>
                                        <img id="photoPreview" src="<?= htmlspecialchars($myPhotoUrl) ?>"
                                             alt="Profile Photo"
                                             style="width:100%;height:100%;object-fit:cover;">
                                    <?php else: ?>
                                        <img id="photoPreview" src="" alt=""
                                             style="width:100%;height:100%;object-fit:cover;display:none;">
                                        <div id="photoInitials"
                                             style="width:100%;height:100%;background:linear-gradient(135deg,#1a4f7a,#3a7fa8);display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.8rem;font-weight:700;">
                                            <?= strtoupper(substr($self['first_name'],0,1).substr($self['last_name'],0,1)) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <!-- Pencil edit button -->
                                <button type="button"
                                        onclick="document.getElementById('profilePhotoInput').click()"
                                        title="Change photo"
                                        style="position:absolute;bottom:-6px;right:-6px;width:28px;height:28px;border-radius:50%;background:#1a4f7a;border:2px solid #fff;color:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 2px 6px rgba(0,0,0,0.25);font-size:0.75rem;">
                                    <i class="bi bi-pencil-fill"></i>
                                </button>
                            </div>
                            <div id="photoSizeErr" class="text-danger mt-1" style="display:none;font-size:0.72rem;"></div>
                        </form>

                        <div class="flex-grow-1">
                            <div class="fw-bold fs-6"><?= htmlspecialchars($self['first_name'].' '.$self['last_name']) ?></div>
                            <div class="small text-muted"><?= htmlspecialchars($self['email']) ?></div>
                            <span class="badge mt-1" style="background:rgba(26,79,122,0.1);color:#1a4f7a;font-size:0.7rem;">
                                <i class="bi bi-shield-fill-check me-1"></i>Barangay Admin
                            </span>
                            <?php if ($myPhoto): ?>
                            <div class="mt-2">
                                <form method="POST" class="d-inline"
                                      onsubmit="return confirm('Remove your profile photo?');">
                                    <input type="hidden" name="action" value="save_profile_photo">
                                    <input type="hidden" name="remove_photo" value="1">
                                    <?= csrfField() ?>
                                    <button type="submit" class="btn btn-sm btn-outline-danger"
                                            style="border-radius:6px;font-size:0.72rem;padding:2px 10px;">
                                        <i class="bi bi-trash3 me-1"></i>Remove Photo
                                    </button>
                                </form>
                            </div>
                            <?php endif; ?>
                            <div class="mt-1" style="font-size:0.7rem;color:#94a3b8;">
                                <i class="bi bi-pencil-fill me-1"></i>Click the pencil icon to change photo &nbsp;·&nbsp; JPG, PNG, WEBP, GIF &nbsp;·&nbsp; Max 2 MB
                            </div>
                        </div>
                    </div>

                    <form method="POST">
                        <input type="hidden" name="action" value="save_profile">
                        <?= csrfField() ?>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small">First Name <span class="text-danger">*</span></label>
                                <input type="text" name="first_name" class="form-control" required
                                       value="<?= htmlspecialchars($self['first_name']) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small">Middle Name</label>
                                <input type="text" name="middle_name" class="form-control"
                                       value="<?= htmlspecialchars($self['middle_name'] ?? '') ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small">Last Name <span class="text-danger">*</span></label>
                                <input type="text" name="last_name" class="form-control" required
                                       value="<?= htmlspecialchars($self['last_name']) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">Email Address <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                    <input type="email" name="email" class="form-control" required
                                           value="<?= htmlspecialchars($self['email']) ?>">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold small">Contact Number</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-phone"></i></span>
                                    <input type="text" name="contact" id="profContact" class="form-control"
                                           value="<?= htmlspecialchars($self['contact_number'] ?? '') ?>"
                                           placeholder="09XXXXXXXXX" maxlength="11"
                                           inputmode="numeric" pattern="09[0-9]{9}"
                                           oninput="validateContact(this,'profContactErr')">
                                </div>
                                <div id="profContactErr" class="text-danger mt-1" style="display:none;font-size:0.72rem;"></div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold small">Position / Title</label>
                                <input type="text" name="position" class="form-control"
                                       value="<?= htmlspecialchars($self['position'] ?? '') ?>"
                                       placeholder="e.g. Barangay Secretary">
                            </div>
                        </div>
                        <div class="d-flex justify-content-end mt-3">
                            <button type="submit" class="btn btn-primary px-4 fw-semibold" style="border-radius:8px;">
                                <i class="bi bi-check-lg me-1"></i>Save Profile
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Barangay Info (read-only) -->
            <?php if ($brgy): ?>
            <div class="settings-card">
                <div class="sc-header">
                    <div class="sc-icon" style="background:linear-gradient(135deg,#059669,#047857);">
                        <i class="bi bi-geo-alt-fill"></i>
                    </div>
                    <div>
                        <h6>Barangay Information</h6>
                        <p>Managed by the Super Admin — contact LGU to update</p>
                    </div>
                </div>
                <div class="sc-body">
                    <div class="row g-3">
                        <?php
                        $addrRows = [
                            ['Region',              $brgy['region']        ?? '—'],
                            ['Province',            $brgy['province']      ?? '—'],
                            ['City / Municipality', $brgy['municipality']  ?? '—'],
                            ['Barangay',            $brgy['barangay_name'] ?? '—'],
                            ['Zip Code',            $brgy['zip_code']      ?? '—'],
                        ];
                        foreach ($addrRows as [$label,$val]):
                        ?>
                        <div class="col-sm-6 col-md-4">
                            <div class="p-3 rounded-3" style="background:rgba(26,79,122,0.04);border:1px solid rgba(26,79,122,0.08);">
                                <div style="font-size:0.68rem;color:#3a7fa8;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;"><?= $label ?></div>
                                <div class="fw-semibold mt-1" style="font-size:0.88rem;"><?= htmlspecialchars($val) ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

<?php endif; /* end profile tab */ ?>

<?php /* ══════════════════════════════════════════
   TAB: SECURITY
══════════════════════════════════════════ */ ?>
<?php if ($tab === 'security'): ?>

            <form method="POST">
                <input type="hidden" name="action" value="save_security">
                <?= csrfField() ?>

                <!-- Login Attempt Limits -->
                <div class="settings-card">
                    <div class="sc-header">
                        <div class="sc-icon"><i class="bi bi-shield-exclamation"></i></div>
                        <div>
                            <h6>Login Attempt Limits</h6>
                            <p>Lock accounts after too many failed login attempts</p>
                        </div>
                    </div>
                    <div class="sc-body">
                        <div class="row g-3">
                            <div class="col-md-5">
                                <label class="form-label fw-semibold small">Max Failed Attempts</label>
                                <div class="input-group">
                                    <input type="number" name="login_max_attempts" class="form-control"
                                           value="<?= (int)baSetting($db,'login_max_attempts','5') ?>"
                                           min="1" max="20"
                                           oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                                    <span class="input-group-text">tries</span>
                                </div>
                                <div class="form-text">Account locks after this many failures (1–20)</div>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label fw-semibold small">Lockout Duration</label>
                                <div class="input-group">
                                    <input type="number" name="login_lockout_seconds" class="form-control"
                                           value="<?= (int)baSetting($db,'login_lockout_seconds','300') ?>"
                                           min="30" max="86400"
                                           oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                                    <span class="input-group-text">sec</span>
                                </div>
                                <div class="form-text">How long the account stays locked (30–86400 sec)</div>
                            </div>
                        </div>
                        <div class="alert d-flex align-items-start gap-2 mt-3 mb-0 py-2 px-3"
                             style="background:rgba(220,38,38,0.07);border:1px solid rgba(220,38,38,0.18);border-radius:10px;font-size:0.8rem;color:#991b1b;white-space:normal;word-break:break-word;">
                            <i class="bi bi-lock-fill flex-shrink-0 mt-1"></i>
                            <span>These limits apply to all users in this barangay. Once the limit is reached, the account is locked and <strong>cannot be automatically unlocked</strong> — the user must contact the LGU Admin to restore access.</span>
                        </div>
                    </div>
                </div>

                <!-- Idle Auto-Logout -->
                <?php
                $curIdleUnit = baSetting($db,'idle_timeout_unit','minutes');
                $curIdleUnit = in_array($curIdleUnit,['seconds','minutes']) ? $curIdleUnit : 'minutes';
                $curIdleVal  = (int)baSetting($db,'idle_timeout_minutes','5');
                $idleEnabled = baSetting($db,'idle_timeout_enabled','1') === '1';
                // Range hints per unit
                $idleMin = $curIdleUnit === 'seconds' ? 10  : 1;
                $idleMax = $curIdleUnit === 'seconds' ? 300 : 120;
                ?>
                <div class="settings-card">
                    <div class="sc-header">
                        <div class="sc-icon"><i class="bi bi-hourglass-split"></i></div>
                        <div>
                            <h6>Idle Auto-Logout</h6>
                            <p>Automatically log out users after inactivity</p>
                        </div>
                    </div>
                    <div class="sc-body">
                        <div class="toggle-row">
                            <div>
                                <div class="tr-label">Enable Idle Timeout</div>
                                <div class="tr-desc">When idle, users see a "Log In Now" prompt and are auto-logged out after the countdown</div>
                            </div>
                            <input type="checkbox" class="form-check-input" name="idle_timeout_enabled"
                                   id="idleToggle"
                                   <?= $idleEnabled ? 'checked' : '' ?>
                                   style="width:42px;height:24px;cursor:pointer;"
                                   onchange="document.getElementById('idleMinRow').style.display=this.checked?'flex':'none'">
                        </div>
                        <div class="row g-3 mt-2 align-items-end" id="idleMinRow"
                             style="display:<?= $idleEnabled ? 'flex' : 'none' ?>">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small">Idle Timeout After</label>
                                <input type="number" name="idle_timeout_minutes" id="idleValueInput"
                                       class="form-control"
                                       value="<?= $curIdleVal ?>"
                                       min="<?= $idleMin ?>" max="<?= $idleMax ?>"
                                       oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold small">Unit</label>
                                <select name="idle_timeout_unit" id="idleUnitSelect" class="form-select"
                                        onchange="idleUnitChanged(this.value)">
                                    <option value="seconds" <?= $curIdleUnit==='seconds'?'selected':'' ?>>Seconds</option>
                                    <option value="minutes" <?= $curIdleUnit==='minutes'?'selected':'' ?>>Minutes</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <div class="form-text" id="idleRangeHint">
                                    <?= $curIdleUnit==='seconds'
                                        ? '10–300 seconds (e.g. 30 = 30 seconds)'
                                        : '1–120 minutes (e.g. 5 = 5 minutes)' ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <script>
                function idleUnitChanged(unit) {
                    var input = document.getElementById('idleValueInput');
                    var hint  = document.getElementById('idleRangeHint');
                    if (unit === 'seconds') {
                        input.min = 10; input.max = 300;
                        if (parseInt(input.value) < 10)  input.value = 30;
                        if (parseInt(input.value) > 300) input.value = 300;
                        hint.textContent = '10–300 seconds (e.g. 30 = 30 seconds)';
                    } else {
                        input.min = 1; input.max = 120;
                        if (parseInt(input.value) > 120) input.value = 5;
                        if (parseInt(input.value) < 1)   input.value = 1;
                        hint.textContent = '1–120 minutes (e.g. 5 = 5 minutes)';
                    }
                }
                </script>

                <!-- Session Timeout removed — replaced by Idle Auto-Logout -->

                <div class="d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary px-4 fw-semibold" style="border-radius:8px;">
                        <i class="bi bi-check-lg me-1"></i>Save Security Settings
                    </button>
                </div>
            </form>

<?php endif; /* end security tab */ ?>

<?php /* ══════════════════════════════════════════
   TAB: ARCHIVE
══════════════════════════════════════════ */ ?>
<?php if ($tab === 'archive'): ?>

            <form method="POST">
                <input type="hidden" name="action" value="save_archive">
                <?= csrfField() ?>

                <!-- Auto-archive settings -->
                <div class="settings-card">
                    <div class="sc-header">
                        <div class="sc-icon"><i class="bi bi-archive-fill"></i></div>
                        <div>
                            <h6>Auto-Archive Settings</h6>
                            <p>Automatically archive old or inactive records</p>
                        </div>
                    </div>
                    <div class="sc-body">
                        <div class="toggle-row">
                            <div>
                                <div class="tr-label">Enable Auto-Archive</div>
                                <div class="tr-desc">Automatically archive records older than the specified period</div>
                            </div>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" name="archive_auto_enabled"
                                       id="archAutoToggle" role="switch" style="width:2.5em;height:1.3em;"
                                       <?= baSetting($db,'archive_auto_enabled','0')==='1'?'checked':'' ?>
                                       onchange="document.getElementById('archPeriodRow').style.display=this.checked?'':'none'">
                            </div>
                        </div>
                        <div class="row g-3 mt-1" id="archPeriodRow"
                             style="display:<?= baSetting($db,'archive_auto_enabled','0')==='1'?'flex':'none' ?>">
                            <div class="col-md-5">
                                <label class="form-label fw-semibold small">Archive After</label>
                                <div class="input-group">
                                    <input type="number" name="archive_after_months" class="form-control"
                                           value="<?= (int)baSetting($db,'archive_after_months','3') ?>"
                                           min="1" max="60"
                                           oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                                    <span class="input-group-text">months</span>
                                </div>
                                <div class="form-text">Records older than this will be auto-archived (1–60 months)</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- What to archive -->
                <div class="settings-card">
                    <div class="sc-header">
                        <div class="sc-icon"><i class="bi bi-folder2-open"></i></div>
                        <div>
                            <h6>What to Auto-Archive</h6>
                            <p>Select which record types are included in auto-archiving</p>
                        </div>
                    </div>
                    <div class="sc-body">
                        <div class="toggle-row">
                            <div>
                                <div class="tr-label"><i class="bi bi-people-fill text-secondary me-1"></i>Inactive Residents</div>
                                <div class="tr-desc">Archive resident records that have not been updated in the specified period</div>
                            </div>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" name="archive_residents"
                                       role="switch" style="width:2.5em;height:1.3em;"
                                       <?= baSetting($db,'archive_residents','1')==='1'?'checked':'' ?>>
                            </div>
                        </div>
                        <div class="toggle-row">
                            <div>
                                <div class="tr-label"><i class="bi bi-inbox-fill text-secondary me-1"></i>Old Requests</div>
                                <div class="tr-desc">Archive completed or rejected document requests</div>
                            </div>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" name="archive_requests"
                                       role="switch" style="width:2.5em;height:1.3em;"
                                       <?= baSetting($db,'archive_requests','1')==='1'?'checked':'' ?>>
                            </div>
                        </div>
                        <div class="toggle-row">
                            <div>
                                <div class="tr-label"><i class="bi bi-megaphone-fill text-secondary me-1"></i>Expired Notices</div>
                                <div class="tr-desc">Archive notices that have passed their expiry date</div>
                            </div>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" name="archive_notices"
                                       role="switch" style="width:2.5em;height:1.3em;"
                                       <?= baSetting($db,'archive_notices','1')==='1'?'checked':'' ?>>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick link to archive viewer -->
                <div class="settings-card">
                    <div class="sc-header">
                        <div class="sc-icon"><i class="bi bi-eye-fill"></i></div>
                        <div>
                            <h6>View Archived Records</h6>
                            <p>Browse, restore, or permanently delete archived data</p>
                        </div>
                        <a href="<?= BASE_URL ?>/barangay_admin/archive.php" target="_top" class="btn btn-outline-primary btn-sm ms-auto">
                            <i class="bi bi-arrow-up-right-square me-1"></i>Open Archive
                        </a>
                    </div>
                    <div class="sc-body">
                        <?php
                        try {
                            $archCount = $db->prepare("SELECT COUNT(*) FROM archives WHERE barangay_id=?");
                            $archCount->execute([$bid]);
                            $archCount = (int)$archCount->fetchColumn();
                        } catch (PDOException $e) {
                            $archCount = 0;
                        }
                        ?>
                        <div class="d-flex align-items-center gap-3">
                            <div style="width:44px;height:44px;border-radius:12px;background:linear-gradient(135deg,#1a4f7a,#3a7fa8);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                <i class="bi bi-archive-fill text-white fs-5"></i>
                            </div>
                            <div>
                                <div class="fw-bold fs-5"><?= number_format($archCount) ?></div>
                                <div class="small text-muted">Total archived records in this barangay</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary px-4 fw-semibold" style="border-radius:8px;">
                        <i class="bi bi-check-lg me-1"></i>Save Archive Settings
                    </button>
                </div>
            </form>

<?php endif; /* end archive tab */ ?>

<?php /* ══════════════════════════════════════════
   TAB: AUTHENTICATOR (2FA)
══════════════════════════════════════════ */ ?>
<?php if ($tab === 'authenticator'):
    require_once BASE_PATH . '/helpers/totp.php';
    $twoFaRow = $db->prepare("SELECT * FROM users WHERE id=?");
    $twoFaRow->execute([$u['id']]);
    $twoFaUser    = $twoFaRow->fetch();
    $is2FAEnabled = (bool)($twoFaUser['two_fa_enabled'] ?? 0);
    $hasSecret    = !empty($twoFaUser['totp_secret']);
    $qrDataUri    = $hasSecret ? TOTP::getQRCodeDataUri($twoFaUser['totp_secret'], $twoFaUser['email'], APP_NAME) : null;
?>

            <!-- Status Card -->
            <div class="settings-card">
                <div class="sc-header">
                    <div class="sc-icon" style="background:<?= $is2FAEnabled ? 'linear-gradient(135deg,#1b5e20,#1a4f7a)' : 'linear-gradient(135deg,#6c757d,#adb5bd)' ?>">
                        <i class="bi bi-shield-<?= $is2FAEnabled ? 'check' : 'exclamation' ?>-fill"></i>
                    </div>
                    <div>
                        <h6>Two-Factor Authentication</h6>
                        <p><?= $is2FAEnabled ? 'Your account is protected with 2FA' : 'Add an extra layer of security to your account' ?></p>
                    </div>
                    <?php if ($is2FAEnabled): ?>
                    <span class="badge ms-auto" style="background:#d1fae5;color:#065f46;font-size:0.75rem;padding:6px 12px;border-radius:20px;">
                        <i class="bi bi-check-circle-fill me-1"></i>Enabled
                    </span>
                    <?php else: ?>
                    <span class="badge ms-auto" style="background:#f1f5f9;color:#64748b;font-size:0.75rem;padding:6px 12px;border-radius:20px;">
                        <i class="bi bi-x-circle me-1"></i>Disabled
                    </span>
                    <?php endif; ?>
                </div>
                <div class="sc-body">

                    <?php if ($is2FAEnabled): ?>
                    <!-- 2FA is ON -->
                    <div class="row g-4 align-items-start">
                        <div class="col-md-7">
                            <h6 class="fw-semibold mb-3"><i class="bi bi-info-circle me-2"></i>How it works</h6>
                            <ul class="small text-muted mb-3" style="line-height:2.2;">
                                <li>Every time you log in, you'll be asked for a 6-digit code</li>
                                <li>Open your authenticator app and enter the current code</li>
                                <li>Codes refresh every 30 seconds</li>
                                <li>If you lose access to your app, contact the Super Admin</li>
                            </ul>
                            <div class="alert alert-warning py-2 small mb-0">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                <strong>Important:</strong> Keep your authenticator app installed. If you lose it, contact the LGU Super Admin to disable 2FA on your account.
                            </div>
                        </div>
                        <div class="col-md-5 text-center">
                            <div style="width:80px;height:80px;background:linear-gradient(135deg,#1b5e20,#1a4f7a);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
                                <i class="bi bi-shield-check-fill text-white" style="font-size:2rem;"></i>
                            </div>
                            <p class="small text-muted mb-3">2FA is active on your account</p>
                            <form method="POST" onsubmit="return confirm('Disable 2FA? Your account will be less secure.')">
                                <input type="hidden" name="action" value="disable_2fa">
                                <?= csrfField() ?>
                                <button class="btn btn-outline-danger btn-sm px-4">
                                    <i class="bi bi-shield-x me-1"></i>Disable 2FA
                                </button>
                            </form>
                        </div>
                    </div>

                    <?php elseif ($hasSecret): ?>
                    <!-- Secret generated — show QR + verify form -->
                    <div class="row g-4 align-items-center">
                        <div class="col-md-5 text-center">
                            <img src="<?= htmlspecialchars($qrDataUri, ENT_QUOTES, 'UTF-8') ?>" alt="QR Code"
                                 class="img-fluid rounded border p-2" style="max-width:200px;">
                            <div class="mt-2 small text-muted">Scan with Google Authenticator,<br>Authy, or Microsoft Authenticator</div>
                        </div>
                        <div class="col-md-7">
                            <h6 class="fw-semibold mb-3">Steps to enable:</h6>
                            <ol class="small text-muted mb-3" style="line-height:2.2;">
                                <li>Install an authenticator app on your phone</li>
                                <li>Tap <strong>+</strong> or <strong>Add Account</strong></li>
                                <li>Scan the QR code on the left</li>
                                <li>Enter the 6-digit code below to verify</li>
                            </ol>
                            <div class="alert alert-info py-2 small mb-3">
                                <i class="bi bi-info-circle me-1"></i>
                                Manual key: <code class="user-select-all"><?= htmlspecialchars($twoFaUser['totp_secret']) ?></code>
                            </div>
                            <form method="POST">
                                <input type="hidden" name="action" value="enable_2fa">
                                <?= csrfField() ?>
                                <div class="input-group">
                                    <input type="text" name="code" class="form-control form-control-lg text-center fw-bold"
                                           placeholder="000000" maxlength="6" inputmode="numeric"
                                           pattern="[0-9]{6}" required autofocus
                                           style="letter-spacing:0.4rem;font-size:1.4rem;"
                                           oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                                    <button class="btn btn-success px-4" type="submit">
                                        <i class="bi bi-check-lg me-1"></i>Verify &amp; Enable
                                    </button>
                                </div>
                                <div class="form-text">Enter the 6-digit code shown in your authenticator app</div>
                            </form>
                        </div>
                    </div>

                    <?php else: ?>
                    <!-- Not set up yet -->
                    <div class="text-center py-4">
                        <div style="width:72px;height:72px;background:linear-gradient(135deg,#6c757d,#adb5bd);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
                            <i class="bi bi-shield-lock text-white" style="font-size:2rem;"></i>
                        </div>
                        <h6 class="fw-semibold mb-2">Set Up Two-Factor Authentication</h6>
                        <p class="text-muted small mb-4 mx-auto" style="max-width:380px;">
                            You'll need an authenticator app like <strong>Google Authenticator</strong>,
                            <strong>Authy</strong>, or <strong>Microsoft Authenticator</strong>.
                        </p>
                        <form method="POST">
                            <input type="hidden" name="action" value="generate_2fa">
                            <?= csrfField() ?>
                            <button class="btn btn-primary px-5">
                                <i class="bi bi-qr-code me-2"></i>Generate QR Code
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>

                </div>
            </div>

            <!-- What is 2FA? info card -->
            <div class="settings-card">
                <div class="sc-header">
                    <div class="sc-icon"><i class="bi bi-question-circle-fill"></i></div>
                    <div>
                        <h6>What is Two-Factor Authentication?</h6>
                        <p>Learn why 2FA protects your account</p>
                    </div>
                </div>
                <div class="sc-body">
                    <div class="row g-3">
                        <?php
                        $cards = [
                            ['bi-lock-fill','#1a4f7a','Extra Security','Even if someone steals your password, they still need your phone to log in.'],
                            ['bi-phone-fill','#059669','Your Phone','The 6-digit code is generated on your device — it never leaves your phone.'],
                            ['bi-clock-fill','#e65100','30-Second Codes','Each code is only valid for 30 seconds, making it nearly impossible to guess.'],
                        ];
                        foreach ($cards as [$icon,$color,$title,$desc]):
                        ?>
                        <div class="col-md-4">
                            <div class="p-3 rounded-3 h-100" style="background:rgba(26,79,122,0.04);border:1px solid rgba(26,79,122,0.08);">
                                <div style="width:32px;height:32px;background:<?= $color ?>1a;border-radius:8px;display:flex;align-items:center;justify-content:center;margin-bottom:10px;">
                                    <i class="bi <?= $icon ?>" style="color:<?= $color ?>;font-size:0.9rem;"></i>
                                </div>
                                <div class="fw-semibold small mb-1"><?= $title ?></div>
                                <div class="text-muted" style="font-size:0.75rem;"><?= $desc ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

<?php endif; /* end authenticator tab */ ?>

<?php /* ══════════════════════════════════════════
   TAB: PREFERENCES
══════════════════════════════════════════ */ ?>
<?php if ($tab === 'preferences'): ?>

            <form method="POST">
                <input type="hidden" name="action" value="save_preferences">
                <?= csrfField() ?>

                <div class="settings-card">
                    <div class="sc-header">
                        <div class="sc-icon"><i class="bi bi-bell-fill"></i></div>
                        <div>
                            <h6>Notification Preferences</h6>
                            <p>Choose which notifications you receive</p>
                        </div>
                    </div>
                    <div class="sc-body">

                        <?php
                        $prefRows = [
                            ['notif_request_approved', 'Request Approved',     'Notify users when their request is approved'],
                            ['notif_request_rejected', 'Request Rejected',     'Notify users when their request is rejected'],
                            ['notif_request_done',     'Request Completed',    'Notify users when their request is marked as done'],
                            ['notif_system_alerts',    'System Alerts',        'Show system-level alerts and announcements'],
                            ['notif_email_enabled',    'Email Notifications',  'Send email alerts in addition to in-app notifications (requires mail server setup)'],
                        ];
                        foreach ($prefRows as [$key, $title, $desc]):
                            $checked = baSetting($db, $key, '1') === '1';
                        ?>
                        <div class="toggle-row">
                            <div>
                                <div class="tr-label"><?= htmlspecialchars($title) ?></div>
                                <div class="tr-desc"><?= htmlspecialchars($desc) ?></div>
                            </div>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox"
                                       name="<?= $key ?>" id="pref_<?= $key ?>"
                                       <?= $checked ? 'checked' : '' ?>
                                       role="switch">
                            </div>
                        </div>
                        <?php endforeach; ?>

                        <div class="d-flex justify-content-end mt-3">
                            <button type="submit" class="btn btn-primary px-4 fw-semibold" style="border-radius:8px;">
                                <i class="bi bi-check-lg me-1"></i>Save Preferences
                            </button>
                        </div>

                    </div>
                </div>

            </form>

<?php endif; /* end preferences tab */ ?>

        </div><!-- end settings-content -->
    </div><!-- end settings-wrap -->
</div><!-- end main-content -->

<!-- ══════════════════════════════════════════════════════════
     CHANGE PASSWORD MODAL
══════════════════════════════════════════════════════════ -->
<div id="changePasswordModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.5);backdrop-filter:blur(4px);align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:20px;width:100%;max-width:460px;box-shadow:0 20px 60px rgba(0,0,0,0.3);overflow:hidden;margin:16px;">

        <div style="background:linear-gradient(135deg,#1a4f7a,#3a7fa8);padding:22px 28px;display:flex;align-items:center;justify-content:space-between;">
            <div class="d-flex align-items-center gap-3">
                <div style="width:40px;height:40px;background:rgba(255,255,255,0.15);border-radius:10px;display:flex;align-items:center;justify-content:center;">
                    <i class="bi bi-key-fill text-white fs-5"></i>
                </div>
                <div>
                    <div class="fw-bold text-white" style="font-size:1rem;">Change Password</div>
                    <div style="font-size:0.75rem;color:rgba(255,255,255,0.7);">Update your account password</div>
                </div>
            </div>
            <button onclick="closeCPModal()" style="background:rgba(255,255,255,0.15);border:none;border-radius:8px;width:32px;height:32px;color:#fff;font-size:1.1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <div style="padding:28px;">
            <div id="cpAlert" style="display:none;padding:10px 14px;border-radius:8px;font-size:0.85rem;margin-bottom:16px;align-items:center;gap:8px;"></div>

            <div class="mb-3">
                <label class="form-label fw-semibold small">Current Password <span class="text-danger">*</span></label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                    <input type="password" id="cpCurrent" class="form-control" placeholder="Enter current password">
                    <button type="button" class="btn btn-outline-secondary" onclick="toggleCPVis('cpCurrent','cpEye1')"><i class="bi bi-eye" id="cpEye1"></i></button>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold small">New Password <span class="text-danger">*</span></label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                    <input type="password" id="cpNew" class="form-control" placeholder="Min 8 chars, letters + numbers" oninput="cpStrength(this.value)">
                    <button type="button" class="btn btn-outline-secondary" onclick="toggleCPVis('cpNew','cpEye2')"><i class="bi bi-eye" id="cpEye2"></i></button>
                </div>
                <div style="background:#e9ecef;border-radius:2px;height:4px;margin-top:6px;">
                    <div id="cpStrBar" style="height:4px;border-radius:2px;width:0%;transition:width .3s,background .3s;"></div>
                </div>
                <small id="cpStrLabel" style="font-size:0.72rem;color:#6c757d;"></small>
            </div>
            <div class="mb-4">
                <label class="form-label fw-semibold small">Confirm New Password <span class="text-danger">*</span></label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                    <input type="password" id="cpConfirm" class="form-control" placeholder="Repeat new password">
                    <button type="button" class="btn btn-outline-secondary" onclick="toggleCPVis('cpConfirm','cpEye3')"><i class="bi bi-eye" id="cpEye3"></i></button>
                </div>
            </div>

            <!-- Confirm step -->
            <div id="cpConfirmStep" style="display:none;background:#fff8e1;border:1px solid #ffe082;border-radius:10px;padding:14px 16px;margin-bottom:16px;">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <i class="bi bi-exclamation-triangle-fill text-warning"></i>
                    <span class="fw-semibold small">Are you sure you want to change your password?</span>
                </div>
                <p class="text-muted small mb-3">You will be logged out automatically after the password is changed.</p>
                <div class="d-flex gap-2">
                    <button class="btn btn-danger btn-sm px-4" onclick="doChangePassword()">
                        <i class="bi bi-check-lg me-1"></i>Yes, Change It
                    </button>
                    <button class="btn btn-outline-secondary btn-sm"
                            onclick="document.getElementById('cpConfirmStep').style.display='none';document.getElementById('cpSubmitBtn').style.display='block';">
                        Cancel
                    </button>
                </div>
            </div>

            <button id="cpSubmitBtn" class="btn btn-primary w-100 fw-semibold" onclick="showCPConfirm()">
                <i class="bi bi-key me-2"></i>Change Password
            </button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Password Policy (from system_settings) ─────────────────
const PW_POLICY = {
    minLen:  <?= max(6, (int)baSetting($db, 'password_min_length', '8')) ?>,
    upper:   <?= baSetting($db, 'password_require_upper',   '1') === '1' ? 'true' : 'false' ?>,
    number:  <?= baSetting($db, 'password_require_number',  '1') === '1' ? 'true' : 'false' ?>,
    special: <?= baSetting($db, 'password_require_special', '0') === '1' ? 'true' : 'false' ?>,
};
function validatePasswordPolicyJS(pw) {
    if (pw.length < PW_POLICY.minLen) return `Password must be at least ${PW_POLICY.minLen} characters.`;
    if (PW_POLICY.upper   && !/[A-Z]/.test(pw))  return 'Password must contain at least one uppercase letter.';
    if (PW_POLICY.number  && !/[0-9]/.test(pw))  return 'Password must contain at least one number.';
    if (PW_POLICY.special && !/[\W_]/.test(pw))  return 'Password must contain at least one special character (e.g. !@#$%).';
    return '';
}

// ── Sidebar toggle ────────────────────────────────────────
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('show'); }

// ── Barangay logo live preview ────────────────────────────
function previewLogo(input) {
    const preview     = document.getElementById('logoPreview');
    const placeholder = document.getElementById('logoPlaceholder');
    const errEl       = document.getElementById('logoSizeErr');
    errEl.style.display = 'none';

    if (!input.files || !input.files[0]) return;
    const file = input.files[0];

    if (file.size > 2 * 1024 * 1024) {
        errEl.textContent    = 'File exceeds 2 MB limit. Please choose a smaller image.';
        errEl.style.display  = 'block';
        input.value          = '';
        return;
    }

    const reader = new FileReader();
    reader.onload = function(e) {
        preview.src          = e.target.result;
        preview.style.display = 'block';
        if (placeholder) placeholder.style.display = 'none';
    };
    reader.readAsDataURL(file);
}

// ── Profile photo live preview ────────────────────────────
// ── Profile photo: preview then auto-submit ───────────────
function autoSubmitPhoto(input) {
    const preview  = document.getElementById('photoPreview');
    const initials = document.getElementById('photoInitials');
    const errEl    = document.getElementById('photoSizeErr');
    errEl.style.display = 'none';

    if (!input.files || !input.files[0]) return;
    const file = input.files[0];

    if (file.size > 2 * 1024 * 1024) {
        errEl.textContent   = 'File exceeds 2 MB limit. Please choose a smaller image.';
        errEl.style.display = 'block';
        input.value         = '';
        return;
    }

    const reader = new FileReader();
    reader.onload = function(e) {
        preview.src           = e.target.result;
        preview.style.display = 'block';
        if (initials) initials.style.display = 'none';
        // Auto-submit the form after preview renders
        document.getElementById('photoUploadForm').submit();
    };
    reader.readAsDataURL(file);
}

// ── Contact validation ────────────────────────────────────
function validateContact(input, errId) {
    const err = document.getElementById(errId);
    if (!input.value) { err.style.display='none'; return; }
    if (!/^09\d{9}$/.test(input.value)) {
        err.textContent = 'Must be 11 digits starting with 09';
        err.style.display = 'block';
    } else {
        err.style.display = 'none';
    }
}

// ── Change Password Modal ─────────────────────────────────
function openCPModal() {
    document.getElementById('changePasswordModal').style.display = 'flex';
}
function closeCPModal() {
    document.getElementById('changePasswordModal').style.display = 'none';
    ['cpCurrent','cpNew','cpConfirm'].forEach(id => document.getElementById(id).value = '');
    const al = document.getElementById('cpAlert');
    al.style.display = 'none';
    document.getElementById('cpConfirmStep').style.display = 'none';
    document.getElementById('cpSubmitBtn').style.display = 'block';
    document.getElementById('cpStrBar').style.width = '0%';
    document.getElementById('cpStrLabel').textContent = '';
}
document.getElementById('changePasswordModal').addEventListener('click', function(e) {
    if (e.target === this) closeCPModal();
});

function toggleCPVis(inputId, iconId) {
    const el = document.getElementById(inputId);
    const ic = document.getElementById(iconId);
    el.type = el.type === 'password' ? 'text' : 'password';
    ic.className = el.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
}

function cpStrength(val) {
    const bar = document.getElementById('cpStrBar');
    const lbl = document.getElementById('cpStrLabel');
    let score = 0;
    if (val.length >= 8)          score++;
    if (/[A-Z]/.test(val))        score++;
    if (/[0-9]/.test(val))        score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;
    if (val.length >= 12)         score++;
    const lvls = [
        {w:'20%',bg:'#ef4444',t:'Very weak'},
        {w:'40%',bg:'#f97316',t:'Weak'},
        {w:'60%',bg:'#eab308',t:'Fair'},
        {w:'80%',bg:'#22c55e',t:'Strong'},
        {w:'100%',bg:'#16a34a',t:'Very strong'},
    ];
    const lvl = lvls[Math.min(score - 1, 4)];
    if (val && lvl) {
        bar.style.width = lvl.w;
        bar.style.background = lvl.bg;
        lbl.textContent = lvl.t;
        lbl.style.color = lvl.bg;
    } else {
        bar.style.width = '0%';
        lbl.textContent = '';
    }
}

function showCPConfirm() {
    const cur = document.getElementById('cpCurrent').value;
    const nw  = document.getElementById('cpNew').value;
    const cf  = document.getElementById('cpConfirm').value;
    if (!cur || !nw || !cf) { showCPAlert('All fields are required.', 'danger'); return; }
    if (nw !== cf)           { showCPAlert('New passwords do not match.', 'danger'); return; }
    const cpErr = validatePasswordPolicyJS(nw);
    if (cpErr) { showCPAlert(cpErr, 'danger'); return; }
    document.getElementById('cpAlert').style.display = 'none';
    document.getElementById('cpSubmitBtn').style.display = 'none';
    document.getElementById('cpConfirmStep').style.display = 'block';
}

function showCPAlert(msg, type) {
    const al = document.getElementById('cpAlert');
    const colors = { success:'#d1fae5', danger:'#fee2e2', warning:'#fef3c7', info:'#dbeafe' };
    const textColors = { success:'#065f46', danger:'#991b1b', warning:'#92400e', info:'#1e40af' };
    al.style.cssText = `display:flex;background:${colors[type]};color:${textColors[type]};border:1px solid ${textColors[type]}33;padding:10px 14px;border-radius:8px;font-size:0.85rem;margin-bottom:16px;align-items:center;gap:8px;`;
    al.innerHTML = `<i class="bi bi-${type==='success'?'check-circle':'exclamation-triangle'}-fill"></i>${msg}`;
}

function doChangePassword() {
    const btn = document.querySelector('#cpConfirmStep .btn-danger');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving…';

    fetch('<?= BASE_URL ?>/barangay_admin/settings.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=change_password_ajax'
            + '&current_password=' + encodeURIComponent(document.getElementById('cpCurrent').value)
            + '&new_password='     + encodeURIComponent(document.getElementById('cpNew').value)
            + '&confirm_password=' + encodeURIComponent(document.getElementById('cpConfirm').value)
            + '&csrf_token='       + encodeURIComponent(document.querySelector('meta[name="csrf-token"]')?.content || '')
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            showCPAlert(data.msg, 'success');
            document.getElementById('cpConfirmStep').style.display = 'none';
            setTimeout(() => { window.location.href = '<?= BASE_URL ?>/logout.php'; }, 1800);
        } else {
            showCPAlert(data.msg, 'danger');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Yes, Change It';
            document.getElementById('cpConfirmStep').style.display = 'none';
            document.getElementById('cpSubmitBtn').style.display = 'block';
        }
    })
    .catch(() => {
        showCPAlert('An error occurred. Please try again.', 'danger');
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Yes, Change It';
    });
}
</script>
</body>
</html>
