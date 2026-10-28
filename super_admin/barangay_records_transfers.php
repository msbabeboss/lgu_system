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
$statusF   = trim(strip_tags($_GET['status']    ?? ''));
$dirFilter = trim(strip_tags($_GET['direction'] ?? '')); // 'outgoing' | 'incoming' | ''
$sort      = trim(strip_tags($_GET['sort']      ?? 'date'));
$perPage   = 20;
$page      = max(1, (int)($_GET['page'] ?? 1));

// Build WHERE — always scoped to this barangay as origin or destination
$where = "WHERE (r.origin_barangay_id = $brgFilter OR r.destination_barangay_id = $brgFilter)";
if ($statusF)  $where .= " AND r.status = " . $db->quote($statusF);
if ($dirFilter === 'outgoing') $where .= " AND r.origin_barangay_id = $brgFilter";
if ($dirFilter === 'incoming') $where .= " AND r.destination_barangay_id = $brgFilter";
if ($search)   $where .= " AND r.resident_name LIKE " . $db->quote("%$search%");

$orderBy = match($sort) {
    'name'   => "r.resident_name ASC",
    'status' => "FIELD(r.status,'pending','approved','verified','rejected'), r.created_at DESC",
    default  => "r.created_at DESC",
};

$totalCount = (int)$db->query("SELECT COUNT(*) FROM resident_transfer_requests r $where")->fetchColumn();
$totalPages = max(1, (int)ceil($totalCount / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$transfers = $db->query("
    SELECT r.*,
           b1.barangay_name AS origin_name,
           b2.barangay_name AS dest_name,
           CONCAT(ru.first_name,' ',ru.last_name) AS requester_name,
           CONCAT(rv.first_name,' ',rv.last_name) AS reviewed_by_name,
           CONCAT(vf.first_name,' ',vf.last_name) AS verified_by_name
    FROM resident_transfer_requests r
    LEFT JOIN barangays b1 ON r.origin_barangay_id      = b1.id
    LEFT JOIN barangays b2 ON r.destination_barangay_id = b2.id
    LEFT JOIN users ru     ON r.requester_user_id       = ru.id
    LEFT JOIN users rv     ON r.reviewed_by             = rv.id
    LEFT JOIN users vf     ON r.verified_by             = vf.id
    $where
    ORDER BY $orderBy
    LIMIT $perPage OFFSET $offset
")->fetchAll();

// Quick counts for this barangay
$outCount = (int)$db->query("SELECT COUNT(*) FROM resident_transfer_requests WHERE origin_barangay_id=$brgFilter")->fetchColumn();
$inCount  = (int)$db->query("SELECT COUNT(*) FROM resident_transfer_requests WHERE destination_barangay_id=$brgFilter")->fetchColumn();
$verCount = (int)$db->query("SELECT COUNT(*) FROM resident_transfer_requests WHERE (origin_barangay_id=$brgFilter OR destination_barangay_id=$brgFilter) AND status='verified'")->fetchColumn();
$pendCount= (int)$db->query("SELECT COUNT(*) FROM resident_transfer_requests WHERE (origin_barangay_id=$brgFilter OR destination_barangay_id=$brgFilter) AND status='pending'")->fetchColumn();

$statusCfg = [
    'pending'  => ['label'=>'Pending',          'bg'=>'#fef3c7','txt'=>'#92400e','icon'=>'bi-hourglass-split'],
    'approved' => ['label'=>'Approved',         'bg'=>'#dbeafe','txt'=>'#1e40af','icon'=>'bi-check-circle-fill'],
    'rejected' => ['label'=>'Rejected',         'bg'=>'#fee2e2','txt'=>'#991b1b','icon'=>'bi-x-circle-fill'],
    'verified' => ['label'=>'Transfer Complete','bg'=>'#dcfce7','txt'=>'#166534','icon'=>'bi-patch-check-fill'],
];

$pageTitle    = 'Resident Transfers';
$pageIcon     = 'bi-arrow-left-right';
$pageSubtitle = 'Transfer records involving ' . htmlspecialchars($brgyRow['barangay_name']);
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
                    <li class="breadcrumb-item active">Transfers</li>
                </ol>
            </nav>
            <h4 class="fw-bold mb-0" style="color:#1a4f7a;">
                <i class="bi bi-arrow-left-right me-2"></i><?= $pageTitle ?>
            </h4>
            <p class="text-muted small mb-0"><?= $pageSubtitle ?></p>
        </div>
        <div class="d-flex gap-2 no-print">
            <a href="<?= BASE_URL ?>/super_admin/export_br_transfers.php?<?= htmlspecialchars(http_build_query(array_filter([
                'barangay_id' => $brgFilter,
                'search'      => $search,
                'status'      => $statusF,
                'direction'   => $dirFilter,
                'sort'        => $sort,
            ]))) ?>" class="btn btn-success btn-sm fw-semibold">
                <i class="bi bi-file-earmark-excel me-1"></i>Download CSV
            </a>
        </div>
    </div>

    <!-- Mini stat cards -->
    <div class="row g-2 mb-3">
        <?php foreach ([
            ['label'=>'Total Outgoing', 'val'=>$outCount,  'color'=>'#dc2626','bg'=>'#fee2e2','icon'=>'bi-box-arrow-right'],
            ['label'=>'Total Incoming', 'val'=>$inCount,   'color'=>'#0369a1','bg'=>'#dbeafe','icon'=>'bi-box-arrow-in-left'],
            ['label'=>'Completed',      'val'=>$verCount,  'color'=>'#059669','bg'=>'#dcfce7','icon'=>'bi-patch-check-fill'],
            ['label'=>'Pending',        'val'=>$pendCount, 'color'=>'#d97706','bg'=>'#fef3c7','icon'=>'bi-hourglass-split'],
        ] as $sc): ?>
        <div class="col-6 col-md-3">
            <div style="background:#fff;border-radius:12px;padding:12px 16px;box-shadow:0 2px 8px rgba(0,0,0,0.06);border:1px solid #e8f4fb;display:flex;align-items:center;gap:12px;">
                <div style="width:38px;height:38px;border-radius:9px;background:<?= $sc['bg'] ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="bi <?= $sc['icon'] ?>" style="font-size:1rem;color:<?= $sc['color'] ?>;"></i>
                </div>
                <div>
                    <div style="font-size:1.3rem;font-weight:800;color:<?= $sc['color'] ?>;line-height:1;"><?= number_format($sc['val']) ?></div>
                    <div style="font-size:0.68rem;color:#64748b;"><?= $sc['label'] ?></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Filters -->
    <div style="background:#fff;border-radius:14px;box-shadow:0 2px 12px rgba(0,0,0,0.06);border:1px solid #e0f0e4;padding:14px 18px;margin-bottom:14px;" class="no-print">
        <form method="GET" class="d-flex gap-2 flex-wrap align-items-center">
            <a href="<?= BASE_URL ?>/super_admin/barangay_records.php?barangay_id=<?= $brgFilter ?>" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Back
            </a>
            <span style="background:#e0f2fe;color:#0369a1;padding:5px 14px;border-radius:20px;font-size:0.82rem;font-weight:700;">
                <i class="bi bi-geo-alt-fill me-1"></i><?= $brgyLabel ?>
            </span>
            <input type="hidden" name="barangay_id" value="<?= $brgFilter ?>">

            <!-- Direction filter -->
            <select name="direction" class="form-select form-select-sm" style="width:150px;" onchange="this.form.submit()">
                <option value="">All Directions</option>
                <option value="outgoing" <?= $dirFilter==='outgoing'?'selected':'' ?>>Outgoing</option>
                <option value="incoming" <?= $dirFilter==='incoming'?'selected':'' ?>>Incoming</option>
            </select>

            <!-- Status filter -->
            <select name="status" class="form-select form-select-sm" style="width:150px;" onchange="this.form.submit()">
                <option value="">All Status</option>
                <?php foreach ($statusCfg as $k => $cfg): ?>
                    <option value="<?= $k ?>" <?= $statusF===$k?'selected':'' ?>><?= $cfg['label'] ?></option>
                <?php endforeach; ?>
            </select>

            <!-- Sort -->
            <select name="sort" class="form-select form-select-sm" style="width:140px;" onchange="this.form.submit()">
                <option value="date"   <?= $sort==='date'  ?'selected':'' ?>>Sort: Date</option>
                <option value="name"   <?= $sort==='name'  ?'selected':'' ?>>Sort: Name</option>
                <option value="status" <?= $sort==='status'?'selected':'' ?>>Sort: Status</option>
            </select>

            <!-- Search -->
            <input type="text" name="search" class="form-control form-control-sm" style="width:200px;"
                   placeholder="Search resident name..." value="<?= htmlspecialchars($search) ?>">
            <button class="btn btn-primary btn-sm px-3"><i class="bi bi-search me-1"></i>Search</button>
            <a href="?barangay_id=<?= $brgFilter ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x me-1"></i>Clear</a>
            <span class="ms-auto text-muted small"><?= number_format($totalCount) ?> record<?= $totalCount!==1?'s':'' ?></span>
        </form>
    </div>

    <!-- Table -->
    <div style="background:#fff;border-radius:18px;box-shadow:0 2px 16px rgba(0,0,0,0.07);border:1px solid #e0f0e4;overflow:hidden;">
        <div class="table-responsive">
            <table class="table mb-0" style="font-size:0.84rem;">
                <thead>
                    <tr style="background:#f4f7fa;border-bottom:2px solid #d0ecd6;">
                        <?php foreach (['#','Resident','Direction','From','To','Reason','Status','Filed','View'] as $th): ?>
                        <th class="py-3 px-3 <?= $th==='View'?'no-print':'' ?>"
                            style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;white-space:nowrap;"><?= $th ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($transfers)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-5">
                        <i class="bi bi-arrow-left-right" style="font-size:2.5rem;opacity:0.3;display:block;margin-bottom:8px;"></i>
                        No transfer records found for this barangay.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($transfers as $i => $t):
                        $rowNum   = $offset + $i + 1;
                        $sc       = $statusCfg[$t['status']] ?? ['label'=>$t['status'],'bg'=>'#f1f5f9','txt'=>'#475569','icon'=>'bi-circle'];
                        $isOut    = (int)$t['origin_barangay_id'] === $brgFilter;
                        $dirBg    = $isOut ? '#fee2e2' : '#dbeafe';
                        $dirTxt   = $isOut ? '#991b1b' : '#1e40af';
                        $dirIcon  = $isOut ? 'bi-box-arrow-right' : 'bi-box-arrow-in-left';
                        $dirLabel = $isOut ? 'Outgoing' : 'Incoming';
                    ?>
                    <tr style="border-bottom:1px solid #f0f6fb;" onmouseover="this.style.background='#f8fbff'" onmouseout="this.style.background=''">
                        <td class="px-3 py-2 text-muted small" style="border:none;"><?= $rowNum ?></td>
                        <td class="px-3 py-2 fw-semibold" style="border:none;"><?= htmlspecialchars($t['resident_name']) ?></td>
                        <td class="px-3 py-2" style="border:none;">
                            <span style="background:<?= $dirBg ?>;color:<?= $dirTxt ?>;padding:2px 9px;border-radius:20px;font-size:0.72rem;font-weight:700;">
                                <i class="bi <?= $dirIcon ?> me-1"></i><?= $dirLabel ?>
                            </span>
                        </td>
                        <td class="px-3 py-2 small" style="border:none;">
                            <?php if ($isOut): ?>
                                <span style="background:#e0f2fe;color:#0369a1;padding:1px 7px;border-radius:10px;font-size:0.72rem;font-weight:600;">
                                    <?= htmlspecialchars($brgyRow['barangay_name']) ?>
                                </span>
                            <?php else: ?>
                                <?= htmlspecialchars($t['origin_name'] ?? '—') ?>
                            <?php endif; ?>
                        </td>
                        <td class="px-3 py-2 small" style="border:none;">
                            <?php if (!$isOut): ?>
                                <span style="background:#e0f2fe;color:#0369a1;padding:1px 7px;border-radius:10px;font-size:0.72rem;font-weight:600;">
                                    <?= htmlspecialchars($brgyRow['barangay_name']) ?>
                                </span>
                            <?php else: ?>
                                <?= htmlspecialchars($t['dest_name'] ?? '—') ?>
                            <?php endif; ?>
                        </td>
                        <td class="px-3 py-2 small" style="border:none;"><?= htmlspecialchars($t['reason']) ?></td>
                        <td class="px-3 py-2" style="border:none;">
                            <span style="background:<?= $sc['bg'] ?>;color:<?= $sc['txt'] ?>;padding:2px 9px;border-radius:20px;font-size:0.72rem;font-weight:600;white-space:nowrap;">
                                <i class="bi <?= $sc['icon'] ?> me-1"></i><?= $sc['label'] ?>
                            </span>
                        </td>
                        <td class="px-3 py-2 small text-nowrap" style="border:none;">
                            <?= date('M d, Y', strtotime($t['created_at'])) ?>
                            <div class="text-muted" style="font-size:0.68rem;"><?= date('h:i A', strtotime($t['created_at'])) ?></div>
                        </td>
                        <td class="px-3 py-2 no-print" style="border:none;">
                            <button class="btn btn-xs btn-outline-info"
                                    onclick='viewTransfer(<?= htmlspecialchars(json_encode($t), ENT_QUOTES) ?>)'
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
$paginationBase = '?' . http_build_query(array_filter([
    'barangay_id' => $brgFilter,
    'search'      => $search,
    'status'      => $statusF,
    'direction'   => $dirFilter,
    'sort'        => $sort,
])) . '&';
$paginationLabel = 'record';
include __DIR__ . '/../includes/pagination.php';
?>

