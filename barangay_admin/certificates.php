<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/mailer.php';
requireRole('barangay_admin');

$db = Database::getInstance()->getConnection();
$u = currentUser();
$bid = (int)$u['barangay_id'];

// AJAX: issue certificate, return JSON with certId
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_issue'])) {
    verifyCsrfOrAbort();
    $resId   = (int)$_POST['resident_id'];
    $type    = $_POST['certificate_type'];
    $purpose = sanitize($_POST['purpose']);
    $designerData = $_POST['certificate_data'] ?? '';
    if (strlen($designerData) > 65535 || ($designerData !== '' && !is_array(json_decode($designerData, true)))) {
        jsonResponse(['success' => false, 'message' => 'Invalid certificate design data.'], 422);
    }
    $date    = date('Y-m-d');
    $stmt = $db->prepare("INSERT INTO certificates (barangay_id,resident_id,certificate_type,purpose,issued_by,date_issued,certificate_data) VALUES (?,?,?,?,?,?,?)");
    $stmt->execute([$bid, $resId, $type, $purpose, $u['id'], $date, $designerData ?: null]);
    $certId = $db->lastInsertId();
    $resName = $db->prepare("SELECT CONCAT(first_name,' ',last_name) FROM residents WHERE id=?");
    $resName->execute([$resId]);
    $rname = $resName->fetchColumn() ?: "resident #$resId";
    logActivity($u['id'], 'ISSUE_CERTIFICATE', 'Certificates', "Issued $type certificate for $rname");
    // Bell notification — issuing admin + barangay peers
    $certBellMsg = "📜 <strong>$type</strong> certificate issued for <strong>$rname</strong>";
    sendNotification($u['id'], $certBellMsg, 'success', 'certificate');
    $certPeers = $db->prepare("SELECT id FROM users WHERE role='barangay_admin' AND barangay_id=? AND status='active' AND id != ?");
    $certPeers->execute([$bid, $u['id']]);
    foreach ($certPeers->fetchAll(PDO::FETCH_ASSOC) as $cp) {
        sendNotification((int)$cp['id'], $certBellMsg, 'success', 'certificate');
    }
    // Return JSON immediately — do NOT send email here (SMTP blocks the response)
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'cert_id' => $certId]);
    // Send email after flushing response so the user isn't blocked
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    if ($u['email']) {
        $brgyName = $db->query("SELECT barangay_name FROM barangays WHERE id=$bid")->fetchColumn() ?: '';
        sendCertificateIssuedEmail($u['email'], $u['name'], $rname, $type, $purpose, $brgyName);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_update'])) {
    verifyCsrfOrAbort();
    $certId = (int)($_POST['cert_id'] ?? 0);
    $type = sanitize($_POST['certificate_type'] ?? '');
    $purpose = sanitize($_POST['purpose'] ?? '');
    $designerData = $_POST['certificate_data'] ?? '';
    $allowedTypes = ['Indigency', 'Business Permit', 'Residency', 'Clearance', 'Solo Parent', 'Live-in', 'Same Person', 'BARC Certificate', 'Proof of Low Income'];

    if ($certId <= 0 || !in_array($type, $allowedTypes, true) || $purpose === '' ||
        strlen($designerData) > 65535 || !is_array(json_decode($designerData, true))) {
        jsonResponse(['success' => false, 'message' => 'Choose a certificate type and enter its purpose.'], 422);
    }

    $certStmt = $db->prepare("SELECT id FROM certificates WHERE id=? AND barangay_id=?");
    $certStmt->execute([$certId, $bid]);
    if (!$certStmt->fetchColumn()) {
        jsonResponse(['success' => false, 'message' => 'Certificate not found.'], 404);
    }

    $db->prepare("UPDATE certificates SET certificate_type=?, purpose=?, certificate_data=? WHERE id=? AND barangay_id=?")
       ->execute([$type, $purpose, $designerData, $certId, $bid]);
    logActivity($u['id'], 'EDIT_CERTIFICATE', 'Certificates', "Updated certificate #$certId");
    jsonResponse(['success' => true]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrAbort();
    $action = $_POST['action'] ?? '';
    // NOTE: 'action=issue' (non-AJAX form-POST) has been removed.
    // All certificate issuance goes through the AJAX handler above (ajax_issue).
    // This prevents the double-insert/double-notification that occurred when both
    // handlers ran on a JS failure, retry, or non-JS form submission.
    if ($action === 'archive_cert') {
        $certId = (int)$_POST['cert_id'];
        $row = $db->prepare("SELECT * FROM certificates WHERE id=? AND barangay_id=?");
        $row->execute([$certId, $bid]);
        $data = $row->fetch();
        if ($data) {
            $db->prepare("INSERT INTO archives (table_name,record_id,data_json,archived_by) VALUES ('certificates',?,?,?)")
               ->execute([$certId, json_encode($data), $u['id']]);
            $db->prepare("DELETE FROM certificates WHERE id=? AND barangay_id=?")->execute([$certId, $bid]);
            $certType = $data['certificate_type'] ?? 'certificate';
            logActivity($u['id'], 'ARCHIVE_CERTIFICATE', 'Certificates', "Archived a $certType certificate record");
            flash('cert', 'Certificate archived.', 'info');
        }
        redirect(BASE_URL . "/barangay_admin/certificates.php");
    }
}

$search = sanitize($_GET['search'] ?? '');
$typeFilter = sanitize($_GET['type'] ?? '');
$perPage = 10;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

// Auto-remove certificates with no type (orphaned saves)
$db->prepare("DELETE FROM certificates WHERE barangay_id=? AND (certificate_type IS NULL OR certificate_type='')")->execute([$bid]);

$baseWhere = "FROM certificates c LEFT JOIN residents r ON c.resident_id=r.id LEFT JOIN users u ON c.issued_by=u.id WHERE c.barangay_id=$bid AND c.certificate_type IS NOT NULL AND c.certificate_type != ''";
if ($search) {
    $s = $db->quote('%' . $search . '%');
    $baseWhere .= " AND (
        r.first_name LIKE $s OR r.last_name LIKE $s OR r.middle_name LIKE $s OR
        CONCAT(r.first_name,' ',r.last_name) LIKE $s OR
        CONCAT(r.last_name,' ',r.first_name) LIKE $s OR
        CONCAT(r.first_name,' ',r.middle_name,' ',r.last_name) LIKE $s OR
        CONCAT(r.last_name,', ',r.first_name,' ',r.middle_name) LIKE $s OR
        CONCAT(r.last_name,', ',r.first_name) LIKE $s
    )";
}
if ($typeFilter) $baseWhere .= " AND c.certificate_type='$typeFilter'";

$totalCount = (int)$db->query("SELECT COUNT(*) $baseWhere")->fetchColumn();
$totalPages = max(1, (int)ceil($totalCount / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$query = "SELECT c.*, CONCAT(r.last_name,', ',r.first_name) as resident_name, CONCAT(u.first_name,' ',u.last_name) as issued_by_name $baseWhere ORDER BY c.created_at DESC LIMIT $perPage OFFSET $offset";
$certs = $db->query($query)->fetchAll();

$residents = $db->prepare("SELECT id, CONCAT(last_name,', ',first_name,' ',COALESCE(middle_name,'')) as full_name FROM residents WHERE barangay_id=? AND resident_status='Active' ORDER BY last_name");
$residents->execute([$bid]);
$residents = $residents->fetchAll();

$flash = getFlash('cert');
$certTypes = ['Indigency','Business Permit','Residency','Clearance','Solo Parent','Live-in','Same Person','BARC Certificate','Proof of Low Income'];

// Data for certificate designer
$brgyRow = $db->prepare("SELECT * FROM barangays WHERE id=?");
$brgyRow->execute([$bid]);
$brgyRow  = $brgyRow->fetch();
$brgyName = $brgyRow['barangay_name'] ?? '';

$offQ = $db->prepare("SELECT * FROM officials WHERE barangay_id=? AND status='active'");
$offQ->execute([$bid]);
$secretary = $punong = '';
foreach ($offQ->fetchAll() as $o) {
    if (stripos($o['position'],'secretary') !== false) $secretary = $o['full_name'];
    if (stripos($o['position'],'punong') !== false || stripos($o['position'],'captain') !== false) $punong = $o['full_name'];
}
$dayNum   = date('j');
$monthStr = date('F');
$yearStr  = date('Y');
$today    = date('F d, Y');
$BRGY     = htmlspecialchars(strtoupper($brgyName));
$SEC      = htmlspecialchars($secretary);
$PUNO     = htmlspecialchars($punong);
$L1       = BASE_URL.'/assets/img/logo 1.jpg';
$L2       = BASE_URL.'/assets/img/logo 2.jpg';

function wave_cert() {
    return '<div class="footer-wave"><svg viewBox="0 0 720 200" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">
<path d="M0,120 C80,80 180,150 300,110 C420,70 520,140 720,100 L720,200 L0,200 Z" fill="#90caf9" opacity="0.6"/>
<path d="M0,140 C100,100 220,160 360,125 C480,95 600,155 720,120 L720,200 L0,200 Z" fill="#3a7fa8"/>
<path d="M0,158 C60,130 160,170 280,148 C400,126 500,165 620,145 C680,135 710,150 720,148 L720,200 L0,200 Z" fill="#1a4f7a"/>
<path d="M0,172 C80,155 200,182 340,165 C460,150 580,178 720,162 L720,200 L0,200 Z" fill="#0d3b5e"/>
</svg></div>';
}
function hdr_cert($L1,$L2,$BRGY,$id) {
    return '<div class="header">
<img class="logo" src="'.$L1.'" alt="" onerror="this.style.visibility=\'hidden\'">
<div class="header-text">Republika ng Pilipinas<br>Lalawigan ng Nueva Ecija<br>Bayan ng TALAVERA<br>
<span class="barangay-name">BARANGAY <input class="ef brgy-hdr" id="'.$id.'" type="text" value="'.$BRGY.'"></span></div>
<img class="logo" src="'.$L2.'" alt="" onerror="this.style.visibility=\'hidden\'">
</div><div class="tanggapan">TANGGAPAN NG PUNONG BARANGAY</div>';
}
?>

<?php
$pageIcon     = 'bi-file-earmark-text-fill';
$pageTitle    = 'Certificates';
$pageSubtitle = 'Issue and manage barangay certificates and clearances.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favcon.png">
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favcon.png">
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=3.2">
    <style>
        .certificates-table-card .table-pagination {
            margin-top: 0 !important;
            padding: 8px 12px;
            background: #fff;
            border-top: 0;
        }

        html.dark-mode .certificates-table-card .table-pagination {
            background: #0f1f35;
        }

        #certDesignerOverlay.cert-view-mode .designer-main-row {
            display: block;
        }

        #certDesignerOverlay.cert-view-mode .toolbar-panel {
            display: none;
        }

        #certDesignerOverlay.cert-view-mode .cert-preview-panel {
            height: calc(100vh - var(--topbar-height) - 52px);
            border-left: 1px solid #ccc;
            border-radius: 0;
        }

        #certDesignerOverlay.cert-view-mode .ef {
            pointer-events: none;
        }

        #certDesignerOverlay.cert-view-mode #certTypeLabel,
        #certDesignerOverlay.cert-view-mode #certTypeDropdown,
        #certDesignerOverlay.cert-view-mode #btnSave,
        #certDesignerOverlay.cert-view-mode #saveSuccessBar {
            display: none !important;
        }

        #certDesignerOverlay #btnEditSavedCert {
            display: none;
        }

        #certDesignerOverlay.cert-view-mode #btnEditSavedCert {
            display: inline-block;
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/sidebar_barangay.php'; ?>
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<div class="main-content">
    

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show">
            <?= $flash['message'] ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Certificate Type Cards -->
    <div class="row g-2 mb-3">
        <?php
        $icons = ['Indigency'=>'bi-heart-fill','Business Permit'=>'bi-shop','Residency'=>'bi-house-fill','Clearance'=>'bi-shield-check','Solo Parent'=>'bi-person-heart','Live-in'=>'bi-people-fill','Same Person'=>'bi-person-badge','BARC Certificate'=>'bi-patch-check-fill','Proof of Low Income'=>'bi-cash-stack'];
        $colors = ['Indigency'=>'#1a4f7a','Business Permit'=>'#1a4f7a','Residency'=>'#e65100','Clearance'=>'#1a4f7a','Solo Parent'=>'#ad1457','Live-in'=>'#00796b','Same Person'=>'#5e35b1','BARC Certificate'=>'#2e7d32','Proof of Low Income'=>'#f57f17'];
        foreach ($certTypes as $ct):
            $cnt = $db->prepare("SELECT COUNT(*) FROM certificates WHERE barangay_id=? AND certificate_type=?");
            $cnt->execute([$bid, $ct]);
            $cnt = $cnt->fetchColumn();
        ?>
        <div class="col-6 col-md-1" style="min-width:110px;flex:1;">
            <div class="card text-center h-100" style="cursor:pointer;border-radius:10px;" onclick="filterType('<?= $ct ?>')">
                <div class="card-body py-2 px-1">
                    <i class="bi <?= $icons[$ct] ?>" style="font-size:1.2rem;color:<?= $colors[$ct] ?>;display:block;margin-bottom:3px;"></i>
                    <div style="font-size:0.72rem;font-weight:700;line-height:1.2;"><?= $ct ?></div>
                    <div style="font-size:0.68rem;color:#888;"><?= $cnt ?> issued</div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="card mb-3">
        <div class="card-body py-2">
            <div class="d-flex gap-2 flex-wrap align-items-center">
                <form method="GET" class="d-flex gap-2 flex-wrap align-items-center flex-fill" id="filterForm">
                    <div class="input-group input-group-sm ac-wrapper" style="width:240px;">
                        <span class="input-group-text bg-white border-end-0" style="border-color:#dee2e6;">
                            <i class="bi bi-search text-muted" style="font-size:0.8rem;"></i>
                        </span>
                        <input type="text" id="certSearchInput" name="search" class="form-control form-control-sm border-start-0 border-end-0 ps-0"
                               placeholder="Search resident..." autocomplete="off"
                               value="<?= htmlspecialchars($search) ?>" style="border-color:#dee2e6;">
                        <span class="input-group-text bg-white border-start-0 p-0" style="border-color:#dee2e6;">
                            <span id="certSearchClear" title="Clear search"
                                  style="width:28px;height:28px;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;color:#9ca3af;border-radius:4px;<?= $search ? '' : 'visibility:hidden;' ?>"
                                  onmouseover="this.style.color='#dc2626';this.style.background='#fee2e2';"
                                  onmouseout="this.style.color='#9ca3af';this.style.background='transparent';">
                                <i class="bi bi-x-lg" style="font-size:0.75rem;"></i>
                            </span>
                        </span>
                        <div class="ac-list" id="certAcList"></div>
                    </div>
                    <select name="type" id="typeFilter" class="form-select form-select-sm" style="width:180px;">
                        <option value="">All Types</option>
                        <?php foreach ($certTypes as $ct): ?>
                            <option value="<?= $ct ?>" <?= $typeFilter===$ct?'selected':'' ?>><?= $ct ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-sm btn-primary">Filter</button>
                    <a href="?" class="btn btn-sm btn-outline-secondary" title="Clear filters"><i class="bi bi-x-lg"></i></a>
                    <span class="text-muted small"><?= $totalCount ?> records</span>
                </form>
                <button type="button" class="btn btn-sm btn-success fw-semibold"
                        onclick="openCertDesigner()">
                    <i class="bi bi-file-earmark-plus-fill me-1"></i>Issue Certificate
                </button>
                <button type="button" class="btn btn-sm btn-outline-success fw-semibold" id="btnExportSelected"
                        onclick="exportCerts('selected')" title="Export selected rows to Excel" disabled>
                    <i class="bi bi-download me-1"></i>Export Selected <span id="selCount" class="badge bg-success ms-1" style="font-size:0.68rem;">0</span>
                </button>
                <button type="button" class="btn btn-sm btn-outline-primary fw-semibold"
                        onclick="exportCerts('all')" title="Export all certificates (current filter) to Excel">
                    <i class="bi bi-file-earmark-arrow-down me-1"></i>Export All
                </button>
            </div>
        </div>
    </div>

    <div class="certificates-table-card" style="background:#fff;border-radius:18px;box-shadow:0 2px 16px rgba(0,0,0,0.07);border:1px solid #dbeafe;overflow:hidden;">
        <div class="table-responsive">
            <table class="table mb-0" style="font-size:0.85rem;">
                <thead>
                    <tr style="background:#f4f7fa;border-bottom:2px solid #c7ddf2;">
                        <th class="ps-3 py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;width:40px;">
                            <input type="checkbox" id="chkAll" title="Select all" style="width:15px;height:15px;cursor:pointer;" onchange="toggleAllCerts(this)">
                        </th>
                        <th class="ps-2 py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">#</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">Resident</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">Certificate Type</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">Purpose</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">Date Issued</th>
                        <th class="py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">Issued By</th>
                        <th class="pe-4 py-3" style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($certs)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-5"><i class="bi bi-inbox" style="font-size:2.5rem;opacity:0.3;display:block;margin-bottom:10px;"></i>No certificates found.</td></tr>
                <?php else: ?>
                    <?php foreach ($certs as $i => $c): ?>
                    <tr style="border-bottom:1px solid #e8f4fb;transition:background 0.15s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                        <td class="ps-3 py-3" style="border:none;width:40px;">
                            <input type="checkbox" class="cert-chk" value="<?= $c['id'] ?>" style="width:15px;height:15px;cursor:pointer;">
                        </td>
                        <td class="ps-2 py-3" style="border:none;color:#3a7fa8;"><?= $offset + $i+1 ?></td>
                        <td class="py-3" style="border:none;font-weight:600;color:#1a2e1f;"><?= htmlspecialchars($c['resident_name']) ?></td>
                        <td class="py-3" style="border:none;"><span class="badge bg-primary"><?= $c['certificate_type'] ?></span></td>
                        <td class="py-3" style="border:none;font-size:0.8rem;color:#3a7fa8;max-width:200px;"><?= htmlspecialchars($c['purpose']) ?></td>
                        <td class="py-3" style="border:none;font-size:0.8rem;color:#2a5070;"><?= date('M d, Y', strtotime($c['date_issued'])) ?></td>
                        <td class="py-3" style="border:none;font-size:0.8rem;color:#2a5070;"><?= htmlspecialchars($c['issued_by_name'] ?? '') ?></td>
                        <td class="pe-4 py-3" style="border:none;">
                            <div class="d-flex gap-1">
                                <button type="button" onclick="openCertView(<?= $c['id'] ?>, this)" class="btn btn-xs btn-primary" title="View certificate">
                                    <i class="bi bi-eye-fill me-1"></i>View
                                </button>
                                <button type="button" onclick="openCertArchiveModal(<?= $c['id'] ?>, '<?= htmlspecialchars(addslashes($c['certificate_type'])) ?>', '<?= htmlspecialchars(addslashes($c['resident_name'])) ?>')"
                                        class="btn btn-xs btn-outline-warning" title="Archive">
                                    <i class="bi bi-archive"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
        $paginationBase = '?' . http_build_query(array_filter(['search'=>$search,'type'=>$typeFilter]));
        $paginationBase = ($paginationBase === '?') ? '?' : $paginationBase . '&';
        ?>
        <?php $paginationLabel = 'certificate'; include __DIR__ . '/../includes/pagination.php'; ?>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     FULL-SCREEN CERTIFICATE DESIGNER OVERLAY
     ═══════════════════════════════════════════════════════════ -->
