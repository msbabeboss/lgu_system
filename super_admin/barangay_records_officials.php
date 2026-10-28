<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/mailer.php';
requireRole('super_admin');

$db = Database::getInstance()->getConnection();
$u  = currentUser();

// ── POST: Edit official personal info ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrAbort();
    $action = $_POST['action'] ?? '';

    if ($action === 'edit_official') {
        $offId    = (int)($_POST['official_id'] ?? 0);
        $fullName = sanitize($_POST['full_name'] ?? '');
        $contact  = sanitize($_POST['contact']   ?? '');
        $email    = sanitize($_POST['email']      ?? '');

        // Validate contact number format
        if ($contact && !preg_match('/^09\d{9}$/', $contact)) {
            flash('br_off', 'Contact number must be 11 digits and start with 09.', 'danger');
            redirect($_SERVER['HTTP_REFERER'] ?? BASE_URL . '/super_admin/barangay_records.php');
        }

        // Duplicate contact check (exclude self)
        if ($contact) {
            $dupQ = $db->prepare("SELECT id FROM officials WHERE contact=? AND id!=? AND status!='inactive'");
            $dupQ->execute([$contact, $offId]);
            if ($dupQ->fetch()) {
                flash('br_off', "Contact number <strong>$contact</strong> is already in use by another official.", 'danger');
                redirect($_SERVER['HTTP_REFERER'] ?? BASE_URL . '/super_admin/barangay_records.php');
            }
        }

        // Photo upload / base64
        $photo = null;
        if (!empty($_POST['photo_base64'])) {
            $b64data = $_POST['photo_base64'];
            if (preg_match('/^data:image\/(jpeg|png|webp);base64,/', $b64data, $typeMatch)) {
                $imgData = base64_decode(preg_replace('/^data:image\/\w+;base64,/', '', $b64data));
                $ext     = ($typeMatch[1] === 'png') ? 'png' : 'jpg';
                $dir     = UPLOAD_PATH . 'officials/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $fname   = 'official_' . time() . '_' . random_int(1000, 9999) . '.' . $ext;
                file_put_contents($dir . $fname, $imgData);
                $photo   = 'officials/' . $fname;
            }
        } elseif (!empty($_FILES['photo']['name'])) {
            $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png'])) {
                $dir   = UPLOAD_PATH . 'officials/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $fname = 'official_' . time() . '.' . $ext;
                move_uploaded_file($_FILES['photo']['tmp_name'], $dir . $fname);
                $photo = 'officials/' . $fname;
            }
        }

        if ($offId && $fullName) {
            if ($photo) {
                $db->prepare("UPDATE officials SET full_name=?, contact=?, email=?, photo=? WHERE id=?")
                   ->execute([$fullName, $contact ?: null, $email ?: null, $photo, $offId]);
            } else {
                $db->prepare("UPDATE officials SET full_name=?, contact=?, email=? WHERE id=?")
                   ->execute([$fullName, $contact ?: null, $email ?: null, $offId]);
            }
            logActivity($u['id'], 'EDIT_OFFICIAL', 'Barangay Records', "Super admin updated official ID $offId: $fullName");
            flash('br_off', "Official <strong>$fullName</strong> updated successfully.", 'success');
        } else {
            flash('br_off', 'Invalid official or missing name.', 'danger');
        }

        // Redirect back preserving filters
        $qs = http_build_query(array_filter([
            'barangay_id' => (int)($_POST['barangay_id'] ?? 0) ?: null,
            'search'      => $_POST['search_back']   ?? null,
            'position'    => $_POST['position_back'] ?? null,
            'status'      => $_POST['status_back']   ?? null,
            'sort'        => $_POST['sort_back']     ?? null,
            'page'        => $_POST['page_back']     ?? null,
        ]));
        redirect(BASE_URL . '/super_admin/barangay_records_officials.php?' . $qs);
    }
}

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
$positionF = trim(strip_tags($_GET['position'] ?? ''));
$statusF   = trim(strip_tags($_GET['status']   ?? ''));
$sort      = trim(strip_tags($_GET['sort']     ?? 'name'));
$dateFrom  = trim(strip_tags($_GET['date_from']?? ''));
$dateTo    = trim(strip_tags($_GET['date_to']  ?? ''));
$perPage   = 20;
$page      = max(1, (int)($_GET['page'] ?? 1));

