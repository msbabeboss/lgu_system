<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/mailer.php';
requireRole('super_admin');

$db = Database::getInstance()->getConnection();
$u = currentUser();

// ── Helper: send welcome email on account creation ────────
function sendWelcomeEmail(string $toEmail, string $toName, string $username, string $setPasswordUrl, string $role): void {
    $appName   = APP_NAME;
    $roleLabel = $role === 'super_admin' ? 'System Administrator' : 'Barangay Administrator';
    $html = "
    <div style='font-family:Segoe UI,Arial,sans-serif;max-width:560px;margin:0 auto;'>
      <table width='100%' cellpadding='0' cellspacing='0' style='background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.10);'>
        <tr>
          <td style='background:linear-gradient(135deg,#1a4f7a,#2d9f50);padding:32px 40px;text-align:center;'>
            <div style='font-size:2.5rem;'>🏛️</div>
            <h1 style='color:#fff;font-size:1.3rem;margin:10px 0 4px;font-weight:700;'>Welcome to $appName</h1>
            <p style='color:rgba(255,255,255,0.8);font-size:0.85rem;margin:0;'>Your account has been created</p>
          </td>
        </tr>
        <tr>
          <td style='padding:32px 40px;'>
            <p style='color:#334155;font-size:0.95rem;margin:0 0 16px;'>Hello <strong>$toName</strong>,</p>
            <p style='color:#334155;font-size:0.9rem;line-height:1.6;margin:0 0 20px;'>
              Your account has been created by the System Administrator.
              You have been assigned the role of <strong>$roleLabel</strong>.
            </p>
            <div style='background:#e8f4fb;border:1px solid #5bb8d4;border-radius:10px;padding:18px 24px;margin-bottom:24px;'>
              <p style='margin:0 0 8px;font-size:0.82rem;color:#2a5070;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;'>Your Login Details</p>
              <p style='margin:0 0 6px;font-size:0.9rem;color:#1a2e1f;'><strong>Username:</strong> $username</p>
              <p style='margin:0;font-size:0.9rem;color:#1a2e1f;'><strong>Role:</strong> $roleLabel</p>
            </div>
            <p style='color:#334155;font-size:0.9rem;margin:0 0 16px;'>
              Click the button below to <strong>set your own password</strong> and activate your account.
              This link is valid for <strong>24 hours</strong>.
            </p>
            <table width='100%' cellpadding='0' cellspacing='0'><tr><td align='center' style='padding:8px 0 28px;'>
              <a href='$setPasswordUrl'
                 style='display:inline-block;background:linear-gradient(135deg,#1a4f7a,#2d9f50);color:#fff;text-decoration:none;padding:14px 36px;border-radius:8px;font-size:0.95rem;font-weight:600;letter-spacing:0.3px;'>
                Set My Password &amp; Login
              </a>
            </td></tr></table>
            <p style='color:#6a8090;font-size:0.78rem;margin:0 0 8px;'>Or copy and paste this link into your browser:</p>
            <p style='background:#e8f4fb;border-radius:6px;padding:10px 14px;font-size:0.78rem;color:#1a4f7a;word-break:break-all;margin:0 0 24px;'>$setPasswordUrl</p>
            <div style='background:#fff8e1;border:1px solid #ffe082;border-radius:8px;padding:12px 16px;'>
              <p style='color:#7a5c00;font-size:0.8rem;margin:0;'>
                ⚠️ This link expires in <strong>24 hours</strong>.
                Keep your credentials secure and do not share this link with anyone.
              </p>
            </div>
          </td>
        </tr>
        <tr>
          <td style='padding:16px 40px;background:#f8fafc;border-top:1px solid #e2e8f0;text-align:center;'>
            <p style='color:#94a3b8;font-size:0.75rem;margin:0;'>$appName &mdash; Official Government System</p>
          </td>
        </tr>
      </table>
    </div>";

    try {
        sendMail($toEmail, $toName, "Your Account Has Been Created — $appName", $html);
    } catch (Exception $e) {
        // Email failure is non-fatal
    }
}
function sendPasswordChangedEmail(string $toEmail, string $toName, string $username, string $newPass): void {
    $loginUrl = EMAIL_BASE_URL . '/home.php';
    $appName  = APP_NAME;
    $html = "
    <div style='font-family:Segoe UI,Arial,sans-serif;max-width:560px;margin:0 auto;'>
      <table width='100%' cellpadding='0' cellspacing='0' style='background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.10);'>
        <tr>
          <td style='background:linear-gradient(135deg,#1a4f7a,#2d9f50);padding:32px 40px;text-align:center;'>
            <div style='font-size:2rem;'>🔐</div>
            <h1 style='color:#fff;font-size:1.3rem;margin:10px 0 4px;font-weight:700;'>Password Changed</h1>
            <p style='color:rgba(255,255,255,0.8);font-size:0.85rem;margin:0;'>$appName</p>
          </td>
        </tr>
        <tr>
          <td style='padding:32px 40px;'>
            <p style='color:#334155;font-size:0.95rem;margin:0 0 16px;'>Hello <strong>$toName</strong>,</p>
            <p style='color:#334155;font-size:0.9rem;line-height:1.6;margin:0 0 20px;'>
              Your account password has been <strong>reset by the System Administrator</strong>.
              You will be required to change it on your next login.
            </p>
            <div style='background:#e8f4fb;border:1px solid #5bb8d4;border-radius:10px;padding:18px 24px;margin-bottom:24px;'>
              <p style='margin:0 0 8px;font-size:0.82rem;color:#2a5070;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;'>Your New Credentials</p>
              <p style='margin:0 0 6px;font-size:0.9rem;color:#1a2e1f;'><strong>Username:</strong> $username</p>
              <p style='margin:0;font-size:0.9rem;color:#1a2e1f;'><strong>Temporary Password:</strong> <code style='background:#d8edf7;padding:2px 8px;border-radius:4px;font-size:0.9rem;'>$newPass</code></p>
            </div>
            <table width='100%' cellpadding='0' cellspacing='0'><tr><td align='center' style='padding:8px 0 28px;'>
              <a href='$loginUrl'
                 style='display:inline-block;background:linear-gradient(135deg,#1a4f7a,#2d9f50);color:#fff;text-decoration:none;padding:14px 36px;border-radius:8px;font-size:0.95rem;font-weight:600;letter-spacing:0.3px;'>
                Login Now
              </a>
            </td></tr></table>
            <div style='background:#fff8e1;border:1px solid #ffe082;border-radius:8px;padding:12px 16px;'>
              <p style='color:#7a5c00;font-size:0.8rem;margin:0;'>
                ⚠️ For security, please change your password immediately after logging in.
                If you did not expect this change, contact your System Administrator.
              </p>
            </div>
          </td>
        </tr>
        <tr>
          <td style='padding:16px 40px;background:#f8fafc;border-top:1px solid #e2e8f0;text-align:center;'>
            <p style='color:#94a3b8;font-size:0.75rem;margin:0;'>$appName &mdash; Official Government System</p>
          </td>
        </tr>
      </table>
    </div>";

    try {
        sendMail($toEmail, $toName, "Your Password Has Been Reset — $appName", $html);
    } catch (Exception $e) {
        // Email failure is non-fatal — password is already changed
    }
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrAbort();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $fname    = sanitize($_POST['first_name']);
        $lname    = sanitize($_POST['last_name']);
        $mname    = sanitize($_POST['middle_name'] ?? '');
        $email    = sanitize($_POST['email']);
        $brgyId   = (int)$_POST['barangay_id'];
        $position = sanitize($_POST['position'] ?? '');
        $contact  = sanitize($_POST['contact_number'] ?? '');
        $role     = $_POST['role'] === 'super_admin' ? 'super_admin' : 'barangay_admin';
        $username = sanitize($_POST['username']);
        $passMode = $_POST['password_mode'] ?? 'random';
        $rawPass  = $passMode === 'manual' ? $_POST['manual_password'] : generatePassword();
        $hashed   = password_hash($rawPass, PASSWORD_BCRYPT, ['cost'=>12]);

        // Validate contact: must match 09XXXXXXXXX (11 digits, starts with 09)
        if ($contact && !preg_match('/^09\d{9}$/', $contact)) {
            flash('users', 'Contact number must be 11 digits and start with 09 (e.g. 09171234567).', 'danger');
            redirect(BASE_URL . '/super_admin/users.php');
        }

        // Validate password: alphanumeric, min 8 chars
        if ($passMode === 'manual') {
            if (strlen($rawPass) < 8 || !preg_match('/[a-zA-Z]/', $rawPass) || !preg_match('/[0-9]/', $rawPass)) {
                flash('users', 'Password must be at least 8 characters and contain both letters and numbers.', 'danger');
                redirect(BASE_URL . '/super_admin/users.php');
            }
        }

        // A full name must be unique; matching only one or two name parts is allowed.
        $dupCheck = $db->prepare("
            SELECT COUNT(*) FROM users
            WHERE LOWER(TRIM(COALESCE(first_name, ''))) = LOWER(TRIM(?))
              AND LOWER(TRIM(COALESCE(middle_name, ''))) = LOWER(TRIM(?))
              AND LOWER(TRIM(COALESCE(last_name, ''))) = LOWER(TRIM(?))
        ");
        $dupCheck->execute([$fname, $mname, $lname]);
        if ((int)$dupCheck->fetchColumn() > 0) {
            flash('users', 'A user with the same first, middle, and last name already exists. Please check the full name.', 'danger');
            redirect(BASE_URL . '/super_admin/users.php');
        }

        // Hard check: username must be unique
        $uniqCheck = $db->prepare("SELECT COUNT(*) FROM users WHERE username=?");
        $uniqCheck->execute([$username]);
        if ((int)$uniqCheck->fetchColumn() > 0) {
            flash('users', "Username <strong>$username</strong> is already taken. Please choose a different username.", 'danger');
            redirect(BASE_URL . '/super_admin/users.php');
        }

        // Hard check: email must be unique
        if ($email) {
            $emailCheck = $db->prepare("SELECT COUNT(*) FROM users WHERE email=?");
            $emailCheck->execute([$email]);
            if ((int)$emailCheck->fetchColumn() > 0) {
                flash('users', "Email <strong>" . htmlspecialchars($email) . "</strong> is already registered. Please use a different email.", 'danger');
                redirect(BASE_URL . '/super_admin/users.php');
            }
        }

        $stmt = $db->prepare("INSERT INTO users (first_name,middle_name,last_name,username,email,password,role,barangay_id,position,contact_number,must_change_password) VALUES (?,?,?,?,?,?,?,?,?,?,1)");
        $stmt->execute([$fname,$mname,$lname,$username,$email,$hashed,$role,$brgyId ?: null,$position,$contact]);
        $newId = $db->lastInsertId();

        // Update barangay address fields regardless of role
        if ($brgyId) {
            $region       = sanitize($_POST['brgy_region']       ?? '');
            $province     = sanitize($_POST['brgy_province']     ?? '');
            $municipality = sanitize($_POST['brgy_municipality'] ?? '');
            $zipCode      = sanitize($_POST['brgy_zip_code']     ?? '');
            $db->prepare("UPDATE barangays SET region=?, province=?, municipality=COALESCE(NULLIF(?,''),(SELECT municipality FROM (SELECT municipality FROM barangays WHERE id=?) AS t)), zip_code=? WHERE id=?")
               ->execute([$region ?: null, $province ?: null, $municipality, $brgyId, $zipCode ?: null, $brgyId]);
        }

        logActivity($u['id'], 'ADD_USER', 'Users', "Added user: $fname $lname ($username)");
        sendNotification($newId, "Your account has been created. Username: $username", 'info');

        // Generate a password-reset token so the user sets their own password via email link
        $token     = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + 86400); // 24 hours
        // Invalidate any existing unused tokens for this user first
        $db->prepare("UPDATE password_resets SET used=1 WHERE user_id=? AND used=0")->execute([$newId]);
        $db->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?,?,?)")
           ->execute([$newId, $token, $expiresAt]);
        $resetUrl = BASE_URL . '/home.php?reset_token=' . $token;

        // Send welcome email with the set-password link
        if ($email) {
            sendWelcomeEmail($email, "$fname $lname", $username, EMAIL_BASE_URL . '/home.php?reset_token=' . $token, $role);
            flash('users', "User <strong>$username</strong> created! A set-password link has been sent to <strong>$email</strong>.", 'success');
        } else {
            flash('users', "User created! Username: <strong>$username</strong> | Temp Password: <strong>$rawPass</strong> (No email — share manually.)", 'success');
        }
        redirect(BASE_URL . '/super_admin/users.php');
    }

    if ($action === 'edit') {
        $id       = (int)$_POST['user_id'];
        $fname    = sanitize($_POST['first_name']);
        $lname    = sanitize($_POST['last_name']);
        $mname    = sanitize($_POST['middle_name'] ?? '');
        $email    = sanitize($_POST['email']);
        $brgyId   = (int)$_POST['barangay_id'];
        $position = sanitize($_POST['position'] ?? '');
        $contact  = sanitize($_POST['contact_number'] ?? '');
        $status   = $_POST['status'] === 'active' ? 'active' : 'inactive';
        $username = sanitize($_POST['username']);
        $passMode = $_POST['edit_password_mode'] ?? 'keep';

        // Validate contact: must match 09XXXXXXXXX (11 digits, starts with 09)
        if ($contact && !preg_match('/^09\d{9}$/', $contact)) {
            flash('users', 'Contact number must be 11 digits and start with 09 (e.g. 09171234567).', 'danger');
            redirect(BASE_URL . '/super_admin/users.php');
        }

        // Check username uniqueness (exclude current user)
        $uniqCheck = $db->prepare("SELECT COUNT(*) FROM users WHERE username=? AND id!=?");
        $uniqCheck->execute([$username, $id]);
        if ((int)$uniqCheck->fetchColumn() > 0) {
            flash('users', "Username <strong>$username</strong> is already taken. Please choose another.", 'danger');
            redirect(BASE_URL . '/super_admin/users.php');
        }

        // Check email uniqueness (exclude current user)
        if ($email) {
            $emailUniq = $db->prepare("SELECT COUNT(*) FROM users WHERE email=? AND id!=?");
            $emailUniq->execute([$email, $id]);
            if ((int)$emailUniq->fetchColumn() > 0) {
                flash('users', "Email <strong>" . htmlspecialchars($email) . "</strong> is already used by another account.", 'danger');
                redirect(BASE_URL . '/super_admin/users.php');
            }
        }

        if ($passMode === 'random') {
            $rawPass = generatePassword();
            $hashed  = password_hash($rawPass, PASSWORD_BCRYPT, ['cost'=>12]);
            $db->prepare("UPDATE users SET first_name=?,middle_name=?,last_name=?,username=?,email=?,password=?,barangay_id=?,position=?,contact_number=?,status=?,must_change_password=1 WHERE id=?")
               ->execute([$fname,$mname,$lname,$username,$email,$hashed,$brgyId ?: null,$position,$contact,$status,$id]);
            logActivity($u['id'], 'EDIT_USER', 'Users', "Updated account for $fname $lname — password reset");
            sendPasswordChangedEmail($email, "$fname $lname", $username, $rawPass);
            flash('users', "User updated! New password: <strong>$rawPass</strong> — Email sent to <strong>$email</strong>.", 'success');
        } elseif ($passMode === 'manual') {
            $rawPass = $_POST['edit_manual_password'];
            if (strlen($rawPass) < 8 || !preg_match('/[a-zA-Z]/', $rawPass) || !preg_match('/[0-9]/', $rawPass)) {
                flash('users', 'Password must be at least 8 characters and contain both letters and numbers.', 'danger');
                redirect(BASE_URL . '/super_admin/users.php');
            }
            $hashed  = password_hash($rawPass, PASSWORD_BCRYPT, ['cost'=>12]);
            $db->prepare("UPDATE users SET first_name=?,middle_name=?,last_name=?,username=?,email=?,password=?,barangay_id=?,position=?,contact_number=?,status=?,must_change_password=1 WHERE id=?")
               ->execute([$fname,$mname,$lname,$username,$email,$hashed,$brgyId ?: null,$position,$contact,$status,$id]);
            logActivity($u['id'], 'EDIT_USER', 'Users', "Updated account for $fname $lname — password changed");
            sendPasswordChangedEmail($email, "$fname $lname", $username, $rawPass);
            flash('users', "User updated! New password sent to <strong>$email</strong>.", 'success');
        } else {
            // keep existing password
            $db->prepare("UPDATE users SET first_name=?,middle_name=?,last_name=?,username=?,email=?,barangay_id=?,position=?,contact_number=?,status=? WHERE id=?")
               ->execute([$fname,$mname,$lname,$username,$email,$brgyId ?: null,$position,$contact,$status,$id]);
            logActivity($u['id'], 'EDIT_USER', 'Users', "Updated account details for $fname $lname");
            // Email the affected user if their status changed to inactive
            if ($status === 'inactive' && $email) {
                sendTransactionLogEmail(
                    $email, "$fname $lname",
                    'ACCOUNT_UPDATED', 'Users',
                    "Your account has been updated by the System Administrator. Username: $username — Status: Inactive",
                    EMAIL_BASE_URL . '/home.php'
                );
            } elseif ($email) {
                sendTransactionLogEmail(
                    $email, "$fname $lname",
                    'ACCOUNT_UPDATED', 'Users',
                    "Your account details have been updated by the System Administrator. Username: $username",
                    EMAIL_BASE_URL . '/home.php'
                );
            }
            flash('users', 'User updated successfully.', 'success');
        }

        // Sync barangay address fields when editing too
        if ($brgyId) {
            $region       = sanitize($_POST['brgy_region']       ?? '');
            $province     = sanitize($_POST['brgy_province']     ?? '');
            $municipality = sanitize($_POST['brgy_municipality'] ?? '');
            $zipCode      = sanitize($_POST['brgy_zip_code']     ?? '');
            $db->prepare("UPDATE barangays SET region=?, province=?, municipality=COALESCE(NULLIF(?,''),(SELECT municipality FROM (SELECT municipality FROM barangays WHERE id=?) AS t)), zip_code=? WHERE id=?")
               ->execute([$region ?: null, $province ?: null, $municipality, $brgyId, $zipCode ?: null, $brgyId]);
        }

        redirect(BASE_URL . '/super_admin/users.php');
    }

    if ($action === 'reset_password') {
        $id       = (int)$_POST['user_id'];
        $passMode = $_POST['reset_pass_mode'] ?? 'random';
        if ($passMode === 'manual') {
            $rawPass = $_POST['reset_manual_password'];
            if (strlen($rawPass) < 8 || !preg_match('/[a-zA-Z]/', $rawPass) || !preg_match('/[0-9]/', $rawPass)) {
                flash('users', 'Password must be at least 8 characters and contain both letters and numbers.', 'danger');
                redirect(BASE_URL . '/super_admin/users.php');
            }
        } else {
            $rawPass = generatePassword();
        }
        $hashed = password_hash($rawPass, PASSWORD_BCRYPT, ['cost'=>12]);
        $db->prepare("UPDATE users SET password=?, must_change_password=1 WHERE id=?")->execute([$hashed, $id]);

        // Fetch the target user's details
        $targetUser = $db->prepare("SELECT first_name, last_name, email, username FROM users WHERE id=?");
        $targetUser->execute([$id]);
        $targetUser = $targetUser->fetch();
        $targetName = trim(($targetUser['first_name'] ?? '') . ' ' . ($targetUser['last_name'] ?? '')) ?: "User #$id";
        logActivity($u['id'], 'RESET_PASSWORD', 'Users', "Reset password for $targetName");

        if ($targetUser && $targetUser['email']) {
            // Send email to the user with their new password
            sendPasswordChangedEmail(
                $targetUser['email'],
                $targetUser['first_name'] . ' ' . $targetUser['last_name'],
                $targetUser['username'],
                $rawPass
            );
            // Notify the user in-system
            sendNotification($id,
                "Your password has been reset by the System Administrator. New temporary password: $rawPass — Please change it on your next login.",
                'warning'
            );
            // Notify the super admin that the email was sent
            sendNotification($u['id'],
                "✅ Password reset for <strong>{$targetUser['first_name']} {$targetUser['last_name']}</strong> ({$targetUser['username']}) — Email notification sent to <strong>{$targetUser['email']}</strong>.",
                'success'
            );
            flash('users',
                "Password reset for <strong>{$targetUser['first_name']} {$targetUser['last_name']}</strong>! " .
                "New password: <strong>$rawPass</strong> — " .
                "Email notification sent to <strong>{$targetUser['email']}</strong>.",
                'warning'
            );
        } else {
            flash('users', "Password reset! New password: <strong>$rawPass</strong> (No email on file — share manually.)", 'warning');
        }

        redirect(BASE_URL . '/super_admin/users.php');
    }

    if ($action === 'archive') {
        $id = (int)$_POST['user_id'];
        $row = $db->prepare("SELECT * FROM users WHERE id=?");
        $row->execute([$id]);
        $data = $row->fetch();
        $archivedName = trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')) ?: "User #$id";
        $db->prepare("INSERT INTO archives (table_name,record_id,data_json,archived_by) VALUES ('users',?,?,?)")->execute([$id, json_encode($data), $u['id']]);
        $db->prepare("UPDATE users SET status='inactive' WHERE id=?")->execute([$id]);
        logActivity($u['id'], 'ARCHIVE_USER', 'Users', "Archived user account of $archivedName");
        // Email the archived user
        if (!empty($data['email'])) {
            sendTransactionLogEmail(
                $data['email'], $archivedName,
                'ACCOUNT_ARCHIVED', 'Users',
                "Your account ({$data['username']}) has been archived by the System Administrator. Contact the LGU if you need to restore access.",
                EMAIL_BASE_URL . '/home.php'
            );
        }
        flash('users', 'User archived.', 'info');
        redirect(BASE_URL . '/super_admin/users.php');
    }

    if ($action === 'block_access') {
        $id         = (int)$_POST['user_id'];
        $newStatus  = $_POST['new_status'] === 'active' ? 'active' : 'inactive';
        $targetU = $db->prepare("SELECT first_name, last_name, email, username FROM users WHERE id=?");
        $targetU->execute([$id]); $tu = $targetU->fetch();
        $tuName = trim(($tu['first_name'] ?? '') . ' ' . ($tu['last_name'] ?? '')) ?: "User #$id";
        $db->prepare("UPDATE users SET status=? WHERE id=?")->execute([$newStatus, $id]);
        $label = $newStatus === 'inactive' ? 'Blocked' : 'Unblocked';
        logActivity($u['id'], 'BLOCK_USER', 'Users', "$label access for $tuName");
        // Email the affected user
        if (!empty($tu['email'])) {
            $blockMsg = $newStatus === 'inactive'
                ? "Your system access has been BLOCKED by the Administrator. Username: {$tu['username']}. Contact the LGU if you believe this is an error."
                : "Your system access has been RESTORED by the Administrator. Username: {$tu['username']}. You may now log in.";
            sendTransactionLogEmail(
                $tu['email'], $tuName,
                $newStatus === 'inactive' ? 'ACCESS_BLOCKED' : 'ACCESS_RESTORED',
                'Users',
                $blockMsg,
                EMAIL_BASE_URL . '/home.php'
            );
        }
        flash('users', "User access $label successfully.", $newStatus === 'inactive' ? 'warning' : 'success');
        redirect(BASE_URL . '/super_admin/users.php');
    }

    if ($action === 'unlock_login') {
        $id = (int)$_POST['user_id'];
        $targetU = $db->prepare("SELECT first_name, last_name, email, username FROM users WHERE id=?");
        $targetU->execute([$id]); $tu = $targetU->fetch();
        $tuName = trim(($tu['first_name'] ?? '') . ' ' . ($tu['last_name'] ?? '')) ?: "User #$id";
        $db->prepare("UPDATE users SET login_attempts=0, locked_until=NULL, login_locked=0 WHERE id=?")->execute([$id]);
        logActivity($u['id'], 'UNLOCK_LOGIN', 'Users', "Unlocked login access for $tuName (cleared failed-attempts lock)");
        // Notify the unlocked user
        sendNotification($id,
            '✅ Your login access has been restored by the System Administrator. You may now log in.',
            'success', 'system'
        );
        // Notify acting superadmin
        sendNotification($u['id'],
            "🔓 Login access restored for <strong>$tuName</strong> (@{$tu['username']}).",
            'success', 'system'
        );
        flash('users', "Login access restored for <strong>$tuName</strong>.", 'success');
        redirect(BASE_URL . '/super_admin/users.php');
    }

}

