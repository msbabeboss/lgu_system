<?php
require_once __DIR__ . '/../config/config.php';
requireRole('super_admin');

$db = Database::getInstance()->getConnection();
$attachmentId = (int)($_GET['id'] ?? 0);
if ($attachmentId < 1) {
    http_response_code(404);
    exit;
}

$stmt = $db->prepare("SELECT a.original_name, a.file_path
    FROM manual_report_attachments a
    JOIN manual_reports r ON r.id = a.report_id
    WHERE a.id = ? AND r.status = 'sent'");
$stmt->execute([$attachmentId]);
$attachment = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$attachment) {
    http_response_code(404);
    exit;
}

$reportsDir = realpath(UPLOAD_PATH . 'reports');
$filePath = realpath(UPLOAD_PATH . $attachment['file_path']);
if (!$reportsDir || !$filePath || !is_file($filePath)
    || strncasecmp($filePath, $reportsDir . DIRECTORY_SEPARATOR, strlen($reportsDir . DIRECTORY_SEPARATOR)) !== 0) {
    http_response_code(404);
    exit;
}

$mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($filePath);
$allowedMimes = [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp',
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'text/plain',
];
if (!in_array($mimeType, $allowedMimes, true)) {
    http_response_code(415);
    exit;
}

$inline = str_starts_with($mimeType, 'image/') || $mimeType === 'application/pdf';
$fallbackName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($attachment['original_name']));
if ($fallbackName === '') $fallbackName = 'report-attachment';

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($filePath));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
    . '; filename="' . $fallbackName . '"; filename*=UTF-8\'\'' . rawurlencode($attachment['original_name']));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($filePath);
