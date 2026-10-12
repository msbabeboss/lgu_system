<?php
require_once __DIR__ . '/../config/config.php';
requireRole('barangay_admin');

$db       = Database::getInstance()->getConnection();
$u        = currentUser();
$bid      = (int)$u['barangay_id'];
$category = sanitize($_GET['category']  ?? 'summary');
$dateFrom = sanitize($_GET['date_from'] ?? '');
$dateTo   = sanitize($_GET['date_to']   ?? '');
$format   = sanitize($_GET['format']    ?? 'excel');
$periodLabel = ($dateFrom && $dateTo)
    ? date('M d, Y', strtotime($dateFrom)) . ' to ' . date('M d, Y', strtotime($dateTo))
    : 'All Time';
$filename = 'report_' . $category . '_' . ($dateFrom ?: 'alltime') . ($dateTo ? '_to_' . $dateTo : '');

// ── Fetch ALL data for the selected category ───────────────

// ── All-time Total Transactions (always fetched, shown on every export) ──
$brgyName = $db->query("SELECT barangay_name FROM barangays WHERE id=$bid")->fetchColumn();

$totals = [];
// Residents
$totals['total_residents_alltime']    = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid")->fetchColumn();
$totals['active_residents']           = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND resident_status='Active'")->fetchColumn();
$totals['archived_residents']         = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND resident_status='Archived'")->fetchColumn();
// Demographics (all-time active)
$totals['male']                       = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND gender='Male' AND resident_status='Active'")->fetchColumn();
$totals['female']                     = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND gender='Female' AND resident_status='Active'")->fetchColumn();
$totals['registered_voters']          = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND voter_status='Registered' AND resident_status='Active'")->fetchColumn();
$totals['pwd']                        = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND is_pwd=1 AND resident_status='Active'")->fetchColumn();
$totals['senior_citizens']            = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND is_senior=1 AND resident_status='Active'")->fetchColumn();
$totals['4ps']                        = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND is_4ps=1 AND resident_status='Active'")->fetchColumn();
$totals['solo_parents']               = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND is_solo_parent=1 AND resident_status='Active'")->fetchColumn();
// Employment
$totals['employed']                   = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND occupation_status='Employed' AND resident_status='Active'")->fetchColumn();
$totals['unemployed']                 = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND occupation_status='Unemployed' AND resident_status='Active'")->fetchColumn();
$totals['self_employed']              = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND occupation_status='Self-Employed' AND resident_status='Active'")->fetchColumn();
$totals['student']                    = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND occupation_status='Student' AND resident_status='Active'")->fetchColumn();
$totals['retired']                    = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND occupation_status='Retired' AND resident_status='Active'")->fetchColumn();
// Households
$totals['total_households']           = (int)$db->query("SELECT COUNT(*) FROM households WHERE barangay_id=$bid")->fetchColumn();
$totals['total_household_members']    = (int)$db->query("SELECT SUM(total_members) FROM households WHERE barangay_id=$bid")->fetchColumn();
// Certificates
$totals['total_certificates']         = (int)$db->query("SELECT COUNT(*) FROM certificates WHERE barangay_id=$bid")->fetchColumn();
// Certificate breakdown
$certBreakdown = $db->query("SELECT certificate_type, COUNT(*) as cnt FROM certificates WHERE barangay_id=$bid GROUP BY certificate_type ORDER BY cnt DESC")->fetchAll();
// Blotter
$totals['total_blotter']              = (int)$db->query("SELECT COUNT(*) FROM blotter_cases WHERE barangay_id=$bid")->fetchColumn();
$totals['blotter_open']               = (int)$db->query("SELECT COUNT(*) FROM blotter_cases WHERE barangay_id=$bid AND status='Open'")->fetchColumn();
$totals['blotter_settled']            = (int)$db->query("SELECT COUNT(*) FROM blotter_cases WHERE barangay_id=$bid AND status='Settled'")->fetchColumn();
$totals['blotter_escalated']          = (int)$db->query("SELECT COUNT(*) FROM blotter_cases WHERE barangay_id=$bid AND status='Escalated'")->fetchColumn();
$totals['blotter_closed']             = (int)$db->query("SELECT COUNT(*) FROM blotter_cases WHERE barangay_id=$bid AND status='Closed'")->fetchColumn();
// Officials
$totals['active_officials']           = (int)$db->query("SELECT COUNT(*) FROM officials WHERE barangay_id=$bid AND status='active'")->fetchColumn();
// User Requests
$totals['total_requests']             = (int)$db->query("SELECT COUNT(*) FROM user_requests WHERE barangay_id=$bid")->fetchColumn();
$totals['pending_requests']           = (int)$db->query("SELECT COUNT(*) FROM user_requests WHERE barangay_id=$bid AND status='pending'")->fetchColumn();
$totals['approved_requests']          = (int)$db->query("SELECT COUNT(*) FROM user_requests WHERE barangay_id=$bid AND status='approved'")->fetchColumn();
$totals['rejected_requests']          = (int)$db->query("SELECT COUNT(*) FROM user_requests WHERE barangay_id=$bid AND status='rejected'")->fetchColumn();
// Archives (scoped by barangay via data_json)
$totals['total_archives']             = (int)$db->query("SELECT COUNT(*) FROM archives WHERE data_json LIKE '%\"barangay_id\":\"$bid\"%' OR data_json LIKE '%\"barangay_id\":$bid%' OR data_json LIKE '%\"barangay_id\": $bid%'")->fetchColumn();
// Activity Logs (this barangay's users)
$totals['total_activity_logs']        = (int)$db->query("SELECT COUNT(*) FROM activity_logs al JOIN users u ON al.user_id=u.id WHERE u.barangay_id=$bid")->fetchColumn();
// Reports submitted
$totals['total_reports_submitted']    = (int)$db->query("SELECT COUNT(*) FROM reports WHERE barangay_id=$bid AND status='submitted'")->fetchColumn();
// Transfers (all-time)
$totals['transferred_out']            = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND resident_status='Transferred'")->fetchColumn();
$totals['transferred_in']             = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND resident_status='Active' AND transfer_from_barangay IS NOT NULL AND transfer_from_barangay != ''")->fetchColumn();
$totals['transferred_outside']        = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND resident_status='Transferred' AND transfer_outside=1")->fetchColumn();
$totals['net_movement']               = $totals['transferred_in'] - $totals['transferred_out'];

// Transfers (period-scoped) — only computed when a date range is provided
if ($dateFrom && $dateTo) {
    $totals['transferred_out_period']  = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND resident_status='Transferred' AND DATE(created_at) BETWEEN '$dateFrom' AND '$dateTo'")->fetchColumn();
    $totals['transferred_in_period']   = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND resident_status='Active' AND transfer_from_barangay IS NOT NULL AND transfer_from_barangay != '' AND DATE(created_at) BETWEEN '$dateFrom' AND '$dateTo'")->fetchColumn();
} else {
    $totals['transferred_out_period']  = $totals['transferred_out'];
    $totals['transferred_in_period']   = $totals['transferred_in'];
}