// Safe migrations for barangay address fields
try { $db->exec("ALTER TABLE barangays ADD COLUMN region VARCHAR(100) NULL"); } catch(PDOException $e){}
try { $db->exec("ALTER TABLE barangays ADD COLUMN province VARCHAR(100) NULL"); } catch(PDOException $e){}
try { $db->exec("ALTER TABLE barangays ADD COLUMN zip_code VARCHAR(10) NULL"); } catch(PDOException $e){}
// Safe migration: login_locked column
try { $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS login_locked TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = locked by failed attempts; requires superadmin unlock'"); } catch(\Throwable $e) {}

// Ensure unlock_requests table exists
try { $db->exec("CREATE TABLE IF NOT EXISTS superadmin_unlock_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    status ENUM('pending','approved','expired') DEFAULT 'pending',
    approved_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB"); } catch(\Throwable $e) {}

// Check for an incoming unlock_request from email link
$unlockRequestData = null;
$unlockRequestId   = (int)($_GET['unlock_request'] ?? 0);
if ($unlockRequestId) {
    $rq = $db->prepare("
        SELECT r.*, u.first_name, u.last_name, u.username, u.email, u.login_locked
        FROM superadmin_unlock_requests r
        JOIN users u ON r.user_id = u.id
        WHERE r.id = ? AND r.status = 'pending'
        LIMIT 1
    ");
    $rq->execute([$unlockRequestId]);
    $unlockRequestData = $rq->fetch() ?: null;
}

// Fetch users — include ALL users (current user shown as "You" in the table)
$searchInput = $_GET['search'] ?? '';
$search = is_string($searchInput) ? trim(strip_tags($searchInput)) : '';
$statusInput = $_GET['status'] ?? '';
$statusFilter = is_string($statusInput) && in_array($statusInput, ['active', 'blocked', 'locked'], true)
    ? $statusInput
    : '';
$query = "SELECT u.*, b.barangay_name, b.region AS brgy_region, b.province AS brgy_province, b.municipality AS brgy_municipality, b.zip_code AS brgy_zip_code FROM users u LEFT JOIN barangays b ON u.barangay_id=b.id WHERE 1=1";
$queryParams = [];
if ($search !== '') {
    $query .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.username LIKE ? OR u.email LIKE ?)";
    $searchTerm = '%' . $search . '%';
    array_push($queryParams, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
}
if ($statusFilter === 'active') {
    $query .= " AND u.status = 'active' AND COALESCE(u.login_locked, 0) = 0";
} elseif ($statusFilter === 'blocked') {
    $query .= " AND u.status <> 'active' AND COALESCE(u.login_locked, 0) = 0";
} elseif ($statusFilter === 'locked') {
    $query .= " AND COALESCE(u.login_locked, 0) = 1";
}
$query .= " ORDER BY u.role='super_admin' DESC, u.created_at DESC";
$userQuery = $db->prepare($query);
$userQuery->execute($queryParams);
$users = $userQuery->fetchAll();
$existingUsernames = array_map('strtolower', $db->query("SELECT username FROM users")->fetchAll(PDO::FETCH_COLUMN));
$existingFullNames = $db->query("
    SELECT first_name, middle_name, last_name
    FROM users
")->fetchAll(PDO::FETCH_ASSOC);

$barangays = $db->query("SELECT * FROM barangays WHERE status='active' ORDER BY barangay_name")->fetchAll();
$flash = getFlash('users');
$pageTitle = 'User Management';
$superAdmins    = array_values(array_filter($users, fn($x) => $x['role'] === 'super_admin'));
$barangayAdmins = array_values(array_filter($users, fn($x) => $x['role'] === 'barangay_admin'));

// Pagination — 10 per section
$perPage = 10;

$saTotal  = count($superAdmins);
$saPage   = max(1, (int)($_GET['sa_page'] ?? 1));
$saPages  = max(1, (int)ceil($saTotal / $perPage));
$saPage   = min($saPage, $saPages);
$saOffset = ($saPage - 1) * $perPage;
$superAdminsPaged = array_slice($superAdmins, $saOffset, $perPage);

$baTotal  = count($barangayAdmins);
$baPage   = max(1, (int)($_GET['ba_page'] ?? 1));
$baPages  = max(1, (int)ceil($baTotal / $perPage));
$baPage   = min($baPage, $baPages);
$baOffset = ($baPage - 1) * $perPage;
$barangayAdminsPaged = array_slice($barangayAdmins, $baOffset, $perPage);
?>

<?php
$pageIcon     = 'bi-people-fill';
$pageTitle    = 'Users';
$pageSubtitle = 'Manage system administrators and barangay admin accounts.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=3.2">
    <style>
    .user-drawer {
        position: fixed; top: 0; right: -400px; width: 380px; height: 100vh;
        background: #fff; z-index: 1050; transition: right 0.3s cubic-bezier(.4,0,.2,1);
        box-shadow: -8px 0 32px rgba(0,0,0,0.18); display: flex; flex-direction: column;
        overflow: hidden;
    }
    .user-drawer.open { right: 0; }
    .drawer-backdrop {
        position: fixed; inset: 0; background: rgba(0,0,0,0.4); z-index: 1049;
        display: none; backdrop-filter: blur(2px);
    }
    .drawer-backdrop.show { display: block; }
    .brgy-option:hover { background: #e8f0fe; color: #1a4f7a; }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/sidebar_super.php'; ?>
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<div class="main-content">

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show">
            <?= $flash['message'] ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($unlockRequestData): ?>
    <!-- ── Unlock Approval Banner ── -->
    <div class="alert alert-warning alert-dismissible fade show d-flex align-items-center gap-3 mb-3"
         style="border-radius:12px;border:1.5px solid #f59e0b;background:#fffbeb;" id="unlockApprovalBanner">
        <i class="bi bi-lock-fill fs-4 text-warning flex-shrink-0"></i>
        <div class="flex-grow-1">
            <strong>Unlock Request</strong> —
            <strong><?= htmlspecialchars(trim($unlockRequestData['first_name'].' '.$unlockRequestData['last_name'])) ?></strong>
            (@<?= htmlspecialchars($unlockRequestData['username']) ?>) is locked out and is requesting you to unlock their account.
        </div>
        <?php if (!empty($unlockRequestData['login_locked'])): ?>
        <button class="btn btn-warning btn-sm fw-semibold px-3"
                onclick="approveUnlockRequest(<?= $unlockRequestId ?>, this)"
                style="white-space:nowrap;">
            <i class="bi bi-unlock-fill me-1"></i> Approve Unlock
        </button>
        <?php else: ?>
        <span class="badge bg-success">Already Unlocked</span>
        <?php endif; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Summary Cards -->
    <div class="d-flex align-items-center justify-content-between gap-2 mb-3 flex-wrap">
        <div class="d-flex align-items-center gap-2 flex-wrap">
        <div style="background:#fff;border-radius:12px;padding:9px 12px;box-shadow:0 4px 6px -1px rgba(0,0,0,0.07),0 2px 4px -1px rgba(0,0,0,0.05),0 0 0 1px rgba(0,0,0,0.04);display:flex;align-items:center;gap:9px;min-width:150px;max-width:190px;">
            <div style="width:30px;height:30px;background:linear-gradient(135deg,#1a4f7a,#3a7fa8);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;box-shadow:0 2px 4px rgba(26,79,122,0.3);">
                <i class="bi bi-people-fill text-white" style="font-size:0.85rem;"></i>
            </div>
            <div>
                <div style="font-size:1.1rem;font-weight:800;color:#1a2e1f;line-height:1;"><?= count($users) ?></div>
                <div style="font-size:0.66rem;color:#3a7fa8;margin-top:2px;">Total Users</div>
            </div>
        </div>
        <div style="background:#fff;border-radius:12px;padding:9px 12px;box-shadow:0 4px 6px -1px rgba(0,0,0,0.07),0 2px 4px -1px rgba(0,0,0,0.05),0 0 0 1px rgba(0,0,0,0.04);display:flex;align-items:center;gap:9px;min-width:150px;max-width:190px;">
            <div style="width:30px;height:30px;background:linear-gradient(135deg,#06111e,#1a4f7a);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;box-shadow:0 2px 4px rgba(15,37,64,0.3);">
                <i class="bi bi-shield-fill-check text-white" style="font-size:0.85rem;"></i>
            </div>
            <div>
                <div style="font-size:1.1rem;font-weight:800;color:#1a2e1f;line-height:1;"><?= count($superAdmins) ?></div>
                <div style="font-size:0.66rem;color:#3a7fa8;margin-top:2px;">System Admins</div>
            </div>
        </div>
        <div style="background:#fff;border-radius:12px;padding:9px 12px;box-shadow:0 4px 6px -1px rgba(0,0,0,0.07),0 2px 4px -1px rgba(0,0,0,0.05),0 0 0 1px rgba(0,0,0,0.04);display:flex;align-items:center;gap:9px;min-width:150px;max-width:190px;">
            <div style="width:30px;height:30px;background:linear-gradient(135deg,#3a7fa8,#60a5fa);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;box-shadow:0 2px 4px rgba(58,127,168,0.3);">
                <i class="bi bi-person-badge-fill text-white" style="font-size:0.85rem;"></i>
            </div>
            <div>
                <div style="font-size:1.1rem;font-weight:800;color:#1a2e1f;line-height:1;"><?= count($barangayAdmins) ?></div>
                <div style="font-size:0.66rem;color:#3a7fa8;margin-top:2px;">Barangay Admins</div>
            </div>
        </div>
        </div>
        <div class="d-flex align-items-center justify-content-end gap-2 flex-wrap ms-auto">
            <form method="get" class="d-flex flex-wrap" style="gap:6px;">
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                       class="form-control form-control-sm" placeholder="Search users…"
                       style="width:140px;min-width:0;border-radius:8px;font-size:0.82rem;">
                <select name="status" class="form-select form-select-sm"
                        aria-label="Filter users by account status"
                        style="width:125px;min-width:0;border-radius:8px;font-size:0.82rem;">
                    <option value="">All statuses</option>
                    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="blocked" <?= $statusFilter === 'blocked' ? 'selected' : '' ?>>Blocked</option>
                    <option value="locked" <?= $statusFilter === 'locked' ? 'selected' : '' ?>>Login Locked</option>
                </select>
                <button class="btn btn-sm btn-outline-secondary" type="submit" style="border-radius:8px;">
                    <i class="bi bi-funnel"></i>
                </button>
                <?php if ($search !== '' || $statusFilter !== ''): ?>
                    <a href="users.php" class="btn btn-sm btn-outline-danger" style="border-radius:8px;" title="Clear filters">
                        <i class="bi bi-x"></i>
                    </a>
                <?php endif; ?>
            </form>
            <button class="btn btn-sm btn-success fw-semibold" data-bs-toggle="modal" data-bs-target="#addUserModal">
                <i class="bi bi-person-plus-fill me-1"></i>Add User
            </button>
        </div>
    </div>

    <!-- System Administrators Table -->
    <div style="background:#fff;border-radius:18px;box-shadow:0 2px 16px rgba(0,0,0,0.07);border:1px solid #e0f0e4;margin-bottom:20px;overflow:hidden;">
        <!-- Card Header -->
        <div style="padding:18px 22px 16px;display:flex;align-items:center;gap:14px;border-bottom:1px solid #e8f4fb;">
            <div style="width:36px;height:36px;background:linear-gradient(135deg,#06111e,#1a4f7a);border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="bi bi-shield-fill-check text-white" style="font-size:0.82rem;"></i>
            </div>
            <div style="flex:1;">
                <div style="font-weight:700;font-size:0.95rem;color:#1a2e1f;">System Administrators</div>
                <div style="font-size:0.75rem;color:#3a7fa8;">Super admin accounts with full system access</div>
            </div>
            <span style="background:#e8f4fb;color:#1a4f7a;font-size:0.75rem;font-weight:600;padding:5px 14px;border-radius:20px;">
                <?= count($superAdmins) ?> account<?= count($superAdmins) !== 1 ? 's' : '' ?>
            </span>
        </div>
        <!-- Table -->
        <?php if (empty($superAdmins)): ?>
        <div class="text-center text-muted py-5 small">No system administrators found.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table mb-0" style="font-size:0.85rem;">
                <thead>
                    <tr style="border-bottom:1px solid #e8f4fb;">
                        <th class="ps-4 py-3" style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#3a7fa8;background:transparent;border:none;">Name</th>
                        <th class="py-3" style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#3a7fa8;background:transparent;border:none;">Username</th>
                        <th class="py-3" style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#3a7fa8;background:transparent;border:none;">Position</th>
                        <th class="py-3" style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#3a7fa8;background:transparent;border:none;">Status</th>
                        <th class="py-3" style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#3a7fa8;background:transparent;border:none;">2FA</th>
                        <th class="pe-4 py-3" style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#3a7fa8;background:transparent;border:none;">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($superAdminsPaged as $usr):
                    $isMe = ($usr['id'] == $u['id']);
                ?>
                    <tr style="border-bottom:1px solid #f8fafc;transition:background 0.15s;<?= $isMe ? 'background:#f0f7ff;' : '' ?>"
                        onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='<?= $isMe ? '#f0f7ff' : '' ?>'">
                        <td class="ps-4 py-3" style="border:none;">
                            <div class="d-flex align-items-center gap-3">
                                <div style="width:36px;height:36px;background:linear-gradient(135deg,#06111e,#1a4f7a);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:0.72rem;font-weight:700;flex-shrink:0;">
                                    <?= strtoupper(substr($usr['first_name'],0,1).substr($usr['last_name'],0,1)) ?>
                                </div>
                                <div>
                                    <div style="font-weight:600;color:#1a2e1f;display:flex;align-items:center;gap:6px;">
                                        <?= htmlspecialchars($usr['first_name'].' '.$usr['last_name']) ?>
                                        <?php if ($isMe): ?>
                                        <span style="background:linear-gradient(90deg,#1a4f7a,#3a7fa8);color:#fff;font-size:0.62rem;font-weight:700;padding:2px 8px;border-radius:20px;letter-spacing:0.03em;">You</span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="font-size:0.72rem;color:#3a7fa8;"><?= htmlspecialchars($usr['email']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="py-3" style="border:none;color:#2a5070;"><?= htmlspecialchars($usr['username']) ?></td>
                        <td class="py-3" style="border:none;color:#2a5070;font-size:0.82rem;"><?= htmlspecialchars($usr['position'] ?? '—') ?></td>
                        <td class="py-3" style="border:none;">
                            <?php if ($usr['status']==='active' && empty($usr['login_locked'])): ?>
                            <span style="background:#e0f2fb;color:#0d3a5c;font-size:0.72rem;font-weight:600;padding:4px 12px;border-radius:20px;">Active</span>
                            <?php elseif (!empty($usr['login_locked'])): ?>
                            <span style="background:#fff7ed;color:#c2410c;font-size:0.72rem;font-weight:600;padding:4px 12px;border-radius:20px;"><i class="bi bi-lock-fill me-1"></i>Login Locked</span>
                            <?php else: ?>
                            <span style="background:#fff0f0;color:#c62828;font-size:0.72rem;font-weight:600;padding:4px 12px;border-radius:20px;"><i class="bi bi-slash-circle me-1"></i>Blocked</span>
                            <?php endif; ?>
                        </td>
                        <td class="py-3" style="border:none;">
                            <?php if (!empty($usr['two_fa_enabled'])): ?>
                            <span style="background:#e0f2fb;color:#0d3a5c;font-size:0.72rem;font-weight:600;padding:4px 10px;border-radius:20px;"><i class="bi bi-shield-check me-1"></i>On</span>
                            <?php else: ?>
                            <span style="background:#f1f5f9;color:#64748b;font-size:0.72rem;font-weight:500;padding:4px 10px;border-radius:20px;"><i class="bi bi-shield me-1"></i>Off</span>
                            <?php endif; ?>
                        </td>
                        <td class="pe-4 py-3" style="border:none;">
                            <?php if ($isMe): ?>
                            <button class="btn btn-sm" style="font-size:0.78rem;border-radius:8px;padding:4px 14px;background:rgba(26,79,122,0.08);color:#1a4f7a;border:1px solid rgba(26,79,122,0.15);"
                                    onclick="openUserDrawer(<?= htmlspecialchars(json_encode($usr)) ?>)">
                                <i class="bi bi-person-circle me-1"></i>My Profile
                            </button>
                            <?php else: ?>
                            <button class="btn btn-sm btn-outline-primary" style="font-size:0.78rem;border-radius:8px;padding:4px 14px;"
                                    onclick="openUserDrawer(<?= htmlspecialchars(json_encode($usr)) ?>)">
                                <i class="bi bi-sliders me-1"></i>Manage
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        <?php if ($saTotal > 0): ?>
            <?php
            $page = $saPage;
            $totalPages = $saPages;
            $totalCount = $saTotal;
            $offset = $saOffset;
            $paginationBase = '?' . http_build_query(array_filter([
                'search' => $search,
                'status' => $statusFilter,
            ], static fn($value) => $value !== '')) . '&';
            $paginationLabel = 'system administrator';
            $paginationParam = 'sa_page';
            include __DIR__ . '/../includes/pagination.php';
            ?>
        <?php endif; ?>
    </div>

    <!-- Barangay Admins Table -->
    <div style="background:#fff;border-radius:18px;box-shadow:0 2px 16px rgba(0,0,0,0.07);border:1px solid #e0f0e4;margin-bottom:20px;overflow:hidden;">
        <!-- Card Header -->
        <div style="padding:18px 22px 16px;display:flex;align-items:center;gap:14px;border-bottom:1px solid #e8f4fb;">
            <div style="width:36px;height:36px;background:linear-gradient(135deg,#3a7fa8,#60a5fa);border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="bi bi-person-badge-fill text-white" style="font-size:0.82rem;"></i>
            </div>
            <div style="flex:1;">
                <div style="font-weight:700;font-size:0.95rem;color:#1a2e1f;">Barangay Administrators</div>
                <div style="font-size:0.75rem;color:#3a7fa8;">Barangay-level admin accounts</div>
            </div>
            <span style="background:#e8f4fb;color:#3a7fa8;font-size:0.75rem;font-weight:600;padding:5px 14px;border-radius:20px;">
                <?= count($barangayAdmins) ?> account<?= count($barangayAdmins) !== 1 ? 's' : '' ?>
            </span>
        </div>
        <!-- Table -->
        <?php if (empty($barangayAdmins)): ?>
        <div class="text-center text-muted py-5 small">No barangay administrators found.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table mb-0" style="font-size:0.85rem;">
                <thead>
                    <tr style="border-bottom:1px solid #e8f4fb;">
                        <th class="ps-4 py-3" style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#3a7fa8;background:transparent;border:none;">Name</th>
                        <th class="py-3" style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#3a7fa8;background:transparent;border:none;">Username</th>
                        <th class="py-3" style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#3a7fa8;background:transparent;border:none;">Barangay</th>
                        <th class="py-3" style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#3a7fa8;background:transparent;border:none;">Position</th>
                        <th class="py-3" style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#3a7fa8;background:transparent;border:none;">Status</th>
                        <th class="pe-4 py-3" style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#3a7fa8;background:transparent;border:none;">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($barangayAdminsPaged as $usr): ?>
                    <tr style="border-bottom:1px solid #f8fafc;transition:background 0.15s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                        <td class="ps-4 py-3" style="border:none;">
                            <div class="d-flex align-items-center gap-3">
                                <div style="width:36px;height:36px;background:linear-gradient(135deg,#3a7fa8,#60a5fa);border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:0.72rem;font-weight:700;flex-shrink:0;">
                                    <?= strtoupper(substr($usr['first_name'],0,1).substr($usr['last_name'],0,1)) ?>
                                </div>
                                <div>
                                    <div style="font-weight:600;color:#1a2e1f;"><?= htmlspecialchars($usr['first_name'].' '.$usr['last_name']) ?></div>
                                    <div style="font-size:0.72rem;color:#3a7fa8;"><?= htmlspecialchars($usr['email']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="py-3" style="border:none;color:#2a5070;"><?= htmlspecialchars($usr['username']) ?></td>
                        <td class="py-3" style="border:none;">
                            <?php if (!empty($usr['barangay_name'])): ?>
                            <span style="background:rgba(58,127,168,0.1);color:#1a4f7a;font-size:0.75rem;font-weight:600;padding:4px 10px;border-radius:20px;">
                                <i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($usr['barangay_name']) ?>
                            </span>
                            <?php else: ?>
                            <span style="color:#94a3b8;font-size:0.82rem;">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="py-3" style="border:none;color:#2a5070;font-size:0.82rem;"><?= htmlspecialchars($usr['position'] ?? '—') ?></td>
                        <td class="py-3" style="border:none;">
                            <?php if ($usr['status']==='active' && empty($usr['login_locked'])): ?>
                            <span style="background:#e0f2fb;color:#0d3a5c;font-size:0.72rem;font-weight:600;padding:4px 12px;border-radius:20px;">Active</span>
                            <?php elseif (!empty($usr['login_locked'])): ?>
                            <span style="background:#fff7ed;color:#c2410c;font-size:0.72rem;font-weight:600;padding:4px 12px;border-radius:20px;"><i class="bi bi-lock-fill me-1"></i>Login Locked</span>
                            <?php else: ?>
                            <span style="background:#fff0f0;color:#c62828;font-size:0.72rem;font-weight:600;padding:4px 12px;border-radius:20px;"><i class="bi bi-slash-circle me-1"></i>Blocked</span>
                            <?php endif; ?>
                        </td>
                        <td class="pe-4 py-3" style="border:none;">
                            <button class="btn btn-sm btn-outline-primary" style="font-size:0.78rem;border-radius:8px;padding:4px 14px;"
                                    onclick="openUserDrawer(<?= htmlspecialchars(json_encode($usr)) ?>)">
                                <i class="bi bi-sliders me-1"></i>Manage
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        <?php if ($baTotal > 0): ?>
            <?php
            $page = $baPage;
            $totalPages = $baPages;
            $totalCount = $baTotal;
            $offset = $baOffset;
            $paginationBase = '?' . http_build_query(array_filter([
                'search' => $search,
                'status' => $statusFilter,
            ], static fn($value) => $value !== '')) . '&';
            $paginationLabel = 'barangay administrator';
            $paginationParam = 'ba_page';
            include __DIR__ . '/../includes/pagination.php';
            ?>
        <?php endif; ?>
    </div>
</div>

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px; overflow:hidden;">
            <div class="d-flex">
                <!-- LEFT PANEL -->
                <div class="d-flex flex-column align-items-center justify-content-center px-4 py-5"
                     style="background:linear-gradient(160deg,#1a4f7a 0%,#3a7fa8 100%); min-width:190px; width:190px; flex-shrink:0;">
                    <div style="width:72px;height:72px;background:rgba(255,255,255,0.15);border-radius:50%;display:flex;align-items:center;justify-content:center;border:3px solid rgba(255,255,255,0.3);">
                        <i class="bi bi-person-plus-fill text-white" style="font-size:2rem;"></i>
                    </div>
                    <h5 class="fw-bold mt-3 mb-1 text-white text-center" style="font-size:1rem;">Add New User</h5>
                    <p class="text-white-50 small text-center mb-0">User Management</p>
                </div>
                <!-- RIGHT PANEL -->
                <div class="flex-fill d-flex flex-column" style="min-width:0;">
                    <div class="p-4" style="overflow-y:auto; max-height:75vh;">

                        <!-- Row 1: Name -->
                        <div class="row g-2 mb-2">
                            <div class="col-md-4">
                                <label class="form-label form-label-sm fw-semibold mb-1">First Name <span class="text-danger">*</span></label>
                                <input type="text" id="add_first_name" class="form-control form-control-sm" required oninput="checkDuplicateName()">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label form-label-sm fw-semibold mb-1">Middle Name</label>
                                <input type="text" id="add_middle_name" class="form-control form-control-sm" oninput="checkDuplicateName()">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label form-label-sm fw-semibold mb-1">Last Name <span class="text-danger">*</span></label>
                                <input type="text" id="add_last_name" class="form-control form-control-sm" required oninput="checkDuplicateName()">
                            </div>
                        </div>
                        <div id="dupNameWarn" style="display:none;" class="mb-2">
                            <div class="alert alert-warning py-1 small mb-0">
                                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                                A user with this exact full name already exists. Please check the first, middle, and last name.
                            </div>
                        </div>

                        <!-- Row 2: Username / Email / Contact -->
                        <div class="row g-2 mb-2">
                            <div class="col-md-4">
                                <label class="form-label form-label-sm fw-semibold mb-1">Username <span class="text-danger">*</span></label>
                                <div class="input-group input-group-sm">
                                    <input type="text" id="add_username" class="form-control form-control-sm" placeholder="e.g. jdelacruz" required oninput="checkUsernameAvailability()">
                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="suggestUsername()" title="Suggest">
                                        <i class="bi bi-magic"></i>
                                    </button>
                                </div>
                                <div class="form-text" style="font-size:0.7rem;">Used for login.</div>
                                <div id="usernameAvailability" class="mt-1" style="display:none;font-size:0.75rem;" aria-live="polite"></div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label form-label-sm fw-semibold mb-1">Email <span class="text-danger">*</span></label>
                                <input type="email" id="add_email" class="form-control form-control-sm" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label form-label-sm fw-semibold mb-1">Contact Number</label>
                                <input type="text" id="add_contact" class="form-control form-control-sm"
                                       inputmode="numeric" pattern="09[0-9]{9}" placeholder="e.g. 09171234567"
                                       oninput="validateContact(this,'add_contact_err')">
                                <div id="add_contact_err" class="text-danger mt-1" style="display:none;font-size:0.7rem;">
                                    <i class="bi bi-x-circle me-1"></i>Must be 11 digits starting with 09
                                </div>
                            </div>
                        </div>

                        <!-- Row 3: Role / Barangay / Position -->
                        <div class="row g-2 mb-2">
                            <div class="col-md-3">
                                <label class="form-label form-label-sm fw-semibold mb-1">Role <span class="text-danger">*</span></label>
                                <select id="add_role" class="form-select form-select-sm" required>
                                    <option value="barangay_admin">Barangay Admin</option>
                                    <option value="super_admin">Super Admin</option>
                                </select>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label form-label-sm fw-semibold mb-1">Barangay</label>
                                <!-- Custom dropdown to prevent overflow -->
                                <div class="position-relative" id="brgyDropdownWrap">
                                    <input type="text" id="brgySearch" class="form-control form-control-sm"
                                           placeholder="— Search barangay —"
                                           autocomplete="off"
                                           onfocus="showBrgyList()"
                                           oninput="filterBrgyList(this.value)">
                                    <div id="brgyListBox"
                                         style="display:none;position:absolute;top:100%;left:0;right:0;z-index:9999;
                                                background:#fff;border:1px solid #ced4da;border-radius:6px;
                                                max-height:180px;overflow-y:auto;box-shadow:0 4px 12px rgba(0,0,0,0.15);">
                                        <div class="brgy-option px-3 py-1 small text-muted" data-value="" data-muni=""
                                             style="cursor:pointer;" onmousedown="selectBrgy('','','— None —','','','')">— None —</div>
                                        <?php foreach ($barangays as $b): ?>
                                        <div class="brgy-option px-3 py-1 small"
                                             data-value="<?= $b['id'] ?>"
                                             data-muni="<?= htmlspecialchars($b['municipality'] ?? '') ?>"
                                             data-name="<?= htmlspecialchars($b['barangay_name']) ?>"
                                             data-region="<?= htmlspecialchars($b['region'] ?? '') ?>"
                                             data-province="<?= htmlspecialchars($b['province'] ?? '') ?>"
                                             data-zip="<?= htmlspecialchars($b['zip_code'] ?? '') ?>"
                                             style="cursor:pointer;"
                                             onmousedown="selectBrgy('<?= $b['id'] ?>','<?= htmlspecialchars(addslashes($b['municipality'] ?? '')) ?>','<?= htmlspecialchars(addslashes($b['barangay_name'])) ?>','<?= htmlspecialchars(addslashes($b['region'] ?? '')) ?>','<?= htmlspecialchars(addslashes($b['province'] ?? '')) ?>','<?= htmlspecialchars(addslashes($b['zip_code'] ?? '')) ?>')">
                                            <?= htmlspecialchars($b['barangay_name']) ?>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <!-- Hidden actual select for form submission -->
                                <select id="add_barangay" style="display:none;">
                                    <option value="">— None —</option>
                                    <?php foreach ($barangays as $b): ?>
                                        <option value="<?= $b['id'] ?>"
                                                data-name="<?= htmlspecialchars($b['barangay_name']) ?>"
                                                data-municipality="<?= htmlspecialchars($b['municipality'] ?? '') ?>"
                                                data-region="<?= htmlspecialchars($b['region'] ?? '') ?>"
                                                data-province="<?= htmlspecialchars($b['province'] ?? '') ?>"
                                                data-zip="<?= htmlspecialchars($b['zip_code'] ?? '') ?>">
                                            <?= htmlspecialchars($b['barangay_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label form-label-sm fw-semibold mb-1">Position</label>
                                <input type="text" id="add_position" class="form-control form-control-sm" placeholder="e.g. Barangay Secretary">
                            </div>
                        </div>

                        <!-- Row 4: Address -->
                        <div id="brgyAddressSection">
                            <hr class="my-2">
                            <p class="text-muted mb-2" style="font-size:0.78rem;"><i class="bi bi-geo-alt me-1"></i><strong>Barangay Address</strong> <span class="text-muted" style="font-weight:400;">— auto-inherited by all residents under this barangay</span></p>
                            <div class="row g-2 mb-2">
                                <div class="col-md-5">
                                    <label class="form-label form-label-sm fw-semibold mb-1">Region</label>
                                    <select id="add_brgy_region" class="form-select form-select-sm">
                                        <option value="">— Select Region —</option>
                                        <option value="Region I - Ilocos Region">Region I — Ilocos Region</option>
                                        <option value="Region II - Cagayan Valley">Region II — Cagayan Valley</option>
                                        <option value="Region III - Central Luzon" selected>Region III — Central Luzon</option>
                                        <option value="Region IV-A - CALABARZON">Region IV-A — CALABARZON</option>
                                        <option value="NCR - National Capital Region">NCR — National Capital Region</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label form-label-sm fw-semibold mb-1">Province</label>
                                    <select id="add_brgy_province" class="form-select form-select-sm">
                                        <option value="">— Select Province —</option>
                                        <option value="Nueva Ecija" selected>Nueva Ecija</option>
                                        <option value="Aurora">Aurora</option>
                                        <option value="Bataan">Bataan</option>
                                        <option value="Bulacan">Bulacan</option>
                                        <option value="Pampanga">Pampanga</option>
                                        <option value="Tarlac">Tarlac</option>
                                        <option value="Zambales">Zambales</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label form-label-sm fw-semibold mb-1">Zip Code</label>
                                    <input type="text" id="add_brgy_zip" class="form-control form-control-sm" placeholder="e.g. 3114" inputmode="numeric" maxlength="10">
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label form-label-sm fw-semibold mb-1">City / Municipality</label>
                                    <input type="text" id="add_brgy_city" class="form-control form-control-sm" placeholder="e.g. Talavera">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label form-label-sm fw-semibold mb-1">Barangay</label>
                                    <input type="text" id="add_brgy_name_display" class="form-control form-control-sm bg-light" readonly placeholder="Auto-filled from selected barangay">
                                </div>
                            </div>
                            <div class="alert alert-info py-2 small mb-0" style="border-radius:8px;">
                                <i class="bi bi-info-circle me-1"></i>
                                Region, Province, City/Municipality, and Barangay are saved to the barangay record. Residents added under this barangay will automatically inherit this address. Only <strong>Purok / Street / Zone</strong> will be entered per resident.
                            </div>
                        </div>

                        <!-- Row 5: Password -->
                        <hr class="my-2">
                        <p class="text-muted mb-2" style="font-size:0.78rem;"><i class="bi bi-key me-1"></i><strong>Password</strong> <span class="text-danger">*</span></p>
                        <div class="d-flex gap-3 mb-2">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="pass_mode" id="passRandom" value="random" checked onchange="togglePassMode()">
                                <label class="form-check-label small" for="passRandom">
                                    <i class="bi bi-shuffle me-1"></i>Generate random
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="pass_mode" id="passManual" value="manual" onchange="togglePassMode()">
                                <label class="form-check-label small" for="passManual">
                                    <i class="bi bi-keyboard me-1"></i>Set manually
                                </label>
                            </div>
                        </div>
                        <div id="randomPassInfo" class="alert alert-info py-2 small mb-0">
                            <i class="bi bi-info-circle me-1"></i>A secure random password will be generated and shown in the review step.
                        </div>
                        <div id="manualPassFields" style="display:none;">
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <div class="input-group input-group-sm">
                                        <input type="password" id="add_password" class="form-control form-control-sm" placeholder="Enter password" minlength="8">
                                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="togglePassVis('add_password','eyeAdd')">
                                            <i class="bi bi-eye" id="eyeAdd"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="input-group input-group-sm">
                                        <input type="password" id="add_password_confirm" class="form-control form-control-sm" placeholder="Confirm password">
                                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="togglePassVis('add_password_confirm','eyeAddC')">
                                            <i class="bi bi-eye" id="eyeAddC"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div id="passMatchMsg" class="form-text"></div>
                                </div>
                            </div>
                        </div>

                    </div>
                    <!-- Buttons -->
                    <div class="d-flex gap-2 px-4 py-3 border-top">
                        <button type="button" class="btn btn-outline-secondary flex-fill" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary flex-fill fw-semibold" onclick="openReviewModal()">
                            <i class="bi bi-eye me-1"></i>Review & Save
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Review Modal -->
<div class="modal fade" id="reviewUserModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST" id="createUserForm">
            <input type="hidden" name="action" value="add">
            <?= csrfField() ?>
            <input type="hidden" name="first_name" id="r_first_name">
            <input type="hidden" name="middle_name" id="r_middle_name">
            <input type="hidden" name="last_name" id="r_last_name">
            <input type="hidden" name="email" id="r_email">
            <input type="hidden" name="contact_number" id="r_contact">
            <input type="hidden" name="role" id="r_role">
            <input type="hidden" name="barangay_id" id="r_barangay_id">
            <input type="hidden" name="position" id="r_position">
            <input type="hidden" name="username" id="r_username">
            <input type="hidden" name="password_mode" id="r_password_mode">
            <input type="hidden" name="manual_password" id="r_manual_password">
            <input type="hidden" name="brgy_region" id="r_brgy_region">
            <input type="hidden" name="brgy_province" id="r_brgy_province">
            <input type="hidden" name="brgy_municipality" id="r_brgy_municipality">
            <input type="hidden" name="brgy_zip_code" id="r_brgy_zip_code">
            <div class="modal-content" style="border-radius:16px; overflow:hidden;">
                <div class="d-flex" style="min-height:300px;">
                    <!-- LEFT PANEL -->
                    <div class="d-flex flex-column align-items-center justify-content-center px-4 py-5"
                         style="background:linear-gradient(160deg,#1b5e20 0%,#1a4f7a 100%); min-width:190px; width:190px; flex-shrink:0;">
                        <div style="width:72px;height:72px;background:rgba(255,255,255,0.15);border-radius:50%;display:flex;align-items:center;justify-content:center;border:3px solid rgba(255,255,255,0.3);">
                            <i class="bi bi-clipboard-check-fill text-white" style="font-size:2rem;"></i>
                        </div>
                        <h5 class="fw-bold mt-3 mb-1 text-white text-center" style="font-size:1rem;">Review Details</h5>
                        <p class="text-white-50 small text-center mb-0">Confirm before creating</p>
                    </div>
                    <!-- RIGHT PANEL -->
                    <div class="flex-fill d-flex flex-column justify-content-between p-4">
                        <div>
                            <p class="text-muted small mb-3">Please review the details below before creating the account.</p>
                            <table class="table table-borderless mb-0" style="font-size:0.88rem;">
                                <tbody>
                                    <tr>
                                        <td class="text-muted fw-semibold py-1" style="width:38%">Full Name</td>
                                        <td class="fw-semibold py-1" id="rv_fullname"></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted fw-semibold py-1">Username</td>
                                        <td class="py-1"><code id="rv_username"></code></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted fw-semibold py-1">Email</td>
                                        <td class="py-1" id="rv_email"></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted fw-semibold py-1">Role</td>
                                        <td class="py-1" id="rv_role"></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted fw-semibold py-1">Barangay</td>
                                        <td class="py-1" id="rv_barangay"></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted fw-semibold py-1">Position</td>
                                        <td class="py-1" id="rv_position"></td>
                                    </tr>
                                    <tr>
                                        <td class="text-muted fw-semibold py-1">Password</td>
                                        <td class="py-1">
                                            <span id="rv_password_display"></span>
                                            <button type="button" class="btn btn-link btn-sm p-0 ms-1" onclick="toggleReviewPass()" id="rv_pass_toggle" style="display:none;">
                                                <i class="bi bi-eye" id="rv_eye"></i>
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <div class="alert alert-warning py-2 small mt-3 mb-0">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                Make sure to note the password — it will only be shown once after saving.
                            </div>
                        </div>
                        <div class="d-flex gap-2 mt-4">
                            <button type="button" class="btn btn-outline-secondary flex-fill" onclick="backToAddModal()">
                                <i class="bi bi-arrow-left me-1"></i>Back
                            </button>
                            <button type="submit" class="btn btn-success flex-fill fw-semibold">
                                <i class="bi bi-check-lg me-1"></i>Confirm & Create User
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background:#1a4f7a; color:#fff; border-radius:12px 12px 0 0;">
                <div>
                    <h5 class="modal-title mb-0"><i class="bi bi-pencil-square me-2"></i><span id="editUserFullName"></span></h5>
                    <small style="color:rgba(255,255,255,0.55);">Update the details below</small>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <!-- Name -->
                    <div class="col-md-4">
                        <label class="form-label">First Name <span class="text-danger">*</span></label>
                        <input type="text" id="edit_first_name" class="form-control" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Middle Name</label>
                        <input type="text" id="edit_middle_name" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Last Name <span class="text-danger">*</span></label>
                        <input type="text" id="edit_last_name" class="form-control" required>
                    </div>
                    <!-- Username -->
                    <div class="col-md-6">
                        <label class="form-label">Username <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="text" id="edit_username" class="form-control" placeholder="e.g. jdelacruz" required>
                            <button type="button" class="btn btn-outline-secondary" onclick="suggestEditUsername()" title="Suggest username">
                                <i class="bi bi-magic"></i>
                            </button>
                        </div>
                        <div class="form-text">Change the login username if needed.</div>
                    </div>
                    <!-- Email -->
                    <div class="col-md-6">
                        <label class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" id="edit_email" class="form-control" required>
                    </div>
                    <!-- Contact -->
                    <div class="col-md-4">
                        <label class="form-label">Contact Number</label>
                        <input type="text" id="edit_contact" class="form-control"
                               inputmode="numeric" pattern="09[0-9]{9}" placeholder="e.g. 09171234567"
                               oninput="validateContact(this,'edit_contact_err')">
                        <div id="edit_contact_err" class="text-danger small mt-1" style="display:none;"></div>
                    </div>
                    <!-- Barangay -->
                    <div class="col-md-4">
                        <label class="form-label">Barangay</label>
                        <select id="edit_barangay" class="form-select" onchange="onEditBrgyChange(this)">
                            <option value="">— None —</option>
                            <?php foreach ($barangays as $b): ?>
                                <option value="<?= $b['id'] ?>"
                                    data-name="<?= htmlspecialchars($b['barangay_name']) ?>"
                                    data-region="<?= htmlspecialchars($b['region'] ?? '') ?>"
                                    data-province="<?= htmlspecialchars($b['province'] ?? '') ?>"
                                    data-municipality="<?= htmlspecialchars($b['municipality'] ?? '') ?>"
                                    data-zip="<?= htmlspecialchars($b['zip_code'] ?? '') ?>">
                                    <?= htmlspecialchars($b['barangay_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <!-- Position -->
                    <div class="col-md-4">
                        <label class="form-label">Position</label>
                        <input type="text" id="edit_position" class="form-control" placeholder="e.g. Barangay Secretary">
                    </div>
                    <!-- Status -->
                    <div class="col-md-4">
                        <label class="form-label">Status</label>
                        <select id="edit_status" class="form-select">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                    <!-- Password Section -->
                    <div class="col-12">
                        <label class="form-label">Password</label>
                        <div class="d-flex gap-3 mb-2 flex-wrap">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="edit_pass_mode" id="editPassKeep" value="keep" checked onchange="toggleEditPassMode()">
                                <label class="form-check-label" for="editPassKeep">
                                    <i class="bi bi-lock me-1"></i>Keep current password
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="edit_pass_mode" id="editPassRandom" value="random" onchange="toggleEditPassMode()">
                                <label class="form-check-label" for="editPassRandom">
                                    <i class="bi bi-shuffle me-1"></i>Generate new random password
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="edit_pass_mode" id="editPassManual" value="manual" onchange="toggleEditPassMode()">
                                <label class="form-check-label" for="editPassManual">
                                    <i class="bi bi-keyboard me-1"></i>Set new password manually
                                </label>
                            </div>
                        </div>
                        <div id="editKeepPassInfo" class="alert alert-secondary py-2 small mb-0">
                            <i class="bi bi-info-circle me-1"></i>The user's current password will not be changed.
                        </div>
                        <div id="editRandomPassInfo" style="display:none;" class="alert alert-info py-2 small mb-0">
                            <i class="bi bi-info-circle me-1"></i>A new secure random password will be generated and shown in the review step.
                        </div>
                        <div id="editManualPassFields" style="display:none;">
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <div class="input-group">
                                        <input type="password" id="edit_password" class="form-control" placeholder="New password" minlength="8">
                                        <button type="button" class="btn btn-outline-secondary" onclick="togglePassVis('edit_password','eyeEdit')">
                                            <i class="bi bi-eye" id="eyeEdit"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="input-group">
                                        <input type="password" id="edit_password_confirm" class="form-control" placeholder="Confirm new password">
                                        <button type="button" class="btn btn-outline-secondary" onclick="togglePassVis('edit_password_confirm','eyeEditC')">
                                            <i class="bi bi-eye" id="eyeEditC"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div id="editPassMatchMsg" class="form-text"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="openEditReviewModal()">
                    <i class="bi bi-eye me-1"></i>Review & Save
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Review Modal -->
<div class="modal fade" id="editReviewModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST" id="editUserForm">
            <input type="hidden" name="action" value="edit">
            <?= csrfField() ?>
            <input type="hidden" name="user_id" id="er_user_id">
            <input type="hidden" name="first_name" id="er_first_name">
            <input type="hidden" name="middle_name" id="er_middle_name">
            <input type="hidden" name="last_name" id="er_last_name">
            <input type="hidden" name="email" id="er_email">
            <input type="hidden" name="contact_number" id="er_contact">
            <input type="hidden" name="barangay_id" id="er_barangay_id">
            <input type="hidden" name="position" id="er_position">
            <input type="hidden" name="username" id="er_username">
            <input type="hidden" name="status" id="er_status">
            <input type="hidden" name="edit_password_mode" id="er_password_mode">
            <input type="hidden" name="edit_manual_password" id="er_manual_password">
            <input type="hidden" name="brgy_region" id="er_brgy_region">
            <input type="hidden" name="brgy_province" id="er_brgy_province">
            <input type="hidden" name="brgy_municipality" id="er_brgy_municipality">
            <input type="hidden" name="brgy_zip_code" id="er_brgy_zip_code">
            <div class="modal-content" style="border-radius:16px; overflow:hidden;">
                <div class="d-flex" style="min-height:300px;">
                    <!-- LEFT PANEL -->
                    <div class="d-flex flex-column align-items-center justify-content-center px-4 py-5"
                         style="background:linear-gradient(160deg,#1a4f7a 0%,#3a7fa8 100%); min-width:190px; width:190px; flex-shrink:0;">
                        <div style="width:72px;height:72px;background:rgba(255,255,255,0.15);border-radius:50%;display:flex;align-items:center;justify-content:center;border:3px solid rgba(255,255,255,0.3);">
                            <i class="bi bi-clipboard-check-fill text-white" style="font-size:2rem;"></i>
                        </div>
                        <h5 class="fw-bold mt-3 mb-1 text-white text-center" style="font-size:1rem;">Review Changes</h5>
                        <p class="text-white-50 small text-center mb-0" id="er_title_name" style="word-break:break-word;"></p>
                    </div>
                    <!-- RIGHT PANEL -->
                    <div class="flex-fill d-flex flex-column justify-content-between p-4">
                        <div>
                            <p class="text-muted small mb-3">Please review the updated details before saving.</p>
                            <table class="table table-borderless mb-0" style="font-size:0.88rem;">
                                <tbody>
                                    <tr><td class="text-muted fw-semibold py-1" style="width:38%">Full Name</td><td class="fw-semibold py-1" id="er_rv_fullname"></td></tr>
                                    <tr><td class="text-muted fw-semibold py-1">Username</td><td class="py-1"><code id="er_rv_username"></code></td></tr>
                                    <tr><td class="text-muted fw-semibold py-1">Email</td><td class="py-1" id="er_rv_email"></td></tr>
                                    <tr><td class="text-muted fw-semibold py-1">Barangay</td><td class="py-1" id="er_rv_barangay"></td></tr>
                                    <tr><td class="text-muted fw-semibold py-1">Position</td><td class="py-1" id="er_rv_position"></td></tr>
                                    <tr><td class="text-muted fw-semibold py-1">Status</td><td class="py-1" id="er_rv_status"></td></tr>
                                    <tr>
                                        <td class="text-muted fw-semibold py-1">Password</td>
                                        <td class="py-1">
                                            <span id="er_rv_password_display"></span>
                                            <button type="button" class="btn btn-link btn-sm p-0 ms-1" onclick="toggleEditReviewPass()" id="er_pass_toggle" style="display:none;">
                                                <i class="bi bi-eye" id="er_eye"></i>
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <div class="alert alert-warning py-2 small mt-3 mb-0" id="er_pass_note" style="display:none;">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                Make sure to note the new password — it will only be shown once after saving.
                            </div>
                        </div>
                        <div class="d-flex gap-2 mt-4">
                            <button type="button" class="btn btn-outline-secondary flex-fill" onclick="backToEditModal()">
                                <i class="bi bi-arrow-left me-1"></i>Back
                            </button>
                            <button type="submit" class="btn btn-success flex-fill fw-semibold">
                                <i class="bi bi-check-lg me-1"></i>Confirm & Save Changes
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<!-- Archive User Modal -->
<div class="modal fade" id="archiveUserModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px; overflow:hidden;">
            <div class="d-flex">
                <!-- LEFT PANEL -->
                <div class="d-flex flex-column align-items-center justify-content-center px-4 py-5"
                     style="background:linear-gradient(160deg,#1a4f7a 0%,#3a7fa8 100%); min-width:200px; width:200px; align-self:stretch;">
                    <div style="width:72px;height:72px;background:rgba(255,255,255,0.15);border-radius:50%;display:flex;align-items:center;justify-content:center;border:3px solid rgba(255,255,255,0.3);">
                        <i class="bi bi-archive-fill text-white" style="font-size:2rem;"></i>
                    </div>
                    <h5 class="fw-bold mt-3 mb-1 text-white text-center">Archive User</h5>
                    <p class="text-white-50 small text-center mb-0">This action is reversible</p>
                </div>
                <!-- RIGHT PANEL -->
                <div class="flex-fill d-flex flex-column justify-content-between p-4">
                    <div>
                        <p class="mb-1">You are about to archive:</p>
                        <p class="fw-bold fs-6" id="archiveUserName"></p>
                        <div class="alert alert-warning small py-2 mb-0">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            The user will be set to inactive and moved to the archive. It can be restored from the Archive page.
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-3">
                        <button type="button" class="btn btn-outline-secondary flex-fill" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary flex-fill fw-semibold" onclick="submitArchive()">
                            <i class="bi bi-check-lg me-1"></i>Confirm Archive
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Block Access Confirm Modal -->
<div class="modal fade" id="blockUserModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px; overflow:hidden;">
            <div class="d-flex">
                <!-- LEFT -->
                <div class="d-flex flex-column align-items-center justify-content-center px-4 py-5"
                     id="blockLeftPanel"
                     style="background:linear-gradient(160deg,#1a4f7a 0%,#3a7fa8 100%); min-width:220px; width:220px; align-self:stretch;">
                    <div style="width:80px;height:80px;background:rgba(255,255,255,0.15);border-radius:50%;display:flex;align-items:center;justify-content:center;border:3px solid rgba(255,255,255,0.3);">
                        <i id="blockModalIconI" class="bi bi-slash-circle-fill text-white" style="font-size:2.2rem;"></i>
                    </div>
                    <h5 class="fw-bold mt-3 mb-1 text-white text-center" id="blockModalTitle">Block Access</h5>
                    <p class="text-white-50 small text-center mb-0">Confirm action</p>
                </div>
                <!-- RIGHT -->
                <div class="flex-fill d-flex flex-column justify-content-between p-4">
                    <div>
                        <p class="text-muted small mb-2" id="blockModalSubtitle">You are about to block:</p>
                        <p class="fw-bold fs-6 mb-3" id="blockUserName" style="color:#1a4f7a;"></p>
                        <div class="alert py-2 small mb-0" id="blockModalDesc"></div>
                    </div>
                    <div class="d-flex gap-2 mt-4">
                        <button type="button" class="btn btn-outline-secondary flex-fill" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary flex-fill fw-semibold" id="blockConfirmBtn" onclick="submitBlock()"></button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Hidden forms for archive and block -->
<form method="POST" id="archiveUserForm" style="display:none;">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="archive">
    <input type="hidden" name="user_id" id="archiveUserId">
</form>
<form method="POST" id="blockUserForm" style="display:none;">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="block_access">
    <input type="hidden" name="user_id" id="blockUserId">
    <input type="hidden" name="new_status" id="blockNewStatus">
</form>
<!-- Unlock Login hidden form -->
<form method="POST" id="unlockLoginForm" style="display:none;">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="unlock_login">
    <input type="hidden" name="user_id" id="unlockLoginUserId">
</form>
<!-- Reset Password Modal -->
<div class="modal fade" id="resetPassModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST" id="resetPassForm">
            <input type="hidden" name="action" value="reset_password">
            <?= csrfField() ?>
            <input type="hidden" name="user_id" id="rp_user_id">
            <input type="hidden" name="reset_pass_mode" id="rp_mode_hidden">
            <input type="hidden" name="reset_manual_password" id="rp_manual_hidden">
            <div class="modal-content" style="border-radius:16px; overflow:hidden;">
                <div class="d-flex" style="min-height:320px;">
                    <!-- LEFT — icon + title panel -->
                    <div class="d-flex flex-column align-items-center justify-content-center px-4 py-5"
                         style="background:linear-gradient(160deg,#1a4f7a 0%,#3a7fa8 100%); min-width:220px; width:220px; align-self:stretch;">
                        <div style="width:80px;height:80px;background:rgba(255,255,255,0.15);border-radius:50%;display:flex;align-items:center;justify-content:center;border:3px solid rgba(255,255,255,0.3);">
                            <i class="bi bi-key-fill text-white" style="font-size:2.2rem;"></i>
                        </div>
                        <h5 class="fw-bold mt-3 mb-1 text-white text-center">Reset Password</h5>
                        <p class="text-white-50 small text-center mb-0" id="rp_subtitle"></p>
                    </div>

                    <!-- RIGHT — form panel -->
                    <div class="flex-fill d-flex flex-column">
                        <div class="flex-fill p-4">
                            <!-- Password mode -->
                            <p class="text-muted small mb-3">Choose how to reset the password:</p>
                            <div class="d-flex flex-column gap-2 mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="rp_mode_ui" id="rpRandom" value="random" checked onchange="toggleResetPassMode()">
                                    <label class="form-check-label fw-semibold" for="rpRandom">
                                        <i class="bi bi-shuffle me-1 text-primary"></i>Generate random password
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="rp_mode_ui" id="rpManual" value="manual" onchange="toggleResetPassMode()">
                                    <label class="form-check-label fw-semibold" for="rpManual">
                                        <i class="bi bi-keyboard me-1 text-primary"></i>Set new password manually
                                    </label>
                                </div>
                            </div>

                            <!-- Random info -->
                            <div id="rpRandomInfo" class="alert alert-info py-2 small mb-0">
                                <i class="bi bi-info-circle me-1"></i>A secure random password will be generated and shown after saving.
                            </div>

                            <!-- Manual fields -->
                            <div id="rpManualFields" style="display:none;">
                                <div class="mb-3">
                                    <label class="form-label fw-semibold" style="color:#1a4f7a;">New Password</label>
                                    <div class="input-group">
                                        <input type="password" id="rp_password" class="form-control" placeholder="Password" minlength="8">
                                        <button type="button" class="btn btn-outline-secondary" onclick="togglePassVis('rp_password','rpEye1')">
                                            <i class="bi bi-eye" id="rpEye1"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label fw-semibold" style="color:#1a4f7a;">Confirm New Password</label>
                                    <div class="input-group">
                                        <input type="password" id="rp_password_confirm" class="form-control" placeholder="Password">
                                        <button type="button" class="btn btn-outline-secondary" onclick="togglePassVis('rp_password_confirm','rpEye2')">
                                            <i class="bi bi-eye" id="rpEye2"></i>
                                        </button>
                                    </div>
                                </div>
                                <div id="rpMatchMsg" class="form-text"></div>
                            </div>
                        </div>

                        <!-- Footer inside right panel -->
                        <div class="px-4 pb-4 d-flex gap-2">
                            <button type="button" class="btn btn-outline-secondary flex-fill" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-primary flex-fill fw-semibold" onclick="submitResetPass()">
                                <i class="bi bi-check-lg me-1"></i>Continue
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
// ── Edit User ──────────────────────────────────────────────
let _editUserId = null;
let _editGeneratedPass = '';
let _editReviewPassVisible = false;

function editUser(u) {
    _editUserId = u.id;
    const fullName = [u.first_name, u.middle_name, u.last_name].filter(Boolean).join(' ');
    document.getElementById('editUserFullName').textContent = fullName;
    document.getElementById('edit_first_name').value  = u.first_name;
    document.getElementById('edit_middle_name').value = u.middle_name || '';
    document.getElementById('edit_last_name').value   = u.last_name;
    document.getElementById('edit_username').value    = u.username;
    document.getElementById('edit_email').value       = u.email;
    document.getElementById('edit_contact').value     = u.contact_number || '';
    document.getElementById('edit_barangay').value    = u.barangay_id || '';
    document.getElementById('edit_position').value    = u.position || '';
    document.getElementById('edit_status').value      = u.status;
    // Populate barangay address hidden fields from existing barangay record
    document.getElementById('r_brgy_region').value      = u.brgy_region       || '';
    document.getElementById('r_brgy_province').value    = u.brgy_province      || '';
    document.getElementById('r_brgy_municipality').value= u.brgy_municipality  || '';
    document.getElementById('r_brgy_zip_code').value    = u.brgy_zip_code      || '3114';
    // Reset password mode to "keep"
    document.getElementById('editPassKeep').checked = true;
    toggleEditPassMode();
    // Reset contact validation
    document.getElementById('edit_contact').classList.remove('is-invalid','is-valid');
    document.getElementById('edit_contact_err').style.display = 'none';
    new bootstrap.Modal(document.getElementById('editUserModal')).show();
}

// When barangay changes in edit modal, sync address fields from the option's data attributes
function onEditBrgyChange(sel) {
    const opt = sel.options[sel.selectedIndex];
    document.getElementById('r_brgy_region').value       = opt.dataset.region       || '';
    document.getElementById('r_brgy_province').value     = opt.dataset.province     || '';
    document.getElementById('r_brgy_municipality').value = opt.dataset.municipality || '';
    document.getElementById('r_brgy_zip_code').value     = opt.dataset.zip          || '3114';
}

function toggleEditPassMode() {
    const mode = document.querySelector('input[name="edit_pass_mode"]:checked').value;
    document.getElementById('editKeepPassInfo').style.display   = mode === 'keep'   ? 'block' : 'none';
    document.getElementById('editRandomPassInfo').style.display = mode === 'random' ? 'block' : 'none';
    document.getElementById('editManualPassFields').style.display = mode === 'manual' ? 'block' : 'none';
    if (mode !== 'manual') {
        document.getElementById('edit_password').value = '';
        document.getElementById('edit_password_confirm').value = '';
        document.getElementById('editPassMatchMsg').textContent = '';
    }
}

function suggestEditUsername() {
    const fn = document.getElementById('edit_first_name').value.trim().toLowerCase();
    const ln = document.getElementById('edit_last_name').value.trim().toLowerCase().replace(/\s+/g,'');
    if (!fn || !ln) { alert('Enter first and last name first.'); return; }
    document.getElementById('edit_username').value = fn[0] + ln + Math.floor(Math.random()*90+10);
}

document.addEventListener('DOMContentLoaded', function () {
    ['edit_password','edit_password_confirm'].forEach(id => {
        document.getElementById(id).addEventListener('input', checkEditPassMatch);
    });
});
function checkEditPassMatch() {
    const p1 = document.getElementById('edit_password').value;
    const p2 = document.getElementById('edit_password_confirm').value;
    const msg = document.getElementById('editPassMatchMsg');
    if (!p2) { msg.textContent = ''; return; }
    if (p1 === p2) { msg.innerHTML = '<span class="text-success"><i class="bi bi-check-circle me-1"></i>Passwords match</span>'; }
    else { msg.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>Passwords do not match</span>'; }
}

function openEditReviewModal() {
    const fn = document.getElementById('edit_first_name').value.trim();
    const ln = document.getElementById('edit_last_name').value.trim();
    const un = document.getElementById('edit_username').value.trim();
    const em = document.getElementById('edit_email').value.trim();
    const mode = document.querySelector('input[name="edit_pass_mode"]:checked').value;

    if (!fn || !ln || !un || !em) {
        alert('Please fill in all required fields (First Name, Last Name, Username, Email).'); return;
    }
    const ct = document.getElementById('edit_contact').value.trim();
    if (ct && !/^09\d{9}$/.test(ct)) {
        document.getElementById('edit_contact').classList.add('is-invalid');
        document.getElementById('edit_contact_err').style.display = 'block';
        document.getElementById('edit_contact_err').innerHTML = '<i class="bi bi-x-circle me-1"></i>Must be 11 digits starting with 09 (e.g. 09171234567).';
        document.getElementById('edit_contact').focus();
        return;
    }
    if (mode === 'manual') {
        const p1 = document.getElementById('edit_password').value;
        const p2 = document.getElementById('edit_password_confirm').value;
        if (!p1 || !validateAlphanumericPass(p1)) {
            document.getElementById('editPassMatchMsg').innerHTML = '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>Password must be at least 8 characters and contain both letters and numbers</span>';
            return;
        }
        if (p1 !== p2) { alert('Passwords do not match.'); return; }
    }

    const mn       = document.getElementById('edit_middle_name').value.trim();
    const brgyEl   = document.getElementById('edit_barangay');
    const brgyId   = brgyEl.value;
    const brgyName = brgyEl.options[brgyEl.selectedIndex].dataset.name || '— None —';
    const position = document.getElementById('edit_position').value.trim();
    const status   = document.getElementById('edit_status').value;
    const password = mode === 'manual' ? document.getElementById('edit_password').value
                   : mode === 'random' ? generateRandomPass() : '';

    _editGeneratedPass = password;
    _editReviewPassVisible = false;

    // Populate hidden fields
    document.getElementById('er_user_id').value       = _editUserId;
    document.getElementById('er_first_name').value    = fn;
    document.getElementById('er_middle_name').value   = mn;
    document.getElementById('er_last_name').value     = ln;
    document.getElementById('er_email').value         = em;
    document.getElementById('er_contact').value       = ct;
    document.getElementById('er_barangay_id').value   = brgyId;
    document.getElementById('er_position').value      = position;
    document.getElementById('er_username').value      = un;
    document.getElementById('er_status').value        = status;
    document.getElementById('er_password_mode').value = mode;
    document.getElementById('er_manual_password').value = password;

    // Carry barangay address fields through to the review form
    document.getElementById('er_brgy_region').value       = document.getElementById('r_brgy_region').value;
    document.getElementById('er_brgy_province').value     = document.getElementById('r_brgy_province').value;
    document.getElementById('er_brgy_municipality').value = document.getElementById('r_brgy_municipality').value;
    document.getElementById('er_brgy_zip_code').value     = document.getElementById('r_brgy_zip_code').value;
    const fullName = [fn, mn, ln].filter(Boolean).join(' ');
    document.getElementById('er_title_name').textContent    = fullName;
    document.getElementById('er_rv_fullname').textContent   = fullName;
    document.getElementById('er_rv_username').textContent   = un;
    document.getElementById('er_rv_email').textContent      = em;
    document.getElementById('er_rv_barangay').textContent   = brgyName;
    document.getElementById('er_rv_position').textContent   = position || '—';
    document.getElementById('er_rv_status').innerHTML       = status === 'active'
        ? '<span class="badge bg-success">Active</span>'
        : '<span class="badge bg-secondary">Inactive</span>';

    const pdEl   = document.getElementById('er_rv_password_display');
    const ptEl   = document.getElementById('er_pass_toggle');
    const noteEl = document.getElementById('er_pass_note');
    if (mode === 'keep') {
        pdEl.innerHTML = '<span class="badge bg-secondary">Unchanged</span>';
        ptEl.style.display = 'none';
        noteEl.style.display = 'none';
    } else if (mode === 'random') {
        pdEl.innerHTML = '<span class="badge bg-info text-dark">Auto-generated</span> <span id="er_pass_text" style="display:none;">' + password + '</span>';
        ptEl.style.display = 'inline';
        noteEl.style.display = 'block';
    } else {
        pdEl.innerHTML = '<span id="er_pass_text">••••••••</span>';
        ptEl.style.display = 'inline';
        noteEl.style.display = 'block';
    }

    bootstrap.Modal.getInstance(document.getElementById('editUserModal')).hide();
    setTimeout(() => new bootstrap.Modal(document.getElementById('editReviewModal')).show(), 300);
}

function toggleEditReviewPass() {
    _editReviewPassVisible = !_editReviewPassVisible;
    const el  = document.getElementById('er_pass_text');
    const eye = document.getElementById('er_eye');
    if (_editReviewPassVisible) {
        el.textContent = _editGeneratedPass;
        el.style.display = 'inline';
        eye.className = 'bi bi-eye-slash';
    } else {
        el.textContent = '••••••••';
        eye.className = 'bi bi-eye';
    }
}

function backToEditModal() {
    bootstrap.Modal.getInstance(document.getElementById('editReviewModal')).hide();
    setTimeout(() => new bootstrap.Modal(document.getElementById('editUserModal')).show(), 300);
}

// ── Archive Modal ──────────────────────────────────────────
function openArchiveModal(userId, userName) {
    document.getElementById('archiveUserId').value  = userId;
    document.getElementById('archiveUserName').textContent = userName;
    new bootstrap.Modal(document.getElementById('archiveUserModal')).show();
}
function submitArchive() {
    bootstrap.Modal.getInstance(document.getElementById('archiveUserModal')).hide();
    document.getElementById('archiveUserForm').submit();
}

// ── Block / Unblock Modal ──────────────────────────────────
function openBlockModal(userId, userName, action) {
    document.getElementById('blockUserId').value         = userId;
    document.getElementById('blockUserName').textContent = userName;
    const isBlock = action === 'block';
    document.getElementById('blockNewStatus').value = isBlock ? 'inactive' : 'active';

    document.getElementById('blockModalIconI').className = isBlock
        ? 'bi bi-slash-circle-fill text-white'
        : 'bi bi-check-circle-fill text-white';
    document.getElementById('blockModalTitle').textContent    = isBlock ? 'Block Access' : 'Unblock Access';
    document.getElementById('blockModalSubtitle').textContent = 'You are about to ' + (isBlock ? 'block:' : 'unblock:');
    document.getElementById('blockModalDesc').className       = 'alert py-2 small mb-0 ' + (isBlock ? 'alert-warning' : 'alert-success');
    document.getElementById('blockModalDesc').innerHTML       = isBlock
        ? '<i class="bi bi-exclamation-triangle me-1"></i>This will prevent the user from signing in. Their data will not be removed.'
        : '<i class="bi bi-check-circle me-1"></i>The user will be <strong>allowed to log in</strong> again immediately.';

    const btn = document.getElementById('blockConfirmBtn');
    btn.innerHTML = isBlock
        ? '<i class="bi bi-slash-circle me-1"></i>Yes, Block Access'
        : '<i class="bi bi-check-circle me-1"></i>Yes, Unblock Access';

    new bootstrap.Modal(document.getElementById('blockUserModal')).show();
}
function submitBlock() {
    bootstrap.Modal.getInstance(document.getElementById('blockUserModal')).hide();
    document.getElementById('blockUserForm').submit();
}
function openResetPassModal(userId, userName) {
    document.getElementById('rp_user_id').value = userId;
    document.getElementById('rp_subtitle').textContent = 'for ' + userName;
    // Reset to defaults
    document.getElementById('rpRandom').checked = true;
    toggleResetPassMode();
    document.getElementById('rp_password').value = '';
    document.getElementById('rp_password_confirm').value = '';
    document.getElementById('rpMatchMsg').textContent = '';
    new bootstrap.Modal(document.getElementById('resetPassModal')).show();
}

function toggleResetPassMode() {
    const isManual = document.getElementById('rpManual').checked;
    document.getElementById('rpRandomInfo').style.display  = isManual ? 'none'  : 'block';
    document.getElementById('rpManualFields').style.display = isManual ? 'block' : 'none';
    if (!isManual) {
        document.getElementById('rp_password').value = '';
        document.getElementById('rp_password_confirm').value = '';
        document.getElementById('rpMatchMsg').textContent = '';
    }
}

document.addEventListener('DOMContentLoaded', function () {
    ['rp_password','rp_password_confirm'].forEach(id => {
        document.getElementById(id).addEventListener('input', function () {
            const p1  = document.getElementById('rp_password').value;
            const p2  = document.getElementById('rp_password_confirm').value;
            const msg = document.getElementById('rpMatchMsg');
            if (!p2) { msg.textContent = ''; return; }
            if (p1 === p2) msg.innerHTML = '<span class="text-success"><i class="bi bi-check-circle me-1"></i>Passwords match</span>';
            else           msg.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>Passwords do not match</span>';
        });
    });
});

function submitResetPass() {
    const isManual = document.getElementById('rpManual').checked;
    if (isManual) {
        const p1 = document.getElementById('rp_password').value;
        const p2 = document.getElementById('rp_password_confirm').value;
        if (!p1 || !validateAlphanumericPass(p1)) {
            document.getElementById('rpMatchMsg').innerHTML = '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>Password must be at least 8 characters and contain both letters and numbers</span>';
            return;
        }
        if (p1 !== p2) { alert('Passwords do not match.'); return; }
        document.getElementById('rp_mode_hidden').value   = 'manual';
        document.getElementById('rp_manual_hidden').value = p1;
    } else {
        document.getElementById('rp_mode_hidden').value   = 'random';
        document.getElementById('rp_manual_hidden').value = '';
    }
    document.getElementById('resetPassForm').submit();
}

// ── Sidebar ────────────────────────────────────────────────
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('show'); }

// ── Contact: digits only, must match ^09\d{9}$ ────────────
function validateContact(input, errId) {
    // Block non-digit characters — strip them immediately
    input.value = input.value.replace(/[^0-9]/g, '');

    const val = input.value;
    const err = document.getElementById(errId);

    if (!val) {
        // Empty — clear error
        input.classList.remove('is-invalid', 'is-valid');
        err.style.display = 'none';
        err.textContent = '';
        return;
    }

    // Full pattern check: must be exactly 11 digits starting with 09
    if (/^09\d{9}$/.test(val)) {
        input.classList.remove('is-invalid');
        input.classList.add('is-valid');
        err.style.display = 'none';
    } else {
        input.classList.add('is-invalid');
        input.classList.remove('is-valid');
        err.style.display = 'block';

        if (!/^0/.test(val)) {
            err.innerHTML = '<i class="bi bi-x-circle me-1"></i>Must start with 09.';
        } else if (!/^09/.test(val)) {
            err.innerHTML = '<i class="bi bi-x-circle me-1"></i>Must start with 09.';
        } else if (val.length < 11) {
            err.innerHTML = '<i class="bi bi-x-circle me-1"></i>Must be exactly 11 digits (e.g. 09171234567). ' + (11 - val.length) + ' more digit(s) needed.';
        } else if (val.length > 11) {
            err.innerHTML = '<i class="bi bi-x-circle me-1"></i>Must be exactly 11 digits. Too many digits entered.';
        } else {
            err.innerHTML = '<i class="bi bi-x-circle me-1"></i>Invalid format. Must start with 09 and be 11 digits.';
        }
    }
}

// ── Duplicate name check ───────────────────────────────────
const existingFullNames = <?= json_encode(array_map(fn($user) => [
    'first_name' => strtolower(trim($user['first_name'] ?? '')),
    'middle_name' => strtolower(trim($user['middle_name'] ?? '')),
    'last_name' => strtolower(trim($user['last_name'] ?? ''))
], $existingFullNames), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

function checkDuplicateName() {
    const fn = document.getElementById('add_first_name').value.trim().toLowerCase();
    const mn = document.getElementById('add_middle_name').value.trim().toLowerCase();
    const ln = document.getElementById('add_last_name').value.trim().toLowerCase();
    const warn = document.getElementById('dupNameWarn');
    const nameInputs = [
        document.getElementById('add_first_name'),
        document.getElementById('add_middle_name'),
        document.getElementById('add_last_name')
    ];
    const isDup = !!fn && !!ln && existingFullNames.some(user =>
        user.first_name === fn && user.middle_name === mn && user.last_name === ln
    );
    warn.style.display = isDup ? 'block' : 'none';
    nameInputs.forEach(input => input.classList.toggle('is-invalid', isDup));
    return !isDup;
}

// ── Username availability check ────────────────────────────
const existingUsernames = <?= json_encode($existingUsernames, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

function checkUsernameAvailability() {
    const input = document.getElementById('add_username');
    const status = document.getElementById('usernameAvailability');
    const username = input.value.trim().toLowerCase();
    input.classList.remove('is-valid', 'is-invalid');

    if (!username) {
        status.style.display = 'none';
        status.textContent = '';
        return false;
    }

    const isTaken = existingUsernames.includes(username);
    status.className = isTaken ? 'mt-1 text-danger' : 'mt-1 text-success';
    status.style.display = 'block';
    status.innerHTML = isTaken
        ? '<i class="bi bi-exclamation-triangle-fill me-1"></i>This username is already taken. Please choose another.'
        : '<i class="bi bi-check-circle-fill me-1"></i>This username is available.';
    input.classList.add(isTaken ? 'is-invalid' : 'is-valid');
    return !isTaken;
}

// ── Password mode toggle ───────────────────────────────────
function togglePassMode() {
    const isManual = document.getElementById('passManual').checked;
    document.getElementById('randomPassInfo').style.display = isManual ? 'none' : 'block';
    document.getElementById('manualPassFields').style.display = isManual ? 'block' : 'none';
    if (!isManual) {
        document.getElementById('add_password').value = '';
        document.getElementById('add_password_confirm').value = '';
    }
}

// ── Show/hide password ─────────────────────────────────────
function togglePassVis(inputId, iconId) {
    const inp = document.getElementById(inputId);
    const ico = document.getElementById(iconId);
    if (inp.type === 'password') { inp.type = 'text'; ico.className = 'bi bi-eye-slash'; }
    else { inp.type = 'password'; ico.className = 'bi bi-eye'; }
}

// ── Password match check ───────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    ['add_password','add_password_confirm'].forEach(id => {
        document.getElementById(id).addEventListener('input', checkPassMatch);
    });
});
function checkPassMatch() {
    const p1 = document.getElementById('add_password').value;
    const p2 = document.getElementById('add_password_confirm').value;
    const msg = document.getElementById('passMatchMsg');
    if (!p2) { msg.textContent = ''; return; }
    if (p1 === p2) { msg.innerHTML = '<span class="text-success"><i class="bi bi-check-circle me-1"></i>Passwords match</span>'; }
    else { msg.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>Passwords do not match</span>'; }
}

// ── Suggest username ───────────────────────────────────────
function suggestUsername() {
    const fn = document.getElementById('add_first_name').value.trim().toLowerCase();
    const ln = document.getElementById('add_last_name').value.trim().toLowerCase().replace(/\s+/g,'');
    if (!fn || !ln) { alert('Enter first and last name first.'); return; }
    document.getElementById('add_username').value = fn[0] + ln + Math.floor(Math.random()*90+10);
    checkUsernameAvailability();
}

// ── Random password generator (client-side preview) ────────
function generateRandomPass() {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789@#$!';
    let pass = '';
    for (let i = 0; i < 10; i++) pass += chars[Math.floor(Math.random() * chars.length)];
    return pass;
}

// ── Review modal ───────────────────────────────────────────
let _generatedPass = '';
let _reviewPassVisible = false;

function openReviewModal() {
    // Validate required fields
    const fn = document.getElementById('add_first_name').value.trim();
    const ln = document.getElementById('add_last_name').value.trim();
    const un = document.getElementById('add_username').value.trim();
    const em = document.getElementById('add_email').value.trim();
    const isManual = document.getElementById('passManual').checked;

    if (!fn || !ln || !un || !em) {
        alert('Please fill in all required fields (First Name, Last Name, Username, Email).'); return;
    }
    if (!checkDuplicateName()) {
        document.getElementById('add_first_name').focus();
        return;
    }
    if (!checkUsernameAvailability()) {
        document.getElementById('add_username').focus();
        return;
    }
    // Contact digits check
    const ct = document.getElementById('add_contact').value.trim();
    if (ct && !/^09\d{9}$/.test(ct)) {
        document.getElementById('add_contact').classList.add('is-invalid');
        document.getElementById('add_contact_err').style.display = 'block';
        document.getElementById('add_contact_err').innerHTML = '<i class="bi bi-x-circle me-1"></i>Must be 11 digits starting with 09 (e.g. 09171234567).';
        document.getElementById('add_contact').focus();
        return;
    }
    if (isManual) {
        const p1 = document.getElementById('add_password').value;
        const p2 = document.getElementById('add_password_confirm').value;
        if (!p1 || !validateAlphanumericPass(p1)) {
            document.getElementById('passMatchMsg').innerHTML = '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>Password must be at least 8 characters and contain both letters and numbers</span>';
            return;
        }
        if (p1 !== p2) { alert('Passwords do not match.'); return; }
    }

    // Gather values
    const mn       = document.getElementById('add_middle_name').value.trim();
    const email    = em;
    const contact  = document.getElementById('add_contact').value.trim();
    const roleVal  = document.getElementById('add_role').value;
    const brgyEl   = document.getElementById('add_barangay');
    const brgyId   = brgyEl.value;
    const brgyName = brgyEl.options[brgyEl.selectedIndex].dataset.name || '— None —';
    const position = document.getElementById('add_position').value.trim();
    const passMode = isManual ? 'manual' : 'random';
    const password = isManual ? document.getElementById('add_password').value : generateRandomPass();

    _generatedPass = password;
    _reviewPassVisible = false;

    // Populate hidden form fields
    document.getElementById('r_first_name').value    = fn;
    document.getElementById('r_middle_name').value   = mn;
    document.getElementById('r_last_name').value     = ln;
    document.getElementById('r_email').value         = email;
    document.getElementById('r_contact').value       = contact;
    document.getElementById('r_role').value          = roleVal;
    document.getElementById('r_barangay_id').value   = brgyId;
    document.getElementById('r_position').value      = position;
    document.getElementById('r_username').value      = un;
    document.getElementById('r_password_mode').value = passMode;
    document.getElementById('r_manual_password').value = password;
    // Pass barangay address fields (always, regardless of role)
    document.getElementById('r_brgy_region').value      = document.getElementById('add_brgy_region').value.trim();
    document.getElementById('r_brgy_province').value    = document.getElementById('add_brgy_province').value.trim();
    document.getElementById('r_brgy_municipality').value= document.getElementById('add_brgy_city').value.trim();
    document.getElementById('r_brgy_zip_code').value    = document.getElementById('add_brgy_zip').value.trim();

    // Populate review display
    const fullName = [fn, mn, ln].filter(Boolean).join(' ');
    document.getElementById('rv_fullname').textContent  = fullName;
    document.getElementById('rv_username').textContent  = un;
    document.getElementById('rv_email').textContent     = email;
    document.getElementById('rv_role').textContent      = roleVal === 'super_admin' ? 'Super Admin' : 'Barangay Admin';
    document.getElementById('rv_barangay').textContent  = brgyName;
    document.getElementById('rv_position').textContent  = position || '—';

    // Password display
    const pdEl = document.getElementById('rv_password_display');
    const ptEl = document.getElementById('rv_pass_toggle');
    if (passMode === 'random') {
        pdEl.innerHTML = '<span class="badge bg-info text-dark">Auto-generated</span> <span id="rv_pass_text" style="display:none;">' + password + '</span>';
        ptEl.style.display = 'inline';
    } else {
        pdEl.innerHTML = '<span id="rv_pass_text">••••••••</span>';
        ptEl.style.display = 'inline';
    }

    // Hide add modal, show review modal
    bootstrap.Modal.getInstance(document.getElementById('addUserModal')).hide();
    setTimeout(() => new bootstrap.Modal(document.getElementById('reviewUserModal')).show(), 300);
}

function toggleReviewPass() {
    _reviewPassVisible = !_reviewPassVisible;
    const el = document.getElementById('rv_pass_text');
    const eye = document.getElementById('rv_eye');
    if (_reviewPassVisible) {
        el.textContent = _generatedPass;
        el.style.display = 'inline';
        eye.className = 'bi bi-eye-slash';
    } else {
        el.textContent = '••••••••';
        eye.className = 'bi bi-eye';
    }
}

function backToAddModal() {
    bootstrap.Modal.getInstance(document.getElementById('reviewUserModal')).hide();
    setTimeout(() => new bootstrap.Modal(document.getElementById('addUserModal')).show(), 300);
}

// ── Barangay custom dropdown ──────────────────────────────
function showBrgyList() {
    document.getElementById('brgyListBox').style.display = 'block';
    filterBrgyList(document.getElementById('brgySearch').value);
}
function hideBrgyList() {
    document.getElementById('brgyListBox').style.display = 'none';
}
function filterBrgyList(q) {
    const items = document.querySelectorAll('#brgyListBox .brgy-option');
    const lq = q.toLowerCase();
    items.forEach(item => {
        const name = (item.dataset.name || item.textContent).toLowerCase();
        item.style.display = name.includes(lq) ? 'block' : 'none';
    });
    document.getElementById('brgyListBox').style.display = 'block';
}
function selectBrgy(id, municipality, name, region, province, zip) {
    document.getElementById('add_barangay').value          = id;
    document.getElementById('brgySearch').value            = id ? name : '';
    document.getElementById('add_brgy_city').value         = municipality;
    document.getElementById('add_brgy_name_display').value = id ? name : '';
    // Auto-fill address fields from barangay record if available
    if (region)   document.getElementById('add_brgy_region').value   = region;
    if (province) document.getElementById('add_brgy_province').value = province;
    if (zip)      document.getElementById('add_brgy_zip').value      = zip;
    hideBrgyList();
}
// Close list when clicking outside
document.addEventListener('click', function(e) {
    const wrap = document.getElementById('brgyDropdownWrap');
    if (wrap && !wrap.contains(e.target)) hideBrgyList();
});

// ── Barangay address section toggle ───────────────────────
document.addEventListener('DOMContentLoaded', function () {
    const roleSelect = document.getElementById('add_role');
    const brgySelect = document.getElementById('add_barangay');
    const brgySection = document.getElementById('brgyAddressSection');

    function toggleBrgySection() {
        // Always show address section for all roles
        brgySection.style.display = 'block';
    }
    roleSelect.addEventListener('change', toggleBrgySection);
    toggleBrgySection();

    // Auto-fill address fields from barangay selection (hidden select)
    brgySelect.addEventListener('change', function () {
        const opt = brgySelect.options[brgySelect.selectedIndex];
        document.getElementById('add_brgy_city').value         = opt.dataset.municipality || '';
        document.getElementById('add_brgy_name_display').value = opt.dataset.name         || '';
        if (opt.dataset.region)   document.getElementById('add_brgy_region').value   = opt.dataset.region;
        if (opt.dataset.province) document.getElementById('add_brgy_province').value = opt.dataset.province;
        if (opt.dataset.zip)      document.getElementById('add_brgy_zip').value      = opt.dataset.zip;
    });
});

// ── Password alphanumeric validation ──────────────────────
function validateAlphanumericPass(pass) {
    return pass.length >= 8 && /[a-zA-Z]/.test(pass) && /[0-9]/.test(pass);
}
function showPassStrengthError(msgElId) {
    const el = document.getElementById(msgElId);
    if (el) {
        el.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>Password must be at least 8 characters and contain both letters and numbers</span>';
    }
}

// ── User Drawer ────────────────────────────────────────────
let _drawerUser = null;

function openUserDrawer(userJson) {
    const u = typeof userJson === 'string' ? JSON.parse(userJson) : userJson;
    _drawerUser = u;

    const fullName = [u.first_name, u.middle_name, u.last_name].filter(Boolean).join(' ');
    const initials = (u.first_name ? u.first_name[0] : '') + (u.last_name ? u.last_name[0] : '');

    // Header
    document.getElementById('drawerAvatarInitials').textContent = initials.toUpperCase();
    const avatarImg = document.getElementById('drawerAvatarImg');
    if (u.profile_photo) {
        avatarImg.src     = '<?= UPLOAD_URL ?>profile_photos/' + u.profile_photo;
        avatarImg.style.display = 'block';
        document.getElementById('drawerAvatarInitials').style.display = 'none';
    } else {
        avatarImg.style.display = 'none';
        document.getElementById('drawerAvatarInitials').style.display = '';
    }
    document.getElementById('drawerName').textContent   = fullName;
    document.getElementById('drawerEmail').textContent  = u.email || '';

    const roleBadge = document.getElementById('drawerRoleBadge');
    roleBadge.textContent = u.role === 'super_admin' ? 'System Administrator' : 'Barangay Admin';

    const statusBadge = document.getElementById('drawerStatusBadge');
    if (u.status === 'active' && !parseInt(u.login_locked || 0)) {
        statusBadge.textContent = 'Active';
        statusBadge.style.background = 'rgba(16,185,129,0.25)';
        statusBadge.style.color = '#d1fae5';
    } else if (parseInt(u.login_locked || 0)) {
        statusBadge.innerHTML = '<i class="bi bi-lock-fill me-1"></i>Login Locked';
        statusBadge.style.background = 'rgba(194,65,12,0.3)';
        statusBadge.style.color = '#fed7aa';
    } else {
        statusBadge.innerHTML = '<i class="bi bi-slash-circle me-1"></i>Blocked';
        statusBadge.style.background = 'rgba(220,38,38,0.3)';
        statusBadge.style.color = '#fecaca';
    }

    // Body details
    document.getElementById('drawerUsername').textContent  = u.username || '—';
    document.getElementById('drawerBarangay').textContent  = u.barangay_name || '—';
    document.getElementById('drawerPosition').textContent  = u.position || '—';
    document.getElementById('drawerContact').textContent   = u.contact_number || '—';

    // Block/Unblock button
    const isActive = u.status === 'active';
    const blockBtn  = document.getElementById('drawerBlockBtn');
    const blockIcon = document.getElementById('drawerBlockIcon');
    const blockText = document.getElementById('drawerBlockText');
    if (isActive) {
        blockBtn.className  = 'btn btn-secondary w-100 text-start';
        blockIcon.className = 'bi bi-slash-circle me-2';
        blockText.textContent = 'Block Access';
    } else {
        blockBtn.className  = 'btn btn-success w-100 text-start';
        blockIcon.className = 'bi bi-check-circle me-2';
        blockText.textContent = 'Unblock Access';
    }

    // Blocked user banner
    const blockedBanner      = document.getElementById('drawerBlockedBanner');
    const loginLockedBanner  = document.getElementById('drawerLoginLockedBanner');
    const unlockLoginBtn     = document.getElementById('drawerUnlockLoginBtn');
    const isLoginLocked      = parseInt(u.login_locked || 0) === 1;

    blockedBanner.style.display     = (!isActive && !isLoginLocked) ? 'block' : 'none';
    loginLockedBanner.style.display = isLoginLocked ? 'block' : 'none';
    unlockLoginBtn.style.display    = isLoginLocked ? 'block' : 'none';

    // Manage Profile button — only for superadmins
    const manageProfileBtn = document.getElementById('drawerManageProfileBtn');
    const isSuperAdmin     = u.role === 'super_admin';
    manageProfileBtn.style.display = isSuperAdmin ? '' : 'none';
    if (isSuperAdmin) {
        const currentUserId = <?= (int)$u['id'] ?>;
        if (parseInt(u.id) === currentUserId) {
            // Own profile — go to Settings > Profile tab
            manageProfileBtn.onclick = function () {
                closeUserDrawer();
                window.location.href = '<?= BASE_URL ?>/super_admin/settings.php?tab=profile';
            };
        } else {
            // Another SA — open their profile in a modal (edit user)
            manageProfileBtn.onclick = function () {
                closeUserDrawer();
                setTimeout(() => editUser(u), 350);
            };
        }
    }

    // Wire action buttons
    document.getElementById('drawerEditBtn').onclick = function () {
        closeUserDrawer();
        setTimeout(() => editUser(u), 350);
    };
    document.getElementById('drawerResetBtn').onclick = function () {
        closeUserDrawer();
        setTimeout(() => openResetPassModal(u.id, fullName), 350);
    };
    blockBtn.onclick = function () {
        closeUserDrawer();
        setTimeout(() => openBlockModal(u.id, fullName, isActive ? 'block' : 'unblock'), 350);
    };
    unlockLoginBtn.onclick = function () {
        closeUserDrawer();
        setTimeout(() => {
            if (confirm('Unlock login access for ' + fullName + '?\n\nThis will reset their failed-attempt counter and allow them to log in again.')) {
                document.getElementById('unlockLoginUserId').value = u.id;
                document.getElementById('unlockLoginForm').submit();
            }
        }, 350);
    };
    document.getElementById('drawerArchiveBtn').onclick = function () {
        closeUserDrawer();
        setTimeout(() => openArchiveModal(u.id, fullName), 350);
    };
    // Open drawer
    document.getElementById('userDrawer').classList.add('open');
    document.getElementById('drawerBackdrop').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeUserDrawer() {
    document.getElementById('userDrawer').classList.remove('open');
    document.getElementById('drawerBackdrop').classList.remove('show');
    document.body.style.overflow = '';
}

// ── Approve unlock request (from banner) ─────────────────
function approveUnlockRequest(requestId, btn) {
    if (!confirm('Approve the unlock request and restore login access for this administrator?')) return;
    btn.disabled  = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Approving…';

    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    fetch('<?= BASE_URL ?>/ajax/superadmin_recovery.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ sub_action: 'approve_unlock', request_id: requestId, csrf_token: csrf })
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            btn.closest('.alert').innerHTML =
                '<i class="bi bi-check-circle-fill text-success me-2"></i>' +
                '<span class="text-success fw-semibold">' + data.msg + '</span>';
            btn.closest('.alert').classList.remove('alert-warning');
            btn.closest('.alert').classList.add('alert-success');
            // Refresh the user list after 1.5 s
            setTimeout(() => location.reload(), 1500);
        } else {
            btn.disabled  = false;
            btn.innerHTML = '<i class="bi bi-unlock-fill me-1"></i> Approve Unlock';
            alert('Error: ' + data.msg);
        }
    })
    .catch(() => {
        btn.disabled  = false;
        btn.innerHTML = '<i class="bi bi-unlock-fill me-1"></i> Approve Unlock';
        alert('Something went wrong. Please try again.');
    });
}
</script>

<!-- User Drawer Backdrop -->
<div class="drawer-backdrop" id="drawerBackdrop" onclick="closeUserDrawer()"></div>

<!-- User Drawer -->
<div class="user-drawer" id="userDrawer">
    <!-- Drawer Header -->
    <div style="background:linear-gradient(135deg,#1a4f7a,#3a7fa8);padding:20px 20px 16px;flex-shrink:0;">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <div id="drawerAvatar" style="width:52px;height:52px;border-radius:50%;background:rgba(255,255,255,0.2);display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.2rem;font-weight:700;border:2px solid rgba(255,255,255,0.4);overflow:hidden;position:relative;">
                <img id="drawerAvatarImg" src="" alt="" style="display:none;width:100%;height:100%;object-fit:cover;position:absolute;top:0;left:0;">
                <span id="drawerAvatarInitials" style="position:relative;z-index:1;"></span>
            </div>
            <button onclick="closeUserDrawer()" style="background:rgba(255,255,255,0.15);border:none;border-radius:8px;width:32px;height:32px;color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div id="drawerName" style="color:#fff;font-size:1rem;font-weight:700;margin-bottom:4px;"></div>
        <div id="drawerEmail" style="color:rgba(255,255,255,0.7);font-size:0.8rem;margin-bottom:8px;"></div>
        <div class="d-flex gap-2 flex-wrap">
            <span id="drawerRoleBadge" style="font-size:0.72rem;padding:3px 10px;border-radius:20px;background:rgba(255,255,255,0.2);color:#fff;"></span>
            <span id="drawerStatusBadge" style="font-size:0.72rem;padding:3px 10px;border-radius:20px;"></span>
        </div>
    </div>

    <!-- Drawer Body -->
    <div style="flex:1;overflow-y:auto;padding:20px;">
        <!-- Blocked User Banner (manual block) -->
        <div id="drawerBlockedBanner" style="display:none;background:#fff0f0;border:1px solid #fca5a5;border-radius:10px;padding:10px 14px;margin-bottom:14px;">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-slash-circle-fill text-danger"></i>
                <div>
                    <div style="font-size:0.82rem;font-weight:700;color:#b91c1c;">Access Blocked</div>
                    <div style="font-size:0.75rem;color:#c62828;">This user cannot log in. Click <strong>Unblock Access</strong> below to restore access.</div>
                </div>
            </div>
        </div>
        <!-- Login-Locked Banner (failed attempts) -->
        <div id="drawerLoginLockedBanner" style="display:none;background:#fff7ed;border:1px solid #fdba74;border-radius:10px;padding:10px 14px;margin-bottom:14px;">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-lock-fill" style="color:#c2410c;font-size:1.1rem;flex-shrink:0;"></i>
                <div>
                    <div style="font-size:0.82rem;font-weight:700;color:#c2410c;">Login Locked — Too Many Failed Attempts</div>
                    <div style="font-size:0.75rem;color:#92400e;">Login is permanently disabled until you click <strong>Unlock Login</strong> below.</div>
                </div>
            </div>
        </div>
        <!-- User Details -->
        <div style="background:#f8fafc;border-radius:12px;padding:14px;margin-bottom:16px;">
            <div class="small fw-semibold text-muted mb-2" style="text-transform:uppercase;letter-spacing:0.05em;font-size:0.68rem;">User Details</div>
            <div class="d-flex flex-column gap-2" style="font-size:0.85rem;">
                <div class="d-flex justify-content-between">
                    <span class="text-muted"><i class="bi bi-person me-2"></i>Username</span>
                    <code id="drawerUsername" style="font-size:0.82rem;"></code>
                </div>
                <div class="d-flex justify-content-between">
                    <span class="text-muted"><i class="bi bi-geo-alt me-2"></i>Barangay</span>
                    <span id="drawerBarangay" class="fw-semibold text-end" style="max-width:180px;"></span>
                </div>
                <div class="d-flex justify-content-between">
                    <span class="text-muted"><i class="bi bi-briefcase me-2"></i>Position</span>
                    <span id="drawerPosition" class="fw-semibold text-end" style="max-width:180px;"></span>
                </div>
                <div class="d-flex justify-content-between">
                    <span class="text-muted"><i class="bi bi-phone me-2"></i>Contact</span>
                    <span id="drawerContact" class="fw-semibold"></span>
                </div>
            </div>
        </div>

        <!-- Actions -->
        <div class="small fw-semibold text-muted mb-2" style="text-transform:uppercase;letter-spacing:0.05em;font-size:0.68rem;">Actions</div>
        <div class="d-flex flex-column gap-2">
            <button class="btn w-100 text-start fw-semibold" id="drawerManageProfileBtn"
                    style="display:none;background:linear-gradient(135deg,#1a4f7a,#2d6a9f);color:#fff;border:none;">
                <i class="bi bi-person-gear me-2"></i>Manage Profile
            </button>
            <button class="btn btn-primary w-100 text-start" id="drawerEditBtn">
                <i class="bi bi-pencil-square me-2"></i>Edit User
            </button>
            <button class="btn btn-warning w-100 text-start" id="drawerResetBtn">
                <i class="bi bi-key-fill me-2"></i>Reset Password
            </button>
            <button class="btn w-100 text-start" id="drawerBlockBtn">
                <i class="bi bi-slash-circle me-2" id="drawerBlockIcon"></i>
                <span id="drawerBlockText"></span>
            </button>
            <button class="btn btn-warning w-100 text-start fw-semibold" id="drawerUnlockLoginBtn" style="display:none;">
                <i class="bi bi-lock-fill me-2"></i>Unlock Login
            </button>
            <button class="btn btn-outline-danger w-100 text-start" id="drawerArchiveBtn">
                <i class="bi bi-archive me-2"></i>Archive User
            </button>
        </div>
    </div>
</div>
</body>
</html>
