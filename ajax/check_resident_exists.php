<?php
/**
 * AJAX — Check whether a person already exists as a resident in this barangay.
 *
 * GET params:
 *   first_name   (required)
 *   last_name    (required)
 *   middle_name  (optional) — when supplied, narrows match to first+middle+last
 *                             so two people sharing first/last but different middle
 *                             names are not confused with each other.
 *   suffix       (optional) — when both middle_name AND suffix are supplied the
 *                             query also matches on suffix, distinguishing e.g.
 *                             "Juan Santos dela Cruz Jr." from "Juan Santos dela Cruz Sr."
 *
 * Returns JSON:
 *   { found: false }
 *   { found: true, id: 12, name: "Juan S. dela Cruz Jr.", status: "Active" }
 */
require_once __DIR__ . '/../config/config.php';
requireRole('barangay_admin');           // must be logged-in admin

header('Content-Type: application/json');
header('Cache-Control: no-store');

$db  = Database::getInstance()->getConnection();
$u   = currentUser();
$bid = (int)$u['barangay_id'];

$fn = trim($_GET['first_name']  ?? '');
$ln = trim($_GET['last_name']   ?? '');
$mn = trim($_GET['middle_name'] ?? '');   // optional
$sx = trim($_GET['suffix']      ?? '');   // optional

// Need at least first + last name
if ($fn === '' || $ln === '') {
    echo json_encode(['found' => false]);
    exit;
}

/**
 * Query strategy:
 *
 *  • middle_name supplied + suffix supplied
 *      → match on first + middle + last + suffix  (most precise)
 *
 *  • middle_name supplied, no suffix
 *      → match on first + middle + last  (distinguishes different middle names)
 *
 *  • nothing extra supplied
 *      → match on first + last only  (broadest — at least one person with that
 *        first/last exists, regardless of middle name or suffix)
 */
if ($mn !== '' && $sx !== '') {
    // Most specific: all four name parts
    $stmt = $db->prepare("
        SELECT id,
               first_name, middle_name, last_name, suffix,
               birthdate, gender, resident_status
        FROM residents
        WHERE barangay_id = ?
          AND LOWER(first_name)              = LOWER(?)
          AND LOWER(last_name)               = LOWER(?)
          AND LOWER(COALESCE(middle_name,'')) = LOWER(?)
          AND LOWER(COALESCE(suffix,''))      = LOWER(?)
          AND resident_status NOT IN ('Inactive','Deceased')
        ORDER BY FIELD(resident_status,'Active','Transferred') ASC, id ASC
        LIMIT 1
    ");
    $stmt->execute([$bid, $fn, $ln, $mn, $sx]);

} elseif ($mn !== '') {
    // Middle name given but no suffix
    $stmt = $db->prepare("
        SELECT id,
               first_name, middle_name, last_name, suffix,
               birthdate, gender, resident_status
        FROM residents
        WHERE barangay_id = ?
          AND LOWER(first_name)              = LOWER(?)
          AND LOWER(last_name)               = LOWER(?)
          AND LOWER(COALESCE(middle_name,'')) = LOWER(?)
          AND resident_status NOT IN ('Inactive','Deceased')
        ORDER BY FIELD(resident_status,'Active','Transferred') ASC, id ASC
        LIMIT 1
    ");
    $stmt->execute([$bid, $fn, $ln, $mn]);

} else {
    // First + last only (broadest match)
    $stmt = $db->prepare("
        SELECT id,
               first_name, middle_name, last_name, suffix,
               birthdate, gender, resident_status
        FROM residents
        WHERE barangay_id = ?
          AND LOWER(first_name) = LOWER(?)
          AND LOWER(last_name)  = LOWER(?)
          AND resident_status NOT IN ('Inactive','Deceased')
        ORDER BY FIELD(resident_status,'Active','Transferred') ASC, id ASC
        LIMIT 1
    ");
    $stmt->execute([$bid, $fn, $ln]);
}

$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo json_encode(['found' => false]);
    exit;
}

// Build a clean display name
$displayName = trim(
    $row['first_name']
    . ($row['middle_name'] ? ' ' . $row['middle_name'] : '')
    . ' ' . $row['last_name']
    . ($row['suffix']      ? ' ' . $row['suffix']      : '')
);

echo json_encode([
    'found'  => true,
    'id'     => (int)$row['id'],
    'name'   => $displayName,
    'status' => $row['resident_status'],
    'gender' => $row['gender'],
    'bdate'  => $row['birthdate'] ?? '',
]);
