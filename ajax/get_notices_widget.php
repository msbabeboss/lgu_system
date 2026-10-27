<?php
/**
 * get_notices_widget.php
 * Returns the 3 latest Active notices for the dashboard announcements card.
 * Optimised: filters by status='Active', selects only needed columns, uses index.
 */
require_once __DIR__ . '/../config/config.php';
requireRole('barangay_admin');

header('Content-Type: application/json; charset=utf-8');

try {
    $db = Database::getInstance()->getConnection();

    $stmt = $db->prepare(
        "SELECT id, title, content, created_at
         FROM notices
         WHERE status = 'Active'
         ORDER BY created_at DESC
         LIMIT 3"
    );
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $notices = array_map(function ($n) {
        $excerpt = mb_substr($n['content'], 0, 80, 'UTF-8');
        if (mb_strlen($n['content'], 'UTF-8') > 80) {
            $excerpt .= '…';
        }
        return [
            'id'      => (int) $n['id'],
            'title'   => $n['title'],
            'excerpt' => $excerpt,
            'date'    => date('M d, Y', strtotime($n['created_at'])),
        ];
    }, $rows);

    echo json_encode(['success' => true, 'notices' => $notices]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
