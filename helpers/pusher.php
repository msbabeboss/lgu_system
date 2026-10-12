<?php
/**
 * Pusher helper — wraps the Pusher PHP SDK.
 *
 * Usage: getPusher()->trigger('channel', 'event', $data);
 *
 * Returns null if Pusher credentials are not configured, so callers
 * can safely skip the push without breaking anything.
 */

function getPusher(): ?\Pusher\Pusher {
    static $instance = null;

    $appId   = $_ENV['PUSHER_APP_ID']  ?? '';
    $key     = $_ENV['PUSHER_KEY']     ?? '';
    $secret  = $_ENV['PUSHER_SECRET']  ?? '';
    $cluster = $_ENV['PUSHER_CLUSTER'] ?? 'mt1';

    // Return null if not configured — graceful no-op
    if (!$appId || !$key || !$secret) {
        return null;
    }

    if ($instance === null) {
        $instance = new \Pusher\Pusher($key, $secret, $appId, [
            'cluster' => $cluster,
            'useTLS'  => true,
            'curl_options' => [
                CURLOPT_CONNECTTIMEOUT => 3,  // give up connecting after 3s
                CURLOPT_TIMEOUT        => 5,  // total request timeout 5s
            ],
        ]);
    }

    return $instance;
}

/**
 * Broadcast a notification event to a specific user's private channel.
 * Called automatically by sendNotification() in functions.php.
 */
function pushNotification(int $userId, int $notifId, string $message, string $type, string $createdAt, string $category = 'system'): void {
    $pusher = getPusher();
    if ($pusher === null) return;

    try {
        $pusher->trigger(
            "private-user-{$userId}",
            'new-notification',
            [
                'id'         => $notifId,
                'message'    => $message,
                'type'       => $type,
                'category'   => $category,
                'created_at' => $createdAt,
            ]
        );
    } catch (\Throwable $e) {
        // Never let a failed push break the main flow — log silently
        error_log('[Pusher] trigger failed: ' . $e->getMessage());
    }
}
