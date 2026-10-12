<?php
ob_start();
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/helpers/mailer.php';

// Already logged in — go straight to dashboard
// BUT: allow AJAX change_password action through (first-login flow)
// AND: allow force_change panel to render for first-login users
$isAjaxChangePassword = isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'change_password';
$isForceChange        = isset($_GET['force_change']) || (isLoggedIn() && ($_SESSION['must_change_password'] ?? false));
if (isLoggedIn() && !$isAjaxChangePassword && !$isForceChange) {
    if (isSuperAdmin()) {
        redirect(BASE_URL . '/super_admin/dashboard.php');
    } else {
        redirect(BASE_URL . '/barangay_admin/dashboard.php');
    }
}

$db = Database::getInstance()->getConnection();

// ── Load security settings from DB ────────────────────────
(function() use ($db) {
    // Ensure columns exist (idempotent)
    try { $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS login_attempts TINYINT UNSIGNED DEFAULT 0"); } catch (\Throwable $e) {}
    try { $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS locked_until DATETIME NULL"); } catch (\Throwable $e) {}
    // Ensure system_settings table + defaults
    try { $db->exec("CREATE TABLE IF NOT EXISTS system_settings (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) NOT NULL UNIQUE,
        setting_val TEXT,
        updated_by INT UNSIGNED NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB"); } catch (\Throwable $e) {}
    foreach (['login_max_attempts'=>'5','login_lockout_minutes'=>'15',
              'password_min_length'=>'8','password_require_upper'=>'1',
              'password_require_number'=>'1','password_require_special'=>'0'] as $k => $v) {
        try { $db->prepare("INSERT IGNORE INTO system_settings (setting_key,setting_val) VALUES (?,?)")->execute([$k,$v]); } catch (\Throwable $e) {}
    }
})();

/** Fetch one system_setting — local to home.php only */
$landingSetting = static function(string $key, string $default = '') use ($db): string {
    try {
        $s = $db->prepare("SELECT setting_val FROM system_settings WHERE setting_key=?");
        $s->execute([$key]);
        $r = $s->fetchColumn();
        return $r !== false ? $r : $default;
    } catch (\Throwable $e) { return $default; }
};

// ── Safe migration: login_locked column ──────────────────
try { $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS login_locked TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = locked by failed attempts; requires superadmin unlock'"); } catch (\Throwable $e) {}

// ── Safe migration ─────────────────────────────────────────
try { $db->exec("CREATE TABLE IF NOT EXISTS password_resets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB"); } catch (PDOException $e) {}

// ── AJAX: Forgot password request ─────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'forgot') {
    header('Content-Type: application/json');
    verifyCsrfOrAbort();
    $email = sanitize($_POST['email'] ?? '');
    if (!$email) { echo json_encode(['ok'=>false,'msg'=>'Please enter your email.']); exit; }

    $stmt = $db->prepare("SELECT * FROM users WHERE email=? AND status='active' LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // Always return success to prevent enumeration
    echo json_encode(['ok'=>true,'msg'=>'If that email is registered, a reset link has been sent. Check your inbox.']);

    if ($user) {
        $db->prepare("UPDATE password_resets SET used=1 WHERE user_id=?")->execute([$user['id']]);
        $token     = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + 1800);
        $db->prepare("INSERT INTO password_resets (user_id,token,expires_at) VALUES (?,?,?)")->execute([$user['id'],$token,$expiresAt]);
        logActivity($user['id'], 'PASSWORD_RESET_REQUEST', 'Auth', "Reset requested: {$user['email']}");

        // Flush response then send email
        if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
        else { ignore_user_abort(true); ob_end_flush(); flush(); }

        $resetUrl = EMAIL_BASE_URL . '/home.php?reset_token=' . $token;
        sendMail($user['email'], $user['first_name'].' '.$user['last_name'],
            'Password Reset — ' . APP_NAME,
            buildResetEmailHtml($user['first_name'].' '.$user['last_name'], $resetUrl, '30'));
    }
    exit;
}

// ── AJAX: Reset password submit ────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'reset') {
    header('Content-Type: application/json');
    verifyCsrfOrAbort();
    $token   = sanitize($_POST['reset_token'] ?? '');
    $newPass = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!$token || !$newPass || !$confirm) { echo json_encode(['ok'=>false,'msg'=>'All fields are required.']); exit; }
    if ($newPass !== $confirm)             { echo json_encode(['ok'=>false,'msg'=>'Passwords do not match.']); exit; }
    $policyErr = validatePasswordPolicy($db, $newPass);
    if ($policyErr) { echo json_encode(['ok'=>false,'msg'=>$policyErr]); exit; }

    $stmt = $db->prepare("SELECT pr.*, u.id as uid FROM password_resets pr JOIN users u ON pr.user_id=u.id WHERE pr.token=? AND pr.used=0 AND pr.expires_at > NOW() LIMIT 1");
    $stmt->execute([$token]);
    $row = $stmt->fetch();

    if (!$row) { echo json_encode(['ok'=>false,'msg'=>'This reset link is invalid or has expired.','expired'=>true]); exit; }

    $hashed = password_hash($newPass, PASSWORD_BCRYPT, ['cost'=>12]);
    $db->prepare("UPDATE users SET password=?,must_change_password=0 WHERE id=?")->execute([$hashed,$row['uid']]);
    $db->prepare("UPDATE password_resets SET used=1 WHERE token=?")->execute([$token]);
    logActivity($row['uid'], 'PASSWORD_RESET', 'Auth', 'Password reset via landing page');

    echo json_encode(['ok'=>true,'msg'=>'Password reset successfully! You can now log in.']);
    exit;
}

// ── AJAX: Force change password (first login) ─────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'change_password') {
    header('Content-Type: application/json');
    verifyCsrfOrAbort();
    if (!isLoggedIn()) { echo json_encode(['ok'=>false,'msg'=>'Session expired. Please log in again.']); exit; }

    $u       = currentUser();
    $current = $_POST['current_password'] ?? '';
    $newPass = $_POST['new_password']     ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!$current || !$newPass || !$confirm) { echo json_encode(['ok'=>false,'msg'=>'All fields are required.']); exit; }
    if ($newPass !== $confirm)               { echo json_encode(['ok'=>false,'msg'=>'Passwords do not match.']); exit; }
    $policyErr = validatePasswordPolicy($db, $newPass);
    if ($policyErr) { echo json_encode(['ok'=>false,'msg'=>$policyErr]); exit; }

    $stmt = $db->prepare("SELECT password FROM users WHERE id=?");
    $stmt->execute([$u['id']]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($current, $row['password'])) {
        echo json_encode(['ok'=>false,'msg'=>'Current password is incorrect.']); exit;
    }

    $hashed = password_hash($newPass, PASSWORD_BCRYPT, ['cost'=>12]);
    $db->prepare("UPDATE users SET password=?, must_change_password=0 WHERE id=?")->execute([$hashed, $u['id']]);
    $_SESSION['must_change_password'] = false;
    logActivity($u['id'], 'CHANGE_PASSWORD', 'Auth', 'Forced password change on first login');

    echo json_encode(['ok'=>true,'msg'=>'Password changed successfully! Redirecting...','role'=>$u['role'],'csrf_token'=>generateCsrfToken()]);
    exit;
}

