<?php
require_once __DIR__ . '/../config/config.php';
requireRole('barangay_admin');

$db  = Database::getInstance()->getConnection();
$u   = currentUser();
$bid = (int)$u['barangay_id'];

// ── CSV bulk export ──────────────────────────────────────────────────────
if (isset($_GET['format']) && $_GET['format'] === 'csv') {
    $rawIds = $_GET['ids'] ?? '';
    $ids = array_filter(array_map('intval', explode(',', $rawIds)));
    if (empty($ids)) die('No cases selected.');

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT bc.*, b.barangay_name, b.municipality,
        COALESCE(CONCAT(c.last_name,', ',c.first_name), bc.complainant_name_manual) as complainant_display,
        COALESCE(CONCAT(r.last_name,', ',r.first_name), bc.respondent_name_manual)  as respondent_display
        FROM blotter_cases bc
        LEFT JOIN barangays b ON bc.barangay_id = b.id
        LEFT JOIN residents c ON bc.complainant_id = c.id
        LEFT JOIN residents r ON bc.respondent_id  = r.id
        WHERE bc.id IN ($placeholders) AND bc.barangay_id = ?
        ORDER BY bc.incident_date DESC");
    $params = array_merge($ids, [$bid]);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    if (empty($rows)) die('No matching cases found.');

    logActivity($u['id'], 'EXPORT_CSV_BLOTTER', 'Blotter', count($rows) . ' cases exported as CSV');

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Blotter_Cases_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");

    fputcsv($out, ['BARANGAY BLOTTER REPORT — ' . date('F d, Y')]);
    fputcsv($out, []);
    fputcsv($out, [
        'Case No.', 'Case Title', 'Barangay', 'Municipality',
        'Incident Type', 'Priority', 'Status', 'Resolution',
        'Incident Date', 'Incident Time', 'Date & Time Reported', 'Location',
        'Complainant Name', 'Complainant Address', 'Complainant Contact',
        'Complainant Gender', 'Complainant Age',
        'Respondent Name', 'Respondent Address', 'Respondent Contact',
        'Relationship to Complainant',
        'Incident Narrative', 'Action Taken', 'Officer Assigned',
        'Date of Action', 'Settlement Agreement', 'Agreement Signed Date',
        'Date Closed', 'Remarks', 'Recorded By', 'Date Created'
    ]);

    foreach ($rows as $r) {
        fputcsv($out, [
            $r['case_number'],
            $r['case_title'] ?? '',
            $r['barangay_name'] ?? '',
            $r['municipality'] ?? '',
            $r['incident_type'] ?? '',
            $r['priority'] ?? 'Medium',
            $r['status'],
            $r['resolution'] ?? 'Pending',
            $r['incident_date'] ? date('F d, Y', strtotime($r['incident_date'])) : '',
            $r['incident_time'] ? date('h:i A', strtotime($r['incident_time'])) : '',
            $r['date_reported'] ? date('F d, Y h:i A', strtotime($r['date_reported'])) : '',
            $r['location'],
            $r['complainant_display'] ?? '',
            $r['complainant_address'] ?? '',
            $r['complainant_contact'] ?? '',
            $r['complainant_gender'] ?? '',
            $r['complainant_age'] ?? '',
            $r['respondent_display'] ?? '',
            $r['respondent_address'] ?? '',
            $r['respondent_contact'] ?? '',
            $r['respondent_relationship'] ?? '',
            $r['narrative'] ?? '',
            $r['action_taken'] ?? '',
            $r['officer_assigned'] ?? '',
            $r['date_of_action'] ? date('F d, Y', strtotime($r['date_of_action'])) : '',
            $r['settlement_agreement'] ? 'Yes' : 'No',
            !empty($r['settlement_date']) ? date('F d, Y', strtotime($r['settlement_date'])) : '',
            $r['date_closed'] ? date('F d, Y', strtotime($r['date_closed'])) : '',
            $r['remarks'] ?? '',
            $r['recorded_by'] ?? '',
            $r['created_at'] ? date('F d, Y', strtotime($r['created_at'])) : '',
        ]);
    }
    fclose($out);
    exit;
}

