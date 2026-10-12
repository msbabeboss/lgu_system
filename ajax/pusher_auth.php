<?php
/**
 * Pusher private-channel authentication endpoint.
 *
 * Pusher JS calls this automatically when subscribing to a
 * "private-*" channel. We verify the logged-in user may only
 * subscribe to their own channel: private-user-{their_id}.
 */
require_once __DIR__ . '/../config/config.php';
requireLogin();

header('Content-Type: application/json');

$pusher = getPusher();
if ($pusher === null) {
    http_response_code(503);
    echo json_encode(['error' => 'Pusher not configured']);
    exit;
}

$socketId    = $_POST['socket_id']    ?? '';
$channelName = $_POST['channel_name'] ?? '';

if (!$socketId || !$channelName) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing socket_id or channel_name']);
    exit;
}

// Only allow subscribing to the user's own channel
$u              = currentUser();
$expectedChannel = "private-user-{$u['id']}";

if ($channelName !== $expectedChannel) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$auth = $pusher->authorizeChannel($channelName, $socketId);
echo $auth;
