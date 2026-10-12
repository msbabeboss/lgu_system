<?php
/**
 * Landing Page Stats
 * Returns live counts that match the Super Admin dashboard exactly.
 */
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once __DIR__ . '/../config/config.php';

try {
    $db = Database::getInstance()->getConnection();

    // Total active residents — same query as super admin dashboard
    $totalResidents = (int)$db->query(
        "SELECT COUNT(*) FROM residents WHERE resident_status = 'Active'"
    )->fetchColumn();

    // Active user accounts
    $activeUsers = (int)$db->query(
        "SELECT COUNT(*) FROM users WHERE status = 'active'"
    )->fetchColumn();

    // Total records stored across all main tables
    $recordsStored = (int)$db->query("
        SELECT (
            (SELECT COUNT(*) FROM residents) +
            (SELECT COUNT(*) FROM households) +
            (SELECT COUNT(*) FROM blotter_cases) +
            (SELECT COUNT(*) FROM certificates) +
            (SELECT COUNT(*) FROM officials) +
            (SELECT COUNT(*) FROM archives)
        ) AS total
    ")->fetchColumn();

    // Total barangays in the system
    try {
        $totalBarangays = (int)$db->query("SELECT COUNT(*) FROM barangays")->fetchColumn();
    } catch (\Throwable $e) {
        $totalBarangays = 53; // fallback to known count
    }

    echo json_encode([
        'success'    => true,
        'residents'  => $totalResidents,
        'users'      => $activeUsers,
        'records'    => $recordsStored,
        'barangays'  => $totalBarangays,
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success'   => false,
        'residents' => 0,
        'users'     => 0,
        'records'   => 0,
    ]);
}