// ── Single case PDF/Print export ─────────────────────────────────────────
$id = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT bc.*, b.barangay_name, b.municipality, b.province,
    COALESCE(CONCAT(c.last_name,', ',c.first_name,' ',COALESCE(c.middle_name,'')), bc.complainant_name_manual) as complainant_name,
    COALESCE(CONCAT(r.last_name,', ',r.first_name,' ',COALESCE(r.middle_name,'')), bc.respondent_name_manual)  as respondent_name,
    c.birthdate as comp_birthdate, c.gender as comp_gender_db, c.contact_number as comp_contact_db,
    r.birthdate as resp_birthdate, r.gender as resp_gender_db, r.contact_number as resp_contact_db,
    CONCAT(cu.first_name,' ',cu.last_name) as created_by_name
    FROM blotter_cases bc
    LEFT JOIN barangays b ON bc.barangay_id = b.id
    LEFT JOIN residents c ON bc.complainant_id = c.id
    LEFT JOIN residents r ON bc.respondent_id  = r.id
    LEFT JOIN users cu ON bc.created_by = cu.id
    WHERE bc.id = ? AND bc.barangay_id = ?");
$stmt->execute([$id, $bid]);
$case = $stmt->fetch();

if (!$case) { http_response_code(404); die('<p style="font-family:sans-serif;padding:2rem;">Case not found.</p>'); }

$witnesses = $db->prepare("SELECT * FROM blotter_witnesses WHERE blotter_id = ? ORDER BY id");
$witnesses->execute([$id]);
$witnesses = $witnesses->fetchAll();

$attachments = $db->prepare("SELECT * FROM blotter_attachments WHERE blotter_id = ? ORDER BY uploaded_at");
$attachments->execute([$id]);
$attachments = $attachments->fetchAll();

