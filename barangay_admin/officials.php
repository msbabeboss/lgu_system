<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/mailer.php';
requireRole('barangay_admin');

$db = Database::getInstance()->getConnection();
$u = currentUser();
$bid = (int)$u['barangay_id'];

// Fetch barangay info for resident auto-fill
$brgyInfo = $db->prepare("SELECT barangay_name, municipality, region, province, zip_code FROM barangays WHERE id=?");
$brgyInfo->execute([$bid]);
$brgyInfo = $brgyInfo->fetch() ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrAbort();

    // Safe column migrations — idempotent
    foreach ([
        "ALTER TABLE residents ADD COLUMN official_id INT UNSIGNED NULL",
        "ALTER TABLE residents ADD COLUMN official_position VARCHAR(100) NULL",
    ] as $mSql) { try { $db->exec($mSql); } catch (PDOException $e) {} }

    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        // Official fields — build full_name from parts (both add and edit now use split fields)
        $firstName = sanitize($_POST['first_name'] ?? '');
        $middleName= sanitize($_POST['middle_name'] ?? '');
        $lastName  = sanitize($_POST['last_name'] ?? '');
        $suffix    = sanitize($_POST['suffix'] ?? '');
        $fullName  = trim("$lastName, $firstName" . ($middleName ? " $middleName" : '') . ($suffix ? " $suffix" : ''));
        $position  = sanitize($_POST['position']);
        $termStart = $_POST['term_start'];
        $termEnd   = $_POST['term_end'];
        $contact   = sanitize($_POST['contact'] ?? '');
        $email     = sanitize($_POST['email'] ?? '');
        $status    = $_POST['status'];

        // Validate contact number
        if ($contact && !preg_match('/^09\d{9}$/', $contact)) {
            flash('off', 'Contact number must be 11 digits and start with 09 (e.g. 09171234567).', 'danger');
            redirect(BASE_URL . '/barangay_admin/officials.php');
        }
        // Duplicate contact check
        if ($contact) {
            $dupId = ($action === 'edit') ? (int)($_POST['official_id'] ?? 0) : 0;
            $dupQ  = $db->prepare("SELECT id FROM officials WHERE contact=? AND barangay_id=? AND id!=? AND status!='inactive'");
            $dupQ->execute([$contact, $bid, $dupId]);
            if ($dupQ->fetch()) {
                flash('off', "Contact number <strong>$contact</strong> is already in use by another official.", 'danger');
                redirect(BASE_URL . '/barangay_admin/officials.php');
            }
        }
        $photo     = null;

        if (!empty($_POST['photo_base64'])) {
            $b64data = $_POST['photo_base64'];
            if (preg_match('/^data:image\/(jpeg|png|webp);base64,/', $b64data, $typeMatch)) {
                $imgData  = base64_decode(preg_replace('/^data:image\/\w+;base64,/', '', $b64data));
                $ext      = ($typeMatch[1] === 'png') ? 'png' : 'jpg';
                $dir = UPLOAD_PATH . 'officials/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $fname = 'official_' . time() . '_' . random_int(1000,9999) . '.' . $ext;
                file_put_contents($dir . $fname, $imgData);
                $photo = 'officials/' . $fname;
            }
        } elseif (!empty($_FILES['photo']['name'])) {
            $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
            if (in_array(strtolower($ext), ['jpg','jpeg','png'])) {
                $dir = UPLOAD_PATH . 'officials/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $fname = 'official_' . time() . '.' . $ext;
                move_uploaded_file($_FILES['photo']['tmp_name'], $dir . $fname);
                $photo = 'officials/' . $fname;
            }
        }

        if ($action === 'add') {
            $db->prepare("INSERT INTO officials (barangay_id,full_name,position,term_start,term_end,contact,email,photo,status) VALUES (?,?,?,?,?,?,?,?,?)")
               ->execute([$bid,$fullName,$position,$termStart,$termEnd,$contact,$email,$photo,$status]);
            $newOfficialId = (int)$db->lastInsertId();
            logActivity($u['id'], 'ADD_OFFICIAL', 'Officials', "Added: $fullName");

            // Email confirmation
            if ($u['email']) {
                sendTransactionLogEmail(
                    $u['email'], $u['name'],
                    'ADD_OFFICIAL', 'Officials',
                    "Added official: $fullName — Position: $position — Barangay: {$brgyInfo['barangay_name']}",
                    EMAIL_BASE_URL . '/barangay_admin/officials.php'
                );
            }

            // ── Realtime notifications ─────────────────────────────────
            $brgyLabel  = $brgyInfo['barangay_name'] ?? 'Barangay';
            $notifMsg   = "🏛️ New official added: <strong>$fullName</strong> — $position — ID: <strong>#$newOfficialId</strong> (<em>$brgyLabel</em>)";
            // Notify the acting admin
            sendNotification($u['id'], $notifMsg, 'success', 'system');
            // Notify peer barangay admins in same barangay (exclude self)
            notifyBarangayAdmins($bid, $notifMsg, 'success', 'system', $u['id']);
            // Notify all super admins
            notifySuperAdmins($notifMsg, 'info', 'system');

            // ── "Register as Resident" button was clicked ──────────────
            // Store all collected data in session so add_resident.php can pre-fill the form.
            // The official is already saved; no resident record is created here.
            if (!empty($_POST['register_as_resident'])) {
                $_SESSION['prefill_from_official'] = [
                    'official_id'  => $newOfficialId,
                    'first_name'   => $firstName,
                    'middle_name'  => $middleName,
                    'last_name'    => $lastName,
                    'suffix'       => $suffix,
                    'full_name'    => $fullName,
                    'position'     => $position,
                    'birthdate'    => $_POST['birthdate']    ?? '',
                    'gender'       => $_POST['gender']       ?? '',
                    'civil_status' => $_POST['civil_status'] ?? 'Single',
                    'voter_status' => $_POST['voter_status'] ?? 'Not Registered',
                    'contact'      => $contact,
                    'purok'        => sanitize($_POST['purok'] ?? ''),
                    'photo'        => $photo ?? '',   // path like officials/xxx.jpg
                ];
                redirect(BASE_URL . '/barangay_admin/add_resident.php');
            }

            flash('off', "Official <strong>$fullName</strong> saved successfully.", 'success');
        } else {
            $id = (int)$_POST['official_id'];

            if ($photo) {
                $db->prepare("UPDATE officials SET full_name=?,position=?,term_start=?,term_end=?,contact=?,email=?,photo=?,status=? WHERE id=? AND barangay_id=?")->execute([$fullName,$position,$termStart,$termEnd,$contact,$email,$photo,$status,$id,$bid]);
            } else {
                $db->prepare("UPDATE officials SET full_name=?,position=?,term_start=?,term_end=?,contact=?,email=?,status=? WHERE id=? AND barangay_id=?")->execute([$fullName,$position,$termStart,$termEnd,$contact,$email,$status,$id,$bid]);
            }
            logActivity($u['id'], 'EDIT_OFFICIAL', 'Officials', "Updated details for official $fullName");
            // Email confirmation to the admin who edited the official
            if ($u['email']) {
                sendTransactionLogEmail(
                    $u['email'], $u['name'],
                    'EDIT_OFFICIAL', 'Officials',
                    "Updated official: $fullName — Position: $position — Barangay: {$brgyInfo['barangay_name']}",
                    EMAIL_BASE_URL . '/barangay_admin/officials.php'
                );
            }

            // ── Realtime notifications ─────────────────────────────────
            $brgyLabel = $brgyInfo['barangay_name'] ?? 'Barangay';
            $notifMsg  = "✏️ Official updated: <strong>$fullName</strong> — $position — ID: <strong>#$id</strong> (<em>$brgyLabel</em>)";
            sendNotification($u['id'], $notifMsg, 'info', 'system');
            notifyBarangayAdmins($bid, $notifMsg, 'info', 'system', $u['id']);
            notifySuperAdmins($notifMsg, 'info', 'system');

            // ── "Register as Resident" was clicked on the Edit form ──────
            if (!empty($_POST['register_as_resident'])) {
                // Name parts now come directly from the split fields
                $_SESSION['prefill_from_official'] = [
                    'official_id'  => $id,
                    'first_name'   => $firstName,
                    'middle_name'  => $middleName,
                    'last_name'    => $lastName,
                    'suffix'       => $suffix,
                    'full_name'    => $fullName,
                    'position'     => $position,
                    'birthdate'    => '',
                    'gender'       => '',
                    'civil_status' => 'Single',
                    'voter_status' => 'Not Registered',
                    'contact'      => $contact,
                    'purok'        => '',
                    'photo'        => $photo ?? ($db->query("SELECT photo FROM officials WHERE id=$id")->fetchColumn() ?: ''),
                ];
                redirect(BASE_URL . '/barangay_admin/add_resident.php');
            }

            // ── Auto-archive when set to Inactive ─────────────────────
            if ($status === 'inactive') {
                // Only create archive entry if not already archived
                $alreadyArch = $db->prepare("SELECT id FROM archives WHERE table_name='officials' AND record_id=?");
                $alreadyArch->execute([$id]);
                if (!$alreadyArch->fetch()) {
                    $archRow = $db->prepare("SELECT * FROM officials WHERE id=?");
                    $archRow->execute([$id]);
                    $archData = $archRow->fetch();
                    if ($archData) {
                        $archData['archive_reason'] = 'Term ended';
                        $db->prepare("INSERT INTO archives (table_name,record_id,data_json,archived_by) VALUES ('officials',?,?,?)")
                           ->execute([$id, json_encode($archData), $u['id']]);
                        logActivity($u['id'], 'ARCHIVE_OFFICIAL', 'Officials', "Auto-archived (set inactive): $fullName");
                    }
                }
                flash('off', "Official <strong>$fullName</strong> set to inactive and archived.", 'info');
            } else {
                flash('off', 'Official updated.', 'success');
            }
        }
        redirect(BASE_URL . '/barangay_admin/officials.php');
    }

    if ($action === 'archive') {
        $id     = (int)$_POST['official_id'];
        $reason = sanitize($_POST['archive_reason'] ?? 'Term ended');
        $row    = $db->prepare("SELECT * FROM officials WHERE id=? AND barangay_id=?");
        $row->execute([$id, $bid]);
        $data = $row->fetch();
        if ($data) {
            $data['archive_reason'] = $reason;
            $db->prepare("INSERT INTO archives (table_name,record_id,data_json,archived_by) VALUES ('officials',?,?,?)")
               ->execute([$id, json_encode($data), $u['id']]);
            $db->prepare("UPDATE officials SET status='inactive' WHERE id=? AND barangay_id=?")->execute([$id, $bid]);
            logActivity($u['id'], 'ARCHIVE_OFFICIAL', 'Officials', "Archived official {$data['full_name']} — Reason: $reason");
            // Email confirmation to the admin who archived the official
            if ($u['email']) {
                sendTransactionLogEmail(
                    $u['email'], $u['name'],
                    'ARCHIVE_OFFICIAL', 'Officials',
                    "Archived official: {$data['full_name']} — Reason: $reason — Barangay: {$brgyInfo['barangay_name']}",
                    EMAIL_BASE_URL . '/barangay_admin/officials.php'
                );
            }

            // ── Realtime notifications ─────────────────────────────────
            $brgyLabel   = $brgyInfo['barangay_name'] ?? 'Barangay';
            $notifMsgArch = "📦 Official archived: <strong>{$data['full_name']}</strong> — {$data['position']} — ID: <strong>#$id</strong> (<em>$brgyLabel</em>) — Reason: $reason";
            sendNotification($u['id'], $notifMsgArch, 'warning', 'system');
            notifyBarangayAdmins($bid, $notifMsgArch, 'warning', 'system', $u['id']);
            notifySuperAdmins($notifMsgArch, 'warning', 'system');

            flash('off', 'Official archived.', 'info');
        }
        redirect(BASE_URL . '/barangay_admin/officials.php');
    }

    // Auto-archive officials whose term has ended
    if ($action === 'auto_archive_expired') {
        $expired = $db->prepare("SELECT * FROM officials WHERE barangay_id=? AND status='active' AND term_end < CURDATE()");
        $expired->execute([$bid]);
        $expiredList = $expired->fetchAll();
        $count = 0;
        foreach ($expiredList as $off) {
            $off['archive_reason'] = 'Term ended';
            $db->prepare("INSERT INTO archives (table_name,record_id,data_json,archived_by) VALUES ('officials',?,?,?)")
               ->execute([$off['id'], json_encode($off), $u['id']]);
            $db->prepare("UPDATE officials SET status='inactive' WHERE id=?")->execute([$off['id']]);
            $count++;
        }
        logActivity($u['id'], 'AUTO_ARCHIVE_OFFICIALS', 'Officials', "Auto-archived $count term-ended official(s)");
        flash('off', "$count term-ended official(s) auto-archived.", 'info');
        redirect(BASE_URL . '/barangay_admin/officials.php');
    }
}