// ── AJAX: Login ───────────────────────────────────────────
if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'login') {
    header('Content-Type: application/json');
    verifyCsrfOrAbort();
    $username = sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$username || !$password) {
        echo json_encode(['ok'=>false,'msg'=>'Please fill in all fields.']); exit;
    }

    // Load lockout settings
    $maxAttempts  = max(1, (int)$landingSetting('login_max_attempts',    '5'));
    $lockoutMins  = max(1, (int)$landingSetting('login_lockout_minutes', '15'));

    $stmt = $db->prepare("SELECT * FROM users WHERE (username=? OR email=?) AND status='active' LIMIT 1");
    $stmt->execute([$username, $username]);
    $user = $stmt->fetch();

    // ── Lockout check ─────────────────────────────────────
    if ($user) {
        // Permanent login lock (failed-attempts, requires superadmin unlock)
        if (!empty($user['login_locked'])) {
            // Check if this is the sole active/unlocked superadmin
            $isSoleSA = false;
            if ($user['role'] === 'super_admin') {
                $otherSA = $db->prepare("SELECT COUNT(*) FROM users WHERE role='super_admin' AND status='active' AND login_locked=0 AND id!=?");
                $otherSA->execute([$user['id']]);
                $isSoleSA = ((int)$otherSA->fetchColumn() === 0);
            }
            if ($isSoleSA) {
                echo json_encode(['ok'=>false,'msg'=>'Your account is locked. Use the recovery option below to receive an unlock code via email.','login_locked'=>true,'sole_sa_locked'=>true,'identifier'=>$user['username']]); exit;
            }
            echo json_encode(['ok'=>false,'msg'=>'Your account has been locked due to too many failed login attempts. Please contact the System Administrator to unlock your access.','login_locked'=>true]); exit;
        }
        $lockedUntil = $user['locked_until'] ?? null;
        if ($lockedUntil && strtotime($lockedUntil) > time()) {
            $mins = (int)ceil((strtotime($lockedUntil) - time()) / 60);
            echo json_encode(['ok'=>false,'msg'=>"Account is temporarily locked. Try again in {$mins} minute(s)."]); exit;
        }
    }

    if ($user && password_verify($password, $user['password'])) {
        // Reset attempt counter on successful login
        $db->prepare("UPDATE users SET login_attempts=0, locked_until=NULL, login_locked=0 WHERE id=?")->execute([$user['id']]);

        // 2FA check
        if ($user['role']==='super_admin' && !empty($user['two_fa_enabled']) && !empty($user['totp_secret'])) {
            $_SESSION['2fa_pending_user_id'] = $user['id'];
            echo json_encode(['ok'=>true,'redirect'=> BASE_URL . '/verify_2fa.php']); exit;
        }
        loginUser($user);
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $logStmt = $db->prepare("INSERT INTO login_logs (user_id,ip_address) VALUES (?,?)");
        $logStmt->execute([$user['id'], $ip]);
        $_SESSION['login_log_id'] = $db->lastInsertId();
        logActivity($user['id'], 'LOGIN', 'Auth', 'User logged in');

        if ($user['must_change_password']) {
            // Stay on landing page — JS will slide to change password panel
            echo json_encode(['ok'=>true,'must_change'=>true,'csrf_token'=>generateCsrfToken()]); exit;
        }
        $dest = BASE_URL . ($user['role']==='super_admin' ? '/super_admin/dashboard.php' : '/barangay_admin/dashboard.php');
        echo json_encode(['ok'=>true,'redirect'=>$dest,'csrf_token'=>generateCsrfToken()]); exit;
    } else {
        // ── Increment attempts and maybe permanently lock ──
        if ($user) {
            $newAttempts = (int)($user['login_attempts'] ?? 0) + 1;
            if ($newAttempts >= $maxAttempts) {
                // Permanent lock — requires superadmin to unblock
                $db->prepare("UPDATE users SET login_attempts=?, locked_until=NULL, login_locked=1 WHERE id=?")
                   ->execute([$newAttempts, $user['id']]);
                $lockedName = trim($user['first_name'].' '.$user['last_name']);
                logActivity($user['id'], 'ACCOUNT_LOCKED', 'Auth', "Permanently locked after {$newAttempts} failed attempt(s). Requires superadmin unlock.");

                // ── Check if this is the sole active/unlocked superadmin ──
                $isSoleSA = false;
                if ($user['role'] === 'super_admin') {
                    $otherSA = $db->prepare("SELECT COUNT(*) FROM users WHERE role='super_admin' AND status='active' AND login_locked=0 AND id!=?");
                    $otherSA->execute([$user['id']]);
                    $isSoleSA = ((int)$otherSA->fetchColumn() === 0);
                }

                // Notify other superadmins (if any exist)
                $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                notifySuperAdmins(
                    "🔒 Account <strong>{$lockedName}</strong> (@{$user['username']}) has been <strong>permanently locked</strong> after {$newAttempts} failed login attempt(s) from IP <code>{$ip}</code>. Go to <strong>User Management</strong> to unlock.",
                    'danger', 'system', $user['id']
                );

                if ($isSoleSA) {
                    // Show the recovery options panel (user chooses: email code or admin approval)
                    echo json_encode(['ok'=>false,'msg'=>'Too many failed attempts. Your account has been locked. Please use the recovery options below.','login_locked'=>true,'sole_sa_locked'=>true,'identifier'=>$user['username']]); exit;
                }
                echo json_encode(['ok'=>false,'msg'=>'Too many failed attempts. Your account has been locked. Please contact the System Administrator to regain access.','login_locked'=>true]); exit;
            } else {
                $db->prepare("UPDATE users SET login_attempts=? WHERE id=?")->execute([$newAttempts, $user['id']]);
                $remaining = $maxAttempts - $newAttempts;
                echo json_encode(['ok'=>false,'msg'=>"Invalid username or password. {$remaining} attempt(s) remaining before lockout."]); exit;
            }
        }
        echo json_encode(['ok'=>false,'msg'=>'Invalid username or password.']); exit;
    }
}

// ── Handle login POST (fallback non-AJAX) ─────────────────
$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_action'])) {
    verifyCsrfOrAbort();
    $username = sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username && $password) {
        $maxAttempts = max(1, (int)$landingSetting('login_max_attempts',    '5'));
        $lockoutMins = max(1, (int)$landingSetting('login_lockout_minutes', '15'));

        $stmt = $db->prepare("SELECT * FROM users WHERE (username=? OR email=?) AND status='active' LIMIT 1");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();

        // Lockout check
        if ($user) {
            // Permanent lock by failed attempts
            if (!empty($user['login_locked'])) {
                // Check if this is the sole active/unlocked superadmin
                if ($user['role'] === 'super_admin') {
                    $otherSA = $db->prepare("SELECT COUNT(*) FROM users WHERE role='super_admin' AND status='active' AND login_locked=0 AND id!=?");
                    $otherSA->execute([$user['id']]);
                    if ((int)$otherSA->fetchColumn() === 0) {
                        $loginError = 'Your account is locked. A recovery code has been sent to your registered email. Use the recovery option to unlock your account.';
                        goto renderPage;
                    }
                }
                $loginError = 'Your account has been locked due to too many failed login attempts. Please contact the System Administrator to unlock your access.';
                goto renderPage;
            }
            $lockedUntil = $user['locked_until'] ?? null;
            if ($lockedUntil && strtotime($lockedUntil) > time()) {
                $mins = (int)ceil((strtotime($lockedUntil) - time()) / 60);
                $loginError = "Account is temporarily locked. Try again in {$mins} minute(s).";
                goto renderPage;
            }
        }

        if ($user && password_verify($password, $user['password'])) {
            $db->prepare("UPDATE users SET login_attempts=0, locked_until=NULL, login_locked=0 WHERE id=?")->execute([$user['id']]);
            if ($user['role']==='super_admin' && !empty($user['two_fa_enabled']) && !empty($user['totp_secret'])) {
                $_SESSION['2fa_pending_user_id'] = $user['id'];
                redirect(BASE_URL . '/verify_2fa.php');
            }
            loginUser($user);
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $logStmt = $db->prepare("INSERT INTO login_logs (user_id,ip_address) VALUES (?,?)");
            $logStmt->execute([$user['id'], $ip]);
            $_SESSION['login_log_id'] = $db->lastInsertId();
            logActivity($user['id'], 'LOGIN', 'Auth', 'User logged in');
            if ($user['must_change_password']) redirect(BASE_URL . '/home.php?force_change=1');
            redirect(BASE_URL . ($user['role']==='super_admin' ? '/super_admin/dashboard.php' : '/barangay_admin/dashboard.php'));
        } else {
            if ($user) {
                $newAttempts = (int)($user['login_attempts'] ?? 0) + 1;
                if ($newAttempts >= $maxAttempts) {
                    // Permanent lock — requires superadmin to unblock
                    $db->prepare("UPDATE users SET login_attempts=?, locked_until=NULL, login_locked=1 WHERE id=?")->execute([$newAttempts, $user['id']]);
                    $lockedName = trim($user['first_name'].' '.$user['last_name']);
                    logActivity($user['id'], 'ACCOUNT_LOCKED', 'Auth', "Permanently locked after {$newAttempts} failed attempt(s). Requires superadmin unlock.");
                    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                    notifySuperAdmins(
                        "🔒 Account <strong>{$lockedName}</strong> (@{$user['username']}) has been <strong>permanently locked</strong> after {$newAttempts} failed login attempt(s) from IP <code>{$ip}</code>. Go to <strong>User Management</strong> to unlock.",
                        'danger', 'system'
                    );
                    $loginError = 'Too many failed attempts. Your account has been locked. Please contact the System Administrator to regain access.';
                } else {
                    $db->prepare("UPDATE users SET login_attempts=? WHERE id=?")->execute([$newAttempts, $user['id']]);
                    $remaining  = $maxAttempts - $newAttempts;
                    $loginError = "Invalid username or password. {$remaining} attempt(s) remaining before lockout.";
                }
            } else {
                $loginError = 'Invalid username or password.';
            }
        }
    } else {
        $loginError = 'Please fill in all fields.';
    }
}
renderPage:

// ── Check for reset token in URL ───────────────────────────
$urlResetToken = sanitize($_GET['reset_token'] ?? '');
$resetTokenValid = false;
if ($urlResetToken) {
    $chk = $db->prepare("SELECT id FROM password_resets WHERE token=? AND used=0 AND expires_at > NOW() LIMIT 1");
    $chk->execute([$urlResetToken]);
    $resetTokenValid = (bool)$chk->fetch();
}

// ── Load landing content ───────────────────────────────────
$lc = null;
try { $lc = $db->query("SELECT * FROM landing_content WHERE id=1")->fetch(); } catch (PDOException $e) {}