$allBarangays = []; // locked — barangay pre-selected from hub

$positions = ['Barangay Captain','Barangay Secretary','Barangay Treasurer','Barangay Kagawad','SK Chairperson'];

// Build WHERE
$where = "WHERE 1=1";
if ($brgFilter) $where .= " AND o.barangay_id = " . (int)$brgFilter;
if ($positionF) $where .= " AND o.position = " . $db->quote($positionF);
if ($statusF)   $where .= " AND o.status = " . $db->quote($statusF);
if ($dateFrom)  $where .= " AND o.term_start >= " . $db->quote($dateFrom);
if ($dateTo)    $where .= " AND o.term_start <= " . $db->quote($dateTo);
if ($search)    $where .= " AND (o.full_name LIKE " . $db->quote("%$search%") .
                          " OR o.position LIKE " . $db->quote("%$search%") .
                          " OR b.barangay_name LIKE " . $db->quote("%$search%") . ")";

$orderBy = match($sort) {
    'position' => "o.position ASC, o.full_name ASC",
    'barangay' => "b.barangay_name ASC, o.full_name ASC",
    'term_end' => "o.term_end ASC",
    default    => "b.barangay_name ASC, FIELD(o.status,'active','inactive'), o.position ASC, o.full_name ASC",
};

$totalCount = (int)$db->query("SELECT COUNT(*) FROM officials o LEFT JOIN barangays b ON b.id=o.barangay_id $where")->fetchColumn();
$totalPages = max(1, (int)ceil($totalCount / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$officials = $db->query("
    SELECT o.id, o.full_name, o.position, o.contact, o.email,
           o.term_start, o.term_end, o.status, o.photo,
           b.barangay_name
    FROM officials o
    LEFT JOIN barangays b ON b.id = o.barangay_id
    $where
    ORDER BY $orderBy
    LIMIT $perPage OFFSET $offset
")->fetchAll();

$today = date('Y-m-d');

$pageTitle    = 'Barangay Officials';
$pageIcon     = 'bi-person-badge-fill';
$pageSubtitle = 'Officials of ' . htmlspecialchars($brgyRow['barangay_name']);
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

    <?php $flash = getFlash('br_off'); if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show mb-3">
            <?= $flash['message'] ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Page Header -->
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <div>
            <nav aria-label="breadcrumb" class="mb-1">
                <ol class="breadcrumb mb-0" style="font-size:0.78rem;">
                    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/super_admin/barangay_records.php">Barangay Records</a></li>
                    <li class="breadcrumb-item active">Officials</li>
                </ol>
            </nav>
            <h4 class="fw-bold mb-0" style="color:#1a4f7a;">
                <i class="bi bi-person-badge-fill me-2"></i><?= $pageTitle ?>
            </h4>
            <p class="text-muted small mb-0"><?= $pageSubtitle ?></p>
        </div>
        <div class="d-flex gap-2 no-print">
            <a href="<?= BASE_URL ?>/super_admin/export_br_officials.php?mode=all&<?= http_build_query(array_filter(['search'=>$search,'barangay_id'=>$brgFilter?:null,'position'=>$positionF,'status'=>$statusF,'date_from'=>$dateFrom,'date_to'=>$dateTo])) ?>"
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
            <select name="position" class="form-select form-select-sm" style="width:185px;" onchange="this.form.submit()">
                <option value="">All Positions</option>
                <?php foreach ($positions as $pos): ?>
                    <option value="<?= $pos ?>" <?= $positionF===$pos?'selected':'' ?>><?= $pos ?></option>
                <?php endforeach; ?>
            </select>
            <select name="status" class="form-select form-select-sm" style="width:130px;" onchange="this.form.submit()">
                <option value="">All Status</option>
                <option value="active"   <?= $statusF==='active'  ?'selected':'' ?>>Active</option>
                <option value="inactive" <?= $statusF==='inactive'?'selected':'' ?>>Inactive</option>
            </select>
            <select name="sort" class="form-select form-select-sm" style="width:150px;" onchange="this.form.submit()">
                <option value="name"     <?= $sort==='name'    ?'selected':'' ?>>Sort: Name</option>
                <option value="position" <?= $sort==='position'?'selected':'' ?>>Sort: Position</option>
                <option value="barangay" <?= $sort==='barangay'?'selected':'' ?>>Sort: Barangay</option>
                <option value="term_end" <?= $sort==='term_end'?'selected':'' ?>>Sort: Term End</option>
            </select>
            <input type="date" name="date_from" class="form-control form-control-sm" style="width:140px;" value="<?= htmlspecialchars($dateFrom) ?>" title="Term start from">
            <input type="date" name="date_to"   class="form-control form-control-sm" style="width:140px;" value="<?= htmlspecialchars($dateTo)   ?>" title="Term start to">
            <input type="text" name="search" class="form-control form-control-sm" style="width:220px;"
                   placeholder="Search name, position, barangay..." value="<?= htmlspecialchars($search) ?>">
            <button class="btn btn-primary btn-sm px-3"><i class="bi bi-search me-1"></i>Search</button>
            <a href="?" class="btn btn-outline-secondary btn-sm px-3"><i class="bi bi-x me-1"></i>Clear</a>
            <span class="ms-auto text-muted small"><?= number_format($totalCount) ?> official<?= $totalCount!==1?'s':'' ?></span>
        </form>
    </div>

    <!-- Table -->
    <div style="background:#fff;border-radius:18px;box-shadow:0 2px 16px rgba(0,0,0,0.07);border:1px solid #e0f0e4;overflow:hidden;">
        <div class="table-responsive">
            <table class="table mb-0" style="font-size:0.82rem;">
                <thead>
                    <tr style="background:#f4f7fa;border-bottom:2px solid #d0ecd6;">
                        <?php foreach (['#','Official','Position','Term','Contact','Status'] as $th): ?>
                        <th class="py-2 px-2" style="font-size:0.67rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;white-space:nowrap;"><?= $th ?></th>
                        <?php endforeach; ?>
                        <th class="py-2 px-2 no-print" style="font-size:0.67rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($officials)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-5">
                        <i class="bi bi-inbox" style="font-size:2.5rem;opacity:0.3;display:block;margin-bottom:8px;"></i>
                        No officials found.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($officials as $i => $o):
                        $rowNum    = $offset + $i + 1;
                        $sBg       = $o['status']==='active' ? '#dcfce7' : '#f1f5f9';
                        $sTxt      = $o['status']==='active' ? '#166534' : '#475569';
                        $termExp   = $o['term_end'] && $o['term_end'] < $today && $o['status']==='active';
                        $termLabel = $o['term_start'] && $o['term_end']
                            ? date('M Y', strtotime($o['term_start'])) . ' – ' . date('M Y', strtotime($o['term_end']))
                            : ($o['term_start'] ? date('M Y', strtotime($o['term_start'])) . ' – Ongoing' : '—');
                    ?>
                    <tr style="border-bottom:1px solid #f0f6fb;" onmouseover="this.style.background='#f8fbff'" onmouseout="this.style.background=''">
                        <td class="px-2 py-2 text-muted" style="border:none;font-size:0.75rem;"><?= $rowNum ?></td>
                        <td class="px-2 py-2" style="border:none;">
                            <div class="d-flex align-items-center gap-2">
                                <?php if ($o['photo']): ?>
                                    <img src="<?= UPLOAD_URL . htmlspecialchars($o['photo']) ?>"
                                         style="width:26px;height:26px;border-radius:50%;object-fit:cover;flex-shrink:0;"
                                         onerror="this.style.display='none';this.nextElementSibling.style.display='flex';"
                                         alt="Photo">
                                    <div style="display:none;width:26px;height:26px;border-radius:50%;background:#e0f2fe;color:#0369a1;align-items:center;justify-content:center;font-weight:700;font-size:0.68rem;flex-shrink:0;">
                                        <?= strtoupper(substr($o['full_name'],0,2)) ?>
                                    </div>
                                <?php else: ?>
                                    <div style="width:26px;height:26px;border-radius:50%;background:#e0f2fe;color:#0369a1;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:0.68rem;flex-shrink:0;">
                                        <?= strtoupper(substr($o['full_name'],0,2)) ?>
                                    </div>
                                <?php endif; ?>
                                <div style="min-width:0;">
                                    <div class="fw-semibold" style="font-size:0.82rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:160px;"
                                         title="<?= htmlspecialchars($o['full_name']) ?>"><?= htmlspecialchars($o['full_name']) ?></div>
                                    <?php if ($o['email']): ?>
                                    <div style="font-size:0.68rem;color:#3a7fa8;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:160px;"
                                         title="<?= htmlspecialchars($o['email']) ?>"><?= htmlspecialchars($o['email']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td class="px-2 py-2" style="border:none;">
                            <span style="background:#e0f2fe;color:#0369a1;padding:1px 7px;border-radius:20px;font-size:0.7rem;font-weight:600;white-space:nowrap;">
                                <?= htmlspecialchars($o['position'] ?? '—') ?>
                            </span>
                        </td>
                        <td class="px-2 py-2 small text-nowrap" style="border:none;font-size:0.75rem;">
                            <?= htmlspecialchars($termLabel) ?>
                            <?php if ($termExp): ?>
                                <span style="background:#fed7aa;color:#9a3412;padding:1px 5px;border-radius:8px;font-size:0.58rem;margin-left:3px;">Expired</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-2 py-2 small text-nowrap" style="border:none;font-size:0.75rem;"><?= htmlspecialchars($o['contact'] ?? '—') ?></td>
                        <td class="px-2 py-2" style="border:none;">
                            <span style="background:<?= $sBg ?>;color:<?= $sTxt ?>;padding:1px 8px;border-radius:20px;font-size:0.7rem;font-weight:600;white-space:nowrap;">
                                <?= ucfirst($o['status']) ?>
                            </span>
                        </td>
                        <td class="px-2 py-2 no-print" style="border:none;">
                            <div class="d-flex gap-1">
                                <button class="btn btn-xs btn-outline-info"
                                        onclick='viewOfficial(<?= htmlspecialchars(json_encode($o), ENT_QUOTES) ?>)'
                                        title="View Details">
                                    <i class="bi bi-eye"></i>
                                </button>
                                <button class="btn btn-xs btn-outline-primary"
                                        onclick='openEditOfficial(<?= htmlspecialchars(json_encode($o), ENT_QUOTES) ?>)'
                                        title="Edit Personal Info">
                                    <i class="bi bi-pencil"></i>
                                </button>
                            </div>
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
    'position'    => $positionF,
    'status'      => $statusF,
    'date_from'   => $dateFrom,
    'date_to'     => $dateTo,
    'sort'        => $sort,
])) . '&';
$paginationLabel = 'official';
include __DIR__ . '/../includes/pagination.php';
?>

<!-- View Details Modal -->
<div class="modal fade" id="viewOfficialModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;overflow:hidden;">
            <div class="modal-header" style="background:linear-gradient(135deg,#d97706,#f59e0b);border:none;">
                <h5 class="modal-title text-white fw-bold"><i class="bi bi-person-badge-fill me-2"></i>Official Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="text-center mb-3" id="vo_photoWrap"></div>
                <div class="row g-3">
                    <div class="col-md-6"><small class="text-muted d-block">Full Name</small><strong id="vo_name"></strong></div>
                    <div class="col-md-6"><small class="text-muted d-block">Position</small><span id="vo_pos"></span></div>
                    <div class="col-md-6"><small class="text-muted d-block">Barangay</small><span id="vo_brgy"></span></div>
                    <div class="col-md-6"><small class="text-muted d-block">Status</small><span id="vo_status"></span></div>
                    <div class="col-md-6"><small class="text-muted d-block">Contact</small><span id="vo_contact"></span></div>
                    <div class="col-md-6"><small class="text-muted d-block">Email</small><span id="vo_email"></span></div>
                    <div class="col-md-6"><small class="text-muted d-block">Term Start</small><span id="vo_tstart"></span></div>
                    <div class="col-md-6"><small class="text-muted d-block">Term End</small><span id="vo_tend"></span></div>
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ══ EDIT OFFICIAL MODAL ══════════════════════════════════ -->
<div class="modal fade" id="editOfficialModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable" style="max-width:680px;">
        <form method="POST" enctype="multipart/form-data" id="editOfficialForm">
            <input type="hidden" name="action"        value="edit_official">
            <input type="hidden" name="csrf_token"    id="editOffCsrf"   value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES) ?>">
            <input type="hidden" name="official_id"   id="editOffId">
            <input type="hidden" name="barangay_id"   value="<?= $brgFilter ?>">
            <input type="hidden" name="photo_base64"  id="editOffPhotoB64">
            <!-- preserve current filter state for redirect back -->
            <input type="hidden" name="search_back"   value="<?= htmlspecialchars($search) ?>">
            <input type="hidden" name="position_back" value="<?= htmlspecialchars($positionF) ?>">
            <input type="hidden" name="status_back"   value="<?= htmlspecialchars($statusF) ?>">
            <input type="hidden" name="sort_back"     value="<?= htmlspecialchars($sort) ?>">
            <input type="hidden" name="page_back"     value="<?= $page ?>">

            <div class="modal-content" style="border-radius:16px;overflow:hidden;">

                <!-- Header -->
                <div style="background:linear-gradient(90deg,#1a4f7a,#2d7db3);padding:12px 18px;">
                    <div class="d-flex align-items-center gap-2">
                        <div style="width:34px;height:34px;background:rgba(255,255,255,0.15);border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <i class="bi bi-pencil-fill text-white" style="font-size:1rem;"></i>
                        </div>
                        <div>
                            <div style="font-size:0.68rem;color:rgba(255,255,255,0.7);text-transform:uppercase;letter-spacing:0.08em;">Super Admin</div>
                            <div style="font-size:0.95rem;font-weight:800;color:#fff;">Edit Official Info</div>
                            <div style="font-size:0.75rem;color:rgba(255,255,255,0.75);margin-top:1px;" id="editOffSubtitle">Update personal information only. Term dates are managed by Barangay Settings.</div>
                        </div>
                        <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal"></button>
                    </div>
                </div>

                <!-- Body -->
                <div class="modal-body p-4" style="background:#f8fafc;">
                    <div class="row g-4">

                        <!-- Left: Photo -->
                        <div class="col-lg-3 d-flex flex-column align-items-center">
                            <div id="editOffPhotoZone"
                                 onclick="document.getElementById('editOffPhotoInput').click()"
                                 style="width:100px;height:120px;border-radius:10px;background:#f0f4f8;border:2px dashed #b0c4d8;display:flex;flex-direction:column;align-items:center;justify-content:center;cursor:pointer;overflow:hidden;position:relative;transition:border-color .2s;">
                                <img id="editOffPhotoPreview" src="" alt="" style="display:none;width:100%;height:100%;object-fit:cover;position:absolute;top:0;left:0;">
                                <div id="editOffPhotoPlaceholder" style="text-align:center;color:#9ca3af;font-size:0.65rem;padding:8px;pointer-events:none;">
                                    <i class="bi bi-person-bounding-box" style="font-size:2rem;display:block;margin-bottom:4px;color:#b0c4d8;"></i>Click to change
                                </div>
                            </div>
                            <input type="file" id="editOffPhotoInput" name="photo" accept="image/*" style="display:none">
                            <button type="button" class="btn btn-xs btn-outline-danger mt-2" id="editOffClearPhotoBtn"
                                    onclick="editOffClearPhoto()" style="display:none;font-size:0.7rem;">
                                <i class="bi bi-trash me-1"></i>Remove
                            </button>
                            <div class="text-muted mt-1" style="font-size:0.65rem;text-align:center;">JPG/PNG · max 5MB</div>
                        </div>

                        <!-- Right: Fields -->
                        <div class="col-lg-9">

                            <!-- Read-only info card -->
                            <div style="background:#e0f2fe;border-radius:10px;padding:10px 14px;margin-bottom:16px;font-size:0.82rem;">
                                <div class="d-flex gap-3 flex-wrap">
                                    <div>
                                        <span class="text-muted" style="font-size:0.72rem;">Position</span><br>
                                        <strong id="editOffPositionLabel">—</strong>
                                    </div>
                                    <div>
                                        <span class="text-muted" style="font-size:0.72rem;">Term</span><br>
                                        <strong id="editOffTermLabel">—</strong>
                                    </div>
                                    <div>
                                        <span class="text-muted" style="font-size:0.72rem;">Status</span><br>
                                        <strong id="editOffStatusLabel">—</strong>
                                    </div>
                                </div>
                                <div class="text-muted mt-2" style="font-size:0.7rem;">
                                    <i class="bi bi-info-circle me-1"></i>Term dates and position are read-only here. Manage them via Barangay Settings or the Barangay Admin portal.
                                </div>
                            </div>

                            <!-- Editable fields -->
                            <div class="mb-3">
                                <label class="form-label fw-semibold small">Full Name <span class="text-danger">*</span></label>
                                <input type="text" name="full_name" id="editOffFullName" class="form-control" required
                                       placeholder="e.g. Dela Cruz, Juan Santos">
                                <div class="form-text">Format: Last Name, First Name Middle Name (or full name as preferred)</div>
                            </div>

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold small">Contact Number</label>
                                    <input type="text" name="contact" id="editOffContact" class="form-control"
                                           placeholder="e.g. 09171234567" inputmode="numeric"
                                           pattern="09[0-9]{9}" maxlength="11"
                                           oninput="editOffValidateContact(this)">
                                    <div id="editOffContactErr" class="text-danger mt-1" style="display:none;font-size:0.72rem;"></div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold small">Email Address</label>
                                    <input type="email" name="email" id="editOffEmail" class="form-control"
                                           placeholder="e.g. official@email.com">
                                </div>
                            </div>

                        </div>
                    </div>
                </div>

                <!-- Footer -->
                <div class="modal-footer" style="background:#fff;border-top:1px solid #e5e7eb;padding:10px 20px;gap:8px;">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg me-1"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-sm btn-primary fw-semibold">
                        <i class="bi bi-check-lg me-1"></i>Save Changes
                    </button>
                </div>

            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