<!-- View Transfer Modal -->
<div class="modal fade" id="viewTransferModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;overflow:hidden;">
            <!-- Header -->
            <div id="vt_header" style="padding:14px 20px;">
                <div class="d-flex align-items-center gap-2">
                    <div style="width:34px;height:34px;background:rgba(255,255,255,0.2);border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i id="vt_header_icon" class="bi text-white" style="font-size:1rem;"></i>
                    </div>
                    <div>
                        <div id="vt_header_label" style="font-size:0.68rem;color:rgba(255,255,255,0.75);text-transform:uppercase;letter-spacing:0.08em;"></div>
                        <div id="vt_resident_name" style="font-size:1rem;font-weight:800;color:#fff;"></div>
                    </div>
                    <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal"></button>
                </div>
            </div>

            <div class="modal-body p-4">
                <div class="row g-3">
                    <div class="col-md-6">
                        <small class="text-muted d-block">Direction</small>
                        <span id="vt_direction" class="fw-semibold"></span>
                    </div>
                    <div class="col-md-6">
                        <small class="text-muted d-block">Status</small>
                        <span id="vt_status"></span>
                    </div>
                    <div class="col-md-6">
                        <small class="text-muted d-block">From Barangay</small>
                        <strong id="vt_from"></strong>
                    </div>
                    <div class="col-md-6">
                        <small class="text-muted d-block">To Barangay</small>
                        <strong id="vt_to"></strong>
                    </div>
                    <div class="col-md-6">
                        <small class="text-muted d-block">Reason</small>
                        <span id="vt_reason"></span>
                    </div>
                    <div class="col-md-6">
                        <small class="text-muted d-block">Requested By</small>
                        <span id="vt_reqby"></span>
                    </div>
                    <div class="col-md-6">
                        <small class="text-muted d-block">Date Filed</small>
                        <span id="vt_date"></span>
                    </div>
                    <div class="col-md-6" id="vt_reviewed_wrap" style="display:none;">
                        <small class="text-muted d-block">Reviewed By / At</small>
                        <span id="vt_reviewed"></span>
                    </div>
                    <div class="col-12" id="vt_notes_wrap" style="display:none;">
                        <small class="text-muted d-block">Requester Notes</small>
                        <div id="vt_notes" class="small text-muted" style="white-space:pre-wrap;background:#f8fafc;padding:8px 12px;border-radius:8px;border:1px solid #e5e7eb;"></div>
                    </div>
                    <div class="col-12" id="vt_admin_notes_wrap" style="display:none;">
                        <small class="text-muted d-block">LGU Notes</small>
                        <div id="vt_admin_notes" class="small" style="white-space:pre-wrap;background:#f8fafc;padding:8px 12px;border-radius:8px;border:1px solid #e5e7eb;"></div>
                    </div>
                    <div class="col-12" id="vt_verified_wrap" style="display:none;">
                        <small class="text-muted d-block">Verified By / At</small>
                        <span id="vt_verified" class="small"></span>
                    </div>
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
var brgayId = <?= $brgFilter ?>;

