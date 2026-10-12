<?php
require_once __DIR__ . '/../config/config.php';
requireRole('super_admin');

$db = Database::getInstance()->getConnection();
$u  = currentUser();

// Safe migration
try { $db->exec("ALTER TABLE residents ADD COLUMN IF NOT EXISTS email VARCHAR(150) NULL"); } catch (PDOException $e) {}

// Require barangay selection — redirect to hub if missing
$brgFilter = (int)($_GET['barangay_id'] ?? 0);
if (!$brgFilter) {
    redirect(BASE_URL . '/super_admin/barangay_records.php');
}
$brgyRow = $db->prepare("SELECT barangay_name, municipality FROM barangays WHERE id=? AND status='active'");
$brgyRow->execute([$brgFilter]);
$brgyRow = $brgyRow->fetch();
if (!$brgyRow) {
    redirect(BASE_URL . '/super_admin/barangay_records.php');
}
$brgyLabel = htmlspecialchars($brgyRow['barangay_name'] . ($brgyRow['municipality'] ? ' — '.$brgyRow['municipality'] : ''));

// Filters
$search    = trim(strip_tags($_GET['search']    ?? ''));
$genderF   = trim(strip_tags($_GET['gender']   ?? ''));
$statusF   = trim(strip_tags($_GET['status']   ?? ''));
$dateFrom  = trim(strip_tags($_GET['date_from']?? ''));
$dateTo    = trim(strip_tags($_GET['date_to']  ?? ''));
$sort      = trim(strip_tags($_GET['sort']     ?? 'name'));
$perPage   = 30;
$page      = max(1, (int)($_GET['page'] ?? 1));

$allBarangays = []; // no longer needed — barangay is locked

// Build WHERE
$where = "WHERE 1=1";
if ($brgFilter) $where .= " AND r.barangay_id = " . (int)$brgFilter;
if ($genderF)   $where .= " AND r.gender = " . $db->quote($genderF);
if ($statusF)   $where .= " AND r.resident_status = " . $db->quote($statusF);
if ($dateFrom)  $where .= " AND r.created_at >= " . $db->quote($dateFrom . ' 00:00:00');
if ($dateTo)    $where .= " AND r.created_at <= " . $db->quote($dateTo   . ' 23:59:59');
if ($search)    $where .= " AND (r.first_name LIKE " . $db->quote("%$search%") .
                          " OR r.last_name LIKE "    . $db->quote("%$search%") .
                          " OR r.contact_number LIKE " . $db->quote("%$search%") .
                          " OR b.barangay_name LIKE " . $db->quote("%$search%") . ")";

$orderBy = match($sort) {
    'barangay'   => "b.barangay_name ASC, r.last_name ASC",
    'date_added' => "r.created_at DESC",
    default      => "r.last_name ASC, r.first_name ASC",
};

