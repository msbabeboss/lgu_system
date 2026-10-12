<?php
/**
 * AJAX: Get all members of a specific household.
 * Returns resident name and relationship for the household view modal.
 */
require_once __DIR__ . '/../config/config.php';
requireRole('barangay_admin');

$db  = Database::getInstance()->getConnection();
$u   = currentUser();
$bid = (int)$u['barangay_id'];
$hid = (int)($_GET['household_id'] ?? 0);

if (!$hid) {
    jsonResponse([]);
}

// Verify household belongs to this barangay
$check = $db->prepare("SELECT id FROM households WHERE id=? AND barangay_id=?");
$check->execute([$hid, $bid]);
if (!$check->fetch()) {
    jsonResponse([]);
}

$stmt = $db->prepare(
    "SELECT hm.id,
            r.id AS resident_id,
            CONCAT(r.last_name, ', ', r.first_name,
                   IF(r.middle_name != '' AND r.middle_name IS NOT NULL,
                      CONCAT(' ', LEFT(r.middle_name,1), '.'), ''),
                   IF(r.suffix != '' AND r.suffix IS NOT NULL,
                      CONCAT(' ', r.suffix), '')) AS full_name,
            hm.relationship,
            r.age,
            r.gender,
            r.civil_status,
            r.occupation_status,
            r.resident_status
     FROM household_members hm
     JOIN residents r ON hm.resident_id = r.id
     WHERE hm.household_id = ?
     ORDER BY FIELD(hm.relationship, 'Head') DESC, r.last_name ASC, r.first_name ASC"
);
$stmt->execute([$hid]);
$members = $stmt->fetchAll();

jsonResponse($members);