$piExport = $db->prepare("SELECT bpi.*, COALESCE(CONCAT(res.last_name,', ',res.first_name), bpi.full_name) as display_name
    FROM blotter_persons_involved bpi
    LEFT JOIN residents res ON bpi.resident_id = res.id
    WHERE bpi.blotter_id = ? ORDER BY bpi.id");
$piExport->execute([$id]);
$piExport = $piExport->fetchAll();
$suspectsPdf = array_values(array_filter($piExport, fn($p) => ($p['role'] ?? '') === 'Suspect'));
$otherPIPdf  = array_values(array_filter($piExport, fn($p) => ($p['role'] ?? '') !== 'Suspect'));

// Timeline
$tlStmt = $db->prepare("SELECT * FROM blotter_timeline WHERE blotter_id = ? ORDER BY created_at ASC");
$tlStmt->execute([$id]);
$timeline = $tlStmt->fetchAll();

logActivity($u['id'], 'EXPORT_BLOTTER', 'Blotter', "Printed/Exported case: {$case['case_number']}");

// Derived fields
$compName    = trim($case['complainant_name'] ?? $case['complainant_name_manual'] ?? 'N/A');
$respName    = trim($case['respondent_name']  ?? $case['respondent_name_manual']  ?? 'N/A');
$compGender  = $case['comp_gender_db']  ?? $case['complainant_gender']  ?? '—';
$compContact = $case['comp_contact_db'] ?? $case['complainant_contact'] ?? '—';
$respContact = $case['resp_contact_db'] ?? $case['respondent_contact']  ?? '—';
$compAge = '—';
if (!empty($case['comp_birthdate'])) {
    $compAge = (new DateTime())->diff(new DateTime($case['comp_birthdate']))->y;
} elseif (!empty($case['complainant_age'])) {
    $compAge = $case['complainant_age'];
}

$statusColors = ['Open'=>'#f59e0b','Under Investigation'=>'#3b82f6','Referred'=>'#6366f1','Escalated'=>'#ef4444','Settled'=>'#22c55e','Dismissed'=>'#374151','Closed'=>'#6b7280'];
$priColors    = ['Low'=>'#22c55e','Medium'=>'#f59e0b','High'=>'#ef4444'];
$resColors    = ['Pending'=>'#6b7280','Settled'=>'#22c55e','Referred'=>'#6366f1','Dismissed'=>'#374151'];
$statusColor  = $statusColors[$case['status']] ?? '#6b7280';
$priColor     = $priColors[$case['priority'] ?? 'Medium'] ?? '#f59e0b';
$resColor     = $resColors[$case['resolution'] ?? 'Pending'] ?? '#6b7280';

// Parse evidence type from description
function parseEvidenceType(array $att): array {
    $raw = $att['description'] ?? '';
    $type = $att['file_type'] ? strtoupper($att['file_type']) : '—';
    $desc = $raw;
    if (preg_match('/^\[([^\]]+)\]\s*(.*)$/s', $raw, $m)) {
        $type = $m[1];
        $desc = trim($m[2]);
    }
    return ['type' => $type, 'desc' => $desc];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favcon.png">
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favcon.png">
<meta charset="UTF-8">
<title>Blotter Report — <?= htmlspecialchars($case['case_number']) ?></title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Arial', sans-serif;
    font-size: 10pt;
    color: #1a1a2e;
    background: #f0f4f8;
    padding: 20px;
}
.page {
    background: #fff;
    max-width: 840px;
    margin: 0 auto;
    padding: 32px 36px 40px;
    box-shadow: 0 4px 24px rgba(0,0,0,0.10);
    border-radius: 4px;
}
.no-print {
    max-width: 840px;
    margin: 0 auto 12px;
    display: flex;
    gap: 8px;
    align-items: center;
}
.no-print button, .no-print a {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 18px; border-radius: 7px; border: none;
    cursor: pointer; font-size: 0.85rem; font-weight: 600; text-decoration: none;
}
.btn-print  { background: #1a4f7a; color: #fff; }
.btn-close-pg { background: #e5e7eb; color: #374151; }
@media print {
    .no-print { display: none !important; }
    body { background: #fff; padding: 0; }
    .page { box-shadow: none; padding: 18px 24px; }
    .page-break { page-break-before: always; }
}

/* Header */
.doc-header { border-bottom: 3px solid #1a4f7a; padding-bottom: 14px; margin-bottom: 18px; }
.doc-header-top { display: flex; align-items: center; gap: 16px; margin-bottom: 8px; }
.header-logo {
    width: 64px; height: 64px; border: 3px solid #1a4f7a; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.6rem; flex-shrink: 0; background: #eef4fb; color: #1a4f7a;
}
.header-text { flex: 1; text-align: center; }
.header-text .republic  { font-size: 8pt; color: #555; letter-spacing: 0.06em; }
.header-text .province   { font-size: 8.5pt; color: #333; margin: 1px 0; }
.header-text .brgy-name  { font-size: 14pt; font-weight: 700; color: #1a4f7a; margin: 2px 0; }
.header-text .doc-title  { font-size: 11pt; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #1a2e4a; margin-top: 4px; }
.header-right { text-align: right; font-size: 8pt; color: #555; min-width: 120px; }

/* Case banner */
.case-meta {
    background: #1a4f7a; color: #fff; border-radius: 8px;
    padding: 12px 18px; margin-bottom: 18px;
}
.case-meta .case-num   { font-size: 14pt; font-weight: 800; letter-spacing: 0.03em; }
.case-meta .case-title-line { font-size: 10pt; color: rgba(255,255,255,0.85); margin-top: 2px; font-weight: 500; }
.case-meta .badges     { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px; }
.case-meta .meta-row   { font-size: 8pt; opacity: 0.8; margin-top: 5px; }
.badge-pill {
    font-size: 7.5pt; font-weight: 700; padding: 3px 10px;
    border-radius: 20px; color: #fff; text-transform: uppercase; letter-spacing: 0.04em;
}

/* Sections */
.section { margin-bottom: 18px; }
.section-title {
    font-size: 8pt; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.08em; color: #1a4f7a;
    border-bottom: 1.5px solid #c5ddf0; padding-bottom: 4px; margin-bottom: 10px;
    display: flex; align-items: center; gap: 6px;
}
.section-title::before {
    content: ''; display: inline-block; width: 4px; height: 14px;
    background: #1a4f7a; border-radius: 2px; flex-shrink: 0;
}

/* Person cards */
.person-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.person-card { border: 1px solid #d0e4f0; border-radius: 6px; overflow: hidden; }
.person-card-header {
    background: #eef4fb; padding: 5px 10px;
    font-size: 8pt; font-weight: 700; text-transform: uppercase;
    letter-spacing: 0.06em; color: #1a4f7a;
}
.person-card-header.respondent { background: #fff0f0; color: #b91c1c; }
.person-card-header.suspect    { background: #f3e8ff; color: #7c3aed; }
.person-card-body { padding: 8px 10px; font-size: 9pt; }
.person-field { display: flex; gap: 4px; margin-bottom: 3px; }
.person-label { color: #6b7280; min-width: 75px; font-size: 8.5pt; }
.person-value { font-weight: 600; color: #1a1a2e; }

/* Info grid */
.info-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
.info-label { font-size: 7.5pt; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 1px; }
.info-value { font-size: 9.5pt; font-weight: 600; color: #1a1a2e; }

/* Status row */
.status-row { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 10px; }

/* Narrative */
.narrative-label { font-size: 8pt; font-weight: 700; color: #3a7fa8; margin-bottom: 3px; text-transform: uppercase; margin-top: 8px; }
.narrative-label:first-child { margin-top: 0; }
.narrative-box {
    background: #f8fafc; border: 1px solid #d0e4f0; border-radius: 5px;
    padding: 9px 12px; font-size: 9.5pt; line-height: 1.65; color: #1a1a2e; min-height: 40px;
}

/* Tables */
.data-table { width: 100%; border-collapse: collapse; font-size: 8.5pt; margin-top: 4px; }
.data-table thead tr { background: #1a4f7a; color: #fff; }
.data-table thead th { padding: 6px 8px; text-align: left; font-size: 7.5pt; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 700; }
.data-table tbody tr { border-bottom: 1px solid #e8eff5; }
.data-table tbody tr:nth-child(even) { background: #f5f9fc; }
.data-table tbody td { padding: 5px 8px; vertical-align: top; }

/* Timeline */
.tl-row { display: flex; gap: 10px; margin-bottom: 10px; }
.tl-dot {
    width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0;
    margin-top: 3px; border: 2px solid #fff; box-shadow: 0 0 0 1.5px currentColor;
}
.tl-line { width: 1.5px; background: #d0e4f0; margin: 0 auto; height: 100%; }
.tl-event { font-size: 8.5pt; font-weight: 700; color: #1a2e1f; }
.tl-meta  { font-size: 7.5pt; color: #6b7280; margin-top: 1px; }
.tl-note  { font-size: 8pt; color: #444; background: #f8fafc; border-left: 2.5px solid #d0e4f0; padding: 3px 7px; margin-top: 3px; border-radius: 0 3px 3px 0; }

/* Signatures */
.sig-row { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 24px; margin-top: 36px; }
.sig-block { text-align: center; }
.sig-line  { border-top: 1.5px solid #333; margin: 44px auto 5px; width: 88%; }
.sig-name  { font-weight: 700; font-size: 9.5pt; }
.sig-title { font-size: 8pt; color: #555; }

/* Footer */
.doc-footer {
    margin-top: 20px; padding-top: 8px; border-top: 1px solid #d0e4f0;
    display: flex; justify-content: space-between; font-size: 7.5pt; color: #9ca3af;
}
</style>
</head>
<body>

<div class="no-print">
    <button class="btn-print" onclick="window.print()">🖨 Print / Save as PDF</button>
    <a class="btn-close-pg" href="<?= BASE_URL ?>/barangay_admin/blotter.php?view=<?= $case['id'] ?>">← Back to Case</a>
</div>

<div class="page">

  <!-- ── DOCUMENT HEADER ── -->
  <div class="doc-header">
    <div class="doc-header-top">
      <div class="header-logo">⚖</div>
      <div class="header-text">
        <div class="republic">Republic of the Philippines</div>
        <div class="province">Province of <?= htmlspecialchars($case['province'] ?? 'Nueva Ecija') ?> &nbsp;·&nbsp; <?= htmlspecialchars($case['municipality'] ?? '') ?></div>
        <div class="brgy-name">Barangay <?= htmlspecialchars($case['barangay_name'] ?? '') ?></div>
        <div class="doc-title">Official Blotter Report</div>
      </div>
      <div class="header-right">
        <div style="font-size:7.5pt;color:#888;">Printed by</div>
        <div style="font-weight:600;color:#1a4f7a;"><?= htmlspecialchars($u['name']) ?></div>
        <div style="margin-top:4px;"><?= date('F d, Y') ?></div>
        <div><?= date('h:i A') ?></div>
      </div>
    </div>
  </div>

  <!-- ── CASE BANNER ── -->
  <div class="case-meta">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;">
      <div>
        <div class="case-num">CASE # <?= htmlspecialchars($case['case_number']) ?></div>
        <?php if (!empty($case['case_title'])): ?>
        <div class="case-title-line"><?= htmlspecialchars($case['case_title']) ?></div>
        <?php endif; ?>
        <div class="badges">
          <span class="badge-pill" style="background:<?= $statusColor ?>;"><?= htmlspecialchars($case['status']) ?></span>
          <span class="badge-pill" style="background:<?= $priColor ?>;"><?= htmlspecialchars($case['priority'] ?? 'Medium') ?> Priority</span>
          <span class="badge-pill" style="background:<?= $resColor ?>;"><?= htmlspecialchars($case['resolution'] ?? 'Pending') ?></span>
          <?php if ($case['settlement_agreement']): ?>
          <span class="badge-pill" style="background:#22c55e;">✔ Settlement Signed</span>
          <?php endif; ?>
        </div>
      </div>
      <div style="text-align:right;font-size:8pt;opacity:0.85;">
        <div>Reported: <?= $case['date_reported'] ? date('M d, Y h:i A', strtotime($case['date_reported'])) : '—' ?></div>
        <div>Filed: <?= date('M d, Y', strtotime($case['created_at'])) ?></div>
        <?php if (!empty($case['officer_assigned'])): ?>
        <div>Officer: <?= htmlspecialchars($case['officer_assigned']) ?></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ── SECTION 1: Incident Details ── -->
  <div class="section">
    <div class="section-title">1. Incident Details</div>
    <div class="info-grid">
      <div>
        <div class="info-label">Incident Type</div>
        <div class="info-value"><?= htmlspecialchars($case['incident_type'] ?? 'N/A') ?></div>
      </div>
      <div>
        <div class="info-label">Incident Date</div>
        <div class="info-value"><?= date('F d, Y', strtotime($case['incident_date'])) ?></div>
      </div>
      <div>
        <div class="info-label">Incident Time</div>
        <div class="info-value"><?= $case['incident_time'] ? date('h:i A', strtotime($case['incident_time'])) : '—' ?></div>
      </div>
      <div style="grid-column:1/-1;">
        <div class="info-label">Location / Place of Incident</div>
        <div class="info-value"><?= htmlspecialchars($case['location']) ?></div>
      </div>
    </div>
  </div>

  <!-- ── SECTION 2: Persons Involved ── -->
  <div class="section">
    <div class="section-title">2. Persons Involved</div>
    <div class="person-grid">
      <!-- Complainant -->
      <div class="person-card">
        <div class="person-card-header">Complainant / Reporting Person</div>
        <div class="person-card-body">
          <div class="person-field"><span class="person-label">Full Name:</span><span class="person-value"><?= htmlspecialchars($compName) ?></span></div>
          <div class="person-field"><span class="person-label">Address:</span><span class="person-value"><?= htmlspecialchars($case['complainant_address'] ?? '—') ?></span></div>
          <div class="person-field"><span class="person-label">Contact:</span><span class="person-value"><?= htmlspecialchars($compContact) ?></span></div>
          <div class="person-field"><span class="person-label">Gender:</span><span class="person-value"><?= htmlspecialchars($compGender) ?></span></div>
          <div class="person-field"><span class="person-label">Age:</span><span class="person-value"><?= htmlspecialchars((string)$compAge) ?></span></div>
        </div>
      </div>
      <!-- Respondent -->
      <div class="person-card">
        <div class="person-card-header respondent">Respondent</div>
        <div class="person-card-body">
          <div class="person-field"><span class="person-label">Full Name:</span><span class="person-value"><?= htmlspecialchars($respName) ?></span></div>
          <div class="person-field"><span class="person-label">Address:</span><span class="person-value"><?= htmlspecialchars($case['respondent_address'] ?? '—') ?></span></div>
          <div class="person-field"><span class="person-label">Contact:</span><span class="person-value"><?= htmlspecialchars($respContact) ?></span></div>
          <div class="person-field"><span class="person-label">Relationship:</span><span class="person-value"><?= htmlspecialchars($case['respondent_relationship'] ?? '—') ?></span></div>
        </div>
      </div>
      <?php foreach ($suspectsPdf as $sp): ?>
      <div class="person-card">
        <div class="person-card-header suspect">Suspect</div>
        <div class="person-card-body">
          <div class="person-field"><span class="person-label">Full Name:</span><span class="person-value"><?= htmlspecialchars($sp['display_name'] ?? $sp['full_name']) ?></span></div>
          <?php if ($sp['address']): ?><div class="person-field"><span class="person-label">Address:</span><span class="person-value"><?= htmlspecialchars($sp['address']) ?></span></div><?php endif; ?>
          <?php if ($sp['contact']): ?><div class="person-field"><span class="person-label">Contact:</span><span class="person-value"><?= htmlspecialchars($sp['contact']) ?></span></div><?php endif; ?>
          <?php if ($sp['gender']): ?><div class="person-field"><span class="person-label">Gender:</span><span class="person-value"><?= htmlspecialchars($sp['gender']) ?></span></div><?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php if (!empty($otherPIPdf)): ?>
    <div style="margin-top:10px;">
      <div style="font-size:8pt;font-weight:700;color:#3a7fa8;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:5px;">Other Persons Involved</div>
      <table class="data-table">
        <thead><tr><th>#</th><th>Name</th><th>Role</th><th>Address</th><th>Contact</th></tr></thead>
        <tbody>
        <?php foreach ($otherPIPdf as $oi => $op): ?>
          <tr><td><?= $oi+1 ?></td><td><?= htmlspecialchars($op['display_name'] ?? $op['full_name']) ?></td><td><?= htmlspecialchars($op['role'] ?? 'Person Involved') ?></td><td><?= htmlspecialchars($op['address'] ?? '—') ?></td><td><?= htmlspecialchars($op['contact'] ?? '—') ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
    <?php if (!empty($witnesses)): ?>
    <div style="margin-top:10px;">
      <div style="font-size:8pt;font-weight:700;color:#3a7fa8;text-transform:uppercase;letter-spacing:0.06em;margin-bottom:5px;">Witnesses (<?= count($witnesses) ?>)</div>
      <table class="data-table">
        <thead><tr><th>#</th><th>Name</th><th>Address</th><th>Contact No.</th></tr></thead>
        <tbody>
        <?php foreach ($witnesses as $wi => $w): ?>
          <tr><td><?= $wi+1 ?></td><td><?= htmlspecialchars($w['name']) ?></td><td><?= htmlspecialchars($w['address'] ?? '—') ?></td><td><?= htmlspecialchars($w['contact'] ?? '—') ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── SECTION 3: Narrative ── -->
  <div class="section">
    <div class="section-title">3. Narrative / Statement of Facts</div>
    <?php if (!empty($case['incident_summary'])): ?>
    <div class="narrative-label">Incident Summary</div>
    <div class="narrative-box"><?= nl2br(htmlspecialchars($case['incident_summary'])) ?></div>
    <?php endif; ?>
    <div class="narrative-label">Detailed Narrative</div>
    <div class="narrative-box"><?= nl2br(htmlspecialchars($case['detailed_narrative'] ?? $case['narrative'] ?? '—')) ?></div>
    <div style="font-size:8pt;color:#6b7280;margin-top:6px;">
      Recorded by: <strong><?= htmlspecialchars($case['recorded_by'] ?? $case['created_by_name'] ?? '—') ?></strong>
      &nbsp;·&nbsp;
      <?= $case['date_reported'] ? date('F d, Y h:i A', strtotime($case['date_reported'])) : date('F d, Y', strtotime($case['created_at'])) ?>
    </div>
  </div>

  <!-- ── SECTION 4: Case Status ── -->
  <div class="section">
    <div class="section-title">4. Case Status &amp; Action</div>
    <div class="status-row">
      <span class="badge-pill" style="background:<?= $statusColor ?>;"><?= htmlspecialchars($case['status']) ?></span>
      <span class="badge-pill" style="background:<?= $resColor ?>;"><?= htmlspecialchars($case['resolution'] ?? 'Pending') ?></span>
      <?php if ($case['settlement_agreement']): ?>
      <span class="badge-pill" style="background:#22c55e;">✔ Settlement Signed</span>
      <?php endif; ?>
    </div>
    <div class="info-grid" style="margin-bottom:10px;">
      <?php if (!empty($case['officer_assigned'])): ?>
      <div><div class="info-label">Officer Assigned</div><div class="info-value"><?= htmlspecialchars($case['officer_assigned']) ?></div></div>
      <?php endif; ?>
      <?php if (!empty($case['date_of_action'])): ?>
      <div><div class="info-label">Date of Action</div><div class="info-value"><?= date('F d, Y', strtotime($case['date_of_action'])) ?></div></div>
      <?php endif; ?>
      <?php if (!empty($case['date_closed'])): ?>
      <div><div class="info-label">Date Closed</div><div class="info-value"><?= date('F d, Y', strtotime($case['date_closed'])) ?></div></div>
      <?php endif; ?>
      <?php if ($case['settlement_agreement'] && !empty($case['settlement_date'])): ?>
      <div><div class="info-label">Agreement Signed Date</div><div class="info-value"><?= date('F d, Y', strtotime($case['settlement_date'])) ?></div></div>
      <?php endif; ?>
    </div>
    <?php if (!empty($case['action_taken'])): ?>
    <div class="narrative-label">Action Taken</div>
    <div class="narrative-box"><?= nl2br(htmlspecialchars($case['action_taken'])) ?></div>
    <?php endif; ?>
    <?php if (!empty($case['remarks'])): ?>
    <div class="narrative-label">Remarks / Additional Notes</div>
    <div class="narrative-box"><?= nl2br(htmlspecialchars($case['remarks'])) ?></div>
    <?php endif; ?>
  </div>

  <!-- ── SECTION 5: Evidence / Attachments ── -->
  <?php if (!empty($attachments)): ?>
  <div class="section">
    <div class="section-title">5. Evidence &amp; Attachments (<?= count($attachments) ?>)</div>
    <table class="data-table">
      <thead><tr>
        <th style="width:24px;">#</th>
        <th>Evidence Type</th>
        <th>Description</th>
        <th>Supporting Documents / File</th>
        <th>Date Submitted</th>
        <th>Uploaded By</th>
      </tr></thead>
      <tbody>
      <?php foreach ($attachments as $ai => $att):
          $ev = parseEvidenceType($att);
      ?>
        <tr>
          <td><?= $ai + 1 ?></td>
          <td style="font-weight:600;"><?= htmlspecialchars($ev['type']) ?></td>
          <td><?= htmlspecialchars($ev['desc'] ?: '—') ?></td>
          <td><?= htmlspecialchars($att['file_name']) ?></td>
          <td><?= $att['uploaded_at'] ? date('M d, Y', strtotime($att['uploaded_at'])) : '—' ?></td>
          <td><?= htmlspecialchars($att['uploaded_by'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- ── SECTION 6: Case History / Timeline ── -->
  <?php if (!empty($timeline)): ?>
  <div class="section">
    <div class="section-title">6. Case History</div>
    <?php
    $tlStatusColors = ['Open'=>'#f59e0b','Under Investigation'=>'#3b82f6','Referred'=>'#6366f1','Escalated'=>'#ef4444','Settled'=>'#22c55e','Dismissed'=>'#374151','Closed'=>'#6b7280'];
    foreach ($timeline as $ti => $tl):
        $isLast  = ($ti === count($timeline) - 1);
        $dotCol  = strpos($tl['event'],'Created')!==false ? '#1a4f7a' :
                  (strpos($tl['event'],'Status')!==false  ? '#e67e22' :
                  (strpos($tl['event'],'Closed')!==false  ? '#22c55e' : '#3a7fa8'));
        $tlBadgeBg = $tlStatusColors[$tl['status'] ?? ''] ?? '#6b7280';
    ?>
    <div class="tl-row">
      <div style="display:flex;flex-direction:column;align-items:center;flex-shrink:0;width:14px;">
        <div class="tl-dot" style="color:<?= $dotCol ?>;background:<?= $dotCol ?>;"></div>
        <?php if (!$isLast): ?><div class="tl-line" style="flex:1;min-height:16px;"></div><?php endif; ?>
      </div>
      <div style="flex:1;padding-bottom:<?= $isLast ? '0' : '4px' ?>;">
        <div style="display:flex;align-items:center;gap:7px;flex-wrap:wrap;">
          <span class="tl-event"><?= htmlspecialchars($tl['event']) ?></span>
          <?php if (!empty($tl['status'])): ?>
          <span class="badge-pill" style="background:<?= $tlBadgeBg ?>;font-size:7pt;"><?= htmlspecialchars($tl['status']) ?></span>
          <?php endif; ?>
        </div>
        <div class="tl-meta">
          <?= date('M d, Y g:i A', strtotime($tl['created_at'])) ?>
          &nbsp;·&nbsp; <?= htmlspecialchars($tl['actor'] ?? '—') ?>
        </div>
        <?php if (!empty($tl['note'])): ?>
        <div class="tl-note" style="border-left-color:<?= $dotCol ?>;"><?= htmlspecialchars($tl['note']) ?></div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- ── SIGNATURE SECTION ── -->
  <div class="sig-row">
    <div class="sig-block">
      <div class="sig-line"></div>
      <div class="sig-name"><?= htmlspecialchars($compName) ?></div>
      <div class="sig-title">Complainant's Signature over Printed Name</div>
    </div>
    <div class="sig-block">
      <div class="sig-line"></div>
      <div class="sig-name"><?= htmlspecialchars($case['officer_assigned'] ?? '___________________') ?></div>
      <div class="sig-title">Investigating Officer</div>
    </div>
    <div class="sig-block">
      <div class="sig-line"></div>
      <div class="sig-name">Punong Barangay</div>
      <div class="sig-title">Barangay <?= htmlspecialchars($case['barangay_name'] ?? '') ?></div>
    </div>
  </div>

  <!-- ── DOCUMENT FOOTER ── -->
  <div class="doc-footer">
    <span>Case No.: <?= htmlspecialchars($case['case_number']) ?><?= !empty($case['case_title']) ? ' — ' . htmlspecialchars($case['case_title']) : '' ?> &nbsp;·&nbsp; Barangay <?= htmlspecialchars($case['barangay_name'] ?? '') ?>, <?= htmlspecialchars($case['municipality'] ?? '') ?></span>
    <span>Official Barangay Blotter Record &nbsp;·&nbsp; Printed: <?= date('F d, Y h:i A') ?></span>
  </div>

</div><!-- /page -->

<script>
(function () {
    var params = new URLSearchParams(window.location.search);
    var autoPrint = params.get('print') === '1';

    if (autoPrint) {
        // Hide the toolbar immediately so it doesn't flash
        var toolbar = document.querySelector('.no-print');
        if (toolbar) {
            toolbar.innerHTML =
                '<div style="display:flex;gap:8px;align-items:center;">' +
                '<span style="font-size:0.82rem;color:#64748b;">Preparing print preview…</span>' +
                '<button class="btn-print" onclick="window.print()" style="padding:5px 14px;font-size:0.8rem;">🖨 Print now</button>' +
                '<a class="btn-close-pg" href="<?= BASE_URL ?>/barangay_admin/blotter.php?view=<?= $case['id'] ?>" style="padding:5px 14px;font-size:0.8rem;">← Back</a>' +
                '</div>';
        }

        // Trigger print once the full page (all resources) has loaded
        window.addEventListener('load', function () {
            // Small delay to allow CSS to paint completely
            setTimeout(function () {
                window.print();
            }, 350);
        });

        // After print dialog closes: go back to the blotter case view
        window.addEventListener('afterprint', function () {
            window.location.href = '<?= BASE_URL ?>/barangay_admin/blotter.php?view=<?= $case['id'] ?>';
        });
    }
})();
</script>
</body>
</html>
