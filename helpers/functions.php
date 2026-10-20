<?php
function sanitize(string $input): string {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

// ── CSRF Protection ────────────────────────────────────────
/**
 * Generate (or retrieve) the session CSRF token.
 * The token is tied to the session and does NOT rotate on every request —
 * rotation caused stale-token 403s when AJAX calls (e.g. mark-notifications-read)
 * validated the token and rotated it before a form POST could use the same token.
 * The token is regenerated only on login (session_regenerate_id) and logout.
 */
function generateCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify the submitted CSRF token against the session token.
 * Checks $_POST['csrf_token'] first, then the X-CSRF-Token header (for fetch/AJAX).
 */
function verifyCsrfToken(): bool {
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    if (!$sessionToken) return false;

    // Form POST field
    $submitted = $_POST['csrf_token'] ?? '';

    // AJAX header fallback (fetch() requests send X-CSRF-Token header)
    if (!$submitted) {
        $submitted = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    }

    return hash_equals($sessionToken, $submitted);
}

/**
 * Verify CSRF token or immediately abort with 403.
 * Call this at the top of every POST handler block.
 * Token is validated but NOT rotated — the same token remains valid for the
 * entire session so parallel AJAX calls and form submissions never conflict.
 */
function verifyCsrfOrAbort(): void {
    if (!verifyCsrfToken()) {
        http_response_code(403);
        // JSON response for AJAX / fetch() callers
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) ||
            isset($_SERVER['HTTP_X_CSRF_TOKEN']) ||
            (($_SERVER['HTTP_ACCEPT'] ?? '') && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token. Please refresh the page and try again.']);
        } else {
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8">
                  <style>body{font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f8f9fa;}
                  .box{text-align:center;padding:2.5rem;background:#fff;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.08);max-width:420px;}
                  h2{color:#c0392b;margin-bottom:.5rem;}p{color:#555;}</style></head>
                  <body><div class="box">
                    <h2>⚠️ 403 – Session Expired</h2>
                    <p>Your form session token has expired. This usually happens after a long period of inactivity.</p>
                    <p><a href="javascript:history.back()" style="color:#1a4f7a;font-weight:600;">← Go back and try again</a></p>
                  </div></body></html>';
        }
        exit;
    }
    // Token is intentionally NOT rotated here.
    // Rotation caused stale-token 403s because AJAX endpoints (mark-all-read, etc.)
    // would silently consume and rotate the token before the user submitted a form.
}

/**
 * Render a hidden CSRF input field — include inside every HTML form.
 * Usage: <?= csrfField() ?>
 */
function csrfField(): string {
    $token = generateCsrfToken();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES) . '">';
}

/**
 * Return the current CSRF token for use in fetch() / AJAX headers.
 * Usage: <meta name="csrf-token" content="<?= csrfMeta() ?>">
 */
function csrfMeta(): string {
    return generateCsrfToken();
}

function redirect(string $url): void {
    header("Location: $url");
    exit;
}

function flash(string $key, string $message, string $type = 'success'): void {
    $_SESSION['flash'][$key] = ['message' => $message, 'type' => $type];
}

function getFlash(string $key): ?array {
    if (isset($_SESSION['flash'][$key])) {
        $flash = $_SESSION['flash'][$key];
        unset($_SESSION['flash'][$key]);
        return $flash;
    }
    return null;
}

function logActivity(int $userId, string $action, string $module, string $description = ''): void {
    $db = Database::getInstance()->getConnection();
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $stmt = $db->prepare("INSERT INTO activity_logs (user_id, action, module, description, ip_address) VALUES (?,?,?,?,?)");
    $stmt->execute([$userId, $action, $module, $description, $ip]);
}

/**
 * Insert a notification for a user and push it in real-time via Pusher.
 *
 * @param int    $userId   Target user ID
 * @param string $message  Notification message (HTML inline tags allowed)
 * @param string $type     Visual type: 'info' | 'success' | 'warning' | 'danger'
 * @param string $category Semantic category used for bell icon differentiation:
 *                         'system'       — generic admin action (default)
 *                         'resident'     — resident added / edited
 *                         'certificate'  — certificate issued
 *                         'announcement' — LGU announcement / advisory broadcast
 *                         'transaction'  — requests, reports, blotter
 */
function sendNotification(int $userId, string $message, string $type = 'info', string $category = 'system'): void {
    $db = Database::getInstance()->getConnection();

    // ── Ensure category column exists ────────────────────────────────────────
    // MySQL 8.0+ supports IF NOT EXISTS; for older versions we check the
    // information schema instead, so this is safe on all MySQL/MariaDB versions.
    static $categoryChecked = false;
    if (!$categoryChecked) {
        $categoryChecked = true;
        try {
            // Check whether the column already exists
            $chk = $db->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = 'notifications'
                   AND COLUMN_NAME  = 'category'"
            );
            $chk->execute();
            if ((int)$chk->fetchColumn() === 0) {
                // Column absent — add it
                $db->exec("ALTER TABLE notifications
                    ADD COLUMN category VARCHAR(50) NOT NULL DEFAULT 'system'
                    COMMENT 'system|announcement|certificate|resident|transaction'");
                // Back-fill existing rows
                $db->exec("UPDATE notifications SET category = 'system' WHERE category = '' OR category IS NULL");
            }
        } catch (\Throwable $e) {
            error_log('[sendNotification] category column check/add failed: ' . $e->getMessage());
        }
    }

    $validCategories = ['system', 'resident', 'certificate', 'announcement', 'transaction'];
    if (!in_array($category, $validCategories, true)) {
        $category = 'system';
    }

    // ── Dedup guard: suppress identical notification inserted within the last 5 seconds ─
    try {
        $dedup = $db->prepare(
            "SELECT id FROM notifications
             WHERE user_id    = ?
               AND message    = ?
               AND type       = ?
               AND category   = ?
               AND created_at >= DATE_SUB(NOW(), INTERVAL 5 SECOND)
             LIMIT 1"
        );
        $dedup->execute([$userId, $message, $type, $category]);
        if ($dedup->fetchColumn() !== false) {
            return; // duplicate within the time window — skip insert
        }
    } catch (\Throwable $e) {
        // If the dedup check itself fails (e.g. category column absent), fall through
        error_log('[sendNotification] dedup check failed: ' . $e->getMessage());
    }

    // ── Insert — fall back to no-category INSERT if column still absent ──────
    try {
        $stmt = $db->prepare("INSERT INTO notifications (user_id, message, type, category) VALUES (?,?,?,?)");
        $stmt->execute([$userId, $message, $type, $category]);
    } catch (\Throwable $e) {
        // Likely the category column doesn't exist yet — insert without it
        try {
            $stmt = $db->prepare("INSERT INTO notifications (user_id, message, type) VALUES (?,?,?)");
            $stmt->execute([$userId, $message, $type]);
            error_log('[sendNotification] inserted without category column: ' . $e->getMessage());
        } catch (\Throwable $e2) {
            error_log('[sendNotification] insert failed entirely: ' . $e2->getMessage());
            return;
        }
    }

    // Real-time push via Pusher (no-op if Pusher not configured)
    $notifId   = (int)$db->lastInsertId();
    $createdAt = date('Y-m-d H:i:s');
    pushNotification($userId, $notifId, $message, $type, $createdAt, $category);
}

function getUnreadNotifications(int $userId): array {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 50");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function getAllNotifications(int $userId, int $limit = 50): array {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
    $stmt->execute([$userId, $limit]);
    return $stmt->fetchAll();
}

function countUnreadNotifications(int $userId): int {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Fan-out a notification to every active super admin in the system.
 * Optionally exclude one user ID (e.g. the acting super admin who triggered the event).
 *
 * @param string $message   Notification message (HTML inline tags allowed)
 * @param string $type      'info' | 'success' | 'warning' | 'danger'
 * @param string $category  Semantic category (resident / system / transaction / etc.)
 * @param int    $excludeId User ID to skip (0 = exclude nobody)
 */
function notifySuperAdmins(string $message, string $type = 'info', string $category = 'system', int $excludeId = 0): void {
    $db = Database::getInstance()->getConnection();
    try {
        $stmt = $db->prepare(
            "SELECT id FROM users WHERE role = 'super_admin' AND status = 'active'" .
            ($excludeId > 0 ? " AND id != $excludeId" : '')
        );
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $adminId) {
            sendNotification((int)$adminId, $message, $type, $category);
        }
    } catch (\Throwable $e) {
        error_log('[notifySuperAdmins] failed: ' . $e->getMessage());
    }
}

/**
 * Fan-out a notification to all active barangay admins in a given barangay,
 * optionally excluding one user (the acting admin who triggered the event).
 *
 * @param int    $barangayId  Barangay scope
 * @param string $message     Notification message
 * @param string $type        'info' | 'success' | 'warning' | 'danger'
 * @param string $category    Semantic category
 * @param int    $excludeId   User ID to skip (0 = exclude nobody)
 */
function notifyBarangayAdmins(int $barangayId, string $message, string $type = 'info', string $category = 'system', int $excludeId = 0): void {
    $db = Database::getInstance()->getConnection();
    try {
        $stmt = $db->prepare(
            "SELECT id FROM users WHERE role = 'barangay_admin' AND barangay_id = ? AND status = 'active'" .
            ($excludeId > 0 ? " AND id != $excludeId" : '')
        );
        $stmt->execute([$barangayId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $adminId) {
            sendNotification((int)$adminId, $message, $type, $category);
        }
    } catch (\Throwable $e) {
        error_log('[notifyBarangayAdmins] failed: ' . $e->getMessage());
    }
}

function calculateAge(string $birthdate): int {
    $birth = new DateTime($birthdate);
    $today = new DateTime();
    return $birth->diff($today)->y;
}

function generateCaseNumber(int $barangayId): string {
    $year = date('Y');
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT COUNT(*) FROM blotter_cases WHERE barangay_id = ? AND YEAR(created_at) = ?");
    $stmt->execute([$barangayId, $year]);
    $count = (int)$stmt->fetchColumn() + 1;
    return "BLT-{$barangayId}-{$year}-" . str_pad($count, 4, '0', STR_PAD_LEFT);
}

function generateHouseholdCode(int $barangayId): string {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("SELECT COUNT(*) FROM households WHERE barangay_id = ?");
    $stmt->execute([$barangayId]);
    $count = (int)$stmt->fetchColumn() + 1;
    return "HH-{$barangayId}-" . str_pad($count, 5, '0', STR_PAD_LEFT);
}

function generatePassword(int $length = 10): string {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789@#$!';
    return substr(str_shuffle(str_repeat($chars, 3)), 0, $length);
}

/**
 * Validate a plain-text password against the system_settings password policy.
 * Returns an error string on failure, empty string on success.
 *
 * Settings read:
 *   password_min_length      (default 8, clamped 6–32)
 *   password_require_upper   (default 1)
 *   password_require_number  (default 1)
 *   password_require_special (default 0)
 */
function validatePasswordPolicy(PDO $db, string $password): string {
    // Helper to fetch one system_setting safely
    $getSetting = static function(string $key, string $default) use ($db): string {
        try {
            $s = $db->prepare("SELECT setting_val FROM system_settings WHERE setting_key=?");
            $s->execute([$key]);
            $r = $s->fetchColumn();
            return $r !== false ? $r : $default;
        } catch (\Throwable $e) { return $default; }
    };

    $minLen  = max(6, min(32, (int)$getSetting('password_min_length',      '8')));
    $upper   = $getSetting('password_require_upper',   '1') === '1';
    $number  = $getSetting('password_require_number',  '1') === '1';
    $special = $getSetting('password_require_special', '0') === '1';

    if (strlen($password) < $minLen) {
        return "Password must be at least {$minLen} characters.";
    }
    if ($upper && !preg_match('/[A-Z]/', $password)) {
        return 'Password must contain at least one uppercase letter.';
    }
    if ($number && !preg_match('/[0-9]/', $password)) {
        return 'Password must contain at least one number.';
    }
    if ($special && !preg_match('/[\W_]/', $password)) {
        return 'Password must contain at least one special character (e.g. !@#$%).';
    }
    return '';
}

function formatDate(string $date): string {
    return date('F d, Y', strtotime($date));
}

function isAjax(): bool {
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function archiveRecord(int $userId, string $tableName, int $recordId, array $recordData, ?string $archiveReason = null): bool {
    $db = Database::getInstance()->getConnection();
    try {
        $stmt = $db->prepare("INSERT INTO archives (table_name, record_id, data_json, archived_by) VALUES (?, ?, ?, ?)");
        $stmt->execute([$tableName, $recordId, json_encode($recordData), $userId]);
        logActivity($userId, 'ARCHIVE_' . strtoupper($tableName), ucfirst($tableName), "Archived a " . ucfirst($tableName) . " record" . ($archiveReason ? " — Reason: $archiveReason" : ""));
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// ── Image Processing Helpers ───────────────────────────────

/**
 * Resize and save an uploaded image using GD.
 * Converts to WebP when the GD build supports it; falls back to JPEG otherwise.
 * Returns the saved filename (no path), or throws RuntimeException on failure.
 *
 * @param string $tmpPath    Path to the uploaded tmp file
 * @param string $mime       MIME type reported by finfo (image/jpeg|png|gif|webp)
 * @param string $destDir    Absolute directory to save into (trailing slash optional)
 * @param string $baseName   Filename without extension (e.g. "user_3_1713000000")
 * @param int    $maxWidth   Resize so width ≤ this value (proportional)
 * @param int    $maxHeight  Resize so height ≤ this value (proportional)
 * @param int    $quality    WebP quality 0-100 (default 82)
 * @return string            Saved filename including extension
 */
function resizeAndSaveImage(
    string $tmpPath,
    string $mime,
    string $destDir,
    string $baseName,
    int $maxWidth  = 200,
    int $maxHeight = 200,
    int $quality   = 82
): string {
    // Load source image
    $src = match ($mime) {
        'image/jpeg' => imagecreatefromjpeg($tmpPath),
        'image/png'  => imagecreatefrompng($tmpPath),
        'image/gif'  => imagecreatefromgif($tmpPath),
        'image/webp' => imagecreatefromwebp($tmpPath),
        default      => throw new \RuntimeException("Unsupported MIME type: $mime"),
    };
    if (!$src) {
        throw new \RuntimeException("Failed to open image from $tmpPath");
    }

    $origW = imagesx($src);
    $origH = imagesy($src);

    // Calculate scaled dimensions (preserve aspect ratio, never upscale)
    $scale = min(1.0, $maxWidth / $origW, $maxHeight / $origH);
    $newW  = (int)round($origW * $scale);
    $newH  = (int)round($origH * $scale);

    $dst = imagecreatetruecolor($newW, $newH);

    // Preserve transparency for PNG / GIF / WebP
    if (in_array($mime, ['image/png', 'image/gif', 'image/webp'], true)) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $newW, $newH, $transparent);
    }

    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
    imagedestroy($src);

    $destDir = rtrim($destDir, '/\\');

    // Save as WebP if available, otherwise JPEG
    if (function_exists('imagewebp')) {
        $filename = $baseName . '.webp';
        $saved    = imagewebp($dst, $destDir . DIRECTORY_SEPARATOR . $filename, $quality);
    } else {
        $filename = $baseName . '.jpg';
        $saved    = imagejpeg($dst, $destDir . DIRECTORY_SEPARATOR . $filename, $quality);
    }
    imagedestroy($dst);

    if (!$saved) {
        throw new \RuntimeException("Failed to write image to $destDir/$filename");
    }
    return $filename;
}