// NOTE: The old "clean up inactive officials with no archive entry" block has been removed.
// Inactive officials without an archive entry are officials that were auto-inactivated
// due to a resident transfer. They must remain visible so the barangay admin can
// manually archive them from the Officials page.

$officials = $db->prepare(
    "SELECT * FROM officials
     WHERE barangay_id=?
       AND id NOT IN (SELECT record_id FROM archives WHERE table_name='officials')
     ORDER BY FIELD(status,'active','inactive'), position"
);
$officials->execute([$bid]);
$allOfficials = $officials->fetchAll();

// Pagination
$perPage     = 10;
$currentPage = max(1, (int)($_GET['page'] ?? 1));
$totalRows   = count($allOfficials);
$totalPages  = max(1, (int)ceil($totalRows / $perPage));
$currentPage = min($currentPage, $totalPages);
$listOffset  = ($currentPage - 1) * $perPage;   // renamed: avoid shadowing by pagination.php
$officials   = array_slice($allOfficials, $listOffset, $perPage);

// Count active officials with term ended (not yet archived)
$expiredStmt = $db->prepare(
    "SELECT COUNT(*) FROM officials
     WHERE barangay_id=? AND status='active' AND term_end < CURDATE()
       AND id NOT IN (SELECT record_id FROM archives WHERE table_name='officials')"
);
$expiredStmt->execute([$bid]);
$expiredCount = (int)$expiredStmt->fetchColumn();

