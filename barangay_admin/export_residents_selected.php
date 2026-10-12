<?php
require_once __DIR__ . '/../config/config.php';
requireRole('barangay_admin');
verifyCsrfOrAbort();

$db  = Database::getInstance()->getConnection();
$u   = currentUser();
$bid = (int)$u['barangay_id'];

$mode = ($_POST['mode'] ?? 'selected') === 'all' ? 'all' : 'selected';

// ── Build the WHERE clause based on mode ─────────────────────────────────
if ($mode === 'all') {
    // Download every active resident of this barangay
    $whereClause = "WHERE r.barangay_id = ? AND r.resident_status NOT IN ('Inactive','Deceased')";
    $bindParams  = [$bid];
} else {
    // Download only the explicitly selected IDs
    $rawIds = $_POST['ids'] ?? [];
    if (empty($rawIds) || !is_array($rawIds)) {
        http_response_code(400); exit('No residents selected.');
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $rawIds))));
    if (empty($ids)) {
        http_response_code(400); exit('Invalid resident IDs.');
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $whereClause  = "WHERE r.barangay_id = ? AND r.id IN ($placeholders)";
    $bindParams   = array_merge([$bid], $ids);
}

// ── Fetch full resident + address info, scoped to this barangay ──────────
$stmt = $db->prepare("
    SELECT
        r.id,
        r.last_name,
        r.first_name,
        r.middle_name,
        r.suffix,
        r.birthdate,
        r.age,
        r.gender,
        r.civil_status,
        r.contact_number,
        r.email,
        r.voter_status,
        r.occupation_status,
        r.resident_status,
        r.is_4ps,
        r.is_pwd,
        r.is_senior,
        r.is_solo_parent,
        r.transfer_from_barangay,
        r.transfer_to_barangay,
        r.transfer_outside,
        r.transfer_reason,
        r.archive_reason,
        r.created_at,
        a.region,
        a.province,
        a.city,
        a.barangay   AS addr_barangay,
        a.street,
        a.purok,
        a.zone,
        a.zip_code,
        hm.relationship  AS household_role,
        h.household_code,
        CONCAT(hr.last_name, ', ', hr.first_name) AS household_head
    FROM residents r
    LEFT JOIN addresses a          ON r.address_id        = a.id
    LEFT JOIN household_members hm ON hm.resident_id      = r.id
    LEFT JOIN households h         ON hm.household_id     = h.id
    LEFT JOIN residents hr         ON h.head_resident_id  = hr.id
    $whereClause
    ORDER BY r.last_name ASC, r.first_name ASC
");
$stmt->execute($bindParams);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) {
    http_response_code(404);
    exit('No matching residents found for this barangay.');
}

// ── Barangay name for header ─────────────────────────────────────────────
$brgyName = $db->prepare("SELECT barangay_name FROM barangays WHERE id=?");
$brgyName->execute([$bid]);
$brgyName = $brgyName->fetchColumn() ?: 'Barangay';

// ── Log activity ─────────────────────────────────────────────────────────
logActivity($u['id'], 'EXPORT_RESIDENTS', 'Residents',
    'Exported ' . count($rows) . ' resident(s) to Excel (' . ($mode === 'all' ? 'all residents' : 'selected') . ')');

