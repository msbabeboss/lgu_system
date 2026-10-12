<?php
require_once __DIR__ . '/config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$u  = currentUser();

$mustChange = $_SESSION['must_change_password'] ?? false;
$error   = '';
$success = '';

// Non-forced change: redirect to the settings page
if (!$mustChange && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (isSuperAdmin()) {
        redirect(BASE_URL . '/super_admin/settings.php?tab=profile&action=change_pw');
    } else {
        redirect(BASE_URL . '/barangay_admin/settings.php?tab=profile&action=change_pw');
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrAbort();
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password']     ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!$current || !$new || !$confirm) {
        $error = 'All fields are required.';
    } elseif ($new !== $confirm) {
        $error = 'New passwords do not match.';
    } else {
        $policyErr = validatePasswordPolicy($db, $new);
        if ($policyErr) {
            $error = $policyErr;
        } else {
        $stmt = $db->prepare("SELECT password FROM users WHERE id=?");
        $stmt->execute([$u['id']]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($current, $user['password'])) {
            $error = 'Current password is incorrect.';
        } else {
            $hashed = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
            $db->prepare("UPDATE users SET password=?, must_change_password=0 WHERE id=?")
               ->execute([$hashed, $u['id']]);
            logActivity($u['id'], 'CHANGE_PASSWORD', 'Auth', 'Forced password change on first login');
            logoutUser();
            redirect(BASE_URL . '/home.php?msg=password_changed');
        }
        }
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
    <title>Change Password — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/home.css?v=2.0">
    <style>
        body {
            min-height: 100vh;
            background: linear-gradient(135deg, #06111e 0%, #1a4f7a 50%, #3a7fa8 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', sans-serif;
            padding: 20px;
        }
        .cp-wrap {
            width: 100%;
            max-width: 420px;
        }
        .cp-card {
            background: rgba(255,255,255,0.97);
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.35);
            overflow: hidden;
        }
        .cp-header {
            background: linear-gradient(135deg, #1a4f7a, #3a7fa8);
            padding: 28px 28px 22px;
            text-align: center;
        }
        .cp-header .cp-icon {
            width: 60px; height: 60px;
            background: rgba(255,255,255,0.15);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 14px;
            border: 2px solid rgba(255,255,255,0.3);
        }
        .cp-header h4 {
            color: #fff;
            font-weight: 700;
            margin: 0 0 4px;
            font-size: 1.15rem;
        }
        .cp-header p {
            color: rgba(255,255,255,0.7);
            font-size: 0.8rem;
            margin: 0;
        }
        .cp-body { padding: 28px; }
        .form-group { margin-bottom: 18px; }
        .form-group label {
            display: block;
            font-size: 0.82rem;
            font-weight: 600;
            color: #1a4f7a;
            margin-bottom: 6px;
        }
        .pw-wrap {
            position: relative;
        }
        .pw-wrap input {
            width: 100%;
            padding: 10px 42px 10px 14px;
            border: 1.5px solid #d0dce8;
            border-radius: 10px;
            font-size: 0.9rem;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
            background: #f8fafc;
            color: #1a2e1f;
        }
        .pw-wrap input:focus {
            border-color: #3a7fa8;
            box-shadow: 0 0 0 3px rgba(58,127,168,0.15);
            background: #fff;
        }
        .pw-wrap .toggle-btn {
            position: absolute;
            right: 12px; top: 50%;
            transform: translateY(-50%);
            background: none; border: none;
            color: #3a7fa8; cursor: pointer;
            font-size: 0.9rem; padding: 0;
        }
        .pw-wrap .toggle-btn:hover { color: #1a4f7a; }
        .strength-bar-bg {
            background: #e2e8f0;
            border-radius: 3px;
            height: 4px;
            margin-top: 6px;
        }
        .strength-bar {
            height: 4px;
            border-radius: 3px;
            width: 0%;
            transition: width 0.3s, background 0.3s;
        }
        .strength-label {
            font-size: 0.7rem;
            color: #3a7fa8;
            margin-top: 3px;
            display: block;
        }
        .btn-submit {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #1a4f7a, #3a7fa8);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: opacity 0.2s, transform 0.15s;
            margin-top: 6px;
        }
        .btn-submit:hover { opacity: 0.92; transform: translateY(-1px); }
        .btn-submit:active { transform: translateY(0); }
        .alert-box {
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 0.83rem;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .alert-error   { background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; }
        .alert-success { background: #f0f8ff; border: 1px solid #b8e8f0; color: #0d3a5c; }
        .alert-warning { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
        .back-link {
            display: block;
            text-align: center;
            margin-top: 16px;
            font-size: 0.82rem;
            color: #3a7fa8;
            text-decoration: none;
        }
        .back-link:hover { color: #1a4f7a; }
    </style>
</head>
<body>
<div class="cp-wrap">
    <div class="cp-card">
        <!-- Header -->
        <div class="cp-header">
            <div class="cp-icon">
                <i class="bi bi-key-fill text-white" style="font-size:1.6rem;"></i>
            </div>
            <h4>Change Password</h4>
            <p>
                <?php if ($mustChange): ?>
                    You must set a new password before continuing.
                <?php else: ?>
                    Update your account password
                <?php endif; ?>
            </p>
        </div>

        <!-- Body -->
        <div class="cp-body">

            <?php if ($mustChange): ?>
            <div class="alert-box alert-warning">
                <i class="bi bi-exclamation-triangle-fill"></i>
                Your account requires a password change before you can access the system.
            </div>
            <?php endif; ?>

            <?php if ($error): ?>
            <div class="alert-box alert-error">
                <i class="bi bi-x-circle-fill"></i>
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <?php if ($success): ?>
            <div class="alert-box alert-success">
                <i class="bi bi-check-circle-fill"></i>
                <?= htmlspecialchars($success) ?>
            </div>
            <?php endif; ?>

            <form method="POST" id="cpForm">
                <?= csrfField() ?>
                <!-- Current Password -->
                <div class="form-group">
                    <label><i class="bi bi-lock me-1"></i>Current Password</label>
                    <div class="pw-wrap">
                        <input type="password" name="current_password" id="currentPw"
                               placeholder="Enter your current password" required autocomplete="current-password">
                        <button type="button" class="toggle-btn" onclick="toggleVis('currentPw','iconCurrent')">
                            <i class="bi bi-eye" id="iconCurrent"></i>
                        </button>
                    </div>
                </div>

                <!-- New Password -->
                <div class="form-group">
                    <label><i class="bi bi-lock-fill me-1"></i>New Password</label>
                    <div class="pw-wrap">
                        <input type="password" name="new_password" id="newPw"
                               placeholder="Min 8 chars, letters + numbers"
                               required autocomplete="new-password"
                               oninput="checkStrength(this.value)">
                        <button type="button" class="toggle-btn" onclick="toggleVis('newPw','iconNew')">
                            <i class="bi bi-eye" id="iconNew"></i>
                        </button>
                    </div>
                    <div class="strength-bar-bg">
                        <div class="strength-bar" id="strengthBar"></div>
                    </div>
                    <span class="strength-label" id="strengthLabel"></span>
                </div>

                <!-- Confirm Password -->
                <div class="form-group">
                    <label><i class="bi bi-lock-fill me-1"></i>Confirm New Password</label>
                    <div class="pw-wrap">
                        <input type="password" name="confirm_password" id="confirmPw"
                               placeholder="Repeat new password"
                               required autocomplete="new-password">
                        <button type="button" class="toggle-btn" onclick="toggleVis('confirmPw','iconConfirm')">
                            <i class="bi bi-eye" id="iconConfirm"></i>
                        </button>
                    </div>
                    <span class="strength-label" id="matchLabel"></span>
                </div>

                <button type="submit" class="btn-submit">
                    <i class="bi bi-check-lg me-1"></i>Change Password
                </button>
            </form>

            <?php if (!$mustChange): ?>
            <a href="<?= isSuperAdmin() ? BASE_URL.'/super_admin/dashboard.php' : BASE_URL.'/barangay_admin/dashboard.php' ?>"
               class="back-link">
                <i class="bi bi-arrow-left me-1"></i>Back to Dashboard
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function toggleVis(inputId, iconId) {
    const inp  = document.getElementById(inputId);
    const icon = document.getElementById(iconId);
    if (inp.type === 'password') {
        inp.type = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        inp.type = 'password';
        icon.className = 'bi bi-eye';
    }
}

function checkStrength(val) {
    const bar   = document.getElementById('strengthBar');
    const label = document.getElementById('strengthLabel');
    let score = 0;
    if (val.length >= 8)                        score++;
    if (/[A-Z]/.test(val))                      score++;
    if (/[0-9]/.test(val))                      score++;
    if (/[^A-Za-z0-9]/.test(val))              score++;
    if (val.length >= 12)                       score++;

    const levels = [
        { pct: '0%',   bg: 'transparent', text: '' },
        { pct: '25%',  bg: '#ef4444',     text: 'Weak' },
        { pct: '50%',  bg: '#f97316',     text: 'Fair' },
        { pct: '75%',  bg: '#eab308',     text: 'Good' },
        { pct: '90%',  bg: '#22c55e',     text: 'Strong' },
        { pct: '100%', bg: '#16a34a',     text: 'Very Strong' },
    ];
    const lvl = levels[Math.min(score, 5)];
    bar.style.width      = lvl.pct;
    bar.style.background = lvl.bg;
    label.textContent    = lvl.text;
    label.style.color    = lvl.bg;

    // Also check match
    checkMatch();
}

function checkMatch() {
    const np = document.getElementById('newPw').value;
    const cp = document.getElementById('confirmPw').value;
    const ml = document.getElementById('matchLabel');
    if (!cp) { ml.textContent = ''; return; }
    if (np === cp) {
        ml.textContent = '✓ Passwords match';
        ml.style.color = '#16a34a';
    } else {
        ml.textContent = '✗ Passwords do not match';
        ml.style.color = '#ef4444';
    }
}

document.getElementById('confirmPw').addEventListener('input', checkMatch);
</script>
</body>
</html>
