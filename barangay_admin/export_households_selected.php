<?php
require_once __DIR__ . '/../config/config.php';
requireRole('barangay_admin');
verifyCsrfOrAbort();

$db  = Database::getInstance()->getConnection();
$u   = currentUser();
$bid = (int)$u['barangay_id'];

$mode = ($_POST['mode'] ?? 'selected') === 'all' ? 'all' : 'selected';

// ── Build WHERE clause ───────────────────────────────────────────────────
if ($mode === 'all') {
    $whereClause = "WHERE h.barangay_id = ? AND h.status != 'Archived'";
    $bindParams  = [$bid];
} else {
    $rawIds = $_POST['ids'] ?? [];
    if (empty($rawIds) || !is_array($rawIds)) {
        http_response_code(400); exit('No households selected.');
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $rawIds))));
    if (empty($ids)) { http_response_code(400); exit('Invalid household IDs.'); }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $whereClause  = "WHERE h.barangay_id = ? AND h.id IN ($placeholders)";
    $bindParams   = array_merge([$bid], $ids);
}

// ── Fetch households with head name + address ────────────────────────────
$stmt = $db->prepare("
    SELECT
        h.id,
        h.household_code,
        h.contact_number,
        h.total_members,
        h.total_senior,
        h.total_pwd,
        h.income_range,
        h.length_of_stay,
        h.status,
        h.archive_reason,
        h.created_at,
        CONCAT(r.last_name, ', ', r.first_name) AS head_name,
        r.contact_number  AS head_contact,
        r.gender          AS head_gender,
        r.age             AS head_age,
        r.civil_status    AS head_civil,
        r.voter_status    AS head_voter,
        r.occupation_status AS head_occupation,
        a.region,
        a.province,
        a.city,
        a.barangay        AS addr_barangay,
        a.street,
        a.purok,
        a.zone,
        a.zip_code
    FROM households h
    LEFT JOIN residents r ON h.head_resident_id = r.id
    LEFT JOIN addresses a ON h.address_id        = a.id
    $whereClause
    ORDER BY h.household_code ASC
");
$stmt->execute($bindParams);
$households = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($households)) {
    http_response_code(404); exit('No matching households found.');
}

