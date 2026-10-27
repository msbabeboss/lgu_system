<?php
/**
 * AJAX endpoint: returns dashboard stats JSON for a given barangay filter.
 * Called by dashboard.php when the barangay dropdown changes — avoids a full
 * page reload so the welcome banner never blinks.
 */
require_once __DIR__ . '/../config/config.php';
requireRole('super_admin');

header('Content-Type: application/json');

$db           = Database::getInstance()->getConnection();
$selectedBrgy = isset($_GET['barangay_id']) ? (int)$_GET['barangay_id'] : 0;

$where  = $selectedBrgy ? "WHERE r.barangay_id=$selectedBrgy" : "WHERE 1=1";
$whereB = $selectedBrgy ? "WHERE barangay_id=$selectedBrgy"   : "WHERE 1=1";

// ── Overview counts ───────────────────────────────────────
$stats = [];
$stats['total_households'] = (int)$db->query("SELECT COUNT(*) FROM households $whereB")->fetchColumn();
$stats['total_blotter']    = (int)$db->query("SELECT COUNT(*) FROM blotter_cases $whereB")->fetchColumn();
$stats['total_certs']      = (int)$db->query("SELECT COUNT(*) FROM certificates" . ($selectedBrgy ? " WHERE barangay_id=$selectedBrgy" : ""))->fetchColumn();

// ── Resident counts ───────────────────────────────────────
$resSQL = "SELECT
    SUM(CASE WHEN r.resident_status='Active'       THEN 1 ELSE 0 END) AS active,
    SUM(CASE WHEN r.resident_status='Inactive'     THEN 1 ELSE 0 END) AS inactive,
    SUM(CASE WHEN r.resident_status='Transferred'  THEN 1 ELSE 0 END) AS transferred,
    SUM(CASE WHEN r.is_pwd=1         AND r.resident_status='Active' THEN 1 ELSE 0 END) AS pwd,
    SUM(CASE WHEN r.is_senior=1      AND r.resident_status='Active' THEN 1 ELSE 0 END) AS senior,
    SUM(CASE WHEN r.is_solo_parent=1 AND r.resident_status='Active' THEN 1 ELSE 0 END) AS solo,
    SUM(CASE WHEN r.is_4ps=1         AND r.resident_status='Active' THEN 1 ELSE 0 END) AS fours
FROM residents r $where";
$rc = $db->query($resSQL)->fetch();

$stats['total_residents']  = (int)$rc['active'];
$stats['res_active']       = $stats['total_residents'];
$stats['res_inactive']     = (int)$rc['inactive'];
$stats['res_transferred']  = (int)$rc['transferred'];
$stats['total_pwd']        = (int)$rc['pwd'];
$stats['total_senior']     = (int)$rc['senior'];
$stats['total_solo']       = (int)$rc['solo'];
$stats['total_4ps']        = (int)$rc['fours'];

// ── Deceased ──────────────────────────────────────────────
if ($selectedBrgy) {
    $decStmt = $db->prepare("SELECT COUNT(*) FROM (
        SELECT r.id FROM residents r WHERE r.resident_status='Deceased' AND r.barangay_id=?
        UNION
        SELECT a.record_id FROM archives a
        INNER JOIN residents r ON r.id=a.record_id AND r.barangay_id=?
        WHERE a.table_name='residents' AND r.resident_status='Inactive' AND a.data_json LIKE '%Deceased%'
    ) AS d");
    $decStmt->execute([$selectedBrgy, $selectedBrgy]);
} else {
    $decStmt = $db->prepare("SELECT COUNT(*) FROM (
        SELECT r.id FROM residents r WHERE r.resident_status='Deceased'
        UNION
        SELECT a.record_id FROM archives a
        INNER JOIN residents r ON r.id=a.record_id
        WHERE a.table_name='residents' AND r.resident_status='Inactive' AND a.data_json LIKE '%Deceased%'
    ) AS d");
    $decStmt->execute();
}
$stats['res_deceased'] = (int)$decStmt->fetchColumn();

