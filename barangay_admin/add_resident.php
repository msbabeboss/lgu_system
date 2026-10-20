<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/mailer.php';
requireRole('barangay_admin');

$db  = Database::getInstance()->getConnection();
$u   = currentUser();
$bid = (int)$u['barangay_id'];

// Safe migrations
foreach ([
    "ALTER TABLE addresses ADD COLUMN region VARCHAR(100) NULL AFTER id",
    "ALTER TABLE addresses ADD COLUMN zip_code VARCHAR(10) NULL",
    "ALTER TABLE barangays ADD COLUMN region VARCHAR(100) NULL",
    "ALTER TABLE barangays ADD COLUMN province VARCHAR(100) NULL",
    "ALTER TABLE barangays ADD COLUMN zip_code VARCHAR(10) NULL",
    "ALTER TABLE residents ADD COLUMN transfer_from_barangay VARCHAR(150) NULL",
    "ALTER TABLE residents ADD COLUMN transfer_to_barangay VARCHAR(150) NULL",
    "ALTER TABLE residents ADD COLUMN transfer_outside TINYINT(1) DEFAULT 0",
    "ALTER TABLE residents ADD COLUMN transfer_reason VARCHAR(255) NULL",
    "ALTER TABLE residents ADD COLUMN archive_reason VARCHAR(255) NULL",
    "ALTER TABLE residents ADD COLUMN photo VARCHAR(255) NULL",
    "ALTER TABLE residents ADD COLUMN official_id INT UNSIGNED NULL",
    "ALTER TABLE residents ADD COLUMN official_position VARCHAR(100) NULL",
    "ALTER TABLE residents ADD COLUMN email VARCHAR(150) NULL",
] as $sql) { try { $db->exec($sql); } catch (PDOException $e) {} }

// Fetch barangay info
$brgyInfoStmt = $db->prepare("SELECT id, barangay_name, municipality, region, province, zip_code FROM barangays WHERE id=?");
$brgyInfoStmt->execute([$bid]);
$brgyInfo = $brgyInfoStmt->fetch() ?: [];

// Photo dir
$photoDir = __DIR__ . '/../uploads/residents/';
if (!is_dir($photoDir)) { mkdir($photoDir, 0755, true); }

// Active households for dropdown
$activeHouseholdsStmt = $db->prepare(
    "SELECT h.id, h.household_code, CONCAT(r.last_name, ', ', r.first_name) AS head_name
     FROM households h LEFT JOIN residents r ON h.head_resident_id = r.id
     WHERE h.barangay_id = ? AND h.status = 'Active' ORDER BY h.household_code ASC"
);
$activeHouseholdsStmt->execute([$bid]);
$activeHouseholds = $activeHouseholdsStmt->fetchAll();

