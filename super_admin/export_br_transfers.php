<?php
require_once __DIR__ . '/../config/config.php';
requireRole('super_admin');

$db = Database::getInstance()->getConnection();
$u  = currentUser();

// ── POST: selected IDs ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrAbort();
    $rawIds    = $_POST['ids'] ?? [];
    $brgFilter = (int)($_POST['barangay_id'] ?? 0);
    if (empty($rawIds) || !is_array($rawIds)) { http_response_code(400); exit('No rows selected.'); }
    $ids = array_values(array_unique(array_filter(array_map('intval', $rawIds))));
    if (empty($ids)) { http_response_code(400); exit('Invalid IDs.'); }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $where        = "WHERE r.id IN ($placeholders)";
    $bindParams   = $ids;
} else {
    // ── GET: filter-based (all) ───────────────────────────────
    $brgFilter = (int)($_GET['barangay_id'] ?? 0);
    if (!$brgFilter) { redirect(BASE_URL . '/super_admin/barangay_records.php'); }

    $search    = trim(strip_tags($_GET['search']    ?? ''));
    $statusF   = trim(strip_tags($_GET['status']    ?? ''));
    $dirFilter = trim(strip_tags($_GET['direction'] ?? ''));
    $sort      = trim(strip_tags($_GET['sort']      ?? 'date'));
    $dateFrom  = trim(strip_tags($_GET['date_from'] ?? ''));
    $dateTo    = trim(strip_tags($_GET['date_to']   ?? ''));

    // Build WHERE
    $where = "WHERE (r.origin_barangay_id = $brgFilter OR r.destination_barangay_id = $brgFilter)";
    if ($statusF)                  $where .= " AND r.status = " . $db->quote($statusF);
    if ($dirFilter === 'outgoing') $where .= " AND r.origin_barangay_id = $brgFilter";
    if ($dirFilter === 'incoming') $where .= " AND r.destination_barangay_id = $brgFilter";
    if ($search)                   $where .= " AND r.resident_name LIKE " . $db->quote("%$search%");
    if ($dateFrom)                 $where .= " AND r.created_at >= " . $db->quote($dateFrom . ' 00:00:00');
    if ($dateTo)                   $where .= " AND r.created_at <= " . $db->quote($dateTo   . ' 23:59:59');

    $orderBy = match($sort) {
        'name'   => "r.resident_name ASC",
        'status' => "FIELD(r.status,'pending','approved','verified','rejected'), r.created_at DESC",
        default  => "r.created_at DESC",
    };
    $bindParams = null;
}

// Get barangay name for filename
$brgyName = $db->prepare("SELECT barangay_name FROM barangays WHERE id=?");
$brgyName->execute([$brgFilter]);
$brgyName    = $brgyName->fetchColumn() ?: 'barangay';
$safeBarangay = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $brgyName);

logActivity($u['id'], 'EXPORT_BR_TRANSFERS', 'Barangay Records',
    "Exported transfers CSV for barangay ID $brgFilter ($brgyName)");

$orderBy = isset($orderBy) ? $orderBy : "r.created_at DESC";

$sql = "
    SELECT r.id,
           r.resident_name,
           CASE WHEN r.origin_barangay_id = $brgFilter THEN 'Outgoing' ELSE 'Incoming' END AS direction,
           b1.barangay_name  AS origin_barangay,
           b2.barangay_name  AS destination_barangay,
           r.reason,
           r.status,
           CONCAT(ru.first_name,' ',ru.last_name) AS requested_by,
           r.created_at      AS date_filed,
           r.reviewed_at,
           CONCAT(rv.first_name,' ',rv.last_name) AS reviewed_by,
           r.verified_at,
           CONCAT(vf.first_name,' ',vf.last_name) AS verified_by
    FROM resident_transfer_requests r
    LEFT JOIN barangays b1 ON r.origin_barangay_id      = b1.id
    LEFT JOIN barangays b2 ON r.destination_barangay_id = b2.id
    LEFT JOIN users ru     ON r.requester_user_id       = ru.id
    LEFT JOIN users rv     ON r.reviewed_by             = rv.id
    LEFT JOIN users vf     ON r.verified_by             = vf.id
    $where
    ORDER BY $orderBy
";

if ($bindParams !== null) {
    $stmt = $db->prepare($sql);
    $stmt->execute($bindParams);
    $rows = $stmt->fetchAll();
} else {
    $rows = $db->query($sql)->fetchAll();
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="transfers_' . $safeBarangay . '_' . date('Ymd') . '.csv"');

$out = fopen('php://output', 'w');

// BOM for Excel UTF-8
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, [
    'Resident Name', 'Direction',
    'Origin Barangay', 'Destination Barangay',
    'Reason', 'Status',
    'Requested By', 'Date Filed',
    'Reviewed By', 'Reviewed At',
    'Verified By', 'Verified At',
]);

$statusLabels = [
    'pending'  => 'Pending',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'verified' => 'Transfer Complete',
];

foreach ($rows as $row) {
    fputcsv($out, [
        $row['resident_name']        ?? '',
        $row['direction']            ?? '',
        $row['origin_barangay']      ?? '',
        $row['destination_barangay'] ?? '',
        $row['reason']               ?? '',
        $statusLabels[$row['status']] ?? $row['status'] ?? '',
        $row['requested_by']         ?? '',
        $row['date_filed']  ? "\t" . $row['date_filed']    : '',
        $row['reviewed_by'] ?? '',
        $row['reviewed_at'] ? "\t" . $row['reviewed_at']   : '',
        $row['verified_by'] ?? '',
        $row['verified_at'] ? "\t" . $row['verified_at']   : '',
    ]);
}

fclose($out);
exit;
