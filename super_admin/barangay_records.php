<?php
require_once __DIR__ . '/../config/config.php';
requireRole('super_admin');

$db = Database::getInstance()->getConnection();
$u  = currentUser();

$selectedId = (int)($_GET['barangay_id'] ?? 0);

// All active barangays for picker
$allBarangays = $db->query("SELECT id, barangay_name, municipality FROM barangays WHERE status='active' ORDER BY barangay_name")->fetchAll();

// Stats — zero if no barangay selected, accurate if one is picked
$brgy  = null;
$stats = ['residents'=>0,'households'=>0,'officials'=>0,'reports'=>0,'certificates'=>0,'transfers'=>0];

if ($selectedId) {
    $brgyStmt = $db->prepare("SELECT * FROM barangays WHERE id=? AND status='active'");
    $brgyStmt->execute([$selectedId]);
    $brgy = $brgyStmt->fetch();

    if ($brgy) {
        $stats = [
            'residents'    => (int)$db->query("SELECT COUNT(*) FROM residents   WHERE barangay_id=$selectedId AND resident_status='Active'")->fetchColumn(),
            'households'   => (int)$db->query("SELECT COUNT(*) FROM households  WHERE barangay_id=$selectedId")->fetchColumn(),
            'officials'    => (int)$db->query("SELECT COUNT(*) FROM officials   WHERE barangay_id=$selectedId AND status='active'")->fetchColumn(),
            'reports'      => (int)$db->query("SELECT COUNT(*) FROM reports     WHERE barangay_id=$selectedId")->fetchColumn(),
            'certificates' => (int)$db->query("SELECT COUNT(*) FROM certificates WHERE barangay_id=$selectedId")->fetchColumn(),
            'transfers'    => (int)$db->query("SELECT COUNT(*) FROM resident_transfer_requests WHERE origin_barangay_id=$selectedId OR destination_barangay_id=$selectedId")->fetchColumn(),
        ];
    }
}

