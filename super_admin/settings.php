
<?php
require_once __DIR__ . '/../config/config.php';
requireRole('super_admin');

$db = Database::getInstance()->getConnection();
$u  = currentUser();

// ── Safe migrations ────────────────────────────────────────
try { $db->exec("CREATE TABLE IF NOT EXISTS system_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_val TEXT,
    updated_by INT UNSIGNED NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB"); } catch (PDOException $e) {}

try { $db->exec("CREATE TABLE IF NOT EXISTS landing_content (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    welcome_title VARCHAR(200) DEFAULT 'Efficient. Secure. Centralized.',
    welcome_subtitle VARCHAR(200) DEFAULT 'Data Management for Local Governance',
    welcome_desc TEXT,
    about_text TEXT,
    contact_phone VARCHAR(30),
    contact_email VARCHAR(100),
    contact_address VARCHAR(255),
    logo1_path VARCHAR(255),
    logo2_path VARCHAR(255),
    banner_path VARCHAR(255),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB"); } catch (PDOException $e) {}

try { $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS login_attempts TINYINT UNSIGNED DEFAULT 0"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS locked_until DATETIME NULL"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS profile_photo VARCHAR(255) NULL"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE barangays ADD COLUMN IF NOT EXISTS region VARCHAR(100) NULL"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE barangays ADD COLUMN IF NOT EXISTS province VARCHAR(100) NULL"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE barangays ADD COLUMN IF NOT EXISTS zip_code VARCHAR(10) NULL"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE barangays ADD COLUMN IF NOT EXISTS logo_path VARCHAR(255) NULL"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE officials ADD COLUMN IF NOT EXISTS photo VARCHAR(255) NULL"); } catch (PDOException $e) {}

// Seed official_term_years
$db->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_val) VALUES ('official_term_years','3')")->execute();

// Seed defaults
$defaults = [
    'password_min_length'     => '8',
    'password_require_upper'  => '1',
    'password_require_number' => '1',
    'password_require_special'=> '0',
    'login_max_attempts'      => '5',
    'login_lockout_minutes'   => '15',
    'session_timeout_minutes' => '60',
    'idle_timeout_enabled'    => '1',
    'idle_timeout_minutes'    => '5',
    'idle_timeout_unit'       => 'minutes',
    'notif_request_approved'  => '1',
    'notif_request_rejected'  => '1',
    'notif_request_done'      => '1',
    'notif_system_alerts'     => '1',
    'notif_email_enabled'     => '0',
    'archive_auto_enabled'    => '0',
    'archive_after_months'    => '3',
    'archive_users'           => '1',
    'archive_requests'        => '1',
    'archive_notices'         => '1',
];
foreach ($defaults as $k => $v) {
    $db->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_val) VALUES (?,?)")->execute([$k, $v]);
}
// Seed landing content row if not present
try {
    $lcCheck = $db->query("SELECT COUNT(*) FROM landing_content WHERE id=1")->fetchColumn();
    if (!$lcCheck) {
        $db->exec("INSERT INTO landing_content
            (id, welcome_title, welcome_subtitle, welcome_desc, about_text, contact_phone, contact_email, contact_address)
            VALUES (1,
            'Efficient. Secure. Centralized.',
            'Data Management for Local Governance',
            'Empowering local government units with modern technology for better service delivery, transparent governance, and efficient community management.',
            'The Centralized LGU Information Management System is a digital platform designed to streamline barangay operations, resident records, and government services in Talavera, Nueva Ecija.',
            '',
            '',
            'Talavera, Nueva Ecija, Philippines')");
    }
} catch (PDOException $e) {}

// ── Helper: get setting ────────────────────────────────────
function getSetting(PDO $db, string $key, string $default = ''): string {
    $s = $db->prepare("SELECT setting_val FROM system_settings WHERE setting_key=?");
    $s->execute([$key]);
    $r = $s->fetchColumn();
    return $r !== false ? $r : $default;
}

function saveSetting(PDO $db, string $key, string $val, int $userId): void {
    $db->prepare("INSERT INTO system_settings (setting_key, setting_val, updated_by)
        VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_val=VALUES(setting_val), updated_by=VALUES(updated_by)")
       ->execute([$key, $val, $userId]);
}

// ── Active tab ─────────────────────────────────────────────
$tab = $_GET['tab'] ?? 'general';
$validTabs = ['general','profile','security','notifications','archive','appearance','authenticator'];
if (!in_array($tab, $validTabs)) $tab = 'general';
$autoOpenChangePw = isset($_GET['action']) && $_GET['action'] === 'change_pw';
$isAjax  = !empty($_GET['ajax'])  && $_GET['ajax']  === '1' && strtoupper($_SERVER['REQUEST_METHOD']) === 'GET';



// ── 2FA migrations ─────────────────────────────────────────
try { $db->exec("ALTER TABLE users ADD COLUMN totp_secret VARCHAR(64) NULL"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE users ADD COLUMN two_fa_enabled TINYINT(1) DEFAULT 0"); } catch (PDOException $e) {}

// ── Fetch fresh self row (needed for 2FA) ──────────────────
$selfRow = $db->prepare("SELECT * FROM users WHERE id=?");
$selfRow->execute([$u['id']]);
$selfRow = $selfRow->fetch();

// ── POST Handlers ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrAbort();
    $action = $_POST['action'] ?? '';

    // ── 2FA: Generate secret ───────────────────────────────
    if ($action === 'generate_2fa') {
        require_once BASE_PATH . '/helpers/totp.php';
        $secret = TOTP::generateSecret();
        $db->prepare("UPDATE users SET totp_secret=? WHERE id=?")->execute([$secret, $u['id']]);
        flash('settings', 'Scan the QR code with your authenticator app, then verify to enable 2FA.', 'info');
        redirect(BASE_URL . '/super_admin/settings.php?tab=authenticator');
    }

    // ── 2FA: Enable (verify code) ──────────────────────────
    if ($action === 'enable_2fa') {
        require_once BASE_PATH . '/helpers/totp.php';
        $code   = trim($_POST['code'] ?? '');
        $secret = $selfRow['totp_secret'] ?? '';
        if (!$secret) {
            flash('settings', 'No secret found. Please generate a QR code first.', 'danger');
        } elseif (!TOTP::verify($secret, $code)) {
            flash('settings', 'Invalid code. Make sure your authenticator app is synced and try again.', 'danger');
        } else {
            $db->prepare("UPDATE users SET two_fa_enabled=1 WHERE id=?")->execute([$u['id']]);
            logActivity($u['id'], 'ENABLE_2FA', 'Security', '2FA enabled');
            flash('settings', '✅ Two-Factor Authentication has been enabled on your account.', 'success');
        }
        redirect(BASE_URL . '/super_admin/settings.php?tab=authenticator');
    }

    // ── 2FA: Disable ──────────────────────────────────────
    if ($action === 'disable_2fa') {
        $db->prepare("UPDATE users SET two_fa_enabled=0, totp_secret=NULL WHERE id=?")->execute([$u['id']]);
        logActivity($u['id'], 'DISABLE_2FA', 'Security', '2FA disabled');
        flash('settings', '2FA has been disabled on your account.', 'warning');
        redirect(BASE_URL . '/super_admin/settings.php?tab=authenticator');
    }

    // ── General (Landing Content) ──────────────────────────
    if ($action === 'save_general') {
        $wTitle    = sanitize($_POST['welcome_title']    ?? '');
        $wSubtitle = sanitize($_POST['welcome_subtitle'] ?? '');
        $wDesc     = sanitize($_POST['welcome_desc']     ?? '');
        $aboutTxt  = sanitize($_POST['about_text']       ?? '');
        $cPhone    = sanitize($_POST['contact_phone']    ?? '');
        $cEmail    = sanitize($_POST['contact_email']    ?? '');
        $cAddress  = sanitize($_POST['contact_address']  ?? '');

        $db->prepare("INSERT INTO landing_content
            (id, welcome_title, welcome_subtitle, welcome_desc, about_text, contact_phone, contact_email, contact_address)
            VALUES (1,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
                welcome_title=VALUES(welcome_title),
                welcome_subtitle=VALUES(welcome_subtitle),
                welcome_desc=VALUES(welcome_desc),
                about_text=VALUES(about_text),
                contact_phone=VALUES(contact_phone),
                contact_email=VALUES(contact_email),
                contact_address=VALUES(contact_address)")
           ->execute([$wTitle, $wSubtitle, $wDesc, $aboutTxt, $cPhone, $cEmail, $cAddress]);

        // Handle logo1 upload
        if (!empty($_FILES['logo1']['name'])) {
            $ext  = strtolower(pathinfo($_FILES['logo1']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','gif','webp'];
            if (in_array($ext, $allowed)) {
                $fname = 'logo1_' . time() . '.' . $ext;
                move_uploaded_file($_FILES['logo1']['tmp_name'], UPLOAD_PATH . $fname);
                $db->prepare("UPDATE landing_content SET logo1_path=? WHERE id=1")->execute([$fname]);
            }
        }
        // Handle logo2 upload
        if (!empty($_FILES['logo2']['name'])) {
            $ext  = strtolower(pathinfo($_FILES['logo2']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','gif','webp'];
            if (in_array($ext, $allowed)) {
                $fname = 'logo2_' . time() . '.' . $ext;
                move_uploaded_file($_FILES['logo2']['tmp_name'], UPLOAD_PATH . $fname);
                $db->prepare("UPDATE landing_content SET logo2_path=? WHERE id=1")->execute([$fname]);
            }
        }
        // Handle banner upload
        if (!empty($_FILES['banner']['name'])) {
            $ext  = strtolower(pathinfo($_FILES['banner']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','gif','webp'];
            if (in_array($ext, $allowed)) {
                $finfo     = finfo_open(FILEINFO_MIME_TYPE);
                $bannerMime = finfo_file($finfo, $_FILES['banner']['tmp_name']);
                finfo_close($finfo);
                $baseName  = 'banner_' . time();
                // Resize banner to max 1400×600px and save as WebP for efficient delivery
                try {
                    $fname = resizeAndSaveImage($_FILES['banner']['tmp_name'], $bannerMime, UPLOAD_PATH, $baseName, 1400, 600, 82);
                    $db->prepare("UPDATE landing_content SET banner_path=? WHERE id=1")->execute([$fname]);
                } catch (\Throwable $e) {
                    // Fall back to raw move if GD fails
                    $fname = $baseName . '.' . $ext;
                    move_uploaded_file($_FILES['banner']['tmp_name'], UPLOAD_PATH . $fname);
                    $db->prepare("UPDATE landing_content SET banner_path=? WHERE id=1")->execute([$fname]);
                }
            }
        }
        // Handle banner removal
        if (($_POST['remove_banner'] ?? '0') === '1') {
            $db->prepare("UPDATE landing_content SET banner_path=NULL WHERE id=1")->execute();
        }

        logActivity($u['id'], 'UPDATE_SETTINGS', 'Settings', 'Updated General / Landing Content');
        flash('settings', 'Landing page content updated successfully.', 'success');
        redirect(BASE_URL . '/super_admin/settings.php?tab=general');
    }

    // ── Profile ────────────────────────────────────────────
    if ($action === 'save_profile') {
        $fname   = sanitize($_POST['first_name']  ?? '');
        $lname   = sanitize($_POST['last_name']   ?? '');
        $mname   = sanitize($_POST['middle_name'] ?? '');
        $email   = sanitize($_POST['email']       ?? '');
        $contact = sanitize($_POST['contact']     ?? '');
        $pos     = sanitize($_POST['position']    ?? '');

        if ($contact && !preg_match('/^09\d{9}$/', $contact)) {
            flash('settings', 'Contact number must be 11 digits starting with 09.', 'danger');
            redirect(BASE_URL . '/super_admin/settings.php?tab=profile');
        }

        $photoDir = UPLOAD_PATH . 'profile_photos/';
        $photoStmt = $db->prepare("SELECT profile_photo FROM users WHERE id=?");
        $photoStmt->execute([$u['id']]);
        $oldPhoto = $photoStmt->fetchColumn() ?: null;
        $profilePhoto = ($_POST['remove_profile_photo'] ?? '0') === '1' ? null : $oldPhoto;
        $newPhotoPath = null;
        $upload = $_FILES['profile_photo'] ?? null;

        if ($upload && $upload['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($upload['error'] !== UPLOAD_ERR_OK) {
                $uploadErrors = [
                    UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit.',
                    UPLOAD_ERR_FORM_SIZE  => 'File exceeds form upload limit.',
                    UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
                    UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
                    UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                ];
                flash('settings', $uploadErrors[$upload['error']] ?? 'The photo upload failed.', 'danger');
                redirect(BASE_URL . '/super_admin/settings.php?tab=profile');
            }
            if ($upload['size'] > 2 * 1024 * 1024) {
                flash('settings', 'File too large. Maximum allowed size is 2 MB.', 'danger');
                redirect(BASE_URL . '/super_admin/settings.php?tab=profile');
            }
            if (!is_uploaded_file($upload['tmp_name'])) {
                flash('settings', 'The selected photo was not received. Please choose it again.', 'danger');
                redirect(BASE_URL . '/super_admin/settings.php?tab=profile');
            }

            $extension = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
            $mimeExtensions = [
                'image/jpeg' => ['jpg', 'jpeg', 'jfif'],
                'image/png'  => ['png'],
                'image/gif'  => ['gif'],
                'image/webp' => ['webp'],
            ];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = $finfo ? finfo_file($finfo, $upload['tmp_name']) : false;
            if ($finfo) finfo_close($finfo);
            $imageInfo = @getimagesize($upload['tmp_name']);
            if (!is_string($mime) || !isset($mimeExtensions[$mime])
                || !in_array($extension, $mimeExtensions[$mime], true)
                || !$imageInfo || ($imageInfo['mime'] ?? '') !== $mime) {
                flash('settings', 'Invalid image. Please use a JPG, JFIF, PNG, GIF, or WEBP file.', 'danger');
                redirect(BASE_URL . '/super_admin/settings.php?tab=profile');
            }

            if (!is_dir($photoDir) && !mkdir($photoDir, 0755, true) && !is_dir($photoDir)) {
                flash('settings', 'Could not create the profile photo folder.', 'danger');
                redirect(BASE_URL . '/super_admin/settings.php?tab=profile');
            }
            $safeExtension = $mime === 'image/jpeg' ? 'jpg' : substr($mime, 6);
            $filename = 'user_' . (int)$u['id'] . '_' . bin2hex(random_bytes(8)) . '.' . $safeExtension;
            $newPhotoPath = $photoDir . $filename;
            if (!move_uploaded_file($upload['tmp_name'], $newPhotoPath)) {
                flash('settings', 'Could not save the selected photo. Check the upload folder permissions and try again.', 'danger');
                redirect(BASE_URL . '/super_admin/settings.php?tab=profile');
            }
            $profilePhoto = $filename;
        }

        try {
            $db->beginTransaction();
            $db->prepare("UPDATE users SET first_name=?,middle_name=?,last_name=?,email=?,contact_number=?,position=?,profile_photo=? WHERE id=?")
               ->execute([$fname, $mname, $lname, $email, $contact, $pos, $profilePhoto, $u['id']]);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            if ($newPhotoPath && is_file($newPhotoPath)) unlink($newPhotoPath);
            error_log('[Profile save] ' . $e->getMessage());
            flash('settings', 'Could not save your profile. Please try again.', 'danger');
            redirect(BASE_URL . '/super_admin/settings.php?tab=profile');
        }

        if ($oldPhoto && $oldPhoto !== $profilePhoto && basename($oldPhoto) === $oldPhoto
            && is_file($photoDir . $oldPhoto) && !unlink($photoDir . $oldPhoto)) {
            error_log('[Profile photo] Could not remove replaced photo file: ' . $photoDir . $oldPhoto);
        }
        $_SESSION['user_name']  = "$fname $lname";
        $_SESSION['user_email'] = $email;
        $_SESSION['user_photo'] = $profilePhoto;

        logActivity($u['id'], 'UPDATE_PROFILE', 'Settings', 'Updated own profile');
        flash('settings', 'Profile updated successfully.', 'success');
        redirect(BASE_URL . '/super_admin/settings.php?tab=profile');
    }

    // ── Security ───────────────────────────────────────────
    if ($action === 'save_security') {
        $keys = ['password_min_length','password_require_upper','password_require_number',
                 'password_require_special','login_max_attempts','login_lockout_minutes'];
        foreach ($keys as $k) {
            $val = isset($_POST[$k]) ? (string)(int)$_POST[$k] : '0';
            // Clamp sensible ranges
            if ($k === 'password_min_length')     $val = (string)max(6, min(32, (int)$val));
            if ($k === 'login_max_attempts')       $val = (string)max(1, min(20, (int)$val));
            if ($k === 'login_lockout_minutes')    $val = (string)max(1, min(1440, (int)$val));
            saveSetting($db, $k, $val, $u['id']);
        }
        // Idle auto-logout settings
        saveSetting($db, 'idle_timeout_enabled', isset($_POST['idle_timeout_enabled']) ? '1' : '0', $u['id']);
        $idleUnit = in_array($_POST['idle_timeout_unit'] ?? '', ['seconds','minutes'])
                        ? $_POST['idle_timeout_unit'] : 'minutes';
        $idleVal  = (int)($_POST['idle_timeout_minutes'] ?? 5);
        if ($idleUnit === 'seconds') {
            $idleVal = max(10, min(300, $idleVal));
        } else {
            $idleVal = max(1,  min(120, $idleVal));
        }
        saveSetting($db, 'idle_timeout_minutes', (string)$idleVal,  $u['id']);
        saveSetting($db, 'idle_timeout_unit',    $idleUnit,          $u['id']);

        logActivity($u['id'], 'UPDATE_SETTINGS', 'Settings', 'Updated Security settings');
        flash('settings', 'Security settings saved.', 'success');
        redirect(BASE_URL . '/super_admin/settings.php?tab=security');
    }

    // ── Notifications ──────────────────────────────────────
    if ($action === 'save_notifications') {
        $keys = ['notif_request_approved','notif_request_rejected','notif_request_done',
                 'notif_system_alerts','notif_email_enabled'];
        foreach ($keys as $k) {
            saveSetting($db, $k, isset($_POST[$k]) ? '1' : '0', $u['id']);
        }
        logActivity($u['id'], 'UPDATE_SETTINGS', 'Settings', 'Updated Notification settings');
        flash('settings', 'Notification preferences saved.', 'success');
        redirect(BASE_URL . '/super_admin/settings.php?tab=notifications');
    }

    // ── Archive ────────────────────────────────────────────
    if ($action === 'save_archive') {
        saveSetting($db, 'archive_auto_enabled', isset($_POST['archive_auto_enabled']) ? '1' : '0', $u['id']);
        $months = max(1, min(60, (int)($_POST['archive_after_months'] ?? 3)));
        saveSetting($db, 'archive_after_months', (string)$months, $u['id']);
        foreach (['archive_users','archive_requests','archive_notices'] as $k) {
            saveSetting($db, $k, isset($_POST[$k]) ? '1' : '0', $u['id']);
        }
        logActivity($u['id'], 'UPDATE_SETTINGS', 'Settings', 'Updated Archive settings');
        flash('settings', 'Archive settings saved.', 'success');
        redirect(BASE_URL . '/super_admin/settings.php?tab=archive');
    }

    // ── Barangay Management: Official Term ────────────────
    if ($action === 'save_official_term') {
        $years = max(1, min(6, (int)($_POST['official_term_years'] ?? 3)));
        saveSetting($db, 'official_term_years', (string)$years, $u['id']);
        logActivity($u['id'], 'UPDATE_SETTINGS', 'Settings', 'Updated official term duration to ' . $years . ' years');
        $bid = (int)($_POST['barangay_id'] ?? 0);
        flash('settings', 'Official term duration saved successfully.', 'success');
        redirect(BASE_URL . '/super_admin/settings.php?tab=barangay_management' . ($bid ? "&barangay_id=$bid" : ''));
    }

    // ── Barangay Management: Save Portal Info ─────────────
    if ($action === 'save_brgy_portal') {
        $bid      = (int)($_POST['barangay_id'] ?? 0);
        $name     = sanitize($_POST['barangay_name']  ?? '');
        $muni     = sanitize($_POST['municipality']   ?? '');
        $region   = sanitize($_POST['region']         ?? '');
        $province = sanitize($_POST['province']       ?? '');
        $zip      = sanitize($_POST['zip_code']       ?? '');
        $status   = in_array($_POST['status'] ?? '', ['active','inactive']) ? $_POST['status'] : 'active';

        if ($bid && $name) {
            $db->prepare("UPDATE barangays SET barangay_name=?,municipality=?,region=?,province=?,zip_code=?,status=? WHERE id=?")
               ->execute([$name, $muni, $region ?: null, $province ?: null, $zip ?: null, $status, $bid]);

            // Logo upload
            $logoDir = UPLOAD_PATH . 'barangay_logos/';
            if (!is_dir($logoDir)) mkdir($logoDir, 0755, true);
            if (!empty($_FILES['barangay_logo']['name']) && $_FILES['barangay_logo']['error'] === UPLOAD_ERR_OK) {
                $file    = $_FILES['barangay_logo'];
                $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg','jpeg','png','gif','webp'];
                $finfo   = finfo_open(FILEINFO_MIME_TYPE);
                $mime    = finfo_file($finfo, $file['tmp_name']); finfo_close($finfo);
                $allowedMime = ['image/jpeg','image/png','image/gif','image/webp'];
                if (in_array($ext, $allowed) && in_array($mime, $allowedMime) && $file['size'] <= 2*1024*1024) {
                    $oldLogo = $db->prepare("SELECT logo_path FROM barangays WHERE id=?");
                    $oldLogo->execute([$bid]); $oldLogo = $oldLogo->fetchColumn();
                    if ($oldLogo && file_exists($logoDir.$oldLogo)) @unlink($logoDir.$oldLogo);
                    $fname = 'brgy_'.$bid.'_'.time().'.'.$ext;
                    if (move_uploaded_file($file['tmp_name'], $logoDir.$fname)) {
                        $db->prepare("UPDATE barangays SET logo_path=? WHERE id=?")->execute([$fname, $bid]);
                    }
                }
            } elseif (($_POST['remove_logo'] ?? '0') === '1') {
                $oldLogo = $db->prepare("SELECT logo_path FROM barangays WHERE id=?");
                $oldLogo->execute([$bid]); $oldLogo = $oldLogo->fetchColumn();
                if ($oldLogo && file_exists($logoDir.$oldLogo)) @unlink($logoDir.$oldLogo);
                $db->prepare("UPDATE barangays SET logo_path=NULL WHERE id=?")->execute([$bid]);
            }

            logActivity($u['id'], 'EDIT_BARANGAY', 'Barangay Management', "Updated portal info for barangay ID $bid");
            flash('settings', "Barangay portal settings updated successfully.", 'success');
        } else {
            flash('settings', 'Invalid request. Barangay name is required.', 'danger');
        }
        redirect(BASE_URL . '/super_admin/settings.php?tab=barangay_management&barangay_id=' . $bid);
    }

    // ── Barangay Management: Add Official ────────────────
    if ($action === 'add_official') {
        $bid        = (int)($_POST['barangay_id'] ?? 0);
        $firstName  = sanitize($_POST['first_name']  ?? '');
        $middleName = sanitize($_POST['middle_name'] ?? '');
        $lastName   = sanitize($_POST['last_name']   ?? '');
        $suffix     = sanitize($_POST['suffix']      ?? '');
        $position   = sanitize($_POST['position']    ?? '');
        $termStart  = $_POST['term_start'] ?? '';
        $termEnd    = $_POST['term_end']   ?? '';
        $contact    = sanitize($_POST['contact']     ?? '');
        $email      = sanitize($_POST['email']       ?? '');
        $offStatus  = in_array($_POST['off_status'] ?? 'active', ['active','inactive']) ? $_POST['off_status'] : 'active';

        $fullName = trim("$lastName, $firstName" . ($middleName ? " $middleName" : '') . ($suffix ? " $suffix" : ''));

        if ($contact && !preg_match('/^09\d{9}$/', $contact)) {
            flash('settings', 'Contact number must be 11 digits starting with 09.', 'danger');
            redirect(BASE_URL . '/super_admin/settings.php?tab=barangay_management&barangay_id=' . $bid); exit;
        }

        if ($bid && $fullName && $position) {
            $db->prepare("INSERT INTO officials (barangay_id, full_name, position, term_start, term_end, contact, email, status)
                VALUES (?,?,?,?,?,?,?,?)")
               ->execute([$bid, $fullName, $position, $termStart ?: null, $termEnd ?: null, $contact ?: null, $email ?: null, $offStatus]);
            logActivity($u['id'], 'ADD_OFFICIAL', 'Barangay Management', "Added official: $fullName ($position) for barangay $bid");
            flash('settings', "Official <strong>$fullName</strong> added successfully.", 'success');
        } else {
            flash('settings', 'Name and position are required.', 'danger');
        }
        redirect(BASE_URL . '/super_admin/settings.php?tab=barangay_management&barangay_id=' . $bid);
    }

    // ── Barangay Management: Edit Official ───────────────
    if ($action === 'edit_official') {
        $bid      = (int)($_POST['barangay_id']  ?? 0);
        $offId    = (int)($_POST['official_id']  ?? 0);
        $fullName = sanitize($_POST['full_name'] ?? '');
        $position = sanitize($_POST['position']  ?? '');
        $termStart= $_POST['term_start'] ?? '';
        $termEnd  = $_POST['term_end']   ?? '';
        $contact  = sanitize($_POST['contact']   ?? '');
        $email    = sanitize($_POST['email']     ?? '');
        $offStatus= in_array($_POST['off_status'] ?? 'active', ['active','inactive']) ? $_POST['off_status'] : 'active';

        if ($contact && !preg_match('/^09\d{9}$/', $contact)) {
            flash('settings', 'Contact number must be 11 digits starting with 09.', 'danger');
            redirect(BASE_URL . '/super_admin/settings.php?tab=barangay_management&barangay_id=' . $bid); exit;
        }

        if ($offId && $fullName && $position) {
            $db->prepare("UPDATE officials SET full_name=?,position=?,term_start=?,term_end=?,contact=?,email=?,status=? WHERE id=? AND barangay_id=?")
               ->execute([$fullName, $position, $termStart ?: null, $termEnd ?: null, $contact ?: null, $email ?: null, $offStatus, $offId, $bid]);
            logActivity($u['id'], 'EDIT_OFFICIAL', 'Barangay Management', "Updated official ID $offId for barangay $bid");
            flash('settings', "Official updated successfully.", 'success');
        } else {
            flash('settings', 'Invalid request.', 'danger');
        }
        redirect(BASE_URL . '/super_admin/settings.php?tab=barangay_management&barangay_id=' . $bid);
    }

    // ── Barangay Management: Remove/Deactivate Official ──
    if ($action === 'remove_official') {
        $bid   = (int)($_POST['barangay_id']  ?? 0);
        $offId = (int)($_POST['official_id']  ?? 0);
        if ($offId) {
            $db->prepare("UPDATE officials SET status='inactive' WHERE id=? AND barangay_id=?")
               ->execute([$offId, $bid]);
            logActivity($u['id'], 'DEACTIVATE_OFFICIAL', 'Barangay Management', "Deactivated official ID $offId for barangay $bid");
            flash('settings', 'Official deactivated.', 'warning');
        }
        redirect(BASE_URL . '/super_admin/settings.php?tab=barangay_management&barangay_id=' . $bid);
    }

    // ── Appearance (theme/font stored client-side; server saves defaults) ──
    if ($action === 'save_appearance') {
        saveSetting($db, 'appearance_theme',     sanitize($_POST['theme']     ?? 'light'), $u['id']);
        saveSetting($db, 'appearance_font_size', sanitize($_POST['font_size'] ?? '100'),   $u['id']);
        logActivity($u['id'], 'UPDATE_SETTINGS', 'Settings', 'Updated Appearance settings');
        flash('settings', 'Appearance preferences saved.', 'success');
        redirect(BASE_URL . '/super_admin/settings.php?tab=appearance');
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

        $stmt = $db->prepare("SELECT password FROM users WHERE id=?");
        $stmt->execute([$u['id']]);
        $row = $stmt->fetch();

        if (!password_verify($current, $row['password'])) {
            echo json_encode(['ok'=>false,'msg'=>'Current password is incorrect.']); exit;
        }

        $hashed = password_hash($new, PASSWORD_BCRYPT, ['cost'=>12]);
        $db->prepare("UPDATE users SET password=?, must_change_password=0 WHERE id=?")->execute([$hashed, $u['id']]);
        logActivity($u['id'], 'CHANGE_PASSWORD', 'Auth', 'Password changed via Settings');

        echo json_encode(['ok'=>true,'msg'=>'Password changed successfully. You will be logged out now.']);
        exit;
    }
}

// ── Load data for display ──────────────────────────────────
// On AJAX requests only fetch the data the requested tab actually needs.

// general tab: needs landing_content
$lc = [];
if (!$isAjax || $tab === 'general') {
    $lcRow = $db->query("SELECT * FROM landing_content WHERE id=1")->fetch();
    $lc    = $lcRow ?: [];
}

// selfRow already loaded above (needed for 2FA before POST too)

// security / notifications / archive / appearance / authenticator tabs: need system_settings
$allSettings = [];
if (!$isAjax || in_array($tab, ['security','notifications','archive','appearance','authenticator'])) {
    foreach ($db->query("SELECT setting_key, setting_val FROM system_settings")->fetchAll() as $row) {
        $allSettings[$row['setting_key']] = $row['setting_val'];
    }
}
$s = fn(string $k, string $d = '') => $allSettings[$k] ?? $d;

// general + barangay_management tabs: need active barangays list
$barangays = [];
if (!$isAjax || in_array($tab, ['general','barangay_management'])) {
    $barangays = $db->query("SELECT * FROM barangays WHERE status='active' ORDER BY barangay_name")->fetchAll();
}

// ── Barangay Management tab data ───────────────────────────
$bmAllBarangays = [];
$bmSelBrgyId    = (int)($_GET['barangay_id'] ?? 0);
$bmSelBrgy      = null;
$bmOfficials    = [];
$bmTermYears    = 3;
if (!$isAjax || $tab === 'barangay_management') {
    $bmAllBarangays = $db->query("SELECT id, barangay_name, municipality FROM barangays ORDER BY barangay_name")->fetchAll();
    if ($bmSelBrgyId) {
        $bms = $db->prepare("SELECT * FROM barangays WHERE id=?");
        $bms->execute([$bmSelBrgyId]);
        $bmSelBrgy = $bms->fetch() ?: null;

        $bmo = $db->prepare("SELECT * FROM officials WHERE barangay_id=? ORDER BY FIELD(status,'active','inactive'), position, full_name");
        $bmo->execute([$bmSelBrgyId]);
        $bmOfficials = $bmo->fetchAll();
    }
    $bmTermYears = (int)getSetting($db, 'official_term_years', '3');
}

    $flash = getFlash('settings');
    $pageTitle = 'Settings';
    ?>

<?php
$pageIcon     = 'bi-gear-fill';
$pageTitle    = 'Settings';
$pageSubtitle = 'Manage system configuration and preferences.';
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
        /* ── Settings Layout ─────────────────────────────── */
        .main-content { animation: none !important; contain: none !important; }
        #settingsContent { min-height: 400px; }
        .settings-wrap { display: flex; gap: 24px; align-items: flex-start; }
        .settings-sidebar {
            width: 220px; min-width: 220px;
            background: rgba(255,255,255,0.7);
            backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255,255,255,0.8);
            border-radius: 16px;
            padding: 12px 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            position: sticky; top: 24px;
            max-height: calc(100vh - 120px);
            overflow-y: auto;
            will-change: transform; transform: translateZ(0);
            backface-visibility: hidden; isolation: isolate;
        }
        .settings-content { flex: 1; min-width: 0; }
        .settings-sidebar .s-title {
            font-size: 0.65rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.08em; color: var(--text-muted-color,#3a7fa8); padding: 6px 12px 4px;
        }
        .settings-sidebar .s-link {
            display: flex; align-items: center; gap: 10px; padding: 9px 14px;
            border-radius: 10px; font-size: 0.875rem; font-weight: 500;
            color: var(--text-sub,#2a5070); text-decoration: none;
            transition: background 0.15s, color 0.15s; margin-bottom: 2px;
        }
        .settings-sidebar .s-link i { font-size: 1rem; width: 20px; text-align: center; }
        .settings-sidebar .s-link:hover { background: rgba(26,79,122,0.07); color: var(--primary,#1a4f7a); }
        .settings-sidebar .s-link.active {
            background: linear-gradient(90deg,#1a4f7a 0%,#3a7fa8 100%);
            color: #fff; box-shadow: 0 3px 10px rgba(26,79,122,0.25);
        }
        .settings-sidebar .s-link.active i { color: #fff; }
        @media (max-width: 768px) {
            .settings-wrap { flex-direction: column; }
            .settings-sidebar { position: static; width: 100%; max-height: unset; }
        }

        /* ── Card & component styles ── */
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
        .settings-card .sc-header p  { margin: 0; font-size: 0.75rem; color: var(--text-muted-color,#3a7fa8); }
        .settings-card .sc-body { padding: 22px; }
        /* Toggle switch */
        .toggle-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid rgba(0,0,0,0.05);
        }
        .toggle-row:last-child { border-bottom: none; }
        .toggle-row .tr-label { font-size: 0.875rem; font-weight: 500; }
        .toggle-row .tr-desc  { font-size: 0.75rem; color: var(--text-muted-color,#3a7fa8); }
        .form-check-input:checked { background-color: #1a4f7a; border-color: #1a4f7a; }
        /* Image preview */
        .img-preview {
            width: 80px; height: 80px;
            object-fit: cover;
            border-radius: 10px;
            border: 2px solid rgba(0,0,0,0.1);
            background: #e8f4fb;
        }
        .img-preview-banner {
            width: 100%; height: 120px;
            object-fit: cover;
            border-radius: 10px;
            border: 2px solid rgba(0,0,0,0.1);
            background: #e8f4fb;
        }
        /* User table in Users tab */
        .user-role-badge {
            font-size: 0.68rem;
            padding: 3px 8px;
            border-radius: 20px;
            font-weight: 600;
        }
        .badge-super { background: linear-gradient(90deg,#1a4f7a,#3a7fa8); color:#fff; }
        .badge-brgy  { background: rgba(26,79,122,0.1); color:#1a4f7a; }
        @media (max-width: 768px) {
            .settings-sidebar { position: static; width: 100%; max-height: unset; }
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/sidebar_super.php'; ?>
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
        <nav class="settings-sidebar" id="settingsSidebar">
            <div class="s-title">Settings</div>
            <a href="#" class="s-link <?= $tab==='general'?'active':'' ?>" data-tab="general" onclick="loadTab('general',this);return false;"><i class="bi bi-globe2"></i> General</a>
            <a href="#" class="s-link <?= $tab==='profile'?'active':'' ?>" data-tab="profile" onclick="loadTab('profile',this);return false;"><i class="bi bi-person-fill"></i> Profile</a>
            <a href="#" class="s-link <?= $tab==='security'?'active':'' ?>" data-tab="security" onclick="loadTab('security',this);return false;"><i class="bi bi-shield-lock-fill"></i> Security</a>
            <a href="#" class="s-link <?= $tab==='notifications'?'active':'' ?>" data-tab="notifications" onclick="loadTab('notifications',this);return false;"><i class="bi bi-bell-fill"></i> Notifications</a>
            <a href="#" class="s-link <?= $tab==='archive'?'active':'' ?>" data-tab="archive" onclick="loadTab('archive',this);return false;"><i class="bi bi-archive-fill"></i> Archive</a>
            <a href="#" class="s-link <?= $tab==='appearance'?'active':'' ?>" data-tab="appearance" onclick="loadTab('appearance',this);return false;"><i class="bi bi-palette-fill"></i> Appearance</a>
            <a href="#" class="s-link <?= $tab==='authenticator'?'active':'' ?>" data-tab="authenticator" onclick="loadTab('authenticator',this);return false;"><i class="bi bi-shield-check-fill"></i> Authenticator</a>
            <div class="s-title mt-3">Account</div>
            <a href="#" class="s-link" onclick="document.getElementById('changePasswordModal').style.display='flex';return false;"><i class="bi bi-key-fill"></i> Change Password</a>
            <a href="<?= BASE_URL ?>/super_admin/audit_log.php" class="s-link"><i class="bi bi-shield-check-fill"></i> Audit Log</a>
        </nav>

        <!-- ── Settings Content ─────────────────────────── -->
        <?php if ($isAjax) ob_start(); ?>
        <div class="settings-content" id="settingsContent">

            <?php /* ══════════════════════════════════════════
               TAB: GENERAL (Landing Page)
            ══════════════════════════════════════════ */ ?>
            <?php if ($tab === 'general'): ?>
            <!-- Info notice -->
            <div class="alert d-flex align-items-start gap-3 mb-3" style="background:linear-gradient(90deg,rgba(26,79,122,0.08),rgba(58,127,168,0.06));border:1px solid rgba(26,79,122,0.18);border-radius:12px;padding:14px 18px;">
                <div style="width:36px;height:36px;min-width:36px;background:linear-gradient(135deg,#1a4f7a,#3a7fa8);border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="bi bi-globe2 text-white"></i>
                </div>
                <div>
                    <div class="fw-semibold" style="font-size:0.9rem;color:#1a4f7a;">General Settings — Landing Page</div>
                    <div class="text-muted" style="font-size:0.8rem;margin-top:2px;">
                        Changes made here are reflected on the <strong>public-facing landing page</strong> that visitors see before logging in.
                        This includes the welcome text, about section, contact info, logos, and banner image.
                        <a href="<?= BASE_URL ?>/home.php" target="_blank" class="ms-1 fw-semibold" style="color:#1a4f7a;">
                            <i class="bi bi-box-arrow-up-right" style="font-size:0.75rem;"></i> Preview Landing Page
                        </a>
                    </div>
                </div>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="save_general">
                <?= csrfField() ?>

                <!-- Welcome / Hero -->
                <div class="settings-card">
                    <div class="sc-header">
                        <div class="sc-icon"><i class="bi bi-house-fill"></i></div>
                        <div>
                            <h6>Welcome Section</h6>
                            <p>Edit the hero text shown on the landing page</p>
                        </div>
                    </div>
                    <div class="sc-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">Welcome Title</label>
                                <input type="text" name="welcome_title" class="form-control"
                                       value="<?= htmlspecialchars($lc['welcome_title'] ?? '') ?>"
                                       placeholder="Efficient. Secure. Centralized.">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">Subtitle</label>
                                <input type="text" name="welcome_subtitle" class="form-control"
                                       value="<?= htmlspecialchars($lc['welcome_subtitle'] ?? '') ?>"
                                       placeholder="Data Management for Local Governance">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold small">Description</label>
                                <textarea name="welcome_desc" class="form-control" rows="3"
                                          placeholder="Short description shown in the hero section..."><?= htmlspecialchars($lc['welcome_desc'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- About -->
                <div class="settings-card">
                    <div class="sc-header">
                        <div class="sc-icon"><i class="bi bi-info-circle-fill"></i></div>
                        <div>
                            <h6>About Section</h6>
                            <p>Describe the system for the public</p>
                        </div>
                    </div>
                    <div class="sc-body">
                        <label class="form-label fw-semibold small">About Text</label>
                        <textarea name="about_text" class="form-control" rows="4"
                                  placeholder="About the system..."><?= htmlspecialchars($lc['about_text'] ?? '') ?></textarea>
                    </div>
                </div>

                <!-- Contact Info -->
                <div class="settings-card">
                    <div class="sc-header">
                        <div class="sc-icon"><i class="bi bi-telephone-fill"></i></div>
                        <div>
                            <h6>Contact Information</h6>
                            <p>Shown in the footer of the landing page</p>
                        </div>
                    </div>
                    <div class="sc-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small">Phone</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                                    <input type="text" name="contact_phone" class="form-control"
                                           value="<?= htmlspecialchars($lc['contact_phone'] ?? '') ?>"
                                           placeholder="(044) 000-0000"
                                           inputmode="numeric"
                                           oninput="this.value=this.value.replace(/[^0-9()\-\s+]/g,'')">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small">Email</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                    <input type="email" name="contact_email" class="form-control"
                                           value="<?= htmlspecialchars($lc['contact_email'] ?? '') ?>"
                                           placeholder="lgu@talavera.gov.ph">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small">Address</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-geo-alt"></i></span>
                                    <input type="text" name="contact_address" class="form-control"
                                           value="<?= htmlspecialchars($lc['contact_address'] ?? '') ?>"
                                           placeholder="Talavera, Nueva Ecija">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                        <!-- Logos & Banner -->
                <div class="settings-card">
                    <div class="sc-header">
                        <div class="sc-icon"><i class="bi bi-image-fill"></i></div>
                        <div>
                            <h6>Logo &amp; Hero Background</h6>
                            <p>Upload logos and the hero section background image</p>
                        </div>
                    </div>
                    <div class="sc-body">
                        <div class="row g-4">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small">Logo / Seal 1</label>
                                <div class="d-flex align-items-center gap-3 mb-2">
                                    <?php $l1 = !empty($lc['logo1_path']) ? UPLOAD_URL . $lc['logo1_path'] : BASE_URL . '/assets/img/logo 1.jpg'; ?>
                                    <img src="<?= $l1 ?>" alt="Logo 1" class="img-preview" id="prev_logo1">
                                    <div>
                                        <input type="file" name="logo1" class="form-control form-control-sm"
                                               accept="image/*" onchange="previewImg(this,'prev_logo1')">
                                        <div class="form-text">Recommended: 200×200px</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small">Logo / Seal 2</label>
                                <div class="d-flex align-items-center gap-3 mb-2">
                                    <?php $l2 = !empty($lc['logo2_path']) ? UPLOAD_URL . $lc['logo2_path'] : BASE_URL . '/assets/img/logo 2.jpg'; ?>
                                    <img src="<?= $l2 ?>" alt="Logo 2" class="img-preview" id="prev_logo2">
                                    <div>
                                        <input type="file" name="logo2" class="form-control form-control-sm"
                                               accept="image/*" onchange="previewImg(this,'prev_logo2')">
                                        <div class="form-text">Recommended: 200×200px</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small">
                                    Hero Background Image
                                    <span class="badge ms-1" style="background:rgba(26,79,122,0.12);color:#1a4f7a;font-size:0.65rem;font-weight:600;">replaces blue gradient</span>
                                </label>
                                <?php $bn = !empty($lc['banner_path']) ? UPLOAD_URL . $lc['banner_path'] : ''; ?>
                                <?php if ($bn): ?>
                                    <img src="<?= $bn ?>" alt="Hero Background" class="img-preview-banner mb-2" id="prev_banner">
                                <?php else: ?>
                                    <div class="img-preview-banner mb-2 d-flex align-items-center justify-content-center text-muted small" id="prev_banner" style="border:2px dashed #ccc;background:linear-gradient(135deg,#0f2447,#1a3a6b);">
                                        <span style="color:#94a3b8;"><i class="bi bi-image me-1"></i>No image — blue gradient is used</span>
                                    </div>
                                <?php endif; ?>
                                <input type="file" name="banner" class="form-control form-control-sm"
                                       accept="image/*" onchange="previewImgBanner(this,'prev_banner')">
                                <div class="form-text">This image becomes the background of the hero section. A dark overlay is applied automatically so text stays readable. Recommended: 1920×1080px</div>
                                <?php if ($bn): ?>
                                <div class="mt-2">
                                    <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size:0.75rem;"
                                            onclick="if(confirm('Remove hero background image and revert to blue gradient?')) { document.getElementById('removeBanner').value='1'; this.closest('.col-md-4').querySelector('.img-preview-banner').style.display='none'; }">
                                        <i class="bi bi-x-circle me-1"></i>Remove Image
                                    </button>
                                    <input type="hidden" name="remove_banner" id="removeBanner" value="0">
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="bi bi-check-lg me-1"></i>Save Changes
                    </button>
                </div>
            </form>
            <?php endif; ?>

            <?php /* ══════════════════════════════════════════
               TAB: PROFILE
            ══════════════════════════════════════════ */ ?>
            <?php if ($tab === 'profile'):
                $saPhoto    = $selfRow['profile_photo'] ?? '';
                $saPhotoUrl = $saPhoto ? UPLOAD_URL . 'profile_photos/' . $saPhoto : '';
            ?>
            <form method="POST" enctype="multipart/form-data" action="<?= BASE_URL ?>/super_admin/settings.php?tab=profile" data-no-ajax id="saProfileForm">
                <input type="hidden" name="action" value="save_profile">
                <input type="hidden" name="remove_profile_photo" id="saRemoveProfilePhoto" value="0">
                <?= csrfField() ?>
                <div class="settings-card">
                    <div class="sc-header">
                        <div class="sc-icon"><i class="bi bi-person-fill"></i></div>
                        <div>
                            <h6>My Profile</h6>
                            <p>Update your personal information</p>
                        </div>
                    </div>
                    <div class="sc-body">
                        <div class="d-flex align-items-center gap-4 mb-4 p-3 rounded-3" style="background:rgba(26,79,122,0.05);">
                            <input type="file" name="profile_photo" id="saProfilePhotoInput"
                                   accept="image/jpeg,.jfif,image/png,image/gif,image/webp"
                                   style="display:none;"
                                   onchange="previewSAPhoto(this)">

                            <div style="position:relative;width:90px;height:90px;flex-shrink:0;">
                                <div style="width:90px;height:90px;border-radius:12px;overflow:hidden;border:2px solid rgba(26,79,122,0.2);background:#e8f0f7;">
                                    <img id="saPhotoPreview" src="<?= htmlspecialchars($saPhotoUrl) ?>"
                                         data-saved-src="<?= htmlspecialchars($saPhotoUrl) ?>"
                                         alt="Profile Photo"
                                         style="width:100%;height:100%;object-fit:cover;<?= $saPhotoUrl ? '' : 'display:none;' ?>">
                                    <div id="saPhotoInitials"
                                         style="width:100%;height:100%;background:linear-gradient(135deg,#1a4f7a,#3a7fa8);display:<?= $saPhotoUrl ? 'none' : 'flex' ?>;align-items:center;justify-content:center;color:#fff;font-size:1.8rem;font-weight:700;">
                                        <?= strtoupper(substr($selfRow['first_name'],0,1) . substr($selfRow['last_name'],0,1)) ?>
                                    </div>
                                </div>
                                <button type="button"
                                        onclick="document.getElementById('saProfilePhotoInput').click()"
                                        title="Change photo"
                                        style="position:absolute;bottom:-6px;right:-6px;width:28px;height:28px;border-radius:50%;background:#1a4f7a;border:2px solid #fff;color:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 2px 6px rgba(0,0,0,0.25);font-size:0.75rem;">
                                    <i class="bi bi-pencil-fill"></i>
                                </button>
                            </div>
                            <div id="saPhotoSizeErr" class="text-danger mt-1" style="display:none;font-size:0.72rem;"></div>

                            <div class="flex-grow-1">
                                <div class="fw-bold fs-6" id="saDisplayName"><?= htmlspecialchars($selfRow['first_name'].' '.$selfRow['last_name']) ?></div>
                                <div class="small text-muted"><?= htmlspecialchars($selfRow['email']) ?></div>
                                <span class="badge badge-super user-role-badge mt-1">Super Admin</span>
                                <div class="mt-2" id="saRemovePhotoWrap" style="<?= $saPhoto ? '' : 'display:none;' ?>">
                                    <button type="button" class="btn btn-sm btn-outline-danger"
                                            style="border-radius:6px;font-size:0.72rem;padding:2px 10px;"
                                            onclick="toggleSAPhotoRemoval()">
                                        <i class="bi bi-trash3 me-1"></i>Remove Photo
                                    </button>
                                </div>
                                <div id="saPhotoUploadStatus" class="mt-1" style="display:none;font-size:0.75rem;" aria-live="polite"></div>
                                <div class="mt-1" style="font-size:0.7rem;color:#94a3b8;">
                                    <i class="bi bi-pencil-fill me-1"></i>Choose a photo, then click Save Profile to apply it &nbsp;·&nbsp; JPG, JFIF, PNG, WEBP, GIF &nbsp;·&nbsp; Max 2 MB
                                </div>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small">First Name <span class="text-danger">*</span></label>
                                <input type="text" name="first_name" class="form-control" required
                                       value="<?= htmlspecialchars($selfRow['first_name']) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small">Middle Name</label>
                                <input type="text" name="middle_name" class="form-control"
                                       value="<?= htmlspecialchars($selfRow['middle_name'] ?? '') ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small">Last Name <span class="text-danger">*</span></label>
                                <input type="text" name="last_name" class="form-control" required
                                       value="<?= htmlspecialchars($selfRow['last_name']) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">Email Address <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                    <input type="email" name="email" class="form-control" required
                                           value="<?= htmlspecialchars($selfRow['email']) ?>">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold small">Contact Number</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-phone"></i></span>
                                    <input type="text" name="contact" id="saSettingsContact" class="form-control"
                                           value="<?= htmlspecialchars($selfRow['contact_number'] ?? '') ?>"
                                           placeholder="09XXXXXXXXX" maxlength="11"
                                           inputmode="numeric" pattern="09[0-9]{9}"
                                           oninput="validateContact(this,'saSettingsContactErr')">
                                </div>
                                <div id="saSettingsContactErr" class="text-danger mt-1" style="display:none;font-size:0.72rem;"></div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold small">Position / Title</label>
                                <input type="text" name="position" class="form-control"
                                       value="<?= htmlspecialchars($selfRow['position'] ?? '') ?>"
                                       placeholder="e.g. System Administrator">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                    <button type="button" class="btn btn-outline-secondary btn-sm"
                            onclick="document.getElementById('changePasswordModal').style.display='flex'">
                        <i class="bi bi-key me-1"></i>Change Password
                    </button>
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="bi bi-check-lg me-1"></i>Save Profile
                    </button>
                </div>
            </form>

            <?php endif; ?>

            <?php /* ══════════════════════════════════════════
               TAB: SECURITY
            ══════════════════════════════════════════ */ ?>
            <?php if ($tab === 'security'): ?>
            <form method="POST">
                <input type="hidden" name="action" value="save_security">
                <?= csrfField() ?>

                <!-- Password Rules -->
                <div class="settings-card">
                    <div class="sc-header">
                        <div class="sc-icon"><i class="bi bi-key-fill"></i></div>
                        <div>
                            <h6>Password Rules</h6>
                            <p>Set requirements for all user passwords</p>
                        </div>
                    </div>
                    <div class="sc-body">
                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small">Minimum Length</label>
                                <div class="input-group">
                                    <input type="number" name="password_min_length" class="form-control"
                                           value="<?= (int)$s('password_min_length','8') ?>" min="6" max="32"
                                           oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                                    <span class="input-group-text">chars</span>
                                </div>
                                <div class="form-text">Between 6 and 32 characters</div>
                            </div>
                        </div>
                        <div class="toggle-row">
                            <div>
                                <div class="tr-label">Require Uppercase Letter</div>
                                <div class="tr-desc">Password must contain at least one uppercase letter (A–Z)</div>
                            </div>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" name="password_require_upper"
                                       role="switch" style="width:2.5em;height:1.3em;"
                                       <?= $s('password_require_upper','1')==='1'?'checked':'' ?>>
                            </div>
                        </div>
                        <div class="toggle-row">
                            <div>
                                <div class="tr-label">Require Number</div>
                                <div class="tr-desc">Password must contain at least one digit (0–9)</div>
                            </div>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" name="password_require_number"
                                       role="switch" style="width:2.5em;height:1.3em;"
                                       <?= $s('password_require_number','1')==='1'?'checked':'' ?>>
                            </div>
                        </div>
                        <div class="toggle-row">
                            <div>
                                <div class="tr-label">Require Special Character</div>
                                <div class="tr-desc">Password must contain at least one special character (!@#$...)</div>
                            </div>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" name="password_require_special"
                                       role="switch" style="width:2.5em;height:1.3em;"
                                       <?= $s('password_require_special','0')==='1'?'checked':'' ?>>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Login Attempts -->
                <div class="settings-card">
                    <div class="sc-header">
                        <div class="sc-icon"><i class="bi bi-shield-exclamation"></i></div>
                        <div>
                            <h6>Login Attempt Limits</h6>
                            <p>Lock accounts after too many failed logins</p>
                        </div>
                    </div>
                    <div class="sc-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small">Max Failed Attempts</label>
                                <div class="input-group">
                                    <input type="number" name="login_max_attempts" class="form-control"
                                           value="<?= (int)$s('login_max_attempts','5') ?>" min="1" max="20"
                                           oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                                    <span class="input-group-text">tries</span>
                                </div>
                                <div class="form-text">Account locks after this many failures</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small">Lockout Duration</label>
                                <div class="input-group">
                                    <input type="number" name="login_lockout_minutes" class="form-control"
                                           value="<?= (int)$s('login_lockout_minutes','15') ?>" min="1" max="1440"
                                           oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                                    <span class="input-group-text">min</span>
                                </div>
                                <div class="form-text">How long the account stays locked</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Idle Auto-Logout -->
                <div class="settings-card">
                    <div class="sc-header">
                        <div class="sc-icon"><i class="bi bi-hourglass-split"></i></div>
                        <div>
                            <h6>Idle Auto-Logout</h6>
                            <p>Automatically log out users after inactivity</p>
                        </div>
                    </div>
                    <div class="sc-body">
                        <?php
                        $curIdleUnit = $s('idle_timeout_unit','minutes');
                        $curIdleUnit = in_array($curIdleUnit,['seconds','minutes']) ? $curIdleUnit : 'minutes';
                        $curIdleVal  = (int)$s('idle_timeout_minutes','5');
                        $idleEnabled = $s('idle_timeout_enabled','1') === '1';
                        ?>
                        <div class="toggle-row">
                            <div>
                                <div class="tr-label">Enable Idle Timeout</div>
                                <div class="tr-desc">When idle, users see a "Log In Now" prompt and are auto-logged out after the countdown</div>
                            </div>
                            <input type="checkbox" class="form-check-input" name="idle_timeout_enabled"
                                   <?= $idleEnabled ? 'checked' : '' ?>
                                   style="width:42px;height:24px;cursor:pointer;"
                                   onchange="document.getElementById('saIdleMinRow').style.display=this.checked?'flex':'none'">
                        </div>
                        <div class="row g-3 mt-2 align-items-end" id="saIdleMinRow"
                             style="display:<?= $idleEnabled ? 'flex' : 'none' ?>">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small">Idle Timeout After</label>
                                <input type="number" name="idle_timeout_minutes" id="saIdleValueInput"
                                       class="form-control"
                                       value="<?= $curIdleVal ?>"
                                       min="<?= $curIdleUnit==='seconds'?10:1 ?>"
                                       max="<?= $curIdleUnit==='seconds'?300:120 ?>"
                                       oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold small">Unit</label>
                                <select name="idle_timeout_unit" id="saIdleUnitSelect" class="form-select"
                                        onchange="saIdleUnitChanged(this.value)">
                                    <option value="seconds" <?= $curIdleUnit==='seconds'?'selected':'' ?>>Seconds</option>
                                    <option value="minutes" <?= $curIdleUnit==='minutes'?'selected':'' ?>>Minutes</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <div class="form-text" id="saIdleRangeHint">
                                    <?= $curIdleUnit==='seconds'
                                        ? '10–300 seconds (e.g. 30 = 30 seconds)'
                                        : '1–120 minutes (e.g. 5 = 5 minutes)' ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <script>
                function saIdleUnitChanged(unit) {
                    var input = document.getElementById('saIdleValueInput');
                    var hint  = document.getElementById('saIdleRangeHint');
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

                <!-- 2FA shortcut -->
                <div class="settings-card">
                    <div class="sc-header">
                        <div class="sc-icon"><i class="bi bi-phone-fill"></i></div>
                        <div>
                            <h6>Two-Factor Authentication</h6>
                            <p>Add an extra layer of security to your account</p>
                        </div>
                        <a href="<?= BASE_URL ?>/setup_2fa.php" class="btn btn-outline-primary btn-sm ms-auto">
                            <i class="bi bi-shield-check me-1"></i>Manage 2FA
                        </a>
                    </div>
                    <div class="sc-body">
                        <?php
                        $twoFaRow = $db->prepare("SELECT two_fa_enabled FROM users WHERE id=?");
                        $twoFaRow->execute([$u['id']]);
                        $twoFaEnabled = (bool)($twoFaRow->fetchColumn());
                        ?>
                        <div class="d-flex align-items-center gap-3">
                            <div style="width:44px;height:44px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:<?= $twoFaEnabled?'linear-gradient(135deg,#1b5e20,#1a4f7a)':'linear-gradient(135deg,#6c757d,#adb5bd)' ?>;">
                                <i class="bi bi-shield-<?= $twoFaEnabled?'check':'exclamation' ?>-fill text-white fs-5"></i>
                            </div>
                            <div>
                                <div class="fw-semibold"><?= $twoFaEnabled?'2FA is Enabled':'2FA is Disabled' ?></div>
                                <div class="small text-muted"><?= $twoFaEnabled?'Your account is protected with an authenticator app.':'Enable 2FA for stronger account security.' ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="bi bi-check-lg me-1"></i>Save Security Settings
                    </button>
                </div>
            </form>
            <?php endif; ?>

            <?php /* ══════════════════════════════════════════
               TAB: NOTIFICATIONS
            ══════════════════════════════════════════ */ ?>
            <?php if ($tab === 'notifications'): ?>
            <form method="POST">
                <input type="hidden" name="action" value="save_notifications">
                <?= csrfField() ?>
                <div class="settings-card">
                    <div class="sc-header">
                        <div class="sc-icon"><i class="bi bi-bell-fill"></i></div>
                        <div>
                            <h6>Notification Preferences</h6>
                            <p>Control which alerts are sent to users</p>
                        </div>
                    </div>
                    <div class="sc-body">
                        <div class="toggle-row">
                            <div>
                                <div class="tr-label"><i class="bi bi-check-circle-fill text-success me-1"></i>Request Approved</div>
                                <div class="tr-desc">Notify users when their request is approved</div>
                            </div>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" name="notif_request_approved"
                                       role="switch" style="width:2.5em;height:1.3em;"
                                       <?= $s('notif_request_approved','1')==='1'?'checked':'' ?>>
                            </div>
                        </div>
                        <div class="toggle-row">
                            <div>
                                <div class="tr-label"><i class="bi bi-x-circle-fill text-danger me-1"></i>Request Rejected</div>
                                <div class="tr-desc">Notify users when their request is rejected</div>
                            </div>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" name="notif_request_rejected"
                                       role="switch" style="width:2.5em;height:1.3em;"
                                       <?= $s('notif_request_rejected','1')==='1'?'checked':'' ?>>
                            </div>
                        </div>
                        <div class="toggle-row">
                            <div>
                                <div class="tr-label"><i class="bi bi-flag-fill text-primary me-1"></i>Request Completed</div>
                                <div class="tr-desc">Notify users when their request is marked as done</div>
                            </div>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" name="notif_request_done"
                                       role="switch" style="width:2.5em;height:1.3em;"
                                       <?= $s('notif_request_done','1')==='1'?'checked':'' ?>>
                            </div>
                        </div>
                        <div class="toggle-row">
                            <div>
                                <div class="tr-label"><i class="bi bi-exclamation-triangle-fill text-warning me-1"></i>System Alerts</div>
                                <div class="tr-desc">Show system-level alerts and announcements</div>
                            </div>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" name="notif_system_alerts"
                                       role="switch" style="width:2.5em;height:1.3em;"
                                       <?= $s('notif_system_alerts','1')==='1'?'checked':'' ?>>
                            </div>
                        </div>
                        <div class="toggle-row">
                            <div>
                                <div class="tr-label"><i class="bi bi-envelope-fill text-info me-1"></i>Email Notifications</div>
                                <div class="tr-desc">Send email alerts in addition to in-app notifications (requires mail server setup)</div>
                            </div>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" name="notif_email_enabled"
                                       role="switch" style="width:2.5em;height:1.3em;"
                                       <?= $s('notif_email_enabled','0')==='1'?'checked':'' ?>>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="bi bi-check-lg me-1"></i>Save Notification Settings
                    </button>
                </div>
            </form>
            <?php endif; ?>

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
                                <div class="tr-desc">Automatically archive records after the specified period</div>
                            </div>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" name="archive_auto_enabled"
                                       id="archAutoToggle" role="switch" style="width:2.5em;height:1.3em;"
                                       <?= $s('archive_auto_enabled','0')==='1'?'checked':'' ?>
                                       onchange="document.getElementById('archPeriodRow').style.display=this.checked?'':'none'">
                            </div>
                        </div>
                        <div class="row g-3 mt-1" id="archPeriodRow"
                             style="display:<?= $s('archive_auto_enabled','0')==='1'?'flex':'none' ?>">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small">Archive After</label>
                                <div class="input-group">
                                    <input type="number" name="archive_after_months" class="form-control"
                                           value="<?= (int)$s('archive_after_months','3') ?>" min="1" max="60"
                                           oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                                    <span class="input-group-text">months</span>
                                </div>
                                <div class="form-text">Records older than this will be auto-archived</div>
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
                                <div class="tr-label"><i class="bi bi-person-x-fill text-secondary me-1"></i>Inactive Users</div>
                                <div class="tr-desc">Archive user accounts that have been inactive</div>
                            </div>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" name="archive_users"
                                       role="switch" style="width:2.5em;height:1.3em;"
                                       <?= $s('archive_users','1')==='1'?'checked':'' ?>>
                            </div>
                        </div>
                        <div class="toggle-row">
                            <div>
                                <div class="tr-label"><i class="bi bi-inbox-fill text-secondary me-1"></i>Old Requests</div>
                                <div class="tr-desc">Archive completed or rejected requests</div>
                            </div>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" name="archive_requests"
                                       role="switch" style="width:2.5em;height:1.3em;"
                                       <?= $s('archive_requests','1')==='1'?'checked':'' ?>>
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
                                       <?= $s('archive_notices','1')==='1'?'checked':'' ?>>
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
                        <a href="<?= BASE_URL ?>/super_admin/archive.php" class="btn btn-outline-primary btn-sm ms-auto">
                            <i class="bi bi-arrow-up-right-square me-1"></i>Open Archive
                        </a>
                    </div>
                    <div class="sc-body">
                        <?php
                        $archCount = $db->query("SELECT COUNT(*) FROM archives")->fetchColumn();
                        ?>
                        <div class="d-flex align-items-center gap-3">
                            <div style="width:44px;height:44px;border-radius:12px;background:linear-gradient(135deg,#1a4f7a,#3a7fa8);display:flex;align-items:center;justify-content:center;">
                                <i class="bi bi-archive-fill text-white fs-5"></i>
                            </div>
                            <div>
                                <div class="fw-bold fs-5"><?= number_format($archCount) ?></div>
                                <div class="small text-muted">Total archived records</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="bi bi-check-lg me-1"></i>Save Archive Settings
                    </button>
                </div>
            </form>
            <?php endif; ?>

            <?php /* ══════════════════════════════════════════
               TAB: APPEARANCE
            ══════════════════════════════════════════ */ ?>
            <?php if ($tab === 'appearance'): ?>
            <form method="POST" id="appearanceForm">
                <input type="hidden" name="action" value="save_appearance">
                <?= csrfField() ?>
                <input type="hidden" name="theme" id="themeInput" value="<?= $s('appearance_theme','light') ?>">
                <input type="hidden" name="font_size" id="fontSizeInput" value="<?= $s('appearance_font_size','100') ?>">

                <!-- Theme -->
                <div class="settings-card">
                    <div class="sc-header">
                        <div class="sc-icon"><i class="bi bi-moon-stars-fill"></i></div>
                        <div>
                            <h6>Theme</h6>
                            <p>Choose between light and dark mode</p>
                        </div>
                    </div>
                    <div class="sc-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <div class="theme-option" id="themeLight" onclick="selectTheme('light')"
                                     style="cursor:pointer;border:2px solid <?= $s('appearance_theme','light')==='light'?'#1a4f7a':'rgba(0,0,0,0.1)' ?>;border-radius:12px;padding:16px;text-align:center;transition:border 0.2s;">
                                    <div style="width:100%;height:60px;background:linear-gradient(160deg,#e8f4fb,#c8e4f2);border-radius:8px;margin-bottom:10px;display:flex;align-items:center;justify-content:center;">
                                        <i class="bi bi-sun-fill text-warning fs-4"></i>
                                    </div>
                                    <div class="fw-semibold small">Light Mode</div>
                                    <div class="text-muted" style="font-size:0.72rem;">Clean, bright interface</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="theme-option" id="themeDark" onclick="selectTheme('dark')"
                                     style="cursor:pointer;border:2px solid <?= $s('appearance_theme','light')==='dark'?'#1a4f7a':'rgba(0,0,0,0.1)' ?>;border-radius:12px;padding:16px;text-align:center;transition:border 0.2s;">
                                    <div style="width:100%;height:60px;background:linear-gradient(160deg,#1a2e1f,#06111e);border-radius:8px;margin-bottom:10px;display:flex;align-items:center;justify-content:center;">
                                        <i class="bi bi-moon-stars-fill text-info fs-4"></i>
                                    </div>
                                    <div class="fw-semibold small">Dark Mode</div>
                                    <div class="text-muted" style="font-size:0.72rem;">Easy on the eyes</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Font Size -->
                <div class="settings-card">
                    <div class="sc-header">
                        <div class="sc-icon"><i class="bi bi-fonts"></i></div>
                        <div>
                            <h6>Font Size</h6>
                            <p>Adjust the text size across the system</p>
                        </div>
                    </div>
                    <div class="sc-body">
                        <div class="d-flex align-items-center gap-3">
                            <span class="small text-muted">A</span>
                            <input type="range" class="form-range flex-grow-1" id="fontSizeRange"
                                   min="0" max="5" step="1"
                                   value="<?php
                                       $steps = [80,90,100,110,120,130];
                                       $cur = (int)$s('appearance_font_size','100');
                                       echo array_search($cur, $steps) !== false ? array_search($cur, $steps) : 2;
                                   ?>"
                                   oninput="updateFontSize(this.value)">
                            <span class="fw-bold" style="font-size:1.2rem;">A</span>
                            <span class="badge bg-primary ms-2" id="fontSizeDisplay"><?= $s('appearance_font_size','100') ?>%</span>
                        </div>
                        <div class="d-flex justify-content-between mt-1" style="font-size:0.7rem;color:#999;">
                            <span>80%</span><span>90%</span><span>100%</span><span>110%</span><span>120%</span><span>130%</span>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="bi bi-check-lg me-1"></i>Save Appearance
                    </button>
                </div>
            </form>
            <?php endif; ?>

            <?php /* ══════════════════════════════════════════
               TAB: AUTHENTICATOR (2FA)
            ══════════════════════════════════════════ */ ?>
            <?php if ($tab === 'authenticator'):
                require_once BASE_PATH . '/helpers/totp.php';
                // Re-fetch fresh user data after any POST
                $twoFaRow = $db->prepare("SELECT * FROM users WHERE id=?");
                $twoFaRow->execute([$u['id']]);
                $twoFaUser    = $twoFaRow->fetch();
                $is2FAEnabled = (bool)($twoFaUser['two_fa_enabled'] ?? 0);
                $hasSecret    = !empty($twoFaUser['totp_secret']);
                $qrDataUri    = $hasSecret ? TOTP::getQRCodeDataUri($twoFaUser['totp_secret'], $twoFaUser['email'], APP_NAME) : null;
            ?>

            <!-- Flash for 2FA actions -->
            <?php $f2 = getFlash('settings'); if ($f2): ?>
            <div class="alert alert-<?= $f2['type'] ?> alert-dismissible fade show mb-3">
                <i class="bi bi-<?= $f2['type']==='success'?'check-circle':'exclamation-triangle' ?>-fill me-2"></i>
                <?= $f2['message'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

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
                    <!-- 2FA is ON — show info + disable button -->
                    <div class="row g-4 align-items-start">
                        <div class="col-md-7">
                            <h6 class="fw-semibold mb-3"><i class="bi bi-info-circle me-2"></i>How it works</h6>
                            <ul class="small text-muted mb-3" style="line-height:2.2;">
                                <li>Every time you log in, you'll be asked for a 6-digit code</li>
                                <li>Open your authenticator app and enter the current code</li>
                                <li>Codes refresh every 30 seconds</li>
                                <li>If you lose access to your app, use the emergency admin account</li>
                            </ul>
                            <div class="alert alert-warning py-2 small mb-0">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                <strong>Important:</strong> Keep your authenticator app installed. If you lose it, you'll need another admin account to disable 2FA.
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
                            <img src="<?= htmlspecialchars($qrDataUri, ENT_QUOTES, 'UTF-8') ?>" alt="QR Code" class="img-fluid rounded border p-2" style="max-width:200px;">
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
                        <p class="text-muted small mb-4">You'll need an authenticator app like <strong>Google Authenticator</strong>, <strong>Authy</strong>, or <strong>Microsoft Authenticator</strong>.</p>
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
            <?php endif; ?>

            <?php /* ══════════════════════════════════════════
               TAB: BARANGAY MANAGEMENT
            ══════════════════════════════════════════ */ ?>
            <?php if ($tab === 'barangay_management'): ?>

            <!-- ── Barangay Selector ──────────────────────────── -->
            <div class="settings-card mb-3" style="overflow:visible;">
                <div class="sc-header">
                    <div class="sc-icon"><i class="bi bi-buildings-fill"></i></div>
                    <div>
                        <h6>Barangay Management</h6>
                        <p>Select a barangay to manage its portal, officials, and settings</p>
                    </div>
                </div>
                <div class="sc-body" style="overflow:visible;">
                    <div class="d-flex align-items-center gap-2" style="max-width:480px;">
                        <div style="position:relative;flex:1;" id="bmSelectorWrap">
                            <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#3a7fa8;pointer-events:none;font-size:0.8rem;z-index:1;">
                                <i class="bi bi-search"></i>
                            </span>
                            <input type="text" id="bmBrgyInput" autocomplete="off"
                                   placeholder="Search barangay…"
                                   value="<?php
                                       if ($bmSelBrgy) {
                                           echo htmlspecialchars($bmSelBrgy['barangay_name'])
                                                . ($bmSelBrgy['municipality'] ? ' — '.htmlspecialchars($bmSelBrgy['municipality']) : '');
                                       }
                                   ?>"
                                   class="form-control form-control-sm"
                                   style="padding-left:30px;border-radius:8px;border:1.5px solid rgba(26,79,122,0.22);font-size:0.85rem;font-weight:500;color:#1a4f7a;"
                                   oninput="bmFilterDropdown(this.value)"
                                   onfocus="bmShowDropdown()"
                                   onkeydown="bmKeyNav(event)">
                        </div>
                        <?php if ($bmSelBrgyId): ?>
                        <a href="?tab=barangay_management" title="Clear selection"
                           style="color:#adb5bd;font-size:0.9rem;text-decoration:none;flex-shrink:0;">
                            <i class="bi bi-x-circle-fill"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php if (!$bmAllBarangays): ?>
                    <div class="text-muted small mt-2"><i class="bi bi-info-circle me-1"></i>No barangays found. Add barangays in <a href="<?= BASE_URL ?>/super_admin/barangays.php">Barangays</a>.</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Dropdown rendered at body level to escape all overflow clipping -->
            <div id="bmDropdown"
                 style="display:none;position:fixed;background:#fff;border:1px solid rgba(26,79,122,0.18);border-radius:10px;box-shadow:0 8px 32px rgba(0,0,0,0.15);z-index:99999;max-height:240px;overflow-y:auto;">
                <?php foreach ($bmAllBarangays as $br):
                    $optLabel = htmlspecialchars($br['barangay_name']) . ($br['municipality'] ? ' — '.htmlspecialchars($br['municipality']) : '');
                    $isActive = $bmSelBrgyId === (int)$br['id'];
                ?>
                <div class="bm-opt <?= $isActive ? 'bm-opt-active' : '' ?>"
                     data-id="<?= $br['id'] ?>"
                     data-label="<?= $optLabel ?>"
                     onclick="bmSelectBrgy(<?= $br['id'] ?>)"
                     style="padding:8px 14px;cursor:pointer;font-size:0.83rem;font-weight:<?= $isActive?'600':'400' ?>;color:<?= $isActive?'#1a4f7a':'#2a5070' ?>;border-bottom:1px solid rgba(0,0,0,0.04);">
                    <?php if ($isActive): ?><i class="bi bi-check2 me-1" style="color:#1a4f7a;"></i><?php endif; ?>
                    <?= $optLabel ?>
                </div>
                <?php endforeach; ?>
                <?php if (!$bmAllBarangays): ?>
                <div style="padding:10px 14px;font-size:0.8rem;color:#94a3b8;">No barangays found.</div>
                <?php endif; ?>
            </div>

            <style>
                .bm-opt:hover,.bm-opt.bm-opt-focused{background:rgba(26,79,122,0.07);color:#1a4f7a;}
                .bm-opt-active{background:rgba(26,79,122,0.06)!important;}
                .bm-opt:last-child{border-bottom:none!important;}
            </style>
            <script>
            (function(){
                const input = document.getElementById('bmBrgyInput');
                const wrap  = document.getElementById('bmSelectorWrap');
                const dd    = document.getElementById('bmDropdown');
                const opts  = Array.from(dd.querySelectorAll('.bm-opt'));
                let fi = -1;

                function positionDropdown() {
                    const rect = wrap.getBoundingClientRect();
                    dd.style.top   = (rect.bottom + window.scrollY + 4) + 'px';
                    dd.style.left  = (rect.left  + window.scrollX) + 'px';
                    dd.style.width = rect.width + 'px';
                }

                window.bmShowDropdown = function() {
                    positionDropdown();
                    bmFilterDropdown(input.value);
                    dd.style.display = 'block';
                };
                window.bmFilterDropdown = function(q) {
                    const qn = q.trim().toLowerCase();
                    let vis = 0;
                    opts.forEach(o => {
                        const show = !qn || o.dataset.label.toLowerCase().includes(qn);
                        o.style.display = show ? '' : 'none';
                        if (show) vis++;
                    });
                    fi = -1;
                    dd.style.display = vis ? 'block' : 'none';
                    if (vis) positionDropdown();
                };
                window.bmKeyNav = function(e) {
                    const vis = opts.filter(o => o.style.display !== 'none');
                    if      (e.key === 'ArrowDown') { e.preventDefault(); fi = Math.min(fi+1, vis.length-1); }
                    else if (e.key === 'ArrowUp')   { e.preventDefault(); fi = Math.max(fi-1, 0); }
                    else if (e.key === 'Enter' && fi >= 0) { e.preventDefault(); vis[fi].click(); return; }
                    else if (e.key === 'Escape')    { dd.style.display = 'none'; input.blur(); return; }
                    vis.forEach((o,i) => o.classList.toggle('bm-opt-focused', i === fi));
                    if (vis[fi]) vis[fi].scrollIntoView({block:'nearest'});
                };
                window.bmSelectBrgy = function(id) {
                    dd.style.display = 'none';
                    window.location.href = '?tab=barangay_management&barangay_id=' + id;
                };

                // Reposition on scroll/resize
                window.addEventListener('scroll', function() { if (dd.style.display==='block') positionDropdown(); }, true);
                window.addEventListener('resize', function() { if (dd.style.display==='block') positionDropdown(); });

                // Close on outside click
                document.addEventListener('click', function(e) {
                    if (!wrap.contains(e.target) && !dd.contains(e.target)) {
                        dd.style.display = 'none';
                        const active = opts.find(o => o.classList.contains('bm-opt-active'));
                        input.value = active ? active.dataset.label : '';
                    }
                });
            })();
            </script>

            <!-- ── Global Official Term Duration ──────────────── -->
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="save_official_term">
                <input type="hidden" name="barangay_id" value="<?= $bmSelBrgyId ?>">
                <?= csrfField() ?>
                <div class="settings-card">
                    <div class="sc-header">
                        <div class="sc-icon"><i class="bi bi-calendar3"></i></div>
                        <div>
                            <h6>Officials Term Duration</h6>
                            <p>Global default term length applied to all barangay officials</p>
                        </div>
                    </div>
                    <div class="sc-body">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small">Term Duration <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="number" name="official_term_years" class="form-control form-control-lg"
                                           value="<?= $bmTermYears ?>" min="1" max="6" required
                                           style="font-size:1.4rem;font-weight:700;text-align:center;"
                                           oninput="this.value=Math.max(1,Math.min(6,parseInt(this.value)||1))">
                                    <span class="input-group-text fw-semibold">year(s)</span>
                                </div>
                                <div class="form-text">Between 1 and 6 years &nbsp;·&nbsp; Used when auto-filling term end date for officials</div>
                            </div>
                            <div class="col-md-5">
                                <div class="p-3 rounded-3 d-flex align-items-center gap-3"
                                     style="background:rgba(26,79,122,0.06);border:1px solid rgba(26,79,122,0.12);">
                                    <i class="bi bi-info-circle-fill fs-5" style="color:#1a4f7a;flex-shrink:0;"></i>
                                    <span class="small" style="color:#1a4f7a;">
                                        When adding an official with a start date, the end date is auto-calculated
                                        as <strong>start + <?= $bmTermYears ?> year(s)</strong>.
                                    </span>
                                </div>
                            </div>
                            <div class="col-md-3 text-end">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-check-lg me-1"></i>Save Term
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>

            <?php if ($bmSelBrgy): ?>

            <!-- ── Barangay Portal Settings ────────────────────── -->
            <form method="POST" enctype="multipart/form-data" id="bmPortalForm">
                <input type="hidden" name="action" value="save_brgy_portal">
                <input type="hidden" name="barangay_id" value="<?= $bmSelBrgyId ?>">
                <?= csrfField() ?>
                <div class="settings-card">
                    <div class="sc-header">
                        <div class="sc-icon"><i class="bi bi-geo-alt-fill"></i></div>
                        <div>
                            <h6>Portal Settings — <?= htmlspecialchars($bmSelBrgy['barangay_name']) ?></h6>
                            <p>Edit this barangay's information and branding</p>
                        </div>
                    </div>
                    <div class="sc-body">
                        <!-- Logo upload strip -->
                        <?php
                        $bmLogoPath = $bmSelBrgy['logo_path'] ?? '';
                        $bmLogoUrl  = $bmLogoPath ? UPLOAD_URL . 'barangay_logos/' . $bmLogoPath : '';
                        ?>
                        <div class="d-flex align-items-center gap-4 mb-4 p-3 rounded-3" style="background:rgba(26,79,122,0.04);border:1px solid rgba(26,79,122,0.1);">
                            <div style="position:relative;width:80px;height:80px;flex-shrink:0;">
                                <div style="width:80px;height:80px;border-radius:12px;overflow:hidden;border:2px solid rgba(26,79,122,0.2);background:#e8f0f7;display:flex;align-items:center;justify-content:center;">
                                    <?php if ($bmLogoUrl): ?>
                                        <img id="bmLogoPreview" src="<?= htmlspecialchars($bmLogoUrl) ?>" alt="Logo"
                                             style="width:100%;height:100%;object-fit:cover;">
                                    <?php else: ?>
                                        <img id="bmLogoPreview" src="" style="width:100%;height:100%;object-fit:cover;display:none;" alt="">
                                        <i class="bi bi-buildings fs-3" style="color:#3a7fa8;"></i>
                                    <?php endif; ?>
                                </div>
                                <label for="bmLogoInput" title="Change logo"
                                       style="position:absolute;bottom:-6px;right:-6px;width:26px;height:26px;border-radius:50%;background:#1a4f7a;border:2px solid #fff;color:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 2px 6px rgba(0,0,0,0.25);font-size:0.72rem;">
                                    <i class="bi bi-pencil-fill"></i>
                                </label>
                                <input type="file" id="bmLogoInput" name="barangay_logo" accept="image/*"
                                       style="display:none;"
                                       onchange="bmPreviewLogo(this)">
                            </div>
                            <div class="flex-grow-1">
                                <div class="fw-semibold"><?= htmlspecialchars($bmSelBrgy['barangay_name']) ?> Logo</div>
                                <div class="small text-muted">JPG, PNG, WEBP · Max 2 MB · Recommended: 200×200px</div>
                                <?php if ($bmLogoPath): ?>
                                <div class="mt-2">
                                    <input type="hidden" name="remove_logo" id="bmRemoveLogoInput" value="0">
                                    <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size:0.72rem;"
                                            onclick="if(confirm('Remove this barangay logo?')) { document.getElementById('bmRemoveLogoInput').value='1'; document.getElementById('bmLogoPreview').src=''; document.getElementById('bmLogoPreview').style.display='none'; this.style.display='none'; }">
                                        <i class="bi bi-trash3 me-1"></i>Remove Logo
                                    </button>
                                </div>
                                <?php else: ?>
                                <input type="hidden" name="remove_logo" value="0">
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Fields -->
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">Barangay Name <span class="text-danger">*</span></label>
                                <input type="text" name="barangay_name" class="form-control" required
                                       value="<?= htmlspecialchars($bmSelBrgy['barangay_name']) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">Municipality / City</label>
                                <input type="text" name="municipality" class="form-control"
                                       value="<?= htmlspecialchars($bmSelBrgy['municipality'] ?? '') ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small">Region</label>
                                <input type="text" name="region" class="form-control"
                                       value="<?= htmlspecialchars($bmSelBrgy['region'] ?? '') ?>"
                                       placeholder="e.g. Region III">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small">Province</label>
                                <input type="text" name="province" class="form-control"
                                       value="<?= htmlspecialchars($bmSelBrgy['province'] ?? '') ?>"
                                       placeholder="e.g. Nueva Ecija">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label fw-semibold small">ZIP Code</label>
                                <input type="text" name="zip_code" class="form-control"
                                       value="<?= htmlspecialchars($bmSelBrgy['zip_code'] ?? '') ?>"
                                       placeholder="e.g. 3114" maxlength="10">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label fw-semibold small">Status</label>
                                <select name="status" class="form-select">
                                    <option value="active"   <?= ($bmSelBrgy['status'] ?? '') === 'active'   ? 'selected' : '' ?>>Active</option>
                                    <option value="inactive" <?= ($bmSelBrgy['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                </select>
                            </div>
                        </div>
                        <div class="d-flex justify-content-end mt-4">
                            <button type="submit" class="btn btn-primary px-4">
                                <i class="bi bi-check-lg me-1"></i>Save Portal Settings
                            </button>
                        </div>
                    </div>
                </div>
            </form>

            <!-- ── Officials Manager ─────────────────────────── -->
            <div class="settings-card">
                <div class="sc-header">
                    <div class="sc-icon"><i class="bi bi-person-badge-fill"></i></div>
                    <div>
                        <h6>Officials — <?= htmlspecialchars($bmSelBrgy['barangay_name']) ?></h6>
                        <p>Manage barangay officials, positions, and terms</p>
                    </div>
                    <button type="button" class="btn btn-primary btn-sm ms-auto"
                            onclick="bmOpenAddModal()">
                        <i class="bi bi-plus-lg me-1"></i>Add Official
                    </button>
                </div>
                <div class="sc-body p-0">
                    <?php if ($bmOfficials): ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" style="font-size:0.85rem;">
                            <thead style="background:rgba(26,79,122,0.05);">
                                <tr>
                                    <th class="ps-3" style="font-weight:600;font-size:0.75rem;color:#3a7fa8;text-transform:uppercase;letter-spacing:0.04em;">Official</th>
                                    <th style="font-weight:600;font-size:0.75rem;color:#3a7fa8;text-transform:uppercase;letter-spacing:0.04em;">Position</th>
                                    <th style="font-weight:600;font-size:0.75rem;color:#3a7fa8;text-transform:uppercase;letter-spacing:0.04em;">Term</th>
                                    <th style="font-weight:600;font-size:0.75rem;color:#3a7fa8;text-transform:uppercase;letter-spacing:0.04em;">Contact</th>
                                    <th style="font-weight:600;font-size:0.75rem;color:#3a7fa8;text-transform:uppercase;letter-spacing:0.04em;">Status</th>
                                    <th class="text-end pe-3" style="font-weight:600;font-size:0.75rem;color:#3a7fa8;text-transform:uppercase;letter-spacing:0.04em;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($bmOfficials as $off):
                                $today = date('Y-m-d');
                                $isExpired = $off['term_end'] && $off['term_end'] < $today;
                            ?>
                                <tr>
                                    <td class="ps-3">
                                        <div class="d-flex align-items-center gap-2">
                                            <?php if (!empty($off['photo'])): ?>
                                            <img src="<?= UPLOAD_URL . htmlspecialchars($off['photo']) ?>" alt=""
                                                 style="width:36px;height:36px;border-radius:50%;object-fit:cover;border:2px solid rgba(26,79,122,0.2);">
                                            <?php else: ?>
                                            <div style="width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#1a4f7a,#3a7fa8);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                                <i class="bi bi-person-fill text-white" style="font-size:0.85rem;"></i>
                                            </div>
                                            <?php endif; ?>
                                            <span class="fw-semibold"><?= htmlspecialchars($off['full_name']) ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge" style="background:rgba(26,79,122,0.1);color:#1a4f7a;font-size:0.72rem;font-weight:600;padding:4px 8px;">
                                            <?= htmlspecialchars($off['position']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($off['term_start'] || $off['term_end']): ?>
                                        <span class="small <?= $isExpired ? 'text-danger' : 'text-muted' ?>">
                                            <?= $off['term_start'] ? date('M j, Y', strtotime($off['term_start'])) : '?' ?>
                                            – <?= $off['term_end'] ? date('M j, Y', strtotime($off['term_end'])) : '?' ?>
                                            <?php if ($isExpired): ?><i class="bi bi-exclamation-triangle-fill ms-1" title="Term expired"></i><?php endif; ?>
                                        </span>
                                        <?php else: ?>
                                        <span class="text-muted small">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small text-muted">
                                        <?= $off['contact'] ? htmlspecialchars($off['contact']) : '—' ?>
                                    </td>
                                    <td>
                                        <?php if ($off['status'] === 'active'): ?>
                                        <span class="badge" style="background:#d1fae5;color:#065f46;font-size:0.7rem;padding:4px 8px;">Active</span>
                                        <?php else: ?>
                                        <span class="badge" style="background:#f1f5f9;color:#64748b;font-size:0.7rem;padding:4px 8px;">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end pe-3">
                                        <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2 me-1"
                                                style="font-size:0.75rem;"
                                                onclick="bmOpenEditModal(<?= htmlspecialchars(json_encode([
                                                    'id'         => $off['id'],
                                                    'full_name'  => $off['full_name'],
                                                    'position'   => $off['position'],
                                                    'term_start' => $off['term_start'] ?? '',
                                                    'term_end'   => $off['term_end']   ?? '',
                                                    'contact'    => $off['contact']    ?? '',
                                                    'email'      => $off['email']      ?? '',
                                                    'status'     => $off['status'],
                                                ]), ENT_QUOTES) ?>)">
                                            <i class="bi bi-pencil-fill me-1"></i>Edit
                                        </button>
                                        <form method="POST" class="d-inline"
                                              onsubmit="return confirm('Deactivate this official?')">
                                            <input type="hidden" name="action" value="remove_official">
                                            <input type="hidden" name="barangay_id" value="<?= $bmSelBrgyId ?>">
                                            <input type="hidden" name="official_id" value="<?= $off['id'] ?>">
                                            <?= csrfField() ?>
                                            <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2"
                                                    style="font-size:0.75rem;">
                                                <i class="bi bi-person-x-fill me-1"></i>Remove
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-5 text-muted">
                        <div style="font-size:3rem;opacity:0.25;margin-bottom:12px;"><i class="bi bi-person-badge"></i></div>
                        <div class="fw-semibold">No officials yet</div>
                        <div class="small">Click <strong>Add Official</strong> to add the first official for this barangay.</div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php else: ?>
            <!-- No barangay selected placeholder -->
            <div class="settings-card">
                <div class="sc-body text-center py-5">
                    <div style="font-size:4rem;opacity:0.18;margin-bottom:16px;"><i class="bi bi-buildings"></i></div>
                    <h6 class="fw-semibold text-muted">Select a Barangay Above</h6>
                    <p class="text-muted small mb-0">Choose a barangay from the dropdown to manage its portal settings and officials.</p>
                </div>
            </div>
            <?php endif; ?>

            <!-- ══════════════════════════════════════════
                 ADD / EDIT OFFICIAL MODAL
            ══════════════════════════════════════════ -->
            <div id="bmOfficialModal" style="display:none;position:fixed;inset:0;z-index:9998;background:rgba(0,0,0,0.45);backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:16px;">
                <div style="background:#fff;border-radius:20px;width:100%;max-width:560px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
                    <!-- Header -->
                    <div style="background:linear-gradient(135deg,#1a4f7a,#3a7fa8);padding:20px 24px;display:flex;align-items:center;justify-content:space-between;border-radius:20px 20px 0 0;">
                        <div class="d-flex align-items-center gap-3">
                            <div style="width:38px;height:38px;background:rgba(255,255,255,0.15);border-radius:10px;display:flex;align-items:center;justify-content:center;">
                                <i class="bi bi-person-badge-fill text-white fs-5"></i>
                            </div>
                            <div>
                                <div class="fw-bold text-white" id="bmModalTitle">Add Official</div>
                                <div style="font-size:0.72rem;color:rgba(255,255,255,0.75);" id="bmModalSubtitle">Fill in the official's information</div>
                            </div>
                        </div>
                        <button onclick="bmCloseModal()" style="background:rgba(255,255,255,0.15);border:none;border-radius:8px;width:32px;height:32px;color:#fff;font-size:1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                    <!-- Body -->
                    <form method="POST" id="bmOfficialForm" style="padding:24px;">
                        <input type="hidden" name="barangay_id" value="<?= $bmSelBrgyId ?>">
                        <input type="hidden" name="action" id="bmFormAction" value="add_official">
                        <input type="hidden" name="official_id" id="bmOfficialId" value="">
                        <?= csrfField() ?>

                        <!-- Add mode: split name fields -->
                        <div id="bmAddNameFields">
                            <div class="row g-3 mb-3">
                                <div class="col-md-5">
                                    <label class="form-label fw-semibold small">First Name <span class="text-danger">*</span></label>
                                    <input type="text" name="first_name" id="bmFirstName" class="form-control">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold small">Middle Name</label>
                                    <input type="text" name="middle_name" id="bmMiddleName" class="form-control">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold small">Last Name <span class="text-danger">*</span></label>
                                    <input type="text" name="last_name" id="bmLastName" class="form-control">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold small">Suffix</label>
                                    <input type="text" name="suffix" id="bmSuffix" class="form-control" placeholder="Jr., Sr., III">
                                </div>
                            </div>
                        </div>

                        <!-- Edit mode: single full_name field -->
                        <div id="bmEditNameField" style="display:none;" class="mb-3">
                            <label class="form-label fw-semibold small">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="full_name" id="bmFullName" class="form-control"
                                   placeholder="Lastname, Firstname Middlename">
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-8">
                                <label class="form-label fw-semibold small">Position <span class="text-danger">*</span></label>
                                <select name="position" id="bmPosition" class="form-select" required>
                                    <option value="">— Select Position —</option>
                                    <option value="Barangay Captain">Barangay Captain</option>
                                    <option value="Barangay Secretary">Barangay Secretary</option>
                                    <option value="Barangay Treasurer">Barangay Treasurer</option>
                                    <option value="Barangay Kagawad">Barangay Kagawad</option>
                                    <option value="SK Chairperson">SK Chairperson</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small">Status</label>
                                <select name="off_status" id="bmOffStatus" class="form-select">
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">Term Start</label>
                                <input type="date" name="term_start" id="bmTermStart" class="form-control"
                                       onchange="bmAutoFillTermEnd(this.value)">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">Term End
                                    <span class="text-muted" style="font-size:0.7rem;font-weight:400;">(auto-filled from start)</span>
                                </label>
                                <input type="date" name="term_end" id="bmTermEnd" class="form-control">
                            </div>
                        </div>

                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">Contact Number</label>
                                <input type="text" name="contact" id="bmContact" class="form-control"
                                       placeholder="09XXXXXXXXX" maxlength="11" inputmode="numeric"
                                       oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">Email Address</label>
                                <input type="email" name="email" id="bmEmail" class="form-control"
                                       placeholder="official@email.com">
                            </div>
                        </div>

                        <div class="d-flex justify-content-end gap-2">
                            <button type="button" class="btn btn-outline-secondary px-4" onclick="bmCloseModal()">
                                Cancel
                            </button>
                            <button type="submit" class="btn btn-primary px-4">
                                <i class="bi bi-check-lg me-1"></i><span id="bmSubmitLabel">Add Official</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <script>
            const BM_TERM_YEARS = <?= $bmTermYears ?>;

            function bmAutoFillTermEnd(startVal) {
                if (!startVal) return;
                const d = new Date(startVal);
                d.setFullYear(d.getFullYear() + BM_TERM_YEARS);
                // Format as YYYY-MM-DD
                const y = d.getFullYear();
                const m = String(d.getMonth()+1).padStart(2,'0');
                const day = String(d.getDate()).padStart(2,'0');
                document.getElementById('bmTermEnd').value = `${y}-${m}-${day}`;
            }

            function bmOpenAddModal() {
                document.getElementById('bmModalTitle').textContent    = 'Add Official';
                document.getElementById('bmModalSubtitle').textContent = 'Fill in the official\'s information';
                document.getElementById('bmSubmitLabel').textContent   = 'Add Official';
                document.getElementById('bmFormAction').value          = 'add_official';
                document.getElementById('bmOfficialId').value          = '';

                // Show add fields, hide edit
                document.getElementById('bmAddNameFields').style.display = '';
                document.getElementById('bmEditNameField').style.display = 'none';

                // Clear inputs
                ['bmFirstName','bmMiddleName','bmLastName','bmSuffix','bmPosition',
                 'bmTermStart','bmTermEnd','bmContact','bmEmail'].forEach(id => {
                    const el = document.getElementById(id);
                    if (el) el.value = '';
                });
                document.getElementById('bmOffStatus').value = 'active';

                document.getElementById('bmOfficialModal').style.display = 'flex';
            }

            function bmOpenEditModal(data) {
                document.getElementById('bmModalTitle').textContent    = 'Edit Official';
                document.getElementById('bmModalSubtitle').textContent = 'Update official information';
                document.getElementById('bmSubmitLabel').textContent   = 'Save Changes';
                document.getElementById('bmFormAction').value          = 'edit_official';
                document.getElementById('bmOfficialId').value          = data.id;

                // Hide add fields, show edit
                document.getElementById('bmAddNameFields').style.display = 'none';
                document.getElementById('bmEditNameField').style.display = '';

                document.getElementById('bmFullName').value  = data.full_name  || '';
                document.getElementById('bmPosition').value  = data.position   || '';
                document.getElementById('bmTermStart').value = data.term_start || '';
                document.getElementById('bmTermEnd').value   = data.term_end   || '';
                document.getElementById('bmContact').value   = data.contact    || '';
                document.getElementById('bmEmail').value     = data.email      || '';
                document.getElementById('bmOffStatus').value = data.status     || 'active';

                document.getElementById('bmOfficialModal').style.display = 'flex';
            }

            function bmCloseModal() {
                document.getElementById('bmOfficialModal').style.display = 'none';
            }

            // Close on backdrop click
            document.getElementById('bmOfficialModal').addEventListener('click', function(e) {
                if (e.target === this) bmCloseModal();
            });

            // Logo preview
            function bmPreviewLogo(input) {
                if (!input.files || !input.files[0]) return;
                const reader = new FileReader();
                reader.onload = function(e) {
                    const prev = document.getElementById('bmLogoPreview');
                    prev.src           = e.target.result;
                    prev.style.display = 'block';
                };
                reader.readAsDataURL(input.files[0]);
            }
            </script>

            <?php endif; // end tab === barangay_management ?>

        </div><!-- end settings-content -->
        <?php if ($isAjax): echo ob_get_clean(); exit; endif; ?>
    </div><!-- end settings-wrap -->
</div><!-- end main-content -->

<!-- ══════════════════════════════════════════════════════════
     CHANGE PASSWORD MODAL
══════════════════════════════════════════════════════════ -->
<div id="changePasswordModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.5);backdrop-filter:blur(4px);align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:20px;width:100%;max-width:460px;box-shadow:0 20px 60px rgba(0,0,0,0.3);overflow:hidden;margin:16px;">

        <!-- Modal Header -->
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

        <!-- Modal Body -->
        <div style="padding:28px;">
            <div id="cpAlert" style="display:none;padding:10px 14px;border-radius:8px;font-size:0.85rem;margin-bottom:16px;display:none;align-items:center;gap:8px;"></div>

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
                <small id="cpStrLabel" style="font-size:0.72rem;"></small>
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
                    <button class="btn btn-danger btn-sm px-4" id="cpConfirmBtn" onclick="doChangePassword()">
                        <i class="bi bi-check-lg me-1"></i>Yes, Change It
                    </button>
                    <button class="btn btn-outline-secondary btn-sm" onclick="document.getElementById('cpConfirmStep').style.display='none';document.getElementById('cpSubmitBtn').style.display='block';">
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
    minLen:  <?= max(6, (int)$s('password_min_length', '8')) ?>,
    upper:   <?= $s('password_require_upper',   '1') === '1' ? 'true' : 'false' ?>,
    number:  <?= $s('password_require_number',  '1') === '1' ? 'true' : 'false' ?>,
    special: <?= $s('password_require_special', '0') === '1' ? 'true' : 'false' ?>,
};
function validatePasswordPolicyJS(pw) {
    if (pw.length < PW_POLICY.minLen) return `Password must be at least ${PW_POLICY.minLen} characters.`;
    if (PW_POLICY.upper   && !/[A-Z]/.test(pw))    return 'Password must contain at least one uppercase letter.';
    if (PW_POLICY.number  && !/[0-9]/.test(pw))    return 'Password must contain at least one number.';
    if (PW_POLICY.special && !/[\W_]/.test(pw))    return 'Password must contain at least one special character (e.g. !@#$%).';
    return '';
}

// ── Change Password Modal ──────────────────────────────────
function closeCPModal() {
    document.getElementById('changePasswordModal').style.display = 'none';
    // Reset form
    ['cpCurrent','cpNew','cpConfirm'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('cpAlert').style.display = 'none';
    document.getElementById('cpConfirmStep').style.display = 'none';
    document.getElementById('cpSubmitBtn').style.display = 'block';
    document.getElementById('cpStrBar').style.width = '0%';
    document.getElementById('cpStrLabel').textContent = '';
}

// Close on backdrop click
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
    const l = lvls[Math.max(0, score - 1)];
    bar.style.width      = val.length ? l.w  : '0%';
    bar.style.background = l.bg;
    lbl.textContent      = val.length ? l.t  : '';
    lbl.style.color      = l.bg;
}

function showCPConfirm() {
    const current = document.getElementById('cpCurrent').value;
    const newPw   = document.getElementById('cpNew').value;
    const confirm = document.getElementById('cpConfirm').value;
    const alertEl = document.getElementById('cpAlert');

    alertEl.style.display = 'none';

    if (!current || !newPw || !confirm) {
        showCPAlert('danger', 'All fields are required.'); return;
    }
    if (newPw !== confirm) {
        showCPAlert('danger', 'New passwords do not match.'); return;
    }
    const cpErr = validatePasswordPolicyJS(newPw);
    if (cpErr) { showCPAlert('danger', cpErr); return; }

    // Show confirmation step
    document.getElementById('cpSubmitBtn').style.display = 'none';
    document.getElementById('cpConfirmStep').style.display = 'block';
}

function doChangePassword() {
    const btn = document.getElementById('cpConfirmBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Changing...';

    fetch('<?= BASE_URL ?>/super_admin/settings.php', {
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
            document.getElementById('cpConfirmStep').style.display = 'none';
            showCPAlert('success', '<i class="bi bi-check-circle-fill me-1"></i>' + data.msg);
            // Log out after 2 seconds
            setTimeout(() => { window.location.href = '<?= BASE_URL ?>/logout.php'; }, 2000);
        } else {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Yes, Change It';
            document.getElementById('cpConfirmStep').style.display = 'none';
            document.getElementById('cpSubmitBtn').style.display = 'block';
            showCPAlert('danger', data.msg);
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Yes, Change It';
        showCPAlert('danger', 'Something went wrong. Please try again.');
    });
}

function showCPAlert(type, msg) {
    const el = document.getElementById('cpAlert');
    const colors = {
        danger:  {bg:'#fef2f2',border:'#fecaca',color:'#dc2626'},
        success: {bg:'#f0f8ff',border:'#b8e8f0',color:'#0d3a5c'},
        warning: {bg:'#fffbeb',border:'#fde68a',color:'#92400e'},
    };
    const c = colors[type] || colors.danger;
    el.style.cssText = `display:flex;align-items:center;gap:8px;padding:10px 14px;border-radius:8px;font-size:0.85rem;margin-bottom:16px;background:${c.bg};border:1px solid ${c.border};color:${c.color};`;
    el.innerHTML = msg;
}

function toggleSidebar() { document.getElementById('sidebar').classList.toggle('show'); }

// ── Super Admin profile photo: preview changes until Save Profile is submitted ──
function previewSAPhoto(input) {
    const preview    = document.getElementById('saPhotoPreview');
    const initials   = document.getElementById('saPhotoInitials');
    const errEl      = document.getElementById('saPhotoSizeErr');
    const statusEl   = document.getElementById('saPhotoUploadStatus');
    const savedSrc   = preview.dataset.savedSrc || '';
    const removeField = document.getElementById('saRemoveProfilePhoto');
    const removeWrap = document.getElementById('saRemovePhotoWrap');
    const file = input.files?.[0];

    errEl.style.display    = 'none';
    if (!file) return;

    const extension = file.name.split('.').pop().toLowerCase();
    if (!['jpg', 'jpeg', 'jfif', 'png', 'gif', 'webp'].includes(extension)) {
        errEl.textContent = 'Choose a JPG, JFIF, PNG, GIF, or WEBP image.';
        errEl.style.display = 'block';
        input.value = '';
        return;
    }

    if (file.size > 2 * 1024 * 1024) {
        errEl.textContent   = 'File exceeds 2 MB limit. Please choose a smaller image.';
        errEl.style.display = 'block';
        input.value         = '';
        return;
    }

    removeField.value = '0';
    const removeButton = removeWrap?.querySelector('button');
    if (removeButton) removeButton.innerHTML = '<i class="bi bi-trash3 me-1"></i>Remove Photo';

    const reader = new FileReader();
    reader.onload = function(e) {
        preview.src           = e.target.result;
        preview.style.display = 'block';
        if (initials) initials.style.display = 'none';
        statusEl.textContent  = 'Preview only — click Save Profile to apply this photo.';
        statusEl.style.color  = '#3a7fa8';
        statusEl.style.display = 'block';
        if (removeWrap && savedSrc) removeWrap.style.display = 'block';
    };
    reader.onerror = function() {
        statusEl.textContent = 'Could not read the selected image. Please try another file.';
        statusEl.style.color = '#dc2626';
        statusEl.style.display = 'block';
        input.value = '';
    };
    reader.readAsDataURL(file);
}

function toggleSAPhotoRemoval() {
    const removeField = document.getElementById('saRemoveProfilePhoto');
    const input      = document.getElementById('saProfilePhotoInput');
    const preview    = document.getElementById('saPhotoPreview');
    const initials   = document.getElementById('saPhotoInitials');
    const removeWrap = document.getElementById('saRemovePhotoWrap');
    const statusEl   = document.getElementById('saPhotoUploadStatus');
    const removeButton = removeWrap?.querySelector('button');
    const savedSrc   = preview.dataset.savedSrc || '';

    if (removeField.value !== '1') {
        input.value = '';
        removeField.value = '1';
        preview.removeAttribute('src');
        preview.style.display = 'none';
        if (initials) initials.style.display = 'flex';
        if (removeButton) removeButton.innerHTML = '<i class="bi bi-arrow-counterclockwise me-1"></i>Undo Remove';
        statusEl.textContent = 'Photo removal is pending — click Save Profile to apply it.';
    } else {
        removeField.value = '0';
        preview.src = savedSrc;
        preview.style.display = savedSrc ? 'block' : 'none';
        if (initials) initials.style.display = savedSrc ? 'none' : 'flex';
        if (removeButton) removeButton.innerHTML = '<i class="bi bi-trash3 me-1"></i>Remove Photo';
        statusEl.textContent = 'Photo removal cancelled.';
    }
    statusEl.style.color = '#3a7fa8';
    statusEl.style.display = 'block';
}

<?php if ($autoOpenChangePw): ?>
// Auto-open change password modal from topbar link
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('changePasswordModal').style.display = 'flex';
});
<?php endif; ?>

// Image preview helpers
function previewImg(input, previewId) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => { document.getElementById(previewId).src = e.target.result; };
        reader.readAsDataURL(input.files[0]);
    }
}
function previewImgBanner(input, previewId) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => {
            const el = document.getElementById(previewId);
            if (el.tagName === 'IMG') {
                el.src = e.target.result;
            } else {
                // Replace div with img
                const img = document.createElement('img');
                img.src = e.target.result;
                img.id = previewId;
                img.className = 'img-preview-banner mb-2';
                el.replaceWith(img);
            }
        };
        reader.readAsDataURL(input.files[0]);
    }
}

// Appearance: theme selector
function selectTheme(theme) {
    document.getElementById('themeInput').value = theme;
    document.querySelectorAll('.theme-option').forEach(el => {
        el.style.border = '2px solid rgba(0,0,0,0.1)';
    });
    document.getElementById('theme' + theme.charAt(0).toUpperCase() + theme.slice(1)).style.border = '2px solid #1a4f7a';
    // Apply immediately
    if (theme === 'dark') {
        document.documentElement.classList.add('dark-mode');
        localStorage.setItem('darkMode', '1');
    } else {
        document.documentElement.classList.remove('dark-mode');
        localStorage.setItem('darkMode', '0');
    }
}

// Appearance: font size
const fontStepsApp = [80, 90, 100, 110, 120, 130];
function updateFontSize(idx) {
    const val = fontStepsApp[parseInt(idx)];
    document.getElementById('fontSizeInput').value = val;
    document.getElementById('fontSizeDisplay').textContent = val + '%';
    document.documentElement.style.fontSize = val + '%';
    localStorage.setItem('fontSize', val);
}
</script>

<script>
/* ── Settings AJAX tab switching — prefetch on hover for instant feel ── */
(function () {
    const BASE = '<?= BASE_URL ?>/super_admin/settings.php';
    const contentEl = () => document.getElementById('settingsContent');
    let _currentFetch = null;
    const _cache = {};         // tab => html string cache
    const _inflight = {};      // tab => AbortController for prefetch

    // Prefetch a tab in the background and cache result
    function prefetch(tab) {
        if (_cache[tab] || _inflight[tab]) return;
        const ctrl = new AbortController();
        _inflight[tab] = ctrl;
        fetch(BASE + '?tab=' + encodeURIComponent(tab) + '&ajax=1', {
            signal: ctrl.signal,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.text())
        .then(html => {
            delete _inflight[tab];
            _cache[tab] = html;
        })
        .catch(() => { delete _inflight[tab]; });
    }

    // Wire hover prefetch on all tab links
    function wireHoverPrefetch() {
        document.querySelectorAll('#settingsSidebar .s-link[data-tab]').forEach(a => {
            if (a.dataset.prefetchWired) return;
            a.dataset.prefetchWired = '1';
            a.addEventListener('mouseenter', () => prefetch(a.dataset.tab));
            a.addEventListener('touchstart', () => prefetch(a.dataset.tab), {passive:true});
        });
    }

    function swapContent(tab, html) {
        const el2 = contentEl();
        const tmp = document.createElement('div');
        tmp.innerHTML = html;
        const inner = tmp.querySelector('#settingsContent');
        el2.innerHTML = inner ? inner.innerHTML : html;
        bindForms();
        el2.querySelectorAll('script').forEach(s => {
            try { (new Function(s.textContent))(); } catch(e) {}
        });
    }

    window.loadTab = function (tab, el) {
        // Cancel any in-flight click-fetch
        if (_currentFetch) { _currentFetch.abort(); _currentFetch = null; }

        // Active state — instant
        document.querySelectorAll('#settingsSidebar .s-link[data-tab]').forEach(a => a.classList.remove('active'));
        if (el) el.classList.add('active');

        history.pushState({tab}, '', '?tab=' + tab);

        // If already cached from prefetch — swap immediately, zero wait
        if (_cache[tab]) {
            swapContent(tab, _cache[tab]);
            // Invalidate cache entry so next visit re-fetches fresh data
            delete _cache[tab];
            return;
        }

        // If prefetch is already in-flight, wait for it
        if (_inflight[tab]) {
            _inflight[tab]._onDone = function(html) { swapContent(tab, html); };
            return;
        }

        // Otherwise fetch now
        const ctrl = new AbortController();
        _currentFetch = ctrl;
        fetch(BASE + '?tab=' + encodeURIComponent(tab) + '&ajax=1', {
            signal: ctrl.signal,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.text())
        .then(html => {
            _currentFetch = null;
            swapContent(tab, html);
        })
        .catch(err => {
            if (err.name === 'AbortError') return;
            window.location.href = BASE + '?tab=' + tab;
        });
    };

    // Intercept form POSTs inside the content area — submit via fetch, reload same tab
    function bindForms() {
        const el2 = contentEl();
        if (!el2) return;
        el2.querySelectorAll('form[method="post"], form[method="POST"]').forEach(form => {
            if (form.dataset.ajaxBound || form.hasAttribute('data-no-ajax')) return;
            form.dataset.ajaxBound = '1';
            form.addEventListener('submit', function (e) {
                // Skip forms that have file uploads with enctype multipart — let them POST normally
                // but re-intercept the redirect afterward
                const tab = new URLSearchParams(window.location.search).get('tab') || 'general';
                e.preventDefault();

                const formData = new FormData(form);
                const formAction = form.getAttribute('action') || BASE + '?tab=' + tab;
                fetch(formAction, {
                    method: 'POST',
                    body: formData
                })
                .then(r => {
                    // Server will redirect — just reload the current tab via AJAX
                    const activeLink = document.querySelector('#settingsSidebar .s-link.active[data-tab]');
                    const activeTab = activeLink ? activeLink.dataset.tab : tab;
                    loadTab(activeTab, activeLink);
                })
                .catch(() => {
                    // Fallback hard reload
                    window.location.href = BASE + '?tab=' + tab;
                });
            });
        });
    }

    // Back/forward button support
    window.addEventListener('popstate', function (e) {
        const tab = (e.state && e.state.tab) || 'general';
        loadTab(tab, document.querySelector('#settingsSidebar .s-link[data-tab="' + tab + '"]'));
    });

    // Bind forms + wire hover prefetch on initial load
    bindForms();
    wireHoverPrefetch();

    // Prefetch all tabs in the background after page is idle
    // so first click on any tab is instant
    if ('requestIdleCallback' in window) {
        requestIdleCallback(() => {
            ['general','profile','security','notifications','archive','appearance','authenticator'].forEach(t => {
                const cur = new URLSearchParams(window.location.search).get('tab') || 'general';
                if (t !== cur) prefetch(t);
            });
        }, { timeout: 3000 });
    }
})();
</script>
</body>
</html>
