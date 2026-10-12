<?php
/**
 * Background endpoint — sends notifications + emails for a notice.
 * Called via AJAX after page redirect so the user doesn't wait.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/mailer.php';
requireRole('super_admin');

header('Content-Type: application/json');

verifyCsrfOrAbort();

$db      = Database::getInstance()->getConnection();
$u       = currentUser();
$noticeId = (int)($_POST['notice_id'] ?? 0);

if (!$noticeId) {
    echo json_encode(['success' => false, 'message' => 'Missing notice_id']);
    exit;
}

// Fetch the notice
$stmt = $db->prepare("SELECT * FROM notices WHERE id = ? AND status != 'Archived'");
$stmt->execute([$noticeId]);
$notice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$notice) {
    echo json_encode(['success' => false, 'message' => 'Notice not found']);
    exit;
}

$title      = $notice['title'];
$content    = $notice['content'];
$noticeType = $notice['notice_type'];
$targetBrgy = (int)($notice['target_barangay_id'] ?? 0);

// Build recipient list
if ($targetBrgy > 0) {
    $adminsStmt = $db->prepare(
        "SELECT id, email, CONCAT(first_name,' ',last_name) AS name
         FROM users WHERE role='barangay_admin' AND status='active' AND barangay_id=?"
    );
    $adminsStmt->execute([$targetBrgy]);
    $admins = $adminsStmt->fetchAll(PDO::FETCH_ASSOC);
    $bq = $db->prepare("SELECT barangay_name FROM barangays WHERE id=?");
    $bq->execute([$targetBrgy]);
    $brgyLabel = $bq->fetchColumn() ?: "Barangay #$targetBrgy";
} else {
    $admins = $db->query(
        "SELECT id, email, CONCAT(first_name,' ',last_name) AS name
         FROM users WHERE role='barangay_admin' AND status='active'"
    )->fetchAll(PDO::FETCH_ASSOC);
    $brgyLabel = 'All Barangays';
}

$notifMsg    = "📢 New {$noticeType}: <strong>{$title}</strong>";
$notifFailed = 0;

// Send in-system notifications
foreach ($admins as $admin) {
    try {
        sendNotification((int)$admin['id'], $notifMsg, 'info', 'announcement');
    } catch (\Throwable $e) {
        $notifFailed++;
        error_log('[send_notice] sendNotification failed: ' . $e->getMessage());
    }
}

// Send emails
$emailList = array_values(array_filter($admins, fn($a) => !empty($a['email'])));
if (!empty($emailList)) {
    try {
        sendNoticeBroadcastEmails($emailList, $title, $content, $u['name']);
    } catch (\Throwable $e) {
        error_log('[send_notice] sendNoticeBroadcastEmails failed: ' . $e->getMessage());
    }
}

// Notify the super admin who sent it that delivery is done
$doneMsg = "✅ Announcement <strong>\"{$title}\"</strong> successfully sent to {$brgyLabel} — " . count($admins) . " admin(s) notified.";
try {
    sendNotification($u['id'], $doneMsg, 'success', 'system');
} catch (\Throwable $e) {
    error_log('[send_notice] done-notification failed: ' . $e->getMessage());
}

echo json_encode([
    'success'  => true,
    'sent'     => count($admins),
    'failed'   => $notifFailed,
    'doneMsg'  => $doneMsg,
]);
