<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/mailer.php';
requireRole('barangay_admin');

$db  = Database::getInstance()->getConnection();
$u   = currentUser();
$bid = (int)$u['barangay_id'];

// ── FAST ACCESS CHECK — first, before migrations and any slow query ──────
// Deny immediately if the household ID is missing or doesn't belong to this barangay.
// Uses PK + barangay_id index — single-row lookup, < 1 ms.
$hhId = (int)($_GET['id'] ?? 0);
if (!$hhId) {
    redirect(BASE_URL . '/barangay_admin/households.php');
}
$_ownCheck = $db->prepare("SELECT id FROM households WHERE id = ? AND barangay_id = ? AND status != 'Archived' LIMIT 1");
$_ownCheck->execute([$hhId, $bid]);
if (!$_ownCheck->fetch()) {
    flash('hh', 'Household not found or already archived.', 'danger');
    redirect(BASE_URL . '/barangay_admin/households.php');
}

// Safe migrations (run only after ownership is confirmed)
foreach ([
    "ALTER TABLE addresses ADD COLUMN region VARCHAR(100) NULL AFTER id",
    "ALTER TABLE addresses ADD COLUMN zip_code VARCHAR(10) NULL",
    "ALTER TABLE barangays ADD COLUMN region VARCHAR(100) NULL",
    "ALTER TABLE barangays ADD COLUMN province VARCHAR(100) NULL",
    "ALTER TABLE barangays ADD COLUMN zip_code VARCHAR(10) NULL",
    "ALTER TABLE households ADD COLUMN status VARCHAR(20) DEFAULT 'Active'",
    "ALTER TABLE households ADD COLUMN archive_reason VARCHAR(255) NULL",
] as $sql) { try { $db->exec($sql); } catch (PDOException $e) {} }

// Fetch full household data (ownership already confirmed above)
$hhStmt = $db->prepare(
    "SELECT h.*, a.region as addr_region, a.province as addr_province, a.city as addr_city,
                a.barangay as addr_barangay, a.zip_code as addr_zip,
                a.purok, a.street, a.zone
     FROM households h
     LEFT JOIN addresses a ON h.address_id = a.id
     WHERE h.id = ? AND h.barangay_id = ? AND h.status != 'Archived'"
);
$hhStmt->execute([$hhId, $bid]);
$hh = $hhStmt->fetch();
// Ownership already confirmed by fast check above; $hh cannot be null here.
if (!$hh) {
    flash('hh', 'Household not found or already archived.', 'danger');
    redirect(BASE_URL . '/barangay_admin/households.php');
}

// Fetch barangay info for address auto-fill
$brgyInfoStmt = $db->prepare("SELECT id, barangay_name, municipality, region, province, zip_code FROM barangays WHERE id=?");
$brgyInfoStmt->execute([$bid]);
$hhBrgyInfo = $brgyInfoStmt->fetch() ?: [];

// Active residents for dropdown
$residentsStmt = $db->prepare(
    "SELECT r.id,
            CONCAT(r.last_name,', ',r.first_name) AS full_name,
            r.contact_number,
            a.purok, a.street, a.zone
     FROM residents r
     LEFT JOIN addresses a ON r.address_id = a.id
     WHERE r.barangay_id = ? AND r.resident_status = 'Active'
     ORDER BY r.last_name, r.first_name"
);
$residentsStmt->execute([$bid]);
$residents = $residentsStmt->fetchAll();