// IDs of officials that were auto-inactivated by a resident transfer
// (inactive but NOT yet in the archives table — admin hasn't manually archived them yet)
$transferredStmt = $db->prepare("
    SELECT id FROM officials
    WHERE barangay_id=? AND status='inactive'
      AND id NOT IN (SELECT record_id FROM archives WHERE table_name='officials')
");
$transferredStmt->execute([$bid]);
$transferredIds = array_column($transferredStmt->fetchAll(), 'id');
$transferredIds = array_map('intval', $transferredIds);

$flash = getFlash('off');
$positions = ['Barangay Captain','Barangay Kagawad','SK Chairperson','SK Kagawad','Barangay Secretary','Barangay Treasurer','BPSO','Lupon Member'];

// Fetch official term years from system settings (default 3)
$termYearsRow = $db->query("SELECT setting_val FROM system_settings WHERE setting_key='official_term_years'")->fetchColumn();
$officialTermYears = $termYearsRow ? (int)$termYearsRow : 3;
?>

<?php
$pageIcon     = 'bi-person-badge-fill';
$pageTitle    = 'Barangay Officials';
$pageSubtitle = 'Manage elected and appointed officials of your barangay.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favcon.png">
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favcon.png">
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=3.0">
    <style>
        /* ── Officials modal shared styles ── */
        .off-field-label {
            font-size: 0.78rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: 4px;
            display: block;
        }
        .off-field-label .req { color: #dc2626; margin-left: 2px; }
        #officialModal .form-control,
        #officialModal .form-select {
            font-size: 0.84rem;
            border-color: #d0dce8;
            border-radius: 7px;
        }
        #officialModal .form-control:focus,
        #officialModal .form-select:focus {
            border-color: #3a7fa8;
            box-shadow: 0 0 0 3px rgba(58,127,168,0.12);
        }
        #officialModal .form-control[readonly] { background: #f4f7fa; color: #6b7280; }

        /* ── Section cards inside modal ── */
        .off-section {
            background: #fff;
            border-radius: 12px;
            border: 1px solid #d0ecd6;
            overflow: hidden;
            margin-bottom: 14px;
        }
        .off-section-head {
            background: rgba(26,79,122,0.05);
            border-bottom: 1px solid rgba(26,79,122,0.1);
            padding: 7px 12px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .off-section-head span {
            font-weight: 700;
            font-size: 0.78rem;
            color: #1a4f7a;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }
        .off-section-body { padding: 12px; }

        /* ── Photo drop zone ── */
        #offPhotoDropZone {
            width: 90px;
            height: 115px;
            border-radius: 8px;
            background: #f0f4f8;
            border: 2px dashed #b0c4d8;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            overflow: hidden;
            transition: border-color .2s;
            position: relative;
            margin: 0 auto;
        }
        #offPhotoDropZone:hover { border-color: #3a7fa8; background: #e8f3fb; }
        #offPhotoPreview {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: none;
            position: absolute;
            top: 0; left: 0;
        }
        .off-photo-placeholder {
            text-align: center;
            color: #9ca3af;
            font-size: 0.68rem;
            padding: 8px;
            pointer-events: none;
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/sidebar_barangay.php'; ?>
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<div class="main-content">
    

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show">
            <?= $flash['message'] ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card mb-3">
        <div class="card-body py-2">
            <div class="d-flex gap-2 align-items-center flex-wrap">
                <span class="text-muted small"><?= number_format($totalRows) ?> official<?= $totalRows !== 1 ? 's' : '' ?></span>
                <?php if ($expiredCount > 0): ?>
                <form method="POST" class="d-inline ms-1" onsubmit="return confirm('Auto-archive <?= $expiredCount ?> term-ended official(s) whose term has already ended?')">
                    <input type="hidden" name="action" value="auto_archive_expired">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES) ?>">
                    <button type="submit" class="btn btn-sm btn-warning fw-semibold">
                        <i class="bi bi-clock-history me-1"></i>Archive <?= $expiredCount ?> Term Ended
                    </button>
                </form>
                <?php endif; ?>
                <div class="d-flex gap-2 ms-auto">
                    <a href="<?= BASE_URL ?>/barangay_admin/export_officials.php"
                       class="btn btn-sm btn-outline-success fw-semibold"
                       title="Download all officials as Excel">
                        <i class="bi bi-file-earmark-excel me-1"></i>Download Excel
                    </a>
                    <button type="button" class="btn btn-sm btn-success fw-semibold"
                            data-bs-toggle="modal" data-bs-target="#officialModal"
                            onclick="resetOfficialForm()">
                        <i class="bi bi-person-badge-fill me-1"></i>Add Official
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div id="logs-table" style="background:#fff;border-radius:18px;box-shadow:0 2px 16px rgba(0,0,0,0.07);border:1px solid #e0f0e4;overflow:hidden;">
        <div class="table-responsive">
            <table class="table mb-0" style="font-size:0.85rem;">
                <thead>
                    <tr>
                        <th class="ps-4 py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">#</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">Official</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">Position</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">Term</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">Contact</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">Status</th>
                        <th class="pe-4 py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($officials)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-5">
                        <i class="bi bi-person-badge" style="font-size:2.5rem;opacity:0.3;display:block;margin-bottom:10px;"></i>No officials found.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($officials as $i => $off):
                        $isTermEnded  = $off['status'] === 'active' && $off['term_end'] < date('Y-m-d');
                        $isTransferred = in_array((int)$off['id'], $transferredIds, true);
                    ?>
                    <tr style="border-bottom:1px solid #e8f4fb;transition:background 0.15s;<?= $isTransferred ? 'background:#f8f9fa;opacity:0.7;' : ($off['status']==='inactive' ? 'opacity:0.55;' : '') ?>"
                        onmouseover="this.style.background='<?= $isTransferred ? '#f1f5f9' : '#f8fafc' ?>'" onmouseout="this.style.background='<?= $isTransferred ? '#f8f9fa' : '' ?>'">
                        <td class="ps-4 py-3" style="border:none;color:<?= $isTransferred ? '#9ca3af' : '#3a7fa8' ?>;"><?= $listOffset + $i + 1 ?></td>
                        <td class="py-3" style="border:none;">
                            <div class="d-flex align-items-center gap-3">
                                <?php if ($off['photo']): ?>
                                    <img src="<?= BASE_URL ?>/uploads/<?= htmlspecialchars($off['photo']) ?>" class="rounded-circle flex-shrink-0" width="38" height="38" style="object-fit:cover;border:2px solid <?= $isTransferred ? '#cbd5e1' : '#d0ecd6' ?>;<?= $isTransferred ? 'filter:grayscale(1) opacity(0.6);' : '' ?>">
                                <?php else: ?>
                                    <div class="rounded-circle flex-shrink-0 d-flex align-items-center justify-content-center" style="width:38px;height:38px;background:<?= $isTransferred ? 'linear-gradient(135deg,#94a3b8,#cbd5e1)' : 'linear-gradient(135deg,#1a4f7a,#3a7fa8)' ?>;">
                                        <i class="bi bi-person-fill text-white" style="font-size:1rem;"></i>
                                    </div>
                                <?php endif; ?>
                                <div>
                                    <div style="font-weight:600;color:<?= $isTransferred ? '#9ca3af' : '#1a2e1f' ?>;<?= $isTransferred ? 'text-decoration:line-through;text-decoration-color:#9ca3af;' : '' ?>"><?= htmlspecialchars($off['full_name']) ?></div>
                                    <?php if ($off['email']): ?>
                                    <div style="font-size:0.72rem;color:<?= $isTransferred ? '#9ca3af' : '#3a7fa8' ?>;"><?= htmlspecialchars($off['email']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td class="py-3" style="border:none;">
                            <span class="badge <?= $isTransferred ? 'bg-secondary' : 'bg-primary' ?>" style="font-size:0.72rem;<?= $isTransferred ? 'opacity:0.6;' : '' ?>"><?= htmlspecialchars($off['position']) ?></span>
                        </td>
                        <td class="py-3" style="border:none;font-size:0.82rem;color:<?= $isTransferred ? '#9ca3af' : '#2a5070' ?>;">
                            <?= date('M d, Y', strtotime($off['term_start'])) ?> —
                            <span <?= $isTermEnded ? 'style="color:#dc2626;font-weight:600;"' : '' ?>>
                                <?= date('M d, Y', strtotime($off['term_end'])) ?>
                            </span>
                            <?php if ($isTermEnded): ?>
                                <span class="badge ms-1" style="background:#fee2e2;color:#991b1b;font-size:0.65rem;">Term Ended</span>
                            <?php endif; ?>
                        </td>
                        <td class="py-3" style="border:none;font-size:0.82rem;color:<?= $isTransferred ? '#9ca3af' : '#2a5070' ?>;">
                            <?= $off['contact'] ? htmlspecialchars($off['contact']) : '<span class="text-muted">—</span>' ?>
                        </td>
                        <td class="py-3" style="border:none;">
                            <?php if ($isTransferred): ?>
                                <span style="background:#fee2e2;color:#991b1b;font-size:0.72rem;font-weight:700;padding:4px 10px;border-radius:20px;border:1px solid #fca5a5;">
                                    <i class="bi bi-arrow-right-circle-fill me-1"></i>Transferred
                                </span>
                            <?php elseif ($isTermEnded): ?>
                                <span style="background:#fee2e2;color:#991b1b;font-size:0.72rem;font-weight:700;padding:4px 10px;border-radius:20px;border:1px solid #fca5a5;">
                                    <i class="bi bi-calendar-x-fill me-1"></i>Term Ended
                                </span>
                            <?php elseif ($off['status'] === 'active'): ?>
                                <span style="background:#e0f2fb;color:#0d3a5c;font-size:0.72rem;font-weight:600;padding:4px 12px;border-radius:20px;">Active</span>
                            <?php else: ?>
                                <span style="background:#f1f5f9;color:#64748b;font-size:0.72rem;font-weight:600;padding:4px 12px;border-radius:20px;">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td class="pe-4 py-3" style="border:none;">
                            <div class="d-flex gap-1">
                                <button class="btn btn-xs btn-outline-info"
                                        onclick="viewOfficial(<?= htmlspecialchars(json_encode($off)) ?>)"
                                        title="View">
                                    <i class="bi bi-eye"></i>
                                </button>
                                <button class="btn btn-xs btn-outline-primary" onclick="editOfficial(<?= htmlspecialchars(json_encode($off)) ?>)" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <?php if ($off['status'] === 'active' || $isTransferred): ?>
                                <button class="btn btn-xs <?= $isTransferred ? 'btn-outline-secondary' : ($isTermEnded ? 'btn-outline-danger' : 'btn-outline-warning') ?>"
                                        onclick="openArchiveOfficialModal(<?= $off['id'] ?>, '<?= htmlspecialchars(addslashes($off['full_name'])) ?>', '<?= $off['term_end'] ?>')"
                                        title="Archive">
                                    <i class="bi bi-archive"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <?php
        $queryParams = $_GET; unset($queryParams['page']);
        $baseQuery   = http_build_query($queryParams);
        $baseUrl     = '?' . ($baseQuery ? $baseQuery . '&' : '');
        $window = 2;
    ?>
    <?php $page = $currentPage; $totalCount = $totalRows; $offset = $listOffset; $paginationBase = $baseUrl; $paginationLabel = 'official'; include __DIR__ . '/../includes/pagination.php'; ?>
    <?php endif; ?>
</div>

<!-- Add/Edit Official Modal -->
<div class="modal fade" id="officialModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable" style="max-width:960px;">
        <form method="POST" enctype="multipart/form-data" id="officialForm">
            <input type="hidden" name="action" id="offAction" value="add">
            <input type="hidden" name="csrf_token" id="offCsrfToken" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES) ?>">
            <input type="hidden" name="official_id" id="offId">
            <input type="hidden" name="full_name" id="offFullName">
            <input type="hidden" name="photo_base64" id="offPhotoBase64">
            <input type="hidden" name="register_as_resident" id="offRegisterFlag" value="0">

            <div class="modal-content" style="border-radius:16px;overflow:hidden;">

                <!-- ── Blue banner header ── -->
                <div style="background:linear-gradient(90deg,#1a4f7a,#2d7db3);padding:12px 18px;">
                    <div class="d-flex align-items-center gap-2">
                        <div style="width:34px;height:34px;background:rgba(255,255,255,0.15);border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <i class="bi bi-person-badge-fill text-white" style="font-size:1rem;"></i>
                        </div>
                        <div>
                            <div style="font-size:0.68rem;color:rgba(255,255,255,0.7);text-transform:uppercase;letter-spacing:0.08em;">Officials Management</div>
                            <div id="offModalTitle" style="font-size:0.95rem;font-weight:800;color:#fff;">Add New Official</div>
                            <div style="font-size:0.75rem;color:rgba(255,255,255,0.75);margin-top:2px;" id="offModalSub">Fill in all required fields to register a barangay official.</div>
                        </div>
                        <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                </div>

                <!-- ── Modal body ── -->
                <div class="modal-body p-3" style="background:#f8fafc;">

                    <div class="row g-3">

                        <!-- ══ LEFT: Photo ══ -->
                        <div class="col-lg-3">

                            <!-- Photo Card -->
                            <div class="off-section">
                                <div class="off-section-head">
                                    <i class="bi bi-camera-fill" style="color:#1a4f7a;font-size:0.85rem;"></i>
                                    <span>Official Photo</span>
                                </div>
                                <div class="off-section-body text-center">
                                    <div id="offPhotoDropZone" onclick="document.getElementById('offPhotoFileInput').click()" title="Click or drag photo here">
                                        <img id="offPhotoPreview" src="" alt="">
                                        <div class="off-photo-placeholder" id="offPhotoPlaceholder">
                                            <i class="bi bi-person-bounding-box" style="font-size:2.2rem;display:block;margin-bottom:6px;color:#b0c4d8;"></i>
                                            <span style="font-size:0.67rem;color:#9ca3af;">2×2 ID Photo<br>Click or drag here</span>
                                        </div>
                                    </div>
                                    <input type="file" id="offPhotoFileInput" name="photo" accept="image/*" style="display:none">
                                    <div class="d-flex gap-2 justify-content-center mt-2 flex-wrap">
                                        <button type="button" class="btn btn-sm btn-outline-primary" style="font-size:0.72rem;"
                                                onclick="document.getElementById('offPhotoFileInput').click()">
                                            <i class="bi bi-upload me-1"></i>Upload
                                        </button>
                                        <button type="button" id="offOpenCameraBtn" class="btn btn-sm btn-outline-secondary" style="font-size:0.72rem;">
                                            <i class="bi bi-camera me-1"></i>Camera
                                        </button>
                                    </div>
                                    <button type="button" id="offClearPhotoBtn" class="btn btn-sm btn-outline-danger mt-1 w-100"
                                            style="font-size:0.7rem;display:none;" onclick="offClearPhoto()">
                                        <i class="bi bi-trash me-1"></i>Remove Photo
                                    </button>
                                    <div class="text-muted mt-2" style="font-size:0.67rem;">JPG or PNG · max 5MB</div>
                                </div>
                            </div>

                        </div>

                        <!-- ══ RIGHT: Form sections ══ -->
                        <div class="col-lg-9">

                            <!-- Section 1: Official Details -->
                            <div class="off-section">
                                <div class="off-section-head">
                                    <i class="bi bi-person-badge-fill" style="color:#1a4f7a;font-size:0.85rem;"></i>
                                    <span>Official Details</span>
                                </div>
                                <div class="off-section-body">

                                    <!-- Name row — same split fields for both add and edit -->
                                    <div id="addNameRow" class="row g-3 mb-3">
                                        <div class="col-md-3">
                                            <label class="off-field-label">First Name <span class="req">*</span></label>
                                            <input type="text" name="first_name" id="offFirstName" class="form-control" required placeholder="e.g. Juan"
                                                   oninput="offTriggerResidentCheck()">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="off-field-label">Middle Name</label>
                                            <input type="text" name="middle_name" id="offMiddleName" class="form-control" placeholder="e.g. Santos"
                                                   oninput="offTriggerResidentCheck()">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="off-field-label">Last Name <span class="req">*</span></label>
                                            <input type="text" name="last_name" id="offLastName" class="form-control" required placeholder="e.g. dela Cruz"
                                                   oninput="offTriggerResidentCheck()">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="off-field-label">Suffix</label>
                                            <select name="suffix" id="offSuffix" class="form-select">
                                                <option value="">None</option><option>Jr.</option><option>Sr.</option><option>II</option><option>III</option>
                                            </select>
                                        </div>
                                    </div>

                                    <!-- ── Resident status indicator ── -->
                                    <div id="offResidentStatusBar" style="display:none;margin-bottom:14px;">
                                        <!-- content injected by JS -->
                                    </div>

                                    <!-- Row 2: Position · Term Start · Term End · Status (4 equal cols) -->
                                    <div class="row g-3 mb-3">
                                        <div class="col-md-3">
                                            <label class="off-field-label">Position <span class="req">*</span></label>
                                            <select name="position" id="offPosition" class="form-select" required>
                                                <?php foreach ($positions as $p): ?><option><?= $p ?></option><?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="off-field-label">Term Start <span class="req">*</span></label>
                                            <input type="date" name="term_start" id="offTermStart" class="form-control" required>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="off-field-label">Term End <span class="req">*</span></label>
                                            <input type="date" name="term_end" id="offTermEnd" class="form-control" required>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="off-field-label">Status</label>
                                            <select name="status" id="offStatus" class="form-select">
                                                <option value="active">Active</option>
                                                <option value="inactive">Inactive</option>
                                            </select>
                                        </div>
                                    </div>

                                    <!-- Row 3: Contact · Email (each spans 6 cols = 2 equal halves) -->
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="off-field-label">Contact Number</label>
                                            <input type="text" name="contact" id="offContact" class="form-control"
                                                   placeholder="e.g. 09171234567" inputmode="numeric"
                                                   pattern="09[0-9]{9}" maxlength="11"
                                                   data-check-table="officials" data-exclude-id="0"
                                                   oninput="validateContact(this,'offContactErr')">
                                            <div id="offContactErr" class="text-danger mt-1" style="display:none;font-size:0.72rem;"></div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="off-field-label">Email Address</label>
                                            <input type="email" name="email" id="offEmail" class="form-control" placeholder="e.g. juan@email.com">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Section 2: Resident Info (add only) -->
                            <div id="residentSection" class="off-section">
                                <div class="off-section-head">
                                    <i class="bi bi-person-fill" style="color:#1a4f7a;font-size:0.85rem;"></i>
                                    <span>Resident Information</span>
                                    <span style="font-size:0.7rem;color:#2a5070;font-weight:400;text-transform:none;letter-spacing:0;">— auto-registered as a resident of this barangay</span>
                                </div>
                                <div class="off-section-body">
                                    <div class="row g-3">
                                        <div class="col-md-3">
                                            <label class="off-field-label">Birthdate <span class="req">*</span></label>
                                            <input type="date" name="birthdate" id="offBirthdate" class="form-control" onchange="offCalcAge(this.value)">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="off-field-label">Age</label>
                                            <input type="number" id="offAge" class="form-control" readonly placeholder="Auto">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="off-field-label">Gender <span class="req">*</span></label>
                                            <select name="gender" id="offGender" class="form-select">
                                                <option value="">Select</option>
                                                <option>Male</option><option>Female</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="off-field-label">Civil Status <span class="req">*</span></label>
                                            <select name="civil_status" id="offCivil" class="form-select">
                                                <option>Single</option><option>Married</option>
                                                <option>Widowed</option><option>Separated</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="off-field-label">Voter Status</label>
                                            <select name="voter_status" id="offVoter" class="form-select">
                                                <option value="Not Registered">Not Registered</option>
                                                <option value="Registered">Registered</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="off-field-label">Purok / Street / Zone</label>
                                            <input type="text" name="purok" id="offPurok" class="form-control" placeholder="e.g. Purok 3, Rizal St.">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="off-field-label">Barangay Address</label>
                                            <input type="text" class="form-control" readonly
                                                   value="<?= htmlspecialchars(($brgyInfo['barangay_name'] ?? '') . ', ' . ($brgyInfo['municipality'] ?? '')) ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>

                        </div><!-- /col-lg-9 -->
                    </div><!-- /row -->

                </div><!-- /modal-body -->

                <!-- ── Modal footer ── -->
                <div class="modal-footer" style="background:#fff;border-top:1px solid #e5e7eb;padding:10px 20px;gap:6px;">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg me-1"></i>Cancel
                    </button>

                    <!-- Save only — always present, style changes based on resident check -->
                    <button type="button" class="btn btn-sm btn-outline-primary fw-semibold" id="offSubmitBtn"
                            onclick="submitOfficialForm(this, '0')">
                        <i class="bi bi-check-lg me-1"></i><span id="offSubmitLabel">Save Official</span>
                    </button>

                    <!-- Register as Resident — hidden when already a resident or in edit mode -->
                    <button type="button" class="btn btn-sm btn-success fw-semibold" id="offRegisterBtn"
                            onclick="submitOfficialForm(this, '1')" style="display:none;">
                        <i class="bi bi-person-plus-fill me-1"></i>Save &amp; Register as Resident
                    </button>
                </div>

            </div><!-- /modal-content -->
        </form>
    </div>