<div id="certDesignerOverlay" style="display:none;position:fixed;top:var(--topbar-height);left:var(--sidebar-width);right:0;bottom:0;background:#f0f0f0;z-index:998;overflow-y:auto;">

  <!-- Overlay Top Bar (all controls merged here) -->
  <div style="position:sticky;top:0;z-index:999;background:#1a4f7a;color:#fff;padding:7px 16px;display:flex;align-items:center;gap:8px;box-shadow:0 2px 8px rgba(0,0,0,0.3);flex-wrap:wrap;">
    <i class="bi bi-file-earmark-text-fill" style="font-size:1rem;opacity:0.85;"></i>
    <span style="font-weight:700;font-size:0.88rem;" id="overlayTitle">BARANGAY CERTIFICATE — <?= $BRGY ?></span>
    <div style="flex:1;"></div>
    <!-- Certificate Type -->
    <label id="certTypeLabel" style="font-size:11px;font-weight:600;color:rgba(255,255,255,0.85);white-space:nowrap;">Type:</label>
    <select id="certTypeDropdown" onchange="showCertFromDropdown(this.value)"
            style="padding:3px 8px;border:1.5px solid rgba(255,255,255,0.4);border-radius:5px;background:rgba(255,255,255,0.12);color:#fff;font-size:11px;font-weight:600;cursor:pointer;font-family:Arial,sans-serif;min-width:170px;outline:none;">
      <option value="clearance"  style="color:#000;">Barangay Clearance</option>
      <option value="indigency"  style="color:#000;">Certificate of Indigency</option>
      <option value="residency"  style="color:#000;">Certificate of Residency</option>
      <option value="livein"     style="color:#000;">Certificate of Live-in</option>
      <option value="sameperson" style="color:#000;">Certificate of Same Person</option>
      <option value="business"   style="color:#000;">Business Permit</option>
      <option value="barc"       style="color:#000;">BARC Certificate</option>
      <option value="soloparent" style="color:#000;">Certificate for Solo Parent</option>
      <option value="lowincome"  style="color:#000;">Proof of Low Income</option>
    </select>
    <!-- Zoom controls -->
    <button onclick="zoomCert(-0.1)" title="Zoom Out"
            style="padding:4px 9px;background:rgba(255,255,255,0.15);color:#fff;border:1px solid rgba(255,255,255,0.35);border-radius:5px;cursor:pointer;font-size:13px;font-weight:700;white-space:nowrap;">
      <i class="bi bi-zoom-out"></i>
    </button>
    <button onclick="zoomCert(0.1)" title="Zoom In"
            style="padding:4px 9px;background:rgba(255,255,255,0.15);color:#fff;border:1px solid rgba(255,255,255,0.35);border-radius:5px;cursor:pointer;font-size:13px;font-weight:700;white-space:nowrap;">
      <i class="bi bi-zoom-in"></i>
    </button>
    <button onclick="toggleFullscreen()" id="btnFullscreen" title="Full Screen"
            style="padding:4px 9px;background:rgba(255,255,255,0.15);color:#fff;border:1px solid rgba(255,255,255,0.35);border-radius:5px;cursor:pointer;font-size:13px;white-space:nowrap;">
      <i class="bi bi-fullscreen" id="fsIcon"></i>
    </button>
    <!-- Save success flash -->
    <div id="saveSuccessBar" style="display:none;background:#dbeafe;border:1px solid #93c5fd;border-radius:5px;padding:3px 10px;font-size:11px;color:#1e3a5f;white-space:nowrap;">
      <i class="bi bi-check-circle-fill" style="color:#2563eb;"></i> Saved!
    </div>
    <button id="btnSave" onclick="saveAndPreview()" style="padding:4px 12px;background:#1a4f7a;color:#fff;border:none;border-radius:5px;cursor:pointer;font-size:11px;font-weight:700;white-space:nowrap;">
      <i class="bi bi-floppy-fill me-1"></i>Save Record
    </button>
    <button id="btnPrintCert" onclick="doPrint()" style="padding:4px 12px;background:rgba(255,255,255,0.15);color:#fff;border:1px solid rgba(255,255,255,0.35);border-radius:5px;cursor:pointer;font-size:11px;font-weight:700;white-space:nowrap;">
      <i class="bi bi-printer-fill me-1"></i>Print / PDF
    </button>
    <button id="btnEditSavedCert" type="button" onclick="openSavedCertificateEdit()" title="Edit certificate"
            style="padding:4px 10px;background:rgba(255,255,255,0.15);color:#fff;border:1px solid rgba(255,255,255,0.35);border-radius:5px;cursor:pointer;font-size:13px;">
      <i class="bi bi-pencil-square"></i>
    </button>
    <button onclick="closeCertDesigner()" style="padding:4px 12px;background:rgba(255,255,255,0.15);color:#fff;border:1px solid rgba(255,255,255,0.35);border-radius:5px;cursor:pointer;font-size:11px;white-space:nowrap;">
      <i class="bi bi-x-lg me-1"></i>Close
    </button>
  </div>

  <!-- STEP 1 placeholder (kept for JS compat) -->
  <div id="stepSelect" style="display:none;"></div>
  <div id="stepDesigner" style="display:none;padding:0;">

    <!-- ── Side-by-side: LEFT = form fields, RIGHT = certificate preview ── -->
    <div class="designer-main-row">

      <!-- LEFT PANEL: all toolbars (scrollable) -->
      <div class="toolbar-panel">
    <div class="toolbar toolbar-section active" id="tb-clearance">
      <h3>✏️ Barangay Clearance</h3>
      <div class="field-group"><label>Full Name</label><input id="cl-f-name" value=""></div>
      <div class="field-group"><label>Issued at Barangay</label><input id="cl-f-ibrgy" value="<?= $BRGY ?>"></div>
      <div class="field-group"><label>Day</label><input id="cl-f-day" value="<?= $dayNum ?>"></div>
      <div class="field-group"><label>Month</label><input id="cl-f-month" value="<?= $monthStr ?>"></div>
      <div class="field-group"><label>Year</label><input id="cl-f-year" value="<?= $yearStr ?>"></div>
      <div class="field-group"><label>Purpose</label><input id="cl-f-purpose" placeholder="e.g. Employment"></div>
      <div class="field-group"><label>Gender</label><input id="cl-f-gender" value=""></div>
      <div class="field-group"><label>Age</label><input id="cl-f-age" value=""></div>
      <div class="field-group"><label>Date of Birth</label><input id="cl-f-dob" value=""></div>
      <div class="field-group"><label>Place of Birth</label><input id="cl-f-pob" placeholder="e.g. Talavera, NE"></div>
      <div class="field-group"><label>Purok</label><input id="cl-f-purok" value=""></div>
      <div class="field-group"><label>Civil Status</label><input id="cl-f-civil" value=""></div>
      <div class="field-group"><label>TCT No.</label><input id="cl-f-tct" placeholder="e.g. 12345"></div>
      <div class="field-group"><label>Issued At</label><input id="cl-f-issued-at" placeholder="e.g. RD Nueva Ecija"></div>
      <div class="field-group"><label>Issued On</label><input id="cl-f-issued-on" value="<?= $today ?>"></div>
      <div class="field-group"><label>Barangay Secretary</label><input id="cl-f-sec" value="<?= $SEC ?>"></div>
      <div class="field-group"><label>Punong Barangay</label><input id="cl-f-puno" value="<?= $PUNO ?>"></div>
      <div class="toolbar-actions">
        <button class="btn btn-primary" onclick="applyFields('clearance')">Apply</button>
        <button class="btn btn-secondary" onclick="clearFields('clearance')">Clear</button>
      </div>
    </div>
    <div class="cert-section active" id="cert-clearance"><div class="certificate">
      <div class="corner tl"></div><div class="corner tr"></div><div class="corner bl"></div><div class="corner br"></div>
      <?= hdr_cert($L1,$L2,$BRGY,'cl-c-brgy') ?>
      <div class="title-section"><span class="dots-icon">⠿</span><div class="cert-title">BARANGAY CLEARANCE</div></div>
      <div class="body">
        <p><strong>TO WHOM IT MAY CONCERN:</strong></p>
        <p class="indent">This is to certify that <input class="ef ef-name" id="cl-c-name" type="text">, is a <u>bonafide</u> resident of this Barangay. He/She is personally known to me to be a <u>peaceful</u> and law-abiding citizen of this community with good moral character.</p>
        <p class="indent">He/She has not been involved any crimes or illegal activities and is not a member of any subversive elements. This clearance is valid only for ninety (90) days from the date of issuance.</p>
        <p class="indent">Issued at Barangay <input class="ef md" id="cl-c-ibrgy" type="text" value="<?= $BRGY ?>">, Talavera Nueva Ecija this <input class="ef sm" id="cl-c-day" type="text"> day of <input class="ef md" id="cl-c-month" type="text">, <input class="ef sm" id="cl-c-year" type="text" style="width:60px"> upon request of subject person for <input class="ef md" id="cl-c-purpose" type="text">, purposes.</p>
      </div>

      <!-- Main content row: info table LEFT, photo+thumbs+notice RIGHT -->
      <div class="cl-main-row">
        <!-- LEFT: personal info table -->
        <div class="cl-info-col">
          <div class="info-table"><table>
            <tr><td>GENDER</td><td class="colon">:</td><td><input class="cl-if" id="cl-c-gender"></td></tr>
            <tr><td>AGE</td><td class="colon">:</td><td><input class="cl-if" id="cl-c-age"></td></tr>
            <tr><td>DATE OF BIRTH</td><td class="colon">:</td><td><input class="cl-if" id="cl-c-dob"></td></tr>
            <tr><td>PLACE OF BIRTH</td><td class="colon">:</td><td><input class="cl-if" id="cl-c-pob"></td></tr>
            <tr><td>PUROK</td><td class="colon">:</td><td><input class="cl-if" id="cl-c-purok"></td></tr>
            <tr><td>CIVIL STATUS</td><td class="colon">:</td><td><input class="cl-if" id="cl-c-civil"></td></tr>
            <tr class="divider-row"><td>TCT NO.</td><td class="colon">:</td><td><input class="cl-if" id="cl-c-tct"></td></tr>
            <tr><td>ISSUED AT</td><td class="colon">:</td><td><input class="cl-if" id="cl-c-issued-at"></td></tr>
            <tr><td>ISSUED ON</td><td class="colon">:</td><td><input class="cl-if" id="cl-c-issued-on"></td></tr>
          </table></div>
          <!-- Applicant signature under info table -->
          <div style="margin-top:30px;">
            <div style="border-bottom:1px dashed #000;width:200px;margin-bottom:4px;">&nbsp;</div>
            <div style="font-size:11px;">Signature Over Printed Name</div>
          </div>
        </div>

        <!-- RIGHT: photo, thumbmarks, notice -->
        <div class="cl-right-col">
          <div class="cl-photo-box" onclick="document.getElementById('photo-upload').click()">
            <input type="file" id="photo-upload" accept="image/*" onchange="loadPhoto(event)">
            <img id="photo-preview" src="" alt="" style="display:none">
            <span id="photo-label" style="text-align:center;font-size:11px;color:#aaa;">2x2<br>Photo<br><span style="font-size:10px;color:#1a4f7a">Click to upload</span></span>
          </div>
          <div class="cl-thumbmarks">
            <div class="cl-thumb-col">
              <div class="cl-thumb-box"></div>
              <div class="cl-thumb-label">Left Thumb</div>
            </div>
            <div class="cl-thumb-col">
              <div class="cl-thumb-box"></div>
              <div class="cl-thumb-label">Right Thumb</div>
            </div>
          </div>
          <div class="cl-notice">
            <em>*Valid for 90 days from issuance. Not valid without Barangay Dry Seal.</em>
          </div>
        </div>
      </div>

      <!-- Signature row: Secretary LEFT, Punong Barangay RIGHT -->
      <div class="cl-sig-row">
        <div class="cl-sig-col">
          <div class="cl-sig-role">Prepared by:</div>
          <div class="cl-sig-space"></div>
          <div class="cl-sig-line"></div>
          <div><input class="ef ef-name" id="cl-c-sec"></div>
          <div class="cl-sig-title">Barangay Secretary</div>
        </div>
        <div class="cl-sig-col cl-sig-right">
          <div class="cl-sig-role">Noted and Approved by:</div>
          <div class="cl-sig-space"></div>
          <div class="cl-sig-line"></div>
          <div><input class="ef ef-name" id="cl-c-puno"></div>
          <div class="cl-sig-title">Punong Barangay</div>
        </div>
      </div>
      <?= wave_cert() ?>
    </div></div>

    <!-- ══ 2. INDIGENCY TOOLBAR ══ -->
    <div class="toolbar toolbar-section" id="tb-indigency">
      <h3>✏️ Certificate of Indigency</h3>
      <div class="field-group"><label>Full Name</label><input id="in-f-name" value=""></div>
      <div class="field-group"><label>Age</label><input id="in-f-age" value=""></div>
      <div class="field-group"><label>Purok #</label><input id="in-f-purok" value=""></div>
      <div class="field-group"><label>Barangay</label><input id="in-f-brgy" value="<?= $BRGY ?>"></div>
      <div class="field-group"><label>Purpose</label><input id="in-f-purpose" placeholder="e.g. Medical Assistance"></div>
      <div class="field-group"><label>Day</label><input id="in-f-day" value="<?= $dayNum ?>"></div>
      <div class="field-group"><label>Month</label><input id="in-f-month" value="<?= $monthStr ?>"></div>
      <div class="field-group"><label>Year</label><input id="in-f-year" value="<?= $yearStr ?>"></div>
      <div class="field-group"><label>Issued at Barangay</label><input id="in-f-ibrgy" value="<?= $BRGY ?>"></div>
      <div class="field-group"><label>Barangay Secretary</label><input id="in-f-sec" value="<?= $SEC ?>"></div>
      <div class="field-group"><label>Punong Barangay</label><input id="in-f-puno" value="<?= $PUNO ?>"></div>
      <div class="toolbar-actions">
        <button class="btn btn-primary" onclick="applyFields('indigency')">Apply</button>
        <button class="btn btn-secondary" onclick="clearFields('indigency')">Clear</button>
      </div>
    </div>
    <div class="cert-section" id="cert-indigency"><div class="certificate">
      <div class="corner tl"></div><div class="corner tr"></div><div class="corner bl"></div><div class="corner br"></div>
      <?= hdr_cert($L1,$L2,$BRGY,'in-c-brgy') ?>
      <div class="title-section"><span class="dots-icon">⠿</span><div class="cert-title" style="text-decoration:underline">BARANGAY CERTIFICATE OF INDIGENCY</div></div>
      <div class="body">
        <div class="to-whom">TO WHOM IT MAY CONCERN:</div>
        <p class="indent">This is to CERTIFY that <input class="ef ef-name" id="in-c-name">, <input class="ef sm" id="in-c-age"> of age, Filipino, and a resident of Purok <input class="ef sm" id="in-c-purok">, Barangay <input class="ef md" id="in-c-brgy" value="<?= $BRGY ?>">, Municipality of Talavera, Province of Nueva Ecija, is known to this office as a person belonging to an indigent family.</p>
        <p class="indent">Based on records and verification conducted by this barangay, the above-named individual has insufficient or no regular source of income and is therefore considered financially incapable of meeting basic needs.<br>This certification is issued upon the request of the above-named person for <input class="ef md" id="in-c-purpose">, and for whatever legal purpose it may serve.</p>
        <p class="indent">Issued this <input class="ef sm" id="in-c-day"> day of <input class="ef md" id="in-c-month">, <input class="ef sm" id="in-c-year" style="width:60px"> at Barangay <input class="ef md" id="in-c-ibrgy" value="<?= $BRGY ?>">, Talavera, Nueva Ecija.</p>
      </div>
      <div class="in-sig-area">
        <div class="in-sig-col">
          <div class="in-sig-role">Prepared by:</div>
          <div class="in-sig-line-wrap"><input class="ef ef-name" id="in-c-sec"></div>
          <div class="in-sig-title">Barangay Secretary</div>
        </div>
        <div class="in-sig-col in-sig-right">
          <div class="in-sig-role">Noted and Approved by:</div>
          <div class="in-sig-line-wrap"><input class="ef ef-name" id="in-c-puno"></div>
          <div class="in-sig-title">Punong Barangay</div>
        </div>
      </div>
      <?= wave_cert() ?>
    </div></div>

    <!-- ══ 3. RESIDENCY TOOLBAR ══ -->
    <div class="toolbar toolbar-section" id="tb-residency">
      <h3>✏️ Certificate of Residency</h3>
      <div class="field-group"><label>Full Name</label><input id="re-f-name" value=""></div>
      <div class="field-group"><label>Age</label><input id="re-f-age" value=""></div>
      <div class="field-group"><label>Purok #</label><input id="re-f-purok" value=""></div>
      <div class="field-group"><label>Barangay</label><input id="re-f-brgy" value="<?= $BRGY ?>"></div>
      <div class="field-group"><label>Day</label><input id="re-f-day" value="<?= $dayNum ?>"></div>
      <div class="field-group"><label>Month</label><input id="re-f-month" value="<?= $monthStr ?>"></div>
      <div class="field-group"><label>Year</label><input id="re-f-year" value="<?= $yearStr ?>"></div>
      <div class="field-group"><label>Issued at Barangay</label><input id="re-f-ibrgy" value="<?= $BRGY ?>"></div>
      <div class="field-group"><label>Civil Status</label><input id="re-f-civil" value=""></div>
      <div class="field-group"><label>Sex</label><input id="re-f-sex" value=""></div>
      <div class="field-group"><label>Date of Birth</label><input id="re-f-bday" value=""></div>
      <div class="field-group"><label>Place of Birth</label><input id="re-f-bplace" placeholder="e.g. Talavera, NE"></div>
      <div class="field-group"><label>Height</label><input id="re-f-height" placeholder="e.g. 5'6"></div>
      <div class="field-group"><label>Weight</label><input id="re-f-weight" placeholder="e.g. 60 kg"></div>
      <div class="field-group"><label>Father's Name</label><input id="re-f-father" placeholder="Full name"></div>
      <div class="field-group"><label>Mother's Name</label><input id="re-f-mother" placeholder="Full name"></div>
      <div class="field-group"><label>Purpose</label><input id="re-f-purpose" placeholder="e.g. Employment"></div>
      <div class="field-group"><label>Barangay Secretary</label><input id="re-f-sec" value="<?= $SEC ?>"></div>
      <div class="field-group"><label>Punong Barangay</label><input id="re-f-puno" value="<?= $PUNO ?>"></div>
      <div class="toolbar-actions">
        <button class="btn btn-primary" onclick="applyFields('residency')">Apply</button>
        <button class="btn btn-secondary" onclick="clearFields('residency')">Clear</button>
      </div>
    </div>
    <div class="cert-section" id="cert-residency"><div class="certificate">
      <div class="corner tl"></div><div class="corner tr"></div><div class="corner bl"></div><div class="corner br"></div>
      <?= hdr_cert($L1,$L2,$BRGY,'re-c-brgy') ?>
      <div class="title-section"><span class="dots-icon">⠿</span><div class="cert-title">CERTIFICATE OF BARANGAY RESIDENCY</div></div>
      <div class="body">
        <p>To whom it may concern:</p>
        <p class="indent">This is to certify that <input class="ef ef-name" id="re-c-name">, <input class="ef sm" id="re-c-age"> <strong>OF AGE</strong>, is a resident of Purok <input class="ef sm" id="re-c-purok">, Barangay <input class="ef md" id="re-c-brgy" value="<?= $BRGY ?>">, Talavera, Nueva Ecija. We personally known She/He to be a person of good moral character, law-abiding and peaceful community resident.</p>
        <p class="indent">This certification is issued upon request of the said named person for any legal purposes it may serve.</p>
        <p class="indent">Issued this <input class="ef sm" id="re-c-day"> day of <input class="ef md" id="re-c-month">, <input class="ef sm" id="re-c-year" style="width:55px">, here at Barangay <input class="ef md" id="re-c-ibrgy" value="<?= $BRGY ?>">, Talavera, Nueva Ecija.</p>
      </div>

      <div class="personal-title">PERSONAL DETAILS</div>

      <div class="re-details-table">
        <table>
          <colgroup>
            <col style="width:20%">
            <col style="width:10px">
            <col style="width:80%">
          </colgroup>
          <tr>
            <td class="re-label">Name</td>
            <td class="re-colon">:</td>
            <td><input class="ef re-val ef-name" id="re-c-det-name"></td>
          </tr>
          <tr>
            <td class="re-label">Address</td>
            <td class="re-colon">:</td>
            <td>Purok <input class="ef re-val-sm" id="re-c-det-purok">, Barangay <input class="ef re-val-md" id="re-c-det-brgy" value="<?= $BRGY ?>">, Talavera, Nueva Ecija</td>
          </tr>
          <tr>
            <td class="re-label">Civil Status</td>
            <td class="re-colon">:</td>
            <td class="re-pair-cell"><input class="ef re-val-pair" id="re-c-civil"><span class="re-pair-label">Sex:</span><input class="ef re-val-pair" id="re-c-sex"></td>
          </tr>
          <tr>
            <td class="re-label">Date of Birth</td>
            <td class="re-colon">:</td>
            <td class="re-pair-cell"><input class="ef re-val-pair" id="re-c-bday"><span class="re-pair-label">Birth Place:</span><input class="ef re-val-pair" id="re-c-bplace"></td>
          </tr>
          <tr>
            <td class="re-label">Height</td>
            <td class="re-colon">:</td>
            <td class="re-pair-cell"><input class="ef re-val-pair" id="re-c-height"><span class="re-pair-label">Weight:</span><input class="ef re-val-pair" id="re-c-weight"></td>
          </tr>
          <tr>
            <td class="re-label">Father</td>
            <td class="re-colon">:</td>
            <td class="re-pair-cell"><input class="ef re-val-pair" id="re-c-father"><span class="re-pair-label">Mother:</span><input class="ef re-val-pair" id="re-c-mother"></td>
          </tr>
          <tr>
            <td class="re-label">Purpose</td>
            <td class="re-colon">:</td>
            <td>For <input class="ef re-val-short" id="re-c-purpose"></td>
          </tr>
        </table>
      </div>

      <div class="re-sig-area">
        <div class="re-applicant-sig">
          <div class="re-sig-line"></div>
          <div class="re-sig-text">Signature Over Printed Name</div>
        </div>
        <div class="re-officials-sig">
          <div class="re-sig-pair">
            <div class="re-sig-col">
              <div class="re-sig-role-label">Attested by:</div>
              <div class="re-sig-line-wide"></div>
              <div><input class="ef lg" id="re-c-sec"></div>
              <div class="re-sig-title">Barangay Secretary</div>
            </div>
            <div class="re-sig-col re-sig-right">
              <div class="re-sig-role-label">Certified by:</div>
              <div class="re-sig-line-wide"></div>
              <div><input class="ef lg" id="re-c-puno"></div>
              <div class="re-sig-title">Punong Barangay</div>
            </div>
          </div>
        </div>
      </div>
      <?= wave_cert() ?>
    </div></div>

    <!-- ══ 4. LIVE-IN TOOLBAR ══ -->
    <div class="toolbar toolbar-section" id="tb-livein">
      <h3>✏️ Certificate of Live-in</h3>
      <div class="field-group"><label>Partner 1 Full Name</label><input id="li-f-name1" placeholder="e.g. Juan Dela Cruz"></div>
      <div class="field-group"><label>Partner 2 Full Name</label><input id="li-f-name2" placeholder="e.g. Maria Santos"></div>
      <div class="field-group"><label>Purok #</label><input id="li-f-purok" value=""></div>
      <div class="field-group"><label>Barangay</label><input id="li-f-brgy" value="<?= $BRGY ?>"></div>
      <div class="field-group"><label>Cohabiting Since (20__)</label><input id="li-f-since" placeholder="e.g. 20"></div>
      <div class="field-group"><label>Day</label><input id="li-f-day" value="<?= $dayNum ?>"></div>
      <div class="field-group"><label>Month</label><input id="li-f-month" value="<?= $monthStr ?>"></div>
      <div class="field-group"><label>Year</label><input id="li-f-year" value="<?= $yearStr ?>"></div>
      <div class="field-group"><label>Issued at Barangay</label><input id="li-f-ibrgy" value="<?= $BRGY ?>"></div>
      <div class="field-group"><label>Barangay Secretary</label><input id="li-f-sec" value="<?= $SEC ?>"></div>
      <div class="field-group"><label>Punong Barangay</label><input id="li-f-puno" value="<?= $PUNO ?>"></div>
      <div class="toolbar-actions">
        <button class="btn btn-primary" onclick="applyFields('livein')">Apply</button>
        <button class="btn btn-secondary" onclick="clearFields('livein')">Clear</button>
      </div>
    </div>
    <div class="cert-section" id="cert-livein"><div class="certificate">
      <div class="corner tl"></div><div class="corner tr"></div><div class="corner bl"></div><div class="corner br"></div>
      <?= hdr_cert($L1,$L2,$BRGY,'li-c-brgy') ?>
      <div class="title-section"><span class="dots-icon">⠿</span><div class="cert-title">BARANGAY CERTIFICATE OF LIVE-IN</div></div>
      <div class="body">
        <p><strong>TO WHOM IT MAY CONCERN:</strong></p>
        <p class="indent">This is to <strong>CERTIFY</strong> that <input class="ef ef-name" id="li-c-name1">, of legal age, Filipino, and <input class="ef ef-name" id="li-c-name2">, of legal age, Filipino, are <strong>currently living together as live-in partners</strong> at Purok <input class="ef sm" id="li-c-purok">, Barangay <input class="ef md" id="li-c-brgy" value="<?= $BRGY ?>">, Municipality of Talavera, Province of Nueva Ecija.</p>
        <p class="indent">Based on records and to the best of the barangay's knowledge, the above-named individuals have been <strong>cohabiting peacefully</strong> at the stated address since <strong>20<input class="ef sm" id="li-c-since" style="width:40px"></strong>.</p>
        <p class="indent">This certification is issued <strong>upon request of the above-named persons</strong> for <strong>whatever legal purpose it may serve</strong>.</p>
        <p class="indent">Issued this <input class="ef sm" id="li-c-day"> day of <input class="ef md" id="li-c-month">, <input class="ef sm" id="li-c-year" style="width:60px">, at Barangay <input class="ef md" id="li-c-ibrgy" value="<?= $BRGY ?>">, Municipality of Talavera, Province of Nueva Ecija, Philippines.</p>
      </div>
      <div class="sig-section"><div style="font-weight:bold;margin-bottom:28px">Prepared by:</div><div style="display:inline-block"><div class="sig-line"></div><div><input class="ef lg" id="li-c-sec"></div><div>Barangay Secretary</div></div></div>
      <div class="sig-section" style="margin-top:24px"><div style="font-weight:bold;margin-bottom:28px">Noted by:</div><div style="display:inline-block"><div class="sig-line"></div><div><input class="ef lg" id="li-c-puno"></div><div>Punong Barangay</div></div></div>
      <?= wave_cert() ?>
    </div></div>

    <!-- ══ 5. SAME PERSON TOOLBAR ══ -->
    <div class="toolbar toolbar-section" id="tb-sameperson">
      <h3>✏️ Certificate of Same Person</h3>
      <div class="field-group"><label>Full Name</label><input id="sp-f-name" value=""></div>
      <div class="field-group"><label>Purok #</label><input id="sp-f-purok" value=""></div>
      <div class="field-group"><label>Barangay</label><input id="sp-f-brgy" value="<?= $BRGY ?>"></div>
      <div class="field-group"><label>Name 1 (in documents)</label><input id="sp-f-name1" placeholder="e.g. Juan Dela Cruz"></div>
      <div class="field-group"><label>Name 2 (in documents)</label><input id="sp-f-name2" placeholder="e.g. Juan D. Cruz"></div>
      <div class="field-group"><label>Day (ika-)</label><input id="sp-f-day" value="<?= $dayNum ?>"></div>
      <div class="field-group"><label>Month</label><input id="sp-f-month" value="<?= $monthStr ?>"></div>
      <div class="field-group"><label>Year</label><input id="sp-f-year" value="<?= $yearStr ?>"></div>
      <div class="field-group"><label>Issued at Barangay</label><input id="sp-f-ibrgy" value="<?= $BRGY ?>"></div>
      <div class="field-group"><label>Barangay Secretary</label><input id="sp-f-sec" value="<?= $SEC ?>"></div>
      <div class="field-group"><label>Punong Barangay</label><input id="sp-f-puno" value="<?= $PUNO ?>"></div>
      <div class="toolbar-actions">
        <button class="btn btn-primary" onclick="applyFields('sameperson')">Apply</button>
        <button class="btn btn-secondary" onclick="clearFields('sameperson')">Clear</button>
      </div>
    </div>
    <div class="cert-section" id="cert-sameperson"><div class="certificate">
      <div class="corner tl"></div><div class="corner tr"></div><div class="corner bl"></div><div class="corner br"></div>
      <?= hdr_cert($L1,$L2,$BRGY,'sp-c-brgy') ?>
      <div style="padding:14px 0 4px"><div class="cert-title">BARANGAY CERTIFICATE OF SAME PERSON</div><div class="cert-subtitle">(Sertipikasyon ng Iisang Tao)</div></div>
      <div class="body">
        <p>Sa kinauukulan:</p>
        <p class="indent">Ito ay <strong>PINATUTUNAYAN</strong> na si <input class="ef ef-name" id="sp-c-name">, nasa wastong gulang, Pilipino, at kasalukuyang naninirahan sa <strong>Purok <input class="ef sm" id="sp-c-purok">, Barangay <input class="ef md" id="sp-c-brgy" value="<?= $BRGY ?>"></strong>, Talavera, Nueva Ecija ay <strong>iisa at parehong tao</strong> na tinutukoy sa mga sumusunod na pangalan:</p>
      </div>
      <ol class="name-list"><li><input class="ef xl" id="sp-c-name1"></li><li><input class="ef xl" id="sp-c-name2"></li></ol>
      <div class="body">
        <p class="indent">Batay sa talaan ng aming barangay at sa personal na pahayag ng nabanggit na indibidwal, ang pagkakaiba sa baybay o paggamit ng pangalan ay dulot lamang ng kamalian sa dokumento at hindi nangangahulugang magkaibang tao.</p>
        <p class="indent">Ang sertipikasyong ito ay ipinagkakaloob para sa anumang legal at opisyal na layunin.</p>
        <p>Iginawad ngayong ika-<input class="ef sm" id="sp-c-day"> ng <input class="ef md" id="sp-c-month">, <input class="ef sm" id="sp-c-year" style="width:60px">, sa Barangay <input class="ef lg" id="sp-c-ibrgy" value="<?= $BRGY ?>">, Talavera, Nueva Ecija.</p>
        <p>Pinatutunsayan ni:</p>
      </div>
      <div class="sig-section"><div style="display:inline-block"><div class="sig-line"></div><div><input class="ef lg" id="sp-c-sec"></div><div>Barangay Secretary</div></div></div>
      <div class="sig-center" style="margin-top:24px"><div style="margin-bottom:28px">Pinagtibay:</div><div style="display:inline-block;text-align:center"><div style="border-top:1.5px solid #000;width:200px;margin:0 auto 4px"></div><div><input class="ef lg" id="sp-c-puno"></div><div>Punong Barangay</div></div></div>
      <?= wave_cert() ?>
    </div></div>

    <!-- ══ 7. BUSINESS PERMIT TOOLBAR ══ -->
    <div class="toolbar toolbar-section" id="tb-business">
      <h3>✏️ Business Permit</h3>
      <div class="field-group"><label>Date</label><input id="bu-f-date" value="<?= $today ?>"></div>
      <div class="field-group"><label>Time</label><input id="bu-f-time" placeholder="e.g. 9:00 AM"></div>
      <div class="field-group"><label>Business Name / Trade Activity</label><input id="bu-f-business" placeholder="e.g. Sari-sari Store"></div>
      <div class="field-group"><label>Operator / Owner</label><input id="bu-f-owner" value=""></div>
      <div class="field-group"><label>Address Purok #</label><input id="bu-f-purok1" value=""></div>
      <div class="field-group"><label>Address Barangay</label><input id="bu-f-brgy1" value="<?= $BRGY ?>"></div>
      <div class="field-group"><label>Business Location Purok #</label><input id="bu-f-purok2" placeholder="e.g. 3"></div>
      <div class="field-group"><label>Business Location Barangay</label><input id="bu-f-brgy2" value="<?= $BRGY ?>"></div>
      <div class="field-group"><label>Day</label><input id="bu-f-day" value="<?= $dayNum ?>"></div>
      <div class="field-group"><label>Month</label><input id="bu-f-month" value="<?= $monthStr ?>"></div>
      <div class="field-group"><label>Year</label><input id="bu-f-year" value="<?= $yearStr ?>"></div>
      <div class="field-group"><label>Issued at Barangay</label><input id="bu-f-ibrgy" value="<?= $BRGY ?>"></div>
      <div class="field-group"><label>Barangay Secretary</label><input id="bu-f-sec" value="<?= $SEC ?>"></div>
      <div class="field-group"><label>Punong Barangay</label><input id="bu-f-puno" value="<?= $PUNO ?>"></div>
      <div class="field-group"><label>SB Member / Officer of the Day</label><input id="bu-f-sb" placeholder="Full name"></div>
      <div class="toolbar-actions">
        <button class="btn btn-primary" onclick="applyFields('business')">Apply</button>
        <button class="btn btn-secondary" onclick="clearFields('business')">Clear</button>
      </div>
    </div>
    <div class="cert-section" id="cert-business"><div class="certificate">
      <div class="corner tl"></div><div class="corner tr"></div><div class="corner bl"></div><div class="corner br"></div>
      <?= hdr_cert($L1,$L2,$BRGY,'bu-c-brgy') ?>
      <div class="title-section"><span class="dots-icon">⠿</span><div class="cert-title" style="font-size:20px;letter-spacing:2px">BARANGAY BUSINESS &nbsp; PERMIT</div></div>
      <div class="date-center">DATE – <input class="ef md" id="bu-c-date"> &nbsp;&nbsp; TIME – <input class="ef md" id="bu-c-time"></div>
      <div class="body">
        <p>To whom it may concern:</p>
        <p class="indent">This is to certify that the business or trade activity described below.</p>
        <div class="field-block"><input class="ef block-field" id="bu-c-business"><div class="field-label">Business Name or Trade Activity</div></div>
        <div class="field-block" style="margin-top:14px"><input class="ef block-field" id="bu-c-owner"><div class="field-label">Operator/Owner</div></div>
        <p style="margin-top:12px">Address: <strong>Purok <input class="ef sm" id="bu-c-purok1">, Barangay <input class="ef md" id="bu-c-brgy1" value="<?= $BRGY ?>">, Talavera, Nueva Ecija</strong><br>Business Location: <strong>Purok <input class="ef sm" id="bu-c-purok2">, Barangay <input class="ef md" id="bu-c-brgy2" value="<?= $BRGY ?>">, Talavera, Nueva Ecija</strong></p>
        <p class="indent">Being applied for new or corresponding Business Mayor's Permit is hereby granted subject to the provisions of existing laws, Barangay Ordinances, Resolutions and rules and regulations whenever applicable.</p>
        <p class="indent">In case of any violation of the foregoing rules and after due process this permit is hereby revoked or cancelled.</p>
        <p class="indent">Issued this <input class="ef sm" id="bu-c-day"> day of <input class="ef md" id="bu-c-month">, <input class="ef sm" id="bu-c-year" style="width:60px">, at Barangay <input class="ef md" id="bu-c-ibrgy" value="<?= $BRGY ?>">, Municipality of Talavera, Province of Nueva Ecija, Philippines.</p>
      </div>
      <div class="sig-row">
        <div class="sig-col"><div class="sig-label">Prepared by:</div><div class="sig-line"></div><div class="sig-name"><input class="ef lg" id="bu-c-sec"></div><div class="sig-name">Barangay Secretary</div></div>
        <div class="sig-col" style="align-items:flex-end"><div class="sig-label">Certified by:</div><div class="sig-line"></div><div class="sig-name"><input class="ef lg" id="bu-c-puno"></div><div class="sig-name">Punong Barangay</div></div>
      </div>
      <div style="text-align:center;margin-top:20px;font-size:13px"><div style="margin-bottom:26px"></div><div class="sig-line" style="margin:0 auto"></div><div><input class="ef lg" id="bu-c-sb"></div><div>SB Member / Officer of the Day</div></div>
      <?= wave_cert() ?>
    </div></div>

    <!-- ══ 8. BARC TOOLBAR ══ -->
    <div class="toolbar toolbar-section" id="tb-barc">
      <h3>✏️ BARC Certificate</h3>
      <div class="field-group"><label>Full Name</label><input id="ba-f-name" value=""></div>
      <div class="field-group"><label>Purok #</label><input id="ba-f-purok" value=""></div>
      <div class="field-group"><label>Barangay</label><input id="ba-f-brgy" value="<?= $BRGY ?>"></div>
      <div class="field-group"><label>Name (2nd paragraph)</label><input id="ba-f-name2" value=""></div>
      <div class="field-group"><label>Lot No.</label><input id="ba-f-lot" placeholder="e.g. 123"></div>
      <div class="field-group"><label>Plano Blg</label><input id="ba-f-plano" placeholder="e.g. 456"></div>
      <div class="field-group"><label>Land Area / Sukat</label><input id="ba-f-sukat" placeholder="e.g. 500 sq.m."></div>
      <div class="field-group"><label>Recipient Name</label><input id="ba-f-recipient" value=""></div>
      <div class="field-group"><label>Day (ika-)</label><input id="ba-f-day" value="<?= $dayNum ?>"></div>
      <div class="field-group"><label>Month</label><input id="ba-f-month" value="<?= $monthStr ?>"></div>
      <div class="field-group"><label>Year</label><input id="ba-f-year" value="<?= $yearStr ?>"></div>
      <div class="field-group"><label>Barangay Secretary</label><input id="ba-f-sec" value="<?= $SEC ?>"></div>
      <div class="field-group"><label>BARC Chairman</label><input id="ba-f-barc" placeholder="Full name"></div>
      <div class="field-group"><label>Punong Barangay</label><input id="ba-f-puno" value="<?= $PUNO ?>"></div>
      <div class="toolbar-actions">
        <button class="btn btn-primary" onclick="applyFields('barc')">Apply</button>
        <button class="btn btn-secondary" onclick="clearFields('barc')">Clear</button>
      </div>
    </div>
    <div class="cert-section" id="cert-barc"><div class="certificate">
      <div class="corner tl"></div><div class="corner tr"></div><div class="corner bl"></div><div class="corner br"></div>
      <?= hdr_cert($L1,$L2,$BRGY,'ba-c-brgy') ?>
      <div class="title-section"><span class="dots-icon">⠿</span><div class="cert-title" style="font-size:20px;color:#1a4f7a">PAGPAPATUNAY</div></div>
      <div class="body">
        <p>Sa kinauukulan;</p>
        <p class="indent">Pinatutunayan sa lahat na si <input class="ef ef-name" id="ba-c-name"> lehitimong residente ng <strong>Purok <input class="ef sm" id="ba-c-purok">, Barangay <input class="ef md" id="ba-c-brgy" value="<?= $BRGY ?>">, Talavera, Nueva Ecija</strong>.</p>
        <p class="indent">Pinatutunayan parin na ang nasabing si <input class="ef ef-name" id="ba-c-name2"> ay, may loteng sinasaka/tayuan ng bahay, <strong>Lot No.</strong> <input class="ef sm" id="ba-c-lot">, Plano Blg <input class="ef sm" id="ba-c-plano">, at may sukat na humigit kumulang sa <input class="ef lg" id="ba-c-sukat">, at aktuwal na sinasaka/tinitirhan at matatagpuan sa Barangay <?= $BRGY ?>, Talavera, Nueva Ecija.</p>
        <p class="indent">Ang pagpapatunay na ito ay ipinagkaloob kay <input class="ef ef-name" id="ba-c-recipient">, ngayong <strong>ika-<input class="ef sm" id="ba-c-day"></strong>, ng <input class="ef md" id="ba-c-month"> <strong><input class="ef sm" id="ba-c-year" style="width:60px"></strong>, para magamit sa Banko.</p>
      </div>
      <div class="sig-row">
        <div class="sig-col"><div class="sig-label">Inihanda ni:</div><div class="sig-line"></div><div class="sig-name"><input class="ef lg" id="ba-c-sec"></div><div class="sig-name">Barangay Secretary</div></div>
        <div class="sig-col" style="align-items:flex-end"><div class="sig-label">Pinatutunayan ni:</div><div class="sig-line"></div><div class="sig-name"><input class="ef lg" id="ba-c-barc"></div><div class="sig-name">BARC Chairman</div></div>
      </div>
      <div class="sig-center" style="margin-top:24px"><div style="margin-bottom:28px;font-style:italic">Pinatotohanan ni:</div><div style="display:inline-block;text-align:center"><div style="border-top:1.5px solid #000;width:200px;margin:0 auto 4px"></div><div><input class="ef lg" id="ba-c-puno"></div><div>Punong Barangay</div></div></div>
      <?= wave_cert() ?>
    </div></div>

    <!-- ══ 9. SOLO PARENT TOOLBAR ══ -->
    <div class="toolbar toolbar-section" id="tb-soloparent">
      <h3>✏️ Certificate for Solo Parent</h3>
      <div class="field-group"><label>Full Name</label><input id="so-f-name" value=""></div>
      <div class="field-group"><label>Age</label><input id="so-f-age" value=""></div>
      <div class="field-group"><label>Purok #</label><input id="so-f-purok" value=""></div>
      <div class="field-group"><label>Solo Parent Name</label><input id="so-f-solo" value=""></div>
      <div class="field-group"><label>Child 1 Name</label><input id="so-f-child1" placeholder="e.g. Jose Santos"></div>
      <div class="field-group"><label>Child 2 Name</label><input id="so-f-child2" placeholder="e.g. Ana Santos"></div>
      <div class="field-group"><label>Child 3 Name</label><input id="so-f-child3" placeholder="(optional)"></div>
      <div class="field-group"><label>Day</label><input id="so-f-day" value="<?= $dayNum ?>"></div>
      <div class="field-group"><label>Month</label><input id="so-f-month" value="<?= $monthStr ?>"></div>
      <div class="field-group"><label>Year (202_)</label><input id="so-f-year" value="<?= substr($yearStr,-1) ?>"></div>
      <div class="field-group"><label>Issued at Barangay</label><input id="so-f-ibrgy" value="<?= $BRGY ?>"></div>
      <div class="field-group"><label>Barangay Secretary</label><input id="so-f-sec" value="<?= $SEC ?>"></div>
      <div class="field-group"><label>Punong Barangay</label><input id="so-f-puno" value="<?= $PUNO ?>"></div>
      <div class="toolbar-actions">
        <button class="btn btn-primary" onclick="applyFields('soloparent')">Apply</button>
        <button class="btn btn-secondary" onclick="clearFields('soloparent')">Clear</button>
      </div>
    </div>
    <div class="cert-section" id="cert-soloparent"><div class="certificate">
      <div class="corner tl"></div><div class="corner tr"></div><div class="corner bl"></div><div class="corner br"></div>
      <?= hdr_cert($L1,$L2,$BRGY,'so-c-brgy') ?>
      <div class="title-section"><span class="dots-icon">⠿</span><div class="cert-title" style="font-family:'Georgia',serif;font-size:24px;font-style:italic;color:#1a4f7a">Certificate for Solo Parent</div></div>
      <div class="body">
        <p>To whom it may concern;</p>
        <p class="indent">This is to certify that <input class="ef ef-name" id="so-c-name">, <input class="ef sm" id="so-c-age"> years old and with postal address at Purok <input class="ef sm" id="so-c-purok">, Barangay <?= $BRGY ?>, Talavera, Nueva Ecija.</p>
        <p class="indent">Further Certified that, <input class="ef ef-name" id="so-c-solo"> is a Solo Parent of, Name of Children: <input class="ef lg" id="so-c-child1">, <input class="ef lg" id="so-c-child2">, <input class="ef xl" id="so-c-child3">, with the same address above.</p>
        <p class="indent">This certificate is being issued to the above named person as a requirement for Solo Parent Identification <strong>Card (ID)</strong> at <strong>MSWD</strong> and for whatever Legal Intent and Purposes.</p>
        <p class="indent">Issued this <input class="ef sm" id="so-c-day"> day of <input class="ef md" id="so-c-month">, 202<input class="ef sm" id="so-c-year" style="width:30px"> at Barangay <input class="ef md" id="so-c-ibrgy" value="<?= $BRGY ?>">, Talavera, Nueva Ecija.</p>
      </div>
      <div class="sig-printed"><span class="sig-line"></span><div>Signature Over Printed Name</div></div>
      <div class="sig-row">
        <div class="sig-col"><div style="margin-bottom:26px">Prepared by;</div><div class="sig-line"></div><div class="sig-name"><input class="ef lg" id="so-c-sec"></div><div class="sig-name">Barangay Secretary</div></div>
        <div class="sig-col" style="align-items:flex-end"><div style="margin-bottom:26px">Certified by;</div><div class="sig-line"></div><div class="sig-name"><input class="ef lg" id="so-c-puno"></div><div class="sig-name">Punong Barangay</div></div>
      </div>
      <?= wave_cert() ?>
    </div></div>

    <!-- ══ 10. LOW INCOME TOOLBAR ══ -->
    <div class="toolbar toolbar-section" id="tb-lowincome">
      <h3>✏️ Proof of Low Income</h3>
      <div class="field-group"><label>Full Name</label><input id="lo-f-name" value=""></div>
      <div class="field-group"><label>Age</label><input id="lo-f-age" value=""></div>
      <div class="field-group"><label>Purok #</label><input id="lo-f-purok" value=""></div>
      <div class="field-group"><label>Date of Birth</label><input id="lo-f-bday" value=""></div>
      <div class="field-group"><label>Occupation / Work</label><input id="lo-f-work" placeholder="e.g. online seller"></div>
      <div class="field-group"><label>Monthly Income (Php)</label><input id="lo-f-income" placeholder="e.g. 5,000"></div>
      <div class="field-group"><label>Day (ika-)</label><input id="lo-f-day" value="<?= $dayNum ?>"></div>
      <div class="field-group"><label>Month</label><input id="lo-f-month" value="<?= $monthStr ?>"></div>
      <div class="field-group"><label>Year</label><input id="lo-f-year" value="<?= $yearStr ?>"></div>
      <div class="field-group"><label>Issued at Barangay</label><input id="lo-f-ibrgy" value="<?= $BRGY ?>"></div>
      <div class="field-group"><label>Barangay Secretary (Kalihim)</label><input id="lo-f-sec" value="<?= $SEC ?>"></div>
      <div class="field-group"><label>Punong Barangay</label><input id="lo-f-puno" value="<?= $PUNO ?>"></div>
      <div class="toolbar-actions">
        <button class="btn btn-primary" onclick="applyFields('lowincome')">Apply</button>
        <button class="btn btn-secondary" onclick="clearFields('lowincome')">Clear</button>
      </div>
    </div>
    <div class="cert-section" id="cert-lowincome"><div class="certificate">
      <div class="corner tl"></div><div class="corner tr"></div><div class="corner bl"></div><div class="corner br"></div>
      <?= hdr_cert($L1,$L2,$BRGY,'lo-c-brgy') ?>
      <div class="title-section"><span class="dots-icon">⠿</span><div class="cert-title" style="font-size:20px">PROOF OF LOW INCOME</div></div>
      <div class="body">
        <p>Sa Kinauukulan;</p>
        <p class="indent">Ito ay pagpapatunay na si <input class="ef ef-name" id="lo-c-name">, <input class="ef sm" id="lo-c-age"> taong gulang, Residente sa Purok <input class="ef sm" id="lo-c-purok">, ng Barangay <?= $BRGY ?>, Talavera, Nueva Ecija, ipinanganak noong <input class="ef lg" id="lo-c-bday">, ay kasalukuyang nagtatrabaho bilang isang <input class="ef lg" id="lo-c-work"> at siya ay kumikita ng hindi tataas sa <strong>Php</strong> <input class="ef md" id="lo-c-income"> sa loob ng isang buwan.</p>
        <p class="indent">Ito ay iginawad ngayon ika-<input class="ef sm" id="lo-c-day"> ng <input class="ef md" id="lo-c-month">, <input class="ef sm" id="lo-c-year" style="width:60px"> sa Tanggapan ng Barangay <input class="ef lg" id="lo-c-ibrgy" value="<?= $BRGY ?>">, Talavera, Nueva Ecija.</p>
      </div>
      <div class="sig-section"><div style="font-style:italic;margin-bottom:28px">Inihanda ni,</div><div style="display:inline-block"><div class="sig-line"></div><div><input class="ef lg" id="lo-c-sec"></div><div>Barangay Kalihim</div></div></div>
      <div class="sig-right"><div class="sig-label-r">Pinatotohanan ni,</div><div class="sig-line-r"></div><br><div><input class="ef lg" id="lo-c-puno"></div><div>Punong Barangay</div></div>
      <?= wave_cert() ?>
    </div></div>

    </div><!-- end toolbar-panel (left) -->

      <!-- RIGHT PANEL: certificate preview -->
      <div class="cert-preview-panel" id="certPreviewPanel">
        <!-- cert-sections moved here by JS on init -->
      </div>

    </div><!-- end designer-main-row -->

  </div><!-- end stepDesigner -->
