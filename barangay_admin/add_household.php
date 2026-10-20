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
    "ALTER TABLE households ADD COLUMN status VARCHAR(20) DEFAULT 'Active'",
    "ALTER TABLE households ADD COLUMN archive_reason VARCHAR(255) NULL",
] as $sql) { try { $db->exec($sql); } catch (PDOException $e) {} }

// Fetch barangay info for address auto-fill
$brgyInfoStmt = $db->prepare("SELECT id, barangay_name, municipality, region, province, zip_code FROM barangays WHERE id=?");
$brgyInfoStmt->execute([$bid]);
$hhBrgyInfo = $brgyInfoStmt->fetch() ?: [];

// Active residents for head-of-family dropdown
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

// Pre-fill from a ?prefill=residentId redirect
$prefillResident = null;
$prefillId = (int)($_GET['prefill'] ?? 0);
if ($prefillId) {
    $pStmt = $db->prepare(
        "SELECT r.id, r.first_name, r.last_name, r.contact_number, a.purok, a.street, a.zone
         FROM residents r
         LEFT JOIN addresses a ON r.address_id = a.id
         WHERE r.id = ? AND r.barangay_id = ? AND r.resident_status = 'Active'"
    );
    $pStmt->execute([$prefillId, $bid]);
    $prefillResident = $pStmt->fetch() ?: null;
}

// POST handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrAbort();

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
        redirect(BASE_URL . '/barangay_admin/add_household.php');
    }
    // Duplicate contact check
    if ($contact) {
        $dupQ = $db->prepare("SELECT id FROM households WHERE contact_number=? AND barangay_id=? AND status!='Inactive'");
        $dupQ->execute([$contact, $bid]);
        if ($dupQ->fetch()) {
            flash('hh', "Contact number <strong>$contact</strong> is already in use by another household.", 'danger');
            redirect(BASE_URL . '/barangay_admin/add_household.php');
        }
    }

    // Address
    $region   = sanitize($_POST['region']        ?? $hhBrgyInfo['region']       ?? '');
    $province = sanitize($_POST['province']      ?? $hhBrgyInfo['province']     ?? '');
    $city     = sanitize($_POST['city']          ?? $hhBrgyInfo['municipality'] ?? '');
    $brgyAddr = sanitize($_POST['barangay_addr'] ?? $hhBrgyInfo['barangay_name']?? '');
    $zipCode  = sanitize($_POST['zip_code']      ?? $hhBrgyInfo['zip_code']     ?? '3114');
    $purok    = sanitize($_POST['purok'] ?? '');

    $addrStmt = $db->prepare("INSERT INTO addresses (region,province,city,barangay,street,purok,zone,zip_code) VALUES (?,?,?,?,?,?,?,?)");
    $addrStmt->execute([$region, $province, $city, $brgyAddr, '', $purok, '', $zipCode]);
    $addrId = $db->lastInsertId();

    $code = generateHouseholdCode($bid);
    $db->prepare(
        "INSERT INTO households (barangay_id,household_code,head_resident_id,contact_number,address_id,length_of_stay,total_members,total_senior,total_pwd,income_range,status)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)"
    )->execute([$bid, $code, $headId, $contact, $addrId, $los, $members, $senior, $pwd, $income, 'Active']);
    $newHouseholdId = (int)$db->lastInsertId();

    // Auto-register head as a household member
    if ($headId) {
        $dupChk = $db->prepare("SELECT id FROM household_members WHERE household_id=? AND resident_id=?");
        $dupChk->execute([$newHouseholdId, $headId]);
        if (!$dupChk->fetch()) {
            $db->prepare("INSERT INTO household_members (household_id, resident_id, relationship) VALUES (?,?,?)")
               ->execute([$newHouseholdId, $headId, 'Head']);
        }
    }

    logActivity($u['id'], 'ADD_HOUSEHOLD', 'Households', "Added: $code");
    flash('hh', "Household <strong>$code</strong> added successfully.", 'success');

    // ── Real-time notification ──
    $brgyNameHH = $hhBrgyInfo['barangay_name'] ?? 'Barangay';
    $notifMsg = "🏠 New household added: <strong>$code</strong> — ID: <strong>#$newHouseholdId</strong> (<em>$brgyNameHH</em>)";
    sendNotification($u['id'], $notifMsg, 'success', 'system');
    notifyBarangayAdmins($bid, $notifMsg, 'success', 'system', $u['id']);

    // ── Email confirmation to the admin who added the household ──────────
    if ($u['email']) {
        $brgyNameHH = $db->query("SELECT barangay_name FROM barangays WHERE id=$bid")->fetchColumn() ?: '';
        sendTransactionLogEmail(
            $u['email'], $u['name'],
            'ADD_HOUSEHOLD', 'Households',
            "Added household $code — Barangay: $brgyNameHH",
            EMAIL_BASE_URL . '/barangay_admin/households.php'
        );
    }

    redirect(BASE_URL . '/barangay_admin/households.php');
}

