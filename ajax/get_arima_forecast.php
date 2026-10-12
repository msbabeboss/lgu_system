<?php
/**
 * Proxy: PHP → Python ARIMA API
 * Auto-starts the API if it's not running.
 */
require_once __DIR__ . '/../config/config.php';
requireLogin();

header('Content-Type: application/json');

define('ARIMA_API',    'http://127.0.0.1:5001');
define('PYTHON_EXE',   'C:\\Users\\user\\AppData\\Local\\Python\\pythoncore-3.14-64\\python.exe');
define('ARIMA_SCRIPT', 'C:\\xampp\\htdocs\\lgu_system\\python\\arima_api.py');
define('VBS_LAUNCHER', 'C:\\Users\\' . get_current_user() . '\\AppData\\Roaming\\Microsoft\\Windows\\Start Menu\\Programs\\Startup\\LGU_ARIMA_API.vbs');

$type        = in_array($_GET['type'] ?? '', ['residents','blotter','certificates','all']) ? $_GET['type'] : 'all';
$months      = max(1, min(12, (int)($_GET['months']      ?? 6)));
$barangay_id = max(0, (int)($_GET['barangay_id'] ?? 0));
$lookback    = max(6, min(36, (int)($_GET['lookback']    ?? 24)));

$url = $type === 'all'
    ? ARIMA_API . "/forecast/all?barangay_id={$barangay_id}&months={$months}&lookback={$lookback}"
    : ARIMA_API . "/forecast?type={$type}&barangay_id={$barangay_id}&months={$months}&lookback={$lookback}";

// ── Try calling the API ───────────────────────────────────────
function callApi($url, $timeout = 10) {
    $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => true]]);
    return @file_get_contents($url, false, $ctx);
}

// ── Auto-start the API silently ───────────────────────────────
function autoStartApi() {
    // Method 1: Use the VBS launcher (hidden, no window)
    if (file_exists(VBS_LAUNCHER)) {
        @pclose(@popen('wscript.exe "' . VBS_LAUNCHER . '"', 'r'));
        return true;
    }
    // Method 2: Use pythonw.exe directly (no console window)
    $pythonw = str_replace('python.exe', 'pythonw.exe', PYTHON_EXE);
    if (file_exists($pythonw)) {
        $cmd = '"' . $pythonw . '" "' . ARIMA_SCRIPT . '"';
        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen('start /b "" ' . $cmd, 'r'));
        }
        return true;
    }
    // Method 3: Fallback to python.exe detached
    if (file_exists(PYTHON_EXE)) {
        $cmd = '"' . PYTHON_EXE . '" "' . ARIMA_SCRIPT . '"';
        pclose(popen('start /b "" ' . $cmd, 'r'));
        return true;
    }
    return false;
}

// ── First attempt ─────────────────────────────────────────────
$response = callApi($url, 8);

// ── If down, auto-start and retry ────────────────────────────
if ($response === false) {
    $started = autoStartApi();

    if ($started) {
        // Wait up to 8 seconds for the API to come up
        $maxWait = 8;
        $response = false;
        for ($i = 0; $i < $maxWait; $i++) {
            sleep(1);
            $response = callApi($url, 3);
            if ($response !== false) break;
        }
    }
}

// ── Still down after auto-start attempt ───────────────────────
if ($response === false) {
    http_response_code(503);
    echo json_encode([
        'error'        => 'ARIMA API could not be started automatically.',
        'hint'         => 'Please start it manually: double-click python/start_api.bat',
        'auto_started' => $started ?? false,
    ]);
    exit;
}

// ── Forward response ──────────────────────────────────────────
$httpCode = 200;
foreach ($http_response_header ?? [] as $h) {
    if (preg_match('/HTTP\/[\d.]+ (\d{3})/', $h, $m)) {
        $httpCode = (int)$m[1];
    }
}
http_response_code($httpCode);
echo $response;