</div>

<!-- ══ VIEW OFFICIAL OFFCANVAS ══════════════════════════════ -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="viewOfficialCanvas"
     style="width:380px;border-left:1px solid #e5e7eb;">
    <div class="offcanvas-header" style="background:linear-gradient(90deg,#052659,#5483B3);padding:14px 18px;">
        <div class="d-flex align-items-center gap-2">
            <div style="width:32px;height:32px;background:rgba(255,255,255,0.15);border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="bi bi-person-badge-fill text-white" style="font-size:0.9rem;"></i>
            </div>
            <div>
                <div style="font-size:0.65rem;color:rgba(255,255,255,0.65);text-transform:uppercase;letter-spacing:.07em;">Official Profile</div>
                <div id="vOffName" style="font-size:0.92rem;font-weight:700;color:#fff;line-height:1.2;"></div>
            </div>
        </div>
        <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="offcanvas"></button>
    </div>

    <div class="offcanvas-body p-0" style="overflow-y:auto;">

        <!-- Photo + status -->
        <div style="background:#f8fafc;border-bottom:1px solid #e5e7eb;padding:20px;display:flex;align-items:center;gap:16px;">
            <div id="vOffPhotoWrap" style="width:72px;height:90px;border-radius:8px;overflow:hidden;flex-shrink:0;border:1.5px solid #d0dce8;background:#e8f0f8;display:flex;align-items:center;justify-content:center;">
                <i class="bi bi-person-fill" style="font-size:2rem;color:#aab8cc;" id="vOffPhotoIcon"></i>
                <img id="vOffPhoto" src="" alt="" style="display:none;width:100%;height:100%;object-fit:cover;">
            </div>
            <div style="flex:1;min-width:0;">
                <div id="vOffPosition" style="font-size:0.78rem;font-weight:700;color:#052659;margin-bottom:5px;"></div>
                <div id="vOffStatusBadge"></div>
                <div id="vOffTermRange" style="font-size:0.72rem;color:#5483B3;margin-top:6px;display:flex;align-items:center;gap:4px;">
                    <i class="bi bi-calendar3"></i><span></span>
                </div>
            </div>
        </div>

        <!-- Details rows -->
        <div style="padding:16px 20px;">
            <div style="font-size:0.68rem;font-weight:700;color:#5483B3;text-transform:uppercase;letter-spacing:.07em;margin-bottom:10px;">Contact Information</div>

            <div class="voff-row" id="vOffContactRow">
                <div class="voff-label"><i class="bi bi-telephone-fill me-2" style="color:#5483B3;"></i>Contact Number</div>
                <div class="voff-val" id="vOffContact"></div>
            </div>
            <div class="voff-row" id="vOffEmailRow">
                <div class="voff-label"><i class="bi bi-envelope-fill me-2" style="color:#5483B3;"></i>Email Address</div>
                <div class="voff-val" id="vOffEmail"></div>
            </div>

            <div style="font-size:0.68rem;font-weight:700;color:#5483B3;text-transform:uppercase;letter-spacing:.07em;margin:16px 0 10px;">Term Details</div>

            <div class="voff-row">
                <div class="voff-label"><i class="bi bi-calendar-check me-2" style="color:#5483B3;"></i>Term Start</div>
                <div class="voff-val" id="vOffTermStart"></div>
            </div>
            <div class="voff-row">
                <div class="voff-label"><i class="bi bi-calendar-x me-2" style="color:#5483B3;"></i>Term End</div>
                <div class="voff-val" id="vOffTermEnd"></div>
            </div>
            <div class="voff-row" id="vOffExpiredRow" style="display:none;">
                <div class="voff-label"><i class="bi bi-exclamation-triangle-fill me-2" style="color:#dc2626;"></i>Term Status</div>
                <div class="voff-val"><span style="background:#fee2e2;color:#991b1b;font-size:0.72rem;font-weight:700;padding:2px 10px;border-radius:20px;border:1px solid #fca5a5;">Term Ended</span></div>
            </div>
        </div>

        <!-- Action buttons -->
        <div style="padding:0 20px 20px;display:flex;gap:8px;">
            <button type="button" class="btn btn-sm btn-outline-primary flex-fill"
                    id="vOffEditBtn" onclick="">
                <i class="bi bi-pencil me-1"></i>Edit
            </button>
        </div>

    </div>
