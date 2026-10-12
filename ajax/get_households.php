<?php
/**
 * AJAX: Get active households for the current barangay.
 * Returns id, household_code, and head name for dropdown population.
 */
require_once __DIR__ . '/../config/config.php';
requireRole('barangay_admin');

$db = Database::getInstance()->getConnection();
$u  = currentUser();
$bid = (int)$u['barangay_id'];

$stmt = $db->prepare(
    "SELECT h.id,
            h.household_code,
            CONCAT(r.last_name, ', ', r.first_name) AS head_name
     FROM households h
     LEFT JOIN residents r ON h.head_resident_id = r.id
     WHERE h.barangay_id = ? AND h.status = 'Active'
     ORDER BY h.household_code ASC"
);
$stmt->execute([$bid]);
$households = $stmt->fetchAll();

jsonResponse($households);
