<?php
/**
 * Superadmin Self-Recovery
 *
 * Sub-actions:
 *   send             — generate & email a 6-digit recovery code (Option 1)
 *   verify           — validate the code and clear the lock (Option 1)
 *   request_approval — send an unlock notification to all other active SAs (Option 2)
 *   check_approval   — poll: is the account still locked? (Option 2 — client polls)
 *   approve_unlock   — called by an SA inside the system to approve the unlock
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/mailer.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'Method not allowed.']);
    exit;
}

verifyCsrfOrAbort();

$db        = Database::getInstance()->getConnection();
$subAction = sanitize($_POST['sub_action'] ?? '');

// ── Ensure tables exist ───────────────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS superadmin_recovery_codes (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id     INT UNSIGNED NOT NULL,
        code        VARCHAR(10)  NOT NULL,
        expires_at  DATETIME     NOT NULL,
        used        TINYINT(1)   DEFAULT 0,
        created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
} catch (\Throwable $e) {}

try {
    $db->exec("CREATE TABLE IF NOT EXISTS superadmin_unlock_requests (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id     INT UNSIGNED NOT NULL,
        status      ENUM('pending','approved','expired') DEFAULT 'pending',
        approved_by INT UNSIGNED NULL,
        created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        updated_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
} catch (\Throwable $e) {}

// ─────────────────────────────────────────────────────────
// Helper: find a locked active superadmin by username/email
// ─────────────────────────────────────────────────────────
function findLockedSA(PDO $db, string $identifier): array|false
{
    $stmt = $db->prepare("
        SELECT * FROM users
        WHERE (username = ? OR email = ?)
          AND role = 'super_admin'
          AND status = 'active'
          AND login_locked = 1
        LIMIT 1
    ");
    $stmt->execute([$identifier, $identifier]);
    return $stmt->fetch();
}

// ─────────────────────────────────────────────────────────
// SUB-ACTION: send  (email recovery code)
// ─────────────────────────────────────────────────────────
if ($subAction === 'send') {
    $identifier = sanitize($_POST['identifier'] ?? '');

    if (!$identifier) {
        echo json_encode(['ok' => false, 'msg' => 'Please enter your username or email.']);
        exit;
    }

    $user = findLockedSA($db, $identifier);

    // Always return success to prevent enumeration
    echo json_encode([
        'ok'  => true,
        'msg' => 'If that account is locked, a recovery code has been sent to its email address.',
    ]);

    if (!$user || empty($user['email'])) { exit; }

    // Invalidate old codes
    $db->prepare("UPDATE superadmin_recovery_codes SET used=1 WHERE user_id=? AND used=0")
       ->execute([$user['id']]);

    $code      = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    $expiresAt = date('Y-m-d H:i:s', time() + 900); // 15 min

    $db->prepare("INSERT INTO superadmin_recovery_codes (user_id, code, expires_at) VALUES (?,?,?)")
       ->execute([$user['id'], $code, $expiresAt]);

    logActivity($user['id'], 'RECOVERY_CODE_SENT', 'Auth',
        'Self-recovery code sent to email (locked superadmin)');

    $name    = trim($user['first_name'] . ' ' . $user['last_name']);
    $appName = APP_NAME;
    $year    = date('Y');
    $html    = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#e8f4fb;font-family:'Segoe UI',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#e8f4fb;padding:40px 0;">
  <tr><td align="center">
    <table width="560" cellpadding="0" cellspacing="0"
           style="background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.10);">
      <tr>
        <td style="background:linear-gradient(135deg,#1a4f7a,#2d9f50);padding:32px 40px;text-align:center;">
          <div style="font-size:2.5rem;">🔓</div>
          <h1 style="color:#fff;font-size:1.3rem;margin:10px 0 4px;font-weight:700;">Account Recovery Code</h1>
          <p style="color:rgba(255,255,255,0.8);font-size:0.85rem;margin:0;">$appName — System Administrator</p>
        </td>
      </tr>
      <tr>
        <td style="padding:36px 40px;">
          <p style="color:#334155;font-size:0.95rem;margin:0 0 16px;">Hello <strong>$name</strong>,</p>
          <p style="color:#334155;font-size:0.9rem;line-height:1.7;margin:0 0 24px;">
            Your administrator account has been locked due to too many failed login attempts.
            Use the recovery code below to unlock your account.
          </p>
          <div style="text-align:center;background:#f0f9ff;border:2px dashed #2d9f50;border-radius:12px;
                      padding:24px 16px;margin:0 0 24px;">
            <p style="font-size:0.75rem;color:#64748b;margin:0 0 8px;font-weight:600;
                      letter-spacing:0.1em;text-transform:uppercase;">Your Recovery Code</p>
            <p style="font-size:2.6rem;font-weight:900;letter-spacing:0.35em;color:#1a4f7a;
                      margin:0;font-family:monospace;">$code</p>
            <p style="font-size:0.78rem;color:#e11d48;margin:10px 0 0;">
              ⏱ Expires in <strong>15 minutes</strong>
            </p>
          </div>
          <p style="color:#334155;font-size:0.88rem;line-height:1.6;margin:0 0 20px;">
            Return to the login page, select <em>"Send recovery code to my email"</em>, and enter this code to unlock your account.
          </p>
          <div style="background:#fff8e1;border:1px solid #ffe082;border-radius:8px;padding:14px 18px;">
            <p style="color:#7a5c00;font-size:0.8rem;margin:0;">
              ⚠️ <strong>Do not share this code.</strong>
              Change your password immediately after logging in.
            </p>
          </div>
        </td>
      </tr>
      <tr>
        <td style="padding:16px 40px;background:#f8fafc;border-top:1px solid #e2e8f0;text-align:center;">
          <p style="color:#94a3b8;font-size:0.75rem;margin:0;">© $year $appName &mdash; Official Government System</p>
          <p style="color:#94a3b8;font-size:0.72rem;margin:4px 0 0;">This is an automated message — please do not reply.</p>
        </td>
      </tr>
    </table>
  </td></tr>
</table>
</body>
</html>
HTML;

    try {
        sendMail($user['email'], $name, "Account Recovery Code — $appName", $html);
    } catch (\Throwable $e) {
        error_log('[Recovery] Email send failed: ' . $e->getMessage());
    }
    exit;
}

// ─────────────────────────────────────────────────────────
// SUB-ACTION: verify  (check OTP code)
// ─────────────────────────────────────────────────────────
if ($subAction === 'verify') {
    $identifier = sanitize($_POST['identifier'] ?? '');
    $code       = trim($_POST['code'] ?? '');

    if (!$identifier || !$code) {
        echo json_encode(['ok' => false, 'msg' => 'Username/email and recovery code are required.']);
        exit;
    }

    $user = findLockedSA($db, $identifier);
    if (!$user) {
        echo json_encode(['ok' => false, 'msg' => 'Account not found or not currently locked.']);
        exit;
    }

    $codeStmt = $db->prepare("
        SELECT * FROM superadmin_recovery_codes
        WHERE user_id  = ?
          AND code     = ?
          AND used     = 0
          AND expires_at > NOW()
        LIMIT 1
    ");
    $codeStmt->execute([$user['id'], $code]);
    $row = $codeStmt->fetch();

    if (!$row) {
        echo json_encode(['ok' => false, 'msg' => 'Invalid or expired recovery code. Please request a new one.']);
        exit;
    }

    // Mark code used + clear lock
    $db->prepare("UPDATE superadmin_recovery_codes SET used=1 WHERE id=?")->execute([$row['id']]);
    $db->prepare("UPDATE users SET login_attempts=0, locked_until=NULL, login_locked=0 WHERE id=?")
       ->execute([$user['id']]);
    // Also mark any pending unlock requests as approved-by-self
    $db->prepare("UPDATE superadmin_unlock_requests SET status='approved', approved_by=? WHERE user_id=? AND status='pending'")
       ->execute([$user['id'], $user['id']]);

    logActivity($user['id'], 'SELF_RECOVERY_UNLOCK', 'Auth',
        'Account unlocked via email recovery code');

    echo json_encode(['ok' => true, 'msg' => 'Account unlocked successfully! You may now log in.']);
    exit;
}

// ─────────────────────────────────────────────────────────
// SUB-ACTION: request_approval
// ─────────────────────────────────────────────────────────
if ($subAction === 'request_approval') {
    $identifier = sanitize($_POST['identifier'] ?? '');

    if (!$identifier) {
        echo json_encode(['ok' => false, 'msg' => 'Account identifier is required.']);
        exit;
    }

    $user = findLockedSA($db, $identifier);
    if (!$user) {
        echo json_encode(['ok' => false, 'msg' => 'Locked account not found. Please try the email option instead.']);
        exit;
    }

    // Check if there is any other active superadmin who can approve
    $otherSA = $db->prepare("
        SELECT COUNT(*) FROM users
        WHERE role = 'super_admin' AND status = 'active' AND id != ?
    ");
    $otherSA->execute([$user['id']]);
    if ((int) $otherSA->fetchColumn() === 0) {
        echo json_encode(['ok' => false, 'msg' => 'No other System Administrator found. Please use the email recovery option instead.']);
        exit;
    }

    // Expire any old pending requests for this user
    $db->prepare("UPDATE superadmin_unlock_requests SET status='expired' WHERE user_id=? AND status='pending'")
       ->execute([$user['id']]);

    // Create a new pending request
    $db->prepare("INSERT INTO superadmin_unlock_requests (user_id, status) VALUES (?, 'pending')")
       ->execute([$user['id']]);
    $requestId = $db->lastInsertId();

    logActivity($user['id'], 'UNLOCK_REQUEST_SENT', 'Auth',
        'Superadmin unlock approval request submitted by locked account');

    $lockedName = trim($user['first_name'] . ' ' . $user['last_name']);
    $approveUrl = BASE_URL . '/super_admin/users.php?unlock_request=' . $requestId;

    // Notify all other active superadmins via in-system notification
    $othersStmt = $db->prepare("
        SELECT id FROM users
        WHERE role = 'super_admin' AND status = 'active' AND id != ?
    ");
    $othersStmt->execute([$user['id']]);
    $otherIds = $othersStmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($otherIds as $saId) {
        sendNotification(
            (int) $saId,
            "🔒 <strong>{$lockedName}</strong> (@{$user['username']}) is locked out and is requesting you to unlock their account. <a href=\"{$approveUrl}\" style=\"color:#2563eb;font-weight:600;\">Review &amp; Approve</a>",
            'warning',
            'system'
        );
    }

    // Also try to email them
    $emailStmt = $db->prepare("
        SELECT first_name, last_name, email FROM users
        WHERE role = 'super_admin' AND status = 'active' AND email != '' AND id != ?
    ");
    $emailStmt->execute([$user['id']]);
    $saEmails = $emailStmt->fetchAll();

    $appName = APP_NAME;
    $year    = date('Y');
    foreach ($saEmails as $sa) {
        if (empty($sa['email'])) continue;
        $saName     = trim($sa['first_name'] . ' ' . $sa['last_name']);
        $approveUrl = EMAIL_BASE_URL . '/super_admin/users.php?unlock_request=' . $requestId;
        $html       = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#e8f4fb;font-family:'Segoe UI',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#e8f4fb;padding:40px 0;">
  <tr><td align="center">
    <table width="560" cellpadding="0" cellspacing="0"
           style="background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.10);">
      <tr>
        <td style="background:linear-gradient(135deg,#7c3aed,#1a4f7a);padding:32px 40px;text-align:center;">
          <div style="font-size:2.5rem;">🔒</div>
          <h1 style="color:#fff;font-size:1.3rem;margin:10px 0 4px;font-weight:700;">Unlock Request</h1>
          <p style="color:rgba(255,255,255,0.8);font-size:0.85rem;margin:0;">$appName — Admin Action Required</p>
        </td>
      </tr>
      <tr>
        <td style="padding:36px 40px;">
          <p style="color:#334155;font-size:0.95rem;margin:0 0 16px;">Hello <strong>$saName</strong>,</p>
          <p style="color:#334155;font-size:0.9rem;line-height:1.7;margin:0 0 20px;">
            System Administrator <strong>$lockedName</strong> (@{$user['username']}) has been locked out
            and is requesting your approval to unlock their account.
          </p>
          <table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:8px 0 28px;">
            <a href="$approveUrl"
               style="display:inline-block;background:linear-gradient(135deg,#7c3aed,#1a4f7a);color:#fff;
                      text-decoration:none;padding:14px 36px;border-radius:8px;font-size:0.95rem;
                      font-weight:600;letter-spacing:0.3px;">
              Review &amp; Unlock Account
            </a>
          </td></tr></table>
          <div style="background:#fff8e1;border:1px solid #ffe082;border-radius:8px;padding:12px 16px;">
            <p style="color:#7a5c00;font-size:0.8rem;margin:0;">
              ⚠️ Only approve this request if you can verify the identity of the person requesting access.
              If you did not expect this request, ignore this email.
            </p>
          </div>
        </td>
      </tr>
      <tr>
        <td style="padding:16px 40px;background:#f8fafc;border-top:1px solid #e2e8f0;text-align:center;">
          <p style="color:#94a3b8;font-size:0.75rem;margin:0;">© $year $appName &mdash; Official Government System</p>
        </td>
      </tr>
    </table>
  </td></tr>
</table>
</body>
</html>
HTML;
        try {
            sendMail($sa['email'], $saName, "Unlock Request from {$lockedName} — $appName", $html);
        } catch (\Throwable $e) {
            error_log('[Recovery] Failed to email SA: ' . $e->getMessage());
        }
    }

    echo json_encode(['ok' => true, 'msg' => 'Unlock request sent to all active System Administrators.']);
    exit;
}

// ─────────────────────────────────────────────────────────
// SUB-ACTION: check_approval  (poll from locked SA's browser)
// ─────────────────────────────────────────────────────────
if ($subAction === 'check_approval') {
    $identifier = sanitize($_POST['identifier'] ?? '');
    if (!$identifier) {
        echo json_encode(['ok' => true, 'unlocked' => false]);
        exit;
    }

    // Check whether the account is no longer locked
    $stmt = $db->prepare("
        SELECT login_locked FROM users
        WHERE (username = ? OR email = ?)
          AND role = 'super_admin'
          AND status = 'active'
        LIMIT 1
    ");
    $stmt->execute([$identifier, $identifier]);
    $row = $stmt->fetch();

    $unlocked = $row && (int) $row['login_locked'] === 0;
    echo json_encode(['ok' => true, 'unlocked' => $unlocked]);
    exit;
}

// ─────────────────────────────────────────────────────────
// SUB-ACTION: approve_unlock  (called by a logged-in SA)
// ─────────────────────────────────────────────────────────
if ($subAction === 'approve_unlock') {
    // Must be logged in as superadmin
    if (!isLoggedIn() || !isSuperAdmin()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'msg' => 'Unauthorized.']);
        exit;
    }

    $requestId = (int) ($_POST['request_id'] ?? 0);
    if (!$requestId) {
        echo json_encode(['ok' => false, 'msg' => 'Invalid request ID.']);
        exit;
    }

    $reqStmt = $db->prepare("
        SELECT r.*, u.first_name, u.last_name, u.username, u.email
        FROM superadmin_unlock_requests r
        JOIN users u ON r.user_id = u.id
        WHERE r.id = ? AND r.status = 'pending'
        LIMIT 1
    ");
    $reqStmt->execute([$requestId]);
    $req = $reqStmt->fetch();

    if (!$req) {
        echo json_encode(['ok' => false, 'msg' => 'Request not found or already processed.']);
        exit;
    }

    $approver = currentUser();

    // Clear the lock
    $db->prepare("UPDATE users SET login_attempts=0, locked_until=NULL, login_locked=0 WHERE id=?")
       ->execute([$req['user_id']]);
    $db->prepare("UPDATE superadmin_unlock_requests SET status='approved', approved_by=? WHERE id=?")
       ->execute([$approver['id'], $requestId]);
    // Expire any OTP codes they had
    $db->prepare("UPDATE superadmin_recovery_codes SET used=1 WHERE user_id=? AND used=0")
       ->execute([$req['user_id']]);

    $unlockedName  = trim($req['first_name'] . ' ' . $req['last_name']);
    $approverName  = trim($approver['first_name'] . ' ' . $approver['last_name']);
    logActivity($approver['id'], 'UNLOCK_APPROVED', 'Users',
        "Approved unlock request for {$unlockedName} (@{$req['username']})");

    // Notify the unlocked user
    sendNotification((int) $req['user_id'],
        "✅ Your account has been unlocked by <strong>{$approverName}</strong>. You may now log in.",
        'success', 'system'
    );

    // Email them if possible
    if (!empty($req['email'])) {
        $appName  = APP_NAME;
        $loginUrl = EMAIL_BASE_URL . '/home.php';
        $year     = date('Y');
        $html     = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#e8f4fb;font-family:'Segoe UI',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#e8f4fb;padding:40px 0;">
  <tr><td align="center">
    <table width="560" cellpadding="0" cellspacing="0"
           style="background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.10);">
      <tr>
        <td style="background:linear-gradient(135deg,#1a4f7a,#2d9f50);padding:32px 40px;text-align:center;">
          <div style="font-size:2.5rem;">✅</div>
          <h1 style="color:#fff;font-size:1.3rem;margin:10px 0 4px;font-weight:700;">Account Unlocked</h1>
          <p style="color:rgba(255,255,255,0.8);font-size:0.85rem;margin:0;">$appName</p>
        </td>
      </tr>
      <tr>
        <td style="padding:36px 40px;">
          <p style="color:#334155;font-size:0.95rem;margin:0 0 16px;">Hello <strong>$unlockedName</strong>,</p>
          <p style="color:#334155;font-size:0.9rem;line-height:1.7;margin:0 0 24px;">
            Your account has been unlocked by <strong>$approverName</strong>.
            You can now log in normally.
          </p>
          <table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:8px 0 28px;">
            <a href="$loginUrl"
               style="display:inline-block;background:linear-gradient(135deg,#1a4f7a,#2d9f50);color:#fff;
                      text-decoration:none;padding:14px 36px;border-radius:8px;font-size:0.95rem;font-weight:600;">
              Log In Now
            </a>
          </td></tr></table>
        </td>
      </tr>
      <tr>
        <td style="padding:16px 40px;background:#f8fafc;border-top:1px solid #e2e8f0;text-align:center;">
          <p style="color:#94a3b8;font-size:0.75rem;margin:0;">© $year $appName &mdash; Official Government System</p>
        </td>
      </tr>
    </table>
  </td></tr>
</table>
</body>
</html>
HTML;
        try {
            sendMail($req['email'], $unlockedName, "Your Account Has Been Unlocked — $appName", $html);
        } catch (\Throwable $e) { /* non-fatal */ }
    }

    echo json_encode(['ok' => true, 'msg' => "Account of {$unlockedName} has been unlocked successfully."]);
    exit;
}

// Unknown sub-action
echo json_encode(['ok' => false, 'msg' => 'Unknown action.']);