// ── Update welcome_desc in DB if it still has the old default ──
$_newDesc = 'Talavera, known as the "Vegetable Basket of Nueva Ecija," is a 1st class municipality located in the western part of Nueva Ecija, Central Luzon. Bordered by Cabanatuan City to the south and Munoz City to the north, it spans 140.92 km2 across 53 barangays with a total population of 137,444 (2024 census). Home to landmarks such as Rizal Park and St. Isidore the Worker Parish Church, Talavera is a thriving community where agriculture, trade, and local governance work hand in hand.';
$_oldDescs = [
    'Empowering local government units with modern technology for better service delivery, transparent governance, and efficient community management.',
    'Talavera, known as the &amp;quot;Vegetable Basket of Nueva Ecija,&amp;quot;',
];
if ($lc && in_array(trim($lc['welcome_desc']), $_oldDescs, true)) {
    try {
        $db->prepare("UPDATE landing_content SET welcome_desc=? WHERE id=1")->execute([$_newDesc]);
        $lc['welcome_desc'] = $_newDesc;
    } catch (\Throwable $e) {}
}
unset($_newDesc, $_oldDescs);

// ── Update contact info in DB if it still has placeholder defaults ──
$_contactPatches = [];
if ($lc && in_array(trim($lc['contact_phone'] ?? ''), ['+63 (XXX) XXX-XXXX', ''], true))
    $_contactPatches['contact_phone']   = '(044) 958-0032';
if ($lc && in_array(trim($lc['contact_email'] ?? ''), ['lgu@example.gov.ph', ''], true))
    $_contactPatches['contact_email']   = 'lgu.talavera@gmail.com';
if ($lc && in_array(trim($lc['contact_address'] ?? ''), ['Local Government Unit Office', ''], true))
    $_contactPatches['contact_address'] = 'Mahalagang Bayan ng Talavera, Nueva Ecija, Philippines 3114';
if (!empty($_contactPatches)) {
    try {
        $setClauses = implode(', ', array_map(fn($k) => "$k=?", array_keys($_contactPatches)));
        $stmt = $db->prepare("UPDATE landing_content SET $setClauses WHERE id=1");
        $stmt->execute(array_values($_contactPatches));
        foreach ($_contactPatches as $k => $v) $lc[$k] = $v;
    } catch (\Throwable $e) {}
}
unset($_contactPatches);

if (!$lc) {
    $lc = [
        'welcome_title'   => 'Efficient. Secure. Centralized.',
        'welcome_subtitle'=> 'Data Management for Local Governance',
        'welcome_desc'    => 'Talavera, known as the "Vegetable Basket of Nueva Ecija," is a 1st class municipality located in the western part of Nueva Ecija, Central Luzon. Bordered by Cabanatuan City to the south and Munoz City to the north, it spans 140.92 km2 across 53 barangays with a total population of 137,444 (2024 census). Home to landmarks such as Rizal Park and St. Isidore the Worker Parish Church, Talavera is a thriving community where agriculture, trade, and local governance work hand in hand.',
        'about_text'      => 'This system centralizes barangay records, improves data accessibility, and enhances reporting, monitoring, and decision-making for local government units.',
        'contact_phone'   => '(044) 958-0032',
        'contact_email'   => 'lgu.talavera@gmail.com',
        'contact_address' => 'Mahalagang Bayan ng Talavera, Nueva Ecija, Philippines 3114',
        'logo1_path'=>'','logo2_path'=>'','banner_path'=>'',
    ];
}
$logo1 = !empty($lc['logo1_path']) ? UPLOAD_URL.$lc['logo1_path'] : BASE_URL.'/assets/img/logo 1.jpg';
$logo2 = !empty($lc['logo2_path']) ? UPLOAD_URL.$lc['logo2_path'] : BASE_URL.'/assets/img/logo 2.jpg';

// Determine which panel to show on load
$initialPanel = 'login';
if ($urlResetToken && $resetTokenValid)  $initialPanel = 'reset';
if ($urlResetToken && !$resetTokenValid) $initialPanel = 'expired';
if (isLoggedIn() && ($_SESSION['must_change_password'] ?? false)) $initialPanel = 'changePassword';
if (isset($_GET['force_change']) && isLoggedIn()) $initialPanel = 'changePassword';

// Debug: log token check result (remove after testing)
if ($urlResetToken) {
    error_log("[LGU] Reset token attempt: " . substr($urlResetToken,0,8) . "... Valid: " . ($resetTokenValid ? 'YES' : 'NO'));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favcon.png">
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favcon.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES) ?>">
    <title>Centralized LGU Information Management System</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/home.css?v=2.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
</head>
<body>

<!-- ========== HEADER ========== -->
<header class="header">
    <div class="container">
        <div class="header-content">
            <div class="logo-section">
                <img src="<?= htmlspecialchars($logo1) ?>" alt="LGU Logo" class="logo">
                <div class="system-name">
                    <h1>Centralized LGU</h1>
                    <p>Information Management System</p>
                </div>
            </div>
            <nav class="nav-menu">
                <a href="#home"     class="nav-link active" data-page="page-home">Home</a>
                <a href="#features" class="nav-link"        data-page="page-features">Features</a>
                <a href="#about"    class="nav-link"        data-page="page-about">About</a>
                <a href="#contact"  class="nav-link"        data-page="page-contact">Contact</a>
            </nav>
        </div>
    </div>
</header>

