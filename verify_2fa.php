<?php
require_once __DIR__ . '/config/config.php';

// Must have a pending 2FA session — not yet fully logged in
if (empty($_SESSION['2fa_pending_user_id'])) {
    redirect(BASE_URL . '/index.php');
}

require_once __DIR__ . '/helpers/totp.php';

$db     = Database::getInstance()->getConnection();
$userId = (int)$_SESSION['2fa_pending_user_id'];

// Fetch the user
$stmt = $db->prepare("SELECT * FROM users WHERE id = ? AND status = 'active' LIMIT 1");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user || !$user['two_fa_enabled'] || !$user['totp_secret']) {
    // 2FA not set up — clear pending and redirect to login
    unset($_SESSION['2fa_pending_user_id']);
    redirect(BASE_URL . '/index.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrAbort();
    $code = trim($_POST['code'] ?? '');

    if (!$code) {
        $error = 'Please enter the 6-digit code.';
    } elseif (!TOTP::verify($user['totp_secret'], $code)) {
        $error = 'Invalid or expired code. Please try again.';
        logActivity($userId, '2FA_FAILED', 'Auth', '2FA verification failed');
    } else {
        // ✅ 2FA passed — complete login
        unset($_SESSION['2fa_pending_user_id']);
        loginUser($user);

        // Log login
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $db->prepare("INSERT INTO login_logs (user_id, ip_address) VALUES (?,?)")->execute([$userId, $ip]);
        $_SESSION['login_log_id'] = $db->lastInsertId();

        logActivity($userId, 'LOGIN', 'Auth', 'User logged in with 2FA');

        if ($user['must_change_password']) {
            redirect(BASE_URL . '/change_password.php');
        }

        redirect(BASE_URL . '/super_admin/dashboard.php');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favcon.png">
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favcon.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Two-Factor Authentication — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/login.css?v=2.0">
    <style>
        .otp-input {
            letter-spacing: 0.5rem;
            font-size: 1.8rem;
            font-weight: 700;
            text-align: center;
            width: 100%;
            border: 2px solid #ced4da;
            border-radius: 10px;
            padding: 12px;
            transition: border-color 0.2s;
        }
        .otp-input:focus {
            border-color: #1a4f7a;
            box-shadow: 0 0 0 3px rgba(26,79,122,0.15);
            outline: none;
        }
        .shield-icon {
            width: 72px; height: 72px;
            background: linear-gradient(135deg, #1a4f7a, #3a7fa8);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 16px;
        }
    </style>
</head>
<body>
<div class="login-wrapper">
    <div class="login-card" style="max-width:420px;">
        <div class="login-header">
            <div class="shield-icon">
                <i class="bi bi-shield-lock-fill text-white" style="font-size:2rem;"></i>
            </div>
            <h4>Two-Factor Authentication</h4>
            <p class="text-muted small">Enter the 6-digit code from your authenticator app</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger d-flex align-items-center gap-2 py-2 mb-3">
                <i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <?= csrfField() ?>
            <div class="mb-4">
                <input type="text" name="code" id="codeInput"
                       class="otp-input"
                       placeholder="000000"
                       maxlength="6"
                       inputmode="numeric"
                       pattern="[0-9]{6}"
                       autofocus
                       oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                <div class="form-text text-center mt-2">
                    <i class="bi bi-clock me-1"></i>Code refreshes every 30 seconds
                </div>
            </div>
            <button type="submit" class="btn btn-primary w-100 btn-login">
                <i class="bi bi-check-circle me-2"></i>Verify
            </button>
        </form>

        <div class="text-center mt-3">
            <a href="<?= BASE_URL ?>/index.php" class="small text-muted text-decoration-none">
                <i class="bi bi-arrow-left me-1"></i>Back to Login
            </a>
        </div>

        <div class="login-footer">
            <small class="text-muted">© <?= date('Y') ?> LGU System. All rights reserved.</small>
        </div>
    </div>
</div>
<script>
// Auto-submit when 6 digits entered
document.getElementById('codeInput').addEventListener('input', function () {
    if (this.value.length === 6) {
        this.closest('form').submit();
    }
});
</script>
</body>
</html>