// ── Output Excel (HTML table with xls MIME) ──────────────────────────────
$filename = 'residents_' . strtolower(str_replace(' ', '_', $brgyName))
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
  body         { font-family: Arial, sans-serif; font-size: 11px; }
  h2           { color: #1a4f7a; margin-bottom: 2px; font-size: 14px; }
  .meta        { font-size: 10px; color: #555; margin-bottom: 14px; }
  table        { border-collapse: collapse; width: 100%; }
  th           { background: #1a4f7a; color: #fff; padding: 5px 8px;
                 border: 1px solid #ccc; font-size: 10px; text-align: left;
                 white-space: nowrap; }
  td           { padding: 4px 8px; border: 1px solid #ddd; font-size: 10px;
                 vertical-align: top; }
  tr:nth-child(even) td { background: #f5f8fc; }
  .yes         { color: #15803d; font-weight: 700; }
  .no          { color: #9ca3af; }
  .tag         { background: #e8f0fe; color: #1a4f7a; padding: 1px 5px;
                 border-radius: 4px; font-size: 9px; font-weight: 700;
                 margin-right: 2px; }
</style>
</head>
<body>

<h2>Barangay <?= htmlspecialchars($brgyName) ?> — Residents Export <?= $mode === 'all' ? '(All Residents)' : '(Selected)' ?></h2>
<div class="meta">
  <strong>Total Selected:</strong> <?= count($rows) ?> &nbsp;|&nbsp;
  <strong>Generated:</strong> <?= date('F d, Y h:i A') ?> &nbsp;|&nbsp;
  <strong>By:</strong> <?= htmlspecialchars($u['name']) ?>
</div>

<table>
  <thead>
    <tr>
      <th>#</th>
      <th>Last Name</th>
      <th>First Name</th>
      <th>Middle Name</th>
      <th>Suffix</th>
      <th>Birthdate</th>
      <th>Age</th>
      <th>Gender</th>
      <th>Civil Status</th>
      <th>Contact Number</th>
      <th>Email</th>
      <th>Voter Status</th>
      <th>Occupation Status</th>
      <th>Resident Status</th>
      <!-- Tags -->
      <th>PWD</th>
      <th>Senior Citizen</th>
      <th>4Ps Beneficiary</th>
      <th>Solo Parent</th>
      <!-- Address -->
      <th>Region</th>
      <th>Province</th>
      <th>City / Municipality</th>
      <th>Barangay</th>
      <th>Street</th>
      <th>Purok</th>
      <th>Zone</th>
      <th>Zip Code</th>
      <!-- Household -->
      <th>Household Code</th>
      <th>Household Role</th>
      <th>Household Head</th>
      <!-- Transfer info -->
      <th>Transferred From</th>
      <th>Transferred To</th>
      <th>Transfer Outside Brgy</th>
      <th>Transfer Reason</th>
      <!-- Other -->
      <th>Archive Reason</th>
      <th>Date Registered</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($rows as $i => $r): ?>
    <?php
      $tags = [];
      if ($r['is_pwd'])         $tags[] = 'PWD';
      if ($r['is_senior'])      $tags[] = 'Senior';
      if ($r['is_4ps'])         $tags[] = '4Ps';
      if ($r['is_solo_parent']) $tags[] = 'Solo Parent';

      $fullAddr = implode(', ', array_filter([
          $r['purok']        ? 'Purok ' . $r['purok'] : '',
          $r['street']       ?? '',
          $r['addr_barangay'] ?? '',
          $r['city']         ?? '',
          $r['province']     ?? '',
      ]));
    ?>
    <tr>
      <td><?= $i + 1 ?></td>
      <td><?= htmlspecialchars($r['last_name'] ?? '') ?></td>
      <td><?= htmlspecialchars($r['first_name'] ?? '') ?></td>
      <td><?= htmlspecialchars($r['middle_name'] ?? '') ?></td>
      <td><?= htmlspecialchars($r['suffix'] ?? '') ?></td>
      <td><?= $r['birthdate'] ? date('M d, Y', strtotime($r['birthdate'])) : '' ?></td>
      <td><?= (int)$r['age'] ?></td>
      <td><?= htmlspecialchars($r['gender'] ?? '') ?></td>
      <td><?= htmlspecialchars($r['civil_status'] ?? '') ?></td>
      <td><?= htmlspecialchars($r['contact_number'] ?? '') ?></td>
      <td><?= htmlspecialchars($r['email'] ?? '') ?></td>
      <td><?= htmlspecialchars($r['voter_status'] ?? '') ?></td>
      <td><?= htmlspecialchars($r['occupation_status'] ?? '') ?></td>
      <td><?= htmlspecialchars($r['resident_status'] ?? '') ?></td>
      <!-- Tags as Yes/No for easy filtering in Excel -->
      <td class="<?= $r['is_pwd']         ? 'yes' : 'no' ?>"><?= $r['is_pwd']         ? 'Yes' : 'No' ?></td>
      <td class="<?= $r['is_senior']      ? 'yes' : 'no' ?>"><?= $r['is_senior']      ? 'Yes' : 'No' ?></td>
      <td class="<?= $r['is_4ps']         ? 'yes' : 'no' ?>"><?= $r['is_4ps']         ? 'Yes' : 'No' ?></td>
      <td class="<?= $r['is_solo_parent'] ? 'yes' : 'no' ?>"><?= $r['is_solo_parent'] ? 'Yes' : 'No' ?></td>
      <!-- Address -->
      <td><?= htmlspecialchars($r['region']        ?? '') ?></td>
      <td><?= htmlspecialchars($r['province']      ?? '') ?></td>
      <td><?= htmlspecialchars($r['city']          ?? '') ?></td>
      <td><?= htmlspecialchars($r['addr_barangay'] ?? '') ?></td>
      <td><?= htmlspecialchars($r['street']        ?? '') ?></td>
      <td><?= htmlspecialchars($r['purok']         ?? '') ?></td>
      <td><?= htmlspecialchars($r['zone']          ?? '') ?></td>
      <td><?= htmlspecialchars($r['zip_code']      ?? '') ?></td>
      <!-- Household -->
      <td><?= htmlspecialchars($r['household_code'] ?? '') ?></td>
      <td><?= htmlspecialchars($r['household_role'] ?? '') ?></td>
      <td><?= htmlspecialchars($r['household_head'] ?? '') ?></td>
      <!-- Transfer -->
      <td><?= htmlspecialchars($r['transfer_from_barangay'] ?? '') ?></td>
      <td><?= htmlspecialchars($r['transfer_to_barangay']   ?? '') ?></td>
      <td><?= !empty($r['transfer_outside']) ? 'Yes' : 'No' ?></td>
      <td><?= htmlspecialchars($r['transfer_reason'] ?? '') ?></td>
      <!-- Other -->
      <td><?= htmlspecialchars($r['archive_reason'] ?? '') ?></td>
      <td><?= $r['created_at'] ? date('M d, Y', strtotime($r['created_at'])) : '' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

</body>
</html>
<?php
ob_end_flush();
exit;