<!-- ========== PAGE: HOME ========== -->
<div class="page-section active" id="page-home">
<?php
$bannerUrl = !empty($lc['banner_path']) ? UPLOAD_URL . $lc['banner_path'] : BASE_URL . '/assets/img/bgimage.png';
?>
<section class="hero-section" id="home">

    <!-- Right side: building photo (CSS positioned) -->
    <div class="hero-photo" style="background-image: url('<?= htmlspecialchars($bannerUrl) ?>');"></div>

    <!-- Left side gradient fade over photo -->
    <div class="hero-fade"></div>

    <!-- Left side: text content -->
    <div class="container hero-container">
        <div class="hero-left">
            <p class="hero-badge"><i class="fas fa-shield-alt"></i> Official Government System</p>
            <h2 class="hero-title"><?= nl2br(htmlspecialchars($lc['welcome_title'])) ?></h2>
            <p class="hero-subtitle"><?= htmlspecialchars($lc['welcome_subtitle']) ?></p>
            <p class="hero-description"><?= htmlspecialchars($lc['welcome_desc']) ?></p>

            <!-- CTA Buttons -->
            <div class="hero-cta-buttons">
                <button class="hero-btn hero-btn-login" onclick="openLoginPanel()">
                    <i class="fas fa-sign-in-alt"></i> Login
                </button>
            </div>

            <!-- Stats -->
            <div class="stats-container">
                <div class="stat-card">
                    <i class="fas fa-users"></i>
                    <div class="stat-info">
                        <h3 id="totalResidents">—</h3>
                        <p>Total Residents</p>
                    </div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-user-shield"></i>
                    <div class="stat-info">
                        <h3 id="activeUsers">—</h3>
                        <p>Active Users</p>
                    </div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-database"></i>
                    <div class="stat-info">
                        <h3 id="recordsStored">—</h3>
                        <p>Records Stored</p>
                    </div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-map-marker-alt"></i>
                    <div class="stat-info">
                        <h3 id="totalBarangays">—</h3>
                        <p>Barangays</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bottom tagline bar -->
    <div class="hero-tagline">
        <span><em>"A Stronger Talavera Through Transparent and Data-Driven Governance."</em></span>
    </div>

    <!-- LOGIN FORM OVERLAY (centered, appears when Login clicked) -->
    <div id="loginOverlay" style="display:none;">
        <div class="login-overlay-inner">
            <div class="card-flipper" id="cardFlipper">

                    <!-- ═══ FACE 0: ROLE PICKER ═══ -->
                    <div class="flip-face" id="faceRolePicker">
                        <div class="login-card">
                            <div class="login-header">
                                <i class="fas fa-landmark"></i>
                                <h3>Welcome</h3>
                                <span class="role-hint">Select your account type to continue</span>
                            </div>

                            <div class="role-picker-grid">
                                <button class="role-pick-btn" onclick="pickRole('super_admin')">
                                    <div class="role-pick-icon"><i class="fas fa-shield-alt"></i></div>
                                    <div class="role-pick-label">LGU System Admin</div>
                                    <div class="role-pick-sub">Super Administrator</div>
                                </button>
                                <button class="role-pick-btn" onclick="pickRole('barangay_admin')">
                                    <div class="role-pick-icon"><i class="fas fa-map-marker-alt"></i></div>
                                    <div class="role-pick-label">Barangay Admin</div>
                                    <div class="role-pick-sub">Barangay Officer</div>
                                </button>
                            </div>

                            <div class="security-note" style="margin-top:18px;">
                                <i class="fas fa-shield-alt"></i>
                                <p>All data is protected under the <strong>Data Privacy Act of 2012</strong> (RA 10173)</p>
                            </div>
                        </div>
                    </div><!-- /faceRolePicker -->

                    <!-- ═══ FACE 1: LOGIN ═══ -->
                    <div class="flip-face" id="faceLogin" style="display:none;">
                        <div class="login-card">

                            <!-- New-user yellow notice — always visible -->
                            <div id="signupNotice" style="display:flex; background:#fffbeb; border:1px solid #fbbf24; color:#92400e; border-radius:8px; padding:8px 12px; font-size:0.78rem; align-items:flex-start; gap:7px; margin-bottom:12px;">
                                <i class="fas fa-exclamation-triangle" style="color:#d97706; font-size:0.85rem; margin-top:2px; flex-shrink:0;"></i>
                                <span><strong>New user?</strong> Use your admin-provided credentials. You'll be asked to change your password on first login.</span>
                            </div>
                            <div class="login-header">
                                <i class="fas fa-lock" id="loginRoleIcon"></i>
                                <h3 id="loginRoleTitle">System Login</h3>
                                <span class="role-hint" id="loginRoleHint">
                                    <i class="fas fa-user-tag"></i> System Admin &nbsp;/&nbsp; Barangay Admin
                                </span>
                            </div>

                            <form class="login-form" id="loginForm" onsubmit="submitLogin(event)">
                                <?php if ($loginError): ?>
                                <div class="alert alert-error" id="loginAlert">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <?= htmlspecialchars($loginError) ?>
                                </div>
                                <?php else: ?>
                                <div class="alert alert-error" id="loginAlert" style="display:none;"></div>
                                <?php endif; ?>

                                <?php if (isset($_GET['error']) && $_GET['error'] === 'unauthorized'): ?>
                                <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> Unauthorized access. Please log in.</div>
                                <?php endif; ?>
                                <?php if (isset($_GET['msg']) && $_GET['msg'] === 'password_changed'): ?>
                                <div class="alert" style="background:#f0f8ff;border:1px solid #b8e8f0;color:#0d3a5c;border-radius:8px;padding:10px 14px;font-size:0.85rem;display:flex;align-items:center;gap:8px;">
                                    <i class="fas fa-check-circle"></i> Password changed successfully. Please log in.
                                </div>
                                <?php endif; ?>

                                <div class="form-group">
                                    <label for="username"><i class="fas fa-user"></i> Username / Email</label>
                                    <input type="text" id="username" name="username" required
                                           placeholder="Enter your username or email" autocomplete="username">
                                </div>
                                <div class="form-group">
                                    <label for="password"><i class="fas fa-key"></i> Password</label>
                                    <div class="password-wrapper">
                                        <input type="password" id="password" name="password" required
                                               placeholder="Enter your password" autocomplete="current-password">
                                        <button type="button" class="toggle-pw" onclick="togglePassword()" title="Show/Hide">
                                            <i class="fas fa-eye" id="pwIcon"></i>
                                        </button>
                                    </div>
                                </div>
                                <button type="submit" class="btn-login" id="loginBtn">
                                    <i class="fas fa-sign-in-alt"></i> Login to System
                                </button>
                                <div class="login-footer">
                                    <a href="#" class="forgot-password" onclick="showPanel('forgot');return false;">
                                        <i class="fas fa-question-circle"></i> Forgot Password?
                                    </a>
                                    <a href="#" class="forgot-password" onclick="showPanel('rolePicker');return false;" style="color:#94a3b8;">
                                        <i class="fas fa-arrow-left"></i> Back to account type
                                    </a>
                                </div>
                            </form>

                            <div class="security-note">
                                <i class="fas fa-shield-alt"></i>
                                <p>All data is protected under the <strong>Data Privacy Act of 2012</strong> (RA 10173)</p>
                            </div>
                        </div>
                    </div><!-- /faceLogin -->

                    <!-- ═══ FACE 2: FORGOT PASSWORD ═══ -->
                    <div class="flip-face" id="faceForgot" style="display:none;">
                        <div class="login-card">
                            <div class="login-header">
                                <i class="fas fa-envelope" style="color:#2563eb;"></i>
                                <h3>Forgot Password?</h3>
                                <span class="role-hint">Enter your registered email address</span>
                            </div>

                            <div class="alert alert-error" id="forgotAlert" style="display:none;"></div>
                            <div class="alert" id="forgotSuccess" style="display:none;background:#f0f8ff;border:1px solid #b8e8f0;color:#0d3a5c;border-radius:8px;padding:12px 14px;font-size:0.85rem;"></div>

                            <div class="form-group" id="forgotEmailGroup">
                                <label><i class="fas fa-envelope"></i> Email Address</label>
                                <input type="email" id="forgotEmail" placeholder="your@email.com" autocomplete="email">
                            </div>

                            <button class="btn-login" id="forgotBtn" onclick="submitForgot()" style="background:linear-gradient(135deg,#2563eb,#1a3a6b);">
                                <i class="fas fa-paper-plane"></i> Send Reset Link
                            </button>

                            <div class="login-footer" style="margin-top:14px;">
                                <a href="#" class="forgot-password" onclick="showPanel('login');return false;">
                                    <i class="fas fa-arrow-left"></i> Back to Login
                                </a>
                            </div>
                        </div>
                    </div><!-- /faceForgot -->

                    <!-- ═══ FACE 3: RESET PASSWORD ═══ -->
                    <div class="flip-face" id="faceReset" style="display:none;">
                        <div class="login-card">
                            <div class="login-header">
                                <i class="fas fa-key" style="color:#059669;"></i>
                                <h3>Set New Password</h3>
                                <span class="role-hint">Choose a strong new password</span>
                            </div>

                            <div class="alert alert-error" id="resetAlert" style="display:none;"></div>
                            <div class="alert" id="resetSuccess" style="display:none;background:#f0f8ff;border:1px solid #b8e8f0;color:#0d3a5c;border-radius:8px;padding:12px 14px;font-size:0.85rem;"></div>

                            <input type="hidden" id="resetTokenInput" value="<?= htmlspecialchars($urlResetToken) ?>">

                            <div class="form-group">
                                <label><i class="fas fa-lock"></i> New Password</label>
                                <div class="password-wrapper">
                                    <input type="password" id="newPassword" placeholder="Min 8 chars, letters + numbers"
                                           oninput="checkPwStrength(this.value)">
                                    <button type="button" class="toggle-pw" onclick="toggleVis('newPassword','newPwIcon')">
                                        <i class="fas fa-eye" id="newPwIcon"></i>
                                    </button>
                                </div>
                                <div style="background:#e2e8f0;border-radius:3px;height:4px;margin-top:6px;">
                                    <div id="pwStrengthBar" style="height:4px;border-radius:3px;width:0%;transition:width .3s,background .3s;"></div>
                                </div>
                                <small id="pwStrengthLabel" style="font-size:0.72rem;"></small>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-lock"></i> Confirm Password</label>
                                <div class="password-wrapper">
                                    <input type="password" id="confirmPassword" placeholder="Repeat new password">
                                    <button type="button" class="toggle-pw" onclick="toggleVis('confirmPassword','confPwIcon')">
                                        <i class="fas fa-eye" id="confPwIcon"></i>
                                    </button>
                                </div>
                            </div>

                            <button class="btn-login" id="resetBtn" onclick="submitReset()" style="background:linear-gradient(135deg,#059669,#047857);">
                                <i class="fas fa-check"></i> Reset Password
                            </button>

                            <div class="login-footer" style="margin-top:14px;">
                                <a href="#" class="forgot-password" onclick="showPanel('login');return false;">
                                    <i class="fas fa-arrow-left"></i> Back to Login
                                </a>
                            </div>
                        </div>
                    </div><!-- /faceReset -->

                    <!-- ═══ FACE 4: CHANGE PASSWORD (First Login) ═══ -->
                    <div class="flip-face" id="faceChangePassword" style="display:none;">
                        <div class="login-card">
                            <div class="login-header">
                                <i class="fas fa-key" style="color:#d97706;"></i>
                                <h3>Change Your Password</h3>
                                <span class="role-hint" style="color:#fbbf24;">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    Required before you can continue
                                </span>
                            </div>

                            <div class="alert" style="background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:8px;padding:10px 14px;font-size:0.82rem;display:flex;align-items:center;gap:8px;margin-bottom:14px;">
                                <i class="fas fa-info-circle"></i>
                                Your account requires a new password on first login.
                            </div>

                            <div class="alert alert-error" id="cpAlert" style="display:none;"></div>
                            <div class="alert" id="cpSuccess" style="display:none;background:#f0f8ff;border:1px solid #b8e8f0;color:#0d3a5c;border-radius:8px;padding:12px 14px;font-size:0.85rem;"></div>

                            <div class="form-group">
                                <label><i class="fas fa-lock"></i> Current (Temporary) Password</label>
                                <div class="password-wrapper">
                                    <input type="password" id="cpCurrent" placeholder="Enter your temporary password">
                                    <button type="button" class="toggle-pw" onclick="toggleVis('cpCurrent','cpCurrentIcon')">
                                        <i class="fas fa-eye" id="cpCurrentIcon"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-lock"></i> New Password</label>
                                <div class="password-wrapper">
                                    <input type="password" id="cpNew" placeholder="Min 8 chars, letters + numbers"
                                           oninput="checkPwStrengthCp(this.value)">
                                    <button type="button" class="toggle-pw" onclick="toggleVis('cpNew','cpNewIcon')">
                                        <i class="fas fa-eye" id="cpNewIcon"></i>
                                    </button>
                                </div>
                                <div style="background:#e2e8f0;border-radius:3px;height:4px;margin-top:6px;">
                                    <div id="cpStrengthBar" style="height:4px;border-radius:3px;width:0%;transition:width .3s,background .3s;"></div>
                                </div>
                                <small id="cpStrengthLabel" style="font-size:0.72rem;"></small>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-lock"></i> Confirm New Password</label>
                                <div class="password-wrapper">
                                    <input type="password" id="cpConfirm" placeholder="Repeat new password"
                                           oninput="checkCpMatch()">
                                    <button type="button" class="toggle-pw" onclick="toggleVis('cpConfirm','cpConfirmIcon')">
                                        <i class="fas fa-eye" id="cpConfirmIcon"></i>
                                    </button>
                                </div>
                                <small id="cpMatchLabel" style="font-size:0.72rem;"></small>
                            </div>

                            <button class="btn-login" id="cpBtn" onclick="submitChangePassword()"
                                    style="background:linear-gradient(135deg,#d97706,#b45309);">
                                <i class="fas fa-check"></i> Set New Password
                            </button>
                        </div>
                    </div><!-- /faceChangePassword -->

                    <!-- ═══ FACE 5: SUPERADMIN ACCOUNT RECOVERY ═══ -->
                    <div class="flip-face" id="faceRecovery" style="display:none;">
                        <div class="login-card">
                            <div class="login-header">
                                <i class="fas fa-lock" style="color:#c2410c;font-size:1.6rem;"></i>
                                <h3>Account Locked</h3>
                                <span class="role-hint" style="color:#fca5a5;">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    Too many failed login attempts
                                </span>
                            </div>

                            <input type="hidden" id="recoveryIdentifier" value="">

                            <div class="alert alert-error" id="recoveryAlert" style="display:none;"></div>
                            <div class="alert" id="recoverySuccess" style="display:none;background:#f0f8ff;border:1px solid #b8e8f0;color:#0d3a5c;border-radius:8px;padding:12px 14px;font-size:0.85rem;"></div>

                            <!-- ── Option chooser ── -->
                            <div id="recoveryOptions">
                                <p style="font-size:0.83rem;color:#475569;text-align:center;margin:0 0 14px;">
                                    Choose how you want to unlock your account:
                                </p>

                                <!-- Option 1: Email code -->
                                <button class="btn-login" id="recoveryOptEmailBtn"
                                        onclick="chooseRecoveryOption('email')"
                                        style="background:var(--primary-blue);margin-bottom:10px;min-height:48px;">
                                    <i class="fas fa-envelope me-2"></i> Send recovery code to my email
                                </button>

                                <!-- Option 2: Admin approval -->
                                <button class="btn-login" id="recoveryOptApproveBtn"
                                        onclick="chooseRecoveryOption('approval')"
                                        style="background:var(--secondary-blue);min-height:48px;">
                                    <i class="fas fa-user-shield me-2"></i> Request approval from another admin
                                </button>
                            </div>

                            <!-- ── Email code section ── -->
                            <div id="recoveryEmailSection" style="display:none;">
                                <div class="alert" style="background:#f0fdf4;border:1px solid #86efac;color:#14532d;border-radius:8px;padding:10px 14px;font-size:0.82rem;display:flex;align-items:center;gap:8px;margin-bottom:14px;">
                                    <i class="fas fa-envelope" style="flex-shrink:0;"></i>
                                    <span>A 6-digit recovery code has been sent to your registered email. Enter it below.</span>
                                </div>
                                <div class="form-group">
                                    <label><i class="fas fa-key"></i> Recovery Code</label>
                                    <input type="text" id="recoveryCode" placeholder="Enter 6-digit code"
                                           maxlength="6" inputmode="numeric" autocomplete="one-time-code"
                                           style="font-size:1.4rem;letter-spacing:0.3em;text-align:center;font-family:monospace;"
                                           oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                                </div>
                                <button class="btn-login" id="recoveryVerifyBtn" onclick="submitRecovery()"
                                        style="background:linear-gradient(135deg,#1a4f7a,#2d9f50);margin-bottom:10px;">
                                    <i class="fas fa-unlock-alt"></i> Unlock My Account
                                </button>
                                <div style="text-align:center;">
                                    <a href="#" style="font-size:0.82rem;color:#2563eb;" id="recoveryResendLink"
                                       onclick="requestRecoveryCode(true);return false;">
                                        <i class="fas fa-redo"></i> Resend code
                                    </a>
                                </div>
                                <div style="text-align:center;margin-top:8px;">
                                    <a href="#" style="font-size:0.8rem;color:#64748b;" onclick="showRecoveryOptions();return false;">
                                        <i class="fas fa-arrow-left"></i> Back to options
                                    </a>
                                </div>
                            </div>

                            <!-- ── Admin approval section ── -->
                            <div id="recoveryApprovalSection" style="display:none;">
                                <div class="alert" style="background:#faf5ff;border:1px solid #c4b5fd;color:#4c1d95;border-radius:8px;padding:10px 14px;font-size:0.82rem;display:flex;align-items:center;gap:8px;margin-bottom:14px;" id="approvalStatusBanner">
                                    <i class="fas fa-hourglass-half" style="flex-shrink:0;"></i>
                                    <span id="approvalStatusText">Sending approval request to active administrators…</span>
                                </div>
                                <div style="text-align:center;padding:10px 0 6px;">
                                    <i class="fas fa-user-shield" style="font-size:2rem;color:#8b5cf6;opacity:0.7;"></i>
                                    <p style="font-size:0.83rem;color:#475569;margin:10px 0 0;">
                                        An unlock request has been sent to all active System Administrators.
                                        This page will automatically refresh once your account is unlocked.
                                    </p>
                                </div>
                                <div style="text-align:center;margin-top:14px;">
                                    <a href="#" style="font-size:0.8rem;color:#64748b;" onclick="showRecoveryOptions();return false;">
                                        <i class="fas fa-arrow-left"></i> Back to options
                                    </a>
                                </div>
                            </div>

                            <div class="login-footer" style="margin-top:12px;">
                                <a href="#" class="forgot-password" onclick="showPanel('login');return false;">
                                    <i class="fas fa-arrow-left"></i> Back to Login
                                </a>
                            </div>
                        </div>
                    </div><!-- /faceRecovery -->

                </div><!-- /cardFlipper -->
            </div><!-- /login-overlay-inner -->
        </div><!-- /loginOverlay -->

