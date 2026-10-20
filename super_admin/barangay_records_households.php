<?php
require_once __DIR__ . '/../config/config.php';
requireRole('super_admin');

$db = Database::getInstance()->getConnection();
$u  = currentUser();

// ── FAST ID CHECK — before migration and any slow query ──────────────────
$brgFilter = (int)($_GET['barangay_id'] ?? 0);
if (!$brgFilter) { redirect(BASE_URL . '/super_admin/barangay_records.php'); }
$_brgyCheck = $db->prepare("SELECT barangay_name, municipality FROM barangays WHERE id=? AND status='active' LIMIT 1");
$_brgyCheck->execute([$brgFilter]);
$brgyRow = $_brgyCheck->fetch();
if (!$brgyRow) { redirect(BASE_URL . '/super_admin/barangay_records.php'); }

// Safe migration — runs only after the ID is confirmed valid
try { $db->exec("ALTER TABLE residents ADD COLUMN IF NOT EXISTS email VARCHAR(150) NULL"); } catch (PDOException $e) {}
// $brgyRow already fetched by the fast check above.
if (!$brgyRow) { redirect(BASE_URL . '/super_admin/barangay_records.php'); }
$brgyLabel = htmlspecialchars($brgyRow['barangay_name'] . ($brgyRow['municipality'] ? ' — '.$brgyRow['municipality'] : ''));

// Filters
$search    = trim(strip_tags($_GET['search']    ?? ''));
$statusF   = trim(strip_tags($_GET['status']   ?? ''));
$sort      = trim(strip_tags($_GET['sort']     ?? 'code'));
$dateFrom  = trim(strip_tags($_GET['date_from']?? ''));
$dateTo    = trim(strip_tags($_GET['date_to']  ?? ''));
$perPage   = 20;
$page      = max(1, (int)($_GET['page'] ?? 1));

$allBarangays = []; // locked — barangay pre-selected from hub

// Build WHERE
$where = "WHERE 1=1";
if ($brgFilter) $where .= " AND h.barangay_id = " . (int)$brgFilter;
if ($statusF)   $where .= " AND h.status = " . $db->quote($statusF);
if ($dateFrom)  $where .= " AND h.created_at >= " . $db->quote($dateFrom . ' 00:00:00');
if ($dateTo)    $where .= " AND h.created_at <= " . $db->quote($dateTo   . ' 23:59:59');
if ($search)    $where .= " AND (h.household_code LIKE " . $db->quote("%$search%") .
                          " OR b.barangay_name LIKE " . $db->quote("%$search%") .
                          " OR r.last_name LIKE " . $db->quote("%$search%") .
                          " OR r.first_name LIKE " . $db->quote("%$search%") . ")";

$orderBy = match($sort) {
    'barangay' => "b.barangay_name ASC, h.household_code ASC",
    'date'     => "h.created_at DESC",
    default    => "h.household_code ASC",
};

