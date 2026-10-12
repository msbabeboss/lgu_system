<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();

$db = Database::getInstance()->getConnection();
$u = currentUser();
$id = (int)($_GET['id'] ?? 0);

$where = isSuperAdmin() ? "WHERE r.id=?" : "WHERE r.id=? AND r.barangay_id={$u['barangay_id']}";
$stmt = $db->prepare("SELECT r.*, a.purok, a.street, a.barangay as addr_barangay, a.city FROM residents r LEFT JOIN addresses a ON r.address_id=a.id $where");
$stmt->execute([$id]);
$resident = $stmt->fetch();

jsonResponse($resident ?: []);
