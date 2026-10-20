<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/mailer.php';
requireRole('barangay_admin');

$db  = Database::getInstance()->getConnection();
$u   = currentUser();
$bid = (int)$u['barangay_id'];

$id = (int)($_GET['id'] ?? 0);
if (!$id) { redirect(BASE_URL . '/barangay_admin/residents.php'); }

// ── FAST ACCESS CHECK — must be first, before any slow query ──────────────
// Deny immediately if the resident doesn't belong to this barangay.
// Uses the PK + barangay_id index — single-row lookup, < 1 ms.
$_ownCheck = $db->prepare("SELECT id FROM residents WHERE id = ? AND barangay_id = ? LIMIT 1");
$_ownCheck->execute([$id, $bid]);
if (!$_ownCheck->fetch()) { redirect(BASE_URL . '/barangay_admin/residents.php'); }

// ── Safe migrations ────────────────────────────────────────
// Add 'Pending Transfer' to resident_status ENUM
try { $db->exec("ALTER TABLE residents MODIFY COLUMN resident_status ENUM('Active','Inactive','Deceased','Transferred','Pending Transfer') DEFAULT 'Active'"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE residents ADD COLUMN IF NOT EXISTS email VARCHAR(150) NULL"); } catch (PDOException $e) {}

// Bootstrap resident_transfer_requests table
$db->exec("CREATE TABLE IF NOT EXISTS resident_transfer_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    requester_user_id INT UNSIGNED NOT NULL,
    origin_barangay_id INT UNSIGNED NOT NULL,
    resident_id INT UNSIGNED NOT NULL,
    resident_name VARCHAR(150) NOT NULL,
    destination_barangay_id INT UNSIGNED NOT NULL,
    reason ENUM('Employment','Residence Change','Family','Other') NOT NULL DEFAULT 'Other',
    notes TEXT NULL,
    status ENUM('pending','approved','rejected','verified') NOT NULL DEFAULT 'pending',
    reviewed_by INT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    admin_notes TEXT NULL,
    verified_by INT UNSIGNED NULL,
    verified_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (requester_user_id)       REFERENCES users(id)     ON DELETE CASCADE,
    FOREIGN KEY (origin_barangay_id)      REFERENCES barangays(id) ON DELETE CASCADE,
    FOREIGN KEY (resident_id)             REFERENCES residents(id)  ON DELETE CASCADE,
    FOREIGN KEY (destination_barangay_id) REFERENCES barangays(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by)             REFERENCES users(id)     ON DELETE SET NULL,
    FOREIGN KEY (verified_by)             REFERENCES users(id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
try { $db->exec("ALTER TABLE resident_transfer_requests ADD COLUMN IF NOT EXISTS notes TEXT NULL"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE resident_transfer_requests MODIFY COLUMN status ENUM('pending','approved','rejected','verified') NOT NULL DEFAULT 'pending'"); } catch (PDOException $e) {}

// Fetch barangay info for address auto-fill
$brgyInfoStmt = $db->prepare("SELECT id, barangay_name, municipality, region, province, zip_code FROM barangays WHERE id=?");
$brgyInfoStmt->execute([$bid]);
$brgyInfo = $brgyInfoStmt->fetch() ?: [];

// Fetch only barangays within the SAME municipality (Within Talavera dropdown)
// Excludes the admin's own barangay and only includes same-municipality barangays
$currentMunicipality = $brgyInfo['municipality'] ?? '';
$allBarangaysStmt = $db->prepare("SELECT id, barangay_name, municipality FROM barangays WHERE status='active' AND municipality=? ORDER BY barangay_name");
$allBarangaysStmt->execute([$currentMunicipality]);
$allBarangays = $allBarangaysStmt->fetchAll();

$photoDir = __DIR__ . '/../uploads/residents/';

// Active households for household assignment dropdown
$activeHouseholdsStmt = $db->prepare(
    "SELECT h.id, h.household_code, CONCAT(r.last_name, ', ', r.first_name) AS head_name
     FROM households h LEFT JOIN residents r ON h.head_resident_id = r.id
     WHERE h.barangay_id = ? AND h.status = 'Active' ORDER BY h.household_code ASC"
);
$activeHouseholdsStmt->execute([$bid]);
$activeHouseholds = $activeHouseholdsStmt->fetchAll();

// Load full resident data (ownership already confirmed above)
$stmt = $db->prepare("SELECT r.*, a.region, a.province, a.city, a.barangay as addr_barangay, a.street, a.purok, a.zone, a.zip_code FROM residents r LEFT JOIN addresses a ON r.address_id = a.id WHERE r.id=? AND r.barangay_id=?");
$stmt->execute([$id, $bid]);
$res = $stmt->fetch();
if (!$res) { redirect(BASE_URL . '/barangay_admin/residents.php'); }

// Load current household membership for this resident
$curHhStmt = $db->prepare(
    "SELECT hm.household_id, hm.relationship, h.household_code,
            h.head_resident_id, CONCAT(hr.last_name, ', ', hr.first_name) AS head_name
     FROM household_members hm
     JOIN households h ON hm.household_id = h.id
     LEFT JOIN residents hr ON h.head_resident_id = hr.id
     WHERE hm.resident_id = ? AND h.barangay_id = ? AND h.status = 'Active'
     LIMIT 1"
);
$curHhStmt->execute([$id, $bid]);
$curHh = $curHhStmt->fetch(); // null if not in any household

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
        flash('res', 'Contact number must be 11 digits starting with 09.', 'danger');
        redirect(BASE_URL . '/barangay_admin/edit_resident.php?id=' . $id);
    }
    if ($contact) {
        $dupQ = $db->prepare("SELECT id FROM residents WHERE contact_number=? AND barangay_id=? AND id!=? AND resident_status NOT IN ('Inactive','Deceased','Transferred')");
        $dupQ->execute([$contact, $bid, $id]);
        if ($dupQ->fetch()) {
            flash('res', "Contact number <strong>$contact</strong> is already in use by another resident.", 'danger');
            redirect(BASE_URL . '/barangay_admin/edit_resident.php?id=' . $id);
        }
    }

    $voter   = $_POST['voter_status'];
    $is4ps   = isset($_POST['is_4ps']) ? 1 : 0;
    $isPwd   = isset($_POST['is_pwd']) ? 1 : 0;
    $isSenior= $age >= 60 ? 1 : (isset($_POST['is_senior']) ? 1 : 0);
    $isSolo  = isset($_POST['is_solo_parent']) ? 1 : 0;
    $status  = $_POST['resident_status'] ?? 'Active';
    $occup   = $_POST['occupation_status'];

    $transferFrom    = sanitize($_POST['transfer_from'] ?? '');
    $rawTransferTo   = $_POST['transfer_to'] ?? '';
    // __outside__ sentinel means user chose "Outside Talavera"
    if ($rawTransferTo === '__outside__') {
        $transferOutside = 1;
        $transferTo      = '';   // no specific barangay — just outside
    } else {
        $transferOutside = 0;
        $transferTo      = sanitize($rawTransferTo);
    }
    $transferReason  = '';
    $archiveReason   = sanitize($_POST['archive_reason'] ?? '');

    if ($status === 'Transferred' && !$transferOutside && !$transferTo) {
        flash('res', 'Please select the destination barangay.', 'danger');
        redirect(BASE_URL . '/barangay_admin/edit_resident.php?id=' . $id);
    }
    if ($status === 'Deceased' && !$archiveReason) {
        flash('res', 'Please provide a reason/confirmation for deceased status.', 'danger');
        redirect(BASE_URL . '/barangay_admin/edit_resident.php?id=' . $id);
    }

    // Photo
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
        $allowed = ['image/jpeg','image/png','image/webp','image/gif'];
        $finfo   = finfo_open(FILEINFO_MIME_TYPE);
        $mime    = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if (!in_array($mime, $allowed)) {
            flash('res', 'Invalid image type.', 'danger');
            redirect(BASE_URL . '/barangay_admin/edit_resident.php?id=' . $id);
        }
        $ext      = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'jpg';
        $filename = 'resident_' . time() . '_' . random_int(1000, 9999) . '.' . strtolower($ext);
        move_uploaded_file($file['tmp_name'], $photoDir . $filename);
        $newPhotoFile = $filename;
    }

    // Address
    $addrId   = (int)$_POST['address_id'];
    $region   = sanitize($_POST['region']        ?? $brgyInfo['region']       ?? '');
    $province = sanitize($_POST['province']      ?? $brgyInfo['province']     ?? '');
    $city     = sanitize($_POST['city']          ?? $brgyInfo['municipality'] ?? '');
    $brgyAddr = sanitize($_POST['barangay_addr'] ?? $brgyInfo['barangay_name']?? '');
    $zipCode  = sanitize($_POST['zip_code']      ?? $brgyInfo['zip_code']     ?? '3114');
    $purok    = sanitize($_POST['purok']  ?? '');

    $db->prepare("UPDATE addresses SET region=?,province=?,city=?,barangay=?,street=?,purok=?,zone=?,zip_code=? WHERE id=?")
       ->execute([$region,$province,$city,$brgyAddr,'', $purok,'', $zipCode, $addrId]);

    // ── For within-Talavera transfer: override status to Pending Transfer before UPDATE ──
    $saveStatus = $status;
    if ($status === 'Transferred' && !$transferOutside && $transferTo) {
        $saveStatus = 'Pending Transfer';
    }

    if ($newPhotoFile) {
        if (!empty($res['photo']) && file_exists($photoDir . $res['photo'])) {
            unlink($photoDir . $res['photo']);
        }
        $db->prepare("UPDATE residents SET first_name=?,middle_name=?,last_name=?,suffix=?,birthdate=?,age=?,civil_status=?,gender=?,contact_number=?,email=?,voter_status=?,is_4ps=?,is_pwd=?,is_senior=?,is_solo_parent=?,resident_status=?,occupation_status=?,transfer_from_barangay=?,transfer_to_barangay=?,transfer_outside=?,transfer_reason=?,archive_reason=?,photo=? WHERE id=? AND barangay_id=?")
           ->execute([$fname,$mname,$lname,$suffix,$bdate,$age,$civil,$gender,$contact,$email ?: null,$voter,$is4ps,$isPwd,$isSenior,$isSolo,$saveStatus,$occup,$transferFrom,$transferTo,$transferOutside,$transferReason,$archiveReason,$newPhotoFile,$id,$bid]);
    } else {
        $db->prepare("UPDATE residents SET first_name=?,middle_name=?,last_name=?,suffix=?,birthdate=?,age=?,civil_status=?,gender=?,contact_number=?,email=?,voter_status=?,is_4ps=?,is_pwd=?,is_senior=?,is_solo_parent=?,resident_status=?,occupation_status=?,transfer_from_barangay=?,transfer_to_barangay=?,transfer_outside=?,transfer_reason=?,archive_reason=? WHERE id=? AND barangay_id=?")
           ->execute([$fname,$mname,$lname,$suffix,$bdate,$age,$civil,$gender,$contact,$email ?: null,$voter,$is4ps,$isPwd,$isSenior,$isSolo,$saveStatus,$occup,$transferFrom,$transferTo,$transferOutside,$transferReason,$archiveReason,$id,$bid]);
    }

    if ($status === 'Deceased') {
        $archData = array_merge($res, ['archive_reason' => $archiveReason]);
        $alreadyArch = $db->prepare("SELECT id FROM archives WHERE table_name='residents' AND record_id=?");
        $alreadyArch->execute([$id]);
        if (!$alreadyArch->fetch()) {
            $db->prepare("INSERT INTO archives (table_name,record_id,data_json,archived_by) VALUES ('residents',?,?,?)")
               ->execute([$id, json_encode($archData), $u['id']]);
        }
        logActivity($u['id'], 'DECEASED_RESIDENT', 'Residents', "Marked $fname $lname as deceased");
        flash('res', 'Resident marked as deceased and moved to archive.', 'warning');

        // Notify own barangay
        $notifMsg = "Resident <strong>$fname $lname</strong> marked as deceased.";
        sendNotification($u['id'], $notifMsg, 'warning', 'resident');
        notifyBarangayAdmins($bid, $notifMsg, 'warning', 'resident', $u['id']);

    } elseif ($status === 'Transferred') {

        $munic      = $brgyInfo['municipality'] ?? 'Talavera';
        $originName = $brgyInfo['barangay_name'] ?? '';
        $resFullName = trim("$fname $mname $lname");

        if ($transferOutside) {
            // ── Outside Talavera: no approval needed, record immediately ──
            logActivity($u['id'], 'TRANSFER_RESIDENT', 'Residents',
                "Transferred $fname $lname — Outside Municipality of $munic");
            flash('res', "Resident <strong>$resFullName</strong> recorded as transferred outside Talavera.", 'info');

            $notifMsg = "Resident <strong>$resFullName</strong> transferred outside Talavera (no barangay notification required).";
            sendNotification($u['id'], $notifMsg, 'info', 'resident');
            notifyBarangayAdmins($bid, $notifMsg, 'info', 'resident', $u['id']);

        } else {
            // ── Within Talavera: look up destination barangay ID ──────────
            $destBrgyStmt = $db->prepare("SELECT id FROM barangays WHERE barangay_name=? AND municipality=? AND status='active' LIMIT 1");
            $destBrgyStmt->execute([$transferTo, $munic]);
            $destBrgyId = (int)($destBrgyStmt->fetchColumn() ?: 0);

            if ($destBrgyId && $destBrgyId !== $bid) {
                // ── Check for existing pending/approved transfer ──────────
                $dupCheck = $db->prepare("SELECT id FROM resident_transfer_requests WHERE resident_id=? AND status IN ('pending','approved')");
                $dupCheck->execute([$id]);
                $existingTr = $dupCheck->fetchColumn();

                if (!$existingTr) {
                    // Main UPDATE already set resident_status to 'Pending Transfer' above.
                    // Insert transfer request record
                    $db->prepare("INSERT INTO resident_transfer_requests
                        (requester_user_id, origin_barangay_id, resident_id, resident_name, destination_barangay_id, reason, notes)
                        VALUES (?,?,?,?,?,?,?)")
                       ->execute([$u['id'], $bid, $id, $resFullName, $destBrgyId, 'Other', '']);

                    $trId = (int)$db->lastInsertId();

                    logActivity($u['id'], 'SUBMIT_TRANSFER_REQUEST', 'Resident Transfer',
                        "Transfer request #{$trId}: $resFullName → $transferTo (Pending approval by destination)");
                    flash('res',
                        "Transfer request for <strong>$resFullName</strong> sent to <strong>$transferTo</strong>. " .
                        "The resident is marked <strong>Pending Transfer</strong> until the destination barangay approves.",
                        'info');

                    // Notify origin barangay (acting admin + peers)
                    $originMsg = "🔄 Transfer request submitted for <strong>$resFullName</strong> → <strong>$transferTo</strong>. Awaiting destination barangay approval.";
                    sendNotification($u['id'], $originMsg, 'info', 'transaction');
                    notifyBarangayAdmins($bid, $originMsg, 'info', 'transaction', $u['id']);

                    // Notify destination barangay admins
                    $destMsg = "📋 Incoming Transfer Request: <strong>$resFullName</strong> from <strong>$originName</strong> is requesting transfer to your barangay. Please review and approve or reject in the <strong>Residents → Transferred</strong> tab.";
                    notifyBarangayAdmins($destBrgyId, $destMsg, 'info', 'transaction');

                } else {
                    // Already has pending transfer — just update other fields, keep status
                    flash('res', 'Resident info updated. An existing transfer request is already pending.', 'warning');
                }

            } else {
                // Destination barangay not found in DB — fall back to simple record
                logActivity($u['id'], 'TRANSFER_RESIDENT', 'Residents', "Transferred $fname $lname to $transferTo");
                flash('res', 'Resident transfer recorded (destination barangay not found in system — no notification sent).', 'warning');
            }
        }

    } else {
        logActivity($u['id'], 'EDIT_RESIDENT', 'Residents', "Updated resident: $fname $lname");
        flash('res', 'Resident updated successfully.', 'success');

        // Notify own barangay
        $notifMsg = "Resident updated: <strong>$fname $lname</strong>";
        sendNotification($u['id'], $notifMsg, 'info', 'resident');
        notifyBarangayAdmins($bid, $notifMsg, 'info', 'resident', $u['id']);
    }

    // ── Household Assignment Update ────────────────────────────────────────
    $hhRole = $_POST['household_role'] ?? '';

    // Determine if the resident is currently head of a household
    $isCurrentHead = false;
    if ($curHh) {
        $headCheck = $db->prepare("SELECT head_resident_id FROM households WHERE id=?");
        $headCheck->execute([$curHh['household_id']]);
        $headResId = (int)($headCheck->fetchColumn() ?: 0);
        $isCurrentHead = ($headResId === $id);
    }

    if ($hhRole === 'head' && !$curHh) {
        // Create a new household with this resident as head
        $hhLos     = sanitize($_POST['hh_length_of_stay'] ?? '');
        $hhIncome  = sanitize($_POST['hh_income_range'] ?? '');
        $hhMembers = max(1, (int)($_POST['hh_total_members'] ?? 1));
        $hhSenior  = (int)($_POST['hh_total_senior'] ?? ($isSenior ? 1 : 0));
        $hhPwd     = (int)($_POST['hh_total_pwd']    ?? ($isPwd    ? 1 : 0));
        $hhCode    = generateHouseholdCode($bid);
        $db->prepare(
            "INSERT INTO households (barangay_id,household_code,head_resident_id,contact_number,address_id,length_of_stay,total_members,total_senior,total_pwd,income_range,status)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)"
        )->execute([$bid, $hhCode, $id, $res['contact_number'] ?? '', $res['address_id'], $hhLos, $hhMembers, $hhSenior, $hhPwd, $hhIncome, 'Active']);
        $newHhId2 = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO household_members (household_id,resident_id,relationship) VALUES (?,?,?)")->execute([$newHhId2, $id, 'Head']);
    } elseif ($hhRole === 'none') {
        // Remove from household — but not if they are the head (protect household integrity)
        if ($curHh && !$isCurrentHead) {
            $db->prepare("DELETE FROM household_members WHERE household_id=? AND resident_id=?")->execute([$curHh['household_id'], $id]);
            $db->prepare("UPDATE households SET total_members = GREATEST(total_members - 1, 0) WHERE id=?")->execute([$curHh['household_id']]);
            if ($res['is_senior']) $db->prepare("UPDATE households SET total_senior = GREATEST(total_senior - 1, 0) WHERE id=?")->execute([$curHh['household_id']]);
            if ($res['is_pwd'])    $db->prepare("UPDATE households SET total_pwd    = GREATEST(total_pwd    - 1, 0) WHERE id=?")->execute([$curHh['household_id']]);
        }
    } elseif ($hhRole === 'member') {
        $newHhId       = (int)($_POST['hh_member_household_id'] ?? 0);
        $newRelationship = sanitize($_POST['hh_member_relationship'] ?? 'Member');

        if ($newHhId) {
            // Verify household belongs to this barangay
            $hhVerify = $db->prepare("SELECT id FROM households WHERE id=? AND barangay_id=? AND status='Active'");
            $hhVerify->execute([$newHhId, $bid]);
            if ($hhVerify->fetch()) {
                $currentHhId = $curHh ? (int)$curHh['household_id'] : 0;

                // If changing households: remove from old one (unless head)
                if ($currentHhId && $currentHhId !== $newHhId && !$isCurrentHead) {
                    $db->prepare("DELETE FROM household_members WHERE household_id=? AND resident_id=?")->execute([$currentHhId, $id]);
                    $db->prepare("UPDATE households SET total_members = GREATEST(total_members - 1, 0) WHERE id=?")->execute([$currentHhId]);
                    if ($res['is_senior']) $db->prepare("UPDATE households SET total_senior = GREATEST(total_senior - 1, 0) WHERE id=?")->execute([$currentHhId]);
                    if ($res['is_pwd'])    $db->prepare("UPDATE households SET total_pwd    = GREATEST(total_pwd    - 1, 0) WHERE id=?")->execute([$currentHhId]);
                }

                // Add to new household (or update relationship if already a member)
                $dupCheck = $db->prepare("SELECT id FROM household_members WHERE household_id=? AND resident_id=?");
                $dupCheck->execute([$newHhId, $id]);
                if ($dupCheck->fetch()) {
                    // Already in this household — just update relationship
                    $db->prepare("UPDATE household_members SET relationship=? WHERE household_id=? AND resident_id=?")->execute([$newRelationship, $newHhId, $id]);
                } else {
                    $db->prepare("INSERT INTO household_members (household_id, resident_id, relationship) VALUES (?,?,?)")->execute([$newHhId, $id, $newRelationship]);
                    $db->prepare("UPDATE households SET total_members = total_members + 1 WHERE id=?")->execute([$newHhId]);
                    if ($isSenior) $db->prepare("UPDATE households SET total_senior = total_senior + 1 WHERE id=?")->execute([$newHhId]);
                    if ($isPwd)    $db->prepare("UPDATE households SET total_pwd    = total_pwd    + 1 WHERE id=?")->execute([$newHhId]);
                }
                // ── Sync resident address purok/street/zone from household ──
                $hhAddrStmt = $db->prepare(
                    "SELECT a.purok, a.street, a.zone FROM households h
                     JOIN addresses a ON h.address_id = a.id WHERE h.id = ?"
                );
                $hhAddrStmt->execute([$newHhId]);
                $hhAddr = $hhAddrStmt->fetch();
                if ($hhAddr) {
                    $db->prepare(
                        "UPDATE addresses SET purok=?, street=?, zone=?
                         WHERE id = (SELECT address_id FROM residents WHERE id=?)"
                    )->execute([$hhAddr['purok'], $hhAddr['street'], $hhAddr['zone'], $id]);
                }
            }
        }
    }
    // If $hhRole is '' (keep current) or resident is head, do nothing — preserve existing assignment.

    // ── Email confirmation to the acting admin ─────────────────────────
    if (!empty($u['email'])) {
        $brgyNameRes = $db->query("SELECT barangay_name FROM barangays WHERE id=$bid")->fetchColumn() ?: '';
        $actionLabel = ($status === 'Deceased')
                     ? 'DECEASED_RESIDENT'
                     : (($status === 'Transferred')
                        ? 'TRANSFER_RESIDENT'
                        : (($status === 'Pending Transfer') ? 'PENDING_TRANSFER' : 'EDIT_RESIDENT'));
        $actionDesc  = ($status === 'Deceased')
                     ? "Marked $fname $lname as deceased"
                     : (($status === 'Pending Transfer')
                        ? "Transfer request submitted: $fname $lname → $transferTo"
                        : (($status === 'Transferred')
                           ? "Transferred $fname $lname (outside municipality)"
                           : "Updated resident: $fname $lname"));
        sendTransactionLogEmail(
            $u['email'], $u['name'],
            $actionLabel, 'Residents',
            $actionDesc . " — Barangay: $brgyNameRes",
            EMAIL_BASE_URL . '/barangay_admin/residents.php'
        );
    }

    redirect(BASE_URL . '/barangay_admin/residents.php');
}

$flash     = getFlash('res');
$pageIcon     = 'bi-person-fill-gear';
$pageTitle    = 'Edit Resident';
$pageSubtitle = 'Update resident profile and information.';
$fullName  = trim($res['last_name'] . ', ' . $res['first_name'] . ($res['middle_name'] ? ' ' . $res['middle_name'] : ''));
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
        .select2-container--default .select2-selection--single .select2-selection__arrow { height: 100%; }
        .select2-container--default.select2-container--focus .select2-selection--single,
        .select2-container--default.select2-container--open  .select2-selection--single {
            border-color: #3a7fa8;
            box-shadow: 0 0 0 3px rgba(58,127,168,0.12);
            outline: none;
        }
        .select2-dropdown { border-color: #3a7fa8; border-radius: 7px; font-size: 0.84rem; }
        .select2-container--default .select2-results__option--highlighted[aria-selected] { background-color: #1a4f7a; }
        .select2-search--dropdown .select2-search__field { border-radius: 5px; border: 1px solid #d0dce8; padding: 6px 10px; font-size: 0.84rem; }
        .select2-container { width: 100% !important; }
        /* ── Household role cards ── */
        .hh-role-card { flex:1; border:2px solid #dee2e6; border-radius:9px; padding:12px 14px; cursor:pointer; transition:all .2s; background:#fff; }
        .hh-role-card.selected       { border-color:#1a4f7a; background:rgba(26,79,122,0.05); }
        .hh-role-card.selected-green { border-color:#198754; background:rgba(25,135,84,0.05); }
        .field-label { font-size:0.78rem; font-weight:600; color:#374151; margin-bottom:4px; display:block; }
        .field-label .req { color:#dc2626; margin-left:2px; }
        .form-control, .form-select { font-size:0.84rem; border-color:#d0dce8; border-radius:7px; }
        .form-control:focus, .form-select:focus { border-color:#3a7fa8; box-shadow:0 0 0 3px rgba(58,127,168,0.12); }
        .form-control[readonly] { background:#f4f7fa; color:#6b7280; }
        .res-section { background:#fff; border-radius:12px; border:1px solid #d0ecd6; overflow:hidden; margin-bottom:16px; }
        .res-section-head { background:rgba(26,79,122,0.05); border-bottom:1px solid rgba(26,79,122,0.1); padding:11px 18px; display:flex; align-items:center; gap:8px; }
        .res-section-head span { font-weight:700; font-size:0.8rem; color:#1a4f7a; text-transform:uppercase; letter-spacing:0.06em; }
        .res-section-body { padding:18px; }
        #photoDropZone { width:130px; height:170px; border-radius:8px; background:#f0f4f8; border:2px dashed #b0c4d8; display:flex; flex-direction:column; align-items:center; justify-content:center; cursor:pointer; overflow:hidden; transition:border-color .2s; position:relative; margin:0 auto; }
        #photoDropZone:hover { border-color:#3a7fa8; background:#e8f3fb; }
        #photoPreview { width:100%; height:100%; object-fit:cover; display:none; position:absolute; top:0; left:0; }
        .res-footer { position:sticky; bottom:0; background:#fff; border-top:1px solid #e5e7eb; padding:14px 0; margin-top:8px; z-index:10; }
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
        <a href="<?= BASE_URL ?>/barangay_admin/residents.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back to Residents
        </a>
    </div>

    <!-- Blue header -->
    <div style="background:linear-gradient(90deg,#1a4f7a,#2d7db3);border-radius:12px;padding:18px 24px;margin-bottom:18px;">
        <div class="d-flex align-items-center gap-3">
            <?php if (!empty($res['photo']) && file_exists($photoDir . $res['photo'])): ?>
                <img src="<?= BASE_URL ?>/uploads/residents/<?= htmlspecialchars($res['photo']) ?>"
                     style="width:52px;height:52px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,0.6);flex-shrink:0;" alt="">
            <?php else: ?>
                <div style="width:52px;height:52px;border-radius:50%;background:rgba(255,255,255,0.18);display:flex;align-items:center;justify-content:center;flex-shrink:0;border:2px solid rgba(255,255,255,0.4);">
                    <i class="bi bi-person-fill text-white" style="font-size:1.4rem;"></i>
                </div>
            <?php endif; ?>
            <div>
                <div style="font-size:0.72rem;color:rgba(255,255,255,0.7);text-transform:uppercase;letter-spacing:0.08em;">Editing Resident</div>
                <div style="font-size:1.1rem;font-weight:800;color:#fff;"><?= htmlspecialchars($fullName) ?></div>
                <div style="font-size:0.78rem;color:rgba(255,255,255,0.75);margin-top:2px;">Update the fields below and save changes.</div>
            </div>
        </div>
    </div>

    <form method="POST" id="residentForm" enctype="multipart/form-data">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="resident_id" value="<?= $id ?>">
        <input type="hidden" name="address_id" id="addressId" value="<?= $res['address_id'] ?>">
        <input type="hidden" name="photo_base64" id="photoBase64">
        <input type="hidden" name="region"        id="fRegion"   value="<?= htmlspecialchars($res['region']       ?? $brgyInfo['region']       ?? '') ?>">
        <input type="hidden" name="province"      id="fProvince" value="<?= htmlspecialchars($res['province']     ?? $brgyInfo['province']     ?? '') ?>">
        <input type="hidden" name="city"          id="fCity"     value="<?= htmlspecialchars($res['city']         ?? $brgyInfo['municipality'] ?? '') ?>">
        <input type="hidden" name="barangay_addr" id="fBrgyAddr" value="<?= htmlspecialchars($res['addr_barangay']?? $brgyInfo['barangay_name']?? '') ?>">
        <input type="hidden" name="zip_code"      id="fZipCode"  value="<?= htmlspecialchars($res['zip_code']     ?? $brgyInfo['zip_code']     ?? '3114') ?>">
        <input type="hidden" name="street"        id="fStreet"   value="">
        <input type="hidden" name="zone"          id="fZone"     value="">

        <div class="row g-4">

            <!-- LEFT: Photo + Categories -->
            <div class="col-lg-3">

                <!-- Photo -->
                <div class="res-section">
                    <div class="res-section-head">
                        <i class="bi bi-camera-fill" style="color:#1a4f7a;"></i>
                        <span>Resident Photo</span>
                    </div>
                    <div class="res-section-body text-center">
                        <div id="photoDropZone" onclick="document.getElementById('photoFileInput').click()" title="Click or drag photo here">
                            <img id="photoPreview" src="<?= !empty($res['photo']) ? BASE_URL.'/uploads/residents/'.htmlspecialchars($res['photo']) : '' ?>" alt=""
                                 style="<?= !empty($res['photo']) ? 'display:block;' : 'display:none;' ?>;width:100%;height:100%;object-fit:cover;position:absolute;top:0;left:0;">
                            <div class="photo-placeholder" id="photoPlaceholder" style="<?= !empty($res['photo']) ? 'display:none;' : '' ?>;text-align:center;color:#9ca3af;font-size:0.7rem;padding:8px;">
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
                        <button type="button" id="clearPhotoBtn" class="btn btn-sm btn-outline-danger mt-1 w-100"
                                style="font-size:0.7rem;<?= empty($res['photo']) ? 'display:none;' : '' ?>" onclick="clearPhoto()">
                            <i class="bi bi-trash me-1"></i>Remove Photo
                        </button>
                        <div class="text-muted mt-2" style="font-size:0.68rem;">JPG, PNG or WebP · max 5MB</div>
                    </div>
                </div>

                <!-- Special Categories -->
                <div class="res-section">
                    <div class="res-section-head">
                        <i class="bi bi-tags-fill" style="color:#1a4f7a;"></i>
                        <span>Special Categories</span>
                    </div>
                    <div class="res-section-body">
                        <div class="d-flex flex-column gap-2">
                            <div class="form-check">
                                <input type="checkbox" name="is_4ps" id="f4ps" class="form-check-input" <?= $res['is_4ps'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="f4ps" style="font-size:0.83rem;">4Ps Beneficiary</label>
                            </div>
                            <div class="form-check">
                                <input type="checkbox" name="is_pwd" id="fPwd" class="form-check-input" <?= $res['is_pwd'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="fPwd" style="font-size:0.83rem;">PWD</label>
                            </div>
                            <div class="form-check">
                                <input type="checkbox" name="is_senior" id="fSenior" class="form-check-input" <?= $res['is_senior'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="fSenior" style="font-size:0.83rem;">Senior Citizen</label>
                            </div>
                            <div class="form-check">
                                <input type="checkbox" name="is_solo_parent" id="fSolo" class="form-check-input" <?= $res['is_solo_parent'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="fSolo" style="font-size:0.83rem;">Solo Parent</label>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- RIGHT: Form Sections -->
            <div class="col-lg-9">

                <!-- Section 1: Personal Info -->
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
                                <input type="text" name="first_name" class="form-control" required value="<?= htmlspecialchars($res['first_name']) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="field-label">Middle Name</label>
                                <input type="text" name="middle_name" class="form-control" value="<?= htmlspecialchars($res['middle_name'] ?? '') ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="field-label">Last Name <span class="req">*</span></label>
                                <input type="text" name="last_name" class="form-control" required value="<?= htmlspecialchars($res['last_name']) ?>">
                            </div>
                            <!-- Row 2: Suffix / Birthdate / Age -->
                            <div class="col-md-4">
                                <label class="field-label">Suffix</label>
                                <select name="suffix" class="form-select">
                                    <option value="">None</option>
                                    <?php foreach (['Jr.','Sr.','II','III'] as $s): ?>
                                    <option <?= ($res['suffix'] ?? '') === $s ? 'selected' : '' ?>><?= $s ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="field-label">Birthdate <span class="req">*</span></label>
                                <input type="date" name="birthdate" id="fBirthdate" class="form-control" required
                                       value="<?= htmlspecialchars($res['birthdate']) ?>" onchange="calcAge(this.value)">
                            </div>
                            <div class="col-md-4">
                                <label class="field-label">Age</label>
                                <input type="number" id="fAge" class="form-control" readonly value="<?= $res['age'] ?>">
                            </div>
                            <!-- Row 3: Gender / Civil Status / Contact -->
                            <div class="col-md-4">
                                <label class="field-label">Gender <span class="req">*</span></label>
                                <select name="gender" class="form-select" required>
                                    <option value="">Select</option>
                                    <option <?= $res['gender']==='Male'   ? 'selected':'' ?>>Male</option>
                                    <option <?= $res['gender']==='Female' ? 'selected':'' ?>>Female</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="field-label">Civil Status <span class="req">*</span></label>
                                <select name="civil_status" class="form-select" required>
                                    <option value="">Select</option>
                                    <?php foreach (['Single','Married','Widowed','Separated','Annulled'] as $c): ?>
                                    <option <?= $res['civil_status']===$c ? 'selected':'' ?>><?= $c ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="field-label">Contact Number</label>
                                <input type="text" name="contact_number" id="fContact" class="form-control"
                                       inputmode="numeric" pattern="09[0-9]{9}" placeholder="e.g. 09171234567"
                                       value="<?= htmlspecialchars($res['contact_number'] ?? '') ?>"
                                       oninput="this.value=this.value.replace(/[^0-9]/g,'');validateContact(this,'fContactErr')"
                                       onkeydown="if(!/[0-9]|Backspace|Delete|ArrowLeft|ArrowRight|Tab|Home|End/.test(event.key))event.preventDefault()">
                                <div id="fContactErr" class="text-danger mt-1" style="display:none;font-size:0.72rem;"></div>
                            </div>
                            <div class="col-md-4">
                                <label class="field-label">Email Address</label>
                                <input type="email" name="email" id="fEmail" class="form-control"
                                       placeholder="e.g. juan@email.com"
                                       value="<?= htmlspecialchars($res['email'] ?? '') ?>">
                            </div>
                            <!-- Row 4: Voter / Occupation / Status -->
                            <div class="col-md-4">
                                <label class="field-label">Voter Status</label>
                                <select name="voter_status" class="form-select">
                                    <option value="Not Registered" <?= $res['voter_status']==='Not Registered' ? 'selected':'' ?>>Not Registered</option>
                                    <option value="Registered"     <?= $res['voter_status']==='Registered'     ? 'selected':'' ?>>Registered</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="field-label">Occupation Status</label>
                                <select name="occupation_status" class="form-select">
                                    <?php foreach (['Unemployed','Employed','Self-Employed','Student','Retired'] as $o): ?>
                                    <option <?= $res['occupation_status']===$o ? 'selected':'' ?>><?= $o ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="field-label">Resident Status</label>
                                <select name="resident_status" id="fStatus" class="form-select" onchange="onStatusChange(this.value)">
                                    <option value="Active"      <?= $res['resident_status']==='Active'      ? 'selected':'' ?>>Active</option>
                                    <option value="Deceased"    <?= $res['resident_status']==='Deceased'    ? 'selected':'' ?>>Deceased</option>
                                    <option value="Transferred" <?= $res['resident_status']==='Transferred' ? 'selected':'' ?>>Transferred</option>
                                </select>
                            </div>
                        </div>

                        <!-- Transfer section -->
                        <div id="transferSection" style="display:<?= $res['resident_status']==='Transferred'?'block':'none' ?>;margin-top:14px;">
                            <div style="background:rgba(26,79,122,0.05);border:1px solid rgba(26,79,122,0.18);border-radius:9px;padding:16px;">
                                <div style="font-weight:700;font-size:0.8rem;color:#1a4f7a;margin-bottom:12px;">
                                    <i class="bi bi-person-walking me-1"></i>Transfer Details
                                </div>

                                <div class="row g-3">
                                    <!-- From (read-only) -->
                                    <div class="col-md-6">
                                        <label class="field-label">From Barangay</label>
                                        <input type="text" name="transfer_from" id="fTransferFrom"
                                               class="form-control bg-light" readonly
                                               value="<?= htmlspecialchars($brgyInfo['barangay_name'] ?? '') ?>">
                                    </div>
                                    <!-- To: barangays in Talavera + Outside option -->
                                    <div class="col-md-6">
                                        <label class="field-label">Transferred To <span class="req">*</span></label>
                                        <?php
                                        // Determine current selection for editing
                                        $currentTransferTo = $res['transfer_to_barangay'] ?? '';
                                        $currentIsOutside  = !empty($res['transfer_outside'])
                                            || stripos($currentTransferTo, 'outside') !== false;
                                        ?>
                                        <select name="transfer_to" id="fTransferTo" class="form-select"
                                                onchange="onTransferToChange(this.value)">
                                            <option value="">— Select destination —</option>
                                            <?php foreach ($allBarangays as $brgyOpt):
                                                if ((int)$brgyOpt['id'] === $bid) continue;
                                                $optVal  = htmlspecialchars($brgyOpt['barangay_name']);
                                                $sel     = (!$currentIsOutside && $currentTransferTo === $brgyOpt['barangay_name']) ? 'selected' : '';
                                            ?>
                                            <option value="<?= $optVal ?>" <?= $sel ?>><?= $optVal ?></option>
                                            <?php endforeach; ?>
                                            <option value="__outside__" <?= $currentIsOutside ? 'selected' : '' ?>
                                                    style="color:#b91c1c;font-weight:600;">
                                                — Outside Talavera —
                                            </option>
                                        </select>
                                        <div class="form-text" id="transferToHint">
                                            <i class="bi bi-info-circle me-1"></i>
                                            Select the destination barangay, or choose "Outside Talavera" if moving out of the municipality.
                                        </div>
                                    </div>
                                </div>
                                <!-- Populated by JS based on dropdown selection -->
                                <input type="hidden" name="transfer_outside" id="fTransferOutside"
                                       value="<?= $currentIsOutside ? '1' : '0' ?>">

                            </div>
                        </div>

                        <!-- Deceased section -->
                        <div id="deceasedSection" style="display:<?= $res['resident_status']==='Deceased'?'block':'none' ?>;margin-top:14px;">
                            <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:9px;padding:14px;">
                                <div style="font-weight:700;font-size:0.8rem;color:#7f1d1d;margin-bottom:8px;"><i class="bi bi-person-x-fill me-1"></i>Deceased — Archive Confirmation</div>
                                <div class="alert alert-warning py-2 small mb-2"><i class="bi bi-exclamation-triangle me-1"></i>Saving with <strong>Deceased</strong> status will automatically move this resident to the archive.</div>
                                <label class="field-label">Reason / Confirmation <span class="req">*</span></label>
                                <select name="archive_reason" id="fArchiveReason" class="form-select">
                                    <option value="">— Select reason —</option>
                                    <?php foreach (['Deceased — Confirmed by Barangay Record','Deceased — Death Certificate Submitted','Deceased — Reported by Family','Deceased — Confirmed by Health Officer'] as $ar): ?>
                                    <option <?= $res['archive_reason']===$ar?'selected':'' ?>><?= $ar ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
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
                                <input type="text" id="fRegionDisplay" class="form-control" readonly tabindex="-1"
                                       value="<?= htmlspecialchars($res['region'] ?? $brgyInfo['region'] ?? '—') ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="field-label">Province</label>
                                <input type="text" id="fProvinceDisplay" class="form-control" readonly tabindex="-1"
                                       value="<?= htmlspecialchars($res['province'] ?? $brgyInfo['province'] ?? '—') ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="field-label">Zip Code</label>
                                <input type="text" id="fZipDisplay" class="form-control" readonly tabindex="-1"
                                       value="<?= htmlspecialchars($res['zip_code'] ?? $brgyInfo['zip_code'] ?? '3114') ?>">
                            </div>
                            <!-- Row 2: City / Barangay / Purok -->
                            <div class="col-md-4">
                                <label class="field-label">City / Municipality</label>
                                <input type="text" id="fCityDisplay" class="form-control" readonly tabindex="-1"
                                       value="<?= htmlspecialchars($res['city'] ?? $brgyInfo['municipality'] ?? '—') ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="field-label">Barangay</label>
                                <input type="text" id="fBrgyDisplay" class="form-control" readonly tabindex="-1"
                                       value="<?= htmlspecialchars($res['addr_barangay'] ?? $brgyInfo['barangay_name'] ?? '—') ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="field-label">Purok / Street / Zone</label>
                                <input type="text" name="purok" id="fPurok" class="form-control"
                                       placeholder="e.g. Purok 3, Rizal St., Zone 2"
                                       value="<?= htmlspecialchars($res['purok'] ?? $res['street'] ?? $res['zone'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="alert alert-info py-2 mt-3 mb-0" style="font-size:0.75rem;">
                            <i class="bi bi-info-circle me-1"></i>
                            Region, Province, City, and Barangay are auto-filled from your barangay profile. Only Purok / Street / Zone is editable.
                        </div>
                    </div>
                </div>

                <!-- Section 3: Household Assignment -->
                <?php
                // Determine current role for pre-selecting the UI
                $isHead = $curHh && ((int)$curHh['head_resident_id'] === $id);
                $isMemberOnly = $curHh && !$isHead;
                $defaultHhRole = $isHead ? 'head' : ($isMemberOnly ? 'member' : 'none');
                ?>
                <div class="res-section">
                    <div class="res-section-head">
                        <i class="bi bi-house-heart-fill" style="color:#1a4f7a;"></i>
                        <span>Household Assignment</span>
                    </div>
                    <div class="res-section-body">

                        <?php if ($isHead): ?>
                        <!-- Resident is head — show info banner, no changes allowed here -->
                        <div class="alert alert-info d-flex align-items-center gap-2 py-2 mb-3" style="font-size:0.8rem;">
                            <i class="bi bi-house-fill fs-5 text-primary"></i>
                            <div>
                                This resident is the <strong>Head of Household <?= htmlspecialchars($curHh['household_code']) ?></strong>.
                                To change the household head or dissolve the household, go to
                                <a href="<?= BASE_URL ?>/barangay_admin/households.php">Households</a>.
                            </div>
                        </div>
                        <input type="hidden" name="household_role" value="">
                        <?php else: ?>

                        <p class="text-muted mb-3" style="font-size:0.8rem;">
                            Update this resident's household membership. Choose <strong>Family Member</strong> to link or change their household, or <strong>Remove</strong> to unlink them.
                        </p>

                        <?php if ($curHh): ?>
                        <div class="alert alert-success d-flex align-items-center gap-2 py-2 mb-3" style="font-size:0.8rem;">
                            <i class="bi bi-house-check-fill fs-5"></i>
                            <div>
                                Currently a member of <strong><?= htmlspecialchars($curHh['household_code']) ?></strong>
                                <?= $curHh['head_name'] ? '(Head: ' . htmlspecialchars($curHh['head_name']) . ')' : '' ?>
                                — relationship: <strong><?= htmlspecialchars($curHh['relationship']) ?></strong>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-warning d-flex align-items-center gap-2 py-2 mb-3" style="font-size:0.8rem;">
                            <i class="bi bi-house-x-fill fs-5"></i>
                            <div>This resident is <strong>not assigned</strong> to any household.</div>
                        </div>
                        <?php endif; ?>

                        <div class="d-flex gap-3 flex-wrap mb-3">
                            <!-- Head of Family card — only when not yet in any household -->
                            <?php if (!$curHh): ?>
                            <label class="hh-role-card" id="roleHeadCard" onclick="setHhRole('head')">
                                <input type="radio" name="household_role" id="roleHead" value="head"
                                       class="form-check-input me-2 m-0" style="vertical-align:middle;">
                                <i class="bi bi-house-fill text-primary me-1"></i>
                                <strong>Head of Family</strong>
                                <div class="text-muted" style="font-size:0.72rem;margin-top:2px;">Create a new household</div>
                            </label>
                            <?php endif; ?>
                            <!-- Family Member card -->
                            <label class="hh-role-card <?= $isMemberOnly ? 'selected-green' : '' ?>" id="roleMemberCard"
                                   onclick="setHhRole('member')" style="<?= $isMemberOnly ? 'border-color:#198754;background:rgba(25,135,84,0.05);' : '' ?>">
                                <input type="radio" name="household_role" id="roleMember" value="member"
                                       class="form-check-input me-2 m-0" style="vertical-align:middle;"
                                       <?= $isMemberOnly ? 'checked' : '' ?>>
                                <i class="bi bi-people-fill text-success me-1"></i>
                                <strong>Family Member</strong>
                                <div class="text-muted" style="font-size:0.72rem;margin-top:2px;">Link to a household</div>
                            </label>
                            <!-- Keep / no change card -->
                            <label class="hh-role-card" id="roleKeepCard"
                                   onclick="setHhRole('')" style="<?= (!$isMemberOnly) ? 'border-color:#1a4f7a;background:rgba(26,79,122,0.05);' : '' ?>">
                                <input type="radio" name="household_role" id="roleKeep" value=""
                                       class="form-check-input me-2 m-0" style="vertical-align:middle;"
                                       <?= (!$isMemberOnly) ? 'checked' : '' ?>>
                                <i class="bi bi-arrow-repeat text-secondary me-1"></i>
                                <strong>Keep Current</strong>
                                <div class="text-muted" style="font-size:0.72rem;margin-top:2px;">No change to household</div>
                            </label>
                            <!-- Remove card (only shown when already in a household) -->
                            <?php if ($curHh): ?>
                            <label class="hh-role-card" id="roleNoneCard" onclick="setHhRole('none')">
                                <input type="radio" name="household_role" id="roleNone" value="none"
                                       class="form-check-input me-2 m-0" style="vertical-align:middle;">
                                <i class="bi bi-x-circle text-danger me-1"></i>
                                <strong>Remove</strong>
                                <div class="text-muted" style="font-size:0.72rem;margin-top:2px;">Unlink from household</div>
                            </label>
                            <?php endif; ?>
                        </div>

                        <!-- Head of Family extra fields -->
                        <?php if (!$curHh): ?>
                        <div id="hhHeadFields" style="display:none;">
                            <div style="background:#f8fbff;border:1px solid #d0e8f5;border-radius:9px;padding:14px;">
                                <div class="fw-semibold text-primary mb-2" style="font-size:0.82rem;">
                                    <i class="bi bi-plus-circle-fill me-1"></i>New Household Details
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="field-label">Total Members</label>
                                        <input type="number" name="hh_total_members" class="form-control" min="1" value="1"
                                               oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="field-label">Senior Citizens</label>
                                        <input type="number" name="hh_total_senior" class="form-control" min="0" value="0"
                                               oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="field-label">PWD Count</label>
                                        <input type="number" name="hh_total_pwd" class="form-control" min="0" value="0"
                                               oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="field-label">Income Range</label>
                                        <select name="hh_income_range" class="form-select">
                                            <option value="">— Select —</option>
                                            <option>Below ₱10,000</option>
                                            <option>₱10,000 - ₱20,000</option>
                                            <option>₱20,001 - ₱40,000</option>
                                            <option>₱40,001 - ₱60,000</option>
                                            <option>Above ₱60,000</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="field-label">Length of Stay</label>
                                        <input type="text" name="hh_length_of_stay" class="form-control"
                                               placeholder="e.g. 5 years">
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Family Member fields -->
                        <div id="hhMemberFields" style="display:<?= $isMemberOnly ? '' : 'none' ?>;">
                            <div style="background:#f8fbff;border:1px solid #d0e8f5;border-radius:9px;padding:14px;">
                                <div class="fw-semibold text-success mb-2" style="font-size:0.82rem;">
                                    <i class="bi bi-link-45deg me-1"></i>Link to Household
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-7">
                                        <label class="field-label">Select Household <span class="req">*</span></label>
                                        <select name="hh_member_household_id" id="hhMemberHouseholdSelect" class="form-select">
                                            <option value="">— Select household —</option>
                                            <?php foreach ($activeHouseholds as $hh): ?>
                                            <option value="<?= $hh['id'] ?>"
                                                <?= ($curHh && (int)$curHh['household_id'] === (int)$hh['id']) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($hh['household_code']) ?>
                                                <?= $hh['head_name'] ? ' — ' . htmlspecialchars($hh['head_name']) : '' ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if (empty($activeHouseholds)): ?>
                                        <div class="text-warning small mt-1">
                                            <i class="bi bi-exclamation-triangle me-1"></i>No active households found.
                                        </div>
                                        <?php endif; ?>
                                        <div class="text-muted mt-1" style="font-size:0.72rem;">
                                            <i class="bi bi-search me-1"></i>Type a surname or household code to search.
                                        </div>
                                    </div>
                                    <div class="col-md-5">
                                        <label class="field-label">Relationship to Head <span class="req">*</span></label>
                                        <select name="hh_member_relationship" id="hhMemberRelationship" class="form-select">
                                            <option value="">— Select —</option>
                                            <?php
                                            $relationships = ['Spouse','Son','Daughter','Father','Mother','Brother','Sister',
                                                'Grandfather','Grandmother','Grandson','Granddaughter','Uncle','Aunt',
                                                'Nephew','Niece','Cousin','In-Law','Boarder','Helper','Other'];
                                            $curRelationship = $curHh['relationship'] ?? '';
                                            foreach ($relationships as $rel): ?>
                                            <option value="<?= $rel ?>" <?= $curRelationship === $rel ? 'selected' : '' ?>><?= $rel ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php endif; // end !$isHead ?>
                    </div>
                </div>

                <!-- Sticky footer -->
                <div class="res-footer">
                    <div class="d-flex gap-2 justify-content-end">
                        <a href="<?= BASE_URL ?>/barangay_admin/residents.php" class="btn btn-outline-secondary px-4">Cancel</a>
                        <button type="submit" id="saveResidentBtn" class="btn btn-primary fw-semibold px-5" style="background:#1a4f7a;border-color:#1a4f7a;">
                            <i class="bi bi-check-circle-fill me-2"></i>Save Changes
                        </button>
                    </div>
                </div>

            </div>
        </div>
    </form>
</div>

<!-- Camera Modal -->
<div class="modal fade" id="cameraModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered" style="max-width:500px;">
        <div class="modal-content" style="border-radius:14px;overflow:hidden;">
            <div class="modal-header" style="background:#1a4f7a;border:none;padding:14px 20px;">
                <h6 class="modal-title text-white fw-bold mb-0"><i class="bi bi-camera-fill me-2"></i>Take Photo</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" onclick="stopCamera()"></button>
            </div>
            <div class="modal-body p-3 text-center">
                <!-- Camera switch button -->
                <div id="cameraSwitchRow" class="mb-2 d-flex justify-content-center gap-2" style="display:none!important;">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="switchCamBtn" onclick="switchCamera()">
                        <i class="bi bi-arrow-repeat me-1"></i>Switch Camera
                    </button>
                    <span id="camFacingLabel" class="badge align-self-center" style="background:rgba(26,79,122,0.12);color:#1a4f7a;font-size:0.72rem;padding:5px 10px;"></span>
                </div>
                <!-- Loading -->
                <div id="cameraLoading" style="display:none;padding:40px 0;">
                    <div class="spinner-border text-primary mb-2" style="width:2rem;height:2rem;"></div>
                    <div class="small text-muted">Starting camera…</div>
                </div>
                <!-- Idle -->
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
// ── Select2: searchable household dropdown ─────────────────────────────────
$(document).ready(function () {
    $('#hhMemberHouseholdSelect').select2({
        placeholder: '— Type surname or household code —',
        allowClear: true,
        width: '100%',
        dropdownParent: $('#hhMemberFields'),
        matcher: function (params, data) {
            if ($.trim(params.term) === '') return data;
            if (!data.text) return null;
            if (data.text.toUpperCase().indexOf(params.term.toUpperCase()) > -1) return data;
            return null;
        }
    });
});

// ── Household role toggle ──────────────────────────────────────────────────
function setHhRole(role) {
    const headRadio   = document.getElementById('roleHead');   // may be null
    const memberRadio = document.getElementById('roleMember');
    const keepRadio   = document.getElementById('roleKeep');
    const noneRadio   = document.getElementById('roleNone');   // may be null

    if (headRadio)   headRadio.checked   = (role === 'head');
    if (memberRadio) memberRadio.checked = (role === 'member');
    if (keepRadio)   keepRadio.checked   = (role === '');
    if (noneRadio)   noneRadio.checked   = (role === 'none');

    const headCard   = document.getElementById('roleHeadCard');
    const memberCard = document.getElementById('roleMemberCard');
    const keepCard   = document.getElementById('roleKeepCard');
    const noneCard   = document.getElementById('roleNoneCard');

    if (headCard) {
        headCard.style.borderColor = role === 'head'   ? '#1a4f7a' : '#dee2e6';
        headCard.style.background  = role === 'head'   ? 'rgba(26,79,122,0.05)' : '#fff';
    }
    if (memberCard) {
        memberCard.style.borderColor = role === 'member' ? '#198754' : '#dee2e6';
        memberCard.style.background  = role === 'member' ? 'rgba(25,135,84,0.05)' : '#fff';
    }
    if (keepCard) {
        keepCard.style.borderColor = role === '' ? '#1a4f7a' : '#dee2e6';
        keepCard.style.background  = role === '' ? 'rgba(26,79,122,0.05)' : '#fff';
    }
    if (noneCard) {
        noneCard.style.borderColor = role === 'none' ? '#dc3545' : '#dee2e6';
        noneCard.style.background  = role === 'none' ? 'rgba(220,53,69,0.05)' : '#fff';
    }

    const headFields   = document.getElementById('hhHeadFields');
    const memberFields = document.getElementById('hhMemberFields');
    if (headFields)   headFields.style.display   = (role === 'head')   ? '' : 'none';
    if (memberFields) memberFields.style.display = (role === 'member') ? '' : 'none';
}
function calcAge(bdate) {
    if (!bdate) return;
    const birth = new Date(bdate), today = new Date();
    let age = today.getFullYear() - birth.getFullYear();
    const m = today.getMonth() - birth.getMonth();
    if (m < 0 || (m === 0 && today.getDate() < birth.getDate())) age--;
    document.getElementById('fAge').value = age;
    if (age >= 60) document.getElementById('fSenior').checked = true;
}

function validateContact(input, errId) {
    const val = input.value.trim(), err = document.getElementById(errId);
    if (!val) { input.classList.remove('is-invalid','is-valid'); err.style.display='none'; return; }
    if (/^09\d{9}$/.test(val)) { input.classList.remove('is-invalid'); input.classList.add('is-valid'); err.style.display='none'; }
    else { input.classList.remove('is-valid'); input.classList.add('is-invalid'); err.textContent='Must be 11 digits starting with 09.'; err.style.display='block'; }
}

function onStatusChange(status) {
    document.getElementById('transferSection').style.display = status === 'Transferred' ? 'block' : 'none';
    document.getElementById('deceasedSection').style.display = status === 'Deceased'    ? 'block' : 'none';
    document.getElementById('fTransferTo').required = (status === 'Transferred');
}

// Initialise required state on page load
document.addEventListener('DOMContentLoaded', function () {
    const statusEl = document.getElementById('fStatus');
    if (statusEl) onStatusChange(statusEl.value);
});

function onTransferToChange(val) {
    const isOutside = val === '__outside__';
    document.getElementById('fTransferOutside').value = isOutside ? '1' : '0';
    const hint = document.getElementById('transferToHint');
    if (isOutside) {
        hint.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1 text-warning"></i>'
            + 'This resident will be recorded as transferred <strong>outside Talavera</strong>.';
    } else {
        hint.innerHTML = '<i class="bi bi-info-circle me-1"></i>'
            + 'Select the destination barangay, or choose "Outside Talavera" if moving out of the municipality.';
    }
}

// ── Photo ─────────────────────────────────────────────────────────────────
function setPhotoPreview(src) {
    const preview = document.getElementById('photoPreview');
    preview.src = src; preview.style.display='block';
    document.getElementById('photoPlaceholder').style.display='none';
    document.getElementById('clearPhotoBtn').style.display='';
}
function clearPhoto() {
    const p = document.getElementById('photoPreview');
    p.src=''; p.style.display='none';
    document.getElementById('photoPlaceholder').style.display='';
    document.getElementById('clearPhotoBtn').style.display='none';
    document.getElementById('photoBase64').value='';
    document.getElementById('photoFileInput').value='';
}
document.getElementById('photoFileInput').addEventListener('change', function() {
    if (!this.files[0]) return;
    const reader = new FileReader();
    reader.onload = e => { setPhotoPreview(e.target.result); document.getElementById('photoBase64').value=''; };
    reader.readAsDataURL(this.files[0]);
});
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
let currentFacing  = 'user';
const cameraModal  = new bootstrap.Modal(document.getElementById('cameraModal'));

document.getElementById('openCameraBtn').addEventListener('click', () => {
    resetCameraUI();
    cameraModal.show();
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

            const switchRow = document.getElementById('cameraSwitchRow');
            switchRow.style.cssText = '';
            switchRow.style.display = 'flex';
            document.getElementById('camFacingLabel').textContent =
                facingMode === 'environment' ? '🔲 Back Camera' : '🤳 Front Camera';

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
    startCamera(currentFacing === 'user' ? 'environment' : 'user');
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
    const v = document.getElementById('cameraVideo');
    const c = document.getElementById('cameraCanvas');
    c.width  = v.videoWidth  || 640;
    c.height = v.videoHeight || 480;
    c.getContext('2d').drawImage(v, 0, 0, c.width, c.height);
    const dataUrl = c.toDataURL('image/jpeg', 0.88);
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

// ── CSRF submit guard ─────────────────────────────────────────────────────
// Syncs the hidden csrf_token field with the server's current session token
// immediately before the form is submitted, so any intervening AJAX call
// (e.g. mark-notifications-read) can never leave the field stale.
(function () {
    const form = document.getElementById('residentForm');
    const btn  = document.getElementById('saveResidentBtn');
    if (!form || !btn) return;

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        // Disable button to prevent double-submit
        btn.disabled = true;
        const origHtml = btn.innerHTML;
        btn.innerHTML  = '<span class="spinner-border spinner-border-sm me-1"></span>Saving…';

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
        .catch(() => { /* network hiccup — submit with existing token */ })
        .finally(() => { form.submit(); });
    });
})();
</script>
</body>
</html>