var uploadUrl = '<?= UPLOAD_URL ?>';

/* ── View official ── */
function viewOfficial(o) {
    document.getElementById('vo_name').textContent    = o.full_name     || '—';
    document.getElementById('vo_pos').textContent     = o.position      || '—';
    document.getElementById('vo_brgy').textContent    = o.barangay_name || '—';
    document.getElementById('vo_status').textContent  = o.status        || '—';
    document.getElementById('vo_contact').textContent = o.contact       || '—';
    document.getElementById('vo_email').textContent   = o.email         || '—';
    document.getElementById('vo_tstart').textContent  = o.term_start    || '—';
    document.getElementById('vo_tend').textContent    = o.term_end      || '—';
    var wrap = document.getElementById('vo_photoWrap');
    if (o.photo) {
        wrap.innerHTML = '<img src="' + uploadUrl + o.photo + '" style="width:80px;height:80px;border-radius:50%;object-fit:cover;border:3px solid #e0f2fe;" alt="Photo">';
    } else {
        var initials = o.full_name ? o.full_name.substring(0,2).toUpperCase() : '??';
        wrap.innerHTML = '<div style="width:80px;height:80px;border-radius:50%;background:#e0f2fe;color:#0369a1;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1.5rem;margin:0 auto;">' + initials + '</div>';
    }
    new bootstrap.Modal(document.getElementById('viewOfficialModal')).show();
}