$pageTitle = 'Barangay Records';
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
</head>
<body>
<?php include __DIR__ . '/../includes/sidebar_super.php'; ?>
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<div class="main-content">

    <!-- Header -->
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <div>
            <h4 class="fw-bold mb-0" style="color:#1a4f7a;">
                <i class="bi bi-journal-bookmark-fill me-2"></i>Barangay Records
            </h4>
            <p class="text-muted small mb-0">
                <?= $brgy ? 'Showing records for <strong>'.htmlspecialchars($brgy['barangay_name']).'</strong>' : 'Select a barangay to view its records' ?>
            </p>
        </div>
    </div>

    <!-- Barangay Picker -->
    <div style="background:#fff;border-radius:14px;box-shadow:0 2px 12px rgba(0,0,0,0.07);border:1px solid #e0f0f8;padding:18px 24px;margin-bottom:20px;">
        <form method="GET" class="d-flex gap-2 align-items-center flex-wrap">
            <i class="bi bi-geo-alt-fill" style="color:#0369a1;font-size:1.1rem;"></i>
            <select name="barangay_id" class="form-select" style="max-width:300px;font-weight:600;border-color:#b0d4ec;"
                    onchange="this.form.submit()">
                <option value="">— Select Barangay —</option>
                <?php foreach ($allBarangays as $b): ?>
                    <option value="<?= $b['id'] ?>" <?= $selectedId == $b['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($b['barangay_name']) ?><?= $b['municipality'] ? ' — '.htmlspecialchars($b['municipality']) : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($selectedId): ?>
                <a href="?" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x me-1"></i>Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Stat Cards -->
    <div class="row g-3 mb-4">
        <?php
        $statCards = [
            ['label'=>'Active Residents', 'value'=>$stats['residents'],    'color'=>'#059669','bg'=>'#d1fae5','icon'=>'bi-people-fill'],
            ['label'=>'Households',       'value'=>$stats['households'],   'color'=>'#7c3aed','bg'=>'#ede9fe','icon'=>'bi-house-fill'],
            ['label'=>'Active Officials', 'value'=>$stats['officials'],    'color'=>'#d97706','bg'=>'#fef3c7','icon'=>'bi-person-badge-fill'],
            ['label'=>'Reports',          'value'=>$stats['reports'],      'color'=>'#dc2626','bg'=>'#fee2e2','icon'=>'bi-file-earmark-bar-graph-fill'],
            ['label'=>'Certificates',     'value'=>$stats['certificates'], 'color'=>'#0891b2','bg'=>'#cffafe','icon'=>'bi-award-fill'],
            ['label'=>'Transfers',        'value'=>$stats['transfers'],    'color'=>'#7c3aed','bg'=>'#ede9fe','icon'=>'bi-arrow-left-right'],
        ];
        foreach ($statCards as $sc): ?>
        <div class="col">
            <div style="background:#fff;border-radius:14px;padding:16px 18px;box-shadow:0 2px 10px rgba(0,0,0,0.06);border:1px solid #e8f4fb;text-align:center;min-width:120px;">
                <div style="width:40px;height:40px;border-radius:10px;background:<?= $sc['bg'] ?>;display:flex;align-items:center;justify-content:center;margin:0 auto 8px;">
                    <i class="bi <?= $sc['icon'] ?>" style="font-size:1.1rem;color:<?= $sc['color'] ?>;"></i>
                </div>
                <div style="font-size:1.6rem;font-weight:800;color:<?= $sc['color'] ?>;line-height:1;"><?= number_format($sc['value']) ?></div>
                <div style="font-size:0.68rem;color:#64748b;margin-top:3px;"><?= $sc['label'] ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Submodule Cards -->
    <h6 class="fw-semibold mb-3" style="color:#1a4f7a;font-size:0.8rem;text-transform:uppercase;letter-spacing:0.07em;">
        <i class="bi bi-grid-fill me-1"></i>
        <?= $brgy ? 'Records of '.htmlspecialchars($brgy['barangay_name']) : 'Submodules' ?>
    </h6>
    <div class="row g-3">
        <?php
        $modules = [
            ['icon'=>'bi-people-fill',                 'color'=>'#059669','bg'=>'#d1fae5','title'=>'Residents',          'count'=>$stats['residents'],    'label'=>'residents',    'file'=>'barangay_records_residents.php'],
            ['icon'=>'bi-house-fill',                  'color'=>'#7c3aed','bg'=>'#ede9fe','title'=>'Households',         'count'=>$stats['households'],   'label'=>'households',   'file'=>'barangay_records_households.php'],
            ['icon'=>'bi-person-badge-fill',           'color'=>'#d97706','bg'=>'#fef3c7','title'=>'Barangay Officials', 'count'=>$stats['officials'],    'label'=>'officials',    'file'=>'barangay_records_officials.php'],
            ['icon'=>'bi-file-earmark-bar-graph-fill', 'color'=>'#dc2626','bg'=>'#fee2e2','title'=>'Reports',            'count'=>$stats['reports'],      'label'=>'reports',      'file'=>'barangay_records_reports.php'],
            ['icon'=>'bi-award-fill',                  'color'=>'#0891b2','bg'=>'#cffafe','title'=>'Certificates',       'count'=>$stats['certificates'], 'label'=>'certificates', 'file'=>'barangay_records_certificates.php'],
            ['icon'=>'bi-arrow-left-right',            'color'=>'#7c3aed','bg'=>'#ede9fe','title'=>'Resident Transfers', 'count'=>$stats['transfers'],    'label'=>'transfers',    'file'=>'barangay_records_transfers.php'],
        ];
        foreach ($modules as $m):
            $href = $selectedId
                ? BASE_URL.'/super_admin/'.$m['file'].'?barangay_id='.$selectedId
                : '#';
        ?>
        <div class="col-md-4 col-sm-6">
            <?= $selectedId ? '<a href="'.htmlspecialchars($href).'" style="text-decoration:none;">' : '<div>' ?>
            <div style="background:#fff;border-radius:16px;padding:22px 20px;box-shadow:0 2px 12px rgba(0,0,0,0.06);border:1px solid #e8f4fb;transition:transform 0.15s,box-shadow 0.15s;<?= $selectedId ? 'cursor:pointer;' : 'opacity:0.65;' ?>"
                 <?= $selectedId ? 'onmouseover="this.style.transform=\'translateY(-3px)\';this.style.boxShadow=\'0 8px 24px rgba(0,0,0,0.10)\'" onmouseout="this.style.transform=\'\';this.style.boxShadow=\'0 2px 12px rgba(0,0,0,0.06)\'"' : '' ?>>
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div style="width:46px;height:46px;border-radius:12px;background:<?= $m['bg'] ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="bi <?= $m['icon'] ?>" style="font-size:1.3rem;color:<?= $m['color'] ?>;"></i>
                    </div>
                    <div>
                        <div class="fw-bold" style="color:#1a4f7a;font-size:0.95rem;"><?= $m['title'] ?></div>
                        <span style="background:<?= $m['bg'] ?>;color:<?= $m['color'] ?>;font-size:0.72rem;font-weight:700;padding:1px 8px;border-radius:20px;">
                            <?= number_format($m['count']) ?> <?= $m['label'] ?>
                        </span>
                    </div>
                </div>
                <div class="d-flex align-items-center justify-content-end">
                    <?php if ($selectedId): ?>
                    <span style="color:<?= $m['color'] ?>;font-size:0.8rem;font-weight:600;">
                        View Records <i class="bi bi-arrow-right-short"></i>
                    </span>
                    <?php else: ?>
                    <span style="color:#94a3b8;font-size:0.78rem;">Select a barangay first</span>
                    <?php endif; ?>
                </div>
            </div>
            <?= $selectedId ? '</a>' : '</div>' ?>
        </div>
        <?php endforeach; ?>
    </div>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