// POST handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrAbort();
    $action = $_POST['action'] ?? 'edit';

    // ── Add Member ────────────────────────────────────────────────────────
    if ($action === 'add_member') {
        $residentId   = (int)$_POST['resident_id'];
        $relationship = sanitize($_POST['relationship'] ?? 'Member');

        if (!$residentId) {
            flash('hh', 'Please select a resident.', 'danger');
            redirect(BASE_URL . '/barangay_admin/edit_household.php?id=' . $hhId);
        }
        // Verify resident belongs to this barangay
        $resChk = $db->prepare("SELECT id, is_senior, is_pwd FROM residents WHERE id=? AND barangay_id=? AND resident_status='Active'");
        $resChk->execute([$residentId, $bid]);
        $resRow = $resChk->fetch();
        if (!$resRow) {
            flash('hh', 'Resident not found or inactive.', 'danger');
            redirect(BASE_URL . '/barangay_admin/edit_household.php?id=' . $hhId);
        }
        // Prevent duplicate
        $dupChk = $db->prepare("SELECT id FROM household_members WHERE household_id=? AND resident_id=?");
        $dupChk->execute([$hhId, $residentId]);
        if ($dupChk->fetch()) {
            flash('hh', 'This resident is already a member of this household.', 'warning');
            redirect(BASE_URL . '/barangay_admin/edit_household.php?id=' . $hhId);
        }
        $db->prepare("INSERT INTO household_members (household_id, resident_id, relationship) VALUES (?,?,?)")
           ->execute([$hhId, $residentId, $relationship]);
        // Auto-count tags
        $db->prepare("UPDATE households SET total_members = total_members + 1 WHERE id=?")->execute([$hhId]);
        if ($resRow['is_senior']) $db->prepare("UPDATE households SET total_senior = total_senior + 1 WHERE id=?")->execute([$hhId]);
        if ($resRow['is_pwd'])    $db->prepare("UPDATE households SET total_pwd    = total_pwd    + 1 WHERE id=?")->execute([$hhId]);
        // Sync purok from household to resident address
        $hhAddrStmt = $db->prepare("SELECT a.purok, a.street, a.zone FROM households h JOIN addresses a ON h.address_id=a.id WHERE h.id=?");
        $hhAddrStmt->execute([$hhId]);
        $hhAddr = $hhAddrStmt->fetch();
        if ($hhAddr) {
            $db->prepare("UPDATE addresses SET purok=?, street=?, zone=? WHERE id=(SELECT address_id FROM residents WHERE id=?)")
               ->execute([$hhAddr['purok'], $hhAddr['street'], $hhAddr['zone'], $residentId]);
        }
        $resName = $db->prepare("SELECT CONCAT(first_name,' ',last_name) FROM residents WHERE id=?")->execute([$residentId])
            ? $db->query("SELECT CONCAT(first_name,' ',last_name) FROM residents WHERE id=$residentId")->fetchColumn() : 'Resident';
        logActivity($u['id'], 'ADD_HH_MEMBER', 'Households', "Added $resName to household {$hh['household_code']} as $relationship");
        flash('hh', "<strong>$resName</strong> added to household as <strong>$relationship</strong>.", 'success');
        redirect(BASE_URL . '/barangay_admin/edit_household.php?id=' . $hhId);
    }

    // ── Remove Member ─────────────────────────────────────────────────────
    if ($action === 'remove_member') {
        $memberId = (int)$_POST['member_id'];
        $chk = $db->prepare(
            "SELECT hm.resident_id, hm.relationship FROM household_members hm
             JOIN households h ON hm.household_id = h.id
             WHERE hm.id=? AND hm.household_id=? AND h.barangay_id=?"
        );
        $chk->execute([$memberId, $hhId, $bid]);
        $row = $chk->fetch();
        if (!$row) {
            flash('hh', 'Member record not found.', 'danger');
            redirect(BASE_URL . '/barangay_admin/edit_household.php?id=' . $hhId);
        }
        // Block removing the head
        if ($row['relationship'] === 'Head') {
            flash('hh', 'Cannot remove the Head of Family. Change the head first from the Household Information section.', 'warning');
            redirect(BASE_URL . '/barangay_admin/edit_household.php?id=' . $hhId);
        }
        $resId = $row['resident_id'];
        $db->prepare("DELETE FROM household_members WHERE id=?")->execute([$memberId]);
        // Decrement counts
        $db->prepare("UPDATE households SET total_members = GREATEST(total_members - 1, 0) WHERE id=?")->execute([$hhId]);
        $rr = $db->prepare("SELECT is_senior, is_pwd FROM residents WHERE id=?");
        $rr->execute([$resId]);
        $rr = $rr->fetch();
        if ($rr && $rr['is_senior']) $db->prepare("UPDATE households SET total_senior = GREATEST(total_senior - 1, 0) WHERE id=?")->execute([$hhId]);
        if ($rr && $rr['is_pwd'])    $db->prepare("UPDATE households SET total_pwd    = GREATEST(total_pwd    - 1, 0) WHERE id=?")->execute([$hhId]);
        $resName = $db->query("SELECT CONCAT(first_name,' ',last_name) FROM residents WHERE id=$resId")->fetchColumn() ?: 'Resident';
        logActivity($u['id'], 'REMOVE_HH_MEMBER', 'Households', "Removed $resName from household {$hh['household_code']}");
        flash('hh', "<strong>$resName</strong> removed from household.", 'info');
        redirect(BASE_URL . '/barangay_admin/edit_household.php?id=' . $hhId);
    }

    // ── Edit (default) ────────────────────────────────────────────────────

    $headId  = (int)$_POST['head_resident_id'] ?: null;
    $contact = sanitize($_POST['contact_number'] ?? '');
    $los     = sanitize($_POST['length_of_stay'] ?? '');
    $members = (int)$_POST['total_members'];
    $senior  = (int)$_POST['total_senior'];
    $pwd     = (int)$_POST['total_pwd'];
    $income  = sanitize($_POST['income_range'] ?? '');

    // Validate contact
    if ($contact && !preg_match('/^09\d{9}$/', $contact)) {
        flash('hh', 'Contact number must be 11 digits and start with 09 (e.g. 09171234567).', 'danger');
        redirect(BASE_URL . '/barangay_admin/edit_household.php?id=' . $hhId);
    }
    // Duplicate contact check (exclude current household)
    if ($contact) {
        $dupQ = $db->prepare("SELECT id FROM households WHERE contact_number=? AND barangay_id=? AND id!=? AND status!='Inactive'");
        $dupQ->execute([$contact, $bid, $hhId]);
        if ($dupQ->fetch()) {
            flash('hh', "Contact number <strong>$contact</strong> is already in use by another household.", 'danger');
            redirect(BASE_URL . '/barangay_admin/edit_household.php?id=' . $hhId);
        }
    }

    // Address
    $addrId   = (int)$_POST['address_id'];
    $region   = sanitize($_POST['region']        ?? $hhBrgyInfo['region']       ?? '');
    $province = sanitize($_POST['province']      ?? $hhBrgyInfo['province']     ?? '');
    $city     = sanitize($_POST['city']          ?? $hhBrgyInfo['municipality'] ?? '');
    $brgyAddr = sanitize($_POST['barangay_addr'] ?? $hhBrgyInfo['barangay_name']?? '');
    $zipCode  = sanitize($_POST['zip_code']      ?? $hhBrgyInfo['zip_code']     ?? '3114');
    $purok    = sanitize($_POST['purok'] ?? '');

    $db->prepare("UPDATE addresses SET region=?,province=?,city=?,barangay=?,street=?,purok=?,zone=?,zip_code=? WHERE id=?")
       ->execute([$region, $province, $city, $brgyAddr, '', $purok, '', $zipCode, $addrId]);

    $db->prepare(
        "UPDATE households SET head_resident_id=?,contact_number=?,length_of_stay=?,total_members=?,total_senior=?,total_pwd=?,income_range=?
         WHERE id=? AND barangay_id=?"
    )->execute([$headId, $contact, $los, $members, $senior, $pwd, $income, $hhId, $bid]);

    $headName = $db->prepare("SELECT CONCAT(first_name,' ',last_name) FROM residents WHERE id=?");
    $headName->execute([$headId]);
    $headName = $headName->fetchColumn() ?: 'unknown head';
    logActivity($u['id'], 'EDIT_HOUSEHOLD', 'Households', "Updated household of $headName (code: {$hh['household_code']})");
    flash('hh', 'Household updated successfully.', 'success');

    // ── Email confirmation to the admin who edited the household ─────────
    if ($u['email']) {
        $brgyNameHH = $db->query("SELECT barangay_name FROM barangays WHERE id=$bid")->fetchColumn() ?: '';
        sendTransactionLogEmail(
            $u['email'], $u['name'],
            'EDIT_HOUSEHOLD', 'Households',
            "Updated household {$hh['household_code']} (Head: $headName) — Barangay: $brgyNameHH",
            EMAIL_BASE_URL . '/barangay_admin/households.php'
        );
    }

    redirect(BASE_URL . '/barangay_admin/households.php');
}

