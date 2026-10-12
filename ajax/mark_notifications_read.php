<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();
verifyCsrfOrAbort();

$db = Database::getInstance()->getConnection();
$u  = currentUser();

$db->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([$u['id']]);

header('Content-Type: application/json');
// Return the current CSRF token so the caller can refresh the meta tag
echo json_encode(['success' => true, 'csrf_token' => generateCsrfToken()]);
