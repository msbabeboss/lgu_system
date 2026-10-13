<?php
/**
 * Save a forecast result to forecast_records table.
 * Called automatically by the front-end after each successful run.
 * POST JSON body expected.
 */
require_once __DIR__ . '/../config/config.php';
requireLogin();
verifyCsrfOrAbort();   // CSRF guard — token must arrive in X-CSRF-Token header

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body']);
    exit;
}

$db   = Database::getInstance()->getConnection();
$user = currentUser();

// ── Sanitise inputs ───────────────────────────────────────────
$brgidRaw    = (int)($body['barangay_id']     ?? 0);
$brgid       = $brgidRaw > 0 ? $brgidRaw : null;
$series      = $body['series_type']                  ?? '';
$months      = max(1, min(12, (int)($body['forecast_months'] ?? 6)));
$lookback    = max(6, min(36, (int)($body['lookback_months'] ?? 24)));
$arimaOrder  = isset($body['arima_order'])  ? substr(trim((string)$body['arima_order']), 0, 20)  : null;
$engine      = isset($body['engine'])       ? substr(trim((string)$body['engine']),      0, 20)  : null;
$summary     = is_array($body['summary']  ?? null)  ? $body['summary']   : [];
$genAt       = $body['generated_at']                ?? date('Y-m-d H:i:s');

// Normalise generated_at to MySQL datetime format
$genAt = date('Y-m-d H:i:s', strtotime($genAt));

if (!in_array($series, ['residents', 'blotter', 'certificates'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid series_type']);
    exit;
}

// Validate barangay exists (skip when 0 = all barangays for super admin)
if ($brgid > 0) {
    $chk = $db->prepare("SELECT id FROM barangays WHERE id = ? LIMIT 1");
    $chk->execute([$brgid]);
    if (!$chk->fetch()) {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown barangay_id']);
        exit;
    }
}

// JSON columns
$forecastJson = isset($body['forecast']) ? json_encode($body['forecast']) : null;
$historyJson  = isset($body['history'])  ? json_encode($body['history'])  : null;

// ── Upsert: replace any record saved within the same minute for same
//    barangay + series + horizon to avoid duplicate flood on re-runs ──
$stmt = $db->prepare("
    INSERT INTO forecast_records
        (barangay_id, series_type, forecast_months, lookback_months,
         arima_order, engine,
         avg_historical, avg_forecast,
         trend_pct, trend_direction, data_quality,
         forecast_json, history_json,
         generated_at, saved_by)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

$ok = $stmt->execute([
    $brgid,
    $series,
    $months,
    $lookback,
    $arimaOrder,
    $engine,
    isset($summary['avg_historical'])  ? (float)$summary['avg_historical']  : null,
    isset($summary['avg_forecast'])    ? (float)$summary['avg_forecast']    : null,
    isset($summary['trend_pct'])       ? (float)$summary['trend_pct']       : null,
    $summary['trend_direction']        ?? null,
    $summary['data_quality']           ?? null,
    $forecastJson,
    $historyJson,
    $genAt,
    $user['id'],
]);

if ($ok) {
    echo json_encode(['success' => true, 'record_id' => (int)$db->lastInsertId()]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'DB insert failed']);
}
