<?php
require_once __DIR__ . '/../config/config.php';
requireRole('super_admin');

$db = Database::getInstance()->getConnection();
$u  = currentUser();

// Require barangay selection
$brgFilter = (int)($_GET['barangay_id'] ?? 0);
if (!$brgFilter) { redirect(BASE_URL . '/super_admin/barangay_records.php'); }
$brgyRow = $db->prepare("SELECT barangay_name, municipality FROM barangays WHERE id=? AND status='active'");
$brgyRow->execute([$brgFilter]);
$brgyRow = $brgyRow->fetch();
if (!$brgyRow) { redirect(BASE_URL . '/super_admin/barangay_records.php'); }
$brgyLabel = htmlspecialchars($brgyRow['barangay_name'] . ($brgyRow['municipality'] ? ' — '.$brgyRow['municipality'] : ''));

// Filters
$search    = trim(strip_tags($_GET['search']    ?? ''));
$typeF     = trim(strip_tags($_GET['type']      ?? ''));
$dateFrom  = trim(strip_tags($_GET['date_from'] ?? ''));
$dateTo    = trim(strip_tags($_GET['date_to']   ?? ''));
$sort      = trim(strip_tags($_GET['sort']      ?? 'date_issued'));
$perPage   = 20;
$page      = max(1, (int)($_GET['page'] ?? 1));

$allBarangays = []; // locked — barangay pre-selected from hub