</section>
</div><!-- /page-home -->

<!-- ========== PAGE: FEATURES ========== -->
<div class="page-section" id="page-features">
<!-- ========== KEY FEATURES ========== -->
<section class="features-section" id="features">
    <div class="container">
        <div class="section-header">
            <h2>Key Features</h2>
            <div class="header-line"></div>
            <p>Comprehensive tools designed for modern local governance</p>
        </div>

        <div class="features-grid">
            <div class="feature-card">
                <div class="feature-icon"><i class="fas fa-folder-open"></i></div>
                <h3>Records Management</h3>
                <p>Manage residents, households, and officials data efficiently in one place</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon"><i class="fas fa-chart-line"></i></div>
                <h3>Reports &amp; Analytics</h3>
                <p>Generate comprehensive reports and gain actionable data insights</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon"><i class="fas fa-search"></i></div>
                <h3>Search &amp; Filtering</h3>
                <p>Quick and advanced search capabilities across all records</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon"><i class="fas fa-lock"></i></div>
                <h3>Secure Role-Based Access</h3>
                <p>Multi-level security with granular role-based permissions</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon"><i class="fas fa-archive"></i></div>
                <h3>Archiving System</h3>
                <p>Organized archival and retrieval of historical records and documents</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon"><i class="fas fa-clipboard-list"></i></div>
                <h3>Activity &amp; Audit Logs</h3>
                <p>Complete tracking of all system activities and data changes</p>
            </div>
        </div>
    </div>
</section>
</div><!-- /page-features -->

<!-- ========== PAGE: ABOUT ========== -->
<div class="page-section" id="page-about">

<!-- ========== SYSTEM OVERVIEW ========== -->
<section class="overview-section" id="about">
    <div class="container">
        <div class="section-header">
            <h2>About the System</h2>
            <div class="header-line"></div>
        </div>
        <p class="overview-text">
            <?= nl2br(htmlspecialchars($lc['about_text'])) ?>
        </p>
    </div>
</section>


</div><!-- /page-about -->

<!-- ========== PAGE: CONTACT ========== -->
<div class="page-section" id="page-contact">

