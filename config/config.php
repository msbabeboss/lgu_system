
<?php
define('APP_NAME', 'Talavera LGU Information Management System');
define('APP_VERSION', '1.0.0');

// ── Auto-detect BASE_URL — works on localhost AND ngrok/any tunnel ──
(function() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
              || str_ends_with($_SERVER['HTTP_HOST'] ?? '', '.ts.net')
              ? 'https' : 'http';
    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
    $host = trim(explode(',', $host)[0]); // first value if comma-separated

    // App lives at /lgu_system on localhost; at root on ngrok (no subfolder)
    // Detect by checking if SCRIPT_NAME contains /lgu_system/
    $script  = $_SERVER['SCRIPT_NAME'] ?? '';
    $appRoot = '';
    if (preg_match('#^(/lgu_system)/#', $script, $m)) {
        $appRoot = $m[1]; // localhost: /lgu_system
    }
    // ngrok serves at root — no subfolder needed

    define('BASE_URL', $scheme . '://' . $host . $appRoot);
})();

define('BASE_PATH', dirname(__DIR__));
define('UPLOAD_PATH', BASE_PATH . '/uploads/');
define('UPLOAD_URL', BASE_URL . '/uploads/');

// ── Timezone — must match MySQL SYSTEM timezone ────────────
date_default_timezone_set('Asia/Manila');

// ── Load .env ──────────────────────────────────────────────
$_envFile = BASE_PATH . '/.env';
if (file_exists($_envFile)) {
    foreach (file($_envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $_line) {
        if (str_starts_with(trim($_line), '#') || !str_contains($_line, '=')) continue;
        [$_k, $_v] = explode('=', $_line, 2);
        $_ENV[trim($_k)] = trim($_v);
    }
}

// ── Mail constants (read from .env) ───────────────────────
define('MAIL_HOST',       $_ENV['MAIL_HOST']       ?? 'smtp.gmail.com');
define('MAIL_PORT',       (int)($_ENV['MAIL_PORT'] ?? 587));
define('MAIL_USERNAME',   $_ENV['MAIL_USERNAME']   ?? '');
define('MAIL_PASSWORD',   $_ENV['MAIL_PASSWORD']   ?? '');
define('MAIL_FROM_NAME',  $_ENV['MAIL_FROM_NAME']  ?? APP_NAME);
define('MAIL_FROM_EMAIL', $_ENV['MAIL_FROM_EMAIL'] ?? '');

// ── Public URL for emails (overrides auto-detected BASE_URL) ──
// Set APP_URL in .env to your LAN IP or ngrok URL so reset links work outside localhost
define('EMAIL_BASE_URL', rtrim($_ENV['APP_URL'] ?? BASE_URL, '/'));

// ── Pusher constants ───────────────────────────────────────
define('PUSHER_APP_ID',  $_ENV['PUSHER_APP_ID']  ?? '');
define('PUSHER_KEY',     $_ENV['PUSHER_KEY']     ?? '');
define('PUSHER_SECRET',  $_ENV['PUSHER_SECRET']  ?? '');
define('PUSHER_CLUSTER', $_ENV['PUSHER_CLUSTER'] ?? 'mt1');

// Session config
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
// Detect HTTPS: standard header, X-Forwarded-Proto (ngrok/Tailscale), or Tailscale host suffix
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
           || str_ends_with($_SERVER['HTTP_HOST'] ?? '', '.ts.net');
ini_set('session.cookie_secure', $isHttps ? 1 : 0);
ini_set('session.cookie_samesite', $isHttps ? 'None' : 'Lax');
session_start();

// ── ngrok tunnel: skip the browser warning interstitial ───
if (!headers_sent()) {
    header('ngrok-skip-browser-warning: true');
}

// Autoload helpers
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/helpers/functions.php';
require_once BASE_PATH . '/helpers/auth.php';
require_once BASE_PATH . '/helpers/totp.php';

// Pusher real-time helper (loads SDK via composer autoload)
$_composerAutoload = BASE_PATH . '/vendor/autoload.php';
if (file_exists($_composerAutoload)) {
    require_once $_composerAutoload;
}
require_once BASE_PATH . '/helpers/pusher.php';