/* ── Open edit modal ── */
function openEditOfficial(o) {
    // Populate IDs & editable fields
    document.getElementById('editOffId').value        = o.id        || '';
    document.getElementById('editOffFullName').value  = o.full_name || '';
    document.getElementById('editOffContact').value   = o.contact   || '';
    document.getElementById('editOffEmail').value     = o.email     || '';

    // Read-only info labels
    document.getElementById('editOffPositionLabel').textContent = o.position   || '—';
    document.getElementById('editOffStatusLabel').textContent   = o.status     || '—';
    var termTxt = (o.term_start || '?') + ' → ' + (o.term_end || '?');
    document.getElementById('editOffTermLabel').textContent = termTxt;

    // Photo
    var preview   = document.getElementById('editOffPhotoPreview');
    var placeholder = document.getElementById('editOffPhotoPlaceholder');
    var clearBtn  = document.getElementById('editOffClearPhotoBtn');
    document.getElementById('editOffPhotoB64').value = '';
    if (o.photo) {
        preview.src           = uploadUrl + o.photo;
        preview.style.display = 'block';
        placeholder.style.display = 'none';
        clearBtn.style.display    = '';
    } else {
        preview.src           = '';
        preview.style.display = 'none';
        placeholder.style.display = '';
        clearBtn.style.display    = 'none';
    }

    // Clear contact error
    var err = document.getElementById('editOffContactErr');
    err.style.display = 'none'; err.textContent = '';

    new bootstrap.Modal(document.getElementById('editOfficialModal')).show();
}

