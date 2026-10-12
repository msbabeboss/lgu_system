<?php
require_once __DIR__ . '/../config/config.php';
requireRole('barangay_admin');
verifyCsrfOrAbort();

$db  = Database::getInstance()->getConnection();
$u   = currentUser();
$bid = (int)$u['barangay_id'];

$mode   = ($_POST['mode'] ?? 'selected') === 'all' ? 'all' : 'selected';
$search = sanitize($_POST['search'] ?? '');
$type   = sanitize($_POST['type']   ?? '');

// ── Build WHERE clause ────────────────────────────────────────────────────
$baseWhere = "WHERE c.barangay_id = $bid";

if ($mode === 'all') {
    // Respect current search/type filters so "Export All" honours the active view
    if ($search) {
        $s = $db->quote('%' . $search . '%');
        $baseWhere .= " AND (
            r.first_name LIKE $s OR r.last_name LIKE $s OR r.middle_name LIKE $s OR
            CONCAT(r.first_name,' ',r.last_name) LIKE $s OR
            CONCAT(r.last_name,' ',r.first_name) LIKE $s OR
            CONCAT(r.first_name,' ',r.middle_name,' ',r.last_name) LIKE $s OR
            CONCAT(r.last_name,', ',r.first_name,' ',r.middle_name) LIKE $s OR
            CONCAT(r.last_name,', ',r.first_name) LIKE $s
        )";
    }
    if ($type) {
        $baseWhere .= " AND c.certificate_type = " . $db->quote($type);
    }
    $bindParams = [];
} else {
    // Export only the explicitly checked IDs
    $rawIds = $_POST['ids'] ?? [];
    if (empty($rawIds) || !is_array($rawIds)) {
        http_response_code(400); exit('No certificates selected.');
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $rawIds))));
    if (empty($ids)) {
        http_response_code(400); exit('Invalid certificate IDs.');
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $baseWhere   .= " AND c.id IN ($placeholders)";
    $bindParams   = $ids;
}

// ── Fetch certificate + resident + issuer info ────────────────────────────
$sql = "
    SELECT
        c.id                                                    AS cert_id,
        c.certificate_type,
        c.purpose,
        c.date_issued,
        c.created_at,
        CONCAT(r.last_name, ', ', r.first_name,
               CASE WHEN r.middle_name IS NOT NULL AND r.middle_name <> ''
                    THEN CONCAT(' ', r.middle_name) ELSE '' END,
               CASE WHEN r.suffix IS NOT NULL AND r.suffix <> ''
                    THEN CONCAT(' ', r.suffix) ELSE '' END
        )                                                       AS resident_name,
        r.first_name,
        r.last_name,
        r.middle_name,
        r.suffix,
        r.birthdate,
        r.age,
        r.gender,
        r.civil_status,
        r.contact_number,
        r.voter_status,
        r.occupation_status,
        r.resident_status,
        r.is_4ps,
        r.is_pwd,
        r.is_senior,
        r.is_solo_parent,
        a.purok,
        a.street,
        a.barangay                                              AS addr_barangay,
        a.city,
        a.province,
        a.zip_code,
        CONCAT(iss.first_name, ' ', iss.last_name)             AS issued_by_name,
        (SELECT COUNT(*) FROM certificates c2
         WHERE c2.resident_id = r.id
           AND c2.barangay_id = c.barangay_id)                 AS total_certs_issued
    FROM certificates c
    LEFT JOIN residents  r   ON c.resident_id  = r.id
    LEFT JOIN addresses  a   ON r.address_id   = a.id
    LEFT JOIN users      iss ON c.issued_by    = iss.id
    $baseWhere
    ORDER BY c.date_issued DESC, r.last_name ASC
";

$stmt = $db->prepare($sql);
$stmt->execute($bindParams);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) {
    http_response_code(404);
    exit('No matching certificates found.');
}

// ── Barangay name for header ──────────────────────────────────────────────
$brgyName = $db->prepare("SELECT barangay_name FROM barangays WHERE id=?");
$brgyName->execute([$bid]);
$brgyName = $brgyName->fetchColumn() ?: 'Barangay';

// ── Log activity ──────────────────────────────────────────────────────────
logActivity(
    $u['id'],
    'EXPORT_CERTIFICATES',
    'Certificates',
    'Exported ' . count($rows) . ' certificate record(s) to Excel (' . ($mode === 'all' ? 'all / filtered' : 'selected') . ')'
);

// ── Output .xls (HTML table with Excel MIME) ──────────────────────────────
$filename = 'certificates_' . strtolower(str_replace(' ', '_', $brgyName))
          . '_' . date('Ymd_His') . '.xls';

header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