<!-- ========== FOOTER ========== -->
<footer class="footer" id="contact">
    <div class="container">
        <div class="footer-content">
            <div class="footer-section">
                <h3><i class="fas fa-landmark"></i> Centralized LGU System</h3>
                <p>Empowering local governance through modern technology and transparent data management.</p>
                <div class="current-datetime">
                    <i class="far fa-clock"></i>
                    <span id="currentDateTime">Loading...</span>
                </div>
            </div>

            <div class="footer-section">
                <h4>LGU Contact Information</h4>
                <?php if (!empty($lc['contact_address'])): ?>
                <p><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($lc['contact_address']) ?></p>
                <?php else: ?>
                <p><i class="fas fa-map-marker-alt"></i> Local Government Unit Office</p>
                <?php endif; ?>
                <?php if (!empty($lc['contact_phone'])): ?>
                <p><i class="fas fa-phone"></i> <?= htmlspecialchars($lc['contact_phone']) ?></p>
                <?php else: ?>
                <p><i class="fas fa-phone"></i> +63 (XXX) XXX-XXXX</p>
                <?php endif; ?>
                <?php if (!empty($lc['contact_email'])): ?>
                <p><i class="fas fa-envelope"></i> <?= htmlspecialchars($lc['contact_email']) ?></p>
                <?php else: ?>
                <p><i class="fas fa-envelope"></i> lgu@example.gov.ph</p>
                <?php endif; ?>
            </div>

            <div class="footer-section">
                <h4>Quick Links</h4>
                <ul class="footer-links">
                    <li><a href="#home"><i class="fas fa-chevron-right"></i> Home</a></li>
                    <li><a href="#features"><i class="fas fa-chevron-right"></i> Features</a></li>
                    <li><a href="#about"><i class="fas fa-chevron-right"></i> About</a></li>
                    <li><a href="#contact"><i class="fas fa-chevron-right"></i> Contact</a></li>
                </ul>
            </div>

            <div class="footer-section">
                <h4>Legal &amp; Compliance</h4>
                <p><i class="fas fa-shield-alt"></i> Data Privacy Act Compliant</p>
                <p><i class="fas fa-balance-scale"></i> Republic Act No. 10173</p>
                <p><i class="fas fa-certificate"></i> Official Government System</p>
            </div>
        </div>

        <div class="footer-bottom">
            <p>&copy; 2026 Centralized LGU Information Management System. All rights reserved.</p>
            <p>Developed for efficient local governance and community service.</p>
        </div>
    </div>
</footer>
</div><!-- /page-contact -->

<script>
// ── AJAX Login ────────────────────────────────────────────
function submitLogin(e) {
    e.preventDefault();
    const username = document.getElementById('username').value.trim();
    const password = document.getElementById('password').value;
    const btn      = document.getElementById('loginBtn');
    const errEl    = document.getElementById('loginAlert');

    errEl.style.display = 'none';

    if (!username || !password) {
        showErr(errEl, 'Please fill in all fields.');
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Logging in...';

    fetch('home.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'ajax_action=login'
            + '&username=' + encodeURIComponent(username)
            + '&password=' + encodeURIComponent(password)
            + '&csrf_token=' + encodeURIComponent(document.querySelector('meta[name="csrf-token"]')?.content || '')
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-sign-in-alt"></i> Login to System';

        if (data.csrf_token) {
            const m = document.querySelector('meta[name="csrf-token"]');
            if (m) m.setAttribute('content', data.csrf_token);
        }

        if (!data.ok) {
            if (data.login_locked) {
                if (data.sole_sa_locked) {
                    // Only superadmin — show 2-option recovery panel
                    document.getElementById('recoveryIdentifier').value = data.identifier || '';
                    // Ensure options view is shown (not email/approval sub-sections)
                    document.getElementById('recoveryOptions').style.display         = '';
                    document.getElementById('recoveryEmailSection').style.display    = 'none';
                    document.getElementById('recoveryApprovalSection').style.display = 'none';
                    document.getElementById('recoveryAlert').style.display           = 'none';
                    document.getElementById('recoverySuccess').style.display         = 'none';
                    if (typeof _approvalPollTimer !== 'undefined' && _approvalPollTimer) {
                        clearInterval(_approvalPollTimer); _approvalPollTimer = null;
                    }
                    // Refresh CSRF then switch panel
                    fetch('<?= BASE_URL ?>/ajax/get_csrf_token.php')
                        .then(r => r.json())
                        .then(t => { if (t.csrf_token) { const m = document.querySelector('meta[name="csrf-token"]'); if (m) m.setAttribute('content', t.csrf_token); } })
                        .catch(() => {});
                    showPanel('recovery');
                } else {
                    // Prominent locked notice — disable the button until page reload
                    errEl.style.display    = 'flex';
                    errEl.innerHTML        = '<i class="fas fa-lock" style="font-size:1.1rem;flex-shrink:0;"></i><span>' + data.msg + '</span>';
                    errEl.style.background = '#fff7ed';
                    errEl.style.border     = '1px solid #fb923c';
                    errEl.style.color      = '#9a3412';
                    btn.disabled           = true;
                    btn.innerHTML          = '<i class="fas fa-lock me-1"></i> Account Locked';
                    btn.style.background   = '#d1d5db';
                    btn.style.cursor       = 'not-allowed';
                }
            } else {
                showErr(errEl, data.msg);
            }
            // Refresh CSRF token so the next login attempt works without a reload
            fetch('<?= BASE_URL ?>/ajax/get_csrf_token.php')
                .then(r => r.json())
                .then(t => {
                    if (t.csrf_token) {
                        const m = document.querySelector('meta[name="csrf-token"]');
                        if (m) m.setAttribute('content', t.csrf_token);
                    }
                })
                .catch(() => {});
            return;
        }
        if (data.must_change) {
            // Smooth transition to change password panel
            showPanel('changePassword');
            return;
        }
        if (data.redirect) {
            window.location.href = data.redirect;
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-sign-in-alt"></i> Login to System';
        showErr(errEl, 'Something went wrong. Please try again.');
    });
}

// ── Panel switching with slide transition ──────────────────
const panels = { rolePicker:'faceRolePicker', login:'faceLogin', forgot:'faceForgot', reset:'faceReset', changePassword:'faceChangePassword', recovery:'faceRecovery' };
let currentPanel = 'rolePicker';
let isAnimating  = false;

function showPanel(name) {
    if (!panels[name] || currentPanel === name || isAnimating) return;

    const from = document.getElementById(panels[currentPanel]);
    const to   = document.getElementById(panels[name]);
    if (!from || !to) return;

    isAnimating = true;

    // Fade out current
    from.style.transition = 'opacity 0.25s ease-out';
    from.style.opacity    = '0';

    setTimeout(() => {
        from.style.display = 'none';
        from.style.opacity = '';
        from.style.transition = '';

        // Fade in new
        to.style.display   = 'block';
        to.style.opacity   = '0';
        to.style.transition= 'opacity 0.25s ease-in';

        requestAnimationFrame(() => {
            to.style.opacity = '1';
        });

        setTimeout(() => {
            to.style.transition = '';
            currentPanel = name;
            isAnimating  = false;
        }, 260);
    }, 260);
}

// ── Init: make sure only login panel is visible ────────────
// ── Login overlay open/close ──────────────────────────────
function openLoginPanel() {
    const overlay = document.getElementById('loginOverlay');
    if (!overlay) return;
    const notice = document.getElementById('signupNotice');
    if (notice) notice.style.display = 'flex';
    showPanel('rolePicker');
    overlay.style.display = 'flex';
    requestAnimationFrame(() => overlay.classList.add('overlay-visible'));
    document.body.style.overflow = 'hidden';
    document.querySelectorAll('.page-section').forEach(s => s.style.overflow = 'hidden');
}

function pickRole(role) {
    const icon  = document.getElementById('loginRoleIcon');
    const title = document.getElementById('loginRoleTitle');
    const hint  = document.getElementById('loginRoleHint');
    if (role === 'super_admin') {
        icon.className  = 'fas fa-shield-alt';
        icon.style.color = '#052659';
        title.textContent = 'LGU System Admin Login';
        hint.innerHTML  = '<i class="fas fa-shield-alt"></i> Super Administrator';
    } else {
        icon.className  = 'fas fa-map-marker-alt';
        icon.style.color = '#5483B3';
        title.textContent = 'Barangay Admin Login';
        hint.innerHTML  = '<i class="fas fa-map-marker-alt"></i> Barangay Officer';
    }
    // Clear previous input
    document.getElementById('username').value = '';
    document.getElementById('password').value = '';
    const err = document.getElementById('loginAlert');
    if (err) err.style.display = 'none';
    showPanel('login');
}

function closeLoginOverlay() {
    const overlay = document.getElementById('loginOverlay');
    if (!overlay) return;
    overlay.classList.remove('overlay-visible');
    setTimeout(() => {
        overlay.style.display = 'none';
        document.body.style.overflow = '';
        // Restore page section scroll
        document.querySelectorAll('.page-section').forEach(s => s.style.overflow = '');
    }, 300);
}

// Close on backdrop click or Escape key
document.addEventListener('DOMContentLoaded', () => {
    const overlay = document.getElementById('loginOverlay');
    if (overlay) {
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) closeLoginOverlay();
        });
    }
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeLoginOverlay();
    });
});