</div><!-- end certDesignerOverlay -->

<!-- ═══════════════════════════════════════════════════
     ISSUE CERTIFICATE MODAL (on main page)
     ═══════════════════════════════════════════════════ -->
<div class="modal fade" id="issueCertModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered" style="max-width:520px;">
    <div class="modal-content" style="border-radius:14px;overflow:visible;border:none;box-shadow:0 8px 40px rgba(0,0,0,0.15);">

      <!-- Header (needs its own border-radius since parent is now visible) -->
      <div style="background:linear-gradient(135deg,#1a4f7a,#3a7fa8);padding:20px 24px;color:#fff;border-radius:14px 14px 0 0;">
        <div class="d-flex align-items-center justify-content-between">
          <div>
            <div style="font-size:1rem;font-weight:700;"><i class="bi bi-file-earmark-plus me-2"></i>Issue New Certificate</div>
            <div style="font-size:0.78rem;opacity:0.8;margin-top:2px;">Select a resident, type, and purpose</div>
          </div>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
      </div>

      <!-- Body -->
      <div style="padding:22px 24px 8px;overflow:visible;">

        <!-- Resident search (simple absolute dropdown below input) -->
        <div class="mb-3">
          <label class="form-label fw-semibold" style="font-size:0.83rem;color:#3a7fa8;">Resident <span class="text-danger">*</span></label>
          <div style="position:relative;">
            <input type="text" id="residentSearch" class="form-control form-control-sm" placeholder="Type name to search..."
                   autocomplete="off" oninput="filterResidents(this.value)" onfocus="showResidentList()">
            <input type="hidden" id="selResident">
            <div id="residentDropdown" style="display:none;position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid #d0dce8;border-radius:8px;max-height:200px;overflow-y:auto;z-index:1070;box-shadow:0 4px 16px rgba(0,0,0,0.12);margin-top:2px;">
              <?php foreach ($residents as $r): ?>
              <div class="res-option" data-id="<?= $r['id'] ?>" data-name="<?= htmlspecialchars($r['full_name']) ?>"
                   onclick="selectResident(<?= $r['id'] ?>, <?= htmlspecialchars(json_encode($r['full_name'])) ?>)"
                   style="padding:8px 14px;cursor:pointer;font-size:0.83rem;border-bottom:1px solid #f1f5f9;"
                   onmouseover="this.style.background='#f0f7ff'" onmouseout="this.style.background=''">
                <?= htmlspecialchars($r['full_name']) ?>
              </div>
              <?php endforeach; ?>
              <div id="noResFound" style="display:none;padding:10px 14px;font-size:0.83rem;color:#94a3b8;text-align:center;">No residents found</div>
            </div>
          </div>
          <div id="selectedResidentBadge" style="display:none;margin-top:5px;padding:4px 10px;background:#dbeafe;border-radius:6px;font-size:0.78rem;color:#1a4f7a;font-weight:600;">
            <i class="bi bi-person-check-fill me-1"></i><span id="selectedResidentName"></span>
            <span onclick="clearResidentSelection()" style="cursor:pointer;margin-left:8px;color:#dc2626;font-size:0.73rem;">✕ Clear</span>
          </div>
        </div>

        <!-- Certificate Type -->
        <div class="mb-3">
          <label class="form-label fw-semibold" style="font-size:0.83rem;color:#3a7fa8;">Certificate Type <span class="text-danger">*</span></label>
          <select id="selCertType" class="form-select form-select-sm">
            <option value="clearance">Barangay Clearance</option>
            <option value="indigency">Certificate of Indigency</option>
            <option value="residency">Certificate of Residency</option>
            <option value="livein">Certificate of Live-in</option>
            <option value="sameperson">Certificate of Same Person</option>
            <option value="business">Business Permit</option>
            <option value="barc">BARC Certificate</option>
            <option value="soloparent">Certificate for Solo Parent</option>
            <option value="lowincome">Proof of Low Income</option>
          </select>
        </div>

        <!-- Purpose — custom cascading dropdown -->
        <div class="mb-3">
          <label class="form-label fw-semibold" style="font-size:0.83rem;color:#3a7fa8;">Purpose <span class="text-danger">*</span></label>

          <!-- Trigger button -->
          <div style="position:relative;">
            <button type="button" id="purposeTrigger" onclick="togglePurposeMenu()"
                    style="width:100%;display:flex;align-items:center;justify-content:space-between;padding:5px 10px;background:#fff;border:1px solid #ced4da;border-radius:6px;font-size:0.83rem;color:#495057;cursor:pointer;text-align:left;">
              <span id="purposeTriggerLabel" style="color:#aaa;">— Select purpose —</span>
              <i class="bi bi-chevron-down" id="purposeChevron" style="font-size:0.7rem;color:#6c757d;transition:transform 0.2s;"></i>
            </button>

            <!-- Main dropdown panel -->
            <div id="purposeMenu" style="display:none;position:absolute;top:calc(100% + 4px);left:0;width:100%;background:#fff;border:1px solid #d0dce8;border-radius:8px;box-shadow:0 4px 18px rgba(0,0,0,0.12);z-index:1070;max-height:240px;overflow-y:auto;overflow-x:hidden;">

              <?php
              $purposeGroups = [
                'Employment'       => ['Employment','Job Application','Government Employment','OFW / Abroad'],
                'Education'        => ['School / Enrollment','Scholarship Application','College Admission','Training / Seminar'],
                'Business'         => ['Business Permit Application','Business Registration','Business Renewal'],
                'Legal / Gov\'t'   => ['Police Clearance','NBI Clearance','Passport Application','COMELEC / Voter Reg.','SSS / PhilHealth / Pag-IBIG','Court Requirement'],
                'Financial'        => ['Bank Loan Application','Senior Citizen Benefit','4Ps / DSWD Assistance'],
                'Housing'          => ['Building Permit Application','Land Title / Property Transfer'],
              ];
              foreach ($purposeGroups as $group => $items):
              ?>
              <div class="pur-group" style="position:relative;">
                <!-- Category row -->
                <div class="pur-cat" onclick="togglePurposeSub(this)"
                     style="display:flex;align-items:center;justify-content:space-between;padding:8px 14px;font-size:0.82rem;font-weight:600;color:#1a4f7a;cursor:pointer;border-bottom:1px solid #f0f4f8;user-select:none;"
                     onmouseover="this.style.background='#f0f7ff'" onmouseout="this.style.background=''">
                  <span><?= $group ?></span>
                  <i class="bi bi-chevron-right" style="font-size:0.68rem;color:#6c757d;transition:transform 0.2s;"></i>
                </div>
                <!-- Sub-items (hidden by default) -->
                <div class="pur-sub" style="display:none;background:#fafcff;border-bottom:1px solid #e8f0f8;">
                  <?php foreach ($items as $item): ?>
                  <div onclick="selectPurpose('<?= addslashes($item) ?>')"
                       style="padding:7px 14px 7px 26px;font-size:0.81rem;color:#2d3748;cursor:pointer;border-bottom:1px solid #f4f7fb;"
                       onmouseover="this.style.background='#e8f4ff'" onmouseout="this.style.background=''">
                    <?= htmlspecialchars($item) ?>
                  </div>
                  <?php endforeach; ?>
                </div>
              </div>
              <?php endforeach; ?>

              <!-- Other -->
              <div style="padding:8px 14px;font-size:0.82rem;font-weight:600;color:#1a4f7a;cursor:pointer;border-bottom:1px solid #f0f4f8;"
                   onclick="selectPurpose('__other__')"
                   onmouseover="this.style.background='#f0f7ff'" onmouseout="this.style.background=''">
                Other…
              </div>
            </div>
          </div>

          <!-- Custom input shown when "Other" selected -->
          <input type="text" id="selPurpose" class="form-control form-control-sm mt-2" placeholder="Type purpose..."
                 style="display:none;" oninput="syncPurposeFromInput()">
          <input type="hidden" id="selPurposeFinal">
        </div>

        <!-- Resident info preview -->
        <div id="resInfoBox" style="display:none;background:#f0f7ff;border:1px solid #c8dff7;border-radius:8px;padding:10px 14px;margin-bottom:4px;">
          <div style="font-size:0.75rem;font-weight:700;color:#1a4f7a;margin-bottom:5px;"><i class="bi bi-person-fill me-1"></i>Resident Info</div>
          <div id="resInfoContent" style="font-size:0.8rem;"></div>
        </div>

      </div>

      <!-- Footer -->
      <div style="padding:12px 24px 20px;display:flex;gap:10px;border-radius:0 0 14px 14px;background:#fff;">
        <button type="button" class="btn btn-outline-secondary flex-fill" data-bs-dismiss="modal">Cancel</button>
        <button type="button" onclick="proceedToDesigner()" class="btn fw-semibold flex-fill"
                style="background:#1a4f7a;color:#fff;border:none;">
          <i class="bi bi-arrow-right me-1"></i>Continue to Certificate
        </button>
      </div>

    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<style>
