<?php
/**
 * AJAX: Get resident address details for household autofill.
 * Returns resident contact number and address fields (purok, street, zone).
 */
require_once __DIR__ . '/../config/config.php';
requireRole('barangay_admin');

$db = Database::getInstance()->getConnection();
$u  = currentUser();
$id = (int)($_GET['id'] ?? 0);

if (!$id) {
    jsonResponse([]);
}

$stmt = $db->prepare(
    "SELECT r.contact_number,
            a.purok, a.street, a.zone
     FROM residents r
     LEFT JOIN addresses a ON r.address_id = a.id
     WHERE r.id = ? AND r.barangay_id = ?"
);
$stmt->execute([$id, (int)$u['barangay_id']]);
$row = $stmt->fetch();

jsonResponse($row ?: []);
