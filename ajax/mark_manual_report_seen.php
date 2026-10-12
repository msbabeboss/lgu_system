<?php
/**
 * ajax/mark_manual_report_seen.php
 * Called by the LGU super admin when they open a manual report.
 * Marks it as seen and sends a notification to the barangay admin who sent it.
 */
require_once __DIR__ . '/../config/config.php';
requireRole('super_admin');

header('Content-Type: application/json');

if (!verifyCsrfToken()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token.']);
    exit;
}

$db = Database::getInstance()->getConnection();
$u  = currentUser();
$id = (int)($_POST['report_id'] ?? 0);

if ($id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid report ID.']);
    exit;
}

// Ensure seen_at column exists
try {
    $db->exec("ALTER TABLE manual_reports ADD COLUMN seen_at DATETIME NULL DEFAULT NULL");
} catch (PDOException $e) { /* already exists */ }
try {
    $db->exec("ALTER TABLE manual_reports ADD COLUMN seen_by INT UNSIGNED NULL DEFAULT NULL");
} catch (PDOException $e) { /* already exists */ }

// Only mark if not already seen
$row = $db->prepare("SELECT id, seen_at, created_by, report_title, barangay_id FROM manual_reports WHERE id=? AND status='sent'");
$row->execute([$id]);
$report = $row->fetch();

if (!$report) {
    echo json_encode(['ok' => false, 'error' => 'Report not found.']);
    exit;
}

$alreadySeen = !empty($report['seen_at']);

if (!$alreadySeen) {
    $now = date('Y-m-d H:i:s');
    $db->prepare("UPDATE manual_reports SET seen_at=?, seen_by=? WHERE id=?")
       ->execute([$now, $u['id'], $id]);

    // Notify the barangay admin who sent the report
    $brgyName = $db->query("SELECT barangay_name FROM barangays WHERE id={$report['barangay_id']}")->fetchColumn() ?: 'Barangay';
    $seenByName = $u['name'];
    $title = htmlspecialchars($report['report_title']);

    sendNotification(
        (int)$report['created_by'],
        "👁️ The LGU has seen your manual report: <strong>$title</strong>.",
        'info',
        'transaction'
    );

    logActivity($u['id'], 'VIEW_MANUAL_REPORT', 'Reports', "Viewed manual report: {$report['report_title']} from $brgyName");
}

echo json_encode([
    'ok'          => true,
    'already_seen'=> $alreadySeen,
    'seen_at'     => $alreadySeen
        ? date('M d, Y h:i A', strtotime($report['seen_at']))
        : date('M d, Y h:i A'),
]);