.btn-xs { padding: 2px 8px; font-size: 0.75rem; }

/* ── Certificate Designer Styles (scoped inside overlay) ── */
#certDesignerOverlay *,#certDesignerOverlay *::before,#certDesignerOverlay *::after{box-sizing:border-box;-webkit-print-color-adjust:exact;print-color-adjust:exact}

/* ── Side-by-side layout ── */
#certDesignerOverlay #stepDesigner{
    display:flex;
    flex-direction:column;
    min-height:0;
}
/* Top bar + selector panel stay full width */
#certDesignerOverlay #certTypeDropdown:focus{border-color:rgba(255,255,255,0.8);}

/* ── Main two-column area ── */
#certDesignerOverlay .designer-main-row{
    display:flex;
    gap:0;
    flex:1;
    min-height:0;
    margin-top:0;
    align-items:flex-start;
}
/* LEFT: form toolbar panel (fixed width, scrollable) */
#certDesignerOverlay .toolbar-panel{
    width:280px;
    min-width:260px;
    flex-shrink:0;
    height:calc(100vh - var(--topbar-height) - 52px);
    overflow-y:auto;
    background:#fff;
    border:1px solid #ccc;
    border-radius:8px 0 0 8px;
    padding:14px 16px;
    font-family:Arial,sans-serif;
    font-size:13px;
    box-shadow:2px 0 6px rgba(0,0,0,.08);
}
#certDesignerOverlay .toolbar{
    background:transparent;
    border:none;
    border-radius:0;
    padding:0;
    width:100%;
    max-width:100%;
    margin:0;
    display:grid;
    grid-template-columns:1fr;
    gap:10px;
    box-shadow:none;
}
#certDesignerOverlay .toolbar h3{grid-column:1/-1;font-size:13px;color:#0d3b5e;border-bottom:1px solid #ccc;padding-bottom:5px;margin-bottom:2px;}
/* RIGHT: certificate preview (takes remaining space, scales to fit) */
#certDesignerOverlay .cert-preview-panel{
    flex:1;
    min-width:0;
    height:calc(100vh - var(--topbar-height) - 52px);
    overflow-y:auto;
    overflow-x:hidden;
    background:#e8e8e8;
    border:1px solid #ccc;
    border-left:none;
    border-radius:0 8px 8px 0;
    display:block;
    padding:16px;
}
#certDesignerOverlay .cert-preview-panel .cert-section{display:none;}
#certDesignerOverlay .cert-preview-panel .cert-section.active{display:block;overflow:visible;}