</div>

<!-- View offcanvas styles -->
<style>
.voff-row {
    display:flex;align-items:flex-start;gap:8px;
    padding:8px 0;border-bottom:1px solid #f0f4f8;
    font-size:0.82rem;
}
.voff-row:last-child { border-bottom:none; }
.voff-label { color:#6b7280;min-width:140px;flex-shrink:0;font-size:0.78rem; }
.voff-val   { color:#111827;font-weight:500;flex:1;word-break:break-word; }
</style>

<!-- Camera modal for official photo -->
<div class="modal fade" id="offCameraModal" tabindex="-1" style="z-index:1060;">
    <div class="modal-dialog modal-dialog-centered" style="max-width:420px;">
        <div class="modal-content" style="border-radius:14px;overflow:hidden;">
            <div class="modal-header" style="background:linear-gradient(90deg,#1a4f7a,#2d7db3);border:none;">
                <h6 class="modal-title text-white fw-bold mb-0"><i class="bi bi-camera-fill me-2"></i>Take Photo</h6>
                <button type="button" class="btn-close btn-close-white" onclick="offCloseCamera()"></button>
            </div>
            <div class="modal-body text-center p-3">
                <video id="offCameraStream" autoplay playsinline style="width:100%;border-radius:8px;background:#000;"></video>
                <canvas id="offCameraCanvas" style="display:none;"></canvas>
            </div>
            <div class="modal-footer justify-content-center border-0 pb-3">
                <button type="button" class="btn btn-primary px-5" onclick="offSnapPhoto()">
                    <i class="bi bi-camera me-1"></i>Capture
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Archive Official Modal -->
<div class="modal fade" id="archiveOfficialModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST">
            <input type="hidden" name="action" value="archive">
            <input type="hidden" name="official_id" id="archOffId">
            <input type="hidden" name="csrf_token" id="archCsrfToken" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES) ?>">
            <div class="modal-content" style="border-radius:16px; overflow:hidden;">
                <div class="d-flex" style="min-height:280px;">
                    <!-- LEFT PANEL -->
                    <div class="d-flex flex-column align-items-center justify-content-center px-4 py-5"
                         style="background:linear-gradient(160deg,#c62828 0%,#e53935 100%); min-width:200px; width:200px; flex-shrink:0;">
                        <div style="width:72px;height:72px;background:rgba(255,255,255,0.15);border-radius:50%;display:flex;align-items:center;justify-content:center;border:3px solid rgba(255,255,255,0.3);">
                            <i class="bi bi-archive-fill text-white" style="font-size:2rem;"></i>
                        </div>
                        <h5 class="fw-bold mt-3 mb-1 text-white text-center" style="font-size:1rem;">Archive Official</h5>
                        <p class="text-white-50 small text-center mb-0">This action is reversible</p>
                    </div>
                    <!-- RIGHT PANEL -->
                    <div class="flex-fill d-flex flex-column justify-content-between p-4">
                        <div>
                            <p class="small text-muted mb-3">Archiving: <strong id="archOffName"></strong></p>
                            <div id="termEndNotice" class="alert alert-warning small py-2 mb-3" style="display:none;">
                                <i class="bi bi-clock me-1"></i>Term ended on <strong id="archOffTermEnd"></strong>
                            </div>
                            <label class="form-label fw-semibold small">Reason for Archiving <span class="text-danger">*</span></label>
                            <select name="archive_reason" id="archOffReason" class="form-select mb-2" required onchange="toggleCustomReason(this)">
                                <option value="Term ended">Term ended</option>
                                <option value="Resigned">Resigned</option>
                                <option value="Removed from office">Removed from office</option>
                                <option value="Deceased">Deceased</option>
                                <option value="Other">Other (specify)</option>
                            </select>
                            <input type="text" name="archive_reason_custom" id="archOffReasonCustom"
                                   class="form-control form-control-sm" placeholder="Specify reason..."
                                   style="display:none;">
                        </div>
                        <div class="d-flex gap-2 mt-4">
                            <button type="button" class="btn btn-outline-secondary flex-fill" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-danger flex-fill fw-semibold" onclick="submitArchiveOfficial(this)">
                                <i class="bi bi-archive me-1"></i>Archive
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* ══ System setting: official term years ══ */
const OFFICIAL_TERM_YEARS = <?= (int)$officialTermYears ?>;

/* ══ Auto-calculate Term End from Term Start ══ */
document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('offTermStart').addEventListener('change', function () {
        const startVal = this.value;
        if (!startVal) return;
        // Add OFFICIAL_TERM_YEARS years to get term end
        const d = new Date(startVal);
        d.setFullYear(d.getFullYear() + OFFICIAL_TERM_YEARS);
        // Format as YYYY-MM-DD
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        const termEndVal = `${y}-${m}-${day}`;
        // Set using lguSetDate so the visible text field also updates
        if (typeof lguSetDate === 'function') {
            lguSetDate('offTermEnd', termEndVal);
        } else {
            document.getElementById('offTermEnd').value = termEndVal;
        }
    });
});
/* ══ Age calc ══ */
function offCalcAge(bdate) {
    if (!bdate) { document.getElementById('offAge').value = ''; return; }
    const today = new Date(), dob = new Date(bdate);
    let age = today.getFullYear() - dob.getFullYear();
    const m = today.getMonth() - dob.getMonth();
    if (m < 0 || (m === 0 && today.getDate() < dob.getDate())) age--;
    document.getElementById('offAge').value = age >= 0 ? age : '';
}