$totalCount = (int)$db->query("SELECT COUNT(*) FROM households h LEFT JOIN barangays b ON b.id=h.barangay_id LEFT JOIN residents r ON r.id=h.head_resident_id $where")->fetchColumn();
$totalPages = max(1, (int)ceil($totalCount / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$households = $db->query("
    SELECT h.id, h.household_code, h.total_members, h.total_senior, h.total_pwd,
           h.income_range, h.length_of_stay, h.contact_number, h.status, h.created_at,
           b.barangay_name,
           CONCAT(COALESCE(r.last_name,''), IF(r.first_name IS NOT NULL AND r.first_name != '', CONCAT(', ', r.first_name), '')) AS head_name,
           r.email AS head_email,
           CONCAT_WS(', ', NULLIF(a.purok,''), NULLIF(a.street,''),
                           NULLIF(a.barangay,''), NULLIF(a.city,'')) AS full_address
    FROM households h
    LEFT JOIN barangays b  ON b.id = h.barangay_id
    LEFT JOIN residents r  ON r.id = h.head_resident_id
    LEFT JOIN addresses a  ON a.id = h.address_id
    $where
    ORDER BY $orderBy
    LIMIT $perPage OFFSET $offset
")->fetchAll();

$pageTitle    = 'Households';
$pageIcon     = 'bi-house-fill';
$pageSubtitle = 'Households of ' . htmlspecialchars($brgyRow['barangay_name']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favcon.png">
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=3.0">
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
                    <li class="breadcrumb-item active">Households</li>
                </ol>
            </nav>
            <h4 class="fw-bold mb-0" style="color:#1a4f7a;">
                <i class="bi bi-house-fill me-2"></i><?= $pageTitle ?>
            </h4>
            <p class="text-muted small mb-0"><?= $pageSubtitle ?></p>
        </div>
        <div class="d-flex gap-2 no-print">
            <a href="<?= BASE_URL ?>/super_admin/export_br_households.php?mode=all&<?= http_build_query(array_filter(['search'=>$search,'barangay_id'=>$brgFilter?:null,'status'=>$statusF,'date_from'=>$dateFrom,'date_to'=>$dateTo])) ?>"
               class="btn btn-success btn-sm fw-semibold">
                <i class="bi bi-file-earmark-excel me-1"></i>Download All
            </a>
            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-printer me-1"></i>Print
            </button>
        </div>
    </div>

    <!-- Filters -->
    <div style="background:#fff;border-radius:14px;box-shadow:0 2px 12px rgba(0,0,0,0.06);border:1px solid #e0f0e4;padding:14px 18px;margin-bottom:14px;" class="no-print">
        <form method="GET" class="d-flex gap-2 flex-wrap align-items-center">
            <!-- Locked barangay label -->
            <a href="<?= BASE_URL ?>/super_admin/barangay_records.php?barangay_id=<?= $brgFilter ?>" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Back
            </a>
            <span style="background:#e0f2fe;color:#0369a1;padding:5px 14px;border-radius:20px;font-size:0.82rem;font-weight:700;">
                <i class="bi bi-geo-alt-fill me-1"></i><?= $brgyLabel ?>
            </span>
            <input type="hidden" name="barangay_id" value="<?= $brgFilter ?>">
            <select name="status" class="form-select form-select-sm" style="width:130px;" onchange="this.form.submit()">
                <option value="">All Status</option>
                <option value="active"   <?= $statusF==='active'  ?'selected':'' ?>>Active</option>
                <option value="inactive" <?= $statusF==='inactive'?'selected':'' ?>>Inactive</option>
                <option value="archived" <?= $statusF==='archived'?'selected':'' ?>>Archived</option>
            </select>
            <select name="sort" class="form-select form-select-sm" style="width:150px;" onchange="this.form.submit()">
                <option value="code"     <?= $sort==='code'    ?'selected':'' ?>>Sort: Code</option>
                <option value="barangay" <?= $sort==='barangay'?'selected':'' ?>>Sort: Barangay</option>
                <option value="date"     <?= $sort==='date'    ?'selected':'' ?>>Sort: Date</option>
            </select>
            <input type="date" name="date_from" class="form-control form-control-sm" style="width:140px;" value="<?= htmlspecialchars($dateFrom) ?>" title="Registered from">
            <input type="date" name="date_to"   class="form-control form-control-sm" style="width:140px;" value="<?= htmlspecialchars($dateTo)   ?>" title="Registered to">
            <input type="text" name="search" class="form-control form-control-sm" style="width:220px;"
                   placeholder="Search code, barangay, head name..." value="<?= htmlspecialchars($search) ?>">
            <button class="btn btn-primary btn-sm px-3"><i class="bi bi-search me-1"></i>Search</button>
            <a href="?" class="btn btn-outline-secondary btn-sm px-3"><i class="bi bi-x me-1"></i>Clear</a>
            <span class="ms-auto text-muted small"><?= number_format($totalCount) ?> household<?= $totalCount!==1?'s':'' ?></span>
        </form>
    </div>

    <!-- Table -->
    <div style="background:#fff;border-radius:18px;box-shadow:0 2px 16px rgba(0,0,0,0.07);border:1px solid #e0f0e4;overflow:hidden;">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr style="background:#f4f7fa;border-bottom:2px solid #d0ecd6;">
                        <?php foreach (['#','Household Code','Barangay','Head of Household','Address','Active Mbrs','Total','PWD','Senior','Status','Registered'] as $th): ?>
                        <th class="py-3 px-3" style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;white-space:nowrap;"><?= $th ?></th>
                        <?php endforeach; ?>
                        <th class="py-3 px-3 no-print" style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">View</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($households)): ?>
                    <tr><td colspan="12" class="text-center text-muted py-5">
                        <i class="bi bi-inbox" style="font-size:2.5rem;opacity:0.3;display:block;margin-bottom:8px;"></i>
                        No households found.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($households as $i => $hh):
                        $rowNum = $offset + $i + 1;
                        $sBg  = in_array($hh['status'],['inactive','archived']) ? '#f1f5f9' : '#dcfce7';
                        $sTxt = in_array($hh['status'],['inactive','archived']) ? '#475569' : '#166534';
                    ?>
                    <tr style="border-bottom:1px solid #f0f6fb;" onmouseover="this.style.background='#f8fbff'" onmouseout="this.style.background=''">
                        <td class="px-3 py-2 text-muted small" style="border:none;"><?= $rowNum ?></td>
                        <td class="px-3 py-2 fw-semibold" style="border:none;font-size:0.88rem;"><?= htmlspecialchars($hh['household_code'] ?? '—') ?></td>
                        <td class="px-3 py-2" style="border:none;">
                            <span class="small fw-semibold" style="color:#0369a1;white-space:nowrap;">
                                <?= htmlspecialchars($hh['barangay_name'] ?? '—') ?>
                            </span>
                        </td>
                        <td class="px-3 py-2" style="border:none;">
                            <div class="small fw-semibold"><?= htmlspecialchars($hh['head_name'] ?? '—') ?></div>
                            <?php if (!empty($hh['head_email'])): ?>
                            <div style="font-size:0.72rem;color:#3a7fa8;margin-top:1px;">
                                <i class="bi bi-envelope me-1"></i><?= htmlspecialchars($hh['head_email']) ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td class="px-3 py-2 small" style="border:none;max-width:180px;">
                            <span title="<?= htmlspecialchars($hh['full_address'] ?? '') ?>">
                                <?= htmlspecialchars(mb_strimwidth($hh['full_address'] ?? '—', 0, 40, '…')) ?>
                            </span>
                        </td>
                        <td class="px-3 py-2 text-center" style="border:none;">
                            <span style="background:#dbeafe;color:#1e40af;padding:2px 10px;border-radius:20px;font-size:0.78rem;font-weight:700;">
                                <?= (int)$hh['total_members'] ?>
                            </span>
                        </td>
                        <td class="px-3 py-2 small text-center" style="border:none;"><?= (int)$hh['total_members'] ?></td>
                        <td class="px-3 py-2 small text-center" style="border:none;"><?= (int)$hh['total_pwd'] ?></td>
                        <td class="px-3 py-2 small text-center" style="border:none;"><?= (int)$hh['total_senior'] ?></td>
                        <td class="px-3 py-2" style="border:none;">
                            <span style="background:<?= $sBg ?>;color:<?= $sTxt ?>;padding:2px 9px;border-radius:20px;font-size:0.72rem;font-weight:600;">
                                <?= ucfirst($hh['status'] ?? '—') ?>
                            </span>
                        </td>
                        <td class="px-3 py-2 small text-nowrap text-muted" style="border:none;"><?= date('M d, Y', strtotime($hh['created_at'])) ?></td>
                        <td class="px-3 py-2 no-print" style="border:none;">
                            <button class="btn btn-xs btn-outline-info"
                                    onclick='viewHousehold(<?= htmlspecialchars(json_encode($hh), ENT_QUOTES) ?>)'
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
    'status'      => $statusF,
    'date_from'   => $dateFrom,
    'date_to'     => $dateTo,
    'sort'        => $sort,
])) . '&';
$paginationLabel = 'household';
include __DIR__ . '/../includes/pagination.php';
?>

<!-- View Details Modal -->
<div class="modal fade" id="viewHHModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;overflow:hidden;">
            <div class="modal-header" style="background:linear-gradient(135deg,#7c3aed,#8b5cf6);border:none;">
                <h5 class="modal-title text-white fw-bold"><i class="bi bi-house-fill me-2"></i>Household Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="row g-3">
                    <div class="col-md-6"><small class="text-muted d-block">Household Code</small><strong id="vh_code"></strong></div>
                    <div class="col-md-6"><small class="text-muted d-block">Barangay</small><span id="vh_brgy"></span></div>
                    <div class="col-md-6"><small class="text-muted d-block">Head of Household</small><span id="vh_head"></span><div id="vh_head_email" class="small" style="color:#3a7fa8;margin-top:2px;"></div></div>
                    <div class="col-md-6"><small class="text-muted d-block">Status</small><span id="vh_status"></span></div>
                    <div class="col-md-12"><small class="text-muted d-block">Address</small><span id="vh_address"></span></div>
                    <div class="col-md-4"><small class="text-muted d-block">Active Members</small><strong id="vh_active"></strong></div>
                    <div class="col-md-4"><small class="text-muted d-block">Total Members</small><strong id="vh_total"></strong></div>
                    <div class="col-md-4"><small class="text-muted d-block">Income Range</small><span id="vh_income"></span></div>
                    <div class="col-md-4"><small class="text-muted d-block">Total Senior</small><span id="vh_senior"></span></div>
                    <div class="col-md-4"><small class="text-muted d-block">Total PWD</small><span id="vh_pwd"></span></div>
                    <div class="col-md-4"><small class="text-muted d-block">Length of Stay</small><span id="vh_los"></span></div>
                    <div class="col-md-6"><small class="text-muted d-block">Contact</small><span id="vh_contact"></span></div>
                    <div class="col-md-6"><small class="text-muted d-block">Date Registered</small><span id="vh_created"></span></div>
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
function viewHousehold(h) {
    document.getElementById('vh_code').textContent    = h.household_code  || '—';
    document.getElementById('vh_brgy').textContent    = h.barangay_name   || '—';
    document.getElementById('vh_head').textContent    = h.head_name       || '—';
    const emailEl = document.getElementById('vh_head_email');
    emailEl.innerHTML = h.head_email ? '<i class="bi bi-envelope me-1"></i>' + h.head_email : '';
    document.getElementById('vh_status').textContent  = h.status          || '—';
    document.getElementById('vh_address').textContent = h.full_address    || '—';
    document.getElementById('vh_active').textContent  = h.active_members  || '0';
    document.getElementById('vh_total').textContent   = h.total_members   || '0';
    document.getElementById('vh_income').textContent  = h.income_range    || '—';
    document.getElementById('vh_senior').textContent  = h.total_senior    || '0';
    document.getElementById('vh_pwd').textContent     = h.total_pwd       || '0';
    document.getElementById('vh_los').textContent     = h.length_of_stay  || '—';
    document.getElementById('vh_contact').textContent = h.contact_number  || '—';
    document.getElementById('vh_created').textContent = h.created_at      || '—';
    new bootstrap.Modal(document.getElementById('viewHHModal')).show();
}
</script>
</body>
</html>