/* ── Photo handling ── */
document.getElementById('editOffPhotoInput').addEventListener('change', function() {
    if (this.files[0]) editOffLoadPhoto(this.files[0]);
});

var editOffZone = document.getElementById('editOffPhotoZone');
editOffZone.addEventListener('dragover', function(e) { e.preventDefault(); this.style.borderColor = '#3a7fa8'; });
editOffZone.addEventListener('dragleave', function()  { this.style.borderColor = '#b0c4d8'; });
editOffZone.addEventListener('drop', function(e) {
    e.preventDefault(); this.style.borderColor = '#b0c4d8';
    var f = e.dataTransfer.files[0];
    if (f && f.type.startsWith('image/')) editOffLoadPhoto(f);
});

function editOffLoadPhoto(file) {
    if (file.size > 5 * 1024 * 1024) { alert('Photo must be under 5MB.'); return; }
    var reader = new FileReader();
    reader.onload = function(e) {
        var preview = document.getElementById('editOffPhotoPreview');
        preview.src = e.target.result;
        preview.style.display = 'block';
        document.getElementById('editOffPhotoPlaceholder').style.display = 'none';
        document.getElementById('editOffClearPhotoBtn').style.display    = '';
        document.getElementById('editOffPhotoB64').value = e.target.result;
    };
    reader.readAsDataURL(file);
}

function editOffClearPhoto() {
    document.getElementById('editOffPhotoPreview').src          = '';
    document.getElementById('editOffPhotoPreview').style.display = 'none';
    document.getElementById('editOffPhotoPlaceholder').style.display = '';
    document.getElementById('editOffClearPhotoBtn').style.display    = 'none';
    document.getElementById('editOffPhotoB64').value = '';
    document.getElementById('editOffPhotoInput').value = '';
}

/* ── Contact validation ── */
function editOffValidateContact(input) {
    var err = document.getElementById('editOffContactErr');
    var val = input.value.trim();
    if (val && !/^09\d{9}$/.test(val)) {
        err.textContent  = 'Must be 11 digits starting with 09 (e.g. 09171234567)';
        err.style.display = '';
        input.setCustomValidity('invalid');
    } else {
        err.style.display = 'none';
        input.setCustomValidity('');
    }
}
</script>
</body>
</html>