ob_start();
?>
<html>
<head>
<meta charset="UTF-8">
<style>
  body  { font-family: Arial, sans-serif; font-size: 11px; }
  h2    { color: #1a4f7a; margin-bottom: 2px; font-size: 14px; }
  .meta { font-size: 10px; color: #555; margin-bottom: 14px; }
  table { border-collapse: collapse; width: 100%; }
  th    { background: #1a4f7a; color: #fff; padding: 5px 8px;
          border: 1px solid #ccc; font-size: 10px; text-align: left;
          white-space: nowrap; }
  td    { padding: 4px 8px; border: 1px solid #ddd; font-size: 10px;
          vertical-align: top; }
  tr:nth-child(even) td { background: #f5f8fc; }
  .yes  { color: #15803d; font-weight: 700; }
  .no   { color: #9ca3af; }
  .badge-cert { background: #dbeafe; color: #1e40af; padding: 1px 6px;
                border-radius: 4px; font-size: 9px; font-weight: 700; }
</style>
</head>
<body>

<h2>Barangay <?= htmlspecialchars($brgyName) ?> — Certificates Export
  <?= $mode === 'all' ? '(All / Filtered)' : '(Selected)' ?>
  <?= $type ? '— Type: ' . htmlspecialchars($type) : '' ?>
</h2>
<div class="meta">
  <strong>Total Records:</strong> <?= count($rows) ?> &nbsp;|&nbsp;
  <strong>Generated:</strong> <?= date('F d, Y h:i A') ?> &nbsp;|&nbsp;
  <strong>By:</strong> <?= htmlspecialchars($u['name']) ?>
  <?php if ($search): ?>
    &nbsp;|&nbsp;<strong>Search:</strong> <?= htmlspecialchars($search) ?>
  <?php endif; ?>
</div>

<table>
  <thead>
    <tr>
      <th>#</th>
      <th>Certificate ID</th>
      <th>Resident Name</th>
      <th>First Name</th>
      <th>Last Name</th>
      <th>Middle Name</th>
      <th>Suffix</th>
      <th>Certificate Type</th>
      <th>Purpose</th>
      <th>Date Issued</th>
      <th>Issued By</th>
      <th>Total Certs Issued (Resident)</th>
      <!-- Resident Details -->
      <th>Birthdate</th>
      <th>Age</th>
      <th>Gender</th>
      <th>Civil Status</th>
      <th>Contact Number</th>
      <th>Voter Status</th>
      <th>Occupation Status</th>
      <th>Resident Status</th>
      <!-- Tags -->
      <th>PWD</th>
      <th>Senior Citizen</th>
      <th>4Ps Beneficiary</th>
      <th>Solo Parent</th>
      <!-- Address -->
      <th>Purok</th>
      <th>Street</th>
      <th>Barangay</th>
      <th>City / Municipality</th>
      <th>Province</th>
      <th>Zip Code</th>
      <!-- Meta -->
      <th>Record Created At</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($rows as $i => $row): ?>
    <tr>
      <td><?= $i + 1 ?></td>
      <td><?= (int)$row['cert_id'] ?></td>
      <td><?= htmlspecialchars($row['resident_name']      ?? '') ?></td>
      <td><?= htmlspecialchars($row['first_name']         ?? '') ?></td>
      <td><?= htmlspecialchars($row['last_name']          ?? '') ?></td>
      <td><?= htmlspecialchars($row['middle_name']        ?? '') ?></td>
      <td><?= htmlspecialchars($row['suffix']             ?? '') ?></td>
      <td><?= htmlspecialchars($row['certificate_type']   ?? '') ?></td>
      <td><?= htmlspecialchars($row['purpose']            ?? '') ?></td>
      <td><?= $row['date_issued'] ? date('M d, Y', strtotime($row['date_issued'])) : '' ?></td>
      <td><?= htmlspecialchars($row['issued_by_name']     ?? '') ?></td>
      <td><?= (int)$row['total_certs_issued'] ?></td>
      <!-- Resident details -->
      <td><?= $row['birthdate'] ? date('M d, Y', strtotime($row['birthdate'])) : '' ?></td>
      <td><?= (int)$row['age'] ?></td>
      <td><?= htmlspecialchars($row['gender']             ?? '') ?></td>
      <td><?= htmlspecialchars($row['civil_status']       ?? '') ?></td>
      <td><?= htmlspecialchars($row['contact_number']     ?? '') ?></td>
      <td><?= htmlspecialchars($row['voter_status']       ?? '') ?></td>
      <td><?= htmlspecialchars($row['occupation_status']  ?? '') ?></td>
      <td><?= htmlspecialchars($row['resident_status']    ?? '') ?></td>
      <!-- Tags as Yes/No for easy Excel filtering -->
      <td class="<?= $row['is_pwd']         ? 'yes' : 'no' ?>"><?= $row['is_pwd']         ? 'Yes' : 'No' ?></td>
      <td class="<?= $row['is_senior']      ? 'yes' : 'no' ?>"><?= $row['is_senior']      ? 'Yes' : 'No' ?></td>
      <td class="<?= $row['is_4ps']         ? 'yes' : 'no' ?>"><?= $row['is_4ps']         ? 'Yes' : 'No' ?></td>
      <td class="<?= $row['is_solo_parent'] ? 'yes' : 'no' ?>"><?= $row['is_solo_parent'] ? 'Yes' : 'No' ?></td>
      <!-- Address -->
      <td><?= htmlspecialchars($row['purok']              ?? '') ?></td>
      <td><?= htmlspecialchars($row['street']             ?? '') ?></td>
      <td><?= htmlspecialchars($row['addr_barangay']      ?? '') ?></td>
      <td><?= htmlspecialchars($row['city']               ?? '') ?></td>
      <td><?= htmlspecialchars($row['province']           ?? '') ?></td>
      <td><?= htmlspecialchars($row['zip_code']           ?? '') ?></td>
      <!-- Meta -->
      <td><?= $row['created_at'] ? date('M d, Y h:i A', strtotime($row['created_at'])) : '' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

</body>
</html>
<?php
ob_end_flush();
exit;
