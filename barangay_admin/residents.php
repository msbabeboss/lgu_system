<?php
require_once __DIR__ . '/../config/config.php';
requireRole('barangay_admin');

$db = Database::getInstance()->getConnection();
$u = currentUser();
$bid = (int)$u['barangay_id'];

// Safe migrations — ensure all address columns exist on both tables
try { $db->exec("ALTER TABLE addresses ADD COLUMN region VARCHAR(100) NULL AFTER id"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE addresses ADD COLUMN zip_code VARCHAR(10) NULL"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE barangays ADD COLUMN region VARCHAR(100) NULL"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE barangays ADD COLUMN province VARCHAR(100) NULL"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE barangays ADD COLUMN zip_code VARCHAR(10) NULL"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE residents ADD COLUMN transfer_from_barangay VARCHAR(150) NULL"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE residents ADD COLUMN transfer_to_barangay VARCHAR(150) NULL"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE residents ADD COLUMN transfer_outside TINYINT(1) DEFAULT 0"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE residents ADD COLUMN transfer_reason VARCHAR(255) NULL"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE residents ADD COLUMN archive_reason VARCHAR(255) NULL"); } catch (PDOException $e) {}
try { $db->exec("ALTER TABLE residents ADD COLUMN photo VARCHAR(255) NULL"); } catch (PDOException $e) {}
// Pending Transfer status
try { $db->exec("ALTER TABLE residents MODIFY COLUMN resident_status ENUM('Active','Inactive','Deceased','Transferred','Pending Transfer') DEFAULT 'Active'"); } catch (PDOException $e) {}

// Bootstrap transfer requests table
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
try { $db->exec("ALTER TABLE resident_transfer_requests MODIFY COLUMN status ENUM('pending','approved','rejected','verified') NOT NULL DEFAULT 'pending'"); } catch (PDOException $e) {}

// Fetch barangay info for auto-fill — all address fields
$brgyInfoStmt = $db->prepare("SELECT id, barangay_name, municipality, region, province, zip_code FROM barangays WHERE id=?");
$brgyInfoStmt->execute([$bid]);
$brgyInfo = $brgyInfoStmt->fetch() ?: [];

// Ensure resident photos directory exists
$photoDir = __DIR__ . '/../uploads/residents/';
if (!is_dir($photoDir)) { mkdir($photoDir, 0755, true); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrAbort();
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $fname    = sanitize($_POST['first_name']);
        $mname    = sanitize($_POST['middle_name'] ?? '');
        $lname    = sanitize($_POST['last_name']);
        $suffix   = sanitize($_POST['suffix'] ?? '');
        $bdate    = $_POST['birthdate'];
        $age      = calculateAge($bdate);
        $civil    = $_POST['civil_status'];
        $gender   = $_POST['gender'];
        $contact  = sanitize($_POST['contact_number'] ?? '');

        // Validate contact: must match 09XXXXXXXXX (11 digits, starts with 09)
        if ($contact && !preg_match('/^09\d{9}$/', $contact)) {
            flash('res', 'Contact number must be 11 digits and start with 09 (e.g. 09171234567).', 'danger');
            redirect(BASE_URL . '/barangay_admin/residents.php');
        }
        // Duplicate contact check
        if ($contact) {
            $dupId = isset($_POST['resident_id']) ? (int)$_POST['resident_id'] : 0;
            $dupQ  = $db->prepare("SELECT id FROM residents WHERE contact_number=? AND barangay_id=? AND id!=? AND resident_status NOT IN ('Inactive','Deceased','Transferred')");
            $dupQ->execute([$contact, $bid, $dupId]);
            if ($dupQ->fetch()) {
                flash('res', "Contact number <strong>$contact</strong> is already in use by another resident.", 'danger');
                redirect(BASE_URL . '/barangay_admin/residents.php');
            }
        }
        $voter    = $_POST['voter_status'];
        $is4ps    = isset($_POST['is_4ps']) ? 1 : 0;
        $isPwd    = isset($_POST['is_pwd']) ? 1 : 0;
        $isSenior = $age >= 60 ? 1 : (isset($_POST['is_senior']) ? 1 : 0);
        $isSolo   = isset($_POST['is_solo_parent']) ? 1 : 0;
        // Status: always Active on add, editable on edit
        $status   = ($action === 'add') ? 'Active' : ($_POST['resident_status'] ?? 'Active');
        $occup    = $_POST['occupation_status'];

        // Transfer fields
        $transferFrom   = sanitize($_POST['transfer_from'] ?? '');
        $transferTo     = sanitize($_POST['transfer_to'] ?? '');
        $transferOutside= isset($_POST['transfer_outside']) ? 1 : 0;
        $transferReason = sanitize($_POST['transfer_reason'] ?? '');
        $archiveReason  = sanitize($_POST['archive_reason'] ?? '');

        // ── Photo upload ─────────────────────────────────────────────────
        $newPhotoFile = null;
        // Priority 1: base64 from camera/crop canvas
        if (!empty($_POST['photo_base64'])) {
            $b64data = $_POST['photo_base64'];
            if (preg_match('/^data:image\/(jpeg|png|webp);base64,/', $b64data, $typeMatch)) {
                $imgData  = base64_decode(preg_replace('/^data:image\/\w+;base64,/', '', $b64data));
                $ext      = ($typeMatch[1] === 'png') ? 'png' : 'jpg';
                $filename = 'resident_' . time() . '_' . random_int(1000, 9999) . '.' . $ext;
                file_put_contents($photoDir . $filename, $imgData);
                $newPhotoFile = $filename;
            }
        }
        // Priority 2: regular file upload
        elseif (!empty($_FILES['photo']['tmp_name'])) {
            $file    = $_FILES['photo'];
            $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            $finfo   = finfo_open(FILEINFO_MIME_TYPE);
            $mime    = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            if (!in_array($mime, $allowed)) {
                flash('res', 'Invalid image type. Allowed: JPG, PNG, WebP, GIF.', 'danger');
                redirect(BASE_URL . '/barangay_admin/residents.php');
            }
            if ($file['size'] > 5 * 1024 * 1024) {
                flash('res', 'Photo must be under 5 MB.', 'danger');
                redirect(BASE_URL . '/barangay_admin/residents.php');
            }
            $ext      = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'jpg';
            $filename = 'resident_' . time() . '_' . random_int(1000, 9999) . '.' . strtolower($ext);
            move_uploaded_file($file['tmp_name'], $photoDir . $filename);
            $newPhotoFile = $filename;
        }
        // ─────────────────────────────────────────────────────────────────
        if ($status === 'Transferred') {
            if (!$transferTo) {
                flash('res', 'Please enter the destination barangay for transfer.', 'danger');
                redirect(BASE_URL . '/barangay_admin/residents.php');
            }
            if ($transferOutside && !$transferReason) {
                flash('res', 'Please provide a reason for outside barangay transfer.', 'danger');
                redirect(BASE_URL . '/barangay_admin/residents.php');
            }
        }
        if ($status === 'Deceased' && !$archiveReason) {
            flash('res', 'Please provide a reason/confirmation for deceased status.', 'danger');
            redirect(BASE_URL . '/barangay_admin/residents.php');
        }

        // Address — all fields come from barangay record (hidden inputs) except street/purok/zone
        $region   = sanitize($_POST['region']        ?? $brgyInfo['region']       ?? '');
        $province = sanitize($_POST['province']      ?? $brgyInfo['province']     ?? '');
        $city     = sanitize($_POST['city']          ?? $brgyInfo['municipality'] ?? '');
        $brgyAddr = sanitize($_POST['barangay_addr'] ?? $brgyInfo['barangay_name']?? '');
        $zipCode  = sanitize($_POST['zip_code']      ?? $brgyInfo['zip_code']     ?? '3114');
        $street   = sanitize($_POST['street'] ?? '');
        $purok    = sanitize($_POST['purok']  ?? '');
        $zone     = sanitize($_POST['zone']   ?? '');

        if ($action === 'add') {
            $addrStmt = $db->prepare("INSERT INTO addresses (region,province,city,barangay,street,purok,zone,zip_code) VALUES (?,?,?,?,?,?,?,?)");
            $addrStmt->execute([$region,$province,$city,$brgyAddr,$street,$purok,$zone,$zipCode]);
            $addrId = $db->lastInsertId();

            $stmt = $db->prepare("INSERT INTO residents (barangay_id,first_name,middle_name,last_name,suffix,birthdate,age,civil_status,gender,contact_number,address_id,voter_status,is_4ps,is_pwd,is_senior,is_solo_parent,resident_status,occupation_status,photo) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$bid,$fname,$mname,$lname,$suffix,$bdate,$age,$civil,$gender,$contact,$addrId,$voter,$is4ps,$isPwd,$isSenior,$isSolo,$status,$occup,$newPhotoFile]);
            $newResidentId = (int)$db->lastInsertId();

            // ── Household linking ──────────────────────────────────────────────────
            $householdRole = $_POST['household_role'] ?? '';

            if ($householdRole === 'head') {
                // Create a new household with this resident as head
                $hhLos      = sanitize($_POST['hh_length_of_stay'] ?? '');
                $hhIncome   = sanitize($_POST['hh_income_range'] ?? '');
                $hhMembers  = max(1, (int)($_POST['hh_total_members'] ?? 1));
                $hhSenior   = (int)($_POST['hh_total_senior'] ?? ($isSenior ? 1 : 0));
                $hhPwd      = (int)($_POST['hh_total_pwd']    ?? ($isPwd    ? 1 : 0));

                $hhCode = generateHouseholdCode($bid);

                // Household shares the resident's address
                $db->prepare(
                    "INSERT INTO households (barangay_id,household_code,head_resident_id,contact_number,address_id,length_of_stay,total_members,total_senior,total_pwd,income_range,status)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)"
                )->execute([$bid,$hhCode,$newResidentId,$contact,$addrId,$hhLos,$hhMembers,$hhSenior,$hhPwd,$hhIncome,'Active']);
                $newHouseholdId = (int)$db->lastInsertId();

                // Register as household member with relationship "Head"
                $db->prepare(
                    "INSERT INTO household_members (household_id, resident_id, relationship) VALUES (?,?,?)"
                )->execute([$newHouseholdId, $newResidentId, 'Head']);

                logActivity($u['id'], 'ADD_RESIDENT', 'Residents', "Added: $fname $lname (Head of new household $hhCode)");
                flash('res', "Resident added and new household <strong>$hhCode</strong> created successfully.", 'success');

            } elseif ($householdRole === 'member') {
                $linkedHouseholdId  = (int)($_POST['hh_member_household_id'] ?? 0);
                $memberRelationship = sanitize($_POST['hh_member_relationship'] ?? 'Member');

                if ($linkedHouseholdId) {
                    // Prevent duplicate membership
                    $dupCheck = $db->prepare("SELECT id FROM household_members WHERE household_id=? AND resident_id=?");
                    $dupCheck->execute([$linkedHouseholdId, $newResidentId]);
                    if (!$dupCheck->fetch()) {
                        $db->prepare(
                            "INSERT INTO household_members (household_id, resident_id, relationship) VALUES (?,?,?)"
                        )->execute([$linkedHouseholdId, $newResidentId, $memberRelationship]);

                        // Increment total_members count
                        $db->prepare("UPDATE households SET total_members = total_members + 1 WHERE id=?")->execute([$linkedHouseholdId]);
                        if ($isSenior) $db->prepare("UPDATE households SET total_senior = total_senior + 1 WHERE id=?")->execute([$linkedHouseholdId]);
                        if ($isPwd)    $db->prepare("UPDATE households SET total_pwd = total_pwd + 1 WHERE id=?")->execute([$linkedHouseholdId]);
                    }
                    $hhCodeStmt = $db->prepare("SELECT household_code FROM households WHERE id=?");
                    $hhCodeStmt->execute([$linkedHouseholdId]);
                    $hhCodeLabel = $hhCodeStmt->fetchColumn() ?: "#{$linkedHouseholdId}";

                    logActivity($u['id'], 'ADD_RESIDENT', 'Residents', "Added: $fname $lname (Family Member of household $hhCodeLabel, relationship: $memberRelationship)");
                    flash('res', "Resident added and linked to household <strong>$hhCodeLabel</strong> as <strong>$memberRelationship</strong>.", 'success');
                } else {
                    logActivity($u['id'], 'ADD_RESIDENT', 'Residents', "Added: $fname $lname (no household selected)");
                    flash('res', 'Resident added successfully.', 'success');
                }
            } else {
                logActivity($u['id'], 'ADD_RESIDENT', 'Residents', "Added: $fname $lname");
                flash('res', 'Resident added successfully.', 'success');
            }
            // ─────────────────────────────────────────────────────────────────────
        } else {
            $id = (int)$_POST['resident_id'];
            $addrId = (int)$_POST['address_id'];
            $db->prepare("UPDATE addresses SET region=?,province=?,city=?,barangay=?,street=?,purok=?,zone=?,zip_code=? WHERE id=?")
               ->execute([$region,$province,$city,$brgyAddr,$street,$purok,$zone,$zipCode,$addrId]);
            // Build photo clause dynamically — only update photo if a new one was uploaded
            if ($newPhotoFile) {
                // Delete old photo if it exists
                $oldPhoto = $db->prepare("SELECT photo FROM residents WHERE id=? AND barangay_id=?");
                $oldPhoto->execute([$id, $bid]);
                $oldRow = $oldPhoto->fetch();
                if (!empty($oldRow['photo']) && file_exists($photoDir . $oldRow['photo'])) {
                    unlink($photoDir . $oldRow['photo']);
                }
                $db->prepare("UPDATE residents SET first_name=?,middle_name=?,last_name=?,suffix=?,birthdate=?,age=?,civil_status=?,gender=?,contact_number=?,voter_status=?,is_4ps=?,is_pwd=?,is_senior=?,is_solo_parent=?,resident_status=?,occupation_status=?,transfer_from_barangay=?,transfer_to_barangay=?,transfer_outside=?,transfer_reason=?,archive_reason=?,photo=? WHERE id=? AND barangay_id=?")
                   ->execute([$fname,$mname,$lname,$suffix,$bdate,$age,$civil,$gender,$contact,$voter,$is4ps,$isPwd,$isSenior,$isSolo,$status,$occup,$transferFrom,$transferTo,$transferOutside,$transferReason,$archiveReason,$newPhotoFile,$id,$bid]);
            } else {
                $db->prepare("UPDATE residents SET first_name=?,middle_name=?,last_name=?,suffix=?,birthdate=?,age=?,civil_status=?,gender=?,contact_number=?,voter_status=?,is_4ps=?,is_pwd=?,is_senior=?,is_solo_parent=?,resident_status=?,occupation_status=?,transfer_from_barangay=?,transfer_to_barangay=?,transfer_outside=?,transfer_reason=?,archive_reason=? WHERE id=? AND barangay_id=?")
                   ->execute([$fname,$mname,$lname,$suffix,$bdate,$age,$civil,$gender,$contact,$voter,$is4ps,$isPwd,$isSenior,$isSolo,$status,$occup,$transferFrom,$transferTo,$transferOutside,$transferReason,$archiveReason,$id,$bid]);
            }

            // Auto-archive if Deceased
            if ($status === 'Deceased') {
                $row = $db->prepare("SELECT r.*, a.* FROM residents r LEFT JOIN addresses a ON r.address_id=a.id WHERE r.id=? AND r.barangay_id=?");
                $row->execute([$id, $bid]);
                $archData = $row->fetch();
                if ($archData) {
                    $archData['archive_reason'] = $archiveReason;
                    // Only archive if not already archived
                    $alreadyArch = $db->prepare("SELECT id FROM archives WHERE table_name='residents' AND record_id=?");
                    $alreadyArch->execute([$id]);
                    if (!$alreadyArch->fetch()) {
                        $db->prepare("INSERT INTO archives (table_name,record_id,data_json,archived_by) VALUES ('residents',?,?,?)")
                           ->execute([$id, json_encode($archData), $u['id']]);
                    }
                    // Keep status as 'Deceased' so dashboard can count it correctly
                    $db->prepare("UPDATE residents SET resident_status='Deceased' WHERE id=? AND barangay_id=?")->execute([$id, $bid]);
                }
                logActivity($u['id'], 'DECEASED_RESIDENT', 'Residents', "Marked $fname $lname as deceased — $archiveReason");
                flash('res', 'Resident marked as deceased and moved to archive.', 'warning');
            } elseif ($status === 'Transferred') {
                logActivity($u['id'], 'TRANSFER_RESIDENT', 'Residents', "Transferred $fname $lname to $transferTo");
                flash('res', 'Resident transfer recorded.', 'info');
            } else {
                logActivity($u['id'], 'EDIT_RESIDENT', 'Residents', "Updated resident record of $fname $lname");
                flash('res', 'Resident updated.', 'success');
            }
        }
        redirect(BASE_URL . '/barangay_admin/residents.php');
    }

    // ── Approve incoming transfer ──────────────────────────────────────
    if ($action === 'approve_transfer') {
        $trId = (int)($_POST['transfer_id'] ?? 0);
        if ($trId) {
            $trStmt = $db->prepare("
                SELECT r.*, b1.barangay_name AS origin_name
                FROM resident_transfer_requests r
                LEFT JOIN barangays b1 ON r.origin_barangay_id = b1.id
                WHERE r.id=? AND r.destination_barangay_id=? AND r.status='pending'
            ");
            $trStmt->execute([$trId, $bid]);
            $tr = $trStmt->fetch();
            if ($tr) {
                // Mark request as approved
                $db->prepare("UPDATE resident_transfer_requests SET status='approved', reviewed_by=?, reviewed_at=NOW() WHERE id=?")
                   ->execute([$u['id'], $trId]);
                // Mark resident as Transferred
                $db->prepare("UPDATE residents SET resident_status='Transferred' WHERE id=? AND barangay_id=?")
                   ->execute([$tr['resident_id'], $tr['origin_barangay_id']]);

                // Notify origin barangay
                notifyBarangayAdmins((int)$tr['origin_barangay_id'],
                    "✅ <strong>{$tr['resident_name']}</strong>'s transfer to your barangay has been <strong>approved</strong> by the destination barangay. The resident is now marked as Transferred.",
                    'success', 'transaction');

                logActivity($u['id'], 'APPROVE_TRANSFER', 'Resident Transfer',
                    "Approved incoming transfer #{$trId}: {$tr['resident_name']} from {$tr['origin_name']}");
                flash('res', "Transfer for <strong>{$tr['resident_name']}</strong> approved. The origin barangay has been notified.", 'success');
            }
        }
        redirect(BASE_URL . '/barangay_admin/residents.php?tab=transferred');
    }

    // ── Reject incoming transfer ───────────────────────────────────────
    if ($action === 'reject_transfer') {
        $trId   = (int)($_POST['transfer_id'] ?? 0);
        $reason = sanitize($_POST['reject_reason'] ?? '');
        if ($trId) {
            $trStmt = $db->prepare("
                SELECT r.*, b1.barangay_name AS origin_name
                FROM resident_transfer_requests r
                LEFT JOIN barangays b1 ON r.origin_barangay_id = b1.id
                WHERE r.id=? AND r.destination_barangay_id=? AND r.status='pending'
            ");
            $trStmt->execute([$trId, $bid]);
            $tr = $trStmt->fetch();
            if ($tr) {
                // Mark request as rejected
                $db->prepare("UPDATE resident_transfer_requests SET status='rejected', reviewed_by=?, reviewed_at=NOW(), admin_notes=? WHERE id=?")
                   ->execute([$u['id'], $reason, $trId]);
                // Revert resident back to Active
                $db->prepare("UPDATE residents SET resident_status='Active', transfer_to_barangay=NULL, transfer_from_barangay=NULL, transfer_outside=0 WHERE id=? AND barangay_id=?")
                   ->execute([$tr['resident_id'], $tr['origin_barangay_id']]);

                // Notify origin barangay
                $reasonNote = $reason ? " Reason: $reason" : '';
                notifyBarangayAdmins((int)$tr['origin_barangay_id'],
                    "❌ <strong>{$tr['resident_name']}</strong>'s transfer request was <strong>rejected</strong> by the destination barangay.{$reasonNote} The resident has been reverted to Active.",
                    'danger', 'transaction');

                logActivity($u['id'], 'REJECT_TRANSFER', 'Resident Transfer',
                    "Rejected incoming transfer #{$trId}: {$tr['resident_name']} from {$tr['origin_name']}" . ($reason ? " — $reason" : ''));
                flash('res', "Transfer for <strong>{$tr['resident_name']}</strong> rejected. The origin barangay has been notified.", 'warning');
            }
        }
        redirect(BASE_URL . '/barangay_admin/residents.php?tab=transferred');
    }

    // ── Confirm physical arrival → add as new resident ────────────────
    if ($action === 'confirm_transfer_arrival') {
        $trId = (int)($_POST['transfer_id'] ?? 0);
        if ($trId) {
            $trStmt = $db->prepare("
                SELECT rtr.*, b.barangay_name AS origin_name,
                       res.first_name, res.middle_name, res.last_name, res.suffix,
                       res.birthdate, res.age, res.gender, res.civil_status,
                       res.contact_number, res.voter_status,
                       res.is_4ps, res.is_pwd, res.is_senior, res.is_solo_parent,
                       res.occupation_status, res.photo,
                       a.region, a.province, a.city, a.purok, a.street, a.zone, a.zip_code
                FROM resident_transfer_requests rtr
                LEFT JOIN barangays b   ON rtr.origin_barangay_id = b.id
                LEFT JOIN residents res ON rtr.resident_id         = res.id
                LEFT JOIN addresses a   ON res.address_id          = a.id
                WHERE rtr.id=? AND rtr.destination_barangay_id=? AND rtr.status='approved'
            ");
            $trStmt->execute([$trId, $bid]);
            $tr = $trStmt->fetch();

            if ($tr) {
                // ── Copy resident into this barangay ──────────────────
                // Create a new address row for the destination barangay
                $destBrgyInfo = $db->prepare("SELECT barangay_name, municipality, region, province, zip_code FROM barangays WHERE id=?");
                $destBrgyInfo->execute([$bid]);
                $destInfo = $destBrgyInfo->fetch() ?: [];

                $db->prepare("INSERT INTO addresses (region, province, city, barangay, street, purok, zone, zip_code)
                              VALUES (?,?,?,?,?,?,?,?)")
                   ->execute([
                       $destInfo['region']        ?? $tr['region']   ?? '',
                       $destInfo['province']       ?? $tr['province'] ?? '',
                       $destInfo['municipality']   ?? $tr['city']     ?? '',
                       $destInfo['barangay_name']  ?? '',
                       $tr['street'] ?? '',
                       $tr['purok']  ?? '',
                       $tr['zone']   ?? '',
                       $destInfo['zip_code'] ?? $tr['zip_code'] ?? '',
                   ]);
                $newAddrId = (int)$db->lastInsertId();

                // Insert as new resident in destination barangay
                $db->prepare("INSERT INTO residents
                    (barangay_id, first_name, middle_name, last_name, suffix,
                     birthdate, age, gender, civil_status, contact_number,
                     voter_status, is_4ps, is_pwd, is_senior, is_solo_parent,
                     resident_status, occupation_status,
                     transfer_from_barangay, transfer_to_barangay,
                     address_id, photo)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'Active',?,?,?,?,?)")
                   ->execute([
                       $bid,
                       $tr['first_name'], $tr['middle_name'], $tr['last_name'], $tr['suffix'] ?? '',
                       $tr['birthdate'], $tr['age'], $tr['gender'], $tr['civil_status'],
                       $tr['contact_number'],
                       $tr['voter_status'],
                       $tr['is_4ps'], $tr['is_pwd'], $tr['is_senior'], $tr['is_solo_parent'],
                       $tr['occupation_status'] ?? '',
                       $tr['origin_name'],   // transfer_from_barangay
                       '',                   // transfer_to_barangay (they're here now)
                       $newAddrId,
                       $tr['photo'] ?? null,
                   ]);
                $newResidentId = (int)$db->lastInsertId();

                // Mark transfer request as verified
                $db->prepare("UPDATE resident_transfer_requests
                    SET status='verified', verified_by=?, verified_at=NOW() WHERE id=?")
                   ->execute([$u['id'], $trId]);

                // Notify origin barangay
                $resFullName = trim("{$tr['first_name']} {$tr['middle_name']} {$tr['last_name']}");
                notifyBarangayAdmins((int)$tr['origin_barangay_id'],
                    "✅ <strong>$resFullName</strong> has physically appeared and is now registered as a resident of the destination barangay. Transfer is complete.",
                    'success', 'transaction');

                logActivity($u['id'], 'CONFIRM_TRANSFER_ARRIVAL', 'Resident Transfer',
                    "Confirmed arrival of $resFullName (Transfer #{$trId}) — added as resident #{$newResidentId}");
                flash('res',
                    "<strong>$resFullName</strong> has been added as a new resident in your barangay. Transfer complete.",
                    'success');
            }
        }
        redirect(BASE_URL . '/barangay_admin/residents.php?tab=transferred');
    }

    if ($action === 'archive') {
        $id = (int)$_POST['resident_id'];
        $row = $db->prepare("SELECT r.*, a.* FROM residents r LEFT JOIN addresses a ON r.address_id=a.id WHERE r.id=? AND r.barangay_id=?");
        $row->execute([$id, $bid]);
        $data = $row->fetch();
        $db->prepare("INSERT INTO archives (table_name,record_id,data_json,archived_by) VALUES ('residents',?,?,?)")->execute([$id, json_encode($data), $u['id']]);
        $db->prepare("UPDATE residents SET resident_status='Inactive' WHERE id=? AND barangay_id=?")->execute([$id, $bid]);
        $resName = trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''));
        logActivity($u['id'], 'ARCHIVE_RESIDENT', 'Residents', "Archived resident $resName");
        flash('res', 'Resident archived.', 'info');
        redirect(BASE_URL . '/barangay_admin/residents.php');
    }
}

$search = sanitize($_GET['search'] ?? '');
$search      = sanitize($_GET['search']       ?? '');
$filter      = sanitize($_GET['filter']       ?? '');   // tag filter (pwd/senior/4ps/solo)
$filterGender = sanitize($_GET['gender']      ?? '');
$filterCivil  = sanitize($_GET['civil']       ?? '');
$filterVoter  = sanitize($_GET['voter']       ?? '');
$sort         = sanitize($_GET['sort']        ?? 'newest');
$tab          = sanitize($_GET['tab']         ?? 'active'); // active | transferred

// Whitelists — prevent invalid values leaking into queries
$validGender = ['Male','Female'];
$validCivil  = ['Single','Married','Widowed','Separated','Annulled'];
$validVoter  = ['Registered','Not Registered'];
if (!in_array($filterGender, $validGender)) $filterGender = '';
if (!in_array($filterCivil,  $validCivil))  $filterCivil  = '';
if (!in_array($filterVoter,  $validVoter))  $filterVoter  = '';

$sortOptions = [
    'newest'  => 'r.created_at DESC',
    'oldest'  => 'r.created_at ASC',
    'name_az' => 'r.last_name ASC, r.first_name ASC, r.middle_name ASC',
    'name_za' => 'r.last_name DESC, r.first_name DESC',
];
$orderBy = $sortOptions[$sort] ?? $sortOptions['newest'];

$perPage = 10;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

// Count for tab badges
$transferredCount  = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND resident_status IN ('Transferred','Pending Transfer')")->fetchColumn();
$pendingTransferCount = (int)$db->query("
    SELECT COUNT(*) FROM resident_transfer_requests
    WHERE destination_barangay_id=$bid AND status IN ('pending','approved')
")->fetchColumn();
$activeCount       = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND resident_status NOT IN ('Inactive','Deceased','Transferred','Pending Transfer')")->fetchColumn();

if ($tab === 'transferred') {
    // ── Transferred + Pending Transfer residents tab ───────────────────
    $baseWhere = "FROM residents r LEFT JOIN addresses a ON r.address_id=a.id WHERE r.barangay_id=$bid AND r.resident_status IN ('Transferred','Pending Transfer')";
    if ($search) {
        $s = $db->quote('%' . $search . '%');
        $baseWhere .= " AND (
            r.first_name  LIKE $s OR
            r.last_name   LIKE $s OR
            r.middle_name LIKE $s OR
            CONCAT(r.first_name,' ',r.last_name)                        LIKE $s OR
            CONCAT(r.last_name,' ',r.first_name)                        LIKE $s OR
            CONCAT(r.first_name,' ',r.middle_name,' ',r.last_name)      LIKE $s OR
            CONCAT(r.last_name,', ',r.first_name,' ',r.middle_name)     LIKE $s OR
            CONCAT(r.last_name,', ',r.first_name)                       LIKE $s
        )";
    }
} else {
    // ── Active residents tab (default) ────────────────────────────────
    $baseWhere = "FROM residents r LEFT JOIN addresses a ON r.address_id=a.id WHERE r.barangay_id=$bid";
    if ($search) {
        $s = $db->quote('%' . $search . '%');
        $baseWhere .= " AND (
            r.first_name  LIKE $s OR
            r.last_name   LIKE $s OR
            r.middle_name LIKE $s OR
            CONCAT(r.first_name,' ',r.last_name)                        LIKE $s OR
            CONCAT(r.last_name,' ',r.first_name)                        LIKE $s OR
            CONCAT(r.first_name,' ',r.middle_name,' ',r.last_name)      LIKE $s OR
            CONCAT(r.last_name,', ',r.first_name,' ',r.middle_name)     LIKE $s OR
            CONCAT(r.last_name,', ',r.first_name)                       LIKE $s
        )";
    }
    // Tag filters
    if ($filter === 'pwd')    $baseWhere .= " AND r.is_pwd=1";
    elseif ($filter === 'senior') $baseWhere .= " AND r.is_senior=1";
    elseif ($filter === '4ps')    $baseWhere .= " AND r.is_4ps=1";
    elseif ($filter === 'solo')   $baseWhere .= " AND r.is_solo_parent=1";
    // Additional filters (whitelisted above)
    if ($filterGender) $baseWhere .= " AND r.gender=" . $db->quote($filterGender);
    if ($filterCivil)  $baseWhere .= " AND r.civil_status=" . $db->quote($filterCivil);
    if ($filterVoter)  $baseWhere .= " AND r.voter_status=" . $db->quote($filterVoter);
    $baseWhere .= " AND r.resident_status NOT IN ('Inactive','Deceased','Transferred','Pending Transfer')";
}

// Count active filters for badge
$activeFilterCount = (int)(bool)$filter + (int)(bool)$filterGender + (int)(bool)$filterCivil + (int)(bool)$filterVoter;

$totalCount = (int)$db->query("SELECT COUNT(*) $baseWhere")->fetchColumn();
$totalPages = max(1, (int)ceil($totalCount / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$query = "SELECT r.*, a.purok, a.street, a.zone, a.barangay as addr_barangay $baseWhere ORDER BY $orderBy LIMIT $perPage OFFSET $offset";
$residents = $db->query($query)->fetchAll();

// Fetch active households for the "Family Member" household dropdown in the Add modal
$activeHouseholdsStmt = $db->prepare(
    "SELECT h.id, h.household_code,
            CONCAT(r.last_name, ', ', r.first_name) AS head_name
     FROM households h
     LEFT JOIN residents r ON h.head_resident_id = r.id
     WHERE h.barangay_id = ? AND h.status = 'Active'
     ORDER BY h.household_code ASC"
);
$activeHouseholdsStmt->execute([$bid]);
$activeHouseholds = $activeHouseholdsStmt->fetchAll();

$flash = getFlash('res');

// ── Fetch incoming transfer requests for THIS barangay (destination) ──────
// Pending = need approve/reject decision
$incomingPendingTransfers = $db->query("
    SELECT rtr.*,
           b.barangay_name AS origin_name,
           CONCAT(u.first_name,' ',u.last_name) AS requester_name
    FROM resident_transfer_requests rtr
    LEFT JOIN barangays b ON rtr.origin_barangay_id = b.id
    LEFT JOIN users u ON rtr.requester_user_id = u.id
    WHERE rtr.destination_barangay_id = $bid
      AND rtr.status = 'pending'
    ORDER BY rtr.created_at ASC
")->fetchAll();

// Approved = waiting for physical arrival confirmation
$incomingApprovedTransfers = $db->query("
    SELECT rtr.*,
           b.barangay_name AS origin_name,
           res.first_name, res.middle_name, res.last_name,
           res.gender, res.birthdate, res.age, res.civil_status,
           res.contact_number, res.voter_status,
           res.is_4ps, res.is_pwd, res.is_senior, res.is_solo_parent
    FROM resident_transfer_requests rtr
    LEFT JOIN barangays b  ON rtr.origin_barangay_id = b.id
    LEFT JOIN residents res ON rtr.resident_id = res.id
    WHERE rtr.destination_barangay_id = $bid
      AND rtr.status = 'approved'
    ORDER BY rtr.reviewed_at ASC
")->fetchAll();
$pageTitle = '';
?>

<?php
$pageIcon     = 'bi-people-fill';
$pageTitle    = 'Resident Management';
$pageSubtitle = 'Manage and monitor all registered residents in your barangay.';
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
        /* ── Autocomplete wrapper ── */
        .ac-wrapper {
            position: relative;
            /* must NOT have overflow:hidden so the dropdown escapes */
        }

        /* The dropdown attaches to .ac-wrapper but is rendered in a fixed
           portal so it is never clipped by overflow:hidden ancestors.
           We position it via JS (see initAutocomplete). */
        .ac-list {
            position: fixed;          /* escape all overflow:hidden parents  */
            background: #fff;
            /* top border blends with the input — no gap, no double border */
            border: 1px solid #3a7fa8;
            border-top: none;         /* seamlessly connected to the input   */
            border-radius: 0 0 8px 8px;
            box-shadow: 0 6px 18px rgba(0,0,0,0.13);
            z-index: 99999;           /* above everything                    */
            max-height: 220px;
            overflow-y: auto;
            display: none;
            font-size: 0.84rem;
        }
        /* Active search input — bottom radius removed so it merges with list */
        .ac-wrapper.ac-open .form-control {
            border-color: #3a7fa8 !important;
            border-bottom-left-radius: 0 !important;
            border-bottom-right-radius: 0 !important;
            border-bottom-color: transparent !important;
            outline: none !important;
            box-shadow: none !important;
        }
        .ac-wrapper.ac-open .input-group-text:first-child {
            border-color: #3a7fa8 !important;
            border-bottom-color: transparent !important;
            border-bottom-left-radius: 0 !important;
        }
        .ac-wrapper.ac-open .input-group-text:last-child {
            border-color: #3a7fa8 !important;
            border-bottom-color: transparent !important;
            border-bottom-right-radius: 0 !important;
        }
        /* Remove Bootstrap focus glow on the search input always */
        #residentSearchInput:focus,
        #transferSearchInput:focus {
            outline: none !important;
            box-shadow: none !important;
            border-color: #dee2e6 !important;
        }
        /* Only show blue border when dropdown is open */
        .ac-wrapper.ac-open #residentSearchInput,
        .ac-wrapper.ac-open #transferSearchInput {
            border-color: #3a7fa8 !important;
        }
        .ac-list .ac-item {
            padding: 8px 14px;
            cursor: pointer;
            color: #212529;
            border-bottom: 1px solid #f3f4f6;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .ac-list .ac-item:last-child { border-bottom: none; }
        .ac-list .ac-item:hover,
        .ac-list .ac-item.ac-active { background: #1a4f7a; color: #fff; }
    </style>
<body>
<?php include __DIR__ . '/../includes/sidebar_barangay.php'; ?>
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<div class="main-content">
    

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show">
            <?= $flash['message'] ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- ── Resident tabs ──────────────────────────────────────────────── -->
    <ul class="nav nav-tabs mb-3" style="border-bottom:2px solid #e5e7eb;">
        <li class="nav-item">
            <a class="nav-link d-flex align-items-center gap-2 <?= $tab !== 'transferred' ? 'active' : '' ?>"
               href="?tab=active&sort=<?= urlencode($sort) ?>"
               style="<?= $tab !== 'transferred' ? 'color:#1a4f7a;border-bottom:2px solid #1a4f7a;font-weight:600;' : 'color:#6b7280;' ?>font-size:0.85rem;">
                <i class="bi bi-people-fill"></i> Active Residents
                <span class="badge rounded-pill ms-1" style="background:<?= $tab !== 'transferred' ? '#1a4f7a' : '#e5e7eb' ?>;color:<?= $tab !== 'transferred' ? '#fff' : '#6b7280' ?>;font-size:0.68rem;"><?= $activeCount ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link d-flex align-items-center gap-2 <?= $tab === 'transferred' ? 'active' : '' ?>"
               href="?tab=transferred&sort=<?= urlencode($sort) ?>"
               style="<?= $tab === 'transferred' ? 'color:#d97706;border-bottom:2px solid #d97706;font-weight:600;' : 'color:#6b7280;' ?>font-size:0.85rem;">
                <i class="bi bi-arrow-left-right"></i> Transferred
                <?php if ($transferredCount > 0): ?>
                <span class="badge rounded-pill ms-1" style="background:<?= $tab === 'transferred' ? '#d97706' : '#fef3c7' ?>;color:<?= $tab === 'transferred' ? '#fff' : '#92400e' ?>;font-size:0.68rem;"><?= $transferredCount ?></span>
                <?php endif; ?>
                <?php if ($pendingTransferCount > 0): ?>
                <span class="badge rounded-pill ms-1" style="background:#dc2626;color:#fff;font-size:0.68rem;" title="<?= $pendingTransferCount ?> transfer(s) need attention"><?= $pendingTransferCount ?> action needed</span>
                <?php endif; ?>
            </a>
        </li>
    </ul>

    <?php if ($tab === 'transferred'): ?>
    <!-- ── Transferred Residents Search + Table ────────────────────────── -->

    <?php if (!empty($incomingPendingTransfers)): ?>
    <!-- ── Incoming Transfer Requests — need approval ───────────────── -->
    <div class="card border-0 mb-4" style="border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(220,38,38,0.12);border:1.5px solid #fecaca !important;">
        <div style="background:linear-gradient(90deg,#dc2626,#ef4444);padding:14px 20px 10px;display:flex;align-items:center;gap:10px;">
            <i class="bi bi-exclamation-triangle-fill text-white" style="font-size:1.2rem;"></i>
            <div class="flex-grow-1">
                <div class="fw-bold text-white" style="font-size:0.9rem;">
                    <?= count($incomingPendingTransfers) ?> Incoming Transfer Request<?= count($incomingPendingTransfers) > 1 ? 's' : '' ?> — Action Required
                </div>
                <div class="text-white-50 small">These residents are requesting to transfer to your barangay. Approve or reject each one.</div>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table mb-0" style="font-size:0.84rem;">
                <thead>
                    <tr style="background:#fef2f2;border-bottom:1px solid #fecaca;">
                        <th class="ps-4 py-2" style="font-size:0.7rem;font-weight:700;color:#b91c1c;text-transform:uppercase;letter-spacing:.06em;border:none;">Resident</th>
                        <th class="py-2" style="font-size:0.7rem;font-weight:700;color:#b91c1c;text-transform:uppercase;letter-spacing:.06em;border:none;">From Barangay</th>
                        <th class="py-2" style="font-size:0.7rem;font-weight:700;color:#b91c1c;text-transform:uppercase;letter-spacing:.06em;border:none;">Requested By</th>
                        <th class="py-2" style="font-size:0.7rem;font-weight:700;color:#b91c1c;text-transform:uppercase;letter-spacing:.06em;border:none;">Date Requested</th>
                        <th class="pe-4 py-2" style="font-size:0.7rem;font-weight:700;color:#b91c1c;text-transform:uppercase;letter-spacing:.06em;border:none;width:220px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($incomingPendingTransfers as $itr): ?>
                <tr style="border-bottom:1px solid #fee2e2;">
                    <td class="ps-4 py-3" style="border:none;">
                        <div class="fw-semibold"><?= htmlspecialchars($itr['resident_name']) ?></div>
                    </td>
                    <td class="py-3" style="border:none;">
                        <span style="background:#fee2e2;color:#b91c1c;font-size:0.75rem;font-weight:600;padding:3px 9px;border-radius:20px;">
                            <i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($itr['origin_name'] ?? '—') ?>
                        </span>
                    </td>
                    <td class="py-3 small text-muted" style="border:none;"><?= htmlspecialchars($itr['requester_name'] ?? '—') ?></td>
                    <td class="py-3 small text-muted" style="border:none;"><?= date('M d, Y', strtotime($itr['created_at'])) ?></td>
                    <td class="pe-4 py-3" style="border:none;">
                        <div class="d-flex gap-2">
                            <!-- Approve -->
                            <form method="POST" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="approve_transfer">
                                <input type="hidden" name="transfer_id" value="<?= $itr['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-success fw-semibold"
                                        style="border-radius:7px;font-size:0.75rem;"
                                        onclick="return confirm('Approve transfer of <?= htmlspecialchars(addslashes($itr['resident_name'])) ?> to your barangay?')">
                                    <i class="bi bi-check-lg me-1"></i>Approve
                                </button>
                            </form>
                            <!-- Reject -->
                            <button type="button" class="btn btn-sm btn-outline-danger fw-semibold"
                                    style="border-radius:7px;font-size:0.75rem;"
                                    onclick="openRejectTransferModal(<?= $itr['id'] ?>, '<?= htmlspecialchars(addslashes($itr['resident_name'])) ?>')">
                                <i class="bi bi-x-lg me-1"></i>Reject
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($incomingApprovedTransfers)): ?>
    <!-- ── Approved Transfers — Awaiting Physical Arrival ───────────── -->
    <div class="card border-0 mb-4" style="border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(5,150,105,0.12);border:1.5px solid #6ee7b7 !important;">
        <div style="background:linear-gradient(90deg,#059669,#10b981);padding:14px 20px 10px;display:flex;align-items:center;gap:10px;">
            <i class="bi bi-person-walking text-white" style="font-size:1.2rem;"></i>
            <div class="flex-grow-1">
                <div class="fw-bold text-white" style="font-size:0.9rem;">
                    <?= count($incomingApprovedTransfers) ?> Approved Transfer<?= count($incomingApprovedTransfers) > 1 ? 's' : '' ?> — Awaiting Physical Arrival
                </div>
                <div class="text-white-50 small">Once the resident physically appears in your office, click <strong>Confirm Arrival</strong> to register them.</div>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table mb-0" style="font-size:0.84rem;">
                <thead>
                    <tr style="background:#ecfdf5;border-bottom:1px solid #a7f3d0;">
                        <th class="ps-4 py-2" style="font-size:0.7rem;font-weight:700;color:#065f46;text-transform:uppercase;letter-spacing:.06em;border:none;">Resident</th>
                        <th class="py-2" style="font-size:0.7rem;font-weight:700;color:#065f46;text-transform:uppercase;letter-spacing:.06em;border:none;">Gender / Age</th>
                        <th class="py-2" style="font-size:0.7rem;font-weight:700;color:#065f46;text-transform:uppercase;letter-spacing:.06em;border:none;">From Barangay</th>
                        <th class="py-2" style="font-size:0.7rem;font-weight:700;color:#065f46;text-transform:uppercase;letter-spacing:.06em;border:none;">Approved On</th>
                        <th class="pe-4 py-2" style="font-size:0.7rem;font-weight:700;color:#065f46;text-transform:uppercase;letter-spacing:.06em;border:none;width:200px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($incomingApprovedTransfers as $atr): ?>
                <?php $atrName = trim("{$atr['first_name']} {$atr['middle_name']} {$atr['last_name']}"); ?>
                <tr style="border-bottom:1px solid #d1fae5;">
                    <td class="ps-4 py-3" style="border:none;">
                        <div class="fw-semibold"><?= htmlspecialchars($atrName) ?></div>
                        <div class="text-muted" style="font-size:0.72rem;"><?= htmlspecialchars($atr['civil_status'] ?? '') ?></div>
                    </td>
                    <td class="py-3" style="border:none;">
                        <div style="font-size:0.82rem;"><?= htmlspecialchars($atr['gender'] ?? '—') ?></div>
                        <div class="text-muted" style="font-size:0.72rem;"><?= $atr['age'] ?? '—' ?> yrs</div>
                    </td>
                    <td class="py-3" style="border:none;">
                        <span style="background:rgba(5,150,105,0.1);color:#065f46;font-size:0.75rem;font-weight:600;padding:3px 9px;border-radius:20px;">
                            <i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($atr['origin_name'] ?? '—') ?>
                        </span>
                    </td>
                    <td class="py-3 small text-muted" style="border:none;">
                        <?= $atr['reviewed_at'] ? date('M d, Y', strtotime($atr['reviewed_at'])) : '—' ?>
                    </td>
                    <td class="pe-4 py-3" style="border:none;">
                        <form method="POST"
                              onsubmit="return confirm('Confirm that <?= htmlspecialchars(addslashes($atrName)) ?> has physically appeared in your barangay office? This will add them as a new resident.');">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="confirm_transfer_arrival">
                            <input type="hidden" name="transfer_id" value="<?= $atr['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-success fw-semibold w-100"
                                    style="border-radius:7px;font-size:0.75rem;">
                                <i class="bi bi-person-check-fill me-1"></i>Confirm Arrival
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <div class="card mb-3">
        <div class="card-body py-2">
            <form method="GET" class="d-flex gap-2 flex-wrap align-items-center">
                <input type="hidden" name="tab" value="transferred">
                <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
                <div class="input-group input-group-sm ac-wrapper" style="width:280px;">
                    <span class="input-group-text bg-white border-end-0" style="border-color:#dee2e6;">
                        <i class="bi bi-search text-muted" style="font-size:0.8rem;"></i>
                    </span>
                    <input type="text" name="search" id="transferSearchInput"
                           class="form-control form-control-sm border-start-0 ps-0"
                           placeholder="Search transferred resident..."
                           autocomplete="off"
                           value="<?= htmlspecialchars($search) ?>"
                           style="border-color:#dee2e6;">
                    <div class="ac-list" id="transferAcList"></div>
                </div>
            </form>
        </div>
    </div>

    <div style="background:#fff;border-radius:14px;box-shadow:0 1px 8px rgba(0,0,0,0.06);border:1px solid #eaecf0;overflow:hidden;">
        <div class="table-responsive">
            <table class="table mb-0" style="font-size:0.84rem;border-collapse:separate;border-spacing:0;">
                <thead>
                    <tr style="background:#fef3c7;border-bottom:1px solid #fde68a;">
                        <th style="padding:11px 16px;border:none;font-size:0.7rem;font-weight:600;color:#92400e;text-transform:uppercase;letter-spacing:.05em;">Resident</th>
                        <th style="padding:11px 16px;border:none;font-size:0.7rem;font-weight:600;color:#92400e;text-transform:uppercase;letter-spacing:.05em;">Gender &amp; Age</th>
                        <th style="padding:11px 16px;border:none;font-size:0.7rem;font-weight:600;color:#92400e;text-transform:uppercase;letter-spacing:.05em;">Transferred To</th>
                        <th style="padding:11px 16px;border:none;font-size:0.7rem;font-weight:600;color:#92400e;text-transform:uppercase;letter-spacing:.05em;">Type</th>
                        <th style="padding:11px 16px;border:none;font-size:0.7rem;font-weight:600;color:#92400e;text-transform:uppercase;letter-spacing:.05em;">Reason</th>
                        <th style="padding:11px 16px;border:none;font-size:0.7rem;font-weight:600;color:#92400e;text-transform:uppercase;letter-spacing:.05em;">Date Recorded</th>
                        <th style="padding:11px 18px 11px 16px;border:none;font-size:0.7rem;font-weight:600;color:#92400e;text-transform:uppercase;letter-spacing:.05em;text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($residents)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-5" style="border:none;color:#9ca3af;">
                            <i class="bi bi-arrow-left-right" style="font-size:2.2rem;display:block;margin-bottom:10px;opacity:.3;"></i>
                            No transferred residents found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($residents as $r): ?>
                    <?php
                        $fullName = $r['last_name'].', '.$r['first_name'].($r['middle_name'] ? ' '.$r['middle_name'][0].'.' : '').($r['suffix'] ? ' '.$r['suffix'] : '');
                    ?>
                    <tr style="border-bottom:1px solid #f3f4f6;">
                        <!-- Avatar + Name -->
                        <td style="padding:13px 16px;border:none;vertical-align:middle;min-width:200px;">
                            <div class="d-flex align-items-center gap-3">
                                <?php if (!empty($r['photo']) && file_exists(__DIR__.'/../uploads/residents/'.$r['photo'])): ?>
                                    <img src="<?= BASE_URL ?>/uploads/residents/<?= htmlspecialchars($r['photo']) ?>"
                                         style="width:38px;height:38px;border-radius:50%;object-fit:cover;flex-shrink:0;border:1.5px solid #fde68a;" alt="">
                                <?php else: ?>
                                    <div style="width:38px;height:38px;border-radius:50%;background:#fef3c7;display:flex;align-items:center;justify-content:center;flex-shrink:0;border:1.5px solid #fde68a;">
                                        <span style="font-size:0.7rem;font-weight:700;color:#92400e;">
                                            <?= strtoupper(substr($r['first_name'],0,1).substr($r['last_name'],0,1)) ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                                <div>
                                    <div style="font-weight:600;color:#111827;font-size:0.84rem;"><?= htmlspecialchars($fullName) ?></div>
                                    <div style="font-size:0.72rem;color:#9ca3af;"><?= htmlspecialchars($r['occupation_status']) ?></div>
                                </div>
                            </div>
                        </td>
                        <!-- Gender & Age -->
                        <td style="padding:13px 16px;border:none;vertical-align:middle;white-space:nowrap;">
                            <div class="d-flex align-items-center gap-2">
                                <span style="width:28px;height:28px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;background:<?= $r['gender']==='Male'?'#eff6ff':'#fdf2f8' ?>;flex-shrink:0;">
                                    <i class="bi <?= $r['gender']==='Male'?'bi-gender-male':'bi-gender-female' ?>"
                                       style="font-size:0.8rem;color:<?= $r['gender']==='Male'?'#3b82f6':'#ec4899' ?>;"></i>
                                </span>
                                <div>
                                    <div style="font-size:0.82rem;color:#374151;font-weight:500;"><?= $r['gender'] ?></div>
                                    <div style="font-size:0.71rem;color:#9ca3af;"><?= $r['age'] ?> yrs</div>
                                </div>
                            </div>
                        </td>
                        <!-- Transferred To -->
                        <td style="padding:13px 16px;border:none;vertical-align:middle;">
                            <?php
                            // Detect outside: either flag is set OR old data stored the string
                            $isOutside = !empty($r['transfer_outside'])
                                || stripos($r['transfer_to_barangay'] ?? '', 'outside') !== false;
                            $destLabel = $isOutside
                                ? 'Outside Talavera'
                                : ($r['transfer_to_barangay'] ?? '');
                            ?>
                            <div class="d-flex align-items-start gap-1">
                                <i class="bi bi-geo-alt-fill" style="color:#d97706;font-size:0.78rem;margin-top:2px;flex-shrink:0;"></i>
                                <span style="font-size:0.82rem;color:#374151;font-weight:500;">
                                    <?= $destLabel !== '' ? htmlspecialchars($destLabel) : '<span style="color:#d1d5db;">—</span>' ?>
                                </span>
                            </div>
                            <?php if (!empty($r['transfer_from_barangay'])): ?>
                            <div style="font-size:0.71rem;color:#9ca3af;margin-top:1px;">
                                From: <?= htmlspecialchars($r['transfer_from_barangay']) ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <!-- Transfer Type -->
                        <td style="padding:13px 16px;border:none;vertical-align:middle;white-space:nowrap;">
                            <?php if ($r['resident_status'] === 'Pending Transfer'): ?>
                                <span style="background:#fef3c7;color:#92400e;font-size:0.72rem;font-weight:600;padding:3px 8px;border-radius:20px;">
                                    <i class="bi bi-hourglass-split me-1"></i>Pending Approval
                                </span>
                            <?php elseif ($isOutside): ?>
                                <span style="background:#fee2e2;color:#b91c1c;font-size:0.72rem;font-weight:600;padding:3px 8px;border-radius:20px;">
                                    <i class="bi bi-box-arrow-right me-1"></i>Outside Talavera
                                </span>
                            <?php else: ?>
                                <span style="background:#e0f2fe;color:#0369a1;font-size:0.72rem;font-weight:600;padding:3px 8px;border-radius:20px;">
                                    <i class="bi bi-arrow-left-right me-1"></i>Within Talavera
                                </span>
                            <?php endif; ?>
                        </td>
                        <!-- Reason -->
                        <td style="padding:13px 16px;border:none;vertical-align:middle;max-width:180px;">
                            <span style="font-size:0.81rem;color:#6b7280;overflow:hidden;text-overflow:ellipsis;display:block;white-space:nowrap;max-width:170px;" title="<?= htmlspecialchars($r['transfer_reason'] ?? '') ?>">
                                <?= !empty($r['transfer_reason']) ? htmlspecialchars($r['transfer_reason']) : '<span style="color:#d1d5db;">—</span>' ?>
                            </span>
                        </td>
                        <!-- Date -->
                        <td style="padding:13px 16px;border:none;vertical-align:middle;white-space:nowrap;">
                            <span style="font-size:0.81rem;color:#6b7280;">
                                <?= date('M d, Y', strtotime($r['created_at'])) ?>
                            </span>
                        </td>
                        <!-- Actions -->
                        <td style="padding:13px 18px 13px 16px;border:none;vertical-align:middle;text-align:right;white-space:nowrap;">
                            <div class="d-inline-flex gap-1">
                                <a href="<?= BASE_URL ?>/barangay_admin/view_resident.php?id=<?= $r['id'] ?>"
                                   class="btn btn-sm btn-outline-primary" title="View" style="font-size:0.75rem;">
                                    <i class="bi bi-eye me-1"></i>View
                                </a>
                                <a href="<?= BASE_URL ?>/barangay_admin/edit_resident.php?id=<?= $r['id'] ?>"
                                   class="btn btn-sm btn-outline-secondary" title="Edit" style="font-size:0.75rem;">
                                    <i class="bi bi-pencil me-1"></i>Edit
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php else: ?>
    <!-- ── Active Residents Search + Filter Card ──────────────────────── -->
    <div class="card mb-3">
        <div class="card-body py-2">
            <div class="d-flex gap-2 flex-wrap align-items-center">
                <!-- Search & controls (GET form) -->
                <form method="GET" class="d-flex gap-2 flex-wrap align-items-center flex-fill" id="searchForm">

                    <!-- Hidden fields so all params travel together -->
                    <input type="hidden" name="filter"  value="<?= htmlspecialchars($filter) ?>">
                    <input type="hidden" name="gender"  value="<?= htmlspecialchars($filterGender) ?>">
                    <input type="hidden" name="civil"   value="<?= htmlspecialchars($filterCivil) ?>">
                    <input type="hidden" name="voter"   value="<?= htmlspecialchars($filterVoter) ?>">
                    <input type="hidden" name="sort"    value="<?= htmlspecialchars($sort) ?>">

                    <!-- Search — inline autocomplete -->
                    <div class="input-group input-group-sm ac-wrapper" style="width:260px;">
                        <span class="input-group-text bg-white border-end-0" style="border-color:#dee2e6;">
                            <i class="bi bi-search text-muted" style="font-size:0.8rem;"></i>
                        </span>
                        <input type="text" name="search" id="residentSearchInput"
                               class="form-control form-control-sm border-start-0 border-end-0 ps-0"
                               placeholder="Search name..."
                               autocomplete="off"
                               value="<?= htmlspecialchars($search) ?>"
                               style="border-color:#dee2e6;">
                        <span class="input-group-text bg-white border-start-0 p-0" style="border-color:#dee2e6;">
                            <span id="residentSearchClear" style="width:28px;height:28px;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;color:#9ca3af;border-radius:4px;<?= $search ? '' : 'visibility:hidden;' ?>"
                                  title="Clear search"
                                  onmouseover="this.style.color='#dc2626';this.style.background='#fee2e2';"
                                  onmouseout="this.style.color='#9ca3af';this.style.background='transparent';">
                                <i class="bi bi-x-lg" style="font-size:0.75rem;"></i>
                            </span>
                        </span>
                        <div class="ac-list" id="residentAcList"></div>
                    </div>

                    <!-- Filters toggle button -->
                    <button type="button"
                            class="btn btn-sm <?= $activeFilterCount ? 'btn-primary' : 'btn-outline-secondary' ?> position-relative"
                            onclick="toggleFilterPanel()"
                            id="filterToggleBtn"
                            title="Filters">
                        <i class="bi bi-funnel-fill me-1"></i>Filters
                        <?php if ($activeFilterCount): ?>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
                                  style="font-size:0.6rem;"><?= $activeFilterCount ?></span>
                        <?php endif; ?>
                    </button>

                    <!-- Sort -->
                    <div class="input-group input-group-sm" style="width:190px;">
                        <span class="input-group-text bg-white border-end-0" style="border-color:#dee2e6;" title="Sort order">
                            <i class="bi bi-arrow-down-up" style="font-size:0.8rem;color:#3a7fa8;"></i>
                        </span>
                        <select name="sort" class="form-select form-select-sm border-start-0"
                                style="border-color:#dee2e6;"
                                onchange="this.form.submit()">
                            <option value="newest"  <?= $sort==='newest'  ?'selected':'' ?>>Newest First</option>
                            <option value="oldest"  <?= $sort==='oldest'  ?'selected':'' ?>>Oldest First</option>
                            <option value="name_az" <?= $sort==='name_az' ?'selected':'' ?>>Name A–Z</option>
                            <option value="name_za" <?= $sort==='name_za' ?'selected':'' ?>>Name Z–A</option>
                        </select>
                    </div>

                </form>

                <!-- Action buttons -->
                <div class="d-flex gap-2 ms-auto">
                    <button type="button" id="downloadExcelBtn"
                            class="btn btn-sm btn-outline-success fw-semibold"
                            onclick="downloadSelectedExcel('selected')"
                            disabled
                            title="Download selected residents as Excel">
                        <i class="bi bi-file-earmark-excel me-1"></i>
                        Download Selected
                        <span id="selectedCountBadge" class="badge bg-success ms-1" style="display:none;font-size:0.65rem;">0</span>
                    </button>
                    <button type="button" id="downloadAllPagesBtn"
                            class="btn btn-sm btn-outline-primary fw-semibold"
                            onclick="downloadSelectedExcel('all')"
                            title="Download ALL residents across all pages (ignores selection)">
                        <i class="bi bi-file-earmark-excel-fill me-1"></i>
                        Download All
                    </button>
                    <a href="<?= BASE_URL ?>/barangay_admin/add_resident.php"
                       class="btn btn-sm btn-success fw-semibold">
                        <i class="bi bi-person-plus-fill me-1"></i>Add Resident
                    </a>
                </div>
            </div>

            <!-- ── Collapsible filter panel ───────────────────────────────── -->
            <div id="filterPanel" style="display:<?= $activeFilterCount ? 'block' : 'none' ?>;">
                <hr class="my-2">
                <form method="GET" id="filterForm" class="row g-2 align-items-end">
                    <!-- Carry search & sort through this form too -->
                    <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                    <input type="hidden" name="sort"   value="<?= htmlspecialchars($sort) ?>">

                    <!-- Tags -->
                    <div class="col-auto">
                        <label class="form-label mb-1" style="font-size:0.72rem;font-weight:700;color:#3a7fa8;text-transform:uppercase;letter-spacing:.04em;">
                            <i class="bi bi-tag-fill me-1"></i>Tag
                        </label>
                        <select name="filter" class="form-select form-select-sm" style="width:150px;">
                            <option value="">All Tags</option>
                            <option value="pwd"    <?= $filter==='pwd'    ?'selected':'' ?>>PWD</option>
                            <option value="senior" <?= $filter==='senior' ?'selected':'' ?>>Senior Citizen</option>
                            <option value="4ps"    <?= $filter==='4ps'    ?'selected':'' ?>>4Ps Beneficiary</option>
                            <option value="solo"   <?= $filter==='solo'   ?'selected':'' ?>>Solo Parent</option>
                        </select>
                    </div>

                    <!-- Gender -->
                    <div class="col-auto">
                        <label class="form-label mb-1" style="font-size:0.72rem;font-weight:700;color:#3a7fa8;text-transform:uppercase;letter-spacing:.04em;">
                            <i class="bi bi-gender-ambiguous me-1"></i>Gender
                        </label>
                        <select name="gender" class="form-select form-select-sm" style="width:130px;">
                            <option value="">All Genders</option>
                            <option value="Male"   <?= $filterGender==='Male'   ?'selected':'' ?>>Male</option>
                            <option value="Female" <?= $filterGender==='Female' ?'selected':'' ?>>Female</option>
                        </select>
                    </div>

                    <!-- Civil Status -->
                    <div class="col-auto">
                        <label class="form-label mb-1" style="font-size:0.72rem;font-weight:700;color:#3a7fa8;text-transform:uppercase;letter-spacing:.04em;">
                            <i class="bi bi-heart-fill me-1"></i>Civil Status
                        </label>
                        <select name="civil" class="form-select form-select-sm" style="width:145px;">
                            <option value="">All Statuses</option>
                            <option value="Single"    <?= $filterCivil==='Single'    ?'selected':'' ?>>Single</option>
                            <option value="Married"   <?= $filterCivil==='Married'   ?'selected':'' ?>>Married</option>
                            <option value="Widowed"   <?= $filterCivil==='Widowed'   ?'selected':'' ?>>Widowed</option>
                            <option value="Separated" <?= $filterCivil==='Separated' ?'selected':'' ?>>Separated</option>
                            <option value="Annulled"  <?= $filterCivil==='Annulled'  ?'selected':'' ?>>Annulled</option>
                        </select>
                    </div>

                    <!-- Voter Status -->
                    <div class="col-auto">
                        <label class="form-label mb-1" style="font-size:0.72rem;font-weight:700;color:#3a7fa8;text-transform:uppercase;letter-spacing:.04em;">
                            <i class="bi bi-check2-square me-1"></i>Voter Status
                        </label>
                        <select name="voter" class="form-select form-select-sm" style="width:165px;">
                            <option value="">All Voters</option>
                            <option value="Registered"     <?= $filterVoter==='Registered'     ?'selected':'' ?>>Registered</option>
                            <option value="Not Registered" <?= $filterVoter==='Not Registered' ?'selected':'' ?>>Not Registered</option>
                        </select>
                    </div>

                    <div class="col-auto d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-primary fw-semibold">
                            <i class="bi bi-funnel me-1"></i>Apply
                        </button>
                        <a href="?<?= $search ? 'search='.urlencode($search).'&' : '' ?>sort=<?= urlencode($sort) ?>"
                           class="btn btn-sm btn-outline-danger">
                            <i class="bi bi-x-lg me-1"></i>Reset Filters
                        </a>
                    </div>
                </form>

                <!-- Active filter pills -->
                <?php if ($activeFilterCount): ?>
                <div class="d-flex gap-2 flex-wrap mt-2">
                    <span class="text-muted" style="font-size:0.72rem;line-height:1.8;">Active:</span>
                    <?php
                    $filterLabels = [
                        'pwd'    => ['PWD',             'bg-purple',  'bi-person-wheelchair'],
                        'senior' => ['Senior Citizen',  'bg-warning text-dark', 'bi-person-fill'],
                        '4ps'    => ['4Ps Beneficiary', 'bg-success', 'bi-people-fill'],
                        'solo'   => ['Solo Parent',     'bg-info text-dark',    'bi-person-hearts'],
                    ];
                    if ($filter && isset($filterLabels[$filter])):
                        [$lbl, $cls, $ico] = $filterLabels[$filter];
                    ?>
                        <a href="?<?= http_build_query(array_filter(['search'=>$search,'gender'=>$filterGender,'civil'=>$filterCivil,'voter'=>$filterVoter,'sort'=>($sort!=='newest'?$sort:'')])) ?>"
                           class="badge <?= $cls ?> text-decoration-none d-flex align-items-center gap-1" style="font-size:0.72rem;padding:5px 8px;">
                            <i class="bi <?= $ico ?>"></i><?= $lbl ?> <i class="bi bi-x"></i>
                        </a>
                    <?php endif; ?>
                    <?php if ($filterGender): ?>
                        <a href="?<?= http_build_query(array_filter(['search'=>$search,'filter'=>$filter,'civil'=>$filterCivil,'voter'=>$filterVoter,'sort'=>($sort!=='newest'?$sort:'')])) ?>"
                           class="badge bg-primary text-decoration-none d-flex align-items-center gap-1" style="font-size:0.72rem;padding:5px 8px;">
                            <i class="bi bi-gender-ambiguous"></i><?= htmlspecialchars($filterGender) ?> <i class="bi bi-x"></i>
                        </a>
                    <?php endif; ?>
                    <?php if ($filterCivil): ?>
                        <a href="?<?= http_build_query(array_filter(['search'=>$search,'filter'=>$filter,'gender'=>$filterGender,'voter'=>$filterVoter,'sort'=>($sort!=='newest'?$sort:'')])) ?>"
                           class="badge bg-secondary text-decoration-none d-flex align-items-center gap-1" style="font-size:0.72rem;padding:5px 8px;">
                            <i class="bi bi-heart-fill"></i><?= htmlspecialchars($filterCivil) ?> <i class="bi bi-x"></i>
                        </a>
                    <?php endif; ?>
                    <?php if ($filterVoter): ?>
                        <a href="?<?= http_build_query(array_filter(['search'=>$search,'filter'=>$filter,'gender'=>$filterGender,'civil'=>$filterCivil,'sort'=>($sort!=='newest'?$sort:'')])) ?>"
                           class="badge bg-success text-decoration-none d-flex align-items-center gap-1" style="font-size:0.72rem;padding:5px 8px;">
                            <i class="bi bi-check2-square"></i><?= htmlspecialchars($filterVoter) ?> <i class="bi bi-x"></i>
                        </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <!-- ─────────────────────────────────────────────────────────── -->
        </div>
    </div>

    <!-- Residents Table -->
    <?php $actionBtnBase = 'background:none;border:none;padding:5px 7px;border-radius:7px;cursor:pointer;transition:background .15s;'; ?>
    <!-- Persistent selection bar — shown when IDs are stored across pages -->
    <div id="selectionBar" style="display:none;background:#eef6ff;border:1.5px solid #bfdbfe;border-radius:10px;padding:8px 16px;margin-bottom:10px;font-size:0.82rem;color:#1a4f7a;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <i class="bi bi-check2-square me-1"></i>
        <span id="selBarCount" style="font-weight:700;"></span> resident(s) selected across pages.
        <button type="button" onclick="clearAllSelection()" class="btn btn-sm btn-outline-danger ms-auto" style="font-size:0.72rem;padding:2px 10px;">
            <i class="bi bi-x-lg me-1"></i>Clear All
        </button>
    </div>
    <div style="background:#fff;border-radius:14px;box-shadow:0 1px 8px rgba(0,0,0,0.06);border:1px solid #eaecf0;overflow:hidden;">
        <div class="table-responsive">
            <table class="table mb-0" id="residentsTable" style="font-size:0.84rem;border-collapse:separate;border-spacing:0;">
                <thead>
                    <tr style="background:#f9fafb;border-bottom:1px solid #eaecf0;">
                        <th style="width:44px;padding:11px 0 11px 18px;border:none;">
                            <input type="checkbox" class="form-check-input mt-0" id="selectAll" title="Select all" style="cursor:pointer;">
                        </th>
                        <th style="padding:11px 16px;border:none;font-size:0.7rem;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap;">
                            Resident
                        </th>
                        <th style="padding:11px 16px;border:none;font-size:0.7rem;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap;">
                            Gender &amp; Age
                        </th>
                        <th style="padding:11px 16px;border:none;font-size:0.7rem;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap;">
                            Civil Status
                        </th>
                        <th style="padding:11px 16px;border:none;font-size:0.7rem;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap;">
                            Address
                        </th>
                        <th style="padding:11px 16px;border:none;font-size:0.7rem;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap;">
                            Voter
                        </th>
                        <th style="padding:11px 16px;border:none;font-size:0.7rem;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap;">
                            Tags
                        </th>
                        <th style="padding:11px 18px 11px 16px;border:none;font-size:0.7rem;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;text-align:right;white-space:nowrap;">
                            Actions
                        </th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($residents)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-5" style="border:none;color:#9ca3af;">
                            <i class="bi bi-people" style="font-size:2.2rem;display:block;margin-bottom:10px;opacity:.3;"></i>
                            No residents found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($residents as $i => $r): ?>
                    <?php
                        $fullName   = $r['last_name'].', '.$r['first_name'].($r['middle_name'] ? ' '.$r['middle_name'][0].'.' : '').($r['suffix'] ? ' '.$r['suffix'] : '');
                        $addressStr = trim(($r['purok'] ? $r['purok'].', ' : '').($r['addr_barangay'] ?? ''));
                    ?>
                    <tr class="res-row" style="border-bottom:1px solid #f3f4f6;transition:background .12s;">
                        <!-- Checkbox -->
                        <td style="padding:13px 0 13px 18px;border:none;vertical-align:middle;">
                            <input type="checkbox" class="form-check-input mt-0 row-check" data-id="<?= $r['id'] ?>" style="cursor:pointer;">
                        </td>

                        <!-- Avatar + Name + Occupation -->
                        <td style="padding:13px 16px;border:none;vertical-align:middle;min-width:200px;">
                            <div class="d-flex align-items-center gap-3">
                                <?php if (!empty($r['photo']) && file_exists(__DIR__.'/../uploads/residents/'.$r['photo'])): ?>
                                    <img src="<?= BASE_URL ?>/uploads/residents/<?= htmlspecialchars($r['photo']) ?>"
                                         style="width:38px;height:38px;border-radius:50%;object-fit:cover;flex-shrink:0;border:1.5px solid #e5e7eb;" alt="">
                                <?php else: ?>
                                    <div style="width:38px;height:38px;border-radius:50%;background:<?= $r['gender']==='Female'?'#fce7f3':'#eff6ff' ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;border:1.5px solid <?= $r['gender']==='Female'?'#fbcfe8':'#bfdbfe' ?>;">
                                        <span style="font-size:0.7rem;font-weight:700;color:<?= $r['gender']==='Female'?'#db2777':'#2563eb' ?>;">
                                            <?= strtoupper(substr($r['first_name'],0,1).substr($r['last_name'],0,1)) ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                                <div style="min-width:0;">
                                    <div style="font-weight:600;color:#111827;font-size:0.84rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:200px;" title="<?= htmlspecialchars($fullName) ?>">
                                        <?= htmlspecialchars($fullName) ?>
                                    </div>
                                    <div style="font-size:0.72rem;color:#9ca3af;margin-top:1px;">
                                        <?= htmlspecialchars($r['occupation_status']) ?>
                                    </div>
                                </div>
                            </div>
                        </td>

                        <!-- Gender & Age -->
                        <td style="padding:13px 16px;border:none;vertical-align:middle;white-space:nowrap;">
                            <div class="d-flex align-items-center gap-2">
                                <span style="width:28px;height:28px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;background:<?= $r['gender']==='Male'?'#eff6ff':'#fdf2f8' ?>;flex-shrink:0;">
                                    <i class="bi <?= $r['gender']==='Male'?'bi-gender-male':'bi-gender-female' ?>"
                                       style="font-size:0.8rem;color:<?= $r['gender']==='Male'?'#3b82f6':'#ec4899' ?>;"></i>
                                </span>
                                <div>
                                    <div style="font-size:0.82rem;color:#374151;font-weight:500;"><?= $r['gender'] ?></div>
                                    <div style="font-size:0.71rem;color:#9ca3af;"><?= $r['age'] ?> yrs</div>
                                </div>
                            </div>
                        </td>

                        <!-- Civil Status -->
                        <td style="padding:13px 16px;border:none;vertical-align:middle;white-space:nowrap;">
                            <?php
                            $civilIcon  = ['Single'=>'bi-person','Married'=>'bi-people-fill','Widowed'=>'bi-heartbreak','Separated'=>'bi-person-dash','Annulled'=>'bi-slash-circle'];
                            $civilColor = ['Single'=>'#6b7280','Married'=>'#059669','Widowed'=>'#7c3aed','Separated'=>'#d97706','Annulled'=>'#dc2626'];
                            $ci = $civilIcon[$r['civil_status']] ?? 'bi-person';
                            $cc = $civilColor[$r['civil_status']] ?? '#6b7280';
                            ?>
                            <span class="d-flex align-items-center gap-1" style="color:<?= $cc ?>;font-size:0.82rem;font-weight:500;">
                                <i class="bi <?= $ci ?>" style="font-size:0.85rem;"></i>
                                <?= $r['civil_status'] ?>
                            </span>
                        </td>

                        <!-- Address -->
                        <td style="padding:13px 16px;border:none;vertical-align:middle;max-width:180px;">
                            <div class="d-flex align-items-start gap-1">
                                <i class="bi bi-geo-alt-fill" style="color:#9ca3af;font-size:0.78rem;margin-top:2px;flex-shrink:0;"></i>
                                <span style="font-size:0.81rem;color:#6b7280;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:155px;" title="<?= htmlspecialchars($addressStr) ?>">
                                    <?= $addressStr ? htmlspecialchars($addressStr) : '<span style="color:#d1d5db;">—</span>' ?>
                                </span>
                            </div>
                        </td>

                        <!-- Voter -->
                        <td style="padding:13px 16px;border:none;vertical-align:middle;white-space:nowrap;">
                            <?php if ($r['voter_status'] === 'Registered'): ?>
                                <span class="d-inline-flex align-items-center gap-1"
                                      style="background:#d1fae5;color:#065f46;font-size:0.72rem;font-weight:600;padding:3px 8px;border-radius:20px;">
                                    <i class="bi bi-check-circle-fill" style="font-size:0.72rem;"></i> Registered
                                </span>
                            <?php else: ?>
                                <span class="d-inline-flex align-items-center gap-1"
                                      style="background:#f3f4f6;color:#9ca3af;font-size:0.72rem;font-weight:500;padding:3px 8px;border-radius:20px;">
                                    <i class="bi bi-dash-circle" style="font-size:0.72rem;"></i> Not Registered
                                </span>
                            <?php endif; ?>
                        </td>

                        <!-- Tags -->
                        <td style="padding:13px 16px;border:none;vertical-align:middle;">
                            <div class="d-flex gap-1 flex-wrap">
                                <?php if (!$r['is_pwd'] && !$r['is_senior'] && !$r['is_4ps'] && !$r['is_solo_parent']): ?>
                                    <span style="color:#d1d5db;font-size:0.78rem;">—</span>
                                <?php endif; ?>
                                <?php if ($r['is_pwd']): ?>
                                    <span style="background:#f3e8ff;color:#7c3aed;font-size:0.68rem;font-weight:600;padding:2px 7px;border-radius:12px;white-space:nowrap;">
                                        <i class="bi bi-person-wheelchair me-1"></i>PWD
                                    </span>
                                <?php endif; ?>
                                <?php if ($r['is_senior']): ?>
                                    <span style="background:#fef3c7;color:#92400e;font-size:0.68rem;font-weight:600;padding:2px 7px;border-radius:12px;white-space:nowrap;">
                                        <i class="bi bi-person-fill me-1"></i>Senior
                                    </span>
                                <?php endif; ?>
                                <?php if ($r['is_4ps']): ?>
                                    <span style="background:#d1fae5;color:#065f46;font-size:0.68rem;font-weight:600;padding:2px 7px;border-radius:12px;white-space:nowrap;">
                                        <i class="bi bi-people-fill me-1"></i>4Ps
                                    </span>
                                <?php endif; ?>
                                <?php if ($r['is_solo_parent']): ?>
                                    <span style="background:#e0f2fe;color:#0369a1;font-size:0.68rem;font-weight:600;padding:2px 7px;border-radius:12px;white-space:nowrap;">
                                        <i class="bi bi-person-hearts me-1"></i>Solo
                                    </span>
                                <?php endif; ?>
                            </div>
                        </td>

                        <!-- Actions -->
                        <td style="padding:13px 18px 13px 16px;border:none;vertical-align:middle;text-align:right;white-space:nowrap;">
                            <div class="d-inline-flex gap-1">
                                <a href="<?= BASE_URL ?>/barangay_admin/view_resident.php?id=<?= $r['id'] ?>"
                                   class="res-action-btn"
                                   title="View resident info"
                                   style="<?= $actionBtnBase ?>color:#2563eb;text-decoration:none;">
                                    <i class="bi bi-eye" style="font-size:0.8rem;"></i>
                                </a>
                                <button class="res-action-btn"
                                        onclick="editResident(<?= htmlspecialchars(json_encode($r)) ?>)"
                                        title="Edit resident"
                                        style="<?= $actionBtnBase ?>color:#4b5563;">
                                    <i class="bi bi-pencil" style="font-size:0.8rem;"></i>
                                </button>
                                <button class="res-action-btn"
                                        onclick="openArchiveModal(<?= $r['id'] ?>, '<?= htmlspecialchars(addslashes($fullName)) ?>')"
                                        title="Archive"
                                        style="<?= $actionBtnBase ?>color:#dc2626;">
                                    <i class="bi bi-archive" style="font-size:0.8rem;"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; // end tab check ?>
</div>

<?php
// Build pagination query string (preserve search/filter/sort/tab)
$paginationBase = '?' . http_build_query(array_filter([
    'tab'    => ($tab !== 'active' ? $tab : ''),
    'search' => $search,
    'filter' => ($tab !== 'transferred' ? $filter : ''),
    'gender' => ($tab !== 'transferred' ? $filterGender : ''),
    'civil'  => ($tab !== 'transferred' ? $filterCivil : ''),
    'voter'  => ($tab !== 'transferred' ? $filterVoter : ''),
    'sort'   => ($sort !== 'newest' ? $sort : ''),
]));
$paginationBase .= $paginationBase === '?' ? '' : '&';
if ($paginationBase === '?') $paginationBase = '?';
$paginationLabel = 'resident';
include __DIR__ . '/../includes/pagination.php';
?>

<!-- (Resident add form moved to add_resident.php) -->

<!-- Hidden archive form -->
<form method="POST" id="archiveResidentForm" style="display:none;">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="archive">
    <input type="hidden" name="resident_id" id="archiveResidentId">
</form>

<!-- Reject Transfer Modal -->
<div class="modal fade" id="rejectTransferModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="reject_transfer">
            <input type="hidden" name="transfer_id" id="rejectTransferId" value="">
            <div class="modal-content" style="border-radius:14px;overflow:hidden;">
                <div class="d-flex">
                    <div class="d-flex flex-column align-items-center justify-content-center px-3 py-5 flex-shrink-0"
                         style="background:linear-gradient(160deg,#b91c1c,#dc2626);width:150px;min-width:150px;">
                        <i class="bi bi-x-circle-fill text-white" style="font-size:2rem;"></i>
                        <h6 class="fw-bold mt-3 mb-0 text-white text-center small">Reject Transfer</h6>
                    </div>
                    <div class="flex-fill p-4">
                        <div class="fw-semibold mb-1" style="font-size:0.88rem;">Rejecting transfer for:</div>
                        <div class="fw-bold mb-3" id="rejectTransferName" style="color:#b91c1c;"></div>
                        <label class="form-label fw-semibold small">Reason <span class="text-muted">(optional)</span></label>
                        <textarea name="reject_reason" class="form-control form-control-sm" rows="3"
                                  placeholder="Provide a reason for rejection..."></textarea>
                        <div class="alert alert-warning py-2 small mt-3 mb-0">
                            <i class="bi bi-info-circle me-1"></i>
                            The resident will be reverted to <strong>Active</strong> in the origin barangay.
                        </div>
                        <div class="d-flex gap-2 mt-3">
                            <button type="button" class="btn btn-outline-secondary flex-fill" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-danger flex-fill fw-semibold">
                                <i class="bi bi-x-circle me-1"></i>Confirm Reject
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Archive Confirm Modal -->
<div class="modal fade" id="archiveResidentModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px; overflow:hidden;">
            <div class="d-flex">
                <div class="d-flex flex-column align-items-center justify-content-center px-4 py-5"
                     style="background:linear-gradient(160deg,#1a4f7a 0%,#3a7fa8 100%); min-width:200px; width:200px; flex-shrink:0;">
                    <div style="width:72px;height:72px;background:rgba(255,255,255,0.15);border-radius:50%;display:flex;align-items:center;justify-content:center;border:3px solid rgba(255,255,255,0.3);">
                        <i class="bi bi-archive-fill text-white" style="font-size:2rem;"></i>
                    </div>
                    <h5 class="fw-bold mt-3 mb-1 text-white text-center" style="font-size:1rem;">Archive Resident</h5>
                    <p class="text-white-50 small text-center mb-0">This action is reversible</p>
                </div>
                <div class="flex-fill d-flex flex-column justify-content-between p-4">
                    <div>
                        <p class="text-muted small mb-2">You are about to archive:</p>
                        <p class="fw-bold fs-6 mb-3" id="archiveResidentName" style="color:#1a4f7a;"></p>
                        <div class="alert alert-warning py-2 small mb-0">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            The resident will be set to <strong>Inactive</strong> and moved to the archive. You can restore them anytime from the Archive section.
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-4">
                        <button type="button" class="btn btn-outline-secondary flex-fill" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary flex-fill fw-semibold" onclick="submitResidentArchive()">
                            <i class="bi bi-archive me-1"></i>Yes, Archive
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
const BASE_URL_JS = '<?= BASE_URL ?>';

// ── Sidebar / Filter toggles ──────────────────────────────────────────────
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('show'); }

// ── Reject Transfer Modal ─────────────────────────────────────────────────
function openRejectTransferModal(transferId, residentName) {
    document.getElementById('rejectTransferId').value          = transferId;
    document.getElementById('rejectTransferName').textContent  = residentName;
    new bootstrap.Modal(document.getElementById('rejectTransferModal')).show();
}

function toggleFilterPanel() {
    const panel = document.getElementById('filterPanel');
    const btn   = document.getElementById('filterToggleBtn');
    const open  = panel.style.display === 'none' || panel.style.display === '';
    panel.style.display = open ? 'block' : 'none';
    btn.classList.toggle('btn-primary', open);
    btn.classList.toggle('btn-outline-secondary', !open);
}

// ── Edit resident — navigate to edit page ────────────────────────────────
function editResident(r) {
    window.location.href = BASE_URL_JS + '/barangay_admin/edit_resident.php?id=' + r.id;
}

// ── Archive modal ─────────────────────────────────────────────────────────
function openArchiveModal(id, name) {
    document.getElementById('archiveResidentId').value        = id;
    document.getElementById('archiveResidentName').textContent = name;
    new bootstrap.Modal(document.getElementById('archiveResidentModal')).show();
}
function submitResidentArchive() {
    bootstrap.Modal.getInstance(document.getElementById('archiveResidentModal')).hide();
    // Sync CSRF token before submitting the hidden archive form
    const archForm = document.getElementById('archiveResidentForm');
    fetch('<?= BASE_URL ?>/ajax/get_csrf_token.php', {
        method: 'GET', credentials: 'same-origin', cache: 'no-store'
    })
    .then(r => r.json())
    .then(data => {
        const f = archForm.querySelector('input[name="csrf_token"]');
        if (f && data.csrf_token) f.value = data.csrf_token;
    })
    .catch(() => {})
    .finally(() => { archForm.submit(); });
}

// ── Row hover effect ──────────────────────────────────────────────────────
document.querySelectorAll('.res-row').forEach(function(row) {
    row.addEventListener('mouseenter', function() { this.style.background = '#f9fafb'; });
    row.addEventListener('mouseleave', function() { this.style.background = ''; });
});

// ── Cross-page selection store (localStorage) ────────────────────────────
// Key: 'res_selected_<barangay_id>' → JSON array of integer IDs
const STORE_KEY = 'res_selected_<?= $bid ?>';

function loadStore() {
    try { return new Set(JSON.parse(localStorage.getItem(STORE_KEY) || '[]')); }
    catch(e) { return new Set(); }
}
function saveStore(set) {
    localStorage.setItem(STORE_KEY, JSON.stringify([...set]));
}

let selectedIds = loadStore();

// ── Restore checkboxes on page load ──────────────────────────────────────
document.querySelectorAll('.row-check').forEach(function(cb) {
    if (selectedIds.has(parseInt(cb.dataset.id))) {
        cb.checked = true;
        cb.closest('tr')?.classList.add('selected');
    }
});
updateExcelBtn();
updateSelectionBar();

// ── Select all (current page only) ───────────────────────────────────────
const selectAll = document.getElementById('selectAll');
if (selectAll) {
    // Reflect current page state on load
    const allCbs    = document.querySelectorAll('.row-check');
    const allChecked = [...allCbs].every(cb => cb.checked);
    selectAll.checked       = allCbs.length > 0 && allChecked;
    selectAll.indeterminate = !allChecked && [...allCbs].some(cb => cb.checked);

    selectAll.addEventListener('change', function() {
        const check = this.checked;
        document.querySelectorAll('.row-check').forEach(function(cb) {
            cb.checked = check;
            const id = parseInt(cb.dataset.id);
            if (check) selectedIds.add(id);
            else        selectedIds.delete(id);
            cb.closest('tr')?.classList.toggle('selected', check);
        });
        saveStore(selectedIds);
        updateExcelBtn();
        updateSelectionBar();
    });
}

// ── Per-row checkbox ──────────────────────────────────────────────────────
document.querySelectorAll('.row-check').forEach(function(cb) {
    cb.addEventListener('change', function() {
        const id = parseInt(this.dataset.id);
        if (this.checked) selectedIds.add(id);
        else               selectedIds.delete(id);
        saveStore(selectedIds);
        this.closest('tr')?.classList.toggle('selected', this.checked);

        // Update select-all header state
        if (selectAll) {
            const allCbs     = document.querySelectorAll('.row-check');
            const checkedCbs = document.querySelectorAll('.row-check:checked');
            selectAll.checked       = checkedCbs.length === allCbs.length;
            selectAll.indeterminate = checkedCbs.length > 0 && checkedCbs.length < allCbs.length;
        }
        updateExcelBtn();
        updateSelectionBar();
    });
});

// ── Update "Download Selected" button ────────────────────────────────────
function updateExcelBtn() {
    const btn   = document.getElementById('downloadExcelBtn');
    const badge = document.getElementById('selectedCountBadge');
    if (!btn) return;
    const count = selectedIds.size;
    if (count > 0) {
        btn.disabled = false;
        btn.classList.remove('btn-outline-success');
        btn.classList.add('btn-success');
        badge.style.display = 'inline-block';
        badge.textContent   = count;
    } else {
        btn.disabled = true;
        btn.classList.remove('btn-success');
        btn.classList.add('btn-outline-success');
        badge.style.display = 'none';
    }
}

// ── Update persistent selection bar ──────────────────────────────────────
function updateSelectionBar() {
    const bar   = document.getElementById('selectionBar');
    const label = document.getElementById('selBarCount');
    if (!bar) return;
    if (selectedIds.size > 0) {
        bar.style.display = 'flex';
        label.textContent = selectedIds.size;
    } else {
        bar.style.display = 'none';
    }
}

// ── Clear all selections ──────────────────────────────────────────────────
function clearAllSelection() {
    selectedIds.clear();
    saveStore(selectedIds);
    document.querySelectorAll('.row-check').forEach(cb => {
        cb.checked = false;
        cb.closest('tr')?.classList.remove('selected');
    });
    if (selectAll) { selectAll.checked = false; selectAll.indeterminate = false; }
    updateExcelBtn();
    updateSelectionBar();
}

// ── Build & POST form to export ───────────────────────────────────────────
// mode: 'selected' = stored IDs only | 'all' = all residents (no IDs, server fetches all)
function downloadSelectedExcel(mode) {
    if (mode === 'selected' && selectedIds.size === 0) return;

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = BASE_URL_JS + '/barangay_admin/export_residents_selected.php';
    form.style.display = 'none';

    // CSRF
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    if (csrfMeta) {
        const ci = document.createElement('input');
        ci.type = 'hidden'; ci.name = 'csrf_token'; ci.value = csrfMeta.content;
        form.appendChild(ci);
    }

    // Mode flag
    const modeInput = document.createElement('input');
    modeInput.type = 'hidden'; modeInput.name = 'mode'; modeInput.value = mode;
    form.appendChild(modeInput);

    if (mode === 'selected') {
        selectedIds.forEach(function(id) {
            const inp = document.createElement('input');
            inp.type = 'hidden'; inp.name = 'ids[]'; inp.value = id;
            form.appendChild(inp);
        });
    }

    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
}

// ── Action button hover ───────────────────────────────────────────────────
document.querySelectorAll('.res-action-btn').forEach(function(btn) {
    btn.addEventListener('mouseenter', function() { this.style.background = '#f3f4f6'; });
    btn.addEventListener('mouseleave', function() { this.style.background = ''; });
});
</script>
<style>
.btn-xs { padding: 2px 8px; font-size: 0.75rem; }

/* Resident table row hover */
#residentsTable tbody tr.res-row:hover { background: #f9fafb; }

/* Action icon buttons */
.res-action-btn:hover { background: #f3f4f6 !important; }
.res-action-btn[title="View resident info"]:hover { background: #eff6ff !important; color: #1d4ed8 !important; }
.res-action-btn[title="Edit resident"]:hover      { background: #f0fdf4 !important; color: #15803d !important; }
.res-action-btn[title="Archive"]:hover            { background: #fee2e2 !important; color: #b91c1c !important; }

/* Selected row highlight */
#residentsTable tbody tr.res-row.selected { background: #eff6ff; }
</style>

<script>
(function () {
    const baseUrl = <?= json_encode(BASE_URL) ?>;

    /**
     * Attach inline autocomplete to a text input.
     * Uses position:fixed so the list is never clipped by overflow:hidden parents.
     */
    function initAutocomplete(inputId, listId, tabValue, clearBtnId) {
        const input   = document.getElementById(inputId);
        const list    = document.getElementById(listId);
        const form    = input ? input.closest('form') : null;
        if (!input || !list || !form) return;

        // Move the list to <body> so it escapes all overflow:hidden ancestors
        document.body.appendChild(list);

        let timer = null;
        let activeIdx = -1;
        let items = [];

        function positionList() {
            const rect = input.closest('.ac-wrapper').getBoundingClientRect();
            list.style.left  = rect.left + 'px';
            list.style.top   = rect.bottom + 'px';   /* no gap — seamless */
            list.style.width = rect.width + 'px';
        }

        function openList() {
            input.closest('.ac-wrapper').classList.add('ac-open');
            positionList();
            list.style.display = 'block';
        }

        function closeList() {
            input.closest('.ac-wrapper').classList.remove('ac-open');
            list.style.display = 'none';
            list.innerHTML = '';
            activeIdx = -1;
            items = [];
        }

        function setActive(idx) {
            items.forEach((el, i) => el.classList.toggle('ac-active', i === idx));
            activeIdx = idx;
        }

        function renderList(results) {
            list.innerHTML = '';
            activeIdx = -1;
            items = [];

            if (!results.length) {
                closeList();
                return;
            }

            results.forEach(function (r) {
                const div = document.createElement('div');
                div.className = 'ac-item';
                div.textContent = r.text;
                div.dataset.rid = r.id;  // store resident id for keyboard nav
                div.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    // Go directly to the resident's view page
                    window.location.href = baseUrl + '/barangay_admin/view_resident.php?id=' + r.id;
                });
                list.appendChild(div);
                items.push(div);
            });

            openList();
        }

        input.addEventListener('input', function () {
            const q = input.value.trim();

            if (clearBtnId) {
                const btn = document.getElementById(clearBtnId);
                if (btn) btn.style.visibility = q ? 'visible' : 'hidden';
            }

            clearTimeout(timer);
            if (!q) { closeList(); return; }

            timer = setTimeout(function () {
                fetch(baseUrl + '/ajax/search_residents.php?q=' + encodeURIComponent(q) + '&tab=' + tabValue)
                    .then(r => r.json())
                    .then(data => renderList(data.results || []))
                    .catch(() => closeList());
            }, 200);
        });

        // Keyboard navigation
        input.addEventListener('keydown', function (e) {
            if (!items.length) {
                if (e.key === 'Enter') { closeList(); form.submit(); }
                return;
            }
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                setActive(Math.min(activeIdx + 1, items.length - 1));
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                setActive(Math.max(activeIdx - 1, 0));
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (activeIdx >= 0) {
                    // Navigate to the highlighted resident
                    window.location.href = baseUrl + '/barangay_admin/view_resident.php?id=' + items[activeIdx].dataset.rid;
                } else {
                    form.submit();
                }
            } else if (e.key === 'Escape') {
                closeList();
            }
        });

        // Re-position on scroll/resize so list follows the input
        window.addEventListener('scroll', function () { if (list.style.display === 'block') positionList(); }, true);
        window.addEventListener('resize', function () { if (list.style.display === 'block') positionList(); });

        // Close on outside click
        document.addEventListener('click', function (e) {
            if (!input.contains(e.target) && !list.contains(e.target)) closeList();
        });

        // Clear button
        if (clearBtnId) {
            const btn = document.getElementById(clearBtnId);
            if (btn) {
                btn.addEventListener('click', function () {
                    input.value = '';
                    btn.style.visibility = 'hidden';
                    closeList();
                    form.submit();
                });
            }
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        initAutocomplete('residentSearchInput', 'residentAcList', 'active', 'residentSearchClear');
        initAutocomplete('transferSearchInput', 'transferAcList', 'transferred', null);
    });
})();
</script>
</body>
</html>