/* ══ Photo drop zone ══ */
const offDropZone = document.getElementById('offPhotoDropZone');
offDropZone.addEventListener('dragover', e => { e.preventDefault(); offDropZone.style.borderColor = '#3a7fa8'; });
offDropZone.addEventListener('dragleave', () => { offDropZone.style.borderColor = '#b0c4d8'; });
offDropZone.addEventListener('drop', e => {
    e.preventDefault();
    offDropZone.style.borderColor = '#b0c4d8';
    const file = e.dataTransfer.files[0];
    if (file && file.type.startsWith('image/')) offLoadPhotoFile(file);
});
document.getElementById('offPhotoFileInput').addEventListener('change', function() {
    if (this.files[0]) offLoadPhotoFile(this.files[0]);
});
function offLoadPhotoFile(file) {
    if (file.size > 5 * 1024 * 1024) { alert('Photo must be under 5MB.'); return; }
    const reader = new FileReader();
    reader.onload = e => {
        document.getElementById('offPhotoPreview').src = e.target.result;
        document.getElementById('offPhotoPreview').style.display = 'block';
        document.getElementById('offPhotoPlaceholder').style.display = 'none';
        document.getElementById('offClearPhotoBtn').style.display = '';
        document.getElementById('offPhotoBase64').value = e.target.result;
    };
    reader.readAsDataURL(file);
}
function offClearPhoto() {
    document.getElementById('offPhotoPreview').src = '';
    document.getElementById('offPhotoPreview').style.display = 'none';
    document.getElementById('offPhotoPlaceholder').style.display = '';
    document.getElementById('offClearPhotoBtn').style.display = 'none';
    document.getElementById('offPhotoBase64').value = '';
    document.getElementById('offPhotoFileInput').value = '';
}

/* ══ Camera ══ */
let offCameraStream = null;
document.getElementById('offOpenCameraBtn').addEventListener('click', function() {
    new bootstrap.Modal(document.getElementById('offCameraModal')).show();
    navigator.mediaDevices.getUserMedia({ video: true }).then(stream => {
        offCameraStream = stream;
        document.getElementById('offCameraStream').srcObject = stream;
    }).catch(() => alert('Camera access denied or not available.'));
});
function offCloseCamera() {
    if (offCameraStream) { offCameraStream.getTracks().forEach(t => t.stop()); offCameraStream = null; }
    bootstrap.Modal.getInstance(document.getElementById('offCameraModal'))?.hide();
}
function offSnapPhoto() {
    const video = document.getElementById('offCameraStream');
    const canvas = document.getElementById('offCameraCanvas');
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext('2d').drawImage(video, 0, 0);
    const dataUrl = canvas.toDataURL('image/jpeg', 0.85);
    document.getElementById('offPhotoPreview').src = dataUrl;
    document.getElementById('offPhotoPreview').style.display = 'block';
    document.getElementById('offPhotoPlaceholder').style.display = 'none';
    document.getElementById('offClearPhotoBtn').style.display = '';
    document.getElementById('offPhotoBase64').value = dataUrl;
    offCloseCamera();
}
// Close camera when modal is dismissed any other way
document.getElementById('offCameraModal').addEventListener('hidden.bs.modal', function() {
    if (offCameraStream) { offCameraStream.getTracks().forEach(t => t.stop()); offCameraStream = null; }
});

/* ══ Reset / populate form ══ */
function resetOfficialForm() {
    document.getElementById('offAction').value = 'add';
    document.getElementById('offId').value = '';
    document.getElementById('offFullName').value = '';
    document.getElementById('offRegisterFlag').value = '0';
    document.getElementById('offModalTitle').textContent = 'Add New Official';
    document.getElementById('offModalSub').textContent = 'Fill in all required fields to register a barangay official.';
    document.getElementById('officialForm').reset();
    // Clear enhanced date fields (form.reset() doesn't fire change, so do it manually)
    lguSetDate('offTermStart', '');
    lguSetDate('offTermEnd',   '');
    lguSetDate('offBirthdate', '');
    offClearPhoto();
    // Contact validation reset
    document.getElementById('offContact').classList.remove('is-invalid', 'is-valid');
    document.getElementById('offContactErr').style.display = 'none';
    // Name fields always visible
    document.getElementById('offFirstName').required = true;
    document.getElementById('offLastName').required  = true;
    // Resident section — show it; required state is managed by offSetResidentStatus()
    document.getElementById('residentSection').style.display = '';
    document.getElementById('offBirthdate').required = false;  // controlled by resident-check
    document.getElementById('offGender').required    = false;  // controlled by resident-check
    // Reset resident-check state and status bar
    _offResCheckLast = '';   // allow re-check if same name is typed again
    offSetResidentStatus('idle');
    document.getElementById('offResidentStatusBar').style.display = 'none';
}

