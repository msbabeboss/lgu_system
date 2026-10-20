<?php
require_once __DIR__ . '/../config/config.php';
requireRole('barangay_admin');

$db  = Database::getInstance()->getConnection();
$u   = currentUser();
$bid = (int)$u['barangay_id'];

$id = (int)($_GET['id'] ?? 0);
if (!$id) { redirect(BASE_URL . '/barangay_admin/residents.php'); }

// ── FAST ACCESS CHECK — must be first, before any slow query ──────────────
// Deny immediately if the resident doesn't belong to this barangay.
// Uses the PK + barangay_id index — single-row lookup, < 1 ms.
$_ownCheck = $db->prepare("SELECT id FROM residents WHERE id = ? AND barangay_id = ? LIMIT 1");
$_ownCheck->execute([$id, $bid]);
if (!$_ownCheck->fetch()) { redirect(BASE_URL . '/barangay_admin/residents.php'); }

// ── Handle household assignment POST (modal form) ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign_household') {
    verifyCsrfOrAbort();

    $hhRole        = $_POST['household_role']        ?? '';
    $newHhId       = (int)($_POST['hh_member_household_id'] ?? 0);
    $newRelationship = sanitize($_POST['hh_member_relationship'] ?? 'Member');

    // Load resident flags needed for counter updates
    $resFlags = $db->prepare("SELECT is_senior, is_pwd FROM residents WHERE id=? AND barangay_id=?");
    $resFlags->execute([$id, $bid]);
    $rf = $resFlags->fetch();
    $resSenior = (int)($rf['is_senior'] ?? 0);
    $resPwd    = (int)($rf['is_pwd']    ?? 0);

    // Fetch current membership
    $curMemStmt = $db->prepare(
        "SELECT hm.household_id, h.head_resident_id FROM household_members hm
         JOIN households h ON hm.household_id = h.id
         WHERE hm.resident_id = ? AND h.barangay_id = ? AND h.status = 'Active' LIMIT 1"
    );
    $curMemStmt->execute([$id, $bid]);
    $curMem = $curMemStmt->fetch();
    $curHhId   = $curMem ? (int)$curMem['household_id']    : 0;
    $isHead    = $curMem ? ((int)$curMem['head_resident_id'] === $id) : false;

    if ($hhRole === 'none') {
        // Remove from household (only if not head)
        if ($curHhId && !$isHead) {
            $db->prepare("DELETE FROM household_members WHERE household_id=? AND resident_id=?")->execute([$curHhId, $id]);
            $db->prepare("UPDATE households SET total_members = GREATEST(total_members-1,0) WHERE id=?")->execute([$curHhId]);
            if ($resSenior) $db->prepare("UPDATE households SET total_senior = GREATEST(total_senior-1,0) WHERE id=?")->execute([$curHhId]);
            if ($resPwd)    $db->prepare("UPDATE households SET total_pwd    = GREATEST(total_pwd-1,0)    WHERE id=?")->execute([$curHhId]);
            logActivity($u['id'], 'EDIT_RESIDENT', 'Residents', "Removed resident #$id from household #$curHhId");
            flash('res_hh', 'Resident removed from household.', 'info');
        }
    } elseif ($hhRole === 'member' && $newHhId) {
        // Verify household belongs to this barangay
        $hhVerify = $db->prepare("SELECT id FROM households WHERE id=? AND barangay_id=? AND status='Active'");
        $hhVerify->execute([$newHhId, $bid]);
        if ($hhVerify->fetch()) {
            // Remove from old household if changing (and not head)
            if ($curHhId && $curHhId !== $newHhId && !$isHead) {
                $db->prepare("DELETE FROM household_members WHERE household_id=? AND resident_id=?")->execute([$curHhId, $id]);
                $db->prepare("UPDATE households SET total_members = GREATEST(total_members-1,0) WHERE id=?")->execute([$curHhId]);
                if ($resSenior) $db->prepare("UPDATE households SET total_senior = GREATEST(total_senior-1,0) WHERE id=?")->execute([$curHhId]);
                if ($resPwd)    $db->prepare("UPDATE households SET total_pwd    = GREATEST(total_pwd-1,0)    WHERE id=?")->execute([$curHhId]);
            }
            // Add to new household or update relationship
            $dupCheck = $db->prepare("SELECT id FROM household_members WHERE household_id=? AND resident_id=?");
            $dupCheck->execute([$newHhId, $id]);
            if ($dupCheck->fetch()) {
                $db->prepare("UPDATE household_members SET relationship=? WHERE household_id=? AND resident_id=?")->execute([$newRelationship, $newHhId, $id]);
            } else {
                $db->prepare("INSERT INTO household_members (household_id,resident_id,relationship) VALUES (?,?,?)")->execute([$newHhId, $id, $newRelationship]);
                $db->prepare("UPDATE households SET total_members = total_members+1 WHERE id=?")->execute([$newHhId]);
                if ($resSenior) $db->prepare("UPDATE households SET total_senior = total_senior+1 WHERE id=?")->execute([$newHhId]);
                if ($resPwd)    $db->prepare("UPDATE households SET total_pwd    = total_pwd+1    WHERE id=?")->execute([$newHhId]);
            }
            // ── Sync resident address purok/street/zone from household ──
            $hhAddrStmt = $db->prepare(
                "SELECT a.purok, a.street, a.zone FROM households h
                 JOIN addresses a ON h.address_id = a.id WHERE h.id = ?"
            );
            $hhAddrStmt->execute([$newHhId]);
            $hhAddr = $hhAddrStmt->fetch();
            if ($hhAddr) {
                $db->prepare(
                    "UPDATE addresses SET purok=?, street=?, zone=?
                     WHERE id = (SELECT address_id FROM residents WHERE id=?)"
                )->execute([$hhAddr['purok'], $hhAddr['street'], $hhAddr['zone'], $id]);
            }
            $hhCode = $db->prepare("SELECT household_code FROM households WHERE id=?");
            $hhCode->execute([$newHhId]);
            $hhCodeLabel = $hhCode->fetchColumn() ?: "#$newHhId";
            logActivity($u['id'], 'EDIT_RESIDENT', 'Residents', "Assigned resident #$id to household $hhCodeLabel as $newRelationship");
            flash('res_hh', "Resident linked to household <strong>$hhCodeLabel</strong> as <strong>$newRelationship</strong>.", 'success');
        }
    }
    redirect(BASE_URL . '/barangay_admin/view_resident.php?id=' . $id);
}

