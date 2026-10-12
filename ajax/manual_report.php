<?php
/**
 * ajax/manual_report.php
 * Handles save-as-draft, send-to-LGU, and file-attachment operations
 * for manual (hand-typed) reports.
 *
 * POST actions:
 *   save_draft        — insert or update a draft manual report (+ optional file uploads)
 *   send_report       — insert/update then mark as sent and notify LGU admins (+ optional file uploads)
 *   upload_attachment — upload one or more files for an existing report (returns attachment list)
 *   delete_attachment — remove one attachment by id
 *   get_attachments   — return attachments for a given report_id
 */

require_once __DIR__ . '/../config/config.php';
requireRole('barangay_admin');

header('Content-Type: application/json');

// ── CSRF guard ────────────────────────────────────────────
if (!verifyCsrfToken()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token. Please refresh the page.']);
    exit;
}

$db     = Database::getInstance()->getConnection();
$u      = currentUser();
$bid    = (int)$u['barangay_id'];
$action = sanitize($_POST['action'] ?? $_GET['action'] ?? '');

$allowedActions = ['save_draft', 'send_report', 'upload_attachment', 'delete_attachment', 'get_attachments'];
if (!in_array($action, $allowedActions, true)) {
    echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
    exit;
}

// ── Auto-create tables ─────────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS manual_reports (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    barangay_id     INT UNSIGNED NOT NULL,
    created_by      INT UNSIGNED NOT NULL,
    report_title    VARCHAR(255) NOT NULL,
    report_body     LONGTEXT NOT NULL,
    report_type     VARCHAR(80) NOT NULL DEFAULT 'General',
    status          ENUM('draft','sent') NOT NULL DEFAULT 'draft',
    sent_at         DATETIME NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_barangay (barangay_id),
    INDEX idx_created  (created_by),
    INDEX idx_status   (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->exec("CREATE TABLE IF NOT EXISTS manual_report_attachments (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_id       INT UNSIGNED NOT NULL,
    barangay_id     INT UNSIGNED NOT NULL,
    uploaded_by     INT UNSIGNED NOT NULL,
    original_name   VARCHAR(255) NOT NULL,
    stored_name     VARCHAR(255) NOT NULL,
    file_path       VARCHAR(500) NOT NULL,
    file_size       INT UNSIGNED NOT NULL DEFAULT 0,
    mime_type       VARCHAR(120) NOT NULL DEFAULT '',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_report   (report_id),
    INDEX idx_barangay (barangay_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Upload dir ────────────────────────────────────────────
$uploadDir = rtrim(UPLOAD_PATH, '/') . '/reports/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// ── Allowed MIME types ────────────────────────────────────
$allowedMimes = [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp',
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'text/plain',
];
$maxFileSize = 10 * 1024 * 1024; // 10 MB per file
$maxFiles    = 5;

/**
 * Save one file from $_FILES[$key][$index] and insert a DB row.
 * Returns the attachment row array on success, or ['error' => '...'] on failure.
 */
function saveAttachment(PDO $db, int $reportId, int $bid, int $userId,
                        array $fileInfo, string $uploadDir,
                        array $allowedMimes, int $maxFileSize): array
{
    if ($fileInfo['error'] !== UPLOAD_ERR_OK) {
        return ['error' => 'Upload error code: ' . $fileInfo['error']];
    }
    if ($fileInfo['size'] > $maxFileSize) {
        return ['error' => htmlspecialchars($fileInfo['name']) . ' exceeds 10 MB limit.'];
    }

    // Detect MIME via finfo (more reliable than browser-supplied)
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($fileInfo['tmp_name']);
    if (!in_array($mimeType, $allowedMimes, true)) {
        return ['error' => htmlspecialchars($fileInfo['name']) . ': file type not allowed (' . $mimeType . ').'];
    }

    $ext        = strtolower(pathinfo($fileInfo['name'], PATHINFO_EXTENSION));
    $storedName = uniqid('rpt_', true) . '.' . $ext;
    $destPath   = $uploadDir . $storedName;
    $filePath   = 'reports/' . $storedName;   // relative to uploads/

    if (!move_uploaded_file($fileInfo['tmp_name'], $destPath)) {
        return ['error' => 'Could not move uploaded file.'];
    }

    $stmt = $db->prepare(
        "INSERT INTO manual_report_attachments
         (report_id, barangay_id, uploaded_by, original_name, stored_name, file_path, file_size, mime_type)
         VALUES (?,?,?,?,?,?,?,?)"
    );
    $stmt->execute([
        $reportId, $bid, $userId,
        $fileInfo['name'], $storedName, $filePath,
        $fileInfo['size'], $mimeType,
    ]);

    return [
        'id'            => (int)$db->lastInsertId(),
        'original_name' => $fileInfo['name'],
        'file_path'     => $filePath,
        'file_size'     => $fileInfo['size'],
        'mime_type'     => $mimeType,
    ];
}

/**
 * Pull all attachments for a report from the DB and return as arrays.
 */
function fetchAttachments(PDO $db, int $reportId, int $bid): array
{
    $stmt = $db->prepare(
        "SELECT id, original_name, file_path, file_size, mime_type, created_at
         FROM manual_report_attachments
         WHERE report_id=? AND barangay_id=?
         ORDER BY created_at ASC"
    );
    $stmt->execute([$reportId, $bid]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ══════════════════════════════════════════════════════════
// ACTION: get_attachments
// ══════════════════════════════════════════════════════════
if ($action === 'get_attachments') {
    $reportId = (int)($_POST['report_id'] ?? $_GET['report_id'] ?? 0);
    if (!$reportId) { echo json_encode(['ok' => false, 'error' => 'Missing report_id.']); exit; }
    // Verify ownership
    $own = $db->prepare("SELECT id FROM manual_reports WHERE id=? AND barangay_id=?");
    $own->execute([$reportId, $bid]);
    if (!$own->fetch()) { echo json_encode(['ok' => false, 'error' => 'Not found.']); exit; }

    echo json_encode(['ok' => true, 'attachments' => fetchAttachments($db, $reportId, $bid)]);
    exit;
}

// ══════════════════════════════════════════════════════════
// ACTION: upload_attachment  (stand-alone upload for existing reports)
// ══════════════════════════════════════════════════════════
if ($action === 'upload_attachment') {
    $reportId = (int)($_POST['report_id'] ?? 0);
    if (!$reportId) { echo json_encode(['ok' => false, 'error' => 'Missing report_id.']); exit; }

    // Ownership + draft-only check
    $own = $db->prepare("SELECT id, status FROM manual_reports WHERE id=? AND barangay_id=? AND created_by=?");
    $own->execute([$reportId, $bid, $u['id']]);
    $existing = $own->fetch();
    if (!$existing) { echo json_encode(['ok' => false, 'error' => 'Report not found or access denied.']); exit; }

    if (!isset($_FILES['files'])) {
        echo json_encode(['ok' => false, 'error' => 'No files received.']);
        exit;
    }

    // Count existing attachments
    $curCount = (int)$db->prepare("SELECT COUNT(*) FROM manual_report_attachments WHERE report_id=? AND barangay_id=?")
        ->execute([$reportId, $bid]) ? $db->query("SELECT FOUND_ROWS()")->fetchColumn() : 0;
    $existingCount = (int)$db->query("SELECT COUNT(*) FROM manual_report_attachments WHERE report_id=$reportId AND barangay_id=$bid")->fetchColumn();

    // Normalise $_FILES['files'] into a list
    $files = [];
    if (is_array($_FILES['files']['name'])) {
        foreach ($_FILES['files']['name'] as $i => $name) {
            $files[] = [
                'name'     => $name,
                'tmp_name' => $_FILES['files']['tmp_name'][$i],
                'size'     => $_FILES['files']['size'][$i],
                'error'    => $_FILES['files']['error'][$i],
            ];
        }
    } else {
        $files[] = [
            'name'     => $_FILES['files']['name'],
            'tmp_name' => $_FILES['files']['tmp_name'],
            'size'     => $_FILES['files']['size'],
            'error'    => $_FILES['files']['error'],
        ];
    }

    if (($existingCount + count($files)) > $maxFiles) {
        echo json_encode(['ok' => false, 'error' => "Maximum $maxFiles attachments allowed per report."]);
        exit;
    }

    $saved  = [];
    $errors = [];
    foreach ($files as $f) {
        $result = saveAttachment($db, $reportId, $bid, $u['id'], $f, $uploadDir, $allowedMimes, $maxFileSize);
        if (isset($result['error'])) {
            $errors[] = $result['error'];
        } else {
            $saved[] = $result;
        }
    }

    echo json_encode([
        'ok'          => count($saved) > 0,
        'saved'       => $saved,
        'errors'      => $errors,
        'attachments' => fetchAttachments($db, $reportId, $bid),
    ]);
    exit;
}

// ══════════════════════════════════════════════════════════
// ACTION: delete_attachment
// ══════════════════════════════════════════════════════════
if ($action === 'delete_attachment') {
    $attId = (int)($_POST['attachment_id'] ?? 0);
    if (!$attId) { echo json_encode(['ok' => false, 'error' => 'Missing attachment_id.']); exit; }

    // Fetch row — must belong to this barangay and be uploaded by this user
    $stmt = $db->prepare(
        "SELECT mra.*, mr.status FROM manual_report_attachments mra
         JOIN manual_reports mr ON mr.id = mra.report_id
         WHERE mra.id=? AND mra.barangay_id=? AND mra.uploaded_by=?"
    );
    $stmt->execute([$attId, $bid, $u['id']]);
    $att = $stmt->fetch();

    if (!$att) { echo json_encode(['ok' => false, 'error' => 'Attachment not found or access denied.']); exit; }
    if ($att['status'] === 'sent') { echo json_encode(['ok' => false, 'error' => 'Cannot remove attachments from a sent report.']); exit; }

    // Delete file from disk
    $diskPath = rtrim(UPLOAD_PATH, '/') . '/' . $att['file_path'];
    if (file_exists($diskPath)) {
        @unlink($diskPath);
    }

    $db->prepare("DELETE FROM manual_report_attachments WHERE id=?")->execute([$attId]);

    echo json_encode(['ok' => true, 'attachments' => fetchAttachments($db, (int)$att['report_id'], $bid)]);
    exit;
}

// ══════════════════════════════════════════════════════════
// Below: save_draft and send_report — validate shared inputs
// ══════════════════════════════════════════════════════════
$title  = trim($_POST['report_title'] ?? '');
$body   = trim($_POST['report_body']  ?? '');
$type   = trim($_POST['report_type']  ?? 'General');
$editId = (int)($_POST['edit_id'] ?? 0);

$allowedTypes = ['General','Incident','Health','Financial','Infrastructure','Census','Other'];
if (!in_array($type, $allowedTypes, true)) $type = 'General';

if ($title === '') { echo json_encode(['ok' => false, 'error' => 'Report title is required.']); exit; }
if ($body  === '') { echo json_encode(['ok' => false, 'error' => 'Report body cannot be empty.']); exit; }
if (mb_strlen($title) > 255) { echo json_encode(['ok' => false, 'error' => 'Title is too long (max 255 characters).']); exit; }

// Verify ownership if editing
if ($editId > 0) {
    $own = $db->prepare("SELECT id, status FROM manual_reports WHERE id=? AND barangay_id=? AND created_by=?");
    $own->execute([$editId, $bid, $u['id']]);
    $existing = $own->fetch();
    if (!$existing) { echo json_encode(['ok' => false, 'error' => 'Report not found or access denied.']); exit; }
    if ($existing['status'] === 'sent') { echo json_encode(['ok' => false, 'error' => 'Cannot edit a report that has already been sent.']); exit; }
}

/**
 * Process any files in $_FILES['attachments'] and save them.
 * Returns ['saved'=>[...], 'errors'=>[...]].
 */
function processInlineAttachments(PDO $db, int $reportId, int $bid, int $userId,
                                   string $uploadDir, array $allowedMimes,
                                   int $maxFileSize, int $maxFiles): array
{
    $saved = []; $errors = [];
    if (empty($_FILES['attachments']['name'])) return compact('saved','errors');

    $existingCount = (int)$db->query("SELECT COUNT(*) FROM manual_report_attachments WHERE report_id=$reportId AND barangay_id=$bid")->fetchColumn();

    // Normalise
    $files = [];
    if (is_array($_FILES['attachments']['name'])) {
        foreach ($_FILES['attachments']['name'] as $i => $name) {
            if ($_FILES['attachments']['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
            $files[] = ['name'=>$name,'tmp_name'=>$_FILES['attachments']['tmp_name'][$i],'size'=>$_FILES['attachments']['size'][$i],'error'=>$_FILES['attachments']['error'][$i]];
        }
    } else {
        if ($_FILES['attachments']['error'] !== UPLOAD_ERR_NO_FILE) {
            $files[] = ['name'=>$_FILES['attachments']['name'],'tmp_name'=>$_FILES['attachments']['tmp_name'],'size'=>$_FILES['attachments']['size'],'error'=>$_FILES['attachments']['error']];
        }
    }

    if (($existingCount + count($files)) > $maxFiles) {
        $errors[] = "Maximum $maxFiles attachments allowed per report.";
        return compact('saved','errors');
    }

    foreach ($files as $f) {
        $result = saveAttachment($db, $reportId, $bid, $userId, $f, $uploadDir, $allowedMimes, $maxFileSize);
        if (isset($result['error'])) $errors[] = $result['error'];
        else $saved[] = $result;
    }
    return compact('saved','errors');
}

// ══════════════════════════════════════════════════════════
// ACTION: save_draft
// ══════════════════════════════════════════════════════════
if ($action === 'save_draft') {
    if ($editId > 0) {
        $stmt = $db->prepare("UPDATE manual_reports SET report_title=?, report_body=?, report_type=? WHERE id=? AND barangay_id=? AND created_by=?");
        $stmt->execute([$title, $body, $type, $editId, $bid, $u['id']]);
        $reportId = $editId;
    } else {
        $stmt = $db->prepare("INSERT INTO manual_reports (barangay_id, created_by, report_title, report_body, report_type, status) VALUES (?,?,?,?,?,'draft')");
        $stmt->execute([$bid, $u['id'], $title, $body, $type]);
        $reportId = (int)$db->lastInsertId();
    }

    $upload = processInlineAttachments($db, $reportId, $bid, $u['id'], $uploadDir, $allowedMimes, $maxFileSize, $maxFiles);

    logActivity($u['id'], 'SAVE_DRAFT_REPORT', 'Reports', "Saved draft: $title");
    echo json_encode([
        'ok'          => true,
        'id'          => $reportId,
        'saved'       => $upload['saved'],
        'errors'      => $upload['errors'],
        'attachments' => fetchAttachments($db, $reportId, $bid),
    ]);
    exit;
}

// ══════════════════════════════════════════════════════════
// ACTION: send_report
// ══════════════════════════════════════════════════════════
if ($action === 'send_report') {
    $now = date('Y-m-d H:i:s');

    if ($editId > 0) {
        $stmt = $db->prepare("UPDATE manual_reports SET report_title=?, report_body=?, report_type=?, status='sent', sent_at=? WHERE id=? AND barangay_id=? AND created_by=?");
        $stmt->execute([$title, $body, $type, $now, $editId, $bid, $u['id']]);
        $reportId = $editId;
    } else {
        $stmt = $db->prepare("INSERT INTO manual_reports (barangay_id, created_by, report_title, report_body, report_type, status, sent_at) VALUES (?,?,?,?,?,'sent',?)");
        $stmt->execute([$bid, $u['id'], $title, $body, $type, $now]);
        $reportId = (int)$db->lastInsertId();
    }

    // Save any inline uploads
    $upload = processInlineAttachments($db, $reportId, $bid, $u['id'], $uploadDir, $allowedMimes, $maxFileSize, $maxFiles);

    // Fetch all attachments for notification/email
    $allAttachments = fetchAttachments($db, $reportId, $bid);

    // Barangay name
    $brgyName = $db->query("SELECT barangay_name FROM barangays WHERE id=$bid")->fetchColumn() ?: 'Barangay';

    // Notify sender
    $attNote = count($allAttachments) ? ' (' . count($allAttachments) . ' file(s) attached)' : '';
    sendNotification(
        $u['id'],
        "You successfully sent the manual report <strong>" . htmlspecialchars($title) . "</strong> to the LGU." . $attNote,
        'success',
        'transaction'
    );

    // Notify all LGU super admins
    $admins = $db->query("SELECT id FROM users WHERE role='super_admin' AND status='active'")->fetchAll();
    foreach ($admins as $admin) {
        sendNotification(
            $admin['id'],
            "Barangay <strong>" . htmlspecialchars($brgyName) . "</strong> sent a manual report: <strong>" . htmlspecialchars($title) . "</strong>." . $attNote,
            'info',
            'transaction'
        );
    }

    logActivity($u['id'], 'SEND_MANUAL_REPORT', 'Reports', "Sent manual report to LGU: $title");

    // Email confirmation
    $senderEmail    = $db->query("SELECT email FROM users WHERE id={$u['id']}")->fetchColumn() ?: '';
    $senderName     = $u['name'] ?? ($u['first_name'] . ' ' . $u['last_name']);
    $adminEmailRows = $db->query("SELECT email, CONCAT(first_name,' ',last_name) as name FROM users WHERE role='super_admin' AND status='active' AND email != ''")->fetchAll();
    $sentDisplay    = date('M d, Y h:i A', strtotime($now));

    try {
        require_once __DIR__ . '/../helpers/mailer.php';
        sendManualReportEmail(
            $senderEmail,
            $senderName,
            $brgyName,
            $title,
            $type,
            $body,
            $sentDisplay,
            $adminEmailRows
        );
    } catch (Throwable $e) {
        error_log('[manual_report] email failed: ' . $e->getMessage());
    }

    echo json_encode([
        'ok'          => true,
        'id'          => $reportId,
        'sent_at'     => $sentDisplay,
        'saved'       => $upload['saved'],
        'errors'      => $upload['errors'],
        'attachments' => $allAttachments,
    ]);
    exit;
}