// Distinct certificate types
$certTypes = [];
try {
    $certTypes = $db->query("SELECT DISTINCT certificate_type FROM certificates WHERE certificate_type IS NOT NULL AND certificate_type != '' ORDER BY certificate_type")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

// Build WHERE
$where = "WHERE 1=1";
if ($brgFilter) $where .= " AND c.barangay_id = " . (int)$brgFilter;
if ($typeF)     $where .= " AND c.certificate_type = " . $db->quote($typeF);
if ($dateFrom)  $where .= " AND c.date_issued >= " . $db->quote($dateFrom);
if ($dateTo)    $where .= " AND c.date_issued <= " . $db->quote($dateTo);
if ($search)    $where .= " AND (c.certificate_type LIKE " . $db->quote("%$search%") .
                          " OR r.first_name LIKE " . $db->quote("%$search%") .
                          " OR r.last_name LIKE " . $db->quote("%$search%") .
                          " OR b.barangay_name LIKE " . $db->quote("%$search%") . ")";

$orderBy = match($sort) {
    'barangay'         => "b.barangay_name ASC, c.date_issued DESC",
    'certificate_type' => "c.certificate_type ASC",
    default            => "c.date_issued DESC, c.created_at DESC",
};

$totalCount = (int)$db->query("
    SELECT COUNT(*) FROM certificates c
    LEFT JOIN barangays b ON b.id = c.barangay_id
    LEFT JOIN residents r ON r.id = c.resident_id
    LEFT JOIN users u     ON u.id = c.issued_by
    $where
")->fetchColumn();
$totalPages = max(1, (int)ceil($totalCount / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$certificates = $db->query("
    SELECT c.id, c.certificate_type, c.purpose, c.date_issued, c.created_at,
           b.barangay_name,
           CONCAT(r.last_name, ', ', r.first_name,
                  IF(r.middle_name IS NOT NULL AND r.middle_name != '',
                     CONCAT(' ', LEFT(r.middle_name,1), '.'), '')) AS resident_name,
           CONCAT(u.first_name,' ',u.last_name) AS issued_by_name,
           u.email AS issued_by_email
    FROM certificates c
    LEFT JOIN barangays b ON b.id = c.barangay_id
    LEFT JOIN residents r ON r.id = c.resident_id
    LEFT JOIN users u     ON u.id = c.issued_by
    $where
    ORDER BY $orderBy
    LIMIT $perPage OFFSET $offset
")->fetchAll();

$pageTitle    = 'Certificates';
$pageIcon     = 'bi-award-fill';
$pageSubtitle = 'Certificates issued in ' . htmlspecialchars($brgyRow['barangay_name']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favcon.png">
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=3.2">
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
                    <li class="breadcrumb-item active">Certificates</li>
                </ol>
            </nav>
            <h4 class="fw-bold mb-0" style="color:#1a4f7a;">
                <i class="bi bi-award-fill me-2"></i><?= $pageTitle ?>
            </h4>
            <p class="text-muted small mb-0"><?= $pageSubtitle ?></p>
        </div>
        <div class="d-flex gap-2 no-print">
            <a href="<?= BASE_URL ?>/super_admin/export_br_certificates.php?<?= http_build_query(array_filter(['search'=>$search,'barangay_id'=>$brgFilter?:null,'type'=>$typeF,'date_from'=>$dateFrom,'date_to'=>$dateTo])) ?>"
               class="btn btn-success btn-sm fw-semibold">
                <i class="bi bi-file-earmark-excel me-1"></i>Download CSV
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
            <?php if (!empty($certTypes)): ?>
            <select name="type" class="form-select form-select-sm" style="width:190px;" onchange="this.form.submit()">
                <option value="">All Types</option>
                <?php foreach ($certTypes as $ct): ?>
                    <option value="<?= htmlspecialchars($ct) ?>" <?= $typeF===$ct?'selected':'' ?>><?= htmlspecialchars($ct) ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <select name="sort" class="form-select form-select-sm" style="width:175px;" onchange="this.form.submit()">
                <option value="date_issued"      <?= $sort==='date_issued'     ?'selected':'' ?>>Sort: Date Issued</option>
                <option value="barangay"         <?= $sort==='barangay'        ?'selected':'' ?>>Sort: Barangay</option>
                <option value="certificate_type" <?= $sort==='certificate_type'?'selected':'' ?>>Sort: Type</option>
            </select>
            <input type="date" name="date_from" class="form-control form-control-sm" style="width:140px;" value="<?= htmlspecialchars($dateFrom) ?>" title="Issued from">
            <input type="date" name="date_to"   class="form-control form-control-sm" style="width:140px;" value="<?= htmlspecialchars($dateTo)   ?>" title="Issued to">
            <input type="text" name="search" class="form-control form-control-sm" style="width:210px;"
                   placeholder="Search name, type, barangay..." value="<?= htmlspecialchars($search) ?>">
            <button class="btn btn-primary btn-sm px-3"><i class="bi bi-search me-1"></i>Search</button>
            <a href="?" class="btn btn-outline-secondary btn-sm px-3"><i class="bi bi-x me-1"></i>Clear</a>
            <span class="ms-auto text-muted small"><?= number_format($totalCount) ?> certificate<?= $totalCount!==1?'s':'' ?></span>
        </form>
    </div>

    <!-- Table -->
    <div style="background:#fff;border-radius:18px;box-shadow:0 2px 16px rgba(0,0,0,0.07);border:1px solid #e0f0e4;overflow:hidden;">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr style="background:#f4f7fa;border-bottom:2px solid #d0ecd6;">
                        <?php foreach (['#','Certificate Type','Resident Name','Barangay','Purpose','Issued By','Date Issued','Date Added'] as $th): ?>
                        <th class="py-3 px-3" style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;white-space:nowrap;"><?= $th ?></th>
                        <?php endforeach; ?>
                        <th class="py-3 px-3 no-print" style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">View</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($certificates)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-5">
                        <i class="bi bi-inbox" style="font-size:2.5rem;opacity:0.3;display:block;margin-bottom:8px;"></i>
                        No certificates found.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($certificates as $i => $cert):
                        $rowNum = $offset + $i + 1;
                    ?>
                    <tr style="border-bottom:1px solid #f0f6fb;" onmouseover="this.style.background='#f8fbff'" onmouseout="this.style.background=''">
                        <td class="px-3 py-2 text-muted small" style="border:none;"><?= $rowNum ?></td>
                        <td class="px-3 py-2" style="border:none;">
                            <span style="background:#cffafe;color:#0891b2;padding:2px 8px;border-radius:20px;font-size:0.72rem;font-weight:600;">
                                <?= htmlspecialchars($cert['certificate_type'] ?? '—') ?>
                            </span>
                        </td>
                        <td class="px-3 py-2 small fw-semibold" style="border:none;"><?= htmlspecialchars($cert['resident_name'] ?? '—') ?></td>
                        <td class="px-3 py-2" style="border:none;">
                            <span style="background:#e0f2fe;color:#0369a1;padding:2px 8px;border-radius:20px;font-size:0.72rem;font-weight:600;">
                                <?= htmlspecialchars($cert['barangay_name'] ?? '—') ?>
                            </span>
                        </td>
                        <td class="px-3 py-2 small" style="border:none;max-width:160px;">
                            <span title="<?= htmlspecialchars($cert['purpose'] ?? '') ?>">
                                <?= htmlspecialchars(mb_strimwidth($cert['purpose'] ?? '—', 0, 40, '…')) ?>
                            </span>
                        </td>
                        <td class="px-3 py-2 small" style="border:none;white-space:nowrap;">
                            <div class="fw-semibold" style="font-size:0.82rem;"><?= htmlspecialchars($cert['issued_by_name'] ?? '—') ?></div>
                            <?php if (!empty($cert['issued_by_email'])): ?>
                            <div class="text-muted" style="font-size:0.72rem;"><?= htmlspecialchars($cert['issued_by_email']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-3 py-2 small text-nowrap" style="border:none;"><?= $cert['date_issued'] ? date('M d, Y', strtotime($cert['date_issued'])) : '—' ?></td>
                        <td class="px-3 py-2 small text-nowrap text-muted" style="border:none;"><?= date('M d, Y', strtotime($cert['created_at'])) ?></td>
                        <td class="px-3 py-2 no-print" style="border:none;">
                            <button class="btn btn-xs btn-outline-info"
                                    onclick='viewCert(<?= htmlspecialchars(json_encode($cert), ENT_QUOTES) ?>)'
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
    'type'        => $typeF,
    'date_from'   => $dateFrom,
    'date_to'     => $dateTo,
    'sort'        => $sort,
])) . '&';
$paginationLabel = 'certificate';
include __DIR__ . '/../includes/pagination.php';
?>

<!-- View Details Modal -->
<div class="modal fade" id="viewCertModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;overflow:hidden;">
            <div class="modal-header" style="background:linear-gradient(135deg,#0891b2,#06b6d4);border:none;">
                <h5 class="modal-title text-white fw-bold"><i class="bi bi-award-fill me-2"></i>Certificate Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="row g-3">
                    <div class="col-md-6"><small class="text-muted d-block">Certificate Type</small><strong id="vc_type"></strong></div>
                    <div class="col-md-6"><small class="text-muted d-block">Resident Name</small><span id="vc_resident"></span></div>
                    <div class="col-md-6"><small class="text-muted d-block">Barangay</small><span id="vc_brgy"></span></div>
                    <div class="col-md-6"><small class="text-muted d-block">Issued By</small><span id="vc_issuedby"></span></div>
                    <div class="col-md-12"><small class="text-muted d-block">Purpose</small><span id="vc_purpose"></span></div>
                    <div class="col-md-6"><small class="text-muted d-block">Date Issued</small><span id="vc_issued"></span></div>
                    <div class="col-md-6"><small class="text-muted d-block">Date Added</small><span id="vc_created"></span></div>
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
function viewCert(c) {
    document.getElementById('vc_type').textContent     = c.certificate_type || '—';
    document.getElementById('vc_resident').textContent = c.resident_name    || '—';
    document.getElementById('vc_brgy').textContent     = c.barangay_name    || '—';
    document.getElementById('vc_issuedby').textContent = c.issued_by_name   || '—';
    document.getElementById('vc_issuedby').innerHTML   = (c.issued_by_name || '—') +
        (c.issued_by_email ? '<br><small class="text-muted">' + c.issued_by_email + '</small>' : '');
    document.getElementById('vc_purpose').textContent  = c.purpose          || '—';
    document.getElementById('vc_issued').textContent   = c.date_issued      || '—';
    document.getElementById('vc_created').textContent  = c.created_at       || '—';
    new bootstrap.Modal(document.getElementById('viewCertModal')).show();
}
</script>
</body>
</html>