function openSignupInfo()      { /* no-op — signup removed */ }
function switchPanelToLogin()  { openLoginPanel(); }
let loginPanelOpen = true;

document.addEventListener('DOMContentLoaded', () => {
    // Ensure correct initial state
    document.getElementById('faceRolePicker').style.display      = 'block';
    document.getElementById('faceLogin').style.display           = 'none';
    document.getElementById('faceForgot').style.display        = 'none';
    document.getElementById('faceReset').style.display         = 'none';
    document.getElementById('faceChangePassword').style.display= 'none';
    document.getElementById('faceRecovery').style.display      = 'none';

    // ── Load live stats from database ─────────────────────
    function animateCount(el, target) {
        const duration = 1400;
        const start    = performance.now();
        const from     = 0;
        function step(now) {
            const progress = Math.min((now - start) / duration, 1);
            const ease     = 1 - Math.pow(1 - progress, 3); // ease-out cubic
            const current  = Math.round(from + (target - from) * ease);
            el.textContent = current.toLocaleString();
            if (progress < 1) requestAnimationFrame(step);
        }
        requestAnimationFrame(step);
    }

    fetch('<?= BASE_URL ?>/ajax/get_landing_stats.php')
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                animateCount(document.getElementById('totalResidents'), data.residents);
                animateCount(document.getElementById('activeUsers'),    data.users);
                animateCount(document.getElementById('recordsStored'),  data.records);
                animateCount(document.getElementById('totalBarangays'), data.barangays);
            }
        })
        .catch(() => {
            // silently fail — keep showing —
        });

    const initial = '<?= $initialPanel ?>';

    if (initial === 'reset' || initial === 'changePassword' || initial === 'expired') {
        // Auto-open overlay for special flows
        const overlay = document.getElementById('loginOverlay');
        if (overlay) {
            overlay.style.display = 'flex';
            requestAnimationFrame(() => overlay.classList.add('overlay-visible'));
        }
        if (initial === 'reset') {
            currentPanel = 'login';
            setTimeout(() => showPanel('reset'), 100);
        } else if (initial === 'changePassword') {
            currentPanel = 'login';
            setTimeout(() => showPanel('changePassword'), 100);
        } else if (initial === 'expired') {
            const alertEl = document.getElementById('loginAlert');
            alertEl.innerHTML = '<i class="fas fa-exclamation-circle"></i> This password reset link has <strong>expired or already been used</strong>. Please use the Forgot Password option to request a new one.';
            alertEl.style.display = 'flex';
        }
    }
});

// ── Forgot password AJAX ───────────────────────────────────
function submitForgot() {
    const email = document.getElementById('forgotEmail').value.trim();
    const btn   = document.getElementById('forgotBtn');
    const errEl = document.getElementById('forgotAlert');
    const okEl  = document.getElementById('forgotSuccess');

    errEl.style.display = 'none';
    okEl.style.display  = 'none';

    if (!email) { showErr(errEl, 'Please enter your email address.'); return; }

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';

    fetch('home.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'ajax_action=forgot&email=' + encodeURIComponent(email)
            + '&csrf_token=' + encodeURIComponent(document.querySelector('meta[name="csrf-token"]')?.content || '')
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Reset Link';
        if (data.ok) {
            document.getElementById('forgotEmailGroup').style.display = 'none';
            btn.style.display = 'none';
            okEl.innerHTML = '<i class="fas fa-check-circle" style="margin-right:6px;"></i>' + data.msg;
            okEl.style.display = 'block';
        } else {
            showErr(errEl, data.msg);
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Reset Link';
        showErr(errEl, 'Something went wrong. Please try again.');
    });
}

// ── Reset password AJAX ────────────────────────────────────
function submitReset() {
    const token   = document.getElementById('resetTokenInput').value;
    const newPass = document.getElementById('newPassword').value;
    const confPass= document.getElementById('confirmPassword').value;
    const btn     = document.getElementById('resetBtn');
    const errEl   = document.getElementById('resetAlert');
    const okEl    = document.getElementById('resetSuccess');

    errEl.style.display = 'none';
    okEl.style.display  = 'none';

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Resetting...';

    fetch('home.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'ajax_action=reset&reset_token=' + encodeURIComponent(token)
             + '&new_password=' + encodeURIComponent(newPass)
             + '&confirm_password=' + encodeURIComponent(confPass)
             + '&csrf_token=' + encodeURIComponent(document.querySelector('meta[name="csrf-token"]')?.content || '')
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check"></i> Reset Password';
        if (data.ok) {
            okEl.innerHTML = '<i class="fas fa-check-circle" style="margin-right:6px;"></i>' + data.msg;
            okEl.style.display = 'block';
            document.getElementById('newPassword').closest('.form-group').style.display='none';
            document.getElementById('confirmPassword').closest('.form-group').style.display='none';
            btn.style.display = 'none';
            // Auto-switch to login after 2s
            setTimeout(() => showPanel('login'), 2000);
        } else {
            if (data.expired) {
                showErr(errEl, data.msg + ' <a href="#" onclick="showPanel(\'forgot\');return false;">Request new link</a>');
            } else {
                showErr(errEl, data.msg);
            }
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check"></i> Reset Password';
        showErr(errEl, 'Something went wrong. Please try again.');
    });
}

function showErr(el, msg) {
    el.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + msg;
    el.style.display = 'flex';
}

// ── Password visibility toggles ───────────────────────────
function togglePassword() {
    const pw = document.getElementById('password');
    const icon = document.getElementById('pwIcon');
    pw.type = pw.type === 'password' ? 'text' : 'password';
    icon.classList.toggle('fa-eye'); icon.classList.toggle('fa-eye-slash');
}
function toggleVis(id, iconId) {
    const el = document.getElementById(id);
    const ic = document.getElementById(iconId);
    el.type = el.type === 'password' ? 'text' : 'password';
    ic.classList.toggle('fa-eye'); ic.classList.toggle('fa-eye-slash');
}

// ── Password strength meter ────────────────────────────────
function checkPwStrength(val) {
    const bar = document.getElementById('pwStrengthBar');
    const lbl = document.getElementById('pwStrengthLabel');
    let score = 0;
    if (val.length >= 8)             score++;
    if (/[A-Z]/.test(val))           score++;
    if (/[0-9]/.test(val))           score++;
    if (/[^A-Za-z0-9]/.test(val))    score++;
    if (val.length >= 12)            score++;
    const lvls = [
        {w:'20%',bg:'#ef4444',t:'Very weak'},
        {w:'40%',bg:'#f97316',t:'Weak'},
        {w:'60%',bg:'#eab308',t:'Fair'},
        {w:'80%',bg:'#22c55e',t:'Strong'},
        {w:'100%',bg:'#16a34a',t:'Very strong'},
    ];
    const l = lvls[Math.max(0,score-1)];
    bar.style.width = val.length ? l.w : '0%';
    bar.style.background = l.bg;
    lbl.textContent = val.length ? l.t : '';
    lbl.style.color = l.bg;
}

// ── Change Password (first login) ─────────────────────────
function checkPwStrengthCp(val) {
    const bar = document.getElementById('cpStrengthBar');
    const lbl = document.getElementById('cpStrengthLabel');
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
    const l = lvls[Math.max(0,score-1)];
    bar.style.width      = val.length ? l.w : '0%';
    bar.style.background = l.bg;
    lbl.textContent      = val.length ? l.t : '';
    lbl.style.color      = l.bg;
    checkCpMatch();
}

function checkCpMatch() {
    const np = document.getElementById('cpNew').value;
    const cp = document.getElementById('cpConfirm').value;
    const ml = document.getElementById('cpMatchLabel');
    if (!cp) { ml.textContent = ''; return; }
    if (np === cp) {
        ml.textContent = '✓ Passwords match';
        ml.style.color = '#16a34a';
    } else {
        ml.textContent = '✗ Passwords do not match';
        ml.style.color = '#ef4444';
    }
}