$totalCount = (int)$db->query("SELECT COUNT(*) FROM residents r LEFT JOIN barangays b ON b.id=r.barangay_id $where")->fetchColumn();
$totalPages = max(1, (int)ceil($totalCount / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$residents = $db->query("
    SELECT r.id, r.first_name, r.middle_name, r.last_name, r.birthdate, r.gender,
           r.civil_status, r.contact_number, r.resident_status, r.is_pwd, r.is_senior,
           r.is_solo_parent, r.is_4ps, r.voter_status, r.occupation_status, r.created_at,
           b.barangay_name,
           CONCAT_WS(', ', NULLIF(a.purok,''), NULLIF(a.street,''), NULLIF(a.barangay,''),
                           NULLIF(a.city,'')) AS full_address
    FROM residents r
    LEFT JOIN barangays b ON b.id = r.barangay_id
    LEFT JOIN addresses a ON a.id = r.address_id
    $where
    ORDER BY $orderBy
    LIMIT $perPage OFFSET $offset
")->fetchAll();

$pageTitle    = 'Residents';
$pageIcon     = 'bi-people-fill';
$pageSubtitle = 'Residents of ' . htmlspecialchars($brgyRow['barangay_name']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favcon.png">
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=2.1">
    <style>
        @media print { .sidebar, .topbar, .no-print { display:none; } .main-content { margin:0; } }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/sidebar_super.php'; ?>
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<div class="main-content">

    <!-- Page Header -->
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <div>
            <nav aria-label="breadcrumb" class="mb-1">
                <ol class="breadcrumb mb-0" style="font-size:0.78rem;">
                    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/super_admin/barangay_records.php">Barangay Records</a></li>
                    <li class="breadcrumb-item active">Residents</li>
                </ol>
            </nav>
            <h4 class="fw-bold mb-0" style="color:#1a4f7a;">
                <i class="bi bi-people-fill me-2"></i><?= $pageTitle ?>
            </h4>
            <p class="text-muted small mb-0"><?= $pageSubtitle ?></p>
        </div>
        <div class="d-flex gap-2 no-print">
            <a href="<?= BASE_URL ?>/super_admin/export_br_residents.php?mode=all&<?= http_build_query(array_filter(['search'=>$search,'barangay_id'=>$brgFilter?:null,'gender'=>$genderF,'status'=>$statusF,'date_from'=>$dateFrom,'date_to'=>$dateTo])) ?>"
               class="btn btn-success btn-sm fw-semibold">
                <i class="bi bi-file-earmark-excel me-1"></i>Download All
            </a>
        </div>
    </div>

    <!-- Filters -->
    <div style="background:#fff;border-radius:14px;box-shadow:0 2px 12px rgba(0,0,0,0.06);border:1px solid #e0f0e4;padding:14px 18px;margin-bottom:14px;" class="no-print">
        <!-- Locked barangay label -->
        <div class="d-flex align-items-center gap-2 mb-2">
            <a href="<?= BASE_URL ?>/super_admin/barangay_records.php?barangay_id=<?= $brgFilter ?>" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Back
            </a>
            <span style="background:#e0f2fe;color:#0369a1;padding:5px 14px;border-radius:20px;font-size:0.82rem;font-weight:700;">
                <i class="bi bi-geo-alt-fill me-1"></i><?= $brgyLabel ?>
            </span>
        </div>
        <form method="GET" class="d-flex gap-2 flex-wrap align-items-center">
            <input type="hidden" name="barangay_id" value="<?= $brgFilter ?>">
            <select name="gender" class="form-select form-select-sm" style="width:120px;" onchange="this.form.submit()">
                <option value="">All Genders</option>
                <option value="Male"   <?= $genderF==='Male'  ?'selected':'' ?>>Male</option>
                <option value="Female" <?= $genderF==='Female'?'selected':'' ?>>Female</option>
            </select>
            <select name="status" class="form-select form-select-sm" style="width:155px;" onchange="this.form.submit()">
                <option value="">All Statuses</option>
                <?php foreach (['Active','Inactive','Deceased','Transferred','Pending Transfer'] as $st): ?>
                    <option value="<?= $st ?>" <?= $statusF===$st?'selected':'' ?>><?= $st ?></option>
                <?php endforeach; ?>
            </select>
            <select name="sort" class="form-select form-select-sm" style="width:150px;" onchange="this.form.submit()">
                <option value="name"       <?= $sort==='name'      ?'selected':'' ?>>Sort: Name</option>
                <option value="barangay"   <?= $sort==='barangay'  ?'selected':'' ?>>Sort: Barangay</option>
                <option value="date_added" <?= $sort==='date_added'?'selected':'' ?>>Sort: Date Added</option>
            </select>
            <input type="date" name="date_from" class="form-control form-control-sm" style="width:140px;" value="<?= htmlspecialchars($dateFrom) ?>" title="From date">
            <input type="date" name="date_to"   class="form-control form-control-sm" style="width:140px;" value="<?= htmlspecialchars($dateTo)   ?>" title="To date">
            <input type="text" name="search" class="form-control form-control-sm" style="width:220px;"
                   placeholder="Search name, contact, barangay..." value="<?= htmlspecialchars($search) ?>">
            <button class="btn btn-primary btn-sm px-3"><i class="bi bi-search me-1"></i>Search</button>
            <a href="?" class="btn btn-outline-secondary btn-sm px-3"><i class="bi bi-x me-1"></i>Clear</a>
            <span class="ms-auto text-muted small"><?= number_format($totalCount) ?> resident<?= $totalCount!==1?'s':'' ?></span>
        </form>
    </div>

    <!-- Table -->
    <div style="background:#fff;border-radius:18px;box-shadow:0 2px 16px rgba(0,0,0,0.07);border:1px solid #e0f0e4;overflow:hidden;">
        <div class="table-responsive">
            <table class="table mb-0 table-sm">
                <thead>
                    <tr style="background:#f4f7fa;border-bottom:2px solid #d0ecd6;">
                        <?php foreach (['#','Name','Brgy','Gender','Birthdate','Status','Contact','Sectors','Voter','Added'] as $th): ?>
                        <th class="py-2 px-2" style="font-size:0.63rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#3a7fa8;border:none;white-space:nowrap;"><?= $th ?></th>
                        <?php endforeach; ?>
                        <th class="py-2 px-2 no-print" style="font-size:0.63rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#3a7fa8;border:none;">View</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($residents)): ?>
                    <tr><td colspan="11" class="text-center text-muted py-5">
                        <i class="bi bi-inbox" style="font-size:2.5rem;opacity:0.3;display:block;margin-bottom:8px;"></i>
                        No residents found.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($residents as $i => $r):
                        $rowNum = $offset + $i + 1;
                        $statusColor = match($r['resident_status']) {
                            'Active'           => ['bg'=>'#dcfce7','text'=>'#166534'],
                            'Deceased'         => ['bg'=>'#f1f5f9','text'=>'#475569'],
                            'Transferred'      => ['bg'=>'#fef3c7','text'=>'#92400e'],
                            'Pending Transfer' => ['bg'=>'#dbeafe','text'=>'#1e40af'],
                            default            => ['bg'=>'#f1f5f9','text'=>'#64748b'],
                        };
                        $age = $r['birthdate'] ? (int)(new DateTime($r['birthdate']))->diff(new DateTime())->y : null;
                        $mi  = $r['middle_name'] ? ' ' . strtoupper(substr($r['middle_name'],0,1)) . '.' : '';
                    ?>
                    <tr style="border-bottom:1px solid #f0f6fb;" onmouseover="this.style.background='#f8fbff'" onmouseout="this.style.background=''">
                        <td class="px-2 py-1 text-muted" style="border:none;white-space:nowrap;font-size:0.75rem;"><?= $rowNum ?></td>
                        <td class="px-2 py-1" style="border:none;white-space:nowrap;">
                            <div class="fw-semibold" style="font-size:0.8rem;"><?= htmlspecialchars($r['last_name'].', '.$r['first_name'].$mi) ?></div>
                            <?php if ($age!==null): ?><div class="text-muted" style="font-size:0.65rem;"><?= $age ?> yrs</div><?php endif; ?>
                        </td>
                        <td class="px-2 py-1" style="border:none;white-space:nowrap;">
                            <span style="background:#e0f2fe;color:#0369a1;padding:1px 7px;border-radius:20px;font-size:0.68rem;font-weight:600;white-space:nowrap;">
                                <?= htmlspecialchars($r['barangay_name'] ?? '—') ?>
                            </span>
                        </td>
                        <td class="px-2 py-1" style="border:none;white-space:nowrap;font-size:0.78rem;">
                            <i class="bi <?= $r['gender']==='Male' ? 'bi-gender-male text-primary' : 'bi-gender-female text-danger' ?>"></i>
                            <?= htmlspecialchars($r['gender'] ?? '—') ?>
                        </td>
                        <td class="px-2 py-1 text-nowrap" style="border:none;font-size:0.78rem;">
                            <?= $r['birthdate'] ? date('M d, Y', strtotime($r['birthdate'])) : '—' ?>
                        </td>
                        <td class="px-2 py-1" style="border:none;white-space:nowrap;">
                            <span style="background:<?= $statusColor['bg'] ?>;color:<?= $statusColor['text'] ?>;padding:1px 7px;border-radius:20px;font-size:0.68rem;font-weight:600;">
                                <?= htmlspecialchars($r['resident_status']) ?>
                            </span>
                        </td>
                        <td class="px-2 py-1" style="border:none;white-space:nowrap;font-size:0.78rem;"><?= htmlspecialchars($r['contact_number'] ?? '—') ?></td>
                        <td class="px-2 py-1" style="border:none;white-space:nowrap;">
                            <div class="d-flex gap-1">
                                <?php if ($r['is_pwd']):         ?><span class="badge" style="background:#fde68a;color:#92400e;font-size:0.58rem;">PWD</span><?php endif; ?>
                                <?php if ($r['is_senior']):      ?><span class="badge" style="background:#e9d5ff;color:#6b21a8;font-size:0.58rem;">Senior</span><?php endif; ?>
                                <?php if ($r['is_solo_parent']): ?><span class="badge" style="background:#fce7f3;color:#9d174d;font-size:0.58rem;">Solo</span><?php endif; ?>
                                <?php if ($r['is_4ps']):         ?><span class="badge" style="background:#d1fae5;color:#065f46;font-size:0.58rem;">4Ps</span><?php endif; ?>
                                <?php if (!$r['is_pwd'] && !$r['is_senior'] && !$r['is_solo_parent'] && !$r['is_4ps']): ?>
                                    <span class="text-muted" style="font-size:0.7rem;">—</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="px-2 py-1" style="border:none;white-space:nowrap;font-size:0.78rem;">
                            <?= $r['voter_status']==='Registered'
                                ? '<span class="text-success" style="font-size:0.75rem;"><i class="bi bi-check-circle-fill me-1"></i>Yes</span>'
                                : '<span class="text-muted" style="font-size:0.75rem;">No</span>' ?>
                        </td>
                        <td class="px-2 py-1 text-nowrap text-muted" style="border:none;font-size:0.75rem;">
                            <?= date('M d, Y', strtotime($r['created_at'])) ?>
                        </td>
                        <td class="px-2 py-1 no-print" style="border:none;">
                            <button class="btn btn-xs btn-outline-info"
                                    onclick='viewResident(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)'
                                    title="View Details">
                                <i class="bi bi-eye"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div><!-- /main-content -->

<?php
$paginationBase  = '?' . http_build_query(array_filter([
    'search'      => $search,
    'barangay_id' => $brgFilter ?: null,
    'gender'      => $genderF,
    'status'      => $statusF,
    'date_from'   => $dateFrom,
    'date_to'     => $dateTo,
    'sort'        => $sort,
])) . '&';
$paginationLabel = 'resident';
include __DIR__ . '/../includes/pagination.php';
?>

<!-- View Details Modal -->
<div class="modal fade" id="viewResidentModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;overflow:hidden;">
            <div class="modal-header" style="background:linear-gradient(135deg,#059669,#10b981);border:none;">
                <h5 class="modal-title text-white fw-bold"><i class="bi bi-person-fill me-2"></i>Resident Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="row g-3">
                    <div class="col-md-6"><small class="text-muted d-block">Full Name</small><strong id="vr_name"></strong></div>
                    <div class="col-md-6"><small class="text-muted d-block">Barangay</small><span id="vr_brgy"></span></div>
                    <div class="col-md-4"><small class="text-muted d-block">Gender</small><span id="vr_gender"></span></div>
                    <div class="col-md-4"><small class="text-muted d-block">Birthdate</small><span id="vr_bdate"></span></div>
                    <div class="col-md-4"><small class="text-muted d-block">Civil Status</small><span id="vr_civil"></span></div>
                    <div class="col-md-4"><small class="text-muted d-block">Status</small><span id="vr_status"></span></div>
                    <div class="col-md-4"><small class="text-muted d-block">Contact</small><span id="vr_contact"></span></div>
                    <div class="col-md-4"><small class="text-muted d-block">Voter Status</small><span id="vr_voter"></span></div>
                    <div class="col-md-12"><small class="text-muted d-block">Address</small><span id="vr_address"></span></div>
                    <div class="col-md-3"><small class="text-muted d-block">PWD</small><span id="vr_pwd"></span></div>
                    <div class="col-md-3"><small class="text-muted d-block">Senior</small><span id="vr_senior"></span></div>
                    <div class="col-md-3"><small class="text-muted d-block">Solo Parent</small><span id="vr_solo"></span></div>
                    <div class="col-md-3"><small class="text-muted d-block">4Ps</small><span id="vr_4ps"></span></div>
                    <div class="col-md-6"><small class="text-muted d-block">Occupation Status</small><span id="vr_occ"></span></div>
                    <div class="col-md-6"><small class="text-muted d-block">Date Added</small><span id="vr_added"></span></div>
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function viewResident(r) {
    var mi = r.middle_name ? ' ' + r.middle_name.charAt(0).toUpperCase() + '.' : '';
    document.getElementById('vr_name').textContent    = (r.last_name||'') + ', ' + (r.first_name||'') + mi;
    document.getElementById('vr_brgy').textContent    = r.barangay_name   || '—';
    document.getElementById('vr_gender').textContent  = r.gender          || '—';
    document.getElementById('vr_bdate').textContent   = r.birthdate       || '—';
    document.getElementById('vr_civil').textContent   = r.civil_status    || '—';
    document.getElementById('vr_status').textContent  = r.resident_status || '—';
    document.getElementById('vr_contact').textContent = r.contact_number  || '—';
    document.getElementById('vr_voter').textContent   = r.voter_status    || '—';
    document.getElementById('vr_address').textContent = r.full_address    || '—';
    document.getElementById('vr_pwd').textContent     = r.is_pwd    == 1 ? 'Yes' : 'No';
    document.getElementById('vr_senior').textContent  = r.is_senior == 1 ? 'Yes' : 'No';
    document.getElementById('vr_solo').textContent    = r.is_solo_parent == 1 ? 'Yes' : 'No';
    document.getElementById('vr_4ps').textContent     = r.is_4ps    == 1 ? 'Yes' : 'No';
    document.getElementById('vr_occ').textContent     = r.occupation_status || '—';
    document.getElementById('vr_added').textContent   = r.created_at      || '—';
    new bootstrap.Modal(document.getElementById('viewResidentModal')).show();
}
</script>
</body>
</html>