var statusCfg = {
    pending:  { label:'Pending',           bg:'#fef3c7', txt:'#92400e', icon:'bi-hourglass-split',  headerGrad:'linear-gradient(135deg,#d97706,#f59e0b)' },
    approved: { label:'Approved',          bg:'#dbeafe', txt:'#1e40af', icon:'bi-check-circle-fill', headerGrad:'linear-gradient(135deg,#1d4ed8,#3b82f6)' },
    rejected: { label:'Rejected',          bg:'#fee2e2', txt:'#991b1b', icon:'bi-x-circle-fill',    headerGrad:'linear-gradient(135deg,#b91c1c,#ef4444)' },
    verified: { label:'Transfer Complete', bg:'#dcfce7', txt:'#166534', icon:'bi-patch-check-fill', headerGrad:'linear-gradient(135deg,#047857,#10b981)' },
};

function viewTransfer(t) {
    var sc  = statusCfg[t.status] || { label:t.status, bg:'#f1f5f9', txt:'#475569', icon:'bi-circle', headerGrad:'linear-gradient(135deg,#475569,#64748b)' };
    var isOut = parseInt(t.origin_barangay_id) === brgayId;

    // Header
    var hdr = document.getElementById('vt_header');
    hdr.style.background = sc.headerGrad;
    document.getElementById('vt_header_icon').className   = 'bi ' + sc.icon + ' text-white';
    document.getElementById('vt_header_label').textContent = isOut ? 'Outgoing Transfer' : 'Incoming Transfer';
    document.getElementById('vt_resident_name').textContent = t.resident_name || '—';

    // Direction badge
    var dirBg  = isOut ? '#fee2e2' : '#dbeafe';
    var dirTxt = isOut ? '#991b1b' : '#1e40af';
    var dirIcon= isOut ? 'bi-box-arrow-right' : 'bi-box-arrow-in-left';
    var dirLbl = isOut ? 'Outgoing' : 'Incoming';
    document.getElementById('vt_direction').innerHTML =
        '<span style="background:'+dirBg+';color:'+dirTxt+';padding:3px 10px;border-radius:20px;font-size:0.75rem;font-weight:700;">' +
        '<i class="bi '+dirIcon+' me-1"></i>'+dirLbl+'</span>';

    // Status badge
    document.getElementById('vt_status').innerHTML =
        '<span style="background:'+sc.bg+';color:'+sc.txt+';padding:3px 10px;border-radius:20px;font-size:0.75rem;font-weight:600;">' +
        '<i class="bi '+sc.icon+' me-1"></i>'+sc.label+'</span>';

    document.getElementById('vt_from').textContent   = t.origin_name  || '—';
    document.getElementById('vt_to').textContent     = t.dest_name    || '—';
    document.getElementById('vt_reason').textContent = t.reason       || '—';
    document.getElementById('vt_reqby').textContent  = t.requester_name || '—';

    var d = new Date((t.created_at || '').replace(' ','T'));
    document.getElementById('vt_date').textContent = isNaN(d) ? (t.created_at||'—')
        : d.toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'})
          + ' ' + d.toLocaleTimeString('en-PH',{hour:'2-digit',minute:'2-digit'});

    // Reviewed
    if (t.reviewed_by_name && t.reviewed_at) {
        document.getElementById('vt_reviewed').textContent = t.reviewed_by_name + ' — ' + t.reviewed_at;
        document.getElementById('vt_reviewed_wrap').style.display = '';
    } else {
        document.getElementById('vt_reviewed_wrap').style.display = 'none';
    }

    // Notes
    if (t.notes) {
        document.getElementById('vt_notes').textContent = t.notes;
        document.getElementById('vt_notes_wrap').style.display = '';
    } else {
        document.getElementById('vt_notes_wrap').style.display = 'none';
    }
    if (t.admin_notes) {
        document.getElementById('vt_admin_notes').textContent = t.admin_notes;
        document.getElementById('vt_admin_notes_wrap').style.display = '';
    } else {
        document.getElementById('vt_admin_notes_wrap').style.display = 'none';
    }

    // Verified
    if (t.verified_by_name && t.verified_at) {
        document.getElementById('vt_verified').textContent = t.verified_by_name + ' — ' + t.verified_at;
        document.getElementById('vt_verified_wrap').style.display = '';
    } else {
        document.getElementById('vt_verified_wrap').style.display = 'none';
    }

    new bootstrap.Modal(document.getElementById('viewTransferModal')).show();
}
</script>
</body>
</html>