// ── Fetch all members grouped by household ───────────────────────────────
$hhIds   = array_column($households, 'id');
$mPlaces = implode(',', array_fill(0, count($hhIds), '?'));
$mStmt   = $db->prepare("
    SELECT
        hm.household_id,
        hm.relationship,
        CONCAT(r.last_name, ', ', r.first_name) AS member_name,
        r.gender, r.age, r.civil_status,
        r.voter_status, r.occupation_status,
        r.is_pwd, r.is_senior, r.is_4ps, r.is_solo_parent
    FROM household_members hm
    JOIN residents r ON hm.resident_id = r.id
    WHERE hm.household_id IN ($mPlaces)
    ORDER BY hm.household_id, hm.relationship DESC
");
$mStmt->execute($hhIds);
$allMembers = $mStmt->fetchAll(PDO::FETCH_ASSOC);

$membersByHH = [];
foreach ($allMembers as $m) {
    $membersByHH[$m['household_id']][] = $m;
}

// ── Barangay name ─────────────────────────────────────────────────────────
$brgyNameStmt = $db->prepare("SELECT barangay_name FROM barangays WHERE id=?");
$brgyNameStmt->execute([$bid]);
$brgyName = $brgyNameStmt->fetchColumn() ?: 'Barangay';

logActivity($u['id'], 'EXPORT_HOUSEHOLDS', 'Households',
    'Exported ' . count($households) . ' household(s) to Excel (' . ($mode === 'all' ? 'all' : 'selected') . ')');

// ── Output Excel ──────────────────────────────────────────────────────────
$filename = 'households_' . strtolower(str_replace(' ', '_', $brgyName))
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
  body     { font-family: Arial, sans-serif; font-size: 11px; color: #222; }
  h2       { color: #1a4f7a; font-size: 16px; margin: 0 0 4px 0; font-weight: 800; }
  .meta    { font-size: 10px; color: #555; margin-bottom: 0; }
  .spacer  { height: 20px; }
  table    { border-collapse: collapse; width: 100%; margin-bottom: 24px; }
  th       { background: #f0f0f0; color: #222; padding: 5px 8px;
             border: 1px solid #bbb; font-size: 10px; text-align: left;
             white-space: nowrap; font-weight: 700; }
  td       { padding: 4px 8px; border: 1px solid #ddd; font-size: 10px; vertical-align: top; }
  tr:nth-child(even) td { background: #fafafa; }
  .hh-code-row td { background: #e0e0e0 !important; font-weight: 700; font-size: 11px; color: #111; }
  .sub-th th { background: #e8e8e8 !important; color: #333 !important; font-weight: 700; }
</style>
</head>
<body>

<h2>Barangay <?= htmlspecialchars($brgyName) ?> — Households Export <?= $mode === 'all' ? '(All Households)' : '(Selected)' ?></h2>
<div class="meta">
  <strong>Total Households:</strong> <?= count($households) ?> &nbsp;|&nbsp;
  <strong>Generated:</strong> <?= date('F d, Y h:i A') ?> &nbsp;|&nbsp;
  <strong>By:</strong> <?= htmlspecialchars($u['name']) ?>
</div>
<br><br>

<?php foreach ($households as $idx => $h):
    $members = $membersByHH[$h['id']] ?? [];
    $addr    = implode(', ', array_filter([
        $h['purok']         ? 'Purok ' . $h['purok'] : '',
        $h['street']        ?? '',
        $h['addr_barangay'] ?? '',
        $h['city']          ?? '',
        $h['province']      ?? '',
    ]));
?>
<table>
  <!-- Household code header row — grey highlight -->
  <tr class="hh-code-row">
    <td colspan="8"><?= $idx + 1 ?>. &nbsp; Household Code: <?= htmlspecialchars($h['household_code']) ?> &nbsp;|&nbsp; Status: <?= htmlspecialchars($h['status'] ?? 'Active') ?> &nbsp;|&nbsp; Registered: <?= $h['created_at'] ? date('M d, Y', strtotime($h['created_at'])) : '' ?></td>
  </tr>

  <!-- Summary -->
  <?php $lth = 'style="background:#f0f0f0;color:#222;font-weight:700;padding:4px 8px;border:1px solid #bbb;font-size:10px;"'; ?>
  <tr>
    <th <?= $lth ?>>Total Members</th><td><?= (int)$h['total_members'] ?></td>
    <th <?= $lth ?>>Senior Citizens</th><td><?= (int)$h['total_senior'] ?></td>
    <th <?= $lth ?>>PWD</th><td><?= (int)$h['total_pwd'] ?></td>
    <th <?= $lth ?>>Income Range</th><td><?= htmlspecialchars($h['income_range'] ?? '—') ?></td>
  </tr>
  <tr>
    <th <?= $lth ?>>Contact</th><td><?= htmlspecialchars($h['contact_number'] ?? '') ?></td>
    <th <?= $lth ?>>Length of Stay</th><td><?= $h['length_of_stay'] ? htmlspecialchars($h['length_of_stay']) . ' yr(s)' : '—' ?></td>
    <th <?= $lth ?>>Address</th><td colspan="3"><?= htmlspecialchars($addr ?: '—') ?></td>
  </tr>
  <tr>
    <th <?= $lth ?>>Region</th><td><?= htmlspecialchars($h['region'] ?? '') ?></td>
    <th <?= $lth ?>>Province</th><td><?= htmlspecialchars($h['province'] ?? '') ?></td>
    <th <?= $lth ?>>City / Municipality</th><td><?= htmlspecialchars($h['city'] ?? '') ?></td>
    <th <?= $lth ?>>Zip Code</th><td><?= htmlspecialchars($h['zip_code'] ?? '') ?></td>
  </tr>
  <?php if (!empty($h['archive_reason'])): ?>
  <tr>
    <th <?= $lth ?>>Archive Reason</th><td colspan="7"><?= htmlspecialchars($h['archive_reason']) ?></td>
  </tr>
  <?php endif; ?>

  <!-- Head of family -->
  <tr><th colspan="8" style="background:#e8e8e8;color:#333;font-weight:700;padding:4px 8px;border:1px solid #bbb;font-size:10px;">HEAD OF FAMILY</th></tr>
  <tr>
    <th <?= $lth ?>>Name</th><td><?= htmlspecialchars($h['head_name'] ?? 'N/A') ?></td>
    <th <?= $lth ?>>Gender</th><td><?= htmlspecialchars($h['head_gender'] ?? '') ?></td>
    <th <?= $lth ?>>Age</th><td><?= (int)($h['head_age'] ?? 0) ?></td>
    <th <?= $lth ?>>Civil Status</th><td><?= htmlspecialchars($h['head_civil'] ?? '') ?></td>
  </tr>
  <tr>
    <th <?= $lth ?>>Voter Status</th><td><?= htmlspecialchars($h['head_voter'] ?? '') ?></td>
    <th <?= $lth ?>>Occupation</th><td><?= htmlspecialchars($h['head_occupation'] ?? '') ?></td>
    <th <?= $lth ?>>Head Contact</th><td colspan="3"><?= htmlspecialchars($h['head_contact'] ?? '') ?></td>
  </tr>

  <!-- Members -->
  <tr><th colspan="8" style="background:#e8e8e8;color:#333;font-weight:700;padding:4px 8px;border:1px solid #bbb;font-size:10px;">HOUSEHOLD MEMBERS (<?= count($members) ?>)</th></tr>
  <?php if (empty($members)): ?>
  <tr><td colspan="8" style="color:#888;font-style:italic;">No members linked to this household.</td></tr>
  <?php else: ?>
  <tr>
    <th <?= $lth ?>>#</th>
    <th <?= $lth ?>>Name</th>
    <th <?= $lth ?>>Relationship</th>
    <th <?= $lth ?>>Gender</th>
    <th <?= $lth ?>>Age</th>
    <th <?= $lth ?>>Civil Status</th>
    <th <?= $lth ?>>Voter</th>
    <th <?= $lth ?>>Tags</th>
  </tr>
  <?php foreach ($members as $mi => $m):
    $tags = implode(', ', array_filter([
        $m['is_pwd']         ? 'PWD'         : '',
        $m['is_senior']      ? 'Senior'       : '',
        $m['is_4ps']         ? '4Ps'          : '',
        $m['is_solo_parent'] ? 'Solo Parent'  : '',
    ]));
  ?>
  <tr>
    <td><?= $mi + 1 ?></td>
    <td><?= htmlspecialchars($m['member_name']) ?></td>
    <td><?= htmlspecialchars($m['relationship'] ?? '') ?></td>
    <td><?= htmlspecialchars($m['gender'] ?? '') ?></td>
    <td><?= (int)$m['age'] ?></td>
    <td><?= htmlspecialchars($m['civil_status'] ?? '') ?></td>
    <td><?= htmlspecialchars($m['voter_status'] ?? '') ?></td>
    <td><?= $tags ?: '—' ?></td>
  </tr>
  <?php endforeach; ?>
  <?php endif; ?>
</table>

<?php endforeach; ?>

</body>
</html>
<?php
ob_end_flush();
exit;