function submitChangePassword() {
    const current = document.getElementById('cpCurrent').value;
    const newPass = document.getElementById('cpNew').value;
    const confirm = document.getElementById('cpConfirm').value;
    const btn     = document.getElementById('cpBtn');
    const errEl   = document.getElementById('cpAlert');
    const okEl    = document.getElementById('cpSuccess');

    errEl.style.display = 'none';
    okEl.style.display  = 'none';

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

    fetch('home.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'ajax_action=change_password'
            + '&current_password=' + encodeURIComponent(current)
            + '&new_password='     + encodeURIComponent(newPass)
            + '&confirm_password=' + encodeURIComponent(confirm)
            + '&csrf_token=' + encodeURIComponent(document.querySelector('meta[name="csrf-token"]')?.content || '')
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check"></i> Set New Password';
        if (data.csrf_token) {
            const m = document.querySelector('meta[name="csrf-token"]');
            if (m) m.setAttribute('content', data.csrf_token);
        }
        if (data.ok) {
            okEl.innerHTML = '<i class="fas fa-check-circle" style="margin-right:6px;"></i>' + data.msg;
            okEl.style.display = 'block';
            document.getElementById('cpCurrent').closest('.form-group').style.display = 'none';
            document.getElementById('cpNew').closest('.form-group').style.display     = 'none';
            document.getElementById('cpConfirm').closest('.form-group').style.display = 'none';
            btn.style.display = 'none';
            setTimeout(() => {
                const dest = data.role === 'super_admin'
                    ? '<?= BASE_URL ?>/super_admin/dashboard.php'
                    : '<?= BASE_URL ?>/barangay_admin/dashboard.php';
                window.location.href = dest;
            }, 1500);
        } else {
            showErr(errEl, data.msg);
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check"></i> Set New Password';
        showErr(errEl, 'Something went wrong. Please try again.');
    });
}

// ── Self-recovery helpers ─────────────────────────────────
let _approvalPollTimer = null;

function showRecoveryOptions() {
    document.getElementById('recoveryOptions').style.display        = '';
    document.getElementById('recoveryEmailSection').style.display   = 'none';
    document.getElementById('recoveryApprovalSection').style.display = 'none';
    document.getElementById('recoveryAlert').style.display          = 'none';
    document.getElementById('recoverySuccess').style.display        = 'none';
    if (_approvalPollTimer) { clearInterval(_approvalPollTimer); _approvalPollTimer = null; }
}

function chooseRecoveryOption(opt) {
    document.getElementById('recoveryOptions').style.display = 'none';
    document.getElementById('recoveryAlert').style.display   = 'none';
    document.getElementById('recoverySuccess').style.display = 'none';

    if (opt === 'email') {
        document.getElementById('recoveryEmailSection').style.display     = '';
        document.getElementById('recoveryApprovalSection').style.display  = 'none';
        requestRecoveryCode(false);
    } else {
        document.getElementById('recoveryEmailSection').style.display     = 'none';
        document.getElementById('recoveryApprovalSection').style.display  = '';
        requestAdminApproval();
    }
}

// ── Option 1: Email code — request/resend ─────────────────
function requestRecoveryCode(isResend) {
    const identifier = document.getElementById('recoveryIdentifier').value.trim();
    if (!identifier) {
        showErr(document.getElementById('recoveryAlert'), 'Unable to determine account. Please go back to Login and try again.');
        return;
    }
    const resendLink = document.getElementById('recoveryResendLink');
    if (resendLink) { resendLink.style.pointerEvents = 'none'; resendLink.style.opacity = '0.5'; }

    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    fetch('<?= BASE_URL ?>/ajax/superadmin_recovery.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ sub_action: 'send', identifier, csrf_token: csrf })
    })
    .then(r => r.json())
    .then(data => {
        if (resendLink) { resendLink.style.pointerEvents = ''; resendLink.style.opacity = ''; }
        const el = document.getElementById('recoveryAlert');
        const ok = document.getElementById('recoverySuccess');
        if (data.ok && isResend) {
            el.style.display = 'none';
            ok.style.display = 'flex';
            ok.textContent   = 'A new recovery code has been sent to your email.';
            setTimeout(() => { ok.style.display = 'none'; }, 5000);
        } else if (!data.ok) {
            showErr(el, data.msg);
        }
        fetch('<?= BASE_URL ?>/ajax/get_csrf_token.php').then(r=>r.json()).then(t=>{ const m=document.querySelector('meta[name="csrf-token"]'); if(m&&t.csrf_token) m.setAttribute('content',t.csrf_token); }).catch(()=>{});
    })
    .catch(() => {
        if (resendLink) { resendLink.style.pointerEvents = ''; resendLink.style.opacity = ''; }
        showErr(document.getElementById('recoveryAlert'), 'Something went wrong. Please try again.');
    });
}

// ── Option 1: Email code — verify ────────────────────────
function submitRecovery() {
    const errEl      = document.getElementById('recoveryAlert');
    const okEl       = document.getElementById('recoverySuccess');
    const btn        = document.getElementById('recoveryVerifyBtn');
    const identifier = document.getElementById('recoveryIdentifier').value.trim();
    const code       = document.getElementById('recoveryCode').value.trim();

    errEl.style.display = 'none';
    okEl.style.display  = 'none';

    if (!code || code.length !== 6) {
        showErr(errEl, 'Please enter the 6-digit recovery code from your email.');
        return;
    }
    if (!identifier) {
        showErr(errEl, 'Account identifier missing. Please go back to Login and try again.');
        return;
    }

    btn.disabled  = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Verifying…';

    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    fetch('<?= BASE_URL ?>/ajax/superadmin_recovery.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ sub_action: 'verify', identifier, code, csrf_token: csrf })
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled  = false;
        btn.innerHTML = '<i class="fas fa-unlock-alt"></i> Unlock My Account';
        if (data.ok) {
            document.getElementById('recoveryCode').value = '';
            okEl.style.display = 'flex';
            okEl.textContent   = data.msg + ' Redirecting to login…';
            document.getElementById('recoveryEmailSection').style.display = 'none';
            setTimeout(() => { showPanel('login'); setTimeout(() => { okEl.style.display = 'none'; document.getElementById('recoveryEmailSection').style.display = ''; }, 600); }, 2200);
        } else {
            showErr(errEl, data.msg);
        }
        fetch('<?= BASE_URL ?>/ajax/get_csrf_token.php').then(r=>r.json()).then(t=>{ const m=document.querySelector('meta[name="csrf-token"]'); if(m&&t.csrf_token) m.setAttribute('content',t.csrf_token); }).catch(()=>{});
    })
    .catch(() => {
        btn.disabled  = false;
        btn.innerHTML = '<i class="fas fa-unlock-alt"></i> Unlock My Account';
        showErr(errEl, 'Something went wrong. Please try again.');
    });
}

// ── Option 2: Admin approval — request & poll ─────────────
function requestAdminApproval() {
    const identifier = document.getElementById('recoveryIdentifier').value.trim();
    const statusText = document.getElementById('approvalStatusText');
    const banner     = document.getElementById('approvalStatusBanner');
    if (!identifier) {
        showErr(document.getElementById('recoveryAlert'), 'Account identifier missing. Please go back to Login and try again.');
        showRecoveryOptions();
        return;
    }

    // Send approval request
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    fetch('<?= BASE_URL ?>/ajax/superadmin_recovery.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ sub_action: 'request_approval', identifier, csrf_token: csrf })
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            banner.style.background = '#faf5ff';
            banner.style.borderColor = '#c4b5fd';
            banner.style.color = '#4c1d95';
            statusText.textContent = 'Unlock request sent! Waiting for a System Administrator to approve…';
            // Poll every 5 seconds to check if unlock was approved
            _approvalPollTimer = setInterval(pollApprovalStatus, 5000);
        } else {
            showErr(document.getElementById('recoveryAlert'), data.msg);
            document.getElementById('recoveryApprovalSection').style.display = 'none';
            document.getElementById('recoveryOptions').style.display = '';
        }
        fetch('<?= BASE_URL ?>/ajax/get_csrf_token.php').then(r=>r.json()).then(t=>{ const m=document.querySelector('meta[name="csrf-token"]'); if(m&&t.csrf_token) m.setAttribute('content',t.csrf_token); }).catch(()=>{});
    })
    .catch(() => {
        showErr(document.getElementById('recoveryAlert'), 'Failed to send request. Please try again.');
        document.getElementById('recoveryApprovalSection').style.display = 'none';
        document.getElementById('recoveryOptions').style.display = '';
    });
}

function pollApprovalStatus() {
    const identifier = document.getElementById('recoveryIdentifier').value.trim();
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    fetch('<?= BASE_URL ?>/ajax/superadmin_recovery.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ sub_action: 'check_approval', identifier, csrf_token: csrf })
    })
    .then(r => r.json())
    .then(data => {
        fetch('<?= BASE_URL ?>/ajax/get_csrf_token.php').then(r=>r.json()).then(t=>{ const m=document.querySelector('meta[name="csrf-token"]'); if(m&&t.csrf_token) m.setAttribute('content',t.csrf_token); }).catch(()=>{});
        if (data.unlocked) {
            clearInterval(_approvalPollTimer);
            _approvalPollTimer = null;
            const banner     = document.getElementById('approvalStatusBanner');
            const statusText = document.getElementById('approvalStatusText');
            banner.style.background  = '#f0fdf4';
            banner.style.borderColor = '#86efac';
            banner.style.color       = '#14532d';
            banner.querySelector('i').className = 'fas fa-check-circle';
            statusText.textContent = 'Your account has been unlocked! Redirecting to login…';
            setTimeout(() => { showPanel('login'); showRecoveryOptions(); }, 2500);
        }
    })
    .catch(() => {});
}
</script>
<script src="<?= BASE_URL ?>/assets/js/home.js"></script>
</body>
</html>