// Transfer detail rows for the transfers category
if (in_array($category, ['transfers', 'summary'])) {
    $dateClause = ($dateFrom && $dateTo) ? "AND DATE(r.created_at) BETWEEN '$dateFrom' AND '$dateTo'" : '';
    $s = $db->query("SELECT r.first_name, r.middle_name, r.last_name, r.suffix, r.gender, r.age,
        r.transfer_to_barangay, r.transfer_from_barangay, r.transfer_outside, r.transfer_reason,
        r.created_at, a.purok, a.barangay as addr_brgy
        FROM residents r LEFT JOIN addresses a ON r.address_id=a.id
        WHERE r.barangay_id=$bid AND r.resident_status='Transferred' $dateClause
        ORDER BY r.created_at DESC");
    $transferOutRows = $s->fetchAll();

    $s = $db->query("SELECT r.first_name, r.middle_name, r.last_name, r.suffix, r.gender, r.age,
        r.transfer_to_barangay, r.transfer_from_barangay, r.transfer_outside, r.transfer_reason,
        r.created_at, a.purok, a.barangay as addr_brgy
        FROM residents r LEFT JOIN addresses a ON r.address_id=a.id
        WHERE r.barangay_id=$bid AND r.resident_status='Active'
          AND r.transfer_from_barangay IS NOT NULL AND r.transfer_from_barangay != '' $dateClause
        ORDER BY r.created_at DESC");
    $transferInRows = $s->fetchAll();
}

// Summary stats (always fetched for summary)
$stats = [];
if ($category === 'summary') {
    $stats['total_residents']  = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND resident_status='Active'")->fetchColumn();
    $dc = ($dateFrom && $dateTo) ? "AND DATE(created_at) BETWEEN '$dateFrom' AND '$dateTo'" : '';
    $stats['new_residents']    = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND resident_status='Active' $dc")->fetchColumn();
    $stats['total_households'] = (int)$db->query("SELECT COUNT(*) FROM households WHERE barangay_id=$bid")->fetchColumn();
    $stats['total_docs']       = (int)$db->query("SELECT COUNT(*) FROM certificates WHERE barangay_id=$bid $dc")->fetchColumn();
    $stats['total_blotter']    = (int)$db->query("SELECT COUNT(*) FROM blotter_cases WHERE barangay_id=$bid $dc")->fetchColumn();
    $stats['total_officials']  = (int)$db->query("SELECT COUNT(*) FROM officials WHERE barangay_id=$bid AND status='active'")->fetchColumn();
    $stats['pwd']              = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND is_pwd=1 AND resident_status='Active'")->fetchColumn();
    $stats['senior']           = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND is_senior=1 AND resident_status='Active'")->fetchColumn();
    $stats['solo']             = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND is_solo_parent=1 AND resident_status='Active'")->fetchColumn();
    $stats['4ps']              = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND is_4ps=1 AND resident_status='Active'")->fetchColumn();
    $stats['voters']           = (int)$db->query("SELECT COUNT(*) FROM residents WHERE barangay_id=$bid AND voter_status='Registered' AND resident_status='Active'")->fetchColumn();
    $stats['transferred_in']   = $totals['transferred_in_period'];
    $stats['transferred_out']  = $totals['transferred_out_period'];
}

// Residents
if (in_array($category, ['residents','summary'])) {
    $dc = ($dateFrom && $dateTo) ? "AND DATE(r.created_at) BETWEEN '$dateFrom' AND '$dateTo'" : '';
    $s = $db->query("SELECT r.first_name, r.middle_name, r.last_name, r.suffix, r.gender, r.age, r.birthdate, r.civil_status, r.contact_number, r.voter_status, r.occupation_status, r.resident_status, r.is_pwd, r.is_senior, r.is_4ps, r.is_solo_parent, a.purok, a.street, a.barangay as addr_brgy, a.city, a.province, r.created_at FROM residents r LEFT JOIN addresses a ON r.address_id=a.id WHERE r.barangay_id=$bid AND r.resident_status='Active' $dc ORDER BY r.last_name, r.first_name");
    $residentRows = $s->fetchAll();
}

// Households
if (in_array($category, ['households','summary'])) {
    $s = $db->prepare("SELECT h.household_code, h.total_members, h.total_senior, h.total_pwd, h.income_range, h.length_of_stay, h.contact_number, CONCAT(r.last_name,', ',r.first_name) as head_name, a.purok, a.street, a.barangay as addr_brgy, a.city, h.created_at FROM households h LEFT JOIN addresses a ON h.address_id=a.id LEFT JOIN residents r ON h.head_resident_id=r.id WHERE h.barangay_id=? ORDER BY h.created_at DESC");
    $s->execute([$bid]);
    $householdRows = $s->fetchAll();
}

// Officials
if (in_array($category, ['officials','summary'])) {
    $s = $db->prepare("SELECT full_name, position, contact, email, term_start, term_end, status FROM officials WHERE barangay_id=? AND status='active' ORDER BY position, full_name");
    $s->execute([$bid]);
    $officialRows = $s->fetchAll();
}

// Documents / Certificates — filter by date_issued (the explicit issue date)
if (in_array($category, ['documents','summary'])) {
    $dc = ($dateFrom && $dateTo) ? "AND c.date_issued BETWEEN '$dateFrom' AND '$dateTo'" : '';
    $s = $db->query("SELECT c.certificate_type, c.purpose, c.date_issued, CONCAT(r.last_name,', ',r.first_name) as resident_name, CONCAT(u.first_name,' ',u.last_name) as issued_by, c.created_at FROM certificates c LEFT JOIN residents r ON c.resident_id=r.id LEFT JOIN users u ON c.issued_by=u.id WHERE c.barangay_id=$bid $dc ORDER BY c.date_issued DESC");
    $docRows = $s->fetchAll();
}

// Blotter
if (in_array($category, ['blotter','summary'])) {
    $dc = ($dateFrom && $dateTo) ? "AND DATE(bc.created_at) BETWEEN '$dateFrom' AND '$dateTo'" : '';
    $s = $db->query("SELECT bc.case_number, bc.incident_date, bc.incident_time, bc.location, bc.narrative, bc.status, bc.action_taken, CONCAT(c.last_name,', ',c.first_name) as complainant_name, CONCAT(r.last_name,', ',r.first_name) as respondent_name, bc.created_at FROM blotter_cases bc LEFT JOIN residents c ON bc.complainant_id=c.id LEFT JOIN residents r ON bc.respondent_id=r.id WHERE bc.barangay_id=$bid $dc ORDER BY bc.created_at DESC");
    $blotterRows = $s->fetchAll();
}

// Audit / Activity
if (in_array($category, ['audit','activity','summary'])) {
    $dc = ($dateFrom && $dateTo) ? "AND DATE(al.created_at) BETWEEN '$dateFrom' AND '$dateTo'" : '';
    $s = $db->query("SELECT al.id, al.action, al.module, al.description, al.ip_address, al.created_at FROM activity_logs al WHERE al.user_id={$u['id']} $dc ORDER BY al.created_at DESC");
    $auditRows = $s->fetchAll();
}

// ── Helper: table row ──────────────────────────────────────
function tr(array $cells): string {
    return '<tr>' . implode('', array_map(fn($c) => '<td>' . htmlspecialchars((string)$c) . '</td>', $cells)) . '</tr>';
}
function th(array $cells): string {
    return '<tr>' . implode('', array_map(fn($c) => '<th>' . htmlspecialchars((string)$c) . '</th>', $cells)) . '</tr>';
}

// ── EXCEL ──────────────────────────────────────────────────
if ($format === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
    header('Cache-Control: max-age=0');
    ob_start();
    echo '<html><head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial; font-size: 11px; }
        h2 { color: #1a4f7a; }
        h3 { color: #1a4f7a; margin-top: 20px; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 20px; }
        th { background: #1a4f7a; color: #fff; padding: 5px 8px; border: 1px solid #ccc; }
        td { padding: 4px 8px; border: 1px solid #ddd; }
        tr:nth-child(even) td { background: #f5f8fc; }
        .stat-table td { font-size: 12px; }
        .stat-val { font-weight: bold; font-size: 14px; color: #1a4f7a; }
        .section-header { background:#e8f0fe; color:#1a4f7a; font-weight:bold; font-size:12px; }
        .grand-total-row td { background:#1a4f7a !important; color:#fff; font-weight:bold; }
        .subtotal-row td { background:#e8f0fe; font-weight:bold; }
    </style>
    </head><body>';

    echo '<h2>Barangay Report — ' . ucfirst($category) . '</h2>';
    echo '<p><b>Barangay:</b> ' . htmlspecialchars($brgyName) . '</p>';
    echo '<p><b>Period Filter:</b> ' . ($dateFrom && $dateTo ? date('M d, Y', strtotime($dateFrom)) . ' to ' . date('M d, Y', strtotime($dateTo)) : 'All Time') . '</p>';
    echo '<p><b>Generated:</b> ' . date('M d, Y h:i A') . ' &nbsp;|&nbsp; <b>By:</b> ' . htmlspecialchars($u['name']) . '</p>';

    // ── TOTAL TRANSACTIONS SUMMARY (all-time) ──────────────
    echo '<h3>&#x1F4CA; Total Transactions Summary (All-Time)</h3>';
    echo '<table>';
    echo '<tr><th colspan="2" style="text-align:center;font-size:12px;">BARANGAY ' . strtoupper(htmlspecialchars($brgyName)) . ' — COMPLETE TRANSACTION TOTALS</th></tr>';

    // Residents section
    echo '<tr><td colspan="2" class="section-header">&#x1F465; RESIDENTS</td></tr>';
    echo '<tr><td>Total Residents (All-Time)</td><td class="stat-val">' . number_format($totals['total_residents_alltime']) . '</td></tr>';
    echo '<tr><td>&#x2714; Active Residents</td><td class="stat-val">' . number_format($totals['active_residents']) . '</td></tr>';
    echo '<tr><td>&#x1F4E6; Archived Residents</td><td class="stat-val">' . number_format($totals['archived_residents']) . '</td></tr>';

    // Demographics
    echo '<tr><td colspan="2" class="section-header">&#x1F4CB; DEMOGRAPHICS (Active Residents)</td></tr>';
    echo '<tr><td>&nbsp;&nbsp;Male</td><td class="stat-val">' . number_format($totals['male']) . '</td></tr>';
    echo '<tr><td>&nbsp;&nbsp;Female</td><td class="stat-val">' . number_format($totals['female']) . '</td></tr>';
    echo '<tr><td>&nbsp;&nbsp;Registered Voters</td><td class="stat-val">' . number_format($totals['registered_voters']) . '</td></tr>';
    echo '<tr><td>&nbsp;&nbsp;PWD (Persons with Disability)</td><td class="stat-val">' . number_format($totals['pwd']) . '</td></tr>';
    echo '<tr><td>&nbsp;&nbsp;Senior Citizens</td><td class="stat-val">' . number_format($totals['senior_citizens']) . '</td></tr>';
    echo '<tr><td>&nbsp;&nbsp;4Ps Beneficiaries</td><td class="stat-val">' . number_format($totals['4ps']) . '</td></tr>';
    echo '<tr><td>&nbsp;&nbsp;Solo Parents</td><td class="stat-val">' . number_format($totals['solo_parents']) . '</td></tr>';

    // Employment
    echo '<tr><td colspan="2" class="section-header">&#x1F4BC; EMPLOYMENT STATUS (Active Residents)</td></tr>';
    echo '<tr><td>&nbsp;&nbsp;Employed</td><td class="stat-val">' . number_format($totals['employed']) . '</td></tr>';
    echo '<tr><td>&nbsp;&nbsp;Unemployed</td><td class="stat-val">' . number_format($totals['unemployed']) . '</td></tr>';
    echo '<tr><td>&nbsp;&nbsp;Self-Employed</td><td class="stat-val">' . number_format($totals['self_employed']) . '</td></tr>';
    echo '<tr><td>&nbsp;&nbsp;Student</td><td class="stat-val">' . number_format($totals['student']) . '</td></tr>';
    echo '<tr><td>&nbsp;&nbsp;Retired</td><td class="stat-val">' . number_format($totals['retired']) . '</td></tr>';

    // Households
    echo '<tr><td colspan="2" class="section-header">&#x1F3E0; HOUSEHOLDS</td></tr>';
    echo '<tr><td>Total Households Registered</td><td class="stat-val">' . number_format($totals['total_households']) . '</td></tr>';
    echo '<tr><td>Total Household Members</td><td class="stat-val">' . number_format($totals['total_household_members']) . '</td></tr>';

    // Certificates
    echo '<tr><td colspan="2" class="section-header">&#x1F4C4; CERTIFICATES / CLEARANCES ISSUED</td></tr>';
    echo '<tr><td>Total Certificates Issued (All-Time)</td><td class="stat-val">' . number_format($totals['total_certificates']) . '</td></tr>';
    foreach ($certBreakdown as $cb) {
        echo '<tr><td>&nbsp;&nbsp;' . htmlspecialchars($cb['certificate_type']) . '</td><td class="stat-val">' . number_format($cb['cnt']) . '</td></tr>';
    }

    // Blotter
    echo '<tr><td colspan="2" class="section-header">&#x1F6E1; BLOTTER CASES</td></tr>';
    echo '<tr><td>Total Blotter Cases (All-Time)</td><td class="stat-val">' . number_format($totals['total_blotter']) . '</td></tr>';
    echo '<tr><td>&nbsp;&nbsp;Open</td><td class="stat-val">' . number_format($totals['blotter_open']) . '</td></tr>';
    echo '<tr><td>&nbsp;&nbsp;Settled</td><td class="stat-val">' . number_format($totals['blotter_settled']) . '</td></tr>';
    echo '<tr><td>&nbsp;&nbsp;Escalated</td><td class="stat-val">' . number_format($totals['blotter_escalated']) . '</td></tr>';
    echo '<tr><td>&nbsp;&nbsp;Closed</td><td class="stat-val">' . number_format($totals['blotter_closed']) . '</td></tr>';

    // Officials, Requests, Archives, Logs
    echo '<tr><td colspan="2" class="section-header">&#x1F3C5; OFFICIALS &amp; OTHER RECORDS</td></tr>';
    echo '<tr><td>Active Barangay Officials</td><td class="stat-val">' . number_format($totals['active_officials']) . '</td></tr>';
    echo '<tr><td colspan="2" class="section-header">&#x1F4E8; RESIDENT REQUESTS</td></tr>';
    echo '<tr><td>Total Requests (All-Time)</td><td class="stat-val">' . number_format($totals['total_requests']) . '</td></tr>';
    echo '<tr><td>&nbsp;&nbsp;Pending</td><td class="stat-val">' . number_format($totals['pending_requests']) . '</td></tr>';
    echo '<tr><td>&nbsp;&nbsp;Approved</td><td class="stat-val">' . number_format($totals['approved_requests']) . '</td></tr>';
    echo '<tr><td>&nbsp;&nbsp;Rejected</td><td class="stat-val">' . number_format($totals['rejected_requests']) . '</td></tr>';
    echo '<tr><td colspan="2" class="section-header">&#x1F5C2; SYSTEM &amp; ACTIVITY</td></tr>';
    echo '<tr><td>Total Archived Records</td><td class="stat-val">' . number_format($totals['total_archives']) . '</td></tr>';
    echo '<tr><td>Total Activity Log Entries</td><td class="stat-val">' . number_format($totals['total_activity_logs']) . '</td></tr>';
    echo '<tr><td>Reports Submitted to LGU</td><td class="stat-val">' . number_format($totals['total_reports_submitted']) . '</td></tr>';

    // Transfers section
    echo '<tr><td colspan="2" class="section-header">&#x1F504; POPULATION TRANSFERS (MOVEMENT)</td></tr>';
    echo '<tr><td>&#x1F7E2; Transferred INTO This Barangay (All-Time)</td><td class="stat-val">' . number_format($totals['transferred_in']) . '</td></tr>';
    echo '<tr><td>&#x1F534; Transferred OUT of This Barangay (All-Time)</td><td class="stat-val">' . number_format($totals['transferred_out']) . '</td></tr>';
    echo '<tr><td>&nbsp;&nbsp;— Transferred Outside LGU / Municipality</td><td class="stat-val">' . number_format($totals['transferred_outside']) . '</td></tr>';
    echo '<tr><td>Net Population Movement (In − Out)</td><td class="stat-val" style="color:' . ($totals['net_movement'] >= 0 ? '#16a34a' : '#dc2626') . ';">' . ($totals['net_movement'] >= 0 ? '+' : '') . number_format($totals['net_movement']) . '</td></tr>';
    if ($dateFrom && $dateTo) {
        echo '<tr><td colspan="2" class="section-header" style="background:#d1fae5;">&#x1F4C5; TRANSFERS FOR SELECTED PERIOD (' . htmlspecialchars($periodLabel) . ')</td></tr>';
        echo '<tr><td>&nbsp;&nbsp;Transferred IN (period)</td><td class="stat-val">' . number_format($totals['transferred_in_period']) . '</td></tr>';
        echo '<tr><td>&nbsp;&nbsp;Transferred OUT (period)</td><td class="stat-val">' . number_format($totals['transferred_out_period']) . '</td></tr>';
    }

    // Grand total row
    $grandTotal = $totals['total_residents_alltime'] + $totals['total_households'] + $totals['total_certificates'] + $totals['total_blotter'] + $totals['total_requests'] + $totals['active_officials'];
    echo '<tr class="grand-total-row"><td><b>GRAND TOTAL (Residents + Households + Certs + Blotter + Requests + Officials)</b></td><td><b>' . number_format($grandTotal) . '</b></td></tr>';
    echo '</table>';

    // Summary stats
    if ($category === 'summary') {
        echo '<h3>Summary Statistics</h3>';
        echo '<table class="stat-table">';
        echo th(['Metric', 'Count']);
        foreach ([
            'Total Active Residents' => $stats['total_residents'],
            'New Residents (Period)'  => $stats['new_residents'],
            'Total Households'        => $stats['total_households'],
            'Documents Issued'        => $stats['total_docs'],
            'Blotter Cases'           => $stats['total_blotter'],
            'Active Officials'        => $stats['total_officials'],
            'PWD'                     => $stats['pwd'],
            'Senior Citizens'         => $stats['senior'],
            'Solo Parents'            => $stats['solo'],
            '4Ps Beneficiaries'       => $stats['4ps'],
            'Registered Voters'       => $stats['voters'],
            'Transferred IN (period)' => $stats['transferred_in'],
            'Transferred OUT (period)'=> $stats['transferred_out'],
        ] as $label => $val) {
            echo '<tr><td>' . $label . '</td><td class="stat-val">' . number_format($val) . '</td></tr>';
        }
        echo '</table>';
    }

    // Transfers IN/OUT detail tables
    if (isset($transferInRows) || isset($transferOutRows)) {
        echo '<h3>&#x1F504; Transfers — Residents Who Moved IN (' . count($transferInRows ?? []) . ')</h3>';
        echo '<table>' . th(['#','Full Name','Gender','Age','From Barangay','Reason','Address','Date']);
        foreach (($transferInRows ?? []) as $i => $r) {
            $name = trim($r['last_name'].', '.$r['first_name'].($r['middle_name']?' '.$r['middle_name'][0].'.':'').($r['suffix']?' '.$r['suffix']:''));
            $addr = trim(($r['purok']?'Purok '.$r['purok'].', ':'').($r['addr_brgy']??''));
            echo tr([$i+1, $name, $r['gender'], $r['age'], $r['transfer_from_barangay']?:'—', $r['transfer_reason']?:'—', $addr, date('M d, Y', strtotime($r['created_at']))]);
        }
        echo '</table>';

        echo '<h3>&#x1F504; Transfers — Residents Who Moved OUT (' . count($transferOutRows ?? []) . ')</h3>';
        echo '<table>' . th(['#','Full Name','Gender','Age','To Barangay','Outside LGU','Reason','Date']);
        foreach (($transferOutRows ?? []) as $i => $r) {
            $name = trim($r['last_name'].', '.$r['first_name'].($r['middle_name']?' '.$r['middle_name'][0].'.':'').($r['suffix']?' '.$r['suffix']:''));
            echo tr([$i+1, $name, $r['gender'], $r['age'], $r['transfer_to_barangay']?:'—', $r['transfer_outside']?'Yes':'No', $r['transfer_reason']?:'—', date('M d, Y', strtotime($r['created_at']))]);
        }
        echo '</table>';
    }

    // Residents
    if (isset($residentRows)) {
        echo '<h3>Residents (' . count($residentRows) . ')</h3>';
        echo '<table>' . th(['#','Last Name','First Name','Middle Name','Suffix','Gender','Age','Birthdate','Civil Status','Contact','Voter Status','Occupation','PWD','Senior','4Ps','Solo Parent','Purok','Street','Barangay','City','Province','Date Registered']);
        foreach ($residentRows as $i => $r) {
            echo tr([$i+1, $r['last_name'], $r['first_name'], $r['middle_name']??'', $r['suffix']??'', $r['gender'], $r['age'], $r['birthdate'], $r['civil_status'], $r['contact_number']??'', $r['voter_status'], $r['occupation_status'], $r['is_pwd']?'Yes':'No', $r['is_senior']?'Yes':'No', $r['is_4ps']?'Yes':'No', $r['is_solo_parent']?'Yes':'No', $r['purok']??'', $r['street']??'', $r['addr_brgy']??'', $r['city']??'', $r['province']??'', date('M d, Y', strtotime($r['created_at']))]);
        }
        echo '</table>';
    }

    // Households
    if (isset($householdRows)) {
        echo '<h3>Households (' . count($householdRows) . ')</h3>';
        echo '<table>' . th(['#','Code','Head of Family','Contact','Members','Senior','PWD','Income Range','Length of Stay','Purok','Street','Barangay','City','Date Added']);
        foreach ($householdRows as $i => $h) {
            echo tr([$i+1, $h['household_code'], $h['head_name']??'N/A', $h['contact_number']??'', $h['total_members'], $h['total_senior'], $h['total_pwd'], $h['income_range']??'', $h['length_of_stay']??'', $h['purok']??'', $h['street']??'', $h['addr_brgy']??'', $h['city']??'', date('M d, Y', strtotime($h['created_at']))]);
        }
        echo '</table>';
    }

    // Officials
    if (isset($officialRows)) {
        echo '<h3>Officials (' . count($officialRows) . ')</h3>';
        echo '<table>' . th(['#','Full Name','Position','Contact','Email','Term Start','Term End','Status']);
        foreach ($officialRows as $i => $o) {
            echo tr([$i+1, $o['full_name'], $o['position'], $o['contact']??'', $o['email']??'', $o['term_start']?date('M d, Y',strtotime($o['term_start'])):'', $o['term_end']?date('M d, Y',strtotime($o['term_end'])):'', ucfirst($o['status'])]);
        }
        echo '</table>';
    }

    // Documents
    if (isset($docRows)) {
        echo '<h3>Certificates / Clearance (' . count($docRows) . ')</h3>';
        echo '<table>' . th(['#','Resident','Certificate Type','Purpose','Date Issued','Issued By']);
        foreach ($docRows as $i => $d) {
            echo tr([$i+1, $d['resident_name'], $d['certificate_type'], $d['purpose'], date('M d, Y', strtotime($d['date_issued'])), $d['issued_by']??'']);
        }
        echo '</table>';
    }

    // Blotter
    if (isset($blotterRows)) {
        echo '<h3>Blotter / Complaints (' . count($blotterRows) . ')</h3>';
        echo '<table>' . th(['#','Case No.','Complainant','Respondent','Incident Date','Time','Location','Status','Action Taken','Narrative','Date Filed']);
        foreach ($blotterRows as $i => $b) {
            echo tr([$i+1, $b['case_number'], $b['complainant_name']??'N/A', $b['respondent_name']??'N/A', date('M d, Y',strtotime($b['incident_date'])), $b['incident_time']??'', $b['location'], $b['status'], $b['action_taken']??'', $b['narrative'], date('M d, Y',strtotime($b['created_at']))]);
        }
        echo '</table>';
    }

    // Audit / Activity
    if (isset($auditRows)) {
        $label = $category === 'audit' ? 'Audit Report' : 'Activity Report';
        echo '<h3>' . $label . ' (' . count($auditRows) . ')</h3>';
        echo '<table>' . th(['Log ID','Action','Module / Page','Description','IP Address','Date','Time']);
        foreach ($auditRows as $a) {
            echo tr(['#'.$a['id'], $a['action'], $a['module']??'', $a['description']??'', $a['ip_address']??'', date('M d, Y',strtotime($a['created_at'])), date('h:i A',strtotime($a['created_at']))]);
        }
        echo '</table>';
    }

    echo '</body></html>';
    ob_end_flush();
    exit;
}

// ── PDF (auto-print HTML → Save as PDF) ───────────────────
header('Content-Type: text/html; charset=UTF-8');
$catLabel = ucfirst(str_replace(['_','-'], ' ', $category));
$genDate  = date('F d, Y');
$genTime  = date('h:i A');
$L1 = BASE_URL . '/assets/img/logo 1.jpg';
$L2 = BASE_URL . '/assets/img/logo 2.jpg';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Barangay Report — <?= htmlspecialchars($catLabel) ?></title>
<style>
/* ── Reset ── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Arial',sans-serif;font-size:10.5px;color:#1a1a1a;background:#fff;padding:18mm 18mm 14mm;}
/* ── Screen toolbar ── */
.screen-only{display:flex;gap:10px;align-items:center;justify-content:center;
    padding:10px 0 18px;position:sticky;top:0;background:#f1f5f9;z-index:99;
    border-bottom:2px solid #c3d6e8;margin:-18mm -18mm 20px;}
.btn-print{padding:9px 26px;background:#1a4f7a;color:#fff;border:none;border-radius:7px;
    font-size:13px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:7px;transition:background .15s;}
.btn-print:hover{background:#2d6fa3;}
.btn-close-tab{padding:9px 18px;background:#fff;color:#64748b;border:1.5px solid #cbd5e1;border-radius:7px;
    font-size:13px;font-weight:600;cursor:pointer;transition:background .15s;}
.btn-close-tab:hover{background:#f1f5f9;}
/* ── Letterhead ── */
.letterhead{border-bottom:3px double #1a4f7a;padding-bottom:10px;margin-bottom:12px;
    display:flex;align-items:center;justify-content:space-between;gap:12px;}
.letterhead img{width:68px;height:68px;object-fit:contain;}
.lh-center{text-align:center;flex:1;line-height:1.55;}
.lh-republic{font-size:10px;font-style:italic;}
.lh-province{font-size:10.5px;}
.lh-brgy{font-size:15px;font-weight:900;color:#1a4f7a;letter-spacing:.03em;}
.lh-office{font-size:11px;font-style:italic;color:#2d6fa3;margin-top:1px;}
/* ── Report title bar ── */
.rpt-titlebar{background:#1a4f7a;color:#fff;padding:7px 14px;border-radius:5px;
    display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;}
.rpt-titlebar h1{font-size:13px;font-weight:700;letter-spacing:.04em;}
.rpt-titlebar .meta{font-size:9.5px;opacity:.85;text-align:right;line-height:1.6;}
/* ── Period badge ── */
.period-badge{display:inline-block;background:#e8f0fe;color:#1a4f7a;border:1px solid #c3d6e8;
    border-radius:20px;padding:3px 12px;font-size:10px;font-weight:700;margin-bottom:12px;}
/* ── Stat grid ── */
.stat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:14px;}
.stat-box{border:1.5px solid #dbeafe;border-radius:7px;padding:8px 6px;text-align:center;background:#f8faff;}
.stat-box .sv{font-size:17px;font-weight:800;color:#1a4f7a;line-height:1.1;}
.stat-box .sl{font-size:9px;color:#64748b;margin-top:2px;line-height:1.3;}
/* ── Section headers ── */
h2{font-size:11.5px;font-weight:700;color:#1a4f7a;border-bottom:1.5px solid #c3d6e8;
    padding:4px 0 3px;margin:14px 0 7px;display:flex;align-items:center;gap:6px;}
h2 .badge-cnt{background:#1a4f7a;color:#fff;border-radius:10px;padding:1px 8px;font-size:9px;font-weight:700;}
/* ── Tables ── */
table{width:100%;border-collapse:collapse;margin-bottom:14px;page-break-inside:auto;font-size:9.5px;}
thead{display:table-header-group;}
th{background:#1a4f7a;color:#fff;padding:5px 7px;text-align:left;border:1px solid #1a4f7a;
    font-size:9px;font-weight:700;letter-spacing:.02em;}
td{padding:4px 7px;border:1px solid #dde3ec;vertical-align:top;}
tr:nth-child(even) td{background:#f5f8fc;}
/* transfer color rows */
.row-in td{background:#f0fdf4 !important;}
.row-out td{background:#fff5f5 !important;}
/* summary totals table */
.totals-table td:first-child{font-weight:600;width:72%;}
.totals-table td:last-child{font-weight:700;color:#1a4f7a;text-align:right;}
.totals-table .sec-row td{background:#e8f0fe;color:#1a4f7a;font-weight:700;font-size:9.5px;}
.totals-table .grand-row td{background:#1a4f7a;color:#fff;font-weight:700;}
/* ── Transfer summary mini row ── */
.transfer-summary{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:10px;}
.ts-box{border:1.5px solid #dde3ec;border-radius:6px;padding:7px 8px;text-align:center;}
.ts-val{font-size:15px;font-weight:800;}
.ts-lbl{font-size:8.5px;color:#64748b;margin-top:1px;}
/* ── Footer ── */
.rpt-footer{margin-top:18px;border-top:1px solid #c3d6e8;padding-top:7px;
    display:flex;align-items:center;justify-content:space-between;font-size:8.5px;color:#94a3b8;}
/* ── Print overrides ── */
@media print{
    @page{size:A4 portrait;margin:12mm 14mm 12mm;}
    .screen-only{display:none!important;}
    body{padding:0;font-size:10px;}
    .letterhead{padding-bottom:8px;margin-bottom:10px;}
    h2{page-break-after:avoid;}
    table{page-break-inside:auto;}
    tr{page-break-inside:avoid;}
    .stat-box,.ts-box{border:1px solid #c3d6e8!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    th,td{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    .totals-table .sec-row td,.totals-table .grand-row td{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
}
</style>
</head>
<body>

<!-- Screen toolbar -->
<div class="screen-only">
    <button class="btn-print" onclick="window.print()">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
            <path d="M2.5 8a.5.5 0 1 0 0-1 .5.5 0 0 0 0 1z"/>
            <path d="M5 1a2 2 0 0 0-2 2v2H2a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h1v1a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2v-1h1a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-1V3a2 2 0 0 0-2-2H5zM4 3a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2H4V3zm1 5a2 2 0 0 0-2 2v1H2a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1h-1v-1a2 2 0 0 0-2-2H5zm7 2v3a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-3a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1z"/>
        </svg>
        Print / Save as PDF
    </button>
    <button class="btn-close-tab" onclick="window.close()">✕ Close</button>
    <span style="font-size:11px;color:#64748b;">Use your browser's <strong>Save as PDF</strong> option in the print dialog.</span>
</div>

<!-- Letterhead -->
<div class="letterhead">
    <img src="<?= $L1 ?>" alt="" onerror="this.style.visibility='hidden'">
    <div class="lh-center">
        <div class="lh-republic">Republic of the Philippines</div>
        <div class="lh-province">Province of Nueva Ecija &nbsp;·&nbsp; Municipality of Talavera</div>
        <div class="lh-brgy">BARANGAY <?= strtoupper(htmlspecialchars($brgyName)) ?></div>
        <div class="lh-office">Office of the Punong Barangay</div>
    </div>
    <img src="<?= $L2 ?>" alt="" onerror="this.style.visibility='hidden'">
</div>

<!-- Title bar -->
<div class="rpt-titlebar">
    <h1>📊 Barangay Data Report — <?= htmlspecialchars($catLabel) ?></h1>
    <div class="meta">
        Generated: <?= $genDate ?>, <?= $genTime ?><br>
        Prepared by: <?= htmlspecialchars($u['name'] ?? ($u['first_name'].' '.$u['last_name'])) ?>
    </div>
</div>
<div class="period-badge">📅 Period: <?= htmlspecialchars($periodLabel) ?></div>

<?php /* ═══════════════ SUMMARY STATS ═══════════════ */ ?>
<?php if ($category === 'summary'): ?>
<h2>Overview Statistics</h2>
<div class="stat-grid">
<?php foreach ([
    ['Total Active Residents', $stats['total_residents'],  '#1a4f7a'],
    ['New Residents (Period)', $stats['new_residents'],     '#0369a1'],
    ['Total Households',       $stats['total_households'], '#065f46'],
    ['Documents Issued',       $stats['total_docs'],       '#4c1d95'],
    ['Blotter Cases',          $stats['total_blotter'],    '#991b1b'],
    ['Active Officials',       $stats['total_officials'],  '#92400e'],
    ['Registered Voters',      $stats['voters'],           '#1a4f7a'],
    ['PWD',                    $stats['pwd'],              '#b45309'],
    ['Senior Citizens',        $stats['senior'],           '#065f46'],
    ['Solo Parents',           $stats['solo'],             '#0369a1'],
    ['4Ps Beneficiaries',      $stats['4ps'],              '#6d28d9'],
    ['Transferred IN',         $stats['transferred_in'],   '#16a34a'],
    ['Transferred OUT',        $stats['transferred_out'],  '#dc2626'],
] as [$lbl,$val,$clr]): ?>
<div class="stat-box">
    <div class="sv" style="color:<?= $clr ?>"><?= number_format($val) ?></div>
    <div class="sl"><?= $lbl ?></div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php /* ═══════════════ ALL-TIME TOTALS ═══════════════ */ ?>
<h2>📈 Complete Transaction Totals <span style="font-size:9px;font-weight:400;color:#64748b;">(All-Time)</span></h2>
<table class="totals-table">
<tbody>
<tr class="sec-row"><td colspan="2">👥 RESIDENTS</td></tr>
<tr><td>Total Residents Registered (All-Time)</td><td><?= number_format($totals['total_residents_alltime']) ?></td></tr>
<tr><td>&nbsp;&nbsp;✔ Active</td><td><?= number_format($totals['active_residents']) ?></td></tr>
<tr><td>&nbsp;&nbsp;📦 Archived</td><td><?= number_format($totals['archived_residents']) ?></td></tr>

<tr class="sec-row"><td colspan="2">📋 DEMOGRAPHICS (Active Residents)</td></tr>
<tr><td>&nbsp;&nbsp;Male</td><td><?= number_format($totals['male']) ?></td></tr>
<tr><td>&nbsp;&nbsp;Female</td><td><?= number_format($totals['female']) ?></td></tr>
<tr><td>&nbsp;&nbsp;Registered Voters</td><td><?= number_format($totals['registered_voters']) ?></td></tr>
<tr><td>&nbsp;&nbsp;PWD</td><td><?= number_format($totals['pwd']) ?></td></tr>
<tr><td>&nbsp;&nbsp;Senior Citizens</td><td><?= number_format($totals['senior_citizens']) ?></td></tr>
<tr><td>&nbsp;&nbsp;4Ps Beneficiaries</td><td><?= number_format($totals['4ps']) ?></td></tr>
<tr><td>&nbsp;&nbsp;Solo Parents</td><td><?= number_format($totals['solo_parents']) ?></td></tr>

<tr class="sec-row"><td colspan="2">💼 EMPLOYMENT (Active Residents)</td></tr>
<tr><td>&nbsp;&nbsp;Employed</td><td><?= number_format($totals['employed']) ?></td></tr>
<tr><td>&nbsp;&nbsp;Unemployed</td><td><?= number_format($totals['unemployed']) ?></td></tr>
<tr><td>&nbsp;&nbsp;Self-Employed</td><td><?= number_format($totals['self_employed']) ?></td></tr>
<tr><td>&nbsp;&nbsp;Student</td><td><?= number_format($totals['student']) ?></td></tr>
<tr><td>&nbsp;&nbsp;Retired</td><td><?= number_format($totals['retired']) ?></td></tr>

<tr class="sec-row"><td colspan="2">🏠 HOUSEHOLDS</td></tr>
<tr><td>Total Households Registered</td><td><?= number_format($totals['total_households']) ?></td></tr>
<tr><td>Total Household Members</td><td><?= number_format($totals['total_household_members']) ?></td></tr>

<tr class="sec-row"><td colspan="2">📄 CERTIFICATES / CLEARANCES ISSUED</td></tr>
<tr><td>Total Certificates Issued (All-Time)</td><td><?= number_format($totals['total_certificates']) ?></td></tr>
<?php foreach ($certBreakdown as $cb): ?>
<tr><td>&nbsp;&nbsp;<?= htmlspecialchars($cb['certificate_type']) ?></td><td><?= number_format($cb['cnt']) ?></td></tr>
<?php endforeach; ?>

<tr class="sec-row"><td colspan="2">🛡️ BLOTTER CASES</td></tr>
<tr><td>Total Blotter Cases (All-Time)</td><td><?= number_format($totals['total_blotter']) ?></td></tr>
<tr><td>&nbsp;&nbsp;Open</td><td><?= number_format($totals['blotter_open']) ?></td></tr>
<tr><td>&nbsp;&nbsp;Settled</td><td><?= number_format($totals['blotter_settled']) ?></td></tr>
<tr><td>&nbsp;&nbsp;Escalated</td><td><?= number_format($totals['blotter_escalated']) ?></td></tr>
<tr><td>&nbsp;&nbsp;Closed</td><td><?= number_format($totals['blotter_closed']) ?></td></tr>

<tr class="sec-row"><td colspan="2">🏅 OFFICIALS &amp; REQUESTS</td></tr>
<tr><td>Active Barangay Officials</td><td><?= number_format($totals['active_officials']) ?></td></tr>
<tr><td>Total Requests (All-Time)</td><td><?= number_format($totals['total_requests']) ?></td></tr>
<tr><td>&nbsp;&nbsp;Pending</td><td><?= number_format($totals['pending_requests']) ?></td></tr>
<tr><td>&nbsp;&nbsp;Approved</td><td><?= number_format($totals['approved_requests']) ?></td></tr>
<tr><td>&nbsp;&nbsp;Rejected</td><td><?= number_format($totals['rejected_requests']) ?></td></tr>

<tr class="sec-row"><td colspan="2">🔄 POPULATION TRANSFERS</td></tr>
<tr class="row-in"><td>&nbsp;&nbsp;🟢 Transferred INTO This Barangay</td><td><?= number_format($totals['transferred_in']) ?></td></tr>
<tr class="row-out"><td>&nbsp;&nbsp;🔴 Transferred OUT of This Barangay</td><td><?= number_format($totals['transferred_out']) ?></td></tr>
<tr><td>&nbsp;&nbsp;&nbsp;&nbsp;— Outside LGU / Municipality</td><td><?= number_format($totals['transferred_outside']) ?></td></tr>
<tr><td>Net Population Movement (In − Out)</td>
    <td style="color:<?= $totals['net_movement'] >= 0 ? '#16a34a' : '#dc2626' ?>;font-weight:800;">
        <?= ($totals['net_movement'] >= 0 ? '+' : '') . number_format($totals['net_movement']) ?>
    </td>
</tr>
<?php if ($dateFrom && $dateTo): ?>
<tr class="sec-row"><td colspan="2">📅 TRANSFERS FOR SELECTED PERIOD (<?= htmlspecialchars($periodLabel) ?>)</td></tr>
<tr class="row-in"><td>&nbsp;&nbsp;Transferred IN (period)</td><td><?= number_format($totals['transferred_in_period']) ?></td></tr>
<tr class="row-out"><td>&nbsp;&nbsp;Transferred OUT (period)</td><td><?= number_format($totals['transferred_out_period']) ?></td></tr>
<?php endif; ?>

<tr class="grand-row">
    <td>GRAND TOTAL (Residents + Households + Certs + Blotter + Requests + Officials)</td>
    <td><?= number_format($totals['total_residents_alltime'] + $totals['total_households'] + $totals['total_certificates'] + $totals['total_blotter'] + $totals['total_requests'] + $totals['active_officials']) ?></td>
</tr>
</tbody>
</table>

<?php /* ═══════════════ RESIDENTS ═══════════════ */ ?>
<?php if (isset($residentRows)): ?>
<h2>👥 Residents <span class="badge-cnt"><?= count($residentRows) ?></span></h2>
<table>
    <thead><tr>
        <th>#</th><th>Name</th><th>Gender</th><th>Age</th><th>Civil Status</th>
        <th>Contact</th><th>Voter</th><th>Occupation</th><th>Tags</th><th>Address</th><th>Date Registered</th>
    </tr></thead>
    <tbody>
    <?php foreach ($residentRows as $i => $r):
        $tags = implode(', ', array_filter([$r['is_pwd']?'PWD':'', $r['is_senior']?'Senior':'', $r['is_4ps']?'4Ps':'', $r['is_solo_parent']?'Solo':'']));
        $addr = implode(', ', array_filter([$r['purok']?'Purok '.$r['purok']:'', $r['street']??'', $r['addr_brgy']??'', $r['city']??'']));
    ?>
    <tr>
        <td><?= $i+1 ?></td>
        <td><?= htmlspecialchars($r['last_name'].', '.$r['first_name'].($r['middle_name']?' '.$r['middle_name'][0].'.':'').($r['suffix']?' '.$r['suffix']:'')) ?></td>
        <td><?= $r['gender'] ?></td>
        <td><?= $r['age'] ?></td>
        <td><?= $r['civil_status'] ?></td>
        <td><?= htmlspecialchars($r['contact_number']??'') ?></td>
        <td><?= $r['voter_status'] ?></td>
        <td><?= $r['occupation_status'] ?></td>
        <td><?= $tags ?: '—' ?></td>
        <td><?= htmlspecialchars($addr) ?></td>
        <td><?= date('M d, Y', strtotime($r['created_at'])) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<?php /* ═══════════════ HOUSEHOLDS ═══════════════ */ ?>
<?php if (isset($householdRows)): ?>
<h2>🏠 Households <span class="badge-cnt"><?= count($householdRows) ?></span></h2>
<table>
    <thead><tr>
        <th>#</th><th>Code</th><th>Head of Family</th><th>Contact</th>
        <th>Members</th><th>Senior</th><th>PWD</th><th>Income Range</th><th>Address</th><th>Date Added</th>
    </tr></thead>
    <tbody>
    <?php foreach ($householdRows as $i => $h):
        $addr = implode(', ', array_filter([$h['purok']?'Purok '.$h['purok']:'', $h['street']??'', $h['addr_brgy']??'', $h['city']??'']));
    ?>
    <tr>
        <td><?= $i+1 ?></td>
        <td><?= htmlspecialchars($h['household_code']) ?></td>
        <td><?= htmlspecialchars($h['head_name']??'N/A') ?></td>
        <td><?= htmlspecialchars($h['contact_number']??'') ?></td>
        <td><?= $h['total_members'] ?></td>
        <td><?= $h['total_senior'] ?></td>
        <td><?= $h['total_pwd'] ?></td>
        <td><?= htmlspecialchars($h['income_range']??'') ?></td>
        <td><?= htmlspecialchars($addr) ?></td>
        <td><?= date('M d, Y', strtotime($h['created_at'])) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<?php /* ═══════════════ OFFICIALS ═══════════════ */ ?>
<?php if (isset($officialRows)): ?>
<h2>🏅 Officials <span class="badge-cnt"><?= count($officialRows) ?></span></h2>
<table>
    <thead><tr>
        <th>#</th><th>Full Name</th><th>Position</th><th>Contact</th><th>Email</th>
        <th>Term Start</th><th>Term End</th><th>Status</th>
    </tr></thead>
    <tbody>
    <?php foreach ($officialRows as $i => $o): ?>
    <tr>
        <td><?= $i+1 ?></td>
        <td><?= htmlspecialchars($o['full_name']) ?></td>
        <td><?= htmlspecialchars($o['position']) ?></td>
        <td><?= htmlspecialchars($o['contact']??'') ?></td>
        <td><?= htmlspecialchars($o['email']??'') ?></td>
        <td><?= $o['term_start'] ? date('M d, Y', strtotime($o['term_start'])) : '—' ?></td>
        <td><?= $o['term_end']   ? date('M d, Y', strtotime($o['term_end']))   : '—' ?></td>
        <td><?= ucfirst($o['status']) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<?php /* ═══════════════ CERTIFICATES ═══════════════ */ ?>
<?php if (isset($docRows)): ?>
<h2>📄 Certificates / Clearances <span class="badge-cnt"><?= count($docRows) ?></span></h2>
<table>
    <thead><tr>
        <th>#</th><th>Resident</th><th>Certificate Type</th><th>Purpose</th>
        <th>Date Issued</th><th>Issued By</th>
    </tr></thead>
    <tbody>
    <?php foreach ($docRows as $i => $d): ?>
    <tr>
        <td><?= $i+1 ?></td>
        <td><?= htmlspecialchars($d['resident_name']) ?></td>
        <td><?= htmlspecialchars($d['certificate_type']) ?></td>
        <td><?= htmlspecialchars($d['purpose']) ?></td>
        <td><?= $d['date_issued'] ? date('M d, Y', strtotime($d['date_issued'])) : '—' ?></td>
        <td><?= htmlspecialchars($d['issued_by']??'') ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<?php /* ═══════════════ BLOTTER ═══════════════ */ ?>
<?php if (isset($blotterRows)): ?>
<h2>🛡️ Blotter / Complaints <span class="badge-cnt"><?= count($blotterRows) ?></span></h2>
<table>
    <thead><tr>
        <th>#</th><th>Case No.</th><th>Complainant</th><th>Respondent</th>
        <th>Incident Date</th><th>Location</th><th>Status</th><th>Action Taken</th><th>Date Filed</th>
    </tr></thead>
    <tbody>
    <?php foreach ($blotterRows as $i => $b): ?>
    <tr>
        <td><?= $i+1 ?></td>
        <td><?= htmlspecialchars($b['case_number']) ?></td>
        <td><?= htmlspecialchars($b['complainant_name']??'N/A') ?></td>
        <td><?= htmlspecialchars($b['respondent_name']??'N/A') ?></td>
        <td><?= date('M d, Y', strtotime($b['incident_date'])) ?></td>
        <td><?= htmlspecialchars($b['location']) ?></td>
        <td><?= htmlspecialchars($b['status']) ?></td>
        <td><?= htmlspecialchars($b['action_taken']??'') ?></td>
        <td><?= date('M d, Y', strtotime($b['created_at'])) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<?php /* ═══════════════ AUDIT / ACTIVITY ═══════════════ */ ?>
<?php if (isset($auditRows)): ?>
<h2><?= $category === 'audit' ? '🔍 Audit Report' : '🕐 Activity Report' ?> <span class="badge-cnt"><?= count($auditRows) ?></span></h2>
<table>
    <thead><tr>
        <th>Log ID</th><th>Action</th><th>Module</th><th>Description</th>
        <th>IP Address</th><th>Date</th><th>Time</th>
    </tr></thead>
    <tbody>
    <?php foreach ($auditRows as $a): ?>
    <tr>
        <td>#<?= $a['id'] ?></td>
        <td><?= htmlspecialchars($a['action']) ?></td>
        <td><?= htmlspecialchars($a['module']??'') ?></td>
        <td><?= htmlspecialchars($a['description']??'') ?></td>
        <td><?= htmlspecialchars($a['ip_address']??'') ?></td>
        <td><?= date('M d, Y', strtotime($a['created_at'])) ?></td>
        <td><?= date('h:i A',  strtotime($a['created_at'])) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<?php /* ═══════════════ TRANSFERS ═══════════════ */ ?>
<?php if (isset($transferInRows) || isset($transferOutRows)): ?>
<h2>🔄 Population Transfers</h2>
<div class="transfer-summary">
    <div class="ts-box" style="border-color:#bbf7d0;">
        <div class="ts-val" style="color:#16a34a;"><?= number_format($totals['transferred_in']) ?></div>
        <div class="ts-lbl">Transferred IN (all-time)</div>
    </div>
    <div class="ts-box" style="border-color:#fecaca;">
        <div class="ts-val" style="color:#dc2626;"><?= number_format($totals['transferred_out']) ?></div>
        <div class="ts-lbl">Transferred OUT (all-time)</div>
    </div>
    <div class="ts-box" style="border-color:#fde68a;">
        <div class="ts-val" style="color:#d97706;"><?= number_format($totals['transferred_outside']) ?></div>
        <div class="ts-lbl">Outside LGU</div>
    </div>
    <div class="ts-box">
        <div class="ts-val" style="color:<?= $totals['net_movement'] >= 0 ? '#16a34a' : '#dc2626' ?>;">
            <?= ($totals['net_movement'] >= 0 ? '+' : '') . number_format($totals['net_movement']) ?>
        </div>
        <div class="ts-lbl">Net Movement (In − Out)</div>
    </div>
</div>

<h2 style="color:#16a34a;">↘ Transferred INTO This Barangay <span class="badge-cnt" style="background:#16a34a;"><?= count($transferInRows ?? []) ?></span></h2>
<table>
    <thead><tr><th>#</th><th>Full Name</th><th>Gender</th><th>Age</th><th>From Barangay</th><th>Reason</th><th>Current Address</th><th>Date</th></tr></thead>
    <tbody>
    <?php if (empty($transferInRows)): ?>
        <tr><td colspan="8" style="text-align:center;color:#999;padding:12px;">No incoming transfers in this period.</td></tr>
    <?php else: foreach ($transferInRows as $i => $r):
        $name = trim($r['last_name'].', '.$r['first_name'].($r['middle_name']?' '.$r['middle_name'][0].'.':'').($r['suffix']?' '.$r['suffix']:''));
        $addr = trim(($r['purok']?'Purok '.$r['purok'].', ':'').($r['addr_brgy']??''));
    ?>
    <tr class="row-in">
        <td><?= $i+1 ?></td>
        <td><?= htmlspecialchars($name) ?></td>
        <td><?= $r['gender'] ?></td>
        <td><?= $r['age'] ?></td>
        <td><?= htmlspecialchars($r['transfer_from_barangay'] ?: '—') ?></td>
        <td><?= htmlspecialchars($r['transfer_reason'] ?: '—') ?></td>
        <td><?= htmlspecialchars($addr) ?></td>
        <td><?= date('M d, Y', strtotime($r['created_at'])) ?></td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>

<h2 style="color:#dc2626;">↗ Transferred OUT of This Barangay <span class="badge-cnt" style="background:#dc2626;"><?= count($transferOutRows ?? []) ?></span></h2>
<table>
    <thead><tr><th>#</th><th>Full Name</th><th>Gender</th><th>Age</th><th>Transferred To</th><th>Outside LGU</th><th>Reason</th><th>Date</th></tr></thead>
    <tbody>
    <?php if (empty($transferOutRows)): ?>
        <tr><td colspan="8" style="text-align:center;color:#999;padding:12px;">No outgoing transfers in this period.</td></tr>
    <?php else: foreach ($transferOutRows as $i => $r):
        $name = trim($r['last_name'].', '.$r['first_name'].($r['middle_name']?' '.$r['middle_name'][0].'.':'').($r['suffix']?' '.$r['suffix']:''));
    ?>
    <tr class="row-out">
        <td><?= $i+1 ?></td>
        <td><?= htmlspecialchars($name) ?></td>
        <td><?= $r['gender'] ?></td>
        <td><?= $r['age'] ?></td>
        <td><?= htmlspecialchars($r['transfer_to_barangay'] ?: '—') ?></td>
        <td><?= $r['transfer_outside'] ? 'Yes' : 'No' ?></td>
        <td><?= htmlspecialchars($r['transfer_reason'] ?: '—') ?></td>
        <td><?= date('M d, Y', strtotime($r['created_at'])) ?></td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
<?php endif; ?>

<!-- Footer -->
<div class="rpt-footer">
    <span>Barangay <?= htmlspecialchars($brgyName) ?> &nbsp;·&nbsp; Talavera, Nueva Ecija</span>
    <span>Generated: <?= $genDate ?>, <?= $genTime ?> by <?= htmlspecialchars($u['name'] ?? ($u['first_name'].' '.$u['last_name'])) ?></span>
    <span>This is a system-generated report.</span>
</div>

<script>
// Auto-open print dialog when page loads
window.addEventListener('load', function() {
    // Small delay so images can load before print dialog opens
    setTimeout(function() { window.print(); }, 800);
});
</script>
</body>
</html>
<?php exit; ?>