function editOfficial(o) {
    document.getElementById('offAction').value = 'edit';
    document.getElementById('offId').value = o.id;
    document.getElementById('offFullName').value = o.full_name;
    document.getElementById('offModalTitle').textContent = 'Edit Official';
    document.getElementById('offModalSub').textContent = 'Update the details of the barangay official.';

    // Parse stored full_name ("Last, First Middle Suffix") into separate fields
    const nameParts  = o.full_name.split(',');
    const parsedLast = (nameParts[0] || '').trim();
    const remainder  = (nameParts[1] || '').trim();
    // Split remainder into words; detect known suffixes
    const suffixes   = ['Jr.','Sr.','II','III','IV','V'];
    const remWords   = remainder.split(' ').filter(w => w.length > 0);
    let parsedFirst  = '';
    let parsedMid    = '';
    let parsedSuffix = '';
    if (remWords.length >= 1) parsedFirst = remWords[0];
    if (remWords.length >= 2) {
        // Last word might be suffix
        const lastWord = remWords[remWords.length - 1];
        if (suffixes.includes(lastWord) && remWords.length >= 3) {
            parsedSuffix = lastWord;
            parsedMid = remWords.slice(1, remWords.length - 1).join(' ');
        } else if (suffixes.includes(lastWord) && remWords.length === 2) {
            parsedSuffix = lastWord;
        } else {
            parsedMid = remWords.slice(1).join(' ');
        }
    }

    document.getElementById('offFirstName').value  = parsedFirst;
    document.getElementById('offMiddleName').value = parsedMid;
    document.getElementById('offLastName').value   = parsedLast;
    // Set suffix dropdown
    const suffixSel = document.getElementById('offSuffix');
    suffixSel.value = parsedSuffix;
    if (!suffixSel.value) suffixSel.selectedIndex = 0; // fallback to "None"

    // Name fields always required
    document.getElementById('offFirstName').required = true;
    document.getElementById('offLastName').required  = true;

    // Resident section hidden on edit (not needed — redirect handles it)
    document.getElementById('residentSection').style.display = 'none';
    document.getElementById('offBirthdate').required = false;
    document.getElementById('offGender').required = false;

    // Fields
    document.getElementById('offPosition').value = o.position;
    // Use lguSetDate so the visible typeable text field also updates
    lguSetDate('offTermStart', o.term_start || '');
    lguSetDate('offTermEnd',   o.term_end   || '');
    document.getElementById('offContact').value = o.contact || '';
    document.getElementById('offContact').classList.remove('is-invalid', 'is-valid');
    document.getElementById('offContactErr').style.display = 'none';
    document.getElementById('offContact').dataset.excludeId = o.id;
    document.getElementById('offEmail').value = o.email || '';
    document.getElementById('offStatus').value = o.status;
    // Photo preview (existing)
    if (o.photo) {
        const photoUrl = '<?= BASE_URL ?>/uploads/' + o.photo;
        document.getElementById('offPhotoPreview').src = photoUrl;
        document.getElementById('offPhotoPreview').style.display = 'block';
        document.getElementById('offPhotoPlaceholder').style.display = 'none';
        document.getElementById('offClearPhotoBtn').style.display = '';
    } else {
        offClearPhoto();
    }
    document.getElementById('offSubmitBtn').className = 'btn btn-sm btn-primary fw-semibold';
    document.getElementById('offSubmitLabel').textContent = 'Update Official';
    document.getElementById('offRegisterFlag').value = '0';

    // ── Check if this official is already a resident ──────────────────
    const bar         = document.getElementById('offResidentStatusBar');
    const registerBtn = document.getElementById('offRegisterBtn');

    if (parsedFirst.length >= 2 && parsedLast.length >= 2) {
        bar.style.display = '';
        bar.innerHTML = `<div style="display:flex;align-items:center;gap:10px;padding:9px 14px;
            background:#f0f6ff;border:1px solid #c7d9f0;border-radius:9px;font-size:0.82rem;">
            <span class="spinner-border spinner-border-sm text-primary" style="width:14px;height:14px;border-width:2px;flex-shrink:0;"></span>
            <span style="color:#374151;">Checking resident records\u2026</span></div>`;

        let url = '<?= BASE_URL ?>/ajax/check_resident_exists.php?'
            + 'first_name=' + encodeURIComponent(parsedFirst)
            + '&last_name='  + encodeURIComponent(parsedLast);
        if (parsedMid) url += '&middle_name=' + encodeURIComponent(parsedMid);

        fetch(url, { credentials: 'same-origin', cache: 'no-store' })
            .then(r => r.json())
            .then(data => {
                if (data.found) {
                    const viewUrl = '<?= BASE_URL ?>/barangay_admin/view_resident.php?id=' + (data.id || '');
                    bar.innerHTML = `<div style="display:flex;align-items:center;gap:12px;padding:10px 14px;
                        background:#f0fdf4;border:1px solid #86efac;border-radius:9px;font-size:0.82rem;">
                        <div style="width:32px;height:32px;min-width:32px;background:#16a34a;border-radius:50%;
                            display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <i class="bi bi-check-lg text-white" style="font-size:1rem;"></i>
                        </div>
                        <div style="flex:1;min-width:0;">
                            <div style="font-weight:700;color:#15803d;">Already a Resident</div>
                            <div style="color:#166534;margin-top:1px;">
                                <strong>${escHtml(data.name || '')}</strong> is already registered.
                                <span style="background:#dcfce7;color:#166534;font-size:0.7rem;font-weight:600;
                                    padding:1px 8px;border-radius:20px;margin-left:4px;">${escHtml(data.status || 'Active')}</span>
                            </div>
                        </div>
                        <a href="${viewUrl}" target="_blank" class="btn btn-sm btn-outline-success" style="white-space:nowrap;font-size:0.75rem;">
                            <i class="bi bi-eye me-1"></i>View
                        </a></div>`;
                    registerBtn.style.display = 'none';
                } else {
                    bar.innerHTML = `<div style="display:flex;align-items:center;gap:12px;padding:10px 14px;
                        background:#fffbeb;border:1px solid #fcd34d;border-radius:9px;font-size:0.82rem;">
                        <div style="width:32px;height:32px;min-width:32px;background:#d97706;border-radius:50%;
                            display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <i class="bi bi-person-plus-fill text-white" style="font-size:0.85rem;"></i>
                        </div>
                        <div style="flex:1;">
                            <div style="font-weight:700;color:#92400e;">Not yet a Resident</div>
                            <div style="color:#78350f;margin-top:1px;">
                                Use <strong>Save &amp; Register as Resident</strong> to also add this official as a barangay resident.
                            </div>
                        </div></div>`;
                    registerBtn.style.display = '';
                    registerBtn.disabled = false;
                }
            })
            .catch(() => {
                bar.style.display = 'none';
                registerBtn.style.display = '';
                registerBtn.disabled = false;
            });
    } else {
        bar.style.display = 'none';
        registerBtn.style.display = '';
        registerBtn.disabled = false;
    }

    new bootstrap.Modal(document.getElementById('officialModal')).show();
}

// NOTE: full_name is synced inside submitOfficialForm() before submitting.
// The listener below is kept only as a safety net.
document.getElementById('officialForm').addEventListener('submit', function() {
    const fn  = (document.getElementById('offFirstName').value  || '').trim();
    const mn  = (document.getElementById('offMiddleName').value || '').trim();
    const ln  = (document.getElementById('offLastName').value   || '').trim();
    const sfx = (document.getElementById('offSuffix').value     || '').trim();
    document.getElementById('offFullName').value = (ln + ', ' + fn + (mn ? ' ' + mn : '') + (sfx ? ' ' + sfx : '')).trim();
});

/**
 * Fetch a fresh CSRF token then submit the official form.
 * Using type="button" + this function avoids the stale-token 403 that occurs
 * when the session token was rotated by a previous POST on the same page.
 * @param {HTMLElement} btn        - the button that was clicked (used to show spinner)
 * @param {string}      registerFlag - '1' = Register as Resident, '0' = Save only
 */
function submitOfficialForm(btn, registerFlag) {
    // Sync full_name from split name fields (both add and edit)
    const fn  = (document.getElementById('offFirstName').value  || '').trim();
    const mn  = (document.getElementById('offMiddleName').value || '').trim();
    const ln  = (document.getElementById('offLastName').value   || '').trim();
    const sfx = (document.getElementById('offSuffix').value     || '').trim();
    document.getElementById('offFullName').value = (ln + ', ' + fn + (mn ? ' ' + mn : '') + (sfx ? ' ' + sfx : '')).trim();

    // Set the register flag
    document.getElementById('offRegisterFlag').value = registerFlag;

    // Disable button to prevent double-submit
    const origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving…';

    document.getElementById('officialForm').submit();
}

/* ══ View Official ══ */
function viewOfficial(o) {
    var baseUrl = '<?= BASE_URL ?>';
    var today   = new Date().toISOString().split('T')[0];

    // Name
    document.getElementById('vOffName').textContent = o.full_name || '';

    // Photo
    var photoImg  = document.getElementById('vOffPhoto');
    var photoIcon = document.getElementById('vOffPhotoIcon');
    if (o.photo) {
        photoImg.src          = baseUrl + '/uploads/' + o.photo;
        photoImg.style.display = 'block';
        photoIcon.style.display = 'none';
    } else {
        photoImg.style.display = 'none';
        photoIcon.style.display = '';
    }

    // Position
    document.getElementById('vOffPosition').textContent = o.position || '';

    // Status badge
    var statusBadge = document.getElementById('vOffStatusBadge');
    if (o.status === 'active') {
        statusBadge.innerHTML = '<span style="background:#e0f2fb;color:#0d3a5c;font-size:0.72rem;font-weight:700;padding:3px 10px;border-radius:20px;">Active</span>';
    } else {
        statusBadge.innerHTML = '<span style="background:#f1f5f9;color:#64748b;font-size:0.72rem;font-weight:700;padding:3px 10px;border-radius:20px;">Inactive</span>';
    }

    // Term range (short format)
    var termEl = document.getElementById('vOffTermRange').querySelector('span');
    var fmtDate = function(d) {
        if (!d) return '—';
        var parts = d.split('-');
        if (parts.length !== 3) return d;
        var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        return months[parseInt(parts[1],10)-1] + ' ' + parseInt(parts[2],10) + ', ' + parts[0];
    };
    termEl.textContent = fmtDate(o.term_start) + ' — ' + fmtDate(o.term_end);

    // Full term dates
    document.getElementById('vOffTermStart').textContent = fmtDate(o.term_start);
    var termEndEl = document.getElementById('vOffTermEnd');
    termEndEl.textContent = fmtDate(o.term_end);
    if (o.term_end && o.term_end < today && o.status === 'active') {
        termEndEl.style.color = '#dc2626';
        document.getElementById('vOffExpiredRow').style.display = '';
    } else {
        termEndEl.style.color = '';
        document.getElementById('vOffExpiredRow').style.display = 'none';
    }

    // Contact
    var contactRow = document.getElementById('vOffContactRow');
    document.getElementById('vOffContact').textContent = o.contact || '—';
    contactRow.style.display = '';

    // Email
    var emailRow = document.getElementById('vOffEmailRow');
    var emailEl  = document.getElementById('vOffEmail');
    if (o.email) {
        emailEl.innerHTML = '<a href="mailto:' + o.email + '" style="color:#052659;">' + o.email + '</a>';
        emailRow.style.display = '';
    } else {
        emailEl.textContent = '—';
        emailRow.style.display = '';
    }

    // Wire Edit button to open the edit modal with this official's data
    document.getElementById('vOffEditBtn').onclick = function () {
        bootstrap.Offcanvas.getInstance(document.getElementById('viewOfficialCanvas'))?.hide();
        setTimeout(function () { editOfficial(o); }, 300);
    };

    // Open the offcanvas
    new bootstrap.Offcanvas(document.getElementById('viewOfficialCanvas')).show();
}