// POST handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrAbort();

    $fname   = sanitize($_POST['first_name']);
    $mname   = sanitize($_POST['middle_name'] ?? '');
    $lname   = sanitize($_POST['last_name']);
    $suffix  = sanitize($_POST['suffix'] ?? '');
    $bdate   = $_POST['birthdate'];
    $age     = calculateAge($bdate);
    $civil   = $_POST['civil_status'];
    $gender  = $_POST['gender'];
    $contact = sanitize($_POST['contact_number'] ?? '');
    $email   = sanitize($_POST['email'] ?? '');

    if ($contact && !preg_match('/^09\d{9}$/', $contact)) {
        flash('res', 'Contact number must be 11 digits and start with 09 (e.g. 09171234567).', 'danger');
        redirect(BASE_URL . '/barangay_admin/add_resident.php');
    }
    if ($contact) {
        $dupQ = $db->prepare("SELECT id FROM residents WHERE contact_number=? AND barangay_id=? AND resident_status NOT IN ('Inactive','Deceased','Transferred')");
        $dupQ->execute([$contact, $bid]);
        if ($dupQ->fetch()) {
            flash('res', "Contact number <strong>$contact</strong> is already in use by another resident.", 'danger');
            redirect(BASE_URL . '/barangay_admin/add_resident.php');
        }
    }

    $voter   = $_POST['voter_status'];
    $is4ps   = isset($_POST['is_4ps']) ? 1 : 0;
    $isPwd   = isset($_POST['is_pwd']) ? 1 : 0;
    $isSenior= $age >= 60 ? 1 : (isset($_POST['is_senior']) ? 1 : 0);
    $isSolo  = isset($_POST['is_solo_parent']) ? 1 : 0;
    $occup   = $_POST['occupation_status'];

    // Photo upload
    $newPhotoFile = null;
    if (!empty($_POST['photo_base64'])) {
        $b64data = $_POST['photo_base64'];
        if (preg_match('/^data:image\/(jpeg|png|webp);base64,/', $b64data, $typeMatch)) {
            $imgData  = base64_decode(preg_replace('/^data:image\/\w+;base64,/', '', $b64data));
            $ext      = ($typeMatch[1] === 'png') ? 'png' : 'jpg';
            $filename = 'resident_' . time() . '_' . random_int(1000, 9999) . '.' . $ext;
            file_put_contents($photoDir . $filename, $imgData);
            $newPhotoFile = $filename;
        }
    } elseif (!empty($_FILES['photo']['tmp_name'])) {
        $file    = $_FILES['photo'];
        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $finfo   = finfo_open(FILEINFO_MIME_TYPE);
        $mime    = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if (!in_array($mime, $allowed)) {
            flash('res', 'Invalid image type.', 'danger');
            redirect(BASE_URL . '/barangay_admin/add_resident.php');
        }
        $ext      = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'jpg';
        $filename = 'resident_' . time() . '_' . random_int(1000, 9999) . '.' . strtolower($ext);
        move_uploaded_file($file['tmp_name'], $photoDir . $filename);
        $newPhotoFile = $filename;
    }

    // Address fields
    $region   = sanitize($_POST['region']        ?? $brgyInfo['region']       ?? '');
    $province = sanitize($_POST['province']      ?? $brgyInfo['province']     ?? '');
    $city     = sanitize($_POST['city']          ?? $brgyInfo['municipality'] ?? '');
    $brgyAddr = sanitize($_POST['barangay_addr'] ?? $brgyInfo['barangay_name']?? '');
    $zipCode  = sanitize($_POST['zip_code']      ?? $brgyInfo['zip_code']     ?? '3114');
    $street   = sanitize($_POST['street'] ?? '');
    $purok    = sanitize($_POST['purok']  ?? '');
    $zone     = sanitize($_POST['zone']   ?? '');

    $addrStmt = $db->prepare("INSERT INTO addresses (region,province,city,barangay,street,purok,zone,zip_code) VALUES (?,?,?,?,?,?,?,?)");
    $addrStmt->execute([$region,$province,$city,$brgyAddr,$street,$purok,$zone,$zipCode]);
    $addrId = $db->lastInsertId();

    $stmt = $db->prepare("INSERT INTO residents (barangay_id,first_name,middle_name,last_name,suffix,birthdate,age,civil_status,gender,contact_number,email,address_id,voter_status,is_4ps,is_pwd,is_senior,is_solo_parent,resident_status,occupation_status,photo) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([$bid,$fname,$mname,$lname,$suffix,$bdate,$age,$civil,$gender,$contact,$email ?: null,$addrId,$voter,$is4ps,$isPwd,$isSenior,$isSolo,'Active',$occup,$newPhotoFile]);
    $newResidentId = (int)$db->lastInsertId();

    // Household linking
    $householdRole = $_POST['household_role'] ?? '';
    if ($householdRole === 'head') {
        $hhLos     = sanitize($_POST['hh_length_of_stay'] ?? '');
        $hhIncome  = sanitize($_POST['hh_income_range'] ?? '');
        $hhMembers = max(1, (int)($_POST['hh_total_members'] ?? 1));
        $hhSenior  = (int)($_POST['hh_total_senior'] ?? ($isSenior ? 1 : 0));
        $hhPwd     = (int)($_POST['hh_total_pwd']    ?? ($isPwd    ? 1 : 0));
        $hhCode    = generateHouseholdCode($bid);
        $db->prepare("INSERT INTO households (barangay_id,household_code,head_resident_id,contact_number,address_id,length_of_stay,total_members,total_senior,total_pwd,income_range,status) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$bid,$hhCode,$newResidentId,$contact,$addrId,$hhLos,$hhMembers,$hhSenior,$hhPwd,$hhIncome,'Active']);
        $newHouseholdId = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO household_members (household_id, resident_id, relationship) VALUES (?,?,?)")->execute([$newHouseholdId, $newResidentId, 'Head']);
        logActivity($u['id'], 'ADD_RESIDENT', 'Residents', "Added: $fname $lname (Head of new household $hhCode)");
        flash('res', "Resident added and new household <strong>$hhCode</strong> created successfully.", 'success');
    } elseif ($householdRole === 'member') {
        $linkedHouseholdId  = (int)($_POST['hh_member_household_id'] ?? 0);
        $memberRelationship = sanitize($_POST['hh_member_relationship'] ?? 'Member');
        if ($linkedHouseholdId) {
            $dupCheck = $db->prepare("SELECT id FROM household_members WHERE household_id=? AND resident_id=?");
            $dupCheck->execute([$linkedHouseholdId, $newResidentId]);
            if (!$dupCheck->fetch()) {
                $db->prepare("INSERT INTO household_members (household_id, resident_id, relationship) VALUES (?,?,?)")->execute([$linkedHouseholdId, $newResidentId, $memberRelationship]);
                $db->prepare("UPDATE households SET total_members = total_members + 1 WHERE id=?")->execute([$linkedHouseholdId]);
                if ($isSenior) $db->prepare("UPDATE households SET total_senior = total_senior + 1 WHERE id=?")->execute([$linkedHouseholdId]);
                if ($isPwd)    $db->prepare("UPDATE households SET total_pwd = total_pwd + 1 WHERE id=?")->execute([$linkedHouseholdId]);
            }
            $hhCodeLabel = $db->prepare("SELECT household_code FROM households WHERE id=?")->execute([$linkedHouseholdId]) ? ($db->query("SELECT household_code FROM households WHERE id=$linkedHouseholdId")->fetchColumn() ?: "#$linkedHouseholdId") : "#$linkedHouseholdId";
            logActivity($u['id'], 'ADD_RESIDENT', 'Residents', "Added: $fname $lname (Member of $hhCodeLabel, $memberRelationship)");
            flash('res', "Resident added and linked to household <strong>$hhCodeLabel</strong> as <strong>$memberRelationship</strong>.", 'success');
        } else {
            logActivity($u['id'], 'ADD_RESIDENT', 'Residents', "Added: $fname $lname");
            flash('res', 'Resident added successfully.', 'success');
        }
    } else {
        logActivity($u['id'], 'ADD_RESIDENT', 'Residents', "Added: $fname $lname");
        flash('res', 'Resident added successfully.', 'success');
    }

    // ── Real-time notification: notify acting user + other admins in same barangay ──
    $brgyNameNotif = $brgyInfo['barangay_name'] ?? 'Barangay';
    $notifMsg = "👤 New resident added: <strong>$fname $lname</strong> — ID: <strong>#$newResidentId</strong> (<em>$brgyNameNotif</em>)";
    sendNotification($u['id'], $notifMsg, 'success', 'resident');
    // Peer barangay admins in the same barangay (exclude self)
    notifyBarangayAdmins($bid, $notifMsg, 'success', 'resident', $u['id']);
    // Notify all super admins so they see barangay activity in real time
    notifySuperAdmins($notifMsg, 'info', 'resident');

    // ── Email confirmation to the admin who added the resident ────────────
    if ($u['email']) {
        $brgyNameRes = $db->query("SELECT barangay_name FROM barangays WHERE id=$bid")->fetchColumn() ?: '';
        sendResidentAddedEmail($u['email'], $u['name'], "$fname $lname", $brgyNameRes, $gender, $bdate, $age, $civil, $occup);
    }

    // ── If this resident was registered from an official, log it ──────────
    $fromOfficialId = (int)($_POST['from_official_id'] ?? 0);
    if ($fromOfficialId > 0) {
        // Verify the official belongs to this barangay before updating
        $offCheck = $db->prepare("SELECT id, full_name, position FROM officials WHERE id=? AND barangay_id=?");
        $offCheck->execute([$fromOfficialId, $bid]);
        $offRow = $offCheck->fetch();
        if ($offRow) {
            // Persist the link and position so transfer system and profile can use them
            $db->prepare("UPDATE residents SET official_id=?, official_position=? WHERE id=?")
               ->execute([$fromOfficialId, $offRow['position'], $newResidentId]);
            logActivity($u['id'], 'ADD_RESIDENT', 'Residents',
                "Registered {$fname} {$lname} as resident from Officials page (official ID #$fromOfficialId — {$offRow['position']})");
        }
    }

    redirect(BASE_URL . '/barangay_admin/residents.php');
}

$flash     = getFlash('res');
$pageIcon     = 'bi-person-plus-fill';
$pageTitle    = 'Add Resident';
$pageSubtitle = 'Register a new resident to the barangay.';

// ── Pre-fill from official (set in session by officials.php when
//    "Register as Resident" button is clicked) ───────────────────
$prefill = [];
if (!empty($_SESSION['prefill_from_official'])) {
    $prefill = $_SESSION['prefill_from_official'];
    unset($_SESSION['prefill_from_official']); // consume once
}
$fromOfficial = !empty($prefill);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favcon.png">
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favcon.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=3.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <style>
        /* ── Select2 skin to match Bootstrap ── */
        .select2-container--default .select2-selection--single {
            height: calc(1.5em + 0.75rem + 2px);
            padding: 0.375rem 0.75rem;
            font-size: 0.84rem;
            border: 1px solid #d0dce8;
            border-radius: 7px;
            line-height: 1.5;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 1.5;
            padding-left: 0;
            color: #212529;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 100%;
        }
        .select2-container--default.select2-container--focus .select2-selection--single,
        .select2-container--default.select2-container--open .select2-selection--single {
            border-color: #3a7fa8;
            box-shadow: 0 0 0 3px rgba(58,127,168,0.12);
            outline: none;
        }
        .select2-dropdown {
            border-color: #3a7fa8;
            border-radius: 7px;
            font-size: 0.84rem;
        }
        .select2-container--default .select2-results__option--highlighted[aria-selected] {
            background-color: #1a4f7a;
        }
        .select2-search--dropdown .select2-search__field {
            border-radius: 5px;
            border: 1px solid #d0dce8;
            padding: 6px 10px;
            font-size: 0.84rem;
        }
        .select2-container { width: 100% !important; }
        /* ── Shared field style ── */
        .field-label {
            font-size: 0.78rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: 4px;
            display: block;
        }
        .field-label .req { color: #dc2626; margin-left: 2px; }
        .form-control, .form-select {
            font-size: 0.84rem;
            border-color: #d0dce8;
            border-radius: 7px;
        }
        .form-control:focus, .form-select:focus {
            border-color: #3a7fa8;
            box-shadow: 0 0 0 3px rgba(58,127,168,0.12);
        }
        .form-control[readonly] { background: #f4f7fa; color: #6b7280; }

        /* ── Section card ── */
        .res-section {
            background: #fff;
            border-radius: 12px;
            border: 1px solid #d0ecd6;
            overflow: hidden;
            margin-bottom: 16px;
        }
        .res-section-head {
            background: rgba(26,79,122,0.05);
            border-bottom: 1px solid rgba(26,79,122,0.1);
            padding: 11px 18px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .res-section-head span {
            font-weight: 700;
            font-size: 0.8rem;
            color: #1a4f7a;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }
        .res-section-body { padding: 18px; }

        /* ── Photo box ── */
        #photoDropZone {
            width: 130px;
            height: 170px;
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
        #photoDropZone:hover { border-color: #3a7fa8; background: #e8f3fb; }
        #photoPreview {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: none;
            position: absolute;
            top: 0; left: 0;
        }
        .photo-placeholder {
            text-align: center;
            color: #9ca3af;
            font-size: 0.7rem;
            padding: 8px;
            pointer-events: none;
        }

        /* ── Household role cards ── */
        .hh-role-card {
            flex: 1;
            border: 2px solid #dee2e6;
            border-radius: 9px;
            padding: 12px 14px;
            cursor: pointer;
            transition: all .2s;
            background: #fff;
        }
        .hh-role-card.selected { border-color: #1a4f7a; background: rgba(26,79,122,0.05); }
        .hh-role-card.selected-green { border-color: #198754; background: rgba(25,135,84,0.05); }

        /* ── Sticky footer ── */
        .res-footer {
            position: sticky;
            bottom: 0;
            background: #fff;
            border-top: 1px solid #e5e7eb;
            padding: 14px 0;
            margin-top: 8px;
            z-index: 10;
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

    <?php if ($fromOfficial): ?>
    <!-- ── Pre-fill notice banner ── -->
    <div style="background:linear-gradient(90deg,rgba(5,38,89,0.07),rgba(84,131,179,0.05));border:1px solid rgba(84,131,179,0.30);border-radius:12px;padding:14px 20px;margin-bottom:16px;display:flex;align-items:center;gap:14px;">
        <div style="width:40px;height:40px;min-width:40px;background:linear-gradient(135deg,#052659,#5483B3);border-radius:10px;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 8px rgba(5,38,89,0.25);">
            <i class="bi bi-person-badge-fill text-white" style="font-size:1rem;"></i>
        </div>
        <div style="flex:1;">
            <div style="font-weight:700;font-size:0.9rem;color:#052659;">
                Registering: <?= htmlspecialchars($prefill['full_name'] ?? '') ?>
            </div>
            <div style="font-size:0.78rem;color:#5483B3;margin-top:2px;">
                <i class="bi bi-check-circle-fill me-1 text-success"></i>
                Official record already saved &mdash; <strong><?= htmlspecialchars($prefill['position'] ?? '') ?></strong>.
                Complete the remaining resident fields below and click <strong>Save Resident</strong>.
            </div>
        </div>
        <a href="<?= BASE_URL ?>/barangay_admin/officials.php" class="btn btn-sm btn-outline-secondary" style="white-space:nowrap;font-size:0.78rem;">
            <i class="bi bi-arrow-left me-1"></i>Back to Officials
        </a>
    </div>
    <?php endif; ?>

    <!-- ── Page Header ── -->
    <div class="mb-3 d-flex align-items-center gap-2">
        <a href="<?= BASE_URL ?>/barangay_admin/residents.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back to Residents
        </a>
    </div>

    <!-- ── Blue header banner ── -->
    <div style="background:linear-gradient(90deg,#1a4f7a,#2d7db3);border-radius:12px;padding:18px 24px;margin-bottom:18px;">
        <div class="d-flex align-items-center gap-3">
            <div style="width:44px;height:44px;background:rgba(255,255,255,0.15);border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="bi bi-person-plus-fill text-white" style="font-size:1.2rem;"></i>
            </div>
            <div>
                <div style="font-size:0.72rem;color:rgba(255,255,255,0.7);text-transform:uppercase;letter-spacing:0.08em;">Resident Management</div>
                <div style="font-size:1.1rem;font-weight:800;color:#fff;">Add New Resident</div>
                <div style="font-size:0.78rem;color:rgba(255,255,255,0.75);margin-top:2px;">Fill in all required fields to register a new resident.</div>
            </div>
        </div>
    </div>

    <form method="POST" id="residentForm" enctype="multipart/form-data">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="from_official_id" value="<?= (int)($prefill['official_id'] ?? 0) ?>">
        <input type="hidden" name="photo_base64" id="photoBase64">
        <input type="hidden" name="region"       id="fRegion">
        <input type="hidden" name="province"     id="fProvince">
        <input type="hidden" name="city"         id="fCity">
        <input type="hidden" name="barangay_addr" id="fBrgyAddr">
        <input type="hidden" name="zip_code"     id="fZipCode">
        <input type="hidden" name="street"       id="fStreet" value="">
        <input type="hidden" name="zone"         id="fZone" value="">

        <div class="row g-4">

            <!-- ══ LEFT: Photo + Special Categories ══ -->
            <div class="col-lg-3">

                <!-- Photo Card -->
                <div class="res-section">
                    <div class="res-section-head">
                        <i class="bi bi-camera-fill" style="color:#1a4f7a;"></i>
                        <span>Resident Photo</span>
                    </div>
                    <div class="res-section-body text-center">
                        <div id="photoDropZone" onclick="document.getElementById('photoFileInput').click()" title="Click or drag photo here">
                            <img id="photoPreview" src="" alt="">
                            <div class="photo-placeholder" id="photoPlaceholder">
                                <i class="bi bi-person-bounding-box" style="font-size:2.4rem;display:block;margin-bottom:6px;color:#b0c4d8;"></i>
                                <span style="font-size:0.68rem;color:#9ca3af;">2×2 ID Photo<br>Click or drag here</span>
                            </div>
                        </div>
                        <input type="file" id="photoFileInput" name="photo" accept="image/*" style="display:none">
                        <div class="d-flex gap-2 justify-content-center mt-2 flex-wrap">
                            <button type="button" class="btn btn-sm btn-outline-primary" style="font-size:0.72rem;"
                                    onclick="document.getElementById('photoFileInput').click()">
                                <i class="bi bi-upload me-1"></i>Upload
                            </button>
                            <button type="button" id="openCameraBtn" class="btn btn-sm btn-outline-secondary" style="font-size:0.72rem;">
                                <i class="bi bi-camera me-1"></i>Camera
                            </button>
                        </div>
                        <button type="button" id="clearPhotoBtn" class="btn btn-sm btn-outline-danger mt-1 w-100" style="font-size:0.7rem;display:none;" onclick="clearPhoto()">
                            <i class="bi bi-trash me-1"></i>Remove Photo
                        </button>
                        <div class="text-muted mt-2" style="font-size:0.68rem;">JPG, PNG or WebP · max 5MB</div>
                    </div>
                </div>

                <!-- Special Categories Card -->
                <div class="res-section">
                    <div class="res-section-head">
                        <i class="bi bi-tags-fill" style="color:#1a4f7a;"></i>
                        <span>Special Categories</span>
                    </div>
                    <div class="res-section-body">
                        <div class="d-flex flex-column gap-2">
                            <div class="form-check">
                                <input type="checkbox" name="is_4ps" id="f4ps" class="form-check-input">
                                <label class="form-check-label" for="f4ps" style="font-size:0.83rem;">4Ps Beneficiary</label>
                            </div>
                            <div class="form-check">
                                <input type="checkbox" name="is_pwd" id="fPwd" class="form-check-input">
                                <label class="form-check-label" for="fPwd" style="font-size:0.83rem;">PWD</label>
                            </div>
                            <div class="form-check">
                                <input type="checkbox" name="is_senior" id="fSenior" class="form-check-input">
                                <label class="form-check-label" for="fSenior" style="font-size:0.83rem;">Senior Citizen</label>
                            </div>
                            <div class="form-check">
                                <input type="checkbox" name="is_solo_parent" id="fSolo" class="form-check-input">
                                <label class="form-check-label" for="fSolo" style="font-size:0.83rem;">Solo Parent</label>
                            </div>
                        </div>
                        <div class="text-muted mt-2" style="font-size:0.71rem;"><i class="bi bi-info-circle me-1"></i>Senior Citizen auto-checked if age ≥ 60.</div>
                    </div>
                </div>

            </div>

            <!-- ══ RIGHT: Form Sections ══ -->
            <div class="col-lg-9">

                <!-- Section 1: Personal Information -->
                <div class="res-section">
                    <div class="res-section-head">
                        <i class="bi bi-person-fill" style="color:#1a4f7a;"></i>
                        <span>Personal Information</span>
                    </div>
                    <div class="res-section-body">
                        <div class="row g-3">
                            <!-- Row 1: First / Middle / Last -->
                            <div class="col-md-4">
                                <label class="field-label">First Name <span class="req">*</span></label>
                                <input type="text" name="first_name" id="resFirstName" class="form-control" required placeholder="e.g. Juan"
                                       oninput="resTriggerDupCheck()">
                            </div>
                            <div class="col-md-4">
                                <label class="field-label">Middle Name</label>
                                <input type="text" name="middle_name" id="resMiddleName" class="form-control" placeholder="e.g. Santos"
                                       oninput="resTriggerDupCheck()">
                            </div>
                            <div class="col-md-4">
                                <label class="field-label">Last Name <span class="req">*</span></label>
                                <input type="text" name="last_name" id="resLastName" class="form-control" required placeholder="e.g. dela Cruz"
                                       oninput="resTriggerDupCheck()">
                            </div>
                            <!-- Row 2: Suffix / Birthdate / Age -->
                            <div class="col-md-4">
                                <label class="field-label">Suffix</label>
                                <select name="suffix" id="resSuffix" class="form-select"
                                        onchange="resTriggerDupCheck()">
                                    <option value="">None</option>
                                    <option>Jr.</option><option>Sr.</option><option>II</option><option>III</option>
                                </select>
                            </div>
                            <!-- Duplicate resident check result bar -->
                            <div class="col-12" id="resDupBar" style="display:none;"></div>

                            <!-- Row 2 continued: Birthdate / Age (suffix col already rendered above) -->
                            <div class="col-md-4">
                                <label class="field-label">Birthdate <span class="req">*</span></label>
                                <input type="date" name="birthdate" id="fBirthdate" class="form-control" required onchange="calcAge(this.value)">
                            </div>
                            <div class="col-md-4">
                                <label class="field-label">Age</label>
                                <input type="number" id="fAge" class="form-control" readonly placeholder="Auto">
                            </div>
                            <!-- Row 3: Gender / Civil Status / Contact -->
                            <div class="col-md-4">
                                <label class="field-label">Gender <span class="req">*</span></label>
                                <select name="gender" class="form-select" required>
                                    <option value="">Select</option>
                                    <option>Male</option><option>Female</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="field-label">Civil Status <span class="req">*</span></label>
                                <select name="civil_status" class="form-select" required>
                                    <option value="">Select</option>
                                    <option>Single</option><option>Married</option><option>Widowed</option>
                                    <option>Separated</option><option>Annulled</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="field-label">Contact Number</label>
                                <input type="text" name="contact_number" id="fContact" class="form-control"
                                       inputmode="numeric" pattern="09[0-9]{9}" placeholder="e.g. 09171234567"
                                       oninput="this.value=this.value.replace(/[^0-9]/g,'');validateContact(this,'fContactErr')"
                                       onkeydown="if(!/[0-9]|Backspace|Delete|ArrowLeft|ArrowRight|Tab|Home|End/.test(event.key))event.preventDefault()">
                                <div id="fContactErr" class="text-danger mt-1" style="display:none;font-size:0.72rem;"></div>
                            </div>
                            <div class="col-md-4">
                                <label class="field-label">Email Address</label>
                                <input type="email" name="email" id="fEmail" class="form-control"
                                       placeholder="e.g. juan@email.com"
                                       value="">
                            </div>
                            <!-- Row 4: Voter / Occupation -->
                            <div class="col-md-4">
                                <label class="field-label">Voter Status</label>
                                <select name="voter_status" class="form-select">
                                    <option value="Not Registered">Not Registered</option>
                                    <option value="Registered">Registered</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="field-label">Occupation Status</label>
                                <select name="occupation_status" class="form-select">
                                    <option>Unemployed</option><option>Employed</option>
                                    <option>Self-Employed</option><option>Student</option><option>Retired</option>
                                </select>
                            </div>
                            <div class="col-md-4"><!-- spacer --></div>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Address -->
                <div class="res-section">
                    <div class="res-section-head">
                        <i class="bi bi-geo-alt-fill" style="color:#1a4f7a;"></i>
                        <span>Address</span>
                    </div>
                    <div class="res-section-body">
                        <div class="row g-3">
                            <!-- Row 1: Region / Province / Zip Code -->
                            <div class="col-md-4">
                                <label class="field-label">Region</label>
                                <input type="text" id="fRegionDisplay" class="form-control" readonly tabindex="-1">
                            </div>
                            <div class="col-md-4">
                                <label class="field-label">Province</label>
                                <input type="text" id="fProvinceDisplay" class="form-control" readonly tabindex="-1">
                            </div>
                            <div class="col-md-4">
                                <label class="field-label">Zip Code</label>
                                <input type="text" id="fZipDisplay" class="form-control" readonly tabindex="-1">
                            </div>
                            <!-- Row 2: City / Barangay / Purok -->
                            <div class="col-md-4">
                                <label class="field-label">City / Municipality</label>
                                <input type="text" id="fCityDisplay" class="form-control" readonly tabindex="-1">
                            </div>
                            <div class="col-md-4">
                                <label class="field-label">Barangay</label>
                                <input type="text" id="fBrgyDisplay" class="form-control" readonly tabindex="-1">
                            </div>
                            <div class="col-md-4">
                                <label class="field-label">Purok / Street / Zone</label>
                                <input type="text" name="purok" id="fPurok" class="form-control"
                                       placeholder="e.g. Purok 3, Rizal St., Zone 2">
                            </div>
                        </div>
                        <div class="alert alert-info py-2 mt-3 mb-0" style="font-size:0.75rem;">
                            <i class="bi bi-info-circle me-1"></i>
                            Region, Province, City, and Barangay are auto-filled from your barangay profile. Only Purok / Street / Zone is editable.
                        </div>
                    </div>
                </div>

                <!-- Section 3: Household Assignment -->
                <div class="res-section">
                    <div class="res-section-head">
                        <i class="bi bi-house-heart-fill" style="color:#1a4f7a;"></i>
                        <span>Household Assignment</span>
                    </div>
                    <div class="res-section-body">
                        <p class="text-muted mb-3" style="font-size:0.8rem;">
                            Select whether this resident is the Head of the Family (creates a new household), a Family Member (links to an existing household), or skip for now.
                        </p>
                        <div class="d-flex gap-3 flex-wrap mb-3">
                            <label class="hh-role-card" id="roleHeadCard" onclick="setHhRole('head')">
                                <input type="radio" name="household_role" id="roleHead" value="head" class="form-check-input me-2 m-0" style="vertical-align:middle;">
                                <i class="bi bi-house-fill text-primary me-1"></i>
                                <strong>Head of Family</strong>
                                <div class="text-muted" style="font-size:0.72rem;margin-top:2px;">Create a new household</div>
                            </label>
                            <label class="hh-role-card" id="roleMemberCard" onclick="setHhRole('member')">
                                <input type="radio" name="household_role" id="roleMember" value="member" class="form-check-input me-2 m-0" style="vertical-align:middle;">
                                <i class="bi bi-people-fill text-success me-1"></i>
                                <strong>Family Member</strong>
                                <div class="text-muted" style="font-size:0.72rem;margin-top:2px;">Link to existing household</div>
                            </label>
                            <label class="hh-role-card" id="roleNoneCard" onclick="setHhRole('')" style="border-color:#1a4f7a;background:rgba(26,79,122,0.05);">
                                <input type="radio" name="household_role" id="roleNone" value="" class="form-check-input me-2 m-0" style="vertical-align:middle;" checked>
                                <i class="bi bi-dash-circle text-secondary me-1"></i>
                                <strong>Skip for now</strong>
                                <div class="text-muted" style="font-size:0.72rem;margin-top:2px;">No household assignment</div>
                            </label>
                        </div>

                        <!-- Head of Family extra fields -->
                        <div id="hhHeadFields" style="display:none;">
                            <div style="background:#f8fbff;border:1px solid #d0e8f5;border-radius:9px;padding:14px;">
                                <div class="fw-semibold text-primary mb-2" style="font-size:0.82rem;"><i class="bi bi-plus-circle-fill me-1"></i>New Household Details</div>
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="field-label">Total Members</label>
                                        <input type="number" name="hh_total_members" id="hhNewMembers" class="form-control" min="1" value="1"
                                               oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="field-label">Senior Citizens</label>
                                        <input type="number" name="hh_total_senior" id="hhNewSenior" class="form-control" min="0" value="0"
                                               oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="field-label">PWD Count</label>
                                        <input type="number" name="hh_total_pwd" id="hhNewPwd" class="form-control" min="0" value="0"
                                               oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="field-label">Income Range</label>
                                        <select name="hh_income_range" class="form-select">
                                            <option value="">— Select —</option>
                                            <option>Below ₱10,000</option><option>₱10,000 - ₱20,000</option>
                                            <option>₱20,001 - ₱40,000</option><option>₱40,001 - ₱60,000</option>
                                            <option>Above ₱60,000</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="field-label">Length of Stay</label>
                                        <input type="text" name="hh_length_of_stay" class="form-control" placeholder="e.g. 5 years">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Family Member extra fields -->
                        <div id="hhMemberFields" style="display:none;">
                            <div style="background:#f8fbff;border:1px solid #d0e8f5;border-radius:9px;padding:14px;">
                                <div class="fw-semibold text-success mb-2" style="font-size:0.82rem;"><i class="bi bi-link-45deg me-1"></i>Link to Existing Household</div>
                                <div class="row g-3">
                                    <div class="col-md-7">
                                        <label class="field-label">Select Household <span class="req">*</span></label>
                                        <select name="hh_member_household_id" id="hhMemberHouseholdSelect" class="form-select">
                                            <option value="">— Select household —</option>
                                            <?php foreach ($activeHouseholds as $hh): ?>
                                            <option value="<?= $hh['id'] ?>">
                                                <?= htmlspecialchars($hh['household_code']) ?>
                                                <?= $hh['head_name'] ? ' — ' . htmlspecialchars($hh['head_name']) : '' ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if (empty($activeHouseholds)): ?>
                                        <div class="text-warning small mt-1"><i class="bi bi-exclamation-triangle me-1"></i>No active households found.</div>
                                        <?php endif; ?>
                                        <div class="text-muted mt-1" style="font-size:0.72rem;">
                                            <i class="bi bi-search me-1"></i>Type a surname or household code to search.
                                        </div>
                                    </div>
                                    <div class="col-md-5">
                                        <label class="field-label">Relationship to Head <span class="req">*</span></label>
                                        <select name="hh_member_relationship" id="hhMemberRelationship" class="form-select">
                                            <option value="">— Select —</option>
                                            <option value="Spouse">Spouse</option><option value="Son">Son</option>
                                            <option value="Daughter">Daughter</option><option value="Father">Father</option>
                                            <option value="Mother">Mother</option><option value="Brother">Brother</option>
                                            <option value="Sister">Sister</option><option value="Grandfather">Grandfather</option>
                                            <option value="Grandmother">Grandmother</option><option value="Grandson">Grandson</option>
                                            <option value="Granddaughter">Granddaughter</option><option value="Uncle">Uncle</option>
                                            <option value="Aunt">Aunt</option><option value="Nephew">Nephew</option>
                                            <option value="Niece">Niece</option><option value="Cousin">Cousin</option>
                                            <option value="In-Law">In-Law</option><option value="Boarder">Boarder</option>
                                            <option value="Helper">Helper</option><option value="Other">Other</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- ── Sticky Footer Buttons ── -->
                <div class="res-footer">
                    <div class="d-flex gap-2 justify-content-end">
                        <a href="<?= BASE_URL ?>/barangay_admin/residents.php" class="btn btn-outline-secondary px-4">Cancel</a>
                        <button type="submit" class="btn btn-primary fw-semibold px-5" style="background:#1a4f7a;border-color:#1a4f7a;">
                            <i class="bi bi-person-check-fill me-2"></i>Save Resident
                        </button>
                    </div>
                </div>

            </div><!-- /col-lg-9 -->
        </div><!-- /row -->
    </form>

</div><!-- /main-content -->

<!-- Camera Modal -->
<div class="modal fade" id="cameraModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered" style="max-width:500px;">
        <div class="modal-content" style="border-radius:14px;overflow:hidden;">
            <div class="modal-header" style="background:#1a4f7a;border:none;padding:14px 20px;">
                <h6 class="modal-title text-white fw-bold mb-0"><i class="bi bi-camera-fill me-2"></i>Take Photo</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" onclick="stopCamera()"></button>
            </div>
            <div class="modal-body p-3 text-center">
                <!-- Camera switch button (shown only when stream is active) -->
                <div id="cameraSwitchRow" class="mb-2 d-flex justify-content-center gap-2" style="display:none!important;">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="switchCamBtn" onclick="switchCamera()">
                        <i class="bi bi-arrow-repeat me-1"></i>Switch Camera
                    </button>
                    <span id="camFacingLabel" class="badge align-self-center" style="background:rgba(26,79,122,0.12);color:#1a4f7a;font-size:0.72rem;padding:5px 10px;"></span>
                </div>
                <!-- Loading indicator -->
                <div id="cameraLoading" style="display:none;padding:40px 0;">
                    <div class="spinner-border text-primary mb-2" style="width:2rem;height:2rem;"></div>
                    <div class="small text-muted">Starting camera…</div>
                </div>
                <!-- Idle state (before camera starts) -->
                <div id="cameraIdle" style="padding:32px 0;">
                    <i class="bi bi-camera-fill" style="font-size:2.5rem;color:#b0c4d8;"></i>
                    <div class="text-muted small mt-2">Choose a camera below to begin</div>
                    <div class="d-flex justify-content-center gap-2 mt-3">
                        <button type="button" class="btn btn-outline-primary btn-sm px-3" onclick="startCamera('user')">
                            <i class="bi bi-person-fill me-1"></i>Front Camera
                        </button>
                        <button type="button" class="btn btn-outline-primary btn-sm px-3" onclick="startCamera('environment')">
                            <i class="bi bi-phone-fill me-1"></i>Back Camera
                        </button>
                    </div>
                </div>
                <video id="cameraVideo" autoplay playsinline muted
                       style="width:100%;max-height:320px;border-radius:10px;background:#000;display:none;object-fit:cover;"></video>
                <canvas id="cameraCanvas" style="display:none;"></canvas>
                <div id="cameraError" class="alert alert-danger py-2 small mt-2 text-start" style="display:none;"></div>
            </div>
            <div class="modal-footer border-0 pt-0 justify-content-center gap-2">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" onclick="stopCamera()">
                    <i class="bi bi-x-lg me-1"></i>Cancel
                </button>
                <button type="button" id="captureBtn" class="btn btn-primary px-4" onclick="capturePhoto()" disabled>
                    <i class="bi bi-camera me-1"></i>Capture Photo
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
// ── Select2: searchable household dropdown ────────────────────────────────
$(document).ready(function () {
    $('#hhMemberHouseholdSelect').select2({
        placeholder: '— Type surname or household code —',
        allowClear: true,
        width: '100%',
        dropdownParent: $('#hhMemberFields'),
        matcher: function (params, data) {
            // Match against the full text (code + head name)
            if ($.trim(params.term) === '') return data;
            if (!data.text) return null;
            if (data.text.toUpperCase().indexOf(params.term.toUpperCase()) > -1) return data;
            return null;
        }
    });
});

// ── Barangay auto-fill ────────────────────────────────────────────────────
const brgyRegion   = <?= json_encode($brgyInfo['region']       ?? '') ?>;
const brgyProvince = <?= json_encode($brgyInfo['province']     ?? '') ?>;
const brgyCity     = <?= json_encode($brgyInfo['municipality'] ?? '') ?>;
const brgyName     = <?= json_encode($brgyInfo['barangay_name']?? '') ?>;
const brgyZip      = <?= json_encode($brgyInfo['zip_code'] ?: '3114') ?>;

(function fillBrgy() {
    document.getElementById('fRegion').value   = brgyRegion;
    document.getElementById('fProvince').value = brgyProvince;
    document.getElementById('fCity').value     = brgyCity;
    document.getElementById('fBrgyAddr').value = brgyName;
    document.getElementById('fZipCode').value  = brgyZip;
    document.getElementById('fRegionDisplay').value   = brgyRegion   || '—';
    document.getElementById('fProvinceDisplay').value = brgyProvince || '—';
    document.getElementById('fCityDisplay').value     = brgyCity     || '—';
    document.getElementById('fBrgyDisplay').value     = brgyName     || '—';
    document.getElementById('fZipDisplay').value      = brgyZip      || '3114';
})();

// ── Pre-fill from official (session data injected by PHP) ────────────────
<?php if ($fromOfficial): ?>
document.addEventListener('DOMContentLoaded', function prefillFromOfficial() {
    const p = <?= json_encode($prefill) ?>;

    // Name fields
    const setVal = (sel, val) => { const el = document.querySelector(sel); if (el && val !== undefined && val !== '') el.value = val; };

    setVal('[name="first_name"]',     p.first_name);
    setVal('[name="middle_name"]',    p.middle_name);
    setVal('[name="last_name"]',      p.last_name);
    setVal('[name="suffix"]',         p.suffix);
    setVal('[name="contact_number"]', p.contact);
    setVal('[name="purok"]',          p.purok);

    // Gender — select
    if (p.gender) {
        const el = document.querySelector('[name="gender"]');
        if (el) el.value = p.gender;
    }

    // Civil status — select
    if (p.civil_status) {
        const el = document.querySelector('[name="civil_status"]');
        if (el) el.value = p.civil_status;
    }

    // Voter status — select
    if (p.voter_status) {
        const el = document.querySelector('[name="voter_status"]');
        if (el) el.value = p.voter_status;
    }

    // Occupation — default Employed for officials
    const occEl = document.querySelector('[name="occupation_status"]');
    if (occEl) occEl.value = 'Employed';

    // Birthdate — use lguSetDate so the typeable mask updates too
    if (p.birthdate) {
        if (typeof lguSetDate === 'function') {
            lguSetDate('fBirthdate', p.birthdate);
        } else {
            const bdEl = document.getElementById('fBirthdate');
            if (bdEl) { bdEl.value = p.birthdate; bdEl.dispatchEvent(new Event('change')); }
        }
        calcAge(p.birthdate);
    }

    // Photo — show the official's photo as the default preview
    // (user can replace it by uploading or taking a camera shot)
    if (p.photo) {
        const baseUrl     = <?= json_encode(BASE_URL) ?>;
        const previewEl   = document.getElementById('photoPreview');
        const placeholder = document.getElementById('photoPlaceholder');
        const clearBtn    = document.getElementById('clearPhotoBtn');
        if (previewEl)   { previewEl.src = baseUrl + '/uploads/' + p.photo; previewEl.style.display = 'block'; }
        if (placeholder) placeholder.style.display = 'none';
        if (clearBtn)    clearBtn.style.display = '';
    }
});
<?php endif; ?>

// ── Age calculation ───────────────────────────────────────────────────────
function calcAge(bdate) {
    if (!bdate) return;
    const birth = new Date(bdate);
    const today = new Date();
    let age = today.getFullYear() - birth.getFullYear();
    const m = today.getMonth() - birth.getMonth();
    if (m < 0 || (m === 0 && today.getDate() < birth.getDate())) age--;
    document.getElementById('fAge').value = age;
    if (age >= 60) document.getElementById('fSenior').checked = true;
}

// ── Contact validation ────────────────────────────────────────────────────
function validateContact(input, errId) {
    const val = input.value.trim();
    const err = document.getElementById(errId);
    if (!val) { input.classList.remove('is-invalid','is-valid'); err.style.display='none'; return; }
    if (/^09\d{9}$/.test(val)) {
        input.classList.remove('is-invalid'); input.classList.add('is-valid');
        err.style.display = 'none';
    } else {
        input.classList.remove('is-valid'); input.classList.add('is-invalid');
        err.textContent = 'Must be 11 digits starting with 09.';
        err.style.display = 'block';
    }
}

// ── Household role toggle ─────────────────────────────────────────────────
function setHhRole(role) {
    document.getElementById('roleHead').checked   = role === 'head';
    document.getElementById('roleMember').checked = role === 'member';
    document.getElementById('roleNone').checked   = role === '';

    const headCard   = document.getElementById('roleHeadCard');
    const memberCard = document.getElementById('roleMemberCard');
    const noneCard   = document.getElementById('roleNoneCard');

    headCard.style.borderColor   = role==='head'   ? '#1a4f7a' : '#dee2e6';
    headCard.style.background    = role==='head'   ? 'rgba(26,79,122,0.05)' : '#fff';
    memberCard.style.borderColor = role==='member' ? '#198754' : '#dee2e6';
    memberCard.style.background  = role==='member' ? 'rgba(25,135,84,0.05)' : '#fff';
    noneCard.style.borderColor   = role===''       ? '#1a4f7a' : '#dee2e6';
    noneCard.style.background    = role===''       ? 'rgba(26,79,122,0.05)' : '#fff';

    document.getElementById('hhHeadFields').style.display   = role==='head'   ? '' : 'none';
    document.getElementById('hhMemberFields').style.display = role==='member' ? '' : 'none';
}

// ── Photo helpers ─────────────────────────────────────────────────────────
function setPhotoPreview(src) {
    const preview = document.getElementById('photoPreview');
    const holder  = document.getElementById('photoPlaceholder');
    preview.src = src;
    preview.style.display = 'block';
    holder.style.display  = 'none';
    document.getElementById('clearPhotoBtn').style.display = '';
}

function clearPhoto() {
    const preview = document.getElementById('photoPreview');
    preview.src = ''; preview.style.display = 'none';
    document.getElementById('photoPlaceholder').style.display = '';
    document.getElementById('clearPhotoBtn').style.display = 'none';
    document.getElementById('photoBase64').value = '';
    document.getElementById('photoFileInput').value = '';
}

document.getElementById('photoFileInput').addEventListener('change', function() {
    if (!this.files[0]) return;
    const reader = new FileReader();
    reader.onload = e => { setPhotoPreview(e.target.result); document.getElementById('photoBase64').value = ''; };
    reader.readAsDataURL(this.files[0]);
});

// Drag-and-drop on photo zone
const dropZone = document.getElementById('photoDropZone');
dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.style.borderColor='#3a7fa8'; });
dropZone.addEventListener('dragleave', () => { dropZone.style.borderColor='#b0c4d8'; });
dropZone.addEventListener('drop', e => {
    e.preventDefault(); dropZone.style.borderColor='#b0c4d8';
    const file = e.dataTransfer.files[0];
    if (file && file.type.startsWith('image/')) {
        const dt = new DataTransfer(); dt.items.add(file);
        document.getElementById('photoFileInput').files = dt.files;
        const reader = new FileReader();
        reader.onload = ev => { setPhotoPreview(ev.target.result); document.getElementById('photoBase64').value=''; };
        reader.readAsDataURL(file);
    }
});

// ── Camera ────────────────────────────────────────────────────────────────
let cameraStream   = null;
let currentFacing  = 'user'; // track current facing mode for switch
const cameraModal  = new bootstrap.Modal(document.getElementById('cameraModal'));

document.getElementById('openCameraBtn').addEventListener('click', function() {
    resetCameraUI();
    cameraModal.show();
    // Auto-start with front camera
    startCamera('user');
});

function resetCameraUI() {
    document.getElementById('cameraError').style.display    = 'none';
    document.getElementById('cameraVideo').style.display    = 'none';
    document.getElementById('cameraLoading').style.display  = 'none';
    document.getElementById('cameraIdle').style.display     = '';
    document.getElementById('cameraSwitchRow').style.display = 'none';
    document.getElementById('captureBtn').disabled = true;
}

function startCamera(facingMode) {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        showCameraError('Camera API not supported on this browser. Please use HTTPS or a modern browser.');
        return;
    }
    stopCamera();
    currentFacing = facingMode;
    document.getElementById('cameraError').style.display   = 'none';
    document.getElementById('cameraIdle').style.display    = 'none';
    document.getElementById('cameraLoading').style.display = '';
    document.getElementById('cameraVideo').style.display   = 'none';
    document.getElementById('captureBtn').disabled = true;

    // Build constraints — use 'ideal' so it falls back gracefully instead of hard-failing
    const constraints = {
        video: {
            facingMode: { ideal: facingMode },
            width:  { ideal: 1280 },
            height: { ideal: 720 }
        },
        audio: false
    };

    navigator.mediaDevices.getUserMedia(constraints)
        .then(stream => {
            cameraStream = stream;
            const video = document.getElementById('cameraVideo');
            video.srcObject = stream;
            video.style.display = 'block';
            document.getElementById('cameraLoading').style.display = 'none';
            document.getElementById('captureBtn').disabled = false;

            // Show switch button & label
            const switchRow = document.getElementById('cameraSwitchRow');
            switchRow.style.cssText = ''; // clear the display:none!important
            switchRow.style.display = 'flex';
            document.getElementById('camFacingLabel').textContent =
                facingMode === 'environment' ? '🔲 Back Camera' : '🤳 Front Camera';

            // Check if device actually has multiple cameras
            navigator.mediaDevices.enumerateDevices().then(devices => {
                const videoInputs = devices.filter(d => d.kind === 'videoinput');
                document.getElementById('switchCamBtn').style.display =
                    videoInputs.length > 1 ? '' : 'none';
            }).catch(() => {});
        })
        .catch(err => {
            document.getElementById('cameraLoading').style.display = 'none';
            document.getElementById('cameraIdle').style.display = '';
            let msg = 'Camera unavailable.';
            if (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError') {
                msg = '⚠️ Camera permission denied. Please allow camera access in your browser settings and try again.';
            } else if (err.name === 'NotFoundError' || err.name === 'DevicesNotFoundError') {
                msg = '⚠️ No camera found on this device.';
            } else if (err.name === 'NotReadableError' || err.name === 'TrackStartError') {
                msg = '⚠️ Camera is already in use by another application.';
            } else if (err.name === 'OverconstrainedError') {
                // Requested facing mode not available — retry without constraint
                if (facingMode !== 'any') { startCameraFallback(); return; }
                msg = '⚠️ Requested camera is not available. Try the other option.';
            } else if (location.protocol !== 'https:' && location.hostname !== 'localhost') {
                msg = '⚠️ Camera requires a secure connection (HTTPS). Please access this page over HTTPS.';
            } else {
                msg = '⚠️ ' + err.message;
            }
            showCameraError(msg);
        });
}

// Fallback: try without any facingMode constraint
function startCameraFallback() {
    navigator.mediaDevices.getUserMedia({ video: true, audio: false })
        .then(stream => {
            cameraStream = stream;
            const video = document.getElementById('cameraVideo');
            video.srcObject = stream;
            video.style.display = 'block';
            document.getElementById('cameraLoading').style.display = 'none';
            document.getElementById('captureBtn').disabled = false;
            document.getElementById('cameraSwitchRow').style.display = 'none';
        })
        .catch(err => {
            document.getElementById('cameraLoading').style.display = 'none';
            document.getElementById('cameraIdle').style.display = '';
            showCameraError('⚠️ Could not start any camera: ' + err.message);
        });
}

function switchCamera() {
    const next = currentFacing === 'user' ? 'environment' : 'user';
    startCamera(next);
}

function stopCamera() {
    if (cameraStream) {
        cameraStream.getTracks().forEach(t => t.stop());
        cameraStream = null;
    }
    const video = document.getElementById('cameraVideo');
    if (video) { video.srcObject = null; video.style.display = 'none'; }
    const captureBtn = document.getElementById('captureBtn');
    if (captureBtn) captureBtn.disabled = true;
}

function showCameraError(msg) {
    const el = document.getElementById('cameraError');
    el.innerHTML = msg;
    el.style.display = 'block';
}

function capturePhoto() {
    const video  = document.getElementById('cameraVideo');
    const canvas = document.getElementById('cameraCanvas');
    canvas.width  = video.videoWidth  || 640;
    canvas.height = video.videoHeight || 480;
    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
    const dataUrl = canvas.toDataURL('image/jpeg', 0.88);
    document.getElementById('photoBase64').value = dataUrl;
    document.getElementById('photoFileInput').value = '';
    setPhotoPreview(dataUrl);
    stopCamera();
    cameraModal.hide();
}

document.getElementById('cameraModal').addEventListener('hidden.bs.modal', function() {
    stopCamera();
    resetCameraUI();
});

/* ══════════════════════════════════════════════════════════════════════════
 * RESIDENT DUPLICATE CHECK
 * Fires on input/change of first_name, middle_name, last_name, suffix.
 *
 * Logic (mirrors officials modal):
 *   • first + last required (≥2 chars each) to trigger
 *   • middle_name passed when non-empty → narrows to 3-part match
 *   • suffix passed only when middle_name is also present → 4-part match
 *     (suffix alone without middle name is too ambiguous to narrow further)
 *
 * States: idle | checking | found | notfound
 * ══════════════════════════════════════════════════════════════════════════ */
let _resDupTimer = null;
let _resDupLast  = '';   // last queried key to skip duplicate requests

function resTriggerDupCheck() {
    const fn = (document.getElementById('resFirstName').value  || '').trim();
    const mn = (document.getElementById('resMiddleName').value || '').trim();
    const ln = (document.getElementById('resLastName').value   || '').trim();
    const sx = (document.getElementById('resSuffix').value     || '').trim();

    // Need at least 2 chars in both first and last to be worth querying
    if (fn.length < 2 || ln.length < 2) {
        resSetDupState('idle');
        return;
    }

    // Cache key includes all four parts so any change re-fires
    const key = [fn, mn, ln, sx].map(s => s.toLowerCase()).join('|');
    if (key === _resDupLast) return;

    clearTimeout(_resDupTimer);
    resSetDupState('checking');

    _resDupTimer = setTimeout(function () {
        _resDupLast = key;

        let url = '<?= BASE_URL ?>/ajax/check_resident_exists.php?'
            + 'first_name=' + encodeURIComponent(fn)
            + '&last_name='  + encodeURIComponent(ln);
        if (mn) url += '&middle_name=' + encodeURIComponent(mn);
        // Only send suffix when middle name is also present (avoids false positives)
        if (mn && sx) url += '&suffix=' + encodeURIComponent(sx);

        fetch(url, { credentials: 'same-origin', cache: 'no-store' })
            .then(r => r.json())
            .then(data => resSetDupState(data.found ? 'found' : 'notfound', data))
            .catch(() => resSetDupState('idle'));   // silent fail — never block the form
    }, 420);
}

function resSetDupState(state, data) {
    const bar = document.getElementById('resDupBar');

    if (state === 'idle') {
        bar.style.display = 'none';
        bar.innerHTML     = '';
        return;
    }

    bar.style.display = '';

    if (state === 'checking') {
        bar.innerHTML = `
            <div style="display:flex;align-items:center;gap:10px;padding:9px 14px;
                        background:#f0f6ff;border:1px solid #c7d9f0;border-radius:9px;font-size:0.82rem;">
                <span class="spinner-border spinner-border-sm text-primary"
                      style="width:14px;height:14px;border-width:2px;flex-shrink:0;"></span>
                <span style="color:#374151;">Checking resident records…</span>
            </div>`;
        return;
    }

    if (state === 'found') {
        const viewUrl = '<?= BASE_URL ?>/barangay_admin/view_resident.php?id=' + (data.id || '');
        bar.innerHTML = `
            <div style="display:flex;align-items:center;gap:12px;padding:10px 14px;
                        background:#f0fdf4;border:1px solid #86efac;border-radius:9px;font-size:0.82rem;">
                <div style="width:32px;height:32px;min-width:32px;background:#16a34a;border-radius:50%;
                            display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="bi bi-exclamation-lg text-white" style="font-size:1rem;"></i>
                </div>
                <div style="flex:1;min-width:0;">
                    <div style="font-weight:700;color:#15803d;">Possible Duplicate Found</div>
                    <div style="color:#166534;margin-top:1px;">
                        <strong>${resEscHtml(data.name || '')}</strong>
                        is already registered as a resident
                        <span style="background:#dcfce7;color:#166534;font-size:0.7rem;font-weight:600;
                                     padding:1px 8px;border-radius:20px;margin-left:4px;">
                            ${resEscHtml(data.status || 'Active')}
                        </span>
                    </div>
                    <div style="font-size:0.75rem;color:#166534;margin-top:3px;">
                        <i class="bi bi-info-circle me-1"></i>
                        Review the existing record before saving to avoid duplicates.
                    </div>
                </div>
                <a href="${viewUrl}" target="_blank"
                   class="btn btn-sm btn-outline-success" style="white-space:nowrap;font-size:0.75rem;flex-shrink:0;">
                    <i class="bi bi-eye me-1"></i>View
                </a>
            </div>`;
        return;
    }

    if (state === 'notfound') {
        bar.innerHTML = `
            <div style="display:flex;align-items:center;gap:12px;padding:9px 14px;
                        background:#f0fdf4;border:1px solid #bbf7d0;border-radius:9px;font-size:0.82rem;">
                <div style="width:28px;height:28px;min-width:28px;background:#16a34a;border-radius:50%;
                            display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="bi bi-check-lg text-white" style="font-size:0.9rem;"></i>
                </div>
                <div style="color:#15803d;font-weight:600;">
                    No existing resident found with this name — safe to proceed.
                </div>
            </div>`;
        return;
    }
}

function resEscHtml(s) {
    return String(s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── CSRF submit guard ─────────────────────────────────────────────────────
(function () {
    const form = document.getElementById('residentForm');
    if (!form) return;

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        const btn = form.querySelector('button[type="submit"]');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving…';
        }

        fetch('<?= BASE_URL ?>/ajax/get_csrf_token.php', {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store'
        })
        .then(r => r.json())
        .then(data => {
            const field = form.querySelector('input[name="csrf_token"]');
            if (field && data.csrf_token) field.value = data.csrf_token;
        })
        .catch(() => {})
        .finally(() => { form.submit(); });
    });
})();
</script>
</body>
</html>