#certDesignerOverlay .field-group{display:flex;flex-direction:column;gap:3px;}
#certDesignerOverlay .field-group label{font-weight:bold;color:#444;font-size:11.5px;}
#certDesignerOverlay .field-group input{border:1px solid #bbb;border-radius:4px;padding:5px 8px;font-size:12px;font-family:'Times New Roman',serif;outline:none;width:100%;}
#certDesignerOverlay .field-group input:focus{border-color:#1a4f7a;}
#certDesignerOverlay .toolbar-actions{grid-column:1/-1;display:flex;gap:8px;margin-top:6px;}
#certDesignerOverlay .btn{padding:7px 14px;border:none;border-radius:5px;cursor:pointer;font-size:12px;font-weight:bold;}
#certDesignerOverlay .btn-primary{background:#1a4f7a;color:#fff;}
#certDesignerOverlay .btn-primary:hover{background:#0d3b5e;}
#certDesignerOverlay .btn-secondary{background:#eee;color:#333;}
#certDesignerOverlay .btn-secondary:hover{background:#ddd;}
#certDesignerOverlay .certificate{background:#fff;width:210mm;max-width:100%;min-height:297mm;padding:10mm 15mm 0 15mm;border:2px solid #1a4f7a;position:relative;overflow:hidden;display:flex;flex-direction:column;margin:0 auto;font-family:'Times New Roman',Times,serif;transform-origin:top center;}
#certDesignerOverlay .corner{position:absolute;width:20px;height:20px;border-color:#1a4f7a;border-style:solid}
#certDesignerOverlay .corner.tl{top:8px;left:8px;border-width:2px 0 0 2px}
#certDesignerOverlay .corner.tr{top:8px;right:8px;border-width:2px 2px 0 0}
#certDesignerOverlay .corner.bl{bottom:8px;left:8px;border-width:0 0 2px 2px}
#certDesignerOverlay .corner.br{bottom:8px;right:8px;border-width:0 2px 2px 0}
#certDesignerOverlay .header{display:flex;align-items:center;justify-content:space-between;padding-bottom:14px;border-bottom:3px solid #1a4f7a}
#certDesignerOverlay .logo{width:80px;height:80px;object-fit:contain}
#certDesignerOverlay .header-text{text-align:center;flex:1;font-size:13px;line-height:1.6}
#certDesignerOverlay .header-text .barangay-name{font-family:'Arial Black',Arial,sans-serif;font-size:15px;font-weight:900;color:#0d3b5e}
#certDesignerOverlay .tanggapan{text-align:center;font-family:Arial,sans-serif;font-size:15px;font-style:italic;font-weight:bold;color:#0d3b5e;letter-spacing:2px;padding:14px 0 10px;border-bottom:4px solid #1a4f7a}
#certDesignerOverlay .title-section{display:flex;align-items:center;justify-content:center;gap:12px;padding:18px 0 14px}
#certDesignerOverlay .dots-icon{font-size:22px;color:#555}
#certDesignerOverlay .cert-title{font-family:'Arial Black',Arial,sans-serif;font-size:18px;font-weight:900;color:#222}
#certDesignerOverlay .body{padding:10px 0;font-size:13px;line-height:2}
#certDesignerOverlay .body p{text-align:justify;margin-bottom:14px}
#certDesignerOverlay .body p.indent{text-indent:50px}
#certDesignerOverlay .ef{border:none;border-bottom:1px solid #000;outline:none;font-family:'Times New Roman',serif;font-size:13px;background:transparent;text-align:center;padding:0 2px;min-width:40px}
#certDesignerOverlay .ef:focus{border-bottom:2px solid #1a4f7a;background:#f0f7ff}
#certDesignerOverlay .ef.sm{min-width:50px;width:60px}
#certDesignerOverlay .ef.md{min-width:110px}
#certDesignerOverlay .ef.lg{min-width:160px}
#certDesignerOverlay .ef.xl{min-width:200px}
/* Auto-expanding name field — grows with content, never truncates */
#certDesignerOverlay .ef.ef-name{min-width:180px;width:auto;max-width:100%;transition:width 0.1s ease}
#certDesignerOverlay .ef.brgy-hdr{min-width:130px;font-weight:900;font-family:'Arial Black',Arial,sans-serif;font-size:15px}
#certDesignerOverlay .ef.block-field{width:100%;border:none;border-bottom:1px solid #000;text-align:center;font-size:14px;outline:none;background:transparent}
#certDesignerOverlay .footer-wave{margin-top:auto;margin-left:-15mm;margin-right:-15mm;height:50mm;overflow:hidden;position:relative;flex-shrink:0;background:linear-gradient(to bottom,#fff 0%,#e8f0fb 40%,#bfdbfe 100%)}
#certDesignerOverlay .footer-wave svg{position:absolute;bottom:0;left:0;width:100%;height:100%}
#certDesignerOverlay .info-section{display:flex;gap:20px;margin:10px 0;align-items:flex-start}
#certDesignerOverlay .photo-box{width:100px;min-height:120px;border:1.5px solid #000;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:11px;color:#aaa;cursor:pointer;position:relative;overflow:hidden}
#certDesignerOverlay .photo-box img{position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover}
#certDesignerOverlay .photo-box input[type=file]{display:none}
#certDesignerOverlay .info-table{font-size:12px;line-height:2;flex:1}
#certDesignerOverlay .info-table table{border-collapse:collapse;width:100%}
#certDesignerOverlay .info-table td{padding:1px 4px;vertical-align:bottom}
#certDesignerOverlay .info-table td:first-child{font-weight:bold;white-space:nowrap;width:120px}
#certDesignerOverlay .info-table td.colon{width:14px;text-align:center}
#certDesignerOverlay .info-table .divider-row td{border-top:1px solid #000;padding-top:4px}
#certDesignerOverlay .if{display:inline-block;min-width:150px;border-bottom:1px solid #000;outline:none;background:transparent;font-family:'Times New Roman',serif;font-size:12px}
#certDesignerOverlay .if:focus{background:#f0f7ff}
#certDesignerOverlay .if-sm{display:inline-block;min-width:70px;border-bottom:1px solid #000;outline:none;background:transparent;font-family:'Times New Roman',serif;font-size:12px}
#certDesignerOverlay .if-sm:focus{background:#f0f7ff}
#certDesignerOverlay .bottom-section{display:flex;justify-content:space-between;align-items:flex-start;margin-top:8px;font-size:12px}
#certDesignerOverlay .left-col{width:200px}
#certDesignerOverlay .right-col{width:300px}
/* Clearance redesigned layout */
#certDesignerOverlay .cl-main-row{display:flex;gap:20px;margin:14px 0 10px;align-items:flex-start}
#certDesignerOverlay .cl-info-col{flex:1;min-width:0}
#certDesignerOverlay .cl-right-col{display:flex;flex-direction:column;align-items:center;gap:8px;min-width:170px;width:170px;flex-shrink:0}
#certDesignerOverlay .cl-photo-box{width:145px;height:170px;border:1.5px solid #000;display:flex;align-items:center;justify-content:center;cursor:pointer;position:relative;overflow:hidden;background:#fafafa}
#certDesignerOverlay .cl-photo-box img{position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover}
#certDesignerOverlay .cl-photo-box input[type=file]{display:none}
#certDesignerOverlay .cl-thumbmarks{display:flex;gap:10px;justify-content:center}
#certDesignerOverlay .cl-thumb-col{display:flex;flex-direction:column;align-items:center;gap:4px}
#certDesignerOverlay .cl-thumb-box{width:72px;height:88px;border:1.5px solid #000;background:#fafafa}
#certDesignerOverlay .cl-thumb-label{font-size:10px;text-align:center;font-family:'Times New Roman',serif;color:#333}
#certDesignerOverlay .cl-notice{font-size:9px;font-family:'Times New Roman',serif;text-align:center;line-height:1.4;color:#444;font-style:italic;width:100%;padding:2px 0}
/* Clearance info table — plain underline fields, no box */
#certDesignerOverlay .cl-if{display:inline-block;width:100%;border:none;border-bottom:1px solid #000;outline:none;background:transparent;font-family:'Times New Roman',serif;font-size:12px;padding:0 2px;vertical-align:bottom}
#certDesignerOverlay .cl-if:focus{border-bottom:2px solid #1a4f7a;background:#f0f7ff}
/* Clearance signature row */
#certDesignerOverlay .cl-sig-row{display:flex;justify-content:space-between;align-items:flex-end;margin-top:24px;font-family:'Times New Roman',Times,serif;font-size:13px}
#certDesignerOverlay .cl-sig-col{display:flex;flex-direction:column;align-items:flex-start;min-width:180px}
#certDesignerOverlay .cl-sig-col.cl-sig-right{align-items:flex-end}
#certDesignerOverlay .cl-sig-role{font-style:italic;font-size:12px;margin-bottom:0}
#certDesignerOverlay .cl-sig-space{height:36px}
#certDesignerOverlay .cl-sig-line{border-top:1.5px solid #000;width:200px;margin-bottom:3px}
#certDesignerOverlay .cl-sig-title{font-size:12px;font-weight:bold;text-align:center;width:200px}
#certDesignerOverlay .sig-dashed{border-bottom:1px dashed #000;width:170px;margin-bottom:3px}
#certDesignerOverlay .thumbmark-row{display:flex;gap:8px;margin-top:8px}
#certDesignerOverlay .thumb-box{width:70px;height:75px;border:1.5px solid #000;display:flex;align-items:center;justify-content:center;font-size:10px;color:#888}
#certDesignerOverlay .note-text{font-size:10px;margin-top:8px;font-style:italic}
#certDesignerOverlay .prepared-label{font-weight:bold;font-size:12px;margin-bottom:22px}
#certDesignerOverlay .certified-label{font-weight:bold;font-size:12px;margin:14px 0 22px}
#certDesignerOverlay .sig-block{text-align:center;margin-bottom:6px}
#certDesignerOverlay .sig-block .sig-line{display:block;width:210px;border-top:1.5px solid #000;margin:0 auto 3px}
#certDesignerOverlay .sig-block .sig-name{font-weight:bold;font-size:12px}
#certDesignerOverlay .sig-row{display:flex;justify-content:space-between;margin-top:36px;font-size:13px}
#certDesignerOverlay .sig-col{display:flex;flex-direction:column}
#certDesignerOverlay .sig-label{font-style:italic;margin-bottom:36px}
#certDesignerOverlay .sig-line{border-top:1.5px solid #000;width:200px;margin-bottom:4px}
#certDesignerOverlay .sig-name{font-size:13px;text-align:center;width:200px}
#certDesignerOverlay .sig-section{margin-top:30px;font-size:13px}
#certDesignerOverlay .sig-center{text-align:center;margin-top:36px;font-size:13px}
#certDesignerOverlay .personal-title{text-align:center;font-weight:bold;font-size:13px;margin:16px 0 8px;text-decoration:underline;letter-spacing:0.5px}
#certDesignerOverlay .details-block{font-size:13px;line-height:2}
#certDesignerOverlay .detail-row{display:flex;align-items:baseline;gap:4px;flex-wrap:wrap}
/* Residency personal details — clean table layout, auto-fits any paper size */
#certDesignerOverlay .re-details-table{font-size:11.5px;line-height:1;margin:8px 0 6px;width:100%}
#certDesignerOverlay .re-details-table>table{border-collapse:collapse;width:100%;table-layout:fixed}
#certDesignerOverlay .re-details-table td{padding:5px 2px;vertical-align:bottom;font-family:'Times New Roman',Times,serif;font-size:11.5px;overflow:hidden}
#certDesignerOverlay .re-details-table .re-label{font-weight:bold;white-space:nowrap;padding-right:2px;width:18%}
#certDesignerOverlay .re-details-table .re-colon{text-align:center;width:8px;padding:0;font-weight:bold}
#certDesignerOverlay .re-details-table .re-pair-cell{white-space:nowrap;overflow:hidden}
/* Full-width value */
#certDesignerOverlay .ef.re-val{display:inline-block;width:100%;min-width:0;border:none;border-bottom:1px solid #333;outline:none;background:transparent;font-family:'Times New Roman',serif;font-size:11.5px;text-align:left;padding:0 2px;vertical-align:bottom}
/* Short value — 50% width */
#certDesignerOverlay .ef.re-val-short{display:inline-block;width:50%;min-width:0;border:none;border-bottom:1px solid #333;outline:none;background:transparent;font-family:'Times New Roman',serif;font-size:11.5px;text-align:left;padding:0 2px;vertical-align:bottom}
/* Paired fields — 35% each so label+both fit on one row */
#certDesignerOverlay .ef.re-val-pair{display:inline-block;width:35%;min-width:0;max-width:35%;border:none;border-bottom:1px solid #333;outline:none;background:transparent;font-family:'Times New Roman',serif;font-size:11.5px;text-align:left;padding:0 2px;vertical-align:bottom}
#certDesignerOverlay .re-pair-label{font-size:11px;font-family:'Times New Roman',serif;margin:0 2px 0 5px;vertical-align:bottom;white-space:nowrap;display:inline-block}
/* Address sub-fields */
#certDesignerOverlay .ef.re-val-md{display:inline-block;min-width:50px;width:90px;border:none;border-bottom:1px solid #333;outline:none;background:transparent;font-family:'Times New Roman',serif;font-size:11.5px;text-align:center;padding:0 2px;vertical-align:bottom}
#certDesignerOverlay .ef.re-val-sm{display:inline-block;min-width:30px;width:45px;border:none;border-bottom:1px solid #333;outline:none;background:transparent;font-family:'Times New Roman',serif;font-size:11.5px;text-align:center;padding:0 2px;vertical-align:bottom}
#certDesignerOverlay .ef.re-val:focus,#certDesignerOverlay .ef.re-val-short:focus,#certDesignerOverlay .ef.re-val-pair:focus,#certDesignerOverlay .ef.re-val-md:focus,#certDesignerOverlay .ef.re-val-sm:focus{border-bottom:2px solid #1a4f7a;background:#f0f7ff}
/* Signature area */
#certDesignerOverlay .re-sig-area{margin-top:28px;font-family:'Times New Roman',Times,serif;font-size:13px}
#certDesignerOverlay .re-applicant-sig{text-align:right;margin-bottom:16px;padding-right:0}
#certDesignerOverlay .re-sig-line{display:inline-block;width:200px;border-top:1.5px solid #000;margin-bottom:4px}
#certDesignerOverlay .re-sig-text{font-size:12px;text-align:center;display:inline-block;width:200px}
#certDesignerOverlay .re-officials-sig{margin-top:8px}
#certDesignerOverlay .re-sig-pair{display:flex;justify-content:space-between;align-items:flex-end}
#certDesignerOverlay .re-sig-col{display:flex;flex-direction:column;align-items:flex-start;min-width:180px}
#certDesignerOverlay .re-sig-col.re-sig-right{align-items:flex-end}
#certDesignerOverlay .re-sig-role-label{font-style:italic;font-size:13px;margin-bottom:36px}
#certDesignerOverlay .re-sig-line-wide{width:200px;border-top:1.5px solid #000;margin-bottom:4px}
#certDesignerOverlay .re-sig-title{font-size:12px;width:200px;text-align:center}
#certDesignerOverlay .date-block{text-align:right;margin-bottom:14px;font-size:14px;line-height:2}
#certDesignerOverlay .date-center{text-align:center;font-weight:bold;font-size:14px;margin-bottom:12px}
#certDesignerOverlay .field-block{text-align:center;margin:12px auto 4px;width:320px}
#certDesignerOverlay .field-block .field-label{font-size:12px}
#certDesignerOverlay .name-list{margin:6px 0 12px 50px;font-size:14px;line-height:2.2}
#certDesignerOverlay .name-list li{list-style:decimal;margin-left:10px}
#certDesignerOverlay .cert-subtitle{font-size:13px;font-style:italic;margin-bottom:14px}
#certDesignerOverlay .to-whom{font-weight:bold;margin-bottom:16px}
#certDesignerOverlay .signatures{margin-top:20px;font-size:13px}
#certDesignerOverlay .sig-cp{font-size:12px}
/* Indigency signature area */
#certDesignerOverlay .in-sig-area{display:flex;justify-content:space-between;margin-top:70px;font-family:'Times New Roman',Times,serif;font-size:13px}
#certDesignerOverlay .in-sig-col{display:flex;flex-direction:column;align-items:flex-start;min-width:180px}
#certDesignerOverlay .in-sig-col.in-sig-right{align-items:flex-end}
#certDesignerOverlay .in-sig-role{font-style:italic;margin-bottom:36px;font-size:13px}
#certDesignerOverlay .in-sig-line-wrap{border-top:1.5px solid #000;margin-bottom:4px;min-width:160px;width:auto}
#certDesignerOverlay .in-sig-title{font-size:12px;font-weight:bold;text-align:center}
#certDesignerOverlay .noted{font-weight:bold;font-size:13px;margin-bottom:36px}
#certDesignerOverlay .sig-printed{margin-top:20px;font-size:13px}
#certDesignerOverlay .sig-printed .sig-line{display:block;width:240px;border-top:1.5px solid #000;margin-bottom:3px}
#certDesignerOverlay .sig-right{text-align:right;margin-top:24px;font-size:13px}
#certDesignerOverlay .sig-right .sig-label-r{font-style:italic;margin-bottom:28px}
#certDesignerOverlay .sig-right .sig-line-r{display:inline-block;width:200px;border-top:1.5px solid #000;margin-bottom:4px}
#certDesignerOverlay .cert-section{display:none}
#certDesignerOverlay .cert-section.active{display:block}
#certDesignerOverlay .toolbar-section{display:none}
#certDesignerOverlay .toolbar-section.active{display:grid}