/* ══ Archive modal ══ */
function openArchiveOfficialModal(id, name, termEnd) {
    document.getElementById('archOffId').value = id;
    document.getElementById('archOffName').textContent = name;

    const today = new Date().toISOString().split('T')[0];
    const notice = document.getElementById('termEndNotice');
    if (termEnd && termEnd < today) {
        document.getElementById('archOffTermEnd').textContent = termEnd;
        notice.style.display = 'block';
        document.getElementById('archOffReason').value = 'Term ended';
    } else {
        notice.style.display = 'none';
    }
    toggleCustomReason(document.getElementById('archOffReason'));
    new bootstrap.Modal(document.getElementById('archiveOfficialModal')).show();
}
function toggleCustomReason(sel) {
    const custom = document.getElementById('archOffReasonCustom');
    custom.style.display = sel.value === 'Other' ? 'block' : 'none';
    custom.required = sel.value === 'Other';
}
function applyCustomReason() {
    const sel = document.getElementById('archOffReason');
    const custom = document.getElementById('archOffReasonCustom');
    if (sel.value === 'Other' && custom.value.trim()) {
        sel.value = custom.value.trim();
    }
}

function submitArchiveOfficial(btn) {
    // Apply custom reason text into the select value first
    applyCustomReason();

    // Validate the reason field
    const sel = document.getElementById('archOffReason');
    if (!sel.value.trim()) { alert('Please select a reason.'); return; }

    // Disable button to prevent double-submit
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Archiving…';

    document.getElementById('archiveOfficialModal').querySelector('form').submit();
}

/* ══ Misc ══ */
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('show'); }

document.querySelectorAll('.page-nav').forEach(function(link) {
    link.addEventListener('click', function(e) {
        if (this.parentElement.classList.contains('disabled')) return;
        e.preventDefault();
        var t = document.getElementById('logs-table');
        if (t) t.scrollIntoView({ behavior:'smooth', block:'start' });
        var href = this.getAttribute('href');
        setTimeout(function(){ window.location.href = href; }, 350);
    });
});
if (window.location.hash === '#logs-table') {
    setTimeout(function(){ var t=document.getElementById('logs-table'); if(t) t.scrollIntoView({behavior:'smooth',block:'start'}); }, 80);
}

/* ══ RESIDENT CHECK — smart duplicate detection ══════════════════════════
 * Fires whenever first_name, middle_name, or last_name changes in add mode.
 * When middle name is supplied it is included in the lookup so that two
 * people sharing the same first+last but different middle names are not
 * confused with each other.
 * Results:
 *   'idle'     — names not complete yet, no check performed
 *   'checking' — AJAX in-flight
 *   'found'    — resident exists → show green banner, hide Register button
 *   'notfound' — no match → show amber banner, make Register button the CTA
 */
let _offResCheckTimer = null;
let _offResCheckLast  = '';   // last queried triplet to avoid duplicate requests

function offTriggerResidentCheck() {
    const fn = (document.getElementById('offFirstName').value  || '').trim();
    const mn = (document.getElementById('offMiddleName').value || '').trim();
    const ln = (document.getElementById('offLastName').value   || '').trim();

    // Only fire when both first + last have ≥2 chars each
    if (fn.length < 2 || ln.length < 2) {
        offSetResidentStatus('idle');
        return;
    }

    // Include middle name in the cache key so changing it re-fires the check
    const key = fn.toLowerCase() + '|' + mn.toLowerCase() + '|' + ln.toLowerCase();
    if (key === _offResCheckLast) return; // already showed result for this combination

    clearTimeout(_offResCheckTimer);
    offSetResidentStatus('checking');

    _offResCheckTimer = setTimeout(function () {
        _offResCheckLast = key;
        let url = '<?= BASE_URL ?>/ajax/check_resident_exists.php?'
            + 'first_name=' + encodeURIComponent(fn)
            + '&last_name='  + encodeURIComponent(ln);
        if (mn) url += '&middle_name=' + encodeURIComponent(mn);

        fetch(url, { credentials: 'same-origin', cache: 'no-store' })
            .then(r => r.json())
            .then(data => {
                offSetResidentStatus(data.found ? 'found' : 'notfound', data);
            })
            .catch(() => {
                offSetResidentStatus('idle'); // silent fail — don't block the form
            });
    }, 420); // 420 ms debounce
}

/**
 * Update the status bar and footer buttons based on check result.
 * @param {'idle'|'checking'|'found'|'notfound'} state
 * @param {object} [data]  — the AJAX response (when state === 'found')
 */
function offSetResidentStatus(state, data) {
    const bar         = document.getElementById('offResidentStatusBar');
    const submitBtn   = document.getElementById('offSubmitBtn');
    const submitLabel = document.getElementById('offSubmitLabel');
    const registerBtn = document.getElementById('offRegisterBtn');
    const resSec      = document.getElementById('residentSection');

    if (state === 'idle') {
        bar.style.display   = 'none';
        bar.innerHTML       = '';
        // Default add-mode layout: Register as Resident is the primary CTA
        submitBtn.className   = 'btn btn-sm btn-outline-secondary fw-semibold';
        submitLabel.textContent = 'Save Official Only';
        registerBtn.style.display = '';
        registerBtn.disabled  = false;
        resSec.style.display  = '';
        return;
    }

    if (state === 'checking') {
        bar.style.display = '';
        bar.innerHTML = `
            <div style="display:flex;align-items:center;gap:10px;padding:9px 14px;
                        background:#f0f6ff;border:1px solid #c7d9f0;border-radius:9px;font-size:0.82rem;">
                <span class="spinner-border spinner-border-sm text-primary" style="width:14px;height:14px;border-width:2px;flex-shrink:0;"></span>
                <span style="color:#374151;">Checking resident records…</span>
            </div>`;
        return;
    }

    if (state === 'found') {
        const viewUrl = '<?= BASE_URL ?>/barangay_admin/view_resident.php?id=' + (data.id || '');
        bar.style.display = '';
        bar.innerHTML = `
            <div style="display:flex;align-items:center;gap:12px;padding:10px 14px;
                        background:#f0fdf4;border:1px solid #86efac;border-radius:9px;font-size:0.82rem;">
                <div style="width:32px;height:32px;min-width:32px;background:#16a34a;border-radius:50%;
                            display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="bi bi-check-lg text-white" style="font-size:1rem;"></i>
                </div>
                <div style="flex:1;min-width:0;">
                    <div style="font-weight:700;color:#15803d;">Already a Resident</div>
                    <div style="color:#166534;margin-top:1px;">
                        <strong>${escHtml(data.name || '')}</strong>
                        is already registered as a resident
                        <span style="background:#dcfce7;color:#166534;font-size:0.7rem;font-weight:600;
                                     padding:1px 8px;border-radius:20px;margin-left:4px;">
                            ${escHtml(data.status || 'Active')}
                        </span>
                    </div>
                </div>
                <a href="${viewUrl}" target="_blank"
                   class="btn btn-sm btn-outline-success" style="white-space:nowrap;font-size:0.75rem;">
                    <i class="bi bi-eye me-1"></i>View
                </a>
            </div>`;

        // Already a resident → only "Save Official" needed (solid primary), hide Register button
        submitBtn.className   = 'btn btn-sm btn-primary fw-semibold';
        submitLabel.textContent = 'Save Official';
        registerBtn.style.display = 'none';
        // Hide resident-info section — no need to collect those fields
        resSec.style.display  = 'none';
        document.getElementById('offBirthdate').required = false;
        document.getElementById('offGender').required    = false;
        return;
    }

    if (state === 'notfound') {
        bar.style.display = '';
        bar.innerHTML = `
            <div style="display:flex;align-items:center;gap:12px;padding:10px 14px;
                        background:#fffbeb;border:1px solid #fcd34d;border-radius:9px;font-size:0.82rem;">
                <div style="width:32px;height:32px;min-width:32px;background:#d97706;border-radius:50%;
                            display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="bi bi-person-plus-fill text-white" style="font-size:0.85rem;"></i>
                </div>
                <div style="flex:1;">
                    <div style="font-weight:700;color:#92400e;">Not yet a Resident</div>
                    <div style="color:#78350f;margin-top:1px;">
                        This person is not registered as a resident.
                        Use <strong>Save &amp; Register as Resident</strong> to complete both records.
                    </div>
                </div>
            </div>`;

        // Not a resident → Register as Resident is the main CTA, Save Official is secondary
        submitBtn.className   = 'btn btn-sm btn-outline-secondary fw-semibold';
        submitLabel.textContent = 'Save Official Only';
        registerBtn.style.display = '';
        registerBtn.disabled  = false;
        // Show resident-info section so extra fields can be filled
        resSec.style.display  = '';
        document.getElementById('offBirthdate').required = true;
        document.getElementById('offGender').required    = true;
        return;
    }
}

/** Minimal HTML escaping for user-supplied strings in innerHTML */
function escHtml(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}
</script>
<style>.btn-xs { padding: 2px 8px; font-size: 0.75rem; }</style>
</body>
</html>


