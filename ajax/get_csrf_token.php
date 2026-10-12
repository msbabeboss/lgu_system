<?php
/**
 * Returns the current session CSRF token as JSON.
 * Called by JS before programmatic form submissions and after failed login
 * attempts so the client always has the correct token without a page reload.
 *
 * NOTE: We no longer force-rotate the token here. Rotation caused 403 errors
 * because one AJAX call would generate a new token that invalidated the token
 * already baked into another open form on the same page.
 * The token is generated once per session (in generateCsrfToken) and is only
 * replaced when the user logs in/out (session_regenerate_id).
 */
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

echo json_encode(['csrf_token' => generateCsrfToken()]);