/* Print: only show the active certificate, hide everything else */
@media print {
  * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
  body > *:not(#certDesignerOverlay) { display: none !important; }
  #certDesignerOverlay { display: block !important; position: static !important; overflow: visible !important; background: #fff !important; width: 100% !important; }
  #certDesignerOverlay > div:first-child { display: none !important; } /* topbar */
  #stepSelect { display: none !important; }
  #stepDesigner { display: block !important; overflow: visible !important; padding: 0 !important; }
  /* Hide all chrome — top bar, selector, left form panel */
  .designer-topbar { display: none !important; }
  .selector-panel  { display: none !important; }
  .designer-main-row { display: block !important; }
  .toolbar-panel   { display: none !important; }
  /* Right panel: no background, no border, no scroll — just the cert */
  .cert-preview-panel {
    display: block !important;
    height: auto !important;
    overflow: visible !important;
    background: #fff !important;
    border: none !important;
    padding: 0 !important;
  }
  .cert-section { display: none !important; }
  .cert-section.active { display: block !important; page-break-inside: avoid; }
  .cert-section.active .certificate {
    width: 210mm !important;
    min-height: 0 !important;
    height: auto !important;
    border: 2px solid #1a4f7a !important;
    padding: 8mm 12mm 0 12mm !important;
    margin: 0 !important;
    overflow: visible !important;
    box-shadow: none !important;
    display: flex !important;
    flex-direction: column !important;
    box-sizing: border-box !important;
    transform: none !important; /* reset screen scaling */
  }
  .cert-section.active .footer-wave {
    margin-left: -12mm !important;
    margin-right: -12mm !important;
    height: 40mm !important;
    margin-top: auto !important;
    flex-shrink: 0 !important;
  }
  .ef, .if, .if-sm { border-bottom: 1px solid #000 !important; background: transparent !important; }
  .cl-if { border-bottom: 1px solid #000 !important; background: transparent !important; border-top: none !important; border-left: none !important; border-right: none !important; }
  .ef.re-val, .ef.re-val-short, .ef.re-val-pair, .ef.re-val-md, .ef.re-val-sm { border-bottom: 1px solid #000 !important; background: transparent !important; }
  .ef.ef-name { width: auto !important; }
  .re-details-table > table { width: 100% !important; table-layout: fixed !important; }
  .re-sig-line-wide { border-top: 1.5px solid #000 !important; }
  .cl-sig-line { border-top: 1.5px solid #000 !important; }
  .in-sig-line-wrap { border-top: 1.5px solid #000 !important; }
  .corner { display: none !important; }
  @page { margin: 0; size: A4 portrait; }
}

/* Responsive: on mobile the sidebar is hidden so overlay fills full width */
@media (max-width: 768px) {
  #certDesignerOverlay { left: 0 !important; }
}
</style>

<script>
// ── Page-level functions ──
function filterType(type) {
    document.getElementById('typeFilter').value = type;
    document.getElementById('filterForm').submit();
}
function toggleSidebar() { document.getElementById('sidebar').classList.toggle('show'); }

// ── Checkbox / Export helpers ──
function toggleAllCerts(master) {
    document.querySelectorAll('.cert-chk').forEach(cb => cb.checked = master.checked);
    updateSelCount();
}

function updateSelCount() {
    const checked = document.querySelectorAll('.cert-chk:checked').length;
    const badge = document.getElementById('selCount');
    const btn   = document.getElementById('btnExportSelected');
    if (badge) badge.textContent = checked;
    if (btn)   btn.disabled = checked === 0;
    // Sync master checkbox indeterminate state
    const all  = document.querySelectorAll('.cert-chk').length;
    const master = document.getElementById('chkAll');
    if (master) {
        master.indeterminate = checked > 0 && checked < all;
        master.checked = all > 0 && checked === all;
    }
}

function exportCerts(mode) {
    const form      = document.getElementById('certExportForm');
    const container = document.getElementById('exportIdsContainer');
    const modeInput = document.getElementById('exportMode');

    modeInput.value = mode;
    container.innerHTML = '';

    if (mode === 'selected') {
        const ids = [...document.querySelectorAll('.cert-chk:checked')].map(cb => cb.value);
        if (ids.length === 0) { alert('Please select at least one certificate to export.'); return; }
        ids.forEach(id => {
            const inp = document.createElement('input');
            inp.type = 'hidden'; inp.name = 'ids[]'; inp.value = id;
            container.appendChild(inp);
        });
    }
    form.submit();
}

// Wire checkbox change events once DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.cert-chk').forEach(cb => cb.addEventListener('change', updateSelCount));
});

function openCertArchiveModal(id, type, resident) {
    document.getElementById('certArchiveId').value = id;
    document.getElementById('certArchiveName').textContent = type + ' — ' + resident;
    new bootstrap.Modal(document.getElementById('certArchiveModal')).show();
}

// ── Overlay open/close ──
function openCertDesigner() {
    document.getElementById('certDesignerOverlay').classList.remove('cert-view-mode');
    // Open the modal on the main page instead of the overlay step 1
    const modal = new bootstrap.Modal(document.getElementById('issueCertModal'));
    // Reset form fields
    document.getElementById('selResident').value = '';
    document.getElementById('residentSearch').value = '';
    document.getElementById('selectedResidentBadge').style.display = 'none';
    document.getElementById('residentDropdown').style.display = 'none';
    document.getElementById('selCertType').value = 'clearance';
    document.getElementById('selPurposeFinal').value = '';
    document.getElementById('selPurpose').value = '';
    document.getElementById('selPurpose').style.display = 'none';
    document.getElementById('purposeTriggerLabel').textContent = '— Select purpose —';
    document.getElementById('purposeTriggerLabel').style.color = '#aaa';
    document.getElementById('purposeMenu').style.display = 'none';
    document.getElementById('purposeChevron').style.transform = '';
    document.getElementById('resInfoBox').style.display = 'none';
    window._residentData = null;
    modal.show();
}

function closeCertDesigner() {
    const overlay = document.getElementById('certDesignerOverlay');
    overlay.style.display = 'none';
    overlay.classList.remove('cert-view-mode');
    window._savedCertId = null; // reset saved cert on close
    if (window._certIssued) { window._certIssued = false; location.reload(); }
}

// ── Purpose cascading menu ────────────────────────────────
function togglePurposeMenu() {
    const menu    = document.getElementById('purposeMenu');
    const chevron = document.getElementById('purposeChevron');
    const open    = menu.style.display === 'block';
    menu.style.display    = open ? 'none' : 'block';
    chevron.style.transform = open ? '' : 'rotate(180deg)';
}

function togglePurposeSub(catEl) {
    const sub     = catEl.nextElementSibling;
    const icon    = catEl.querySelector('.bi-chevron-right');
    const isOpen  = sub.style.display === 'block';
    // Close all other open subs first
    document.querySelectorAll('#purposeMenu .pur-sub').forEach(s => {
        s.style.display = 'none';
        const i = s.previousElementSibling?.querySelector('.bi-chevron-right');
        if (i) i.style.transform = '';
    });
    if (!isOpen) {
        sub.style.display = 'block';
        if (icon) icon.style.transform = 'rotate(90deg)';
    }
}

function selectPurpose(val) {
    const menu    = document.getElementById('purposeMenu');
    const chevron = document.getElementById('purposeChevron');
    const label   = document.getElementById('purposeTriggerLabel');
    const custom  = document.getElementById('selPurpose');
    const final   = document.getElementById('selPurposeFinal');
    menu.style.display      = 'none';
    chevron.style.transform = '';
    if (val === '__other__') {
        label.textContent   = 'Other…';
        label.style.color   = '#495057';
        custom.style.display = 'block';
        custom.focus();
        final.value = '';
    } else {
        label.textContent   = val;
        label.style.color   = '#495057';
        custom.style.display = 'none';
        final.value = val;
    }
}

function syncPurposeFromInput() {
    document.getElementById('selPurposeFinal').value = document.getElementById('selPurpose').value.trim();
}

// Close purpose menu on outside click
document.addEventListener('click', function(e) {
    const trigger = document.getElementById('purposeTrigger');
    const menu    = document.getElementById('purposeMenu');
    if (trigger && menu && !trigger.contains(e.target) && !menu.contains(e.target)) {
        menu.style.display = 'none';
        const chevron = document.getElementById('purposeChevron');
        if (chevron) chevron.style.transform = '';
    }
});

// ── View a saved certificate from the table ──
function openCertView(certId, triggerBtn) {
    const btn = triggerBtn || null;
    const origHTML = btn ? btn.innerHTML : '';
    if (btn) { btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Loading...'; btn.disabled = true; }

    fetch('<?= BASE_URL ?>/ajax/get_certificate.php?id=' + certId)
        .then(r => r.json())
        .then(data => {
            if (!data.cert_type) {
                if (btn) { btn.innerHTML = origHTML; btn.disabled = false; }
                alert('Certificate data not found.');
                return;
            }

            const certKey  = data.cert_key;
            const fullName = data.full_name;
            const purok    = data.purok     || '';
            const age      = data.age       || '';
            const gender   = data.gender    || '';
            const civil    = data.civil_status || '';
            const bday     = data.birthdate || '';
            const purpose  = data.purpose   || '';
            const day      = data.day       || '';
            const month    = data.month     || '';
            const year     = data.year      || '';
            const yearLast = data.year_last || '';
            const dateIssued = data.date_issued || '';
            const photo    = data.photo     || '';

            // ── Fill toolbar fields (same as proceedToDesigner) ──
            const fills = {
                clearance:  [['cl-f-name',fullName],['cl-f-purok',purok],['cl-f-age',age],['cl-f-gender',gender],['cl-f-civil',civil],['cl-f-dob',bday],['cl-f-purpose',purpose],['cl-f-day',day],['cl-f-month',month],['cl-f-year',year],['cl-f-issued-on',dateIssued]],
                indigency:  [['in-f-name',fullName],['in-f-purok',purok],['in-f-age',age],['in-f-purpose',purpose],['in-f-day',day],['in-f-month',month],['in-f-year',year]],
                residency:  [['re-f-name',fullName],['re-f-purok',purok],['re-f-age',age],['re-f-sex',gender],['re-f-civil',civil],['re-f-bday',bday],['re-f-purpose',purpose],['re-f-day',day],['re-f-month',month],['re-f-year',year]],
                livein:     [['li-f-name1',fullName],['li-f-purok',purok],['li-f-day',day],['li-f-month',month],['li-f-year',year]],
                sameperson: [['sp-f-name',fullName],['sp-f-purok',purok],['sp-f-name1',fullName],['sp-f-day',day],['sp-f-month',month],['sp-f-year',year]],
                business:   [['bu-f-owner',fullName],['bu-f-purok1',purok],['bu-f-business',purpose],['bu-f-date',dateIssued],['bu-f-day',day],['bu-f-month',month],['bu-f-year',year]],
                barc:       [['ba-f-name',fullName],['ba-f-name2',fullName],['ba-f-recipient',fullName],['ba-f-purok',purok],['ba-f-day',day],['ba-f-month',month],['ba-f-year',year]],
                soloparent: [['so-f-name',fullName],['so-f-solo',fullName],['so-f-purok',purok],['so-f-age',age],['so-f-day',day],['so-f-month',month],['so-f-year',yearLast]],
                lowincome:  [['lo-f-name',fullName],['lo-f-purok',purok],['lo-f-age',age],['lo-f-bday',bday],['lo-f-day',day],['lo-f-month',month],['lo-f-year',year]]
            };

            // Fill toolbar inputs for this cert type
            (fills[certKey] || []).forEach(([id, val]) => {
                const el = document.getElementById(id);
                if (el && val !== undefined) el.value = val;
            });

            // Sync toolbar → certificate inputs
            applyFields(certKey);
            if (data.certificate_data && typeof data.certificate_data === 'object') {
                Object.entries(data.certificate_data).forEach(([id, value]) => {
                    const field = document.getElementById(id);
                    if (field && typeof value === 'string') field.value = value;
                });
            }

            // Handle photo
            const imgEl = document.getElementById('photo-preview');
            const lblEl = document.getElementById('photo-label');
            if (imgEl) {
                if (photo) {
                    imgEl.src = '<?= BASE_URL ?>/uploads/residents/' + photo;
                    imgEl.style.display = 'block';
                    if (lblEl) lblEl.style.display = 'none';
                } else {
                    imgEl.src = '';
                    imgEl.style.display = 'none';
                    if (lblEl) lblEl.style.display = 'block';
                }
            }

            // Show the designer overlay on the correct cert type (no modal needed)
            const overlay = document.getElementById('certDesignerOverlay');
            overlay.classList.add('cert-view-mode');
            overlay.style.display = 'block';
            document.getElementById('stepSelect').style.display = 'none';
            document.getElementById('stepDesigner').style.display = 'block';

            document.getElementById('certTypeDropdown').value = certKey;
            showCertFromDropdown(certKey);
            document.getElementById('overlayTitle').textContent = 'View Certificate';

            window._pendingCertType = certKey;
            window._pendingResId = data.resident_id;
            window._pendingPurpose = purpose;
            window._savedCertId = certId;

            requestAnimationFrame(() => requestAnimationFrame(scaleCertificate));

            // Restore the table button without starting a print job.
            if (btn) { btn.innerHTML = origHTML; btn.disabled = false; }
        })
        .catch(err => {
            console.error(err);
            if (btn) { btn.innerHTML = origHTML; btn.disabled = false; }
            alert('Could not load certificate data.');
        });
}

function openSavedCertificateEdit() {
    if (!window._savedCertId) {
        alert('Certificate record is not available for editing.');
        return;
    }

    document.getElementById('certDesignerOverlay').classList.remove('cert-view-mode');
    document.getElementById('overlayTitle').textContent = 'Edit Certificate';
    const saveButton = document.getElementById('btnSave');
    saveButton.innerHTML = '<i class="bi bi-check-lg me-1"></i>Save Changes';
}

// ── Searchable Resident Dropdown ──
function filterResidents(query) {
    const q = query.trim().toLowerCase();
    const opts = document.querySelectorAll('.res-option');
    let found = 0;
    opts.forEach(opt => {
        const name = opt.dataset.name.toLowerCase();
        if (!q || name.includes(q)) { opt.style.display = ''; found++; }
        else { opt.style.display = 'none'; }
    });
    document.getElementById('noResFound').style.display = found === 0 ? 'block' : 'none';
    showResidentList();
}
function showResidentList() {
    document.getElementById('residentDropdown').style.display = 'block';
}

function hideResidentList() {
    setTimeout(() => { document.getElementById('residentDropdown').style.display = 'none'; }, 200);
}

function selectResident(id, name) {
    document.getElementById('selResident').value = id;
    document.getElementById('residentSearch').value = name;
    document.getElementById('residentDropdown').style.display = 'none';
    document.getElementById('selectedResidentBadge').style.display = 'block';
    document.getElementById('selectedResidentName').textContent = name;
    onResidentChange(id);
}

function clearResidentSelection() {
    document.getElementById('selResident').value = '';
    document.getElementById('residentSearch').value = '';
    document.getElementById('selectedResidentBadge').style.display = 'none';
    document.getElementById('resInfoBox').style.display = 'none';
    // Reset photo box
    const img = document.getElementById('photo-preview');
    const lbl = document.getElementById('photo-label');
    if (img) { img.src = ''; img.style.display = 'none'; }
    if (lbl) lbl.style.display = 'block';
    filterResidents('');
}

// Close dropdown on outside click
document.addEventListener('click', function(e) {
    const wrap = document.getElementById('residentSearch');
    const dd   = document.getElementById('residentDropdown');
    if (wrap && dd && !wrap.contains(e.target) && !dd.contains(e.target)) {
        dd.style.display = 'none';
    }
});

// Reposition dropdown if modal scrolls or window resizes — not needed with simple absolute positioning

// ── Resident change: load info ──
function onResidentChange(id) {
    if (!id) { document.getElementById('resInfoBox').style.display = 'none'; return; }
    fetch('<?= BASE_URL ?>/ajax/get_resident.php?id=' + id)
        .then(r => r.json())
        .then(data => {
            if (!data.id) return;
            // Store for later use
            window._residentData = data;
            document.getElementById('resInfoContent').innerHTML =
                '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:4px;">' +
                '<div><strong>Name:</strong> ' + data.last_name + ', ' + data.first_name + ' ' + (data.middle_name||'') + '</div>' +
                '<div><strong>Age:</strong> ' + (data.age||'') + '</div>' +
                '<div><strong>Gender:</strong> ' + (data.gender||'') + '</div>' +
                '<div><strong>Civil Status:</strong> ' + (data.civil_status||'') + '</div>' +
                '<div><strong>Purok:</strong> ' + (data.purok||'') + '</div>' +
                '</div>';
            document.getElementById('resInfoBox').style.display = 'block';

            // ── Auto-load resident photo into the certificate photo box ──
            const img = document.getElementById('photo-preview');
            const lbl = document.getElementById('photo-label');
            if (img && data.photo) {
                img.src = '<?= BASE_URL ?>/uploads/residents/' + data.photo;
                img.style.display = 'block';
                if (lbl) lbl.style.display = 'none';
            } else if (img) {
                // No photo on file — reset to placeholder
                img.src = '';
                img.style.display = 'none';
                if (lbl) lbl.style.display = 'block';
            }
        }).catch(() => {});
}

// ── Proceed from step 1 to designer ──
function proceedToDesigner() {
    const resId = document.getElementById('selResident').value;
    const certType = document.getElementById('selCertType').value;
    // Read purpose from the final hidden field (set by dropdown or custom text)
    const purpose = (document.getElementById('selPurposeFinal')?.value || document.getElementById('selPurpose')?.value || '').trim();
    if (!resId) { alert('Please select a resident.'); return; }
    if (!purpose) { alert('Please select or enter the purpose.'); return; }

    // Hide the modal
    const modalEl = document.getElementById('issueCertModal');
    const modalInst = bootstrap.Modal.getInstance(modalEl);
    if (modalInst) modalInst.hide();

    // Show designer overlay
    document.getElementById('certDesignerOverlay').style.display = 'block';
    document.getElementById('stepSelect').style.display = 'none';
    document.getElementById('stepDesigner').style.display = 'block';
    document.getElementById('overlayTitle').textContent = 'BARANGAY CERTIFICATE — <?= $BRGY ?>';

    // Pre-fill from resident data
    const d = window._residentData || {};
    const fullName = d.last_name ? (d.last_name + ', ' + d.first_name + (d.middle_name ? ' ' + d.middle_name : '')).toUpperCase() : '';
    const purok = d.purok || '';
    const age = d.age || '';
    const gender = d.gender || '';
    const civil = d.civil_status || '';
    const bday = d.birthdate ? new Date(d.birthdate).toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'}) : '';

    // Build the fills map and store it globally so showCert() can re-apply when switching types
    window._residentFills = {
        clearance:  [['cl-f-name',fullName],['cl-f-purok',purok],['cl-f-age',age],['cl-f-gender',gender],['cl-f-civil',civil],['cl-f-dob',bday],['cl-f-purpose',purpose]],
        indigency:  [['in-f-name',fullName],['in-f-purok',purok],['in-f-age',age],['in-f-purpose',purpose]],
        residency:  [['re-f-name',fullName],['re-f-purok',purok],['re-f-age',age],['re-f-sex',gender],['re-f-civil',civil],['re-f-bday',bday],['re-f-purpose',purpose]],
        livein:     [['li-f-purok',purok]],
        sameperson: [['sp-f-name',fullName],['sp-f-purok',purok]],
        business:   [['bu-f-owner',fullName],['bu-f-purok1',purok]],
        barc:       [['ba-f-name',fullName],['ba-f-name2',fullName],['ba-f-recipient',fullName],['ba-f-purok',purok]],
        soloparent: [['so-f-name',fullName],['so-f-solo',fullName],['so-f-purok',purok],['so-f-age',age]],
        lowincome:  [['lo-f-name',fullName],['lo-f-purok',purok],['lo-f-age',age],['lo-f-bday',bday]]
    };

    // Fill ALL cert types upfront so switching never loses resident data
    Object.entries(window._residentFills).forEach(([cert, fieldPairs]) => {
        fieldPairs.forEach(([id, val]) => {
            const el = document.getElementById(id);
            if (el && val) el.value = val;
        });
        applyFields(cert);
    });

    showCertFromDropdown(certType);

    // Scale certificate to fit panel after layout settles
    requestAnimationFrame(() => requestAnimationFrame(scaleCertificate));

    // Store for save
    window._pendingResId = resId;
    window._pendingCertType = certType;
    window._pendingPurpose = purpose;
    window._savedCertId = null; // reset any previously saved cert ID

}

// ── Back to step 1 ──
function backToSelect() {
    document.getElementById('stepDesigner').style.display = 'none';
    document.getElementById('overlayTitle').textContent = 'BARANGAY CERTIFICATE — <?= $BRGY ?>';
    window._savedCertId = null; // reset saved cert on going back
    // Re-open the selection modal
    const modal = new bootstrap.Modal(document.getElementById('issueCertModal'));
    modal.show();
}

// ── Save to DB then show print button ──
function saveAndPreview() {
    // Map designer cert type to DB cert type
    const typeMap = {
        clearance:  'Clearance',
        indigency:  'Indigency',
        residency:  'Residency',
        livein:     'Live-in',
        sameperson: 'Same Person',
        business:   'Business Permit',
        barc:       'BARC Certificate',
        soloparent: 'Solo Parent',
        lowincome:  'Proof of Low Income'
    };
    const isEditing = Boolean(window._savedCertId);
    const resId = window._pendingResId;
    const certType = isEditing
        ? document.getElementById('certTypeDropdown').value
        : window._pendingCertType;
    const purposeFieldIds = {
        clearance: 'cl-f-purpose',
        indigency: 'in-f-purpose',
        residency: 're-f-purpose',
        business: 'bu-f-business'
    };
    const purposeField = document.getElementById(purposeFieldIds[certType] || '');
    const purpose = isEditing && purposeField ? purposeField.value.trim() : window._pendingPurpose;

    if ((!isEditing && (!resId || !certType)) || (isEditing && !certType)) {
        alert('Missing certificate data. Please reopen the certificate and try again.');
        return;
    }
    if (isEditing && !purpose) {
        alert('Please enter the certificate purpose before saving.');
        if (purposeField) purposeField.focus();
        return;
    }

    const dbType = typeMap[certType] || certType;

    const fd = new FormData();
    fd.append(isEditing ? 'ajax_update' : 'ajax_issue', '1');
    if (isEditing) {
        fd.append('cert_id', window._savedCertId);
    } else {
        fd.append('resident_id', resId);
    }
    fd.append('certificate_type', dbType);
    fd.append('purpose', purpose);
    const designerData = {};
    [`tb-${certType}`, `cert-${certType}`].forEach(sectionId => {
        document.querySelectorAll(`#${sectionId} input:not([type="file"]), #${sectionId} textarea, #${sectionId} select`)
            .forEach(field => {
                if (field.id) designerData[field.id] = field.value;
            });
    });
    fd.append('certificate_data', JSON.stringify(designerData));

    // Include CSRF token from meta tag
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

    const saveBtn = document.getElementById('btnSave');
    if (saveBtn) { saveBtn.disabled = true; saveBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Saving...'; }

    fetch('<?= BASE_URL ?>/barangay_admin/certificates.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken },
        body: fd
    })
        .then(r => {
            if (!r.ok) throw new Error('Server returned ' + r.status);
            return r.json();
        })
        .then(data => {
            if (data.success) {
                if (isEditing) {
                    window._pendingCertType = certType;
                    window._pendingPurpose = purpose;
                    openCertView(window._savedCertId);
                } else {
                    window._certIssued = true;
                    window._savedCertId = data.cert_id;
                    document.getElementById('overlayTitle').textContent = 'Certificate Preview — Ready to Print';
                }
                // Restore button to normal state
                if (saveBtn) {
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = isEditing
                        ? '<i class="bi bi-check-lg me-1"></i>Save Changes'
                        : '<i class="bi bi-floppy-fill me-1"></i>Save Record';
                }
                // Flash success bar
                const bar = document.getElementById('saveSuccessBar');
                if (bar) { bar.style.display = 'flex'; setTimeout(() => bar.style.display = 'none', 4000); }
            } else {
                if (saveBtn) {
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = isEditing
                        ? '<i class="bi bi-check-lg me-1"></i>Save Changes'
                        : '<i class="bi bi-floppy-fill me-1"></i>Save Record';
                }
                alert(isEditing ? 'Could not update certificate.' : 'Error saving certificate. Please try again.');
            }
        })
        .catch(err => {
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.innerHTML = isEditing
                    ? '<i class="bi bi-check-lg me-1"></i>Save Changes'
                    : '<i class="bi bi-floppy-fill me-1"></i>Save Record';
            }
            alert((isEditing ? 'Could not update: ' : 'Could not save: ') + err.message + '. Please check your connection and try again.');
        });
}

// ── Print / PDF — always uses html2canvas+jsPDF for pixel-perfect output ──
function doPrint() {
    const certEl = document.querySelector('.cert-section.active .certificate');
    if (!certEl) { alert('Please select a certificate type first.'); return; }

    const btn = document.getElementById('btnPrintCert');
    const origText = btn ? btn.innerHTML : '';
    if (btn) { btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Generating...'; btn.disabled = true; }

    const origStyle = certEl.getAttribute('style') || '';
    certEl.style.width = '794px';
    certEl.style.minHeight = 'auto';

    html2canvas(certEl, {
        scale: 2,
        useCORS: true,
        allowTaint: true,
        backgroundColor: '#ffffff',
        logging: false,
        width: certEl.scrollWidth,
        height: certEl.scrollHeight
    }).then(canvas => {
        certEl.setAttribute('style', origStyle);

        const imgData = canvas.toDataURL('image/jpeg', 0.95);
        const { jsPDF } = window.jspdf;
        const pdfW = 210;
        const pdfH = Math.round(pdfW * (canvas.height / canvas.width));
        const pdf = new jsPDF({
            orientation: pdfH > pdfW ? 'portrait' : 'landscape',
            unit: 'mm',
            format: [pdfW, pdfH]
        });
        pdf.addImage(imgData, 'JPEG', 0, 0, pdfW, pdfH);

        const certType = currentCert || 'certificate';
        const nameIds = {
            clearance:'cl-c-name', indigency:'in-c-name', residency:'re-c-name',
            business:'bu-c-owner', soloparent:'so-c-name',
            lowincome:'lo-c-name', barc:'ba-c-name', livein:'li-c-name1', sameperson:'sp-c-name'
        };
        const nameEl = nameIds[certType] ? document.getElementById(nameIds[certType]) : null;
        const resName = (nameEl && nameEl.value)
            ? nameEl.value.replace(/[^a-zA-Z0-9\s]/g,'').trim().replace(/\s+/g,'_')
            : 'certificate';
        pdf.save(certType + '_' + resName + '.pdf');

        if (btn) { btn.innerHTML = origText; btn.disabled = false; }
    }).catch(err => {
        certEl.setAttribute('style', origStyle);
        console.error(err);
        window.print();
        if (btn) { btn.innerHTML = origText; btn.disabled = false; }
    });
}

// Auto-resize inputs that use ef-name class — grows with typed content
function autoResizeInput(el) {
    // Use a hidden span to measure text width
    let ruler = document.getElementById('_ef_ruler');
    if (!ruler) {
        ruler = document.createElement('span');
        ruler.id = '_ef_ruler';
        ruler.style.cssText = 'position:absolute;visibility:hidden;white-space:pre;font-family:"Times New Roman",Times,serif;font-size:13px;padding:0 3px;pointer-events:none;';
        document.body.appendChild(ruler);
    }
    ruler.textContent = el.value || el.placeholder || '';
    const w = Math.max(180, ruler.offsetWidth + 10);
    el.style.width = w + 'px';
}

// Wire up all ef-name inputs
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.ef.ef-name').forEach(el => {
        el.addEventListener('input', () => autoResizeInput(el));
        autoResizeInput(el); // size on load
    });
});

// ── Certificate designer logic ──
const certMaps = {
  clearance:  [['cl-f-name','cl-c-name'],['cl-f-ibrgy','cl-c-ibrgy'],['cl-f-day','cl-c-day'],['cl-f-month','cl-c-month'],['cl-f-year','cl-c-year'],['cl-f-purpose','cl-c-purpose'],['cl-f-gender','cl-c-gender'],['cl-f-age','cl-c-age'],['cl-f-dob','cl-c-dob'],['cl-f-pob','cl-c-pob'],['cl-f-purok','cl-c-purok'],['cl-f-civil','cl-c-civil'],['cl-f-tct','cl-c-tct'],['cl-f-issued-at','cl-c-issued-at'],['cl-f-issued-on','cl-c-issued-on'],['cl-f-sec','cl-c-sec'],['cl-f-puno','cl-c-puno']],
  indigency:  [['in-f-name','in-c-name'],['in-f-age','in-c-age'],['in-f-purok','in-c-purok'],['in-f-brgy','in-c-brgy'],['in-f-purpose','in-c-purpose'],['in-f-day','in-c-day'],['in-f-month','in-c-month'],['in-f-year','in-c-year'],['in-f-ibrgy','in-c-ibrgy'],['in-f-sec','in-c-sec'],['in-f-puno','in-c-puno']],
  residency:  [['re-f-name','re-c-name'],['re-f-name','re-c-det-name'],['re-f-age','re-c-age'],['re-f-purok','re-c-purok'],['re-f-purok','re-c-det-purok'],['re-f-brgy','re-c-brgy'],['re-f-brgy','re-c-det-brgy'],['re-f-day','re-c-day'],['re-f-month','re-c-month'],['re-f-year','re-c-year'],['re-f-ibrgy','re-c-ibrgy'],['re-f-civil','re-c-civil'],['re-f-sex','re-c-sex'],['re-f-bday','re-c-bday'],['re-f-bplace','re-c-bplace'],['re-f-height','re-c-height'],['re-f-weight','re-c-weight'],['re-f-father','re-c-father'],['re-f-mother','re-c-mother'],['re-f-purpose','re-c-purpose'],['re-f-sec','re-c-sec'],['re-f-puno','re-c-puno']],
  livein:     [['li-f-name1','li-c-name1'],['li-f-name2','li-c-name2'],['li-f-purok','li-c-purok'],['li-f-brgy','li-c-brgy'],['li-f-since','li-c-since'],['li-f-day','li-c-day'],['li-f-month','li-c-month'],['li-f-year','li-c-year'],['li-f-ibrgy','li-c-ibrgy'],['li-f-sec','li-c-sec'],['li-f-puno','li-c-puno']],
  sameperson: [['sp-f-name','sp-c-name'],['sp-f-purok','sp-c-purok'],['sp-f-brgy','sp-c-brgy'],['sp-f-name1','sp-c-name1'],['sp-f-name2','sp-c-name2'],['sp-f-day','sp-c-day'],['sp-f-month','sp-c-month'],['sp-f-year','sp-c-year'],['sp-f-ibrgy','sp-c-ibrgy'],['sp-f-sec','sp-c-sec'],['sp-f-puno','sp-c-puno']],
  business:   [['bu-f-date','bu-c-date'],['bu-f-time','bu-c-time'],['bu-f-business','bu-c-business'],['bu-f-owner','bu-c-owner'],['bu-f-purok1','bu-c-purok1'],['bu-f-brgy1','bu-c-brgy1'],['bu-f-purok2','bu-c-purok2'],['bu-f-brgy2','bu-c-brgy2'],['bu-f-day','bu-c-day'],['bu-f-month','bu-c-month'],['bu-f-year','bu-c-year'],['bu-f-ibrgy','bu-c-ibrgy'],['bu-f-sec','bu-c-sec'],['bu-f-puno','bu-c-puno'],['bu-f-sb','bu-c-sb']],
  barc:       [['ba-f-name','ba-c-name'],['ba-f-purok','ba-c-purok'],['ba-f-brgy','ba-c-brgy'],['ba-f-name2','ba-c-name2'],['ba-f-lot','ba-c-lot'],['ba-f-plano','ba-c-plano'],['ba-f-sukat','ba-c-sukat'],['ba-f-recipient','ba-c-recipient'],['ba-f-day','ba-c-day'],['ba-f-month','ba-c-month'],['ba-f-year','ba-c-year'],['ba-f-sec','ba-c-sec'],['ba-f-barc','ba-c-barc'],['ba-f-puno','ba-c-puno']],
  soloparent: [['so-f-name','so-c-name'],['so-f-age','so-c-age'],['so-f-purok','so-c-purok'],['so-f-solo','so-c-solo'],['so-f-child1','so-c-child1'],['so-f-child2','so-c-child2'],['so-f-child3','so-c-child3'],['so-f-day','so-c-day'],['so-f-month','so-c-month'],['so-f-year','so-c-year'],['so-f-ibrgy','so-c-ibrgy'],['so-f-sec','so-c-sec'],['so-f-puno','so-c-puno']],
  lowincome:  [['lo-f-name','lo-c-name'],['lo-f-age','lo-c-age'],['lo-f-purok','lo-c-purok'],['lo-f-bday','lo-c-bday'],['lo-f-work','lo-c-work'],['lo-f-income','lo-c-income'],['lo-f-day','lo-c-day'],['lo-f-month','lo-c-month'],['lo-f-year','lo-c-year'],['lo-f-ibrgy','lo-c-ibrgy'],['lo-f-sec','lo-c-sec'],['lo-f-puno','lo-c-puno']]
};

// Live sync between toolbar and certificate fields
Object.values(certMaps).forEach(map => {
  map.forEach(([s,d]) => {
    const src = document.getElementById(s), dest = document.getElementById(d);
    if (!src || !dest) return;
    src.addEventListener('input', () => {
        dest.value = src.value;
        if (dest.classList.contains('ef-name')) autoResizeInput(dest);
    });
    dest.addEventListener('input', () => {
        src.value = dest.value;
        if (dest.classList.contains('ef-name')) autoResizeInput(dest);
    });
  });
});

function applyFields(cert) {
  certMaps[cert].forEach(([s,d]) => {
    const src = document.getElementById(s), dest = document.getElementById(d);
    if (src && dest) {
        dest.value = src.value;
        if (dest.classList.contains('ef-name')) autoResizeInput(dest);
        if (src.classList.contains('ef-name')) autoResizeInput(src);
    }
  });
}

function clearFields(cert) {
  certMaps[cert].forEach(([s,d]) => {
    const src = document.getElementById(s), dest = document.getElementById(d);
    if (src) src.value = '';
    if (dest) dest.value = '';
  });
}

let currentCert = 'clearance';
function showCert(name, btn) {
  document.getElementById('cert-' + currentCert).classList.remove('active');
  document.getElementById('tb-' + currentCert).classList.remove('active');
  document.getElementById('cert-' + name).classList.add('active');
  document.getElementById('tb-' + name).classList.add('active');
  currentCert = name;
  // Reset zoom to auto-fit when switching types
  _certZoom = null;
  // Keep _pendingCertType in sync so Save Record always saves the correct type
  if (window._pendingCertType !== undefined) window._pendingCertType = name;
  // Sync dropdown
  const dd = document.getElementById('certTypeDropdown');
  if (dd) dd.value = name;
  // Scroll the right panel back to top
  const previewPanel = document.getElementById('certPreviewPanel');
  if (previewPanel) previewPanel.scrollTop = 0;
  scaleCertificate();
}

function showCertFromDropdown(name) {
  showCert(name, null);
}

// ── Zoom controls ──
let _certZoom = null; // null = auto-fit; number = user-set scale

function _getAutoScale() {
    const panel = document.getElementById('certPreviewPanel');
    if (!panel || panel.clientWidth === 0) return null; // overlay not visible yet
    const panelW = panel.clientWidth - 32;
    return Math.min(1, panelW / 794); // 794px = 210mm at 96dpi
}

function zoomCert(delta) {
    const panel = document.getElementById('certPreviewPanel');
    const activeCert = panel ? panel.querySelector('.cert-section.active .certificate') : null;
    if (!activeCert) return;
    // Seed from current auto scale if no manual zoom yet
    if (_certZoom === null) {
        const auto = _getAutoScale();
        if (auto === null) return;
        _certZoom = auto;
    }
    _certZoom = Math.min(1.5, Math.max(0.3, _certZoom + delta));
    // Apply via scaleCertificate so centering logic is consistent
    scaleCertificate();
}

// ── Fullscreen toggle for the certificate overlay ──
function toggleFullscreen() {
    const overlay = document.getElementById('certDesignerOverlay');
    const icon    = document.getElementById('fsIcon');
    if (!overlay) return;
    const isFullscreen = overlay.dataset.fullscreen === '1';

    const toolbarPanel  = overlay.querySelector('.toolbar-panel');
    const previewPanel  = overlay.querySelector('.cert-preview-panel');

    if (isFullscreen) {
        // Restore normal position (below topbar, beside sidebar)
        overlay.style.top      = 'var(--topbar-height)';
        overlay.style.left     = 'var(--sidebar-width)';
        overlay.style.zIndex   = '998';
        overlay.dataset.fullscreen = '0';
        if (icon) { icon.classList.replace('bi-fullscreen-exit','bi-fullscreen'); }
        // Restore panel heights (topbar = 80px, overlay bar = 52px)
        const h = 'calc(100vh - var(--topbar-height) - 52px)';
        if (toolbarPanel) toolbarPanel.style.height = h;
        if (previewPanel) previewPanel.style.height  = h;
    } else {
        // Cover entire viewport including topbar and sidebar
        overlay.style.top      = '0';
        overlay.style.left     = '0';
        overlay.style.right    = '0';
        overlay.style.bottom   = '0';
        overlay.style.zIndex   = '1100';
        overlay.dataset.fullscreen = '1';
        if (icon) { icon.classList.replace('bi-fullscreen','bi-fullscreen-exit'); }
        // Expand panel heights (no page topbar to subtract)
        const h = 'calc(100vh - 52px)';
        if (toolbarPanel) toolbarPanel.style.height = h;
        if (previewPanel) previewPanel.style.height  = h;
    }
    requestAnimationFrame(() => requestAnimationFrame(scaleCertificate));
}

function scaleCertificate() {
  const panel = document.getElementById('certPreviewPanel');
  if (!panel) return;
  const activeCert = panel.querySelector('.cert-section.active .certificate');
  if (!activeCert) return;
  // If overlay is not visible, panel.clientWidth = 0 — skip to avoid bad scale
  if (panel.clientWidth === 0) return;

  const CERT_W = 794; // 210mm at 96dpi
  const panelW = panel.clientWidth - 32; // 16px padding each side

  // Determine scale — user zoom or auto-fit
  const scale = (_certZoom !== null)
    ? _certZoom
    : Math.min(1, panelW / CERT_W);

  const certH   = activeCert.scrollHeight;
  const wrapper = activeCert.parentElement; // .cert-section

  // Scale from top-left, then shift right to center within panel
  const offsetX = Math.max(0, (panelW - CERT_W * scale) / 2);
  activeCert.style.transform       = `scale(${scale})`;
  activeCert.style.transformOrigin = 'top left';
  activeCert.style.marginLeft      = offsetX + 'px';

  // Shrink wrapper height to match scaled cert height so nothing clips below
  wrapper.style.height    = (certH * scale) + 'px';
  wrapper.style.minHeight = (certH * scale) + 'px';
  wrapper.style.overflow  = 'visible';
}

function loadPhoto(event) {
  const file = event.target.files[0];
  if (!file) return;
  const reader = new FileReader();
  reader.onload = e => {
    const img = document.getElementById('photo-preview');
    const lbl = document.getElementById('photo-label');
    img.src = e.target.result;
    img.style.display = 'block';
    if (lbl) lbl.style.display = 'none';
  };
  reader.readAsDataURL(file);
}

// ── On DOM ready: move all cert-sections into the right preview panel ──
document.addEventListener('DOMContentLoaded', () => {
  // Migrate cert-sections from toolbar-panel into certPreviewPanel
  const previewPanel = document.getElementById('certPreviewPanel');
  const toolbarPanel = document.querySelector('#certDesignerOverlay .toolbar-panel');
  if (previewPanel && toolbarPanel) {
    const sections = toolbarPanel.querySelectorAll('.cert-section');
    sections.forEach(s => previewPanel.appendChild(s));
  }

  // Apply all cert field defaults
  Object.keys(certMaps).forEach(cert => applyFields(cert));

  // Initial scale after a tick (let layout settle)
  setTimeout(scaleCertificate, 80);

  // Re-scale on window resize
  window.addEventListener('resize', scaleCertificate);
});
</script>
<!-- Archive Certificate Form -->
<form method="POST" id="certArchiveForm">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="archive_cert">
    <input type="hidden" name="cert_id" id="certArchiveId">
</form>

<!-- Archive Certificate Modal -->
<div class="modal fade" id="certArchiveModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;overflow:hidden;">
            <div class="d-flex">
                <div class="d-flex flex-column align-items-center justify-content-center px-4 py-5"
                     style="background:linear-gradient(160deg,#856404,#b8860b);min-width:170px;width:170px;flex-shrink:0;">
                    <div style="width:60px;height:60px;background:rgba(255,255,255,0.15);border-radius:50%;display:flex;align-items:center;justify-content:center;border:3px solid rgba(255,255,255,0.3);">
                        <i class="bi bi-archive-fill text-white" style="font-size:1.6rem;"></i>
                    </div>
                    <h6 class="fw-bold mt-3 mb-0 text-white text-center">Archive</h6>
                    <p class="text-white-50 small text-center mb-0">Certificate</p>
                </div>
                <div class="flex-fill p-4 d-flex flex-column justify-content-between">
                    <div>
                        <p class="text-muted small mb-1">You are about to archive:</p>
                        <p class="fw-bold mb-3" id="certArchiveName" style="color:#1a4f7a;"></p>
                        <div class="alert alert-warning py-2 small mb-0">
                            <i class="bi bi-info-circle me-1"></i>
                            This certificate will be moved to the Archive. It can be restored anytime.
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-3">
                        <button type="button" class="btn btn-outline-secondary flex-fill" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-warning flex-fill fw-semibold"
                                onclick="document.getElementById('certArchiveForm').submit()">
                            <i class="bi bi-archive me-1"></i>Yes, Archive
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Hidden export form -->
<form method="POST" id="certExportForm" action="<?= BASE_URL ?>/barangay_admin/export_certificates_selected.php" style="display:none;">
    <?= csrfField() ?>
    <input type="hidden" name="mode" id="exportMode" value="selected">
    <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
    <input type="hidden" name="type"   value="<?= htmlspecialchars($typeFilter) ?>">
    <div id="exportIdsContainer"></div>
</form>


<script src="<?= BASE_URL ?>/assets/js/autocomplete.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    initAutocomplete('certSearchInput', 'certAcList', '<?= BASE_URL ?>/ajax/search_certificates.php', 'certSearchClear');
});
</script>
</body>
</html>