// ── Transferred breakdown ─────────────────────────────────
$transferredPerBrgy = $db->query("
    SELECT b.barangay_name,
        COUNT(*) as total_transferred,
        SUM(CASE WHEN r.transfer_outside=1 THEN 1 ELSE 0 END) as outside_transfer,
        SUM(CASE WHEN r.transfer_outside=0 OR r.transfer_outside IS NULL THEN 1 ELSE 0 END) as local_transfer
    FROM residents r
    INNER JOIN barangays b ON b.id = r.barangay_id
    WHERE r.resident_status = 'Transferred'
    " . ($selectedBrgy ? " AND r.barangay_id=$selectedBrgy" : "") . "
    GROUP BY r.barangay_id, b.barangay_name
    ORDER BY total_transferred DESC
")->fetchAll();

$stats['total_transferred']     = $stats['res_transferred'];
$stats['outside_transfer_total'] = array_sum(array_column($transferredPerBrgy, 'outside_transfer'));
$stats['local_transfer_total']   = array_sum(array_column($transferredPerBrgy, 'local_transfer'));
$stats['barangays_affected']     = count($transferredPerBrgy);

// ── Blotter status counts ─────────────────────────────────
$blotterStatusCounts = ['Open'=>0,'Under Investigation'=>0,'Settled'=>0,'Escalated'=>0,'Closed'=>0];
$bsQ = $db->query("SELECT status, COUNT(*) as cnt FROM blotter_cases WHERE status != 'Archived'" . ($selectedBrgy ? " AND barangay_id=$selectedBrgy" : '') . " GROUP BY status");
foreach ($bsQ->fetchAll() as $r) {
    if (isset($blotterStatusCounts[$r['status']])) $blotterStatusCounts[$r['status']] = (int)$r['cnt'];
}
$stats['blotter_status'] = $blotterStatusCounts;

// ── Demographics ──────────────────────────────────────────
$genderQ = $db->query("SELECT gender, COUNT(*) as cnt FROM residents r $where AND r.resident_status='Active' GROUP BY gender");
$genderData = ['Male'=>0,'Female'=>0];
foreach ($genderQ->fetchAll() as $r) $genderData[$r['gender']] = (int)$r['cnt'];
$stats['gender'] = $genderData;

// ── Employment ────────────────────────────────────────────
$empSQL = "SELECT occupation_status, COUNT(*) AS cnt FROM residents r $where
           AND r.occupation_status IN ('Employed','Unemployed','Self-Employed','Student','Retired')
           AND r.resident_status='Active' GROUP BY occupation_status";
$empMap = array_column($db->query($empSQL)->fetchAll(), 'cnt', 'occupation_status');
$empStatuses = ['Employed','Unemployed','Self-Employed','Student','Retired'];
$stats['employment'] = array_map(fn($s) => (int)($empMap[$s] ?? 0), array_combine($empStatuses, $empStatuses));

// ── Population Trend (12 months) ─────────────────────────
$trendStart = date('Y-m-01', strtotime('-11 months'));
$popSQL = "SELECT DATE_FORMAT(r.created_at,'%Y-%m') AS ym, COUNT(*) AS cnt
           FROM residents r WHERE r.created_at >= '$trendStart'"
         . ($selectedBrgy ? " AND r.barangay_id=$selectedBrgy" : "")
         . " GROUP BY ym";
$popMap = array_column($db->query($popSQL)->fetchAll(), 'cnt', 'ym');
$popLabels = []; $popValues = [];
for ($i = 11; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime("-$i months"));
    $popLabels[] = date('M Y', strtotime("-$i months"));
    $popValues[] = (int)($popMap[$ym] ?? 0);
}
$stats['pop_trend'] = ['labels' => $popLabels, 'values' => $popValues];

// ── Blotter Trend (6 months) ──────────────────────────────
$blotterStart = date('Y-m-01', strtotime('-5 months'));
$blotterSQL = "SELECT DATE_FORMAT(created_at,'%Y-%m') AS ym, COUNT(*) AS cnt FROM blotter_cases WHERE created_at >= '$blotterStart'"
             . ($selectedBrgy ? " AND barangay_id=$selectedBrgy" : "")
             . " GROUP BY ym";
$blotterMap = array_column($db->query($blotterSQL)->fetchAll(), 'cnt', 'ym');
$blotterLabels = []; $blotterValues = [];
for ($i = 5; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime("-$i months"));
    $blotterLabels[] = date('M Y', strtotime("-$i months"));
    $blotterValues[] = (int)($blotterMap[$ym] ?? 0);
}
$stats['blotter_trend'] = ['labels' => $blotterLabels, 'values' => $blotterValues];

// ── Top Barangays ─────────────────────────────────────────
$brgySubFilter = $selectedBrgy ? " AND barangay_id=$selectedBrgy" : "";
$brgyStatsSQL = "SELECT b.barangay_name,
    COALESCE(r_cnt.residents,0) AS residents,
    COALESCE(h_cnt.households,0) AS households,
    COALESCE(bl_cnt.blotter,0) AS blotter
FROM barangays b
LEFT JOIN (SELECT barangay_id, COUNT(*) AS residents FROM residents WHERE resident_status='Active'$brgySubFilter GROUP BY barangay_id) r_cnt ON r_cnt.barangay_id=b.id
LEFT JOIN (SELECT barangay_id, COUNT(*) AS households FROM households WHERE 1=1$brgySubFilter GROUP BY barangay_id) h_cnt ON h_cnt.barangay_id=b.id
LEFT JOIN (SELECT barangay_id, COUNT(*) AS blotter FROM blotter_cases WHERE 1=1$brgySubFilter GROUP BY barangay_id) bl_cnt ON bl_cnt.barangay_id=b.id
WHERE b.status='active'"
. ($selectedBrgy ? " AND b.id=$selectedBrgy" : "")
. " ORDER BY residents DESC LIMIT 5";
$stats['top_barangays'] = $db->query($brgyStatsSQL)->fetchAll(PDO::FETCH_ASSOC);

// ── Current month quick stats ─────────────────────────────
$curFrom = date('Y-m-01');
$curTo   = date('Y-m-d');
$stats['month_residents'] = (int)$db->query("SELECT COUNT(*) FROM residents r $where AND DATE(r.created_at) BETWEEN '$curFrom' AND '$curTo'")->fetchColumn();
$stats['month_certs']     = (int)$db->query("SELECT COUNT(*) FROM certificates" . ($selectedBrgy ? " WHERE barangay_id=$selectedBrgy AND" : " WHERE") . " date_issued BETWEEN '$curFrom' AND '$curTo'")->fetchColumn();
$stats['month_blotter']   = (int)$db->query("SELECT COUNT(*) FROM blotter_cases" . ($selectedBrgy ? " WHERE barangay_id=$selectedBrgy AND" : " WHERE") . " status != 'Archived' AND DATE(created_at) BETWEEN '$curFrom' AND '$curTo'")->fetchColumn();

echo json_encode(['success' => true, 'stats' => $stats]);