$flash     = getFlash('hh');
$pageIcon     = 'bi-house-add-fill';
$pageTitle    = 'Add Household';
$pageSubtitle = 'Register a new household record.';
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
        /* Match Select2 to Bootstrap's form-select look */
        .select2-container--default .select2-selection--single {
            height: calc(1.5em + 0.75rem + 2px);
            border: 1px solid #d0dce8;
            border-radius: 7px;
            padding: 0.375rem 0.75rem;
            font-size: 0.84rem;
            line-height: 1.5;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 1.5;
            color: #212529;
            padding: 0;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 100%;
            right: 8px;
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
        .select2-container--default .select2-search--dropdown .select2-search__field {
            border-radius: 5px;
            border-color: #d0dce8;
            font-size: 0.84rem;
        }
        .select2-container { width: 100% !important; }
    </style>
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

        /* Section cards — same style as add_resident.php */
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

        /* Sticky footer */
        .hh-footer {
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
                <i class="bi bi-house-add-fill text-white" style="font-size:1.2rem;"></i>
            </div>
            <div>
                <div style="font-size:0.72rem;color:rgba(255,255,255,0.7);text-transform:uppercase;letter-spacing:0.08em;">Household Management</div>
                <div style="font-size:1.1rem;font-weight:800;color:#fff;">Add New Household</div>
                <div style="font-size:0.78rem;color:rgba(255,255,255,0.75);margin-top:2px;">Fill in the details below to register a new household record.</div>
            </div>
        </div>
    </div>

    <form method="POST" id="householdForm">
        <?= csrfField() ?>
        <!-- Hidden address fields submitted to server -->
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
                        <select name="head_resident_id" id="headResidentId" class="form-select">
                            <option value="">— Select Resident (optional) —</option>
                            <?php foreach ($residents as $r): ?>
                                <option value="<?= $r['id'] ?>"
                                    data-contact="<?= htmlspecialchars($r['contact_number'] ?? '') ?>"
                                    data-purok="<?= htmlspecialchars($r['purok'] ?? '') ?>"
                                    data-street="<?= htmlspecialchars($r['street'] ?? '') ?>"
                                    data-zone="<?= htmlspecialchars($r['zone'] ?? '') ?>">
                                    <?= htmlspecialchars($r['full_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="text-muted mt-1" style="font-size:0.72rem;">
                            <i class="bi bi-info-circle me-1"></i>Selecting a resident auto-fills their contact and address details.
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label class="field-label" for="fContact">Contact Number</label>
                        <input type="text" name="contact_number" id="fContact" class="form-control"
                               inputmode="numeric" pattern="09[0-9]{9}" placeholder="e.g. 09171234567"
                               oninput="this.value=this.value.replace(/[^0-9]/g,'');validateContact(this,'fContactErr')"
                               onkeydown="if(!/[0-9]|Backspace|Delete|ArrowLeft|ArrowRight|Tab|Home|End/.test(event.key))event.preventDefault()">
                        <div id="fContactErr" class="text-danger mt-1" style="display:none;font-size:0.72rem;"></div>
                    </div>

                    <div class="col-md-4">
                        <label class="field-label" for="fIncome">Income Range</label>
                        <select name="income_range" id="fIncome" class="form-select">
                            <option value="">— Select —</option>
                            <option>Below ₱10,000</option>
                            <option>₱10,000 - ₱20,000</option>
                            <option>₱20,001 - ₱40,000</option>
                            <option>₱40,001 - ₱60,000</option>
                            <option>Above ₱60,000</option>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="field-label" for="fLos">Length of Stay</label>
                        <input type="text" name="length_of_stay" id="fLos" class="form-control"
                               placeholder="e.g. 5 years">
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
                               min="0" value="1" required
                               oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                    </div>
                    <div class="col-md-4">
                        <label class="field-label" for="fSenior">Senior Citizens</label>
                        <input type="number" name="total_senior" id="fSenior" class="form-control"
                               min="0" value="0"
                               oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                    </div>
                    <div class="col-md-4">
                        <label class="field-label" for="fPwd">PWD Members</label>
                        <input type="number" name="total_pwd" id="fPwd" class="form-control"
                               min="0" value="0"
                               oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                    </div>
                </div>
                <div class="alert alert-info py-2 mt-3 mb-0" style="font-size:0.75rem;">
                    <i class="bi bi-info-circle me-1"></i>
                    These counts can be updated anytime from the Manage Members panel on the households list.
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
                        <label class="field-label" for="fRegionDisplay">Region</label>
                        <input type="text" id="fRegionDisplay" class="form-control" readonly tabindex="-1">
                    </div>
                    <div class="col-md-4">
                        <label class="field-label" for="fProvinceDisplay">Province</label>
                        <input type="text" id="fProvinceDisplay" class="form-control" readonly tabindex="-1">
                    </div>
                    <div class="col-md-3">
                        <label class="field-label" for="fZipDisplay">Zip Code</label>
                        <input type="text" id="fZipDisplay" class="form-control" readonly tabindex="-1">
                    </div>
                    <div class="col-md-4">
                        <label class="field-label" for="fCityDisplay">City / Municipality</label>
                        <input type="text" id="fCityDisplay" class="form-control" readonly tabindex="-1">
                    </div>
                    <div class="col-md-4">
                        <label class="field-label" for="fBrgyDisplay">Barangay</label>
                        <input type="text" id="fBrgyDisplay" class="form-control" readonly tabindex="-1">
                    </div>
                    <div class="col-md-4">
                        <label class="field-label" for="fPurok">Purok / Street / Zone</label>
                        <input type="text" name="purok" id="fPurok" class="form-control"
                               placeholder="e.g. Purok 3, Rizal St., Zone 2">
                        <div class="text-muted mt-1" id="fPurokHint" style="display:none;font-size:0.72rem;">
                            <i class="bi bi-magic me-1 text-success"></i>Auto-filled from resident — you can edit this.
                        </div>
                    </div>
                </div>
                <div class="alert alert-info py-2 mt-3 mb-0" style="font-size:0.75rem;">
                    <i class="bi bi-info-circle me-1"></i>
                    Region, Province, City, and Barangay are auto-filled from your barangay profile. Only Purok / Street / Zone is editable.
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
                <button type="submit" id="submitBtn" class="btn btn-primary fw-semibold px-5"
                        onclick="return validateBeforeSubmit()">
                    <i class="bi bi-check-lg me-1"></i>Save Household
                </button>
            </div>
        </div>

    </form>

</div><!-- /.main-content -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('show'); }

// ── Barangay address constants ──────────────────────────────────────────
const brgyRegion   = <?= json_encode($hhBrgyInfo['region']       ?? '') ?>;
const brgyProvince = <?= json_encode($hhBrgyInfo['province']     ?? '') ?>;
const brgyCity     = <?= json_encode($hhBrgyInfo['municipality'] ?? '') ?>;
const brgyName     = <?= json_encode($hhBrgyInfo['barangay_name']?? '') ?>;
const brgyZip      = <?= json_encode($hhBrgyInfo['zip_code'] ?: '3114') ?>;

function fillBrgyAddress() {
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
}

// ── Auto-fill from selected resident ───────────────────────────────────
function autofillFromResident(select) {
    const opt = select.options[select.selectedIndex];
    if (!opt || !opt.value) return;

    const contact = opt.dataset.contact || '';
    const purok   = opt.dataset.purok   || '';
    const street  = opt.dataset.street  || '';
    const zone    = opt.dataset.zone    || '';

    const contactField = document.getElementById('fContact');
    if (!contactField.value && contact) {
        contactField.value = contact;
        validateContact(contactField, 'fContactErr');
    }

    const parts    = [purok, street, zone].filter(v => v.trim() !== '');
    const combined = parts.join(', ');
    const purokField = document.getElementById('fPurok');
    const hint       = document.getElementById('fPurokHint');

    if (combined) {
        purokField.value = combined;
        hint.style.display = 'block';
    } else {
        hint.style.display = 'none';
    }
}

// ── Form validation before submit ──────────────────────────────────────
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

// ── Init on page load ──────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    fillBrgyAddress();

    // ── Init Select2 on Head of Family dropdown ────────────────────────
    $('#headResidentId').select2({
        placeholder: '— Select Resident (optional) —',
        allowClear: true,
        width: '100%'
    });

    // Trigger autofill when Select2 value changes
    $('#headResidentId').on('change', function () {
        autofillFromResident(document.getElementById('headResidentId'));
    });

<?php if ($prefillResident): ?>
    // Pre-fill from resident redirect
    const prefill = <?= json_encode($prefillResident) ?>;
    const headSel = document.getElementById('headResidentId');
    for (let i = 0; i < headSel.options.length; i++) {
        if (headSel.options[i].value == prefill.id) {
            headSel.selectedIndex = i;
            break;
        }
    }
    // Trigger Select2 update and autofill
    $('#headResidentId').trigger('change.select2');
    autofillFromResident(headSel);
<?php endif; ?>
});
</script>
</body>
</html>