// Fetch resident with address
$stmt = $db->prepare("
    SELECT r.*,
           a.region, a.province, a.city, a.barangay as addr_barangay,
           a.street, a.purok, a.zone, a.zip_code
    FROM residents r
    LEFT JOIN addresses a ON r.address_id = a.id
    WHERE r.id = ? AND r.barangay_id = ?
");
$stmt->execute([$id, $bid]);
$res = $stmt->fetch();
// Ownership already confirmed by fast check above; $res cannot be null here.
// Defensive fallback kept in case of direct DB inconsistency.
if (!$res) { redirect(BASE_URL . '/barangay_admin/residents.php'); }

// Fetch household info
$hhStmt = $db->prepare("
    SELECT h.household_code, h.income_range, h.length_of_stay, h.status as hh_status,
           hm.relationship,
           h.id as household_id,
           h.head_resident_id,
           CONCAT(hr.last_name, ', ', hr.first_name) as head_name
    FROM household_members hm
    JOIN households h ON hm.household_id = h.id
    LEFT JOIN residents hr ON h.head_resident_id = hr.id
    WHERE hm.resident_id = ?
    LIMIT 1
");
$hhStmt->execute([$id]);
$household = $hhStmt->fetch();

// Active households for the assignment modal dropdown
$activeHouseholdsStmt = $db->prepare(
    "SELECT h.id, h.household_code, CONCAT(r.last_name, ', ', r.first_name) AS head_name
     FROM households h LEFT JOIN residents r ON h.head_resident_id = r.id
     WHERE h.barangay_id = ? AND h.status = 'Active' ORDER BY h.household_code ASC"
);
$activeHouseholdsStmt->execute([$bid]);
$activeHouseholds = $activeHouseholdsStmt->fetchAll();

// Fetch certificates issued to this resident
$certStmt = $db->prepare("
    SELECT c.certificate_type, c.purpose, c.date_issued,
           CONCAT(u.first_name, ' ', u.last_name) as issued_by_name
    FROM certificates c
    LEFT JOIN users u ON c.issued_by = u.id
    WHERE c.resident_id = ? AND c.barangay_id = ?
    ORDER BY c.date_issued DESC
    LIMIT 10
");
$certStmt->execute([$id, $bid]);
$certificates = $certStmt->fetchAll();

// Total certificate count for this resident (all-time)
$certCountStmt = $db->prepare("
    SELECT COUNT(*) as total,
           MAX(date_issued) as last_issued
    FROM certificates
    WHERE resident_id = ? AND barangay_id = ?
");
$certCountStmt->execute([$id, $bid]);
$certCountRow  = $certCountStmt->fetch();
$totalCertCount = (int)($certCountRow['total'] ?? 0);
$lastCertIssued = $certCountRow['last_issued'] ?? null;

// Per-type breakdown
$certByTypeStmt = $db->prepare("
    SELECT certificate_type, COUNT(*) as cnt
    FROM certificates
    WHERE resident_id = ? AND barangay_id = ?
    GROUP BY certificate_type
    ORDER BY cnt DESC
");
$certByTypeStmt->execute([$id, $bid]);
$certByType = $certByTypeStmt->fetchAll();

// Fetch barangay info
$brgyStmt = $db->prepare("SELECT * FROM barangays WHERE id = ?");
$brgyStmt->execute([$bid]);
$brgy = $brgyStmt->fetch();

// Build full name
$fullName = trim(
    $res['last_name'] . ', ' .
    $res['first_name'] .
    ($res['middle_name'] ? ' ' . $res['middle_name'] : '') .
    ($res['suffix']      ? ' ' . $res['suffix']      : '')
);
$displayName = trim(
    $res['first_name'] .
    ($res['middle_name'] ? ' ' . $res['middle_name'] : '') . ' ' .
    $res['last_name'] .
    ($res['suffix'] ? ' ' . $res['suffix'] : '')
);

// Address string
$addressParts = array_filter([
    $res['purok']        ?? '',
    $res['street']       ?? '',
    $res['zone']         ? 'Zone ' . $res['zone'] : '',
    $res['addr_barangay']?? '',
    $res['city']         ?? '',
    $res['province']     ?? '',
]);
$fullAddress = implode(', ', $addressParts) ?: '—';

logActivity($u['id'], 'VIEW_RESIDENT', 'Residents', "Viewed resident profile: $displayName");

$pageIcon     = 'bi-person-vcard-fill';
$pageTitle    = 'Resident Profile';
$pageSubtitle = htmlspecialchars($displayName);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favcon.png">
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favcon.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($displayName) ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=3.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <style>
        /* ── Info card ── */
        .info-card {
            background: #fff;
            border-radius: 10px;
            border: 1px solid #e5e7eb;
            overflow: hidden;
            margin-bottom: 12px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.04);
        }
        .info-card-head {
            background: linear-gradient(90deg, rgba(26,79,122,0.07), rgba(26,79,122,0.02));
            border-bottom: 1px solid #e5eef5;
            padding: 8px 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .info-card-head .head-title {
            font-weight: 700;
            font-size: 0.78rem;
            color: #1a4f7a;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }
        .info-card-body { padding: 14px 16px; }

        /* ── Field display ── */
        .field-group { margin-bottom: 0; }
        .field-label-sm {
            font-size: 0.68rem;
            font-weight: 600;
            color: #9ca3af;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 2px;
        }
        .field-value {
            font-size: 0.85rem;
            font-weight: 600;
            color: #111827;
        }
        .field-value.muted { color: #6b7280; font-weight: 400; }

        /* ── Tight uniform grid ── */
        .info-card-body .row { --bs-gutter-x: 0; --bs-gutter-y: 0; }
        .info-card-body .row > [class*="col-"] {
            padding: 7px 14px;
            border-bottom: 1px solid #f1f5f9;
        }
        .info-card-body .row > [class*="col-"]:last-child,
        .info-card-body .row > [class*="col-"]:nth-last-child(-n+3) {
            border-bottom: none;
        }

        /* ── Tag badges ── */
        .tag-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 0.72rem;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 20px;
        }

        /* ── Status pill ── */
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 0.76rem;
            font-weight: 700;
            padding: 5px 12px;
            border-radius: 20px;
        }

        /* ── Photo frame ── */
        .resident-photo {
            width: 120px;
            height: 150px;
            border-radius: 10px;
            object-fit: cover;
            border: 3px solid rgba(255,255,255,0.5);
            box-shadow: 0 4px 14px rgba(0,0,0,0.18);
        }
        .resident-photo-placeholder {
            width: 120px;
            height: 150px;
            border-radius: 10px;
            background: rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            border: 3px solid rgba(255,255,255,0.4);
        }

        /* ── Print styles ── */
        @media print {
            .no-print { display: none !important; }
            .sidebar, .topbar, .main-content > .no-print { display: none !important; }
            body { background: white !important; font-size: 11px !important; }
            .main-content { margin: 0 !important; padding: 0 !important; }
            .print-container { padding: 0 !important; }
            .info-card { box-shadow: none !important; border: 1px solid #ccc !important; }
            .profile-header-bar { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            .tag-badge, .status-pill { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            .print-header { display: block !important; }
            @page { margin: 15mm 12mm; }
        }

        /* Print-only header (hidden on screen) */
        .print-header { display: none; }

        /* ── Select2 skin ── */
        .select2-container--default .select2-selection--single {
            height: calc(1.5em + 0.75rem + 2px); padding: 0.375rem 0.75rem;
            font-size: 0.84rem; border: 1px solid #d0dce8; border-radius: 7px; line-height: 1.5;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered { line-height:1.5; padding-left:0; color:#212529; }
        .select2-container--default .select2-selection--single .select2-selection__arrow { height:100%; }
        .select2-container--default.select2-container--focus .select2-selection--single,
        .select2-container--default.select2-container--open  .select2-selection--single { border-color:#3a7fa8; box-shadow:0 0 0 3px rgba(58,127,168,0.12); outline:none; }
        .select2-dropdown { border-color:#3a7fa8; border-radius:7px; font-size:0.84rem; }
        .select2-container--default .select2-results__option--highlighted[aria-selected] { background-color:#1a4f7a; }
        .select2-search--dropdown .select2-search__field { border-radius:5px; border:1px solid #d0dce8; padding:6px 10px; font-size:0.84rem; }
        .select2-container { width:100% !important; }
        /* ── Household role cards ── */
        .hh-role-card { flex:1; min-width:120px; border:2px solid #dee2e6; border-radius:9px; padding:10px 12px; cursor:pointer; transition:all .2s; background:#fff; }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/sidebar_barangay.php'; ?>
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<div class="main-content">

    <!-- ── Breadcrumb / Back ── -->
    <div class="d-flex align-items-center mb-3 no-print">
        <a href="<?= BASE_URL ?>/barangay_admin/residents.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back to Residents
        </a>
    </div>

    <?php $hhFlash = getFlash('res_hh'); if ($hhFlash): ?>
    <div class="alert alert-<?= $hhFlash['type'] ?> alert-dismissible fade show no-print">
        <?= $hhFlash['message'] ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- ══ Printable Container ══ -->
    <div class="print-container" id="printArea">

        <!-- Print-only letterhead (hidden on screen) -->
        <div class="print-header mb-3 text-center">
            <div style="font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;">Republic of the Philippines</div>
            <div style="font-size:12px;">Province of <?= htmlspecialchars($brgy['province'] ?? '—') ?></div>
            <div style="font-size:12px;">Municipality of <?= htmlspecialchars($brgy['municipality'] ?? '—') ?></div>
            <div style="font-size:15px;font-weight:800;text-transform:uppercase;margin-top:4px;">
                Barangay <?= htmlspecialchars($brgy['barangay_name'] ?? '—') ?>
            </div>
            <div style="font-size:10px;color:#666;margin-top:2px;">Resident Information Sheet</div>
            <hr style="border-color:#ccc;margin:8px 0;">
        </div>

        <!-- ── Profile Header Banner ── -->
        <div class="profile-header-bar mb-4"
             style="background: linear-gradient(120deg,#1a4f7a 0%,#2d7db3 60%,#3a8fc7 100%);
                    border-radius: 14px; padding: 24px 28px; color: #fff;">
            <div class="d-flex align-items-center gap-4 flex-wrap">

                <!-- Photo -->
                <?php $photoPath = __DIR__ . '/../uploads/residents/' . ($res['photo'] ?? ''); ?>
                <?php if (!empty($res['photo']) && file_exists($photoPath)): ?>
                    <img src="<?= BASE_URL ?>/uploads/residents/<?= htmlspecialchars($res['photo']) ?>"
                         class="resident-photo" alt="<?= htmlspecialchars($displayName) ?>">
                <?php else: ?>
                    <div class="resident-photo-placeholder">
                        <i class="bi bi-person-fill" style="font-size:3rem;color:rgba(255,255,255,0.6);"></i>
                    </div>
                <?php endif; ?>

                <!-- Name + Status + Tags -->
                <div class="flex-fill" style="min-width:0;">
                    <div style="font-size:0.72rem;color:rgba(255,255,255,0.7);text-transform:uppercase;letter-spacing:.08em;">
                        Resident Profile
                    </div>
                    <h4 style="font-size:1.5rem;font-weight:800;margin:4px 0 8px;color:#fff;word-break:break-word;">
                        <?= htmlspecialchars($displayName) ?>
                    </h4>

                    <!-- Status -->
                    <?php
                    $statusColors = [
                        'Active'      => ['bg'=>'#d1fae5','color'=>'#065f46','dot'=>'#10b981'],
                        'Inactive'    => ['bg'=>'#f3f4f6','color'=>'#4b5563','dot'=>'#9ca3af'],
                        'Deceased'    => ['bg'=>'#fce7f3','color'=>'#831843','dot'=>'#ec4899'],
                        'Transferred' => ['bg'=>'#fef3c7','color'=>'#92400e','dot'=>'#f59e0b'],
                    ];
                    $sc = $statusColors[$res['resident_status']] ?? $statusColors['Active'];
                    ?>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span class="status-pill" style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>;">
                            <span style="width:8px;height:8px;border-radius:50%;background:<?= $sc['dot'] ?>;display:inline-block;"></span>
                            <?= htmlspecialchars($res['resident_status']) ?>
                        </span>

                        <?php if ($res['is_pwd']): ?>
                            <span class="tag-badge" style="background:rgba(243,232,255,0.9);color:#6d28d9;">
                                <i class="bi bi-person-wheelchair"></i> PWD
                            </span>
                        <?php endif; ?>
                        <?php if ($res['is_senior']): ?>
                            <span class="tag-badge" style="background:rgba(254,243,199,0.9);color:#92400e;">
                                <i class="bi bi-person-fill"></i> Senior Citizen
                            </span>
                        <?php endif; ?>
                        <?php if ($res['is_4ps']): ?>
                            <span class="tag-badge" style="background:rgba(209,250,229,0.9);color:#065f46;">
                                <i class="bi bi-people-fill"></i> 4Ps
                            </span>
                        <?php endif; ?>
                        <?php if ($res['is_solo_parent']): ?>
                            <span class="tag-badge" style="background:rgba(224,242,254,0.9);color:#0369a1;">
                                <i class="bi bi-person-hearts"></i> Solo Parent
                            </span>
                        <?php endif; ?>
                    </div>

                    <!-- Quick meta -->
                    <div class="d-flex gap-3 mt-2 flex-wrap" style="font-size:0.8rem;color:rgba(255,255,255,0.8);">
                        <span><i class="bi bi-calendar3 me-1"></i><?= $res['age'] ?> years old</span>
                        <span><i class="bi bi-<?= $res['gender']==='Male'?'gender-male':'gender-female' ?> me-1"></i><?= $res['gender'] ?></span>
                        <?php if (!empty($res['contact_number'])): ?>
                        <span><i class="bi bi-telephone-fill me-1"></i><?= htmlspecialchars($res['contact_number']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($res['email'])): ?>
                        <span><i class="bi bi-envelope-fill me-1"></i><?= htmlspecialchars($res['email']) ?></span>
                        <?php endif; ?>
                        <span><i class="bi bi-geo-alt-fill me-1"></i>
                            <?= htmlspecialchars($res['addr_barangay'] ?? ($brgy['barangay_name'] ?? '—')) ?>
                        </span>
                    </div>
                </div>

                <!-- Resident ID badge + Quick Actions -->
                <div style="text-align:center;flex-shrink:0;">
                    <div style="background:rgba(255,255,255,0.15);border-radius:10px;padding:12px 18px;border:1px solid rgba(255,255,255,0.25);">
                        <div style="font-size:0.65rem;color:rgba(255,255,255,0.65);text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px;">Resident ID</div>
                        <div style="font-size:1.4rem;font-weight:800;color:#fff;letter-spacing:.04em;">#<?= str_pad($id, 5, '0', STR_PAD_LEFT) ?></div>
                        <div style="font-size:0.7rem;color:rgba(255,255,255,0.6);margin-top:4px;">
                            Since <?= date('F d, Y', strtotime($res['created_at'])) ?>
                        </div>
                    </div>
                    <!-- Quick action icons -->
                    <div class="d-flex justify-content-center gap-2 mt-2 no-print">
                        <a href="<?= BASE_URL ?>/barangay_admin/edit_resident.php?id=<?= $id ?>"
                           title="Edit Profile"
                           style="width:34px;height:34px;border-radius:8px;background:rgba(255,255,255,0.18);border:1px solid rgba(255,255,255,0.3);display:flex;align-items:center;justify-content:center;color:#fff;text-decoration:none;transition:background .15s;"
                           onmouseover="this.style.background='rgba(255,255,255,0.3)'" onmouseout="this.style.background='rgba(255,255,255,0.18)'">
                            <i class="bi bi-pencil-fill" style="font-size:0.82rem;"></i>
                        </a>
                        <a href="<?= BASE_URL ?>/barangay_admin/certificates.php"
                           title="Issue Certificate"
                           style="width:34px;height:34px;border-radius:8px;background:rgba(255,255,255,0.18);border:1px solid rgba(255,255,255,0.3);display:flex;align-items:center;justify-content:center;color:#fff;text-decoration:none;transition:background .15s;"
                           onmouseover="this.style.background='rgba(255,255,255,0.3)'" onmouseout="this.style.background='rgba(255,255,255,0.18)'">
                            <i class="bi bi-file-earmark-plus-fill" style="font-size:0.82rem;"></i>
                        </a>
                        <button onclick="printProfile()" title="Print Profile"
                           style="width:34px;height:34px;border-radius:8px;background:rgba(255,255,255,0.18);border:1px solid rgba(255,255,255,0.3);display:flex;align-items:center;justify-content:center;color:#fff;cursor:pointer;transition:background .15s;"
                           onmouseover="this.style.background='rgba(255,255,255,0.3)'" onmouseout="this.style.background='rgba(255,255,255,0.18)'">
                            <i class="bi bi-printer-fill" style="font-size:0.82rem;"></i>
                        </button>
                        <button onclick="downloadExcel()" title="Download Excel"
                           style="width:34px;height:34px;border-radius:8px;background:rgba(255,255,255,0.18);border:1px solid rgba(255,255,255,0.3);display:flex;align-items:center;justify-content:center;color:#fff;cursor:pointer;transition:background .15s;"
                           onmouseover="this.style.background='rgba(255,255,255,0.3)'" onmouseout="this.style.background='rgba(255,255,255,0.18)'">
                            <i class="bi bi-file-earmark-excel-fill" style="font-size:0.82rem;"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Content Grid ── -->
        <div class="row g-2">

            <!-- ══ LEFT COLUMN ══ -->
            <div class="col-lg-8">

                <!-- Personal Information -->
                <div class="info-card">
                    <div class="info-card-head">
                        <i class="bi bi-person-fill" style="color:#1a4f7a;font-size:0.9rem;"></i>
                        <span class="head-title">Personal Information</span>
                    </div>
                    <div class="info-card-body">
                        <div class="row g-3">
                            <!-- Row 1: Name -->
                            <div class="col-sm-4 field-group">
                                <div class="field-label-sm">First Name</div>
                                <div class="field-value"><?= htmlspecialchars($res['first_name']) ?></div>
                            </div>
                            <div class="col-sm-4 field-group">
                                <div class="field-label-sm">Middle Name</div>
                                <div class="field-value <?= empty($res['middle_name']) ? 'muted' : '' ?>">
                                    <?= htmlspecialchars($res['middle_name'] ?: '—') ?>
                                </div>
                            </div>
                            <div class="col-sm-4 field-group">
                                <div class="field-label-sm">Last Name</div>
                                <div class="field-value"><?= htmlspecialchars($res['last_name']) ?></div>
                            </div>
                            <!-- Row 2: Suffix / Birthdate / Age -->
                            <div class="col-sm-4 field-group">
                                <div class="field-label-sm">Suffix</div>
                                <div class="field-value <?= empty($res['suffix']) ? 'muted' : '' ?>">
                                    <?= htmlspecialchars($res['suffix'] ?: '—') ?>
                                </div>
                            </div>
                            <div class="col-sm-4 field-group">
                                <div class="field-label-sm">Birthdate</div>
                                <div class="field-value"><?= date('F j, Y', strtotime($res['birthdate'])) ?></div>
                            </div>
                            <div class="col-sm-4 field-group">
                                <div class="field-label-sm">Age</div>
                                <div class="field-value"><?= $res['age'] ?> <span style="font-weight:400;color:#6b7280;font-size:0.8rem;">yrs</span></div>
                            </div>
                            <!-- Row 3: Gender / Civil Status / Contact -->
                            <div class="col-sm-4 field-group">
                                <div class="field-label-sm">Gender</div>
                                <div class="field-value d-flex align-items-center gap-1">
                                    <i class="bi <?= $res['gender']==='Male' ? 'bi-gender-male text-primary' : 'bi-gender-female text-danger' ?>"
                                       style="font-size:0.85rem;"></i>
                                    <?= $res['gender'] ?>
                                </div>
                            </div>
                            <div class="col-sm-4 field-group">
                                <div class="field-label-sm">Civil Status</div>
                                <?php
                                $civilColor = ['Single'=>'#6b7280','Married'=>'#059669','Widowed'=>'#7c3aed','Separated'=>'#d97706','Annulled'=>'#dc2626'];
                                $cc = $civilColor[$res['civil_status']] ?? '#6b7280';
                                ?>
                                <div class="field-value" style="color:<?= $cc ?>;">
                                    <?= htmlspecialchars($res['civil_status']) ?>
                                </div>
                            </div>
                            <div class="col-sm-4 field-group">
                                <div class="field-label-sm">Contact Number</div>
                                <div class="field-value <?= empty($res['contact_number']) ? 'muted' : '' ?>">
                                    <?php if (!empty($res['contact_number'])): ?>
                                        <a href="tel:<?= htmlspecialchars($res['contact_number']) ?>"
                                           style="color:#2563eb;text-decoration:none;" class="no-print">
                                            <i class="bi bi-telephone-fill me-1"></i><?= htmlspecialchars($res['contact_number']) ?>
                                        </a>
                                        <span class="d-none d-print-inline"><?= htmlspecialchars($res['contact_number']) ?></span>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-sm-4 field-group">
                                <div class="field-label-sm">Email Address</div>
                                <div class="field-value <?= empty($res['email']) ? 'muted' : '' ?>">
                                    <?php if (!empty($res['email'])): ?>
                                        <a href="mailto:<?= htmlspecialchars($res['email']) ?>"
                                           style="color:#2563eb;text-decoration:none;" class="no-print">
                                            <i class="bi bi-envelope-fill me-1"></i><?= htmlspecialchars($res['email']) ?>
                                        </a>
                                        <span class="d-none d-print-inline"><?= htmlspecialchars($res['email']) ?></span>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </div>
                            </div>
                            <!-- Row 4: Occupation / Voter Status -->
                            <div class="col-sm-4 field-group">
                                <div class="field-label-sm">Occupation</div>
                                <div class="field-value"><?= htmlspecialchars($res['occupation_status']) ?></div>
                            </div>
                            <div class="col-sm-4 field-group">
                                <div class="field-label-sm">Voter Status</div>
                                <?php if ($res['voter_status'] === 'Registered'): ?>
                                    <div class="field-value" style="color:#065f46;">
                                        <i class="bi bi-check-circle-fill me-1" style="color:#10b981;"></i>Registered
                                    </div>
                                <?php else: ?>
                                    <div class="field-value muted">
                                        <i class="bi bi-dash-circle me-1"></i>Not Registered
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($res['official_position'])): ?>
                            <div class="col-sm-4 field-group">
                                <div class="field-label-sm">Official Position</div>
                                <div class="field-value">
                                    <span style="background:#e0f2fb;color:#0d3a5c;font-size:0.78rem;font-weight:600;padding:3px 10px;border-radius:20px;display:inline-flex;align-items:center;gap:5px;">
                                        <i class="bi bi-person-badge-fill" style="font-size:0.75rem;"></i>
                                        <?= htmlspecialchars($res['official_position']) ?>
                                    </span>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="col-sm-4 field-group">
                                <!-- spacer to keep 3-col symmetry -->
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Address -->
                <div class="row g-2 align-items-stretch">
                <div class="col-lg-7">
                <div class="info-card h-100 mb-0">
                    <div class="info-card-head">
                        <i class="bi bi-geo-alt-fill" style="color:#1a4f7a;font-size:0.9rem;"></i>
                        <span class="head-title">Address</span>
                    </div>
                    <div class="info-card-body">
                        <div class="row g-3">
                            <div class="col-sm-4 field-group">
                                <div class="field-label-sm">Region</div>
                                <div class="field-value <?= empty($res['region']) ? 'muted' : '' ?>">
                                    <?= htmlspecialchars($res['region'] ?: '—') ?>
                                </div>
                            </div>
                            <div class="col-sm-4 field-group">
                                <div class="field-label-sm">Province</div>
                                <div class="field-value <?= empty($res['province']) ? 'muted' : '' ?>">
                                    <?= htmlspecialchars($res['province'] ?: '—') ?>
                                </div>
                            </div>
                            <div class="col-sm-4 field-group">
                                <div class="field-label-sm">City / Municipality</div>
                                <div class="field-value <?= empty($res['city']) ? 'muted' : '' ?>">
                                    <?= htmlspecialchars($res['city'] ?: '—') ?>
                                </div>
                            </div>
                            <div class="col-sm-4 field-group">
                                <div class="field-label-sm">Barangay</div>
                                <div class="field-value">
                                    <?= htmlspecialchars($res['addr_barangay'] ?: ($brgy['barangay_name'] ?? '—')) ?>
                                </div>
                            </div>
                            <div class="col-sm-4 field-group">
                                <div class="field-label-sm">Purok / Street / Zone</div>
                                <div class="field-value <?= empty($res['purok']) ? 'muted' : '' ?>">
                                    <?= htmlspecialchars(
                                        trim(implode(' ', array_filter([$res['purok'], $res['street'], $res['zone'] ? 'Zone '.$res['zone'] : ''])))
                                        ?: '—'
                                    ) ?>
                                </div>
                            </div>
                            <div class="col-sm-4 field-group">
                                <div class="field-label-sm">Zip Code</div>
                                <div class="field-value <?= empty($res['zip_code']) ? 'muted' : '' ?>">
                                    <?= htmlspecialchars($res['zip_code'] ?: '—') ?>
                                </div>
                            </div>
                        </div>

                        <!-- Full address line -->
                        <div class="mt-2 px-3 py-2" style="background:#f8fafc;border-radius:7px;border:1px solid #e5eef5;">
                            <div class="field-label-sm mb-1">Full Address</div>
                            <div style="font-size:0.83rem;color:#374151;font-weight:500;">
                                <i class="bi bi-pin-map-fill me-2" style="color:#1a4f7a;"></i>
                                <?= htmlspecialchars($fullAddress) ?>
                            </div>
                        </div>
                    </div>
                </div><!-- /address card -->
                </div><!-- /col-lg-7 -->

                <!-- Household Info (paired with Address) -->
                <div class="col-lg-5">
                <div class="info-card h-100 mb-0">
                    <div class="info-card-head">
                        <i class="bi bi-house-heart-fill" style="color:#1a4f7a;font-size:0.9rem;"></i>
                        <span class="head-title">Household</span>
                        <?php
                        $isHhHead = $household && ((int)$household['head_resident_id'] === $id);
                        if (!$isHhHead): ?>
                        <button type="button" class="ms-auto btn btn-sm no-print"
                                data-bs-toggle="modal" data-bs-target="#hhAssignModal"
                                style="padding:3px 10px;font-size:0.72rem;background:rgba(26,79,122,0.08);color:#1a4f7a;border:1px solid rgba(26,79,122,0.2);border-radius:6px;">
                            <i class="bi bi-<?= $household ? 'pencil-fill' : 'plus-circle-fill' ?> me-1"></i>
                            <?= $household ? 'Edit' : 'Assign' ?>
                        </button>
                        <?php endif; ?>
                    </div>
                    <div class="info-card-body">
                        <?php if ($household): ?>
                            <div class="d-flex align-items-center gap-3 mb-3">
                                <div style="width:40px;height:40px;border-radius:9px;background:#e0edff;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                    <i class="bi bi-house-fill" style="color:#1a4f7a;font-size:0.95rem;"></i>
                                </div>
                                <div>
                                    <div style="font-size:0.68rem;color:#9ca3af;text-transform:uppercase;letter-spacing:.04em;">Household Code</div>
                                    <div style="font-size:0.95rem;font-weight:800;color:#1a4f7a;"><?= htmlspecialchars($household['household_code']) ?></div>
                                </div>
                            </div>
                            <div class="row g-0">
                                <div class="col-6 field-group" style="padding:6px 10px 6px 0;border-bottom:1px solid #f1f5f9;">
                                    <div class="field-label-sm">Role</div>
                                    <div class="field-value" style="font-size:0.83rem;"><?= htmlspecialchars($household['relationship'] ?: '—') ?></div>
                                </div>
                                <div class="col-6 field-group" style="padding:6px 0 6px 10px;border-bottom:1px solid #f1f5f9;">
                                    <div class="field-label-sm">HH Status</div>
                                    <?php $hhSC=['Active'=>'#065f46','Inactive'=>'#6b7280','Archived'=>'#92400e']; $hhCC=$hhSC[$household['hh_status']]??'#6b7280'; ?>
                                    <div class="field-value" style="font-size:0.83rem;color:<?= $hhCC ?>;"><?= htmlspecialchars($household['hh_status']) ?></div>
                                </div>
                                <?php if (!empty($household['head_name']) && $household['relationship'] !== 'Head'): ?>
                                <div class="col-12 field-group" style="padding:6px 0;border-bottom:1px solid #f1f5f9;">
                                    <div class="field-label-sm">Head of Family</div>
                                    <div class="field-value" style="font-size:0.83rem;"><?= htmlspecialchars($household['head_name']) ?></div>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($household['income_range'])): ?>
                                <div class="col-12 field-group" style="padding:6px 0;border-bottom:1px solid #f1f5f9;">
                                    <div class="field-label-sm">Income Range</div>
                                    <div class="field-value" style="font-size:0.83rem;"><?= htmlspecialchars($household['income_range']) ?></div>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($household['length_of_stay'])): ?>
                                <div class="col-12 field-group" style="padding:6px 0;">
                                    <div class="field-label-sm">Length of Stay</div>
                                    <div class="field-value" style="font-size:0.83rem;"><?= htmlspecialchars($household['length_of_stay']) ?></div>
                                </div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-3">
                                <i class="bi bi-house" style="font-size:2rem;color:#d1d5db;display:block;margin-bottom:8px;"></i>
                                <div style="font-size:0.8rem;color:#9ca3af;">Not assigned to a household</div>
                                <button type="button" class="btn btn-sm btn-outline-primary mt-2 no-print"
                                        style="font-size:0.75rem;"
                                        data-bs-toggle="modal" data-bs-target="#hhAssignModal">
                                    <i class="bi bi-plus-circle me-1"></i>Assign Household
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                </div><!-- /col-lg-5 -->
                </div><!-- /address+household row -->
                <?php if ($res['resident_status'] === 'Transferred'): ?>
                <div class="info-card">
                    <div class="info-card-head">
                        <i class="bi bi-person-walking" style="color:#d97706;font-size:0.9rem;"></i>
                        <span class="head-title" style="color:#d97706;">Transfer Information</span>
                    </div>
                    <div class="info-card-body">
                        <div class="row g-3">
                            <div class="col-sm-6 field-group">
                                <div class="field-label-sm">Transferred From</div>
                                <div class="field-value"><?= htmlspecialchars($res['transfer_from_barangay'] ?: '—') ?></div>
                            </div>
                            <div class="col-sm-6 field-group">
                                <div class="field-label-sm">Transferred To</div>
                                <div class="field-value"><?= htmlspecialchars($res['transfer_to_barangay'] ?: '—') ?></div>
                            </div>
                            <div class="col-sm-6 field-group">
                                <div class="field-label-sm">Transfer Type</div>
                                <div class="field-value">
                                    <?= $res['transfer_outside'] ? 'Outside Barangay' : 'Local Transfer' ?>
                                </div>
                            </div>
                            <?php if (!empty($res['transfer_reason'])): ?>
                            <div class="col-sm-6 field-group">
                                <div class="field-label-sm">Reason</div>
                                <div class="field-value"><?= htmlspecialchars($res['transfer_reason']) ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Certificates -->
                <?php if (!empty($certificates)): ?>
                <div class="info-card">
                    <div class="info-card-head">
                        <i class="bi bi-file-earmark-text-fill" style="color:#1a4f7a;font-size:0.9rem;"></i>
                        <span class="head-title">Certificates Issued</span>
                        <span class="ms-auto badge" style="background:#e0edff;color:#1a4f7a;font-size:0.68rem;font-weight:600;border-radius:20px;padding:3px 9px;">
                            <?= $totalCertCount ?><?= $totalCertCount > 10 ? ' total' : '' ?>
                        </span>
                    </div>
                    <div class="info-card-body p-0">
                        <div class="table-responsive">
                            <table class="table mb-0" style="font-size:0.82rem;">
                                <thead>
                                    <tr style="background:#f9fafb;border-bottom:1px solid #eaecf0;">
                                        <th style="padding:10px 16px;border:none;font-size:0.7rem;font-weight:600;color:#6b7280;text-transform:uppercase;">Type</th>
                                        <th style="padding:10px 16px;border:none;font-size:0.7rem;font-weight:600;color:#6b7280;text-transform:uppercase;">Purpose</th>
                                        <th style="padding:10px 16px;border:none;font-size:0.7rem;font-weight:600;color:#6b7280;text-transform:uppercase;">Date Issued</th>
                                        <th style="padding:10px 16px;border:none;font-size:0.7rem;font-weight:600;color:#6b7280;text-transform:uppercase;">Issued By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($certificates as $cert): ?>
                                    <tr style="border-bottom:1px solid #f3f4f6;">
                                        <td style="padding:10px 16px;border:none;font-weight:600;color:#1a4f7a;">
                                            <?= htmlspecialchars($cert['certificate_type']) ?>
                                        </td>
                                        <td style="padding:10px 16px;border:none;color:#6b7280;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                            <?= htmlspecialchars($cert['purpose'] ?: '—') ?>
                                        </td>
                                        <td style="padding:10px 16px;border:none;white-space:nowrap;color:#374151;">
                                            <?= $cert['date_issued'] ? date('M d, Y', strtotime($cert['date_issued'])) : '—' ?>
                                        </td>
                                        <td style="padding:10px 16px;border:none;color:#6b7280;">
                                            <?= htmlspecialchars($cert['issued_by_name'] ?: '—') ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($totalCertCount > 10): ?>
                        <div class="px-3 py-2 text-center" style="font-size:0.72rem;color:#9ca3af;border-top:1px solid #f1f5f9;">
                            Showing last 10 of <?= $totalCertCount ?> total certificates
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

            </div><!-- /col-lg-8 -->

            <!-- ══ RIGHT COLUMN ══ -->
            <div class="col-lg-4">

                <!-- Special Categories -->
                <div class="info-card">
                    <div class="info-card-head">
                        <i class="bi bi-tags-fill" style="color:#1a4f7a;font-size:0.9rem;"></i>
                        <span class="head-title">Special Categories</span>
                    </div>
                    <div class="info-card-body">
                        <?php
                        $categories = [
                            ['label'=>'4Ps Beneficiary','flag'=>$res['is_4ps'],    'icon'=>'bi-people-fill',         'bg'=>'#d1fae5','color'=>'#065f46'],
                            ['label'=>'PWD',             'flag'=>$res['is_pwd'],    'icon'=>'bi-person-wheelchair',   'bg'=>'#f3e8ff','color'=>'#6d28d9'],
                            ['label'=>'Senior Citizen',  'flag'=>$res['is_senior'], 'icon'=>'bi-person-fill',         'bg'=>'#fef3c7','color'=>'#92400e'],
                            ['label'=>'Solo Parent',     'flag'=>$res['is_solo_parent'],'icon'=>'bi-person-hearts',  'bg'=>'#e0f2fe','color'=>'#0369a1'],
                        ];
                        foreach ($categories as $cat): ?>
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div style="width:36px;height:36px;border-radius:9px;
                                        background:<?= $cat['flag'] ? $cat['bg'] : '#f3f4f6' ?>;
                                        display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                <i class="bi <?= $cat['icon'] ?>"
                                   style="color:<?= $cat['flag'] ? $cat['color'] : '#d1d5db' ?>;font-size:0.9rem;"></i>
                            </div>
                            <div>
                                <div style="font-size:0.83rem;font-weight:600;color:<?= $cat['flag'] ? '#111827' : '#9ca3af' ?>;">
                                    <?= $cat['label'] ?>
                                </div>
                                <div style="font-size:0.71rem;color:<?= $cat['flag'] ? $cat['color'] : '#d1d5db' ?>;">
                                    <?= $cat['flag'] ? 'Yes — qualified' : 'Not applicable' ?>
                                </div>
                            </div>
                            <div class="ms-auto">
                                <?php if ($cat['flag']): ?>
                                    <i class="bi bi-check-circle-fill" style="color:#10b981;font-size:1rem;"></i>
                                <?php else: ?>
                                    <i class="bi bi-dash-circle" style="color:#d1d5db;font-size:1rem;"></i>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- ── Certificate Request Count Card ── -->
                <div class="info-card">
                    <div class="info-card-head">
                        <i class="bi bi-file-earmark-check-fill" style="color:#1a4f7a;font-size:0.9rem;"></i>
                        <span class="head-title">Certificate Requests</span>
                    </div>
                    <div class="info-card-body">

                        <!-- Big counter -->
                        <div class="text-center py-2 mb-3"
                             style="background:linear-gradient(135deg,#e0edff 0%,#f0f7ff 100%);
                                    border-radius:10px;border:1px solid #c5d9f5;">
                            <div style="font-size:2.4rem;font-weight:900;color:#1a4f7a;line-height:1;">
                                <?= $totalCertCount ?>
                            </div>
                            <div style="font-size:0.7rem;color:#4b7cad;font-weight:600;text-transform:uppercase;letter-spacing:.06em;margin-top:2px;">
                                Total Certificate<?= $totalCertCount !== 1 ? 's' : '' ?> Issued
                            </div>
                            <?php if ($lastCertIssued): ?>
                            <div style="font-size:0.7rem;color:#6b7280;margin-top:4px;">
                                <i class="bi bi-clock-history me-1"></i>Last: <?= date('M d, Y', strtotime($lastCertIssued)) ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($certByType)): ?>
                        <!-- Per-type breakdown -->
                        <div style="font-size:0.68rem;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px;">
                            Breakdown by Type
                        </div>
                        <?php foreach ($certByType as $ct): ?>
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <div style="width:28px;height:28px;border-radius:7px;background:#e0edff;
                                        display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                <i class="bi bi-file-text" style="color:#1a4f7a;font-size:0.75rem;"></i>
                            </div>
                            <div class="flex-fill" style="min-width:0;">
                                <div style="font-size:0.78rem;font-weight:600;color:#374151;
                                            white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                    <?= htmlspecialchars($ct['certificate_type']) ?>
                                </div>
                                <?php $pct = $totalCertCount > 0 ? round(($ct['cnt'] / $totalCertCount) * 100) : 0; ?>
                                <div style="height:3px;background:#e5e7eb;border-radius:4px;margin-top:3px;">
                                    <div style="height:3px;width:<?= $pct ?>%;background:#1a4f7a;border-radius:4px;"></div>
                                </div>
                            </div>
                            <span style="font-size:0.78rem;font-weight:700;color:#1a4f7a;flex-shrink:0;">
                                <?= $ct['cnt'] ?>x
                            </span>
                        </div>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <div class="text-center py-2">
                            <i class="bi bi-file-earmark" style="font-size:1.6rem;color:#d1d5db;display:block;margin-bottom:6px;"></i>
                            <div style="font-size:0.78rem;color:#9ca3af;">No certificates issued yet</div>
                        </div>
                        <?php endif; ?>

                        <!-- Issue new link -->
                        <div class="mt-3 no-print">
                            <a href="<?= BASE_URL ?>/barangay_admin/certificates.php"
                               class="btn btn-sm w-100"
                               style="background:#1a4f7a;color:#fff;font-size:0.78rem;font-weight:600;border-radius:7px;">
                                <i class="bi bi-plus-circle me-1"></i>Issue New Certificate
                            </a>
                        </div>
                    </div>
                </div>

            </div><!-- /col-lg-4 -->


        </div><!-- /row -->

        <!-- Print footer -->
        <div class="print-header mt-4 pt-3" style="border-top:1px solid #ccc;">
            <div class="row">
                <div class="col-6">
                    <div style="font-size:10px;color:#555;">
                        Printed by: <?= htmlspecialchars($u['name']) ?><br>
                        Date Printed: <?= date('F j, Y h:i A') ?><br>
                        Resident ID: #<?= str_pad($id, 5, '0', STR_PAD_LEFT) ?>
                    </div>
                </div>
                <div class="col-6 text-end">
                    <div style="font-size:10px;color:#555;">
                        Barangay <?= htmlspecialchars($brgy['barangay_name'] ?? '') ?><br>
                        <?= htmlspecialchars($brgy['municipality'] ?? '') ?><br>
                        Official Document — Centralized LGU IMS
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /print-container -->

</div><!-- /main-content -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<!-- ── Household Assignment Modal ─────────────────────────────────────── -->
<div class="modal fade" id="hhAssignModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered" style="max-width:520px;">
        <div class="modal-content" style="border-radius:14px;overflow:hidden;">
            <div class="modal-header" style="background:linear-gradient(90deg,#1a4f7a,#2d7db3);border:none;padding:14px 20px;">
                <h6 class="modal-title text-white fw-bold mb-0">
                    <i class="bi bi-house-heart-fill me-2"></i>Household Assignment
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="assign_household">

                <div class="modal-body p-4" style="padding-bottom:220px !important;overflow-y:auto;max-height:70vh;">

                    <?php if ($household): ?>
                    <div class="alert alert-success d-flex align-items-center gap-2 py-2 mb-3" style="font-size:0.8rem;">
                        <i class="bi bi-house-check-fill fs-5"></i>
                        <div>
                            Currently in <strong><?= htmlspecialchars($household['household_code']) ?></strong>
                            <?= $household['head_name'] ? ' (Head: ' . htmlspecialchars($household['head_name']) . ')' : '' ?>
                            — <strong><?= htmlspecialchars($household['relationship']) ?></strong>
                        </div>
                    </div>
                    <div class="text-muted mb-3" style="font-size:0.75rem;">
                        <i class="bi bi-info-circle me-1"></i>
                        To change the <strong>Head of Family</strong>, go to
                        <a href="<?= BASE_URL ?>/barangay_admin/households.php" target="_blank">Household Management</a>.
                    </div>
                    <?php else: ?>
                    <div class="alert alert-warning d-flex align-items-center gap-2 py-2 mb-3" style="font-size:0.8rem;">
                        <i class="bi bi-house-x-fill fs-5"></i>
                        <div>This resident is <strong>not assigned</strong> to any household.</div>
                    </div>
                    <?php endif; ?>

                    <!-- Role cards -->
                    <div class="d-flex gap-2 flex-wrap mb-3">
                        <!-- Family Member -->
                        <label class="hh-role-card <?= $household ? '' : '' ?>" id="hhRoleMemberCard"
                               onclick="hhSetRole('member')"
                               style="<?= $household ? 'border-color:#198754;background:rgba(25,135,84,0.05);' : '' ?>">
                            <input type="radio" name="household_role" id="hhRoleMember" value="member"
                                   class="form-check-input me-1 m-0" style="vertical-align:middle;"
                                   <?= $household ? 'checked' : '' ?>>
                            <i class="bi bi-people-fill text-success me-1"></i>
                            <strong style="font-size:0.83rem;">Family Member</strong>
                            <div class="text-muted" style="font-size:0.7rem;margin-top:1px;">Link to a household</div>
                        </label>
                        <!-- Keep current (only if already in one) -->
                        <?php if ($household): ?>
                        <label class="hh-role-card" id="hhRoleKeepCard" onclick="hhSetRole('')">
                            <input type="radio" name="household_role" id="hhRoleKeep" value=""
                                   class="form-check-input me-1 m-0" style="vertical-align:middle;">
                            <i class="bi bi-arrow-repeat text-secondary me-1"></i>
                            <strong style="font-size:0.83rem;">Keep Current</strong>
                            <div class="text-muted" style="font-size:0.7rem;margin-top:1px;">No change</div>
                        </label>
                        <!-- Remove -->
                        <label class="hh-role-card" id="hhRoleNoneCard" onclick="hhSetRole('none')">
                            <input type="radio" name="household_role" id="hhRoleNone" value="none"
                                   class="form-check-input me-1 m-0" style="vertical-align:middle;">
                            <i class="bi bi-x-circle text-danger me-1"></i>
                            <strong style="font-size:0.83rem;">Remove</strong>
                            <div class="text-muted" style="font-size:0.7rem;margin-top:1px;">Unlink from household</div>
                        </label>
                        <?php endif; ?>
                    </div>

                    <!-- Member fields -->
                    <div id="hhModalMemberFields" style="display:<?= $household ? '' : 'none' ?>;">
                        <div style="background:#f8fbff;border:1px solid #d0e8f5;border-radius:9px;padding:14px;">
                            <div class="fw-semibold text-success mb-2" style="font-size:0.82rem;">
                                <i class="bi bi-link-45deg me-1"></i>Link to Household
                            </div>
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="field-label" style="font-size:0.78rem;font-weight:600;color:#374151;margin-bottom:4px;display:block;">
                                        Relationship to Head <span style="color:#dc2626;">*</span>
                                    </label>
                                    <select name="hh_member_relationship" id="hhModalRelationship" class="form-select">
                                        <option value="">— Select —</option>
                                        <?php
                                        $relationships = ['Spouse','Son','Daughter','Father','Mother','Brother','Sister',
                                            'Grandfather','Grandmother','Grandson','Granddaughter','Uncle','Aunt',
                                            'Nephew','Niece','Cousin','In-Law','Boarder','Helper','Other'];
                                        $curRelationship = $household['relationship'] ?? '';
                                        foreach ($relationships as $rel): ?>
                                        <option value="<?= $rel ?>" <?= $curRelationship === $rel ? 'selected' : '' ?>><?= $rel ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="field-label" style="font-size:0.78rem;font-weight:600;color:#374151;margin-bottom:4px;display:block;">
                                        Select Household <span style="color:#dc2626;">*</span>
                                    </label>
                                    <select name="hh_member_household_id" id="hhModalHouseholdSelect" class="form-select">
                                        <option value="">— Select household —</option>
                                        <?php foreach ($activeHouseholds as $hh): ?>
                                        <option value="<?= $hh['id'] ?>"
                                            <?= ($household && (int)$household['household_id'] === (int)$hh['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($hh['household_code']) ?>
                                            <?= $hh['head_name'] ? ' — ' . htmlspecialchars($hh['head_name']) : '' ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (empty($activeHouseholds)): ?>
                                    <div class="text-warning small mt-1"><i class="bi bi-exclamation-triangle me-1"></i>No active households found.</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Remove warning -->
                    <div id="hhModalRemoveWarn" style="display:none;" class="mt-3">
                        <div class="alert alert-danger py-2 mb-0" style="font-size:0.8rem;">
                            <i class="bi bi-exclamation-triangle-fill me-1"></i>
                            This will remove the resident from their current household. This action can be undone by re-assigning them.
                        </div>
                    </div>

                </div>
                <div class="modal-footer border-0 pt-0 gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary fw-semibold px-4"
                            style="background:#1a4f7a;border-color:#1a4f7a;font-size:0.85rem;">
                        <i class="bi bi-check-circle-fill me-1"></i>Save Assignment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// ── Household modal role toggle ────────────────────────────────────────────
function hhSetRole(role) {
    const rMember = document.getElementById('hhRoleMember');
    const rKeep   = document.getElementById('hhRoleKeep');
    const rNone   = document.getElementById('hhRoleNone');
    if (rMember) rMember.checked = (role === 'member');
    if (rKeep)   rKeep.checked   = (role === '');
    if (rNone)   rNone.checked   = (role === 'none');

    const cMember = document.getElementById('hhRoleMemberCard');
    const cKeep   = document.getElementById('hhRoleKeepCard');
    const cNone   = document.getElementById('hhRoleNoneCard');
    if (cMember) { cMember.style.borderColor = role==='member' ? '#198754' : '#dee2e6'; cMember.style.background = role==='member' ? 'rgba(25,135,84,0.05)' : '#fff'; }
    if (cKeep)   { cKeep.style.borderColor   = role===''       ? '#1a4f7a' : '#dee2e6'; cKeep.style.background   = role===''       ? 'rgba(26,79,122,0.05)' : '#fff'; }
    if (cNone)   { cNone.style.borderColor   = role==='none'   ? '#dc3545' : '#dee2e6'; cNone.style.background   = role==='none'   ? 'rgba(220,53,69,0.05)' : '#fff'; }

    document.getElementById('hhModalMemberFields').style.display = role==='member' ? '' : 'none';
    const removeWarn = document.getElementById('hhModalRemoveWarn');
    if (removeWarn) removeWarn.style.display = role==='none' ? '' : 'none';
}

// ── Select2 on modal household dropdown ──────────────────────────────────
$(document).ready(function () {
    $('#hhModalHouseholdSelect').select2({
        placeholder: '— Type surname or household code —',
        allowClear: true,
        width: '100%',
        dropdownParent: $('#hhAssignModal'),
        matcher: function (params, data) {
            if ($.trim(params.term) === '') return data;
            if (!data.text) return null;
            return data.text.toUpperCase().indexOf(params.term.toUpperCase()) > -1 ? data : null;
        }
    });
    $('#hhModalRelationship').select2({
        placeholder: '— Select —',
        allowClear: false,
        width: '100%',
        dropdownParent: $('#hhAssignModal'),
        minimumResultsForSearch: Infinity
    });
});
</script>
<script>
/* html2pdf and xlsx are loaded on demand — only when the user clicks the button */
var _html2pdfLoaded = false;
var _xlsxLoaded = false;

function loadScript(src) {
    return new Promise(function(resolve, reject) {
        var s = document.createElement('script');
        s.src = src;
        s.onload = resolve;
        s.onerror = reject;
        document.head.appendChild(s);
    });
}

function printProfile() {
    window.print();
}

function downloadPDF() {
    const btn = event.currentTarget;
    const origIcon = btn.innerHTML;
    btn.innerHTML = '<i class="bi bi-hourglass-split" style="font-size:0.82rem;"></i>';
    btn.disabled = true;

    var ready = _html2pdfLoaded
        ? Promise.resolve()
        : loadScript('https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js')
              .then(function() { _html2pdfLoaded = true; });

    ready.then(function() {
        document.querySelectorAll('.print-header').forEach(el => el.style.display = 'block');
        document.querySelectorAll('.no-print').forEach(el => el.style.display = 'none');

        const element = document.getElementById('printArea');
        const filename = 'Resident_Profile_<?= preg_replace('/[^a-zA-Z0-9]/', '_', $displayName) ?>_<?= date('Ymd') ?>.pdf';

        const opt = {
            margin:       [12, 10, 12, 10],
            filename:     filename,
            image:        { type: 'jpeg', quality: 0.98 },
            html2canvas:  { scale: 2, useCORS: true, logging: false },
            jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' },
            pagebreak:    { mode: ['avoid-all', 'css', 'legacy'] }
        };

        html2pdf().set(opt).from(element).save().then(() => {
            document.querySelectorAll('.print-header').forEach(el => el.style.display = '');
            document.querySelectorAll('.no-print').forEach(el => el.style.display = '');
            btn.innerHTML = origIcon;
            btn.disabled = false;
        });
    });
}

function downloadExcel() {
    var ready = _xlsxLoaded
        ? Promise.resolve()
        : loadScript('https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js')
              .then(function() { _xlsxLoaded = true; });

    ready.then(function() {
    const wb = XLSX.utils.book_new();

    /* ── Sheet 1: Personal Information ── */
    const personal = [
        ['RESIDENT PROFILE', ''],
        ['Barangay', <?= json_encode($brgy['barangay_name'] ?? '') ?>],
        [''],
        ['PERSONAL INFORMATION', ''],
        ['Resident ID',     '#<?= str_pad($id, 5, '0', STR_PAD_LEFT) ?>'],
        ['First Name',      <?= json_encode($res['first_name']) ?>],
        ['Middle Name',     <?= json_encode($res['middle_name'] ?: '—') ?>],
        ['Last Name',       <?= json_encode($res['last_name']) ?>],
        ['Suffix',          <?= json_encode($res['suffix'] ?: '—') ?>],
        ['Birthdate',       <?= json_encode(date('F j, Y', strtotime($res['birthdate']))) ?>],
        ['Age',             <?= (int)$res['age'] ?>],
        ['Gender',          <?= json_encode($res['gender']) ?>],
        ['Civil Status',    <?= json_encode($res['civil_status']) ?>],
        ['Contact Number',  <?= json_encode($res['contact_number'] ?: '—') ?>],
        ['Occupation',      <?= json_encode($res['occupation_status']) ?>],
        ['Voter Status',    <?= json_encode($res['voter_status']) ?>],
        ['Resident Status', <?= json_encode($res['resident_status']) ?>],
        [''],
        ['SPECIAL CATEGORIES', ''],
        ['4Ps Beneficiary', <?= json_encode($res['is_4ps'] ? 'Yes' : 'No') ?>],
        ['PWD',             <?= json_encode($res['is_pwd'] ? 'Yes' : 'No') ?>],
        ['Senior Citizen',  <?= json_encode($res['is_senior'] ? 'Yes' : 'No') ?>],
        ['Solo Parent',     <?= json_encode($res['is_solo_parent'] ? 'Yes' : 'No') ?>],
        [''],
        ['ADDRESS', ''],
        ['Region',              <?= json_encode($res['region'] ?: '—') ?>],
        ['Province',            <?= json_encode($res['province'] ?: '—') ?>],
        ['City / Municipality', <?= json_encode($res['city'] ?: '—') ?>],
        ['Barangay',            <?= json_encode($res['addr_barangay'] ?: ($brgy['barangay_name'] ?? '—')) ?>],
        ['Purok / Street / Zone', <?= json_encode(trim(implode(' ', array_filter([$res['purok'] ?? '', $res['street'] ?? '', $res['zone'] ? 'Zone '.$res['zone'] : '']))) ?: '—') ?>],
        ['Zip Code',            <?= json_encode($res['zip_code'] ?: '—') ?>],
        ['Full Address',        <?= json_encode($fullAddress) ?>],
        [''],
        ['HOUSEHOLD', ''],
        <?php if ($household): ?>
        ['Household Code',   <?= json_encode($household['household_code']) ?>],
        ['Role',             <?= json_encode($household['relationship'] ?: '—') ?>],
        ['HH Status',        <?= json_encode($household['hh_status']) ?>],
        ['Head of Family',   <?= json_encode($household['head_name'] ?: '—') ?>],
        ['Income Range',     <?= json_encode($household['income_range'] ?: '—') ?>],
        ['Length of Stay',   <?= json_encode($household['length_of_stay'] ?: '—') ?>],
        <?php else: ?>
        ['Household', 'Not assigned'],
        <?php endif; ?>
        [''],
        ['RECORD', ''],
        ['Date Registered',  <?= json_encode(date('F j, Y', strtotime($res['created_at']))) ?>],
        ['Time Registered',  <?= json_encode(date('h:i A', strtotime($res['created_at']))) ?>],
    ];

    const ws1 = XLSX.utils.aoa_to_sheet(personal);
    ws1['!cols'] = [{ wch: 24 }, { wch: 40 }];
    XLSX.utils.book_append_sheet(wb, ws1, 'Profile');

    /* ── Sheet 2: Certificates ── */
    <?php if (!empty($certificates)): ?>
    const certs = [
        ['Certificate Type', 'Purpose', 'Date Issued', 'Issued By'],
        <?php foreach ($certificates as $cert): ?>
        [
            <?= json_encode($cert['certificate_type']) ?>,
            <?= json_encode($cert['purpose'] ?: '—') ?>,
            <?= json_encode($cert['date_issued'] ? date('F j, Y', strtotime($cert['date_issued'])) : '—') ?>,
            <?= json_encode($cert['issued_by_name'] ?: '—') ?>
        ],
        <?php endforeach; ?>
    ];
    const ws2 = XLSX.utils.aoa_to_sheet(certs);
    ws2['!cols'] = [{ wch: 22 }, { wch: 30 }, { wch: 16 }, { wch: 22 }];
    XLSX.utils.book_append_sheet(wb, ws2, 'Certificates');
    <?php endif; ?>

    XLSX.writeFile(wb, 'Resident_<?= preg_replace('/[^a-zA-Z0-9]/', '_', $displayName) ?>_<?= date('Ymd') ?>.xlsx');
    }); // end ready.then
}
</script>
</body>
</html>
