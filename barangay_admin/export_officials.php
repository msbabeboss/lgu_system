<?php
require_once __DIR__ . '/../config/config.php';
requireRole('barangay_admin');

$db  = Database::getInstance()->getConnection();
$u   = currentUser();
$bid = (int)$u['barangay_id'];

// ── Fetch all officials for this barangay ────────────────────────────────
$stmt = $db->prepare("
    SELECT full_name, position, contact, email,
           term_start, term_end, status
    FROM officials
    WHERE barangay_id = ?
    ORDER BY FIELD(status,'active','inactive'), position, full_name
");
$stmt->execute([$bid]);
$officials = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Barangay name ─────────────────────────────────────────────────────────
$brgyName = $db->prepare("SELECT barangay_name FROM barangays WHERE id=?");
$brgyName->execute([$bid]);
$brgyName = $brgyName->fetchColumn() ?: 'Barangay';

logActivity($u['id'], 'EXPORT_OFFICIALS', 'Officials',
    'Exported ' . count($officials) . ' official(s) to Excel');

// ── Output ────────────────────────────────────────────────────────────────
$filename = 'officials_' . strtolower(str_replace(' ', '_', $brgyName))
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
  body  { font-family: Arial, sans-serif; font-size: 11px; color: #222; }
  h2    { color: #1a4f7a; font-size: 13px; margin: 0 0 2px 0; }
  .meta { font-size: 10px; color: #555; margin-bottom: 14px; }
  table { border-collapse: collapse; width: 100%; }
  th    { background: #ebecec; color: #fff; padding: 5px 8px;
          border: 1px solid #1a4f7a; font-size: 10px; text-align: left;
          white-space: nowrap; font-weight: 700; }
  td    { padding: 4px 8px; border: 1px solid #ddd; font-size: 10px; vertical-align: top; }
  tr:nth-child(even) td { background: #f5f8fc; }
</style>
</head>
<body>

<h2>Barangay <?= htmlspecialchars($brgyName) ?> — Officials Export</h2>
<div class="meta">
  <strong>Total Officials:</strong> <?= count($officials) ?> &nbsp;|&nbsp;
  <strong>Generated:</strong> <?= date('F d, Y h:i A') ?> &nbsp;|&nbsp;
  <strong>By:</strong> <?= htmlspecialchars($u['name']) ?>
</div>

<table>
  <thead>
    <tr>
      <th>#</th>
      <th>Full Name</th>
      <th>Position</th>
      <th>Contact</th>
      <th>Email</th>
      <th>Term Start</th>
      <th>Term End</th>
      <th>Status</th>
    </tr>
  </thead>
  <tbody>
  <?php if (empty($officials)): ?>
    <tr><td colspan="8" style="text-align:center;color:#888;font-style:italic;">No officials found.</td></tr>
  <?php else: ?>
    <?php foreach ($officials as $i => $o): ?>
    <tr>
      <td><?= $i + 1 ?></td>
      <td><?= htmlspecialchars($o['full_name'] ?? '') ?></td>
      <td><?= htmlspecialchars($o['position'] ?? '') ?></td>
      <td><?= htmlspecialchars($o['contact'] ?? '') ?></td>
      <td><?= htmlspecialchars($o['email'] ?? '') ?></td>
      <td><?= $o['term_start'] ? date('M d, Y', strtotime($o['term_start'])) : '' ?></td>
      <td><?= $o['term_end']   ? date('M d, Y', strtotime($o['term_end']))   : '' ?></td>
      <td><?= ucfirst(htmlspecialchars($o['status'] ?? '')) ?></td>
    </tr>
    <?php endforeach; ?>
  <?php endif; ?>
  </tbody>
</table>

</body>
</html>
<?php
ob_end_flush();
exit;