$flash     = getFlash('hh');
$pageIcon     = 'bi-pencil-square';
$pageTitle    = 'Edit Household';

// Current members (for table display)
$membersStmt = $db->prepare(
    "SELECT hm.id, hm.relationship,
            r.id AS resident_id,
            CONCAT(r.last_name,', ',r.first_name,
                IF(r.middle_name!='' AND r.middle_name IS NOT NULL, CONCAT(' ',LEFT(r.middle_name,1),'.'), '')) AS full_name,
            r.is_senior, r.is_pwd, r.is_4ps, r.is_solo_parent
     FROM household_members hm
     JOIN residents r ON hm.resident_id = r.id
     WHERE hm.household_id = ?
     ORDER BY FIELD(hm.relationship,'Head') DESC, r.last_name ASC"
);
$membersStmt->execute([$hhId]);
$currentMembers = $membersStmt->fetchAll();

// Residents NOT already in this household (for add dropdown)
$alreadyIds = array_column($currentMembers, 'resident_id');
$alreadyIds[] = 0; // prevent empty IN clause
$inList = implode(',', array_map('intval', $alreadyIds));
$availResidents = $db->query(
    "SELECT r.id, CONCAT(r.last_name,', ',r.first_name) AS full_name
     FROM residents r
     WHERE r.barangay_id=$bid AND r.resident_status='Active'
       AND r.id NOT IN ($inList)
     ORDER BY r.last_name, r.first_name"
)->fetchAll();
$pageSubtitle = 'Update household information.';
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
    <style>
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

        .hh-section {
            background: #fff;
            border-radius: 12px;
            border: 1px solid #d0ecd6;
            overflow: hidden;
            margin-bottom: 16px;
        }
        .hh-section-head {
            background: rgba(26,79,122,0.05);
            border-bottom: 1px solid rgba(26,79,122,0.1);
            padding: 11px 18px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .hh-section-head span {
            font-weight: 700;
            font-size: 0.8rem;
            color: #1a4f7a;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }
        .hh-section-body { padding: 18px; }

        .hh-footer {
            background: transparent;
            border-top: none;
            padding: 14px 0;
            margin-top: 8px;
        }
        .btn-xs { padding: 2px 8px; font-size: 0.75rem; }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/sidebar_barangay.php'; ?>
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<div class="main-content" style="min-height:100vh;padding-bottom:120px;">

    <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show">
        <?= $flash['message'] ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Back button -->
    <div class="mb-3 d-flex align-items-center gap-2">
        <a href="<?= BASE_URL ?>/barangay_admin/households.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back to Households
        </a>
    </div>

    <!-- Blue header banner -->
    <div style="background:linear-gradient(90deg,#1a4f7a,#2d7db3);border-radius:12px;padding:18px 24px;margin-bottom:18px;">
        <div class="d-flex align-items-center gap-3">
            <div style="width:44px;height:44px;background:rgba(255,255,255,0.15);border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i class="bi bi-house-gear-fill text-white" style="font-size:1.2rem;"></i>
            </div>
            <div>
                <div style="font-size:0.72rem;color:rgba(255,255,255,0.7);text-transform:uppercase;letter-spacing:0.08em;">Household Management</div>
                <div style="font-size:1.1rem;font-weight:800;color:#fff;">
                    Edit Household &mdash; <code style="background:rgba(255,255,255,0.15);border-radius:5px;padding:1px 8px;font-size:0.95rem;"><?= htmlspecialchars($hh['household_code']) ?></code>
                </div>
                <div style="font-size:0.78rem;color:rgba(255,255,255,0.75);margin-top:2px;">Update the details for this household record.</div>
            </div>
        </div>
    </div>

    <form method="POST" id="householdForm">
        <?= csrfField() ?>
        <input type="hidden" name="address_id" value="<?= $hh['address_id'] ?>">
        <!-- Hidden address fields -->
        <input type="hidden" name="region"        id="fRegion">
        <input type="hidden" name="province"      id="fProvince">
        <input type="hidden" name="city"          id="fCity">
        <input type="hidden" name="barangay_addr" id="fBrgyAddr">
        <input type="hidden" name="zip_code"      id="fZipCode">

        <!-- ── SECTION 1: Household Information ── -->
        <div class="hh-section">
            <div class="hh-section-head">
                <i class="bi bi-house-fill" style="color:#1a4f7a;"></i>
                <span>Household Information</span>
            </div>
            <div class="hh-section-body">
                <div class="row g-3">

                    <div class="col-md-6">
                        <label class="field-label" for="headResidentId">Head of Family</label>
                        <select name="head_resident_id" id="headResidentId" class="form-select"
                                onchange="autofillFromResident(this)">
                            <option value="">— None —</option>
                            <?php foreach ($residents as $r): ?>
                                <option value="<?= $r['id'] ?>"
                                    data-contact="<?= htmlspecialchars($r['contact_number'] ?? '') ?>"
                                    data-purok="<?= htmlspecialchars($r['purok'] ?? '') ?>"
                                    data-street="<?= htmlspecialchars($r['street'] ?? '') ?>"
                                    data-zone="<?= htmlspecialchars($r['zone'] ?? '') ?>"
                                    <?= $r['id'] == $hh['head_resident_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($r['full_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="field-label" for="fContact">Contact Number</label>
                        <input type="text" name="contact_number" id="fContact" class="form-control"
                               inputmode="numeric" pattern="09[0-9]{9}" placeholder="e.g. 09171234567"
                               value="<?= htmlspecialchars($hh['contact_number'] ?? '') ?>"
                               oninput="this.value=this.value.replace(/[^0-9]/g,'');validateContact(this,'fContactErr')"
                               onkeydown="if(!/[0-9]|Backspace|Delete|ArrowLeft|ArrowRight|Tab|Home|End/.test(event.key))event.preventDefault()">
                        <div id="fContactErr" class="text-danger mt-1" style="display:none;font-size:0.72rem;"></div>
                    </div>

                    <div class="col-md-4">
                        <label class="field-label" for="fIncome">Income Range</label>
                        <select name="income_range" id="fIncome" class="form-select">
                            <option value="">— Select —</option>
                            <?php
                            $incomeOptions = ['Below ₱10,000','₱10,000 - ₱20,000','₱20,001 - ₱40,000','₱40,001 - ₱60,000','Above ₱60,000'];
                            foreach ($incomeOptions as $opt):
                            ?>
                                <option <?= $hh['income_range'] === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="field-label" for="fLos">Length of Stay</label>
                        <input type="text" name="length_of_stay" id="fLos" class="form-control"
                               placeholder="e.g. 5 years"
                               value="<?= htmlspecialchars($hh['length_of_stay'] ?? '') ?>">
                    </div>

                </div>
            </div>
        </div>

        <!-- ── SECTION 2: Household Composition ── -->
        <div class="hh-section">
            <div class="hh-section-head">
                <i class="bi bi-people-fill" style="color:#1a4f7a;"></i>
                <span>Household Composition</span>
            </div>
            <div class="hh-section-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="field-label" for="fMembers">Total Members <span class="req">*</span></label>
                        <input type="number" name="total_members" id="fMembers" class="form-control"
                               min="0" required
                               value="<?= (int)$hh['total_members'] ?>"
                               oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                    </div>
                    <div class="col-md-4">
                        <label class="field-label" for="fSenior">Senior Citizens</label>
                        <input type="number" name="total_senior" id="fSenior" class="form-control"
                               min="0"
                               value="<?= (int)$hh['total_senior'] ?>"
                               oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                    </div>
                    <div class="col-md-4">
                        <label class="field-label" for="fPwd">PWD Members</label>
                        <input type="number" name="total_pwd" id="fPwd" class="form-control"
                               min="0"
                               value="<?= (int)$hh['total_pwd'] ?>"
                               oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                    </div>
                </div>
            </div>
        </div>

        <!-- ── SECTION 3: Address ── -->
        <div class="hh-section">
            <div class="hh-section-head">
                <i class="bi bi-geo-alt-fill" style="color:#1a4f7a;"></i>
                <span>Address</span>
            </div>
            <div class="hh-section-body">
                <div class="row g-3">
                    <div class="col-md-5">
                        <label class="field-label">Region</label>
                        <input type="text" id="fRegionDisplay" class="form-control" readonly tabindex="-1">
                    </div>
                    <div class="col-md-4">
                        <label class="field-label">Province</label>
                        <input type="text" id="fProvinceDisplay" class="form-control" readonly tabindex="-1">
                    </div>
                    <div class="col-md-3">
                        <label class="field-label">Zip Code</label>
                        <input type="text" id="fZipDisplay" class="form-control" readonly tabindex="-1">
                    </div>
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
                               placeholder="e.g. Purok 3, Rizal St., Zone 2"
                               value="<?= htmlspecialchars(trim(implode(', ', array_filter([$hh['purok'] ?? '', $hh['street'] ?? '', $hh['zone'] ?? ''])))) ?>">
                    </div>
                </div>
                <div class="alert alert-info py-2 mt-3 mb-0" style="font-size:0.75rem;">
                    <i class="bi bi-info-circle me-1"></i>
                    Region, Province, City, and Barangay are auto-filled from your barangay profile. Only Purok / Street / Zone is editable.
                </div>
            </div>
        </div>

        <!-- ── SECTION 4: Members ── -->
        <div class="hh-section" style="overflow:visible;position:relative;z-index:5;">
            <div class="hh-section-head">
                <i class="bi bi-people-fill" style="color:#1a4f7a;"></i>
                <span>Current Members</span>
                <span class="ms-auto badge" style="background:#e0edff;color:#1a4f7a;font-size:0.72rem;font-weight:700;border-radius:20px;padding:3px 10px;">
                    <?= count($currentMembers) ?> member<?= count($currentMembers) !== 1 ? 's' : '' ?>
                </span>
            </div>
            <div class="hh-section-body p-0" style="overflow:visible;">

                <!-- Member table -->
                <div class="table-responsive" style="overflow:visible;">
                    <table class="table mb-0" style="font-size:0.83rem;">
                        <thead>
                            <tr style="background:#f8fafc;">
                                <th style="padding:10px 18px;border:none;font-size:0.7rem;font-weight:700;color:#3a7fa8;text-transform:uppercase;letter-spacing:0.06em;">Name</th>
                                <th style="padding:10px 18px;border:none;font-size:0.7rem;font-weight:700;color:#3a7fa8;text-transform:uppercase;letter-spacing:0.06em;">Relationship</th>
                                <th style="padding:10px 18px;border:none;font-size:0.7rem;font-weight:700;color:#3a7fa8;text-transform:uppercase;letter-spacing:0.06em;">Tags</th>
                                <th style="padding:10px 18px;border:none;font-size:0.7rem;font-weight:700;color:#3a7fa8;text-transform:uppercase;letter-spacing:0.06em;text-align:right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($currentMembers)): ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4" style="border:none;">
                                    <i class="bi bi-people" style="font-size:1.8rem;opacity:0.3;display:block;margin-bottom:6px;"></i>
                                    No members yet. Add one below.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($currentMembers as $m):
                                $relColor = match($m['relationship']) {
                                    'Head'     => 'background:#1a4f7a;color:#fff;',
                                    'Spouse'   => 'background:#0dcaf0;color:#000;',
                                    'Son','Daughter' => 'background:#198754;color:#fff;',
                                    default    => 'background:#6c757d;color:#fff;'
                                };
                            ?>
                            <tr style="border-bottom:1px solid #eef4fb;">
                                <td style="padding:10px 18px;border:none;font-weight:600;color:#1a2e1f;">
                                    <?= htmlspecialchars($m['full_name']) ?>
                                </td>
                                <td style="padding:10px 18px;border:none;">
                                    <span style="<?= $relColor ?>font-size:0.72rem;font-weight:600;padding:3px 10px;border-radius:20px;display:inline-block;">
                                        <?= htmlspecialchars($m['relationship']) ?>
                                    </span>
                                </td>
                                <td style="padding:10px 18px;border:none;">
                                    <div class="d-flex gap-1 flex-wrap">
                                        <?php if ($m['is_senior']): ?><span style="background:#fef3c7;color:#92400e;font-size:0.68rem;font-weight:600;padding:2px 7px;border-radius:20px;">Senior</span><?php endif; ?>
                                        <?php if ($m['is_pwd']): ?><span style="background:#f3e8ff;color:#6d28d9;font-size:0.68rem;font-weight:600;padding:2px 7px;border-radius:20px;">PWD</span><?php endif; ?>
                                        <?php if ($m['is_4ps']): ?><span style="background:#d1fae5;color:#065f46;font-size:0.68rem;font-weight:600;padding:2px 7px;border-radius:20px;">4Ps</span><?php endif; ?>
                                        <?php if ($m['is_solo_parent']): ?><span style="background:#e0f2fe;color:#0369a1;font-size:0.68rem;font-weight:600;padding:2px 7px;border-radius:20px;">Solo</span><?php endif; ?>
                                        <?php if (!$m['is_senior'] && !$m['is_pwd'] && !$m['is_4ps'] && !$m['is_solo_parent']): ?>
                                            <span style="color:#9ca3af;font-size:0.72rem;">—</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td style="padding:10px 18px;border:none;text-align:right;">
                                    <?php if ($m['relationship'] !== 'Head'): ?>
                                    <button type="button" class="btn btn-xs btn-outline-danger"
                                            onclick="openRemoveMember(<?= $m['id'] ?>, '<?= htmlspecialchars(addslashes($m['full_name'])) ?>')"
                                            title="Remove from household">
                                        <i class="bi bi-person-dash me-1"></i>Remove
                                    </button>
                                    <?php else: ?>
                                    <span style="font-size:0.72rem;color:#9ca3af;">Change head via Info section</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Add Member Form -->
                <div style="background:rgba(25,135,84,0.04);border-top:1px solid rgba(25,135,84,0.15);padding:16px 18px;">
                    <div class="fw-semibold text-success mb-3" style="font-size:0.85rem;">
                        <i class="bi bi-person-plus-fill me-2"></i>Add Member to this Household
                    </div>
                    <div class="row g-2 align-items-end">
                        <!-- Resident picker -->
                        <div class="col-md-6">
                            <label class="field-label">Select Resident <span class="req">*</span></label>
                            <div id="addResidentWrap" style="position:relative;">
                                <div id="addResidentBox"
                                     style="display:flex;align-items:center;gap:6px;border:1px solid #d0dce8;border-radius:7px;padding:5px 10px;background:#fff;cursor:text;font-size:0.84rem;min-height:34px;"
                                     onclick="focusAddResidentSearch()">
                                    <i class="bi bi-search" style="color:#9ca3af;font-size:0.78rem;flex-shrink:0;"></i>
                                    <input type="text" id="addResidentSearch"
                                           placeholder="Type name to search..."
                                           autocomplete="off"
                                           style="border:none;outline:none;flex:1;font-size:0.84rem;background:transparent;min-width:0;"
                                           oninput="filterAddResidents(this.value)"
                                           onfocus="openAddResidentDropdown()">
                                    <span id="addResidentClear" onclick="clearAddResident(event)"
                                          style="display:none;cursor:pointer;color:#9ca3af;font-size:0.8rem;" title="Clear">
                                        <i class="bi bi-x-circle-fill"></i>
                                    </span>
                                </div>
                                <div id="addResidentDropdown"
                                     style="display:none;position:absolute;top:100%;left:0;right:0;margin-top:2px;background:#fff;border:1px solid #3a7fa8;border-radius:7px;box-shadow:0 4px 16px rgba(0,0,0,0.12);max-height:220px;overflow-y:auto;z-index:9999;">
                                </div>
                            </div>
                        </div>
                        <!-- Relationship picker -->
                        <div class="col-md-4">
                            <label class="field-label">Relationship <span class="req">*</span></label>
                            <div id="addRelWrap" style="position:relative;">
                                <div id="addRelBox"
                                     style="display:flex;align-items:center;justify-content:space-between;border:1px solid #d0dce8;border-radius:7px;padding:5px 10px;background:#fff;cursor:pointer;font-size:0.84rem;min-height:34px;user-select:none;"
                                     onclick="toggleAddRelDropdown()">
                                    <span id="addRelLabel" style="color:#9ca3af;">— Select —</span>
                                    <i class="bi bi-chevron-down" style="font-size:0.72rem;color:#9ca3af;"></i>
                                </div>
                                <div id="addRelDropdown"
                                     style="display:none;position:absolute;top:100%;left:0;right:0;margin-top:2px;background:#fff;border:1px solid #d0dce8;border-radius:7px;box-shadow:0 4px 16px rgba(0,0,0,0.12);max-height:220px;overflow-y:auto;z-index:9999;">
                                    <?php foreach (['Spouse','Son','Daughter','Father','Mother','Brother','Sister',
                                        'Grandfather','Grandmother','Grandson','Granddaughter','Uncle','Aunt',
                                        'Nephew','Niece','Cousin','In-Law','Boarder','Helper','Other'] as $rel): ?>
                                    <div onclick="pickAddRel('<?= $rel ?>')"
                                         style="padding:7px 14px;font-size:0.84rem;cursor:pointer;border-bottom:1px solid #f1f5f9;"
                                         onmouseover="this.style.background='#1a4f7a';this.style.color='#fff';"
                                         onmouseout="this.style.background='';this.style.color='';">
                                        <?= $rel ?>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <!-- Submit -->
                        <div class="col-md-2">
                            <button type="button" class="btn btn-success btn-sm w-100 fw-semibold"
                                    onclick="submitAddMember()">
                                <i class="bi bi-plus-lg me-1"></i>Add
                            </button>
                        </div>
                    </div>
                    <div class="text-muted mt-2" style="font-size:0.72rem;">
                        <i class="bi bi-info-circle me-1"></i>
                        Senior, PWD, 4Ps, and Solo Parent tags are auto-counted from the resident's profile — no manual entry needed.
                    </div>
                </div>

            </div>
        </div>

        <!-- ── Sticky footer ── -->
        <div class="hh-footer">
            <div class="d-flex gap-2 justify-content-end">
                <a href="<?= BASE_URL ?>/barangay_admin/households.php"
                   class="btn btn-outline-secondary px-4">
                    <i class="bi bi-x-lg me-1"></i>Cancel
                </a>
                <button type="submit" class="btn btn-primary fw-semibold px-5"
                        onclick="return validateBeforeSubmit()">
                    <i class="bi bi-check-lg me-1"></i>Save Changes
                </button>
            </div>
        </div>

    </form>

</div><!-- /.main-content -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('show'); }

// ── Barangay address constants ──────────────────────────────────────────
const brgyRegion   = <?= json_encode($hhBrgyInfo['region']       ?? '') ?>;
const brgyProvince = <?= json_encode($hhBrgyInfo['province']     ?? '') ?>;
const brgyCity     = <?= json_encode($hhBrgyInfo['municipality'] ?? '') ?>;
const brgyName     = <?= json_encode($hhBrgyInfo['barangay_name']?? '') ?>;
const brgyZip      = <?= json_encode($hhBrgyInfo['zip_code'] ?: '3114') ?>;

// Existing address values from DB
const savedRegion   = <?= json_encode($hh['addr_region']   ?? '') ?>;
const savedProvince = <?= json_encode($hh['addr_province'] ?? '') ?>;
const savedCity     = <?= json_encode($hh['addr_city']     ?? '') ?>;
const savedBrgy     = <?= json_encode($hh['addr_barangay'] ?? '') ?>;
const savedZip      = <?= json_encode($hh['addr_zip']      ?? '') ?>;

function fillBrgyAddress() {
    const region   = savedRegion   || brgyRegion;
    const province = savedProvince || brgyProvince;
    const city     = savedCity     || brgyCity;
    const brgy     = savedBrgy     || brgyName;
    const zip      = savedZip      || brgyZip || '3114';

    document.getElementById('fRegion').value   = region;
    document.getElementById('fProvince').value = province;
    document.getElementById('fCity').value     = city;
    document.getElementById('fBrgyAddr').value = brgy;
    document.getElementById('fZipCode').value  = zip;

    document.getElementById('fRegionDisplay').value   = region   || '—';
    document.getElementById('fProvinceDisplay').value = province || '—';
    document.getElementById('fCityDisplay').value     = city     || '—';
    document.getElementById('fBrgyDisplay').value     = brgy     || '—';
    document.getElementById('fZipDisplay').value      = zip      || '—';
}

function autofillFromResident(select) {
    const opt = select.options[select.selectedIndex];
    if (!opt || !opt.value) return;

    const contact = opt.dataset.contact || '';
    const purok   = opt.dataset.purok   || '';
    const street  = opt.dataset.street  || '';
    const zone    = opt.dataset.zone    || '';

    const contactField = document.getElementById('fContact');
    if (contact) {
        contactField.value = contact;
        validateContact(contactField, 'fContactErr');
    }

    const parts    = [purok, street, zone].filter(v => v.trim() !== '');
    const combined = parts.join(', ');
    if (combined) document.getElementById('fPurok').value = combined;
}

function validateBeforeSubmit() {
    const contact = document.getElementById('fContact').value.trim();
    if (contact && !/^09\d{9}$/.test(contact)) {
        document.getElementById('fContactErr').textContent = 'Must be 11 digits starting with 09.';
        document.getElementById('fContactErr').style.display = 'block';
        document.getElementById('fContact').focus();
        return false;
    }
    return true;
}

document.addEventListener('DOMContentLoaded', fillBrgyAddress);

// ── Available residents (not yet in this household) ───────────────────────
const _availResidents = <?= json_encode(array_map(fn($r) => ['id'=>$r['id'],'name'=>$r['full_name']], $availResidents)) ?>;

// ── Resident search dropdown ──────────────────────────────────────────────
function escH(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

function renderAddResidents(items) {
    const dd = document.getElementById('addResidentDropdown');
    if (!items.length) {
        dd.innerHTML = '<div style="padding:10px 14px;font-size:0.82rem;color:#9ca3af;text-align:center;">No residents found.</div>';
        return;
    }
    dd.innerHTML = items.map(r =>
        `<div onclick="selectAddResident(${r.id},'${escH(r.name)}')"
              style="padding:7px 14px;font-size:0.84rem;cursor:pointer;border-bottom:1px solid #f1f5f9;"
              onmouseover="this.style.background='#e8f3fb'" onmouseout="this.style.background=''">
            ${escH(r.name)}
         </div>`
    ).join('');
}

function focusAddResidentSearch() {
    document.getElementById('addResidentSearch').focus();
}

function openAddResidentDropdown() {
    const dd = document.getElementById('addResidentDropdown');
    const q  = document.getElementById('addResidentSearch').value.trim();
    const filtered = q ? _availResidents.filter(r => r.name.toLowerCase().includes(q.toLowerCase())) : _availResidents;
    renderAddResidents(filtered);
    dd.style.display = 'block';
    document.getElementById('addResidentBox').style.borderColor = '#3a7fa8';
    document.getElementById('addResidentBox').style.boxShadow  = '0 0 0 3px rgba(58,127,168,0.12)';
}

function filterAddResidents(q) {
    const filtered = q.trim()
        ? _availResidents.filter(r => r.name.toLowerCase().includes(q.toLowerCase()))
        : _availResidents;
    const dd  = document.getElementById('addResidentDropdown');
    renderAddResidents(filtered);
    dd.style.display = 'block';
    document.getElementById('addResidentId').value = '';
    document.getElementById('addResidentClear').style.display = q ? 'inline' : 'none';
    // Highlight match
    if (q.trim()) {
        dd.querySelectorAll('div[onclick]').forEach(el => {
            const text = el.textContent.trim();
            const idx  = text.toLowerCase().indexOf(q.toLowerCase());
            if (idx >= 0) {
                el.innerHTML = escH(text.substring(0, idx))
                    + `<strong style="color:#1a4f7a;">${escH(text.substring(idx, idx+q.length))}</strong>`
                    + escH(text.substring(idx+q.length));
            }
        });
    }
}

function selectAddResident(id, name) {
    document.getElementById('addResidentId').value = id;
    document.getElementById('addResidentSearch').value = name;
    document.getElementById('addResidentClear').style.display = 'inline';
    document.getElementById('addResidentDropdown').style.display = 'none';
    document.getElementById('addResidentBox').style.borderColor = '#198754';
    document.getElementById('addResidentBox').style.boxShadow  = '0 0 0 3px rgba(25,135,84,0.15)';
}

function clearAddResident(e) {
    e.stopPropagation();
    document.getElementById('addResidentId').value = '';
    document.getElementById('addResidentSearch').value = '';
    document.getElementById('addResidentClear').style.display = 'none';
    document.getElementById('addResidentDropdown').style.display = 'none';
    document.getElementById('addResidentBox').style.borderColor = '#d0dce8';
    document.getElementById('addResidentBox').style.boxShadow  = 'none';
    document.getElementById('addResidentSearch').focus();
}

// Close resident dropdown on outside click
document.addEventListener('click', function(e) {
    const wrap = document.getElementById('addResidentWrap');
    if (wrap && !wrap.contains(e.target)) {
        document.getElementById('addResidentDropdown').style.display = 'none';
        document.getElementById('addResidentBox').style.borderColor = '#d0dce8';
        document.getElementById('addResidentBox').style.boxShadow  = 'none';
    }
});

// ── Relationship dropdown ─────────────────────────────────────────────────
function toggleAddRelDropdown() {
    const dd  = document.getElementById('addRelDropdown');
    const box = document.getElementById('addRelBox');
    if (dd.style.display === 'none' || !dd.style.display) {
        dd.style.display = 'block';
        box.style.borderColor = '#3a7fa8';
        box.style.boxShadow   = '0 0 0 3px rgba(58,127,168,0.12)';
    } else {
        dd.style.display = 'none';
    }
}

function pickAddRel(val) {
    document.getElementById('addRelVal').value = val;
    document.getElementById('addRelLabel').textContent = val;
    document.getElementById('addRelLabel').style.color = '#212529';
    document.getElementById('addRelDropdown').style.display = 'none';
    document.getElementById('addRelBox').style.borderColor = '#198754';
    document.getElementById('addRelBox').style.boxShadow  = '0 0 0 3px rgba(25,135,84,0.15)';
}

// Close rel dropdown on outside click
document.addEventListener('click', function(e) {
    const wrap = document.getElementById('addRelWrap');
    if (wrap && !wrap.contains(e.target)) {
        document.getElementById('addRelDropdown').style.display = 'none';
        if (!document.getElementById('addRelVal').value) {
            document.getElementById('addRelBox').style.borderColor = '#d0dce8';
            document.getElementById('addRelBox').style.boxShadow  = 'none';
        }
    }
});

// ── Form validation before submit ─────────────────────────────────────────
function validateAddMember() {
    const resId = document.getElementById('addResidentId').value;
    const rel   = document.getElementById('addRelVal').value;
    if (!resId) {
        document.getElementById('addResidentBox').style.borderColor = '#dc2626';
        document.getElementById('addResidentSearch').focus();
        return false;
    }
    if (!rel) {
        document.getElementById('addRelBox').style.borderColor = '#dc2626';
        return false;
    }
    return true;
}

function submitAddMember() {
    if (!validateAddMember()) return;
    document.getElementById('addMemberForm').submit();
}

// ── Remove member modal ───────────────────────────────────────────────────
let _removeMemberModal = null;
document.addEventListener('DOMContentLoaded', function() {
    _removeMemberModal = new bootstrap.Modal(document.getElementById('removeMemberModal'));
});

function openRemoveMember(memberId, memberName) {
    document.getElementById('removeMemberId').value = memberId;
    document.getElementById('removeMemberName').textContent = memberName;
    _removeMemberModal.show();
}

function submitRemoveMember() {
    document.getElementById('removeMemberForm').submit();
}
</script>

<!-- ── Remove Member Confirm Modal ── -->
<div class="modal fade" id="removeMemberModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered" style="max-width:420px;">
        <div class="modal-content" style="border-radius:14px;overflow:hidden;">
            <div class="modal-header" style="background:linear-gradient(90deg,#c62828,#e53935);border:none;padding:14px 20px;">
                <h6 class="modal-title text-white fw-bold mb-0"><i class="bi bi-person-dash-fill me-2"></i>Remove Member</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 py-3">
                <p class="mb-1" style="font-size:0.9rem;">Remove <strong id="removeMemberName"></strong> from this household?</p>
                <p class="text-muted mb-0" style="font-size:0.78rem;">
                    <i class="bi bi-info-circle me-1"></i>
                    This only removes them from the household — their resident record stays intact.
                </p>
            </div>
            <div class="modal-footer border-0 pt-0 gap-2">
                <button type="button" class="btn btn-outline-secondary flex-fill" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger flex-fill fw-semibold" onclick="submitRemoveMember()">
                    <i class="bi bi-person-dash me-1"></i>Yes, Remove
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Hidden remove form -->
<form method="POST" id="removeMemberForm" style="display:none;">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="remove_member">
    <input type="hidden" name="member_id" id="removeMemberId">
</form>

<!-- Hidden add member form (outside householdForm to avoid nested form issue) -->
<form method="POST" id="addMemberForm" style="display:none;">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="add_member">
    <input type="hidden" name="resident_id" id="addResidentId">
    <input type="hidden" name="relationship" id="addRelVal">
</form>
</body>
</html>
