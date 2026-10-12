<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/mailer.php';
requireRole('barangay_admin');
$db  = Database::getInstance()->getConnection();
$u   = currentUser();
$bid = (int)$u['barangay_id'];

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

$resId = (int)($_GET['resident_id'] ?? 0);
$res   = null;
if ($resId) {
    $rs = $db->prepare("SELECT r.*, a.purok, a.street, a.barangay as addr_barangay, a.city FROM residents r LEFT JOIN addresses a ON r.address_id=a.id WHERE r.id=? AND r.barangay_id=?");
    $rs->execute([$resId, $bid]);
    $res = $rs->fetch();
}

// Count how many certificates have already been issued to this resident
$resCertCount    = 0;
$resCertLast     = null;
$resCertTypeList = [];
if ($resId) {
    $ccStmt = $db->prepare("SELECT COUNT(*) as total, MAX(date_issued) as last_issued FROM certificates WHERE resident_id = ? AND barangay_id = ?");
    $ccStmt->execute([$resId, $bid]);
    $ccRow = $ccStmt->fetch();
    $resCertCount = (int)($ccRow['total'] ?? 0);
    $resCertLast  = $ccRow['last_issued'] ?? null;

    $ctStmt = $db->prepare("SELECT certificate_type, COUNT(*) as cnt FROM certificates WHERE resident_id = ? AND barangay_id = ? GROUP BY certificate_type ORDER BY cnt DESC");
    $ctStmt->execute([$resId, $bid]);
    $resCertTypeList = $ctStmt->fetchAll();
}
$resName  = $res ? strtoupper($res['last_name'].', '.$res['first_name'].($res['middle_name']?' '.$res['middle_name']:'')) : '';
$resPurok = $res['purok'] ?? '';
$resAge   = $res['age'] ?? '';
$resCivil = $res['civil_status'] ?? '';
$resSex   = $res['gender'] ?? '';
$resBday  = $res ? date('F d, Y', strtotime($res['birthdate'])) : '';
$dayNum   = date('j');
$monthStr = date('F');
$yearStr  = date('Y');
$today    = date('F d, Y');
$BRGY     = htmlspecialchars(strtoupper($brgyName));
$SEC      = htmlspecialchars($secretary);
$PUNO     = htmlspecialchars($punong);
$L1       = BASE_URL.'/assets/img/logo 1.jpg';
$L2       = BASE_URL.'/assets/img/logo 2.jpg';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrAbort();
    $type    = $_POST['cert_type'] ?? '';
    $purpose = sanitize($_POST['purpose'] ?? '');
    $rId     = (int)($_POST['resident_id'] ?? 0);

    // Normalise cert key → canonical display name (must match certificates.php $certTypes)
    $certKeyToLabel = [
        'clearance'  => 'Clearance',
        'indigency'  => 'Indigency',
        'residency'  => 'Residency',
        'livein'     => 'Live-in',
        'sameperson' => 'Same Person',
        'business'   => 'Business Permit',
        'barc'       => 'BARC Certificate',
        'soloparent' => 'Solo Parent',
        'lowincome'  => 'Proof of Low Income',
    ];
    if (isset($certKeyToLabel[$type])) {
        $type = $certKeyToLabel[$type];
    }
    if ($rId && $type) {
        $stmt = $db->prepare("INSERT INTO certificates (barangay_id,resident_id,certificate_type,purpose,issued_by,date_issued) VALUES (?,?,?,?,?,?)");
        $stmt->execute([$bid, $rId, $type, $purpose, $u['id'], date('Y-m-d')]);
        $rname = $db->prepare("SELECT CONCAT(first_name,' ',last_name) FROM residents WHERE id=?");
        $rname->execute([$rId]);
        $rname = $rname->fetchColumn() ?: "resident #$rId";
        logActivity($u['id'], 'ISSUE_CERTIFICATE', 'Certificates', "Issued $type certificate for $rname");

        // Bell notification — issuing admin + all peers in the same barangay
        $certNotifMsg = "📜 <strong>$type</strong> certificate issued for <strong>$rname</strong>";
        sendNotification($u['id'], $certNotifMsg, 'success', 'certificate');
        $peers = $db->prepare("SELECT id FROM users WHERE role='barangay_admin' AND barangay_id=? AND status='active' AND id != ?");
        $peers->execute([$bid, $u['id']]);
        foreach ($peers->fetchAll(PDO::FETCH_ASSOC) as $peer) {
            sendNotification((int)$peer['id'], $certNotifMsg, 'success', 'certificate');
        }

        // Email confirmation to the issuing admin
        if ($u['email']) {
            sendCertificateIssuedEmail($u['email'], $u['name'], $rname, $type, $purpose, $brgyName);
        }
    }
    echo "<script>if(window.opener)window.opener.location.reload();window.close();</script>";
    exit;
}

function wave() {
    return '<div class="footer-wave"><svg viewBox="0 0 720 200" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">
<path d="M0,120 C80,80 180,150 300,110 C420,70 520,140 720,100 L720,200 L0,200 Z" fill="#a5d6a7" opacity="0.6"/>
<path d="M0,140 C100,100 220,160 360,125 C480,95 600,155 720,120 L720,200 L0,200 Z" fill="#6ab04c"/>
<path d="M0,158 C60,130 160,170 280,148 C400,126 500,165 620,145 C680,135 710,150 720,148 L720,200 L0,200 Z" fill="#4a7c2f"/>
<path d="M0,172 C80,155 200,182 340,165 C460,150 580,178 720,162 L720,200 L0,200 Z" fill="#2e6b0e"/>
</svg></div>';
}
function hdr($L1,$L2,$BRGY,$id) {
    return '<div class="header">
<img class="logo" src="'.$L1.'" alt="" onerror="this.style.visibility=\'hidden\'">
<div class="header-text">Republika ng Pilipinas<br>Lalawigan ng Nueva Ecija<br>Bayan ng TALAVERA<br>
<span class="barangay-name">BARANGAY <input class="ef brgy-hdr" id="'.$id.'" type="text" value="'.$BRGY.'"></span></div>
<img class="logo" src="'.$L2.'" alt="" onerror="this.style.visibility=\'hidden\'">
</div><div class="tanggapan">TANGGAPAN NG PUNONG BARANGAY</div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favcon.png">
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favcon.png">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Issue Certificate</title>
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;-webkit-print-color-adjust:exact;print-color-adjust:exact}
body{font-family:'Times New Roman',Times,serif;background:#f0f0f0;display:flex;flex-direction:column;align-items:center;padding:20px;gap:16px}
.selector-panel{background:#fff;border:1px solid #ccc;border-radius:10px;padding:16px 20px;width:210mm;box-shadow:0 2px 8px rgba(0,0,0,.12);font-family:Arial,sans-serif}
.selector-panel h2{font-size:15px;color:#2e6b0e;border-bottom:2px solid #4a7c2f;padding-bottom:8px;margin-bottom:12px;text-align:center;letter-spacing:1px}
.cert-buttons{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px}
.cert-btn{padding:9px 12px;border:2px solid #4a7c2f;border-radius:6px;background:#fff;color:#2e6b0e;font-size:12px;font-weight:bold;cursor:pointer;text-align:center;transition:all .2s;font-family:Arial,sans-serif}
.cert-btn:hover{background:#e8f5e0}.cert-btn.active{background:#4a7c2f;color:#fff}
.toolbar{background:#fff;border:1px solid #ccc;border-radius:8px;padding:14px 20px;width:210mm;display:grid;grid-template-columns:1fr 1fr;gap:10px 20px;font-family:Arial,sans-serif;font-size:13px;box-shadow:0 2px 6px rgba(0,0,0,.1)}
.toolbar h3{grid-column:1/-1;font-size:14px;color:#2e6b0e;border-bottom:1px solid #ccc;padding-bottom:6px;margin-bottom:4px}
.field-group{display:flex;flex-direction:column;gap:3px}
.field-group label{font-weight:bold;color:#444;font-size:12px}
.field-group input{border:1px solid #bbb;border-radius:4px;padding:5px 8px;font-size:13px;font-family:'Times New Roman',serif;outline:none}
.field-group input:focus{border-color:#4a7c2f}
.toolbar-actions{grid-column:1/-1;display:flex;gap:10px;margin-top:4px}
.btn{padding:7px 18px;border:none;border-radius:5px;cursor:pointer;font-size:13px;font-weight:bold}
.btn-primary{background:#4a7c2f;color:#fff}.btn-primary:hover{background:#2e6b0e}
.btn-secondary{background:#eee;color:#333}.btn-secondary:hover{background:#ddd}
.btn-danger{background:#c62828;color:#fff}.btn-danger:hover{background:#b71c1c}
.certificate{background:#fff;width:210mm;height:297mm;padding:10mm 15mm 0 15mm;border:2px solid #4a7c2f;position:relative;overflow:hidden;display:flex;flex-direction:column}
.corner{position:absolute;width:20px;height:20px;border-color:#4a7c2f;border-style:solid}
.corner.tl{top:8px;left:8px;border-width:2px 0 0 2px}.corner.tr{top:8px;right:8px;border-width:2px 2px 0 0}
.corner.bl{bottom:8px;left:8px;border-width:0 0 2px 2px}.corner.br{bottom:8px;right:8px;border-width:0 2px 2px 0}
.header{display:flex;align-items:center;justify-content:space-between;padding-bottom:10px;border-bottom:3px solid #4a7c2f}
.logo{width:80px;height:80px;object-fit:contain}
.header-text{text-align:center;flex:1;font-size:13px;line-height:1.6}
.header-text .barangay-name{font-family:'Arial Black',Arial,sans-serif;font-size:15px;font-weight:900;color:#2e6b0e}
.tanggapan{text-align:center;font-family:Arial,sans-serif;font-size:15px;font-style:italic;font-weight:bold;color:#2e6b0e;letter-spacing:2px;padding:10px 0 6px;border-bottom:4px solid #4a7c2f}
.title-section{display:flex;align-items:center;justify-content:center;gap:12px;padding:12px 0 8px}
.dots-icon{font-size:22px;color:#555}
.cert-title{font-family:'Arial Black',Arial,sans-serif;font-size:18px;font-weight:900;color:#222}
.body{padding:6px 0;font-size:13px;line-height:1.8}
.body p{text-align:justify;margin-bottom:10px}.body p.indent{text-indent:50px}
.ef{border:none;border-bottom:1px solid #000;outline:none;font-family:'Times New Roman',serif;font-size:13px;background:transparent;text-align:center;padding:0 2px;min-width:40px}
.ef:focus{border-bottom:2px solid #4a7c2f;background:#f0fff0}
.ef.sm{min-width:50px;width:60px}.ef.md{min-width:110px}.ef.lg{min-width:160px}.ef.xl{min-width:200px}
.ef.brgy-hdr{min-width:130px;font-weight:900;font-family:'Arial Black',Arial,sans-serif;font-size:15px}
.ef.block-field{width:100%;border:none;border-bottom:1px solid #000;text-align:center;font-size:14px;outline:none;background:transparent}
.footer-wave{margin-top:auto;margin-left:-15mm;margin-right:-15mm;height:50mm;overflow:hidden;position:relative;flex-shrink:0;background:linear-gradient(to bottom,#fff 0%,#e8f5e0 40%,#c5e8a0 100%)}
.footer-wave svg{position:absolute;bottom:0;left:0;width:100%;height:100%}
.info-section{display:flex;gap:20px;margin:10px 0;align-items:flex-start}
.photo-box{width:100px;min-height:120px;border:1.5px solid #000;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:11px;color:#aaa;cursor:pointer;position:relative;overflow:hidden}
.photo-box img{position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover}
.photo-box input[type=file]{display:none}
.info-table{font-size:12px;line-height:2;flex:1}
.info-table table{border-collapse:collapse;width:100%}
.info-table td{padding:1px 4px;vertical-align:bottom}
.info-table td:first-child{font-weight:bold;white-space:nowrap;width:120px}
.info-table td.colon{width:14px;text-align:center}
.info-table .divider-row td{border-top:1px solid #000;padding-top:4px}
.if{display:inline-block;min-width:150px;border-bottom:1px solid #000;outline:none;background:transparent;font-family:'Times New Roman',serif;font-size:12px}
.if:focus{background:#f0fff0}
.if-sm{display:inline-block;min-width:70px;border-bottom:1px solid #000;outline:none;background:transparent;font-family:'Times New Roman',serif;font-size:12px}
.if-sm:focus{background:#f0fff0}
.bottom-section{display:flex;justify-content:space-between;align-items:flex-start;margin-top:8px;font-size:12px}
.left-col{width:200px}.right-col{width:300px}
.sig-dashed{border-bottom:1px dashed #000;width:170px;margin-bottom:3px}
.thumbmark-row{display:flex;gap:8px;margin-top:8px}
.thumb-box{width:70px;height:75px;border:1.5px solid #000;display:flex;align-items:center;justify-content:center;font-size:10px;color:#888}
.note-text{font-size:10px;margin-top:8px;font-style:italic}
.prepared-label{font-weight:bold;font-size:12px;margin-bottom:22px}
.certified-label{font-weight:bold;font-size:12px;margin:14px 0 22px}
.sig-block{text-align:center;margin-bottom:6px}
.sig-block .sig-line{display:block;width:210px;border-top:1.5px solid #000;margin:0 auto 3px}
.sig-block .sig-name{font-weight:bold;font-size:12px}
.sig-row{display:flex;justify-content:space-between;margin-top:20px;font-size:13px}
.sig-col{display:flex;flex-direction:column}
.sig-label{font-style:italic;margin-bottom:26px}
.sig-line{border-top:1.5px solid #000;width:200px;margin-bottom:4px}
.sig-name{font-size:13px;text-align:center;width:200px}
.sig-section{margin-top:20px;font-size:13px}
.sig-center{text-align:center;margin-top:24px;font-size:13px}
.personal-title{text-align:center;font-weight:bold;font-size:14px;margin:12px 0 8px;text-decoration:underline}
.details-block{font-size:13px;line-height:2}
.detail-row{display:flex;align-items:baseline;gap:4px;flex-wrap:wrap}
.applicant-sig{text-align:right;font-size:13px;margin-top:14px;margin-bottom:16px}
.applicant-sig .sig-line{display:inline-block;width:200px;border-top:1.5px solid #000;margin-bottom:3px}
.date-block{text-align:right;margin-bottom:14px;font-size:14px;line-height:2}
.date-center{text-align:center;font-weight:bold;font-size:14px;margin-bottom:12px}
.field-block{text-align:center;margin:12px auto 4px;width:320px}
.field-block .field-label{font-size:12px}
.name-list{margin:6px 0 12px 50px;font-size:14px;line-height:2.2}
.name-list li{list-style:decimal;margin-left:10px}
.cert-subtitle{font-size:13px;font-style:italic;margin-bottom:14px}
.to-whom{font-weight:bold;margin-bottom:12px}
.signatures{margin-top:20px;font-size:13px}
.sig-cp{font-size:12px}
.noted{font-weight:bold;font-size:13px;margin-bottom:30px}
.sig-printed{margin-top:20px;font-size:13px}
.sig-printed .sig-line{display:block;width:240px;border-top:1.5px solid #000;margin-bottom:3px}
.sig-right{text-align:right;margin-top:24px;font-size:13px}
.sig-right .sig-label-r{font-style:italic;margin-bottom:28px}
.sig-right .sig-line-r{display:inline-block;width:200px;border-top:1.5px solid #000;margin-bottom:4px}
.cert-section{display:none}.cert-section.active{display:block}
.toolbar-section{display:none}.toolbar-section.active{display:grid}
@media print{
  @page{size:A4 portrait;margin:0}
  html,body{margin:0;padding:0;background:#fff;width:210mm}
  .selector-panel,.toolbar{display:none!important}
  .cert-section.active{display:block!important}
  .cert-section.active .certificate{width:210mm;height:297mm;border:none;padding:10mm 15mm 0 15mm;margin:0;overflow:hidden;box-shadow:none;display:flex;flex-direction:column}
  .cert-section.active .footer-wave{margin-left:-15mm;margin-right:-15mm;height:50mm;margin-top:auto;flex-shrink:0}
  .ef,.if,.if-sm{border-bottom:1px solid #000!important;background:transparent!important}
  .corner{display:none!important}
}
</style>
</head>
<body>

<!-- SELECTOR -->
<div class="selector-panel">
  <h2>📄 BARANGAY CERTIFICATE SYSTEM — <?= $BRGY ?></h2>

  <?php if ($res): ?>
  <!-- Resident info + cert count strip -->
  <div style="display:flex;align-items:center;gap:14px;background:#f0f7ff;border:1px solid #c5d9f5;border-radius:8px;padding:10px 14px;margin-bottom:12px;font-family:Arial,sans-serif;">
    <div style="width:36px;height:36px;border-radius:50%;background:#1a4f7a;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
      <span style="color:#fff;font-size:1rem;">👤</span>
    </div>
    <div style="flex:1;min-width:0;">
      <div style="font-size:13px;font-weight:700;color:#1a4f7a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
        <?= htmlspecialchars($resName) ?>
      </div>
      <?php if ($resCertLast): ?>
      <div style="font-size:11px;color:#6b7280;margin-top:1px;">
        Last issued: <?= date('M d, Y', strtotime($resCertLast)) ?>
      </div>
      <?php endif; ?>
    </div>
    <!-- Total count bubble -->
    <div style="text-align:center;background:<?= $resCertCount > 0 ? '#1a4f7a' : '#e5e7eb' ?>;color:<?= $resCertCount > 0 ? '#fff' : '#9ca3af' ?>;border-radius:10px;padding:6px 14px;flex-shrink:0;">
      <div style="font-size:1.4rem;font-weight:900;line-height:1;"><?= $resCertCount ?></div>
      <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;margin-top:1px;">
        cert<?= $resCertCount !== 1 ? 's' : '' ?> issued
      </div>
    </div>
  </div>
  <?php if (!empty($resCertTypeList)): ?>
  <!-- Per-type pills -->
  <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px;">
    <?php foreach ($resCertTypeList as $ct): ?>
    <span style="display:inline-flex;align-items:center;gap:4px;background:#e0edff;color:#1a4f7a;border-radius:20px;padding:3px 10px;font-size:11px;font-weight:600;font-family:Arial,sans-serif;">
      <?= htmlspecialchars($ct['certificate_type']) ?>
      <span style="background:#1a4f7a;color:#fff;border-radius:10px;padding:0px 6px;font-size:10px;"><?= $ct['cnt'] ?>×</span>
    </span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <div class="cert-buttons">
    <button class="cert-btn active" onclick="showCert('clearance',this)">Barangay Clearance</button>
    <button class="cert-btn" onclick="showCert('indigency',this)">Certificate of Indigency</button>
    <button class="cert-btn" onclick="showCert('residency',this)">Certificate of Residency</button>
    <button class="cert-btn" onclick="showCert('livein',this)">Certificate of Live-in</button>
    <button class="cert-btn" onclick="showCert('sameperson',this)">Certificate of Same Person</button>
    <button class="cert-btn" onclick="showCert('business',this)">Business Permit</button>
    <button class="cert-btn" onclick="showCert('barc',this)">BARC Certificate</button>
    <button class="cert-btn" onclick="showCert('soloparent',this)">Certificate for Solo Parent</button>
    <button class="cert-btn" onclick="showCert('lowincome',this)">Proof of Low Income</button>
  </div>
</div>

<!-- ══ 1. CLEARANCE ══ -->
<div class="toolbar toolbar-section active" id="tb-clearance">
  <h3>✏️ Barangay Clearance</h3>
  <div class="field-group"><label>Full Name</label><input id="cl-f-name" value="<?= htmlspecialchars($resName) ?>"></div>
  <div class="field-group"><label>Issued at Barangay</label><input id="cl-f-ibrgy" value="<?= $BRGY ?>"></div>
  <div class="field-group"><label>Day</label><input id="cl-f-day" value="<?= $dayNum ?>"></div>
  <div class="field-group"><label>Month</label><input id="cl-f-month" value="<?= $monthStr ?>"></div>
  <div class="field-group"><label>Year</label><input id="cl-f-year" value="<?= $yearStr ?>"></div>
  <div class="field-group"><label>Purpose</label><input id="cl-f-purpose" placeholder="e.g. Employment"></div>
  <div class="field-group"><label>Gender</label><input id="cl-f-gender" value="<?= htmlspecialchars($resSex) ?>"></div>
  <div class="field-group"><label>Age</label><input id="cl-f-age" value="<?= htmlspecialchars($resAge) ?>"></div>
  <div class="field-group"><label>Date of Birth</label><input id="cl-f-dob" value="<?= htmlspecialchars($resBday) ?>"></div>
  <div class="field-group"><label>Place of Birth</label><input id="cl-f-pob" placeholder="e.g. Talavera, NE"></div>
  <div class="field-group"><label>Purok</label><input id="cl-f-purok" value="<?= htmlspecialchars($resPurok) ?>"></div>
  <div class="field-group"><label>Civil Status</label><input id="cl-f-civil" value="<?= htmlspecialchars($resCivil) ?>"></div>
  <div class="field-group"><label>TCT No.</label><input id="cl-f-tct" placeholder="e.g. 12345"></div>
  <div class="field-group"><label>Issued At</label><input id="cl-f-issued-at" placeholder="e.g. RD Nueva Ecija"></div>
  <div class="field-group"><label>Issued On</label><input id="cl-f-issued-on" value="<?= $today ?>"></div>
  <div class="field-group"><label>Barangay Secretary</label><input id="cl-f-sec" value="<?= $SEC ?>"></div>
  <div class="field-group"><label>Secretary CP #</label><input id="cl-f-sec-cp" placeholder="09XX-XXX-XXXX"></div>
  <div class="field-group"><label>Punong Barangay</label><input id="cl-f-puno" value="<?= $PUNO ?>"></div>
  <div class="field-group"><label>Punong Barangay CP #</label><input id="cl-f-puno-cp" placeholder="09XX-XXX-XXXX"></div>
  <div class="toolbar-actions">
    <button class="btn btn-primary" onclick="applyFields('clearance')">Apply</button>
    <button class="btn btn-secondary" onclick="clearFields('clearance')">Clear</button>
    <button class="btn btn-secondary" onclick="window.print()">🖨️ Print</button>
    <button class="btn btn-danger" onclick="window.close()">✕ Close</button>
  </div>
</div>
<div class="cert-section active" id="cert-clearance"><div class="certificate">
  <div class="corner tl"></div><div class="corner tr"></div><div class="corner bl"></div><div class="corner br"></div>
  <?= hdr($L1,$L2,$BRGY,'cl-c-brgy') ?>
  <div class="title-section"><span class="dots-icon">⠿</span><div class="cert-title">BARANGAY CERTIFICATION/CLEARANCE</div></div>
  <div class="body">
    <p><strong>TO WHOM IT MAY CONCERN:</strong></p>
    <p class="indent">This is to certify that <input class="ef lg" id="cl-c-name" type="text">, is a <u>bonafide</u> resident of this Barangay. He/She is personally known to me to be a <u>peaceful</u> and law-abiding citizen of this community with good moral character.</p>
    <p class="indent">He/She has not been involved any crimes or illegal activities and is not a member of any subversive elements. This clearance is valid only for ninety (90) days from the date of issuance.</p>
    <p class="indent">Issued at Barangay <input class="ef md" id="cl-c-ibrgy" type="text">, Talavera Nueva Ecija this <input class="ef sm" id="cl-c-day" type="text"> day of <input class="ef md" id="cl-c-month" type="text">, <input class="ef sm" id="cl-c-year" type="text" style="width:60px"> upon request of subject person for <input class="ef md" id="cl-c-purpose" type="text">, purposes.</p>
  </div>
  <div class="info-section">
    <div class="photo-box" onclick="document.getElementById('photo-upload').click()">
      <input type="file" id="photo-upload" accept="image/*" onchange="loadPhoto(event)">
      <img id="photo-preview" src="" alt="" style="display:none">
      <span id="photo-label" style="text-align:center">2x2<br>Photo<br><span style="font-size:10px;color:#4a7c2f">Click to upload</span></span>
    </div>
    <div class="info-table"><table>
      <tr><td>GENDER</td><td class="colon">:</td><td><input class="if-sm" id="cl-c-gender"></td></tr>
      <tr><td>AGE</td><td class="colon">:</td><td><input class="if-sm" id="cl-c-age"></td></tr>
      <tr><td>DATE OF BIRTH</td><td class="colon">:</td><td><input class="if" id="cl-c-dob"></td></tr>
      <tr><td>PLACE OF BIRTH</td><td class="colon">:</td><td><input class="if" id="cl-c-pob"></td></tr>
      <tr><td>PUROK</td><td class="colon">:</td><td><input class="if-sm" id="cl-c-purok"></td></tr>
      <tr><td>CIVIL STATUS</td><td class="colon">:</td><td><input class="if-sm" id="cl-c-civil"></td></tr>
      <tr class="divider-row"><td>TCT NO.</td><td class="colon">:</td><td><input class="if-sm" id="cl-c-tct"></td></tr>
      <tr><td>ISSUED AT</td><td class="colon">:</td><td><input class="if" id="cl-c-issued-at"></td></tr>
      <tr><td>ISSUED ON</td><td class="colon">:</td><td><input class="if" id="cl-c-issued-on"></td></tr>
    </table></div>
  </div>
  <div class="bottom-section">
    <div class="left-col">
      <div class="sig-dashed">&nbsp;</div><div style="font-size:12px">Signature</div>
      <div class="thumbmark-row"><div class="thumb-box">LEFT</div><div class="thumb-box">RIGHT</div></div>
      <div class="note-text">NOTE: valid only in 90 days from the date issued.<br>Not valid without Barangay Dry Seal.</div>
    </div>
    <div class="right-col">
      <div class="prepared-label">PREPARED BY:</div>
      <div class="sig-block"><span class="sig-line"></span><div><input class="ef lg" id="cl-c-sec"></div><div class="sig-name">Barangay Secretary</div><div style="font-size:12px">CP # <input class="if-sm" id="cl-c-sec-cp"></div></div>
      <div class="certified-label">CERTIFIED BY:</div>
      <div class="sig-block"><span class="sig-line"></span><div><input class="ef lg" id="cl-c-puno"></div><div class="sig-name">Punong Barangay</div><div style="font-size:12px">CP # <input class="if-sm" id="cl-c-puno-cp"></div></div>
    </div>
  </div>
  <?= wave() ?>
</div></div>

<!-- ══ 2. INDIGENCY ══ -->
<div class="toolbar toolbar-section" id="tb-indigency">
  <h3>✏️ Certificate of Indigency</h3>
  <div class="field-group"><label>Full Name</label><input id="in-f-name" value="<?= htmlspecialchars($resName) ?>"></div>
  <div class="field-group"><label>Age</label><input id="in-f-age" value="<?= htmlspecialchars($resAge) ?>"></div>
  <div class="field-group"><label>Purok #</label><input id="in-f-purok" value="<?= htmlspecialchars($resPurok) ?>"></div>
  <div class="field-group"><label>Barangay</label><input id="in-f-brgy" value="<?= $BRGY ?>"></div>
  <div class="field-group"><label>Purpose</label><input id="in-f-purpose" placeholder="e.g. Medical Assistance"></div>
  <div class="field-group"><label>Day</label><input id="in-f-day" value="<?= $dayNum ?>"></div>
  <div class="field-group"><label>Month</label><input id="in-f-month" value="<?= $monthStr ?>"></div>
  <div class="field-group"><label>Year</label><input id="in-f-year" value="<?= $yearStr ?>"></div>
  <div class="field-group"><label>Issued at Barangay</label><input id="in-f-ibrgy" value="<?= $BRGY ?>"></div>
  <div class="field-group"><label>Barangay Secretary</label><input id="in-f-sec" value="<?= $SEC ?>"></div>
  <div class="field-group"><label>Secretary CP #</label><input id="in-f-sec-cp" placeholder="09XX-XXX-XXXX"></div>
  <div class="field-group"><label>Punong Barangay</label><input id="in-f-puno" value="<?= $PUNO ?>"></div>
  <div class="field-group"><label>Punong Barangay CP #</label><input id="in-f-puno-cp" placeholder="09XX-XXX-XXXX"></div>
  <div class="toolbar-actions">
    <button class="btn btn-primary" onclick="applyFields('indigency')">Apply</button>
    <button class="btn btn-secondary" onclick="clearFields('indigency')">Clear</button>
    <button class="btn btn-secondary" onclick="window.print()">🖨️ Print</button>
    <button class="btn btn-danger" onclick="window.close()">✕ Close</button>
  </div>
</div>
<div class="cert-section" id="cert-indigency"><div class="certificate">
  <div class="corner tl"></div><div class="corner tr"></div><div class="corner bl"></div><div class="corner br"></div>
  <?= hdr($L1,$L2,$BRGY,'in-c-brgy') ?>
  <div class="title-section"><span class="dots-icon">⠿</span><div class="cert-title" style="text-decoration:underline">BARANGAY CERTIFICATE OF INDIGENCY</div></div>
  <div class="body">
    <div class="to-whom">TO WHOM IT MAY CONCERN:</div>
    <p class="indent">This is to CERTIFY that <input class="ef lg" id="in-c-name">, <input class="ef sm" id="in-c-age"> of age, Filipino, and a resident of Purok <input class="ef sm" id="in-c-purok">, Barangay <input class="ef md" id="in-c-brgy">, Municipality of Talavera, Province of Nueva Ecija, is known to this office as a person belonging to an indigent family.</p>
    <p class="indent">Based on records and verification conducted by this barangay, the above-named individual has insufficient or no regular source of income and is therefore considered financially incapable of meeting basic needs.<br>This certification is issued upon the request of the above-named person for <input class="ef md" id="in-c-purpose">, and for whatever legal purpose it may serve.</p>
    <p class="indent">Issued this <input class="ef sm" id="in-c-day"> day of <input class="ef md" id="in-c-month">, <input class="ef sm" id="in-c-year" style="width:60px"> at Barangay <input class="ef md" id="in-c-ibrgy">, Talavera, Nueva Ecija.</p>
  </div>
  <div class="signatures">
    <div style="margin-bottom:40px"><div class="sig-line"></div><div style="font-weight:bold"><input class="ef lg" id="in-c-sec"></div><div style="font-size:12px">Barangay Secretary</div><div class="sig-cp">CP # <input class="if-sm" id="in-c-sec-cp"></div></div>
    <div class="noted">Noted and Approved by:</div>
    <div><div class="sig-line"></div><div style="font-weight:bold"><input class="ef lg" id="in-c-puno"></div><div style="font-size:12px">Punong Barangay</div><div class="sig-cp">CP # <input class="if-sm" id="in-c-puno-cp"></div></div>
  </div>
  <?= wave() ?>
</div></div>

<!-- ══ 3. RESIDENCY ══ -->
<div class="toolbar toolbar-section" id="tb-residency">
  <h3>✏️ Certificate of Residency</h3>
  <div class="field-group"><label>Full Name</label><input id="re-f-name" value="<?= htmlspecialchars($resName) ?>"></div>
  <div class="field-group"><label>Age</label><input id="re-f-age" value="<?= htmlspecialchars($resAge) ?>"></div>
  <div class="field-group"><label>Purok #</label><input id="re-f-purok" value="<?= htmlspecialchars($resPurok) ?>"></div>
  <div class="field-group"><label>Barangay</label><input id="re-f-brgy" value="<?= $BRGY ?>"></div>
  <div class="field-group"><label>Day</label><input id="re-f-day" value="<?= $dayNum ?>"></div>
  <div class="field-group"><label>Month</label><input id="re-f-month" value="<?= $monthStr ?>"></div>
  <div class="field-group"><label>Year</label><input id="re-f-year" value="<?= $yearStr ?>"></div>
  <div class="field-group"><label>Issued at Barangay</label><input id="re-f-ibrgy" value="<?= $BRGY ?>"></div>
  <div class="field-group"><label>Civil Status</label><input id="re-f-civil" value="<?= htmlspecialchars($resCivil) ?>"></div>
  <div class="field-group"><label>Sex</label><input id="re-f-sex" value="<?= htmlspecialchars($resSex) ?>"></div>
  <div class="field-group"><label>Date of Birth</label><input id="re-f-bday" value="<?= htmlspecialchars($resBday) ?>"></div>
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
    <button class="btn btn-secondary" onclick="window.print()">🖨️ Print</button>
    <button class="btn btn-danger" onclick="window.close()">✕ Close</button>
  </div>
</div>
<div class="cert-section" id="cert-residency"><div class="certificate">
  <div class="corner tl"></div><div class="corner tr"></div><div class="corner bl"></div><div class="corner br"></div>
  <?= hdr($L1,$L2,$BRGY,'re-c-brgy') ?>
  <div class="title-section"><span class="dots-icon">⠿</span><div class="cert-title">CERTIFICATE OF BARANGAY RESIDENCY</div></div>
  <div class="body">
    <p>To whom it may concern:</p>
    <p>This is to certify that <input class="ef lg" id="re-c-name">, <input class="ef sm" id="re-c-age"> <strong>OF AGE</strong> is a resident of Purok <input class="ef sm" id="re-c-purok">, Barangay <input class="ef md" id="re-c-brgy">, Talavera, Nueva Ecija. We personally known She/He to be a person of good moral character, law-abiding and peaceful community resident.</p>
    <p>This certification is issued upon request of the said named person for any legal purposes it may serve.</p>
    <p>Issued this <input class="ef sm" id="re-c-day"> Day of <input class="ef md" id="re-c-month"> <input class="ef sm" id="re-c-year" style="width:60px">, here at Barangay <input class="ef md" id="re-c-ibrgy">, Talavera, Nueva Ecija.</p>
  </div>
  <div class="personal-title">PERSONAL DETAILS</div>
  <div class="details-block">
    <div class="detail-row">Name: <input class="ef lg" id="re-c-det-name"></div>
    <div class="detail-row">Address: Purok <input class="ef sm" id="re-c-det-purok">, Barangay <input class="ef md" id="re-c-det-brgy">, Talavera, Nueva Ecija</div>
    <div class="detail-row">Civil Status: <input class="ef md" id="re-c-civil"> &nbsp; Sex: <input class="ef md" id="re-c-sex"></div>
    <div class="detail-row">Birth Day: <input class="ef md" id="re-c-bday"> &nbsp; Birth Place: <input class="ef lg" id="re-c-bplace"></div>
    <div class="detail-row">Height: <input class="ef md" id="re-c-height"> &nbsp; Weight: <input class="ef md" id="re-c-weight"></div>
    <div class="detail-row">Father: <input class="ef lg" id="re-c-father"> &nbsp; Mother: <input class="ef lg" id="re-c-mother"></div>
    <div class="detail-row">Purpose: For <input class="ef lg" id="re-c-purpose"></div>
  </div>
  <div class="applicant-sig"><div class="sig-line"></div><div>Applicant Signature</div></div>
  <div class="sig-row">
    <div class="sig-col"><div class="sig-label">Attested by:</div><div class="sig-block"><span class="sig-line"></span><div><input class="ef lg" id="re-c-sec"></div><div>Barangay Secretary</div></div></div>
    <div class="sig-col" style="align-items:flex-end"><div class="sig-label">Certified by:</div><div class="sig-block"><span class="sig-line"></span><div><input class="ef lg" id="re-c-puno"></div><div>Punong Barangay</div></div></div>
  </div>
  <?= wave() ?>
</div></div>

<!-- ══ 4. LIVE-IN ══ -->
<div class="toolbar toolbar-section" id="tb-livein">
  <h3>✏️ Certificate of Live-in</h3>
  <div class="field-group"><label>Partner 1 Full Name</label><input id="li-f-name1" placeholder="e.g. Juan Dela Cruz"></div>
  <div class="field-group"><label>Partner 2 Full Name</label><input id="li-f-name2" placeholder="e.g. Maria Santos"></div>
  <div class="field-group"><label>Purok #</label><input id="li-f-purok" value="<?= htmlspecialchars($resPurok) ?>"></div>
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
    <button class="btn btn-secondary" onclick="window.print()">🖨️ Print</button>
    <button class="btn btn-danger" onclick="window.close()">✕ Close</button>
  </div>
</div>
<div class="cert-section" id="cert-livein"><div class="certificate">
  <div class="corner tl"></div><div class="corner tr"></div><div class="corner bl"></div><div class="corner br"></div>
  <?= hdr($L1,$L2,$BRGY,'li-c-brgy') ?>
  <div class="title-section"><span class="dots-icon">⠿</span><div class="cert-title">BARANGAY CERTIFICATE OF LIVE-IN</div></div>
  <div class="body">
    <p><strong>TO WHOM IT MAY CONCERN:</strong></p>
    <p class="indent">This is to <strong>CERTIFY</strong> that <input class="ef xl" id="li-c-name1">, of legal age, Filipino, and <input class="ef xl" id="li-c-name2">, of legal age, Filipino, are <strong>currently living together as live-in partners</strong> at Purok <input class="ef sm" id="li-c-purok">, Barangay <input class="ef md" id="li-c-brgy">, Municipality of Talavera, Province of Nueva Ecija.</p>
    <p class="indent">Based on records and to the best of the barangay's knowledge, the above-named individuals have been <strong>cohabiting peacefully</strong> at the stated address since <strong>20<input class="ef sm" id="li-c-since" style="width:40px"></strong>.</p>
    <p class="indent">This certification is issued <strong>upon request of the above-named persons</strong> for <strong>whatever legal purpose it may serve</strong>.</p>
    <p class="indent">Issued this <input class="ef sm" id="li-c-day"> day of <input class="ef md" id="li-c-month">, <input class="ef sm" id="li-c-year" style="width:60px">, at Barangay <input class="ef md" id="li-c-ibrgy">, Municipality of Talavera, Province of Nueva Ecija, Philippines.</p>
  </div>
  <div class="sig-section"><div style="font-weight:bold;margin-bottom:28px">Prepared by:</div><div style="display:inline-block"><div class="sig-line"></div><div><input class="ef lg" id="li-c-sec"></div><div>Barangay Secretary</div></div></div>
  <div class="sig-section" style="margin-top:24px"><div style="font-weight:bold;margin-bottom:28px">Noted by:</div><div style="display:inline-block"><div class="sig-line"></div><div><input class="ef lg" id="li-c-puno"></div><div>Punong Barangay</div></div></div>
  <?= wave() ?>
</div></div>

<!-- ══ 5. SAME PERSON ══ -->
<div class="toolbar toolbar-section" id="tb-sameperson">
  <h3>✏️ Certificate of Same Person</h3>
  <div class="field-group"><label>Full Name</label><input id="sp-f-name" value="<?= htmlspecialchars($resName) ?>"></div>
  <div class="field-group"><label>Purok #</label><input id="sp-f-purok" value="<?= htmlspecialchars($resPurok) ?>"></div>
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
    <button class="btn btn-secondary" onclick="window.print()">🖨️ Print</button>
    <button class="btn btn-danger" onclick="window.close()">✕ Close</button>
  </div>
</div>
<div class="cert-section" id="cert-sameperson"><div class="certificate">
  <div class="corner tl"></div><div class="corner tr"></div><div class="corner bl"></div><div class="corner br"></div>
  <?= hdr($L1,$L2,$BRGY,'sp-c-brgy') ?>
  <div style="padding:14px 0 4px"><div class="cert-title">BARANGAY CERTIFICATE OF SAME PERSON</div><div class="cert-subtitle">(Sertipikasyon ng Iisang Tao)</div></div>
  <div class="body">
    <p>Sa kinauukulan:</p>
    <p class="indent">Ito ay <strong>PINATUTUNAYAN</strong> na si <input class="ef xl" id="sp-c-name">, nasa wastong gulang, Pilipino, at kasalukuyang naninirahan sa <strong>Purok <input class="ef sm" id="sp-c-purok">, Barangay <input class="ef md" id="sp-c-brgy"></strong>, Talavera, Nueva Ecija ay <strong>iisa at parehong tao</strong> na tinutukoy sa mga sumusunod na pangalan:</p>
  </div>
  <ol class="name-list"><li><input class="ef xl" id="sp-c-name1"></li><li><input class="ef xl" id="sp-c-name2"></li></ol>
  <div class="body">
    <p class="indent">Batay sa talaan ng aming barangay at sa personal na pahayag ng nabanggit na indibidwal, ang pagkakaiba sa baybay o paggamit ng pangalan ay dulot lamang ng kamalian sa dokumento at hindi nangangahulugang magkaibang tao.</p>
    <p class="indent">Ang sertipikasyong ito ay ipinagkakaloob para sa anumang legal at opisyal na layunin.</p>
    <p>Iginawad ngayong ika-<input class="ef sm" id="sp-c-day"> ng <input class="ef md" id="sp-c-month">, <input class="ef sm" id="sp-c-year" style="width:60px">, sa Barangay <input class="ef lg" id="sp-c-ibrgy">, Talavera, Nueva Ecija.</p>
    <p>Pinatutunsayan ni:</p>
  </div>
  <div class="sig-section"><div style="display:inline-block"><div class="sig-line"></div><div><input class="ef lg" id="sp-c-sec"></div><div>Barangay Secretary</div></div></div>
  <div class="sig-center" style="margin-top:24px"><div style="margin-bottom:28px">Pinagtibay:</div><div style="display:inline-block;text-align:center"><div style="border-top:1.5px solid #000;width:200px;margin:0 auto 4px"></div><div><input class="ef lg" id="sp-c-puno"></div><div>Punong Barangay</div></div></div>
  <?= wave() ?>
</div></div>

<!-- ══ 7. BUSINESS PERMIT ══ -->
<div class="toolbar toolbar-section" id="tb-business">
  <h3>✏️ Business Permit</h3>
  <div class="field-group"><label>Date</label><input id="bu-f-date" value="<?= $today ?>"></div>
  <div class="field-group"><label>Time</label><input id="bu-f-time" placeholder="e.g. 9:00 AM"></div>
  <div class="field-group"><label>Business Name / Trade Activity</label><input id="bu-f-business" placeholder="e.g. Sari-sari Store"></div>
  <div class="field-group"><label>Operator / Owner</label><input id="bu-f-owner" value="<?= htmlspecialchars($resName) ?>"></div>
  <div class="field-group"><label>Address Purok #</label><input id="bu-f-purok1" value="<?= htmlspecialchars($resPurok) ?>"></div>
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
    <button class="btn btn-secondary" onclick="window.print()">🖨️ Print</button>
    <button class="btn btn-danger" onclick="window.close()">✕ Close</button>
  </div>
</div>
<div class="cert-section" id="cert-business"><div class="certificate">
  <div class="corner tl"></div><div class="corner tr"></div><div class="corner bl"></div><div class="corner br"></div>
  <?= hdr($L1,$L2,$BRGY,'bu-c-brgy') ?>
  <div class="title-section"><span class="dots-icon">⠿</span><div class="cert-title" style="font-size:20px;letter-spacing:2px">BARANGAY BUSINESS &nbsp; PERMIT</div></div>
  <div class="date-center">DATE – <input class="ef md" id="bu-c-date"> &nbsp;&nbsp; TIME – <input class="ef md" id="bu-c-time"></div>
  <div class="body">
    <p>To whom it may concern:</p>
    <p class="indent">This is to certify that the business or trade activity described below.</p>
    <div class="field-block"><input class="ef block-field" id="bu-c-business"><div class="field-label">Business Name or Trade Activity</div></div>
    <div class="field-block" style="margin-top:14px"><input class="ef block-field" id="bu-c-owner"><div class="field-label">Operator/Owner</div></div>
    <p style="margin-top:12px">Address: <strong>Purok <input class="ef sm" id="bu-c-purok1">, Barangay <input class="ef md" id="bu-c-brgy1">, Talavera, Nueva Ecija</strong><br>Business Location: <strong>Purok <input class="ef sm" id="bu-c-purok2">, Barangay <input class="ef md" id="bu-c-brgy2">, Talavera, Nueva Ecija</strong></p>
    <p class="indent">Being applied for new or corresponding Business Mayor's Permit is hereby granted subject to the provisions of existing laws, Barangay Ordinances, Resolutions and rules and regulations whenever applicable.</p>
    <p class="indent">In case of any violation of the foregoing rules and after due process this permit is hereby revoked or cancelled.</p>
    <p class="indent">Issued this <input class="ef sm" id="bu-c-day"> day of <input class="ef md" id="bu-c-month">, <input class="ef sm" id="bu-c-year" style="width:60px">, at Barangay <input class="ef md" id="bu-c-ibrgy">, Municipality of Talavera, Province of Nueva Ecija, Philippines.</p>
  </div>
  <div class="sig-row">
    <div class="sig-col"><div class="sig-label">Prepared by:</div><div class="sig-line"></div><div class="sig-name"><input class="ef lg" id="bu-c-sec"></div><div class="sig-name">Barangay Secretary</div></div>
    <div class="sig-col" style="align-items:flex-end"><div class="sig-label">Certified by:</div><div class="sig-line"></div><div class="sig-name"><input class="ef lg" id="bu-c-puno"></div><div class="sig-name">Punong Barangay</div></div>
  </div>
  <div style="text-align:center;margin-top:20px;font-size:13px"><div style="margin-bottom:26px"></div><div class="sig-line" style="margin:0 auto"></div><div><input class="ef lg" id="bu-c-sb"></div><div>SB Member / Officer of the Day</div></div>
  <?= wave() ?>
</div></div>

<!-- ══ 8. BARC ══ -->
<div class="toolbar toolbar-section" id="tb-barc">
  <h3>✏️ BARC Certificate</h3>
  <div class="field-group"><label>Full Name</label><input id="ba-f-name" value="<?= htmlspecialchars($resName) ?>"></div>
  <div class="field-group"><label>Purok #</label><input id="ba-f-purok" value="<?= htmlspecialchars($resPurok) ?>"></div>
  <div class="field-group"><label>Barangay</label><input id="ba-f-brgy" value="<?= $BRGY ?>"></div>
  <div class="field-group"><label>Name (2nd paragraph)</label><input id="ba-f-name2" value="<?= htmlspecialchars($resName) ?>"></div>
  <div class="field-group"><label>Lot No.</label><input id="ba-f-lot" placeholder="e.g. 123"></div>
  <div class="field-group"><label>Plano Blg</label><input id="ba-f-plano" placeholder="e.g. 456"></div>
  <div class="field-group"><label>Land Area / Sukat</label><input id="ba-f-sukat" placeholder="e.g. 500 sq.m."></div>
  <div class="field-group"><label>Recipient Name</label><input id="ba-f-recipient" value="<?= htmlspecialchars($resName) ?>"></div>
  <div class="field-group"><label>Day (ika-)</label><input id="ba-f-day" value="<?= $dayNum ?>"></div>
  <div class="field-group"><label>Month</label><input id="ba-f-month" value="<?= $monthStr ?>"></div>
  <div class="field-group"><label>Year</label><input id="ba-f-year" value="<?= $yearStr ?>"></div>
  <div class="field-group"><label>Barangay Secretary</label><input id="ba-f-sec" value="<?= $SEC ?>"></div>
  <div class="field-group"><label>BARC Chairman</label><input id="ba-f-barc" placeholder="Full name"></div>
  <div class="field-group"><label>Punong Barangay</label><input id="ba-f-puno" value="<?= $PUNO ?>"></div>
  <div class="toolbar-actions">
    <button class="btn btn-primary" onclick="applyFields('barc')">Apply</button>
    <button class="btn btn-secondary" onclick="clearFields('barc')">Clear</button>
    <button class="btn btn-secondary" onclick="window.print()">🖨️ Print</button>
    <button class="btn btn-danger" onclick="window.close()">✕ Close</button>
  </div>
</div>
<div class="cert-section" id="cert-barc"><div class="certificate">
  <div class="corner tl"></div><div class="corner tr"></div><div class="corner bl"></div><div class="corner br"></div>
  <?= hdr($L1,$L2,$BRGY,'ba-c-brgy') ?>
  <div class="title-section"><span class="dots-icon">⠿</span><div class="cert-title" style="font-size:20px;color:#4a7c2f">PAGPAPATUNAY</div></div>
  <div class="body">
    <p>Sa kinauukulan;</p>
    <p class="indent">Pinatutunayan sa lahat na si <input class="ef xl" id="ba-c-name"> lehitimong residente ng <strong>Purok <input class="ef sm" id="ba-c-purok">, Barangay <input class="ef md" id="ba-c-brgy">, Talavera, Nueva Ecija</strong>.</p>
    <p class="indent">Pinatutunayan parin na ang nasabing si <input class="ef xl" id="ba-c-name2"> ay, may loteng sinasaka/tayuan ng bahay, <strong>Lot No.</strong> <input class="ef sm" id="ba-c-lot">, Plano Blg <input class="ef sm" id="ba-c-plano">, at may sukat na humigit kumulang sa <input class="ef lg" id="ba-c-sukat">, at aktuwal na sinasaka/tinitirhan at matatagpuan sa Barangay <?= $BRGY ?>, Talavera, Nueva Ecija.</p>
    <p class="indent">Ang pagpapatunay na ito ay ipinagkaloob kay <input class="ef xl" id="ba-c-recipient">, ngayong <strong>ika-<input class="ef sm" id="ba-c-day"></strong>, ng <input class="ef md" id="ba-c-month"> <strong><input class="ef sm" id="ba-c-year" style="width:60px"></strong>, para magamit sa Banko.</p>
  </div>
  <div class="sig-row">
    <div class="sig-col"><div class="sig-label">Inihanda ni:</div><div class="sig-line"></div><div class="sig-name"><input class="ef lg" id="ba-c-sec"></div><div class="sig-name">Barangay Secretary</div></div>
    <div class="sig-col" style="align-items:flex-end"><div class="sig-label">Pinatutunayan ni:</div><div class="sig-line"></div><div class="sig-name"><input class="ef lg" id="ba-c-barc"></div><div class="sig-name">BARC Chairman</div></div>
  </div>
  <div class="sig-center" style="margin-top:24px"><div style="margin-bottom:28px;font-style:italic">Pinatotohanan ni:</div><div style="display:inline-block;text-align:center"><div style="border-top:1.5px solid #000;width:200px;margin:0 auto 4px"></div><div><input class="ef lg" id="ba-c-puno"></div><div>Punong Barangay</div></div></div>
  <?= wave() ?>
</div></div>

<!-- ══ 9. SOLO PARENT ══ -->
<div class="toolbar toolbar-section" id="tb-soloparent">
  <h3>✏️ Certificate for Solo Parent</h3>
  <div class="field-group"><label>Full Name</label><input id="so-f-name" value="<?= htmlspecialchars($resName) ?>"></div>
  <div class="field-group"><label>Age</label><input id="so-f-age" value="<?= htmlspecialchars($resAge) ?>"></div>
  <div class="field-group"><label>Purok #</label><input id="so-f-purok" value="<?= htmlspecialchars($resPurok) ?>"></div>
  <div class="field-group"><label>Solo Parent Name</label><input id="so-f-solo" value="<?= htmlspecialchars($resName) ?>"></div>
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
    <button class="btn btn-secondary" onclick="window.print()">🖨️ Print</button>
    <button class="btn btn-danger" onclick="window.close()">✕ Close</button>
  </div>
</div>
<div class="cert-section" id="cert-soloparent"><div class="certificate">
  <div class="corner tl"></div><div class="corner tr"></div><div class="corner bl"></div><div class="corner br"></div>
  <?= hdr($L1,$L2,$BRGY,'so-c-brgy') ?>
  <div class="title-section"><span class="dots-icon">⠿</span><div class="cert-title" style="font-family:'Georgia',serif;font-size:24px;font-style:italic;color:#4a7c2f">Certificate for Solo Parent</div></div>
  <div class="body">
    <p>To whom it may concern;</p>
    <p class="indent">This is to certify that <input class="ef xl" id="so-c-name">, <input class="ef sm" id="so-c-age"> years old and with postal address at Purok <input class="ef sm" id="so-c-purok">, Barangay <?= $BRGY ?>, Talavera, Nueva Ecija.</p>
    <p class="indent">Further Certified that, <input class="ef xl" id="so-c-solo"> is a Solo Parent of, Name of Children: <input class="ef lg" id="so-c-child1">, <input class="ef lg" id="so-c-child2">, <input class="ef xl" id="so-c-child3">, with the same address above.</p>
    <p class="indent">This certificate is being issued to the above named person as a requirement for Solo Parent Identification <strong>Card (ID)</strong> at <strong>MSWD</strong> and for whatever Legal Intent and Purposes.</p>
    <p class="indent">Issued this <input class="ef sm" id="so-c-day"> day of <input class="ef md" id="so-c-month">, 202<input class="ef sm" id="so-c-year" style="width:30px"> at Barangay <input class="ef md" id="so-c-ibrgy">, Talavera, Nueva Ecija.</p>
  </div>
  <div class="sig-printed"><span class="sig-line"></span><div>Signature Over Printed Name</div></div>
  <div class="sig-row">
    <div class="sig-col"><div style="margin-bottom:26px">Prepared by;</div><div class="sig-line"></div><div class="sig-name"><input class="ef lg" id="so-c-sec"></div><div class="sig-name">Barangay Secretary</div></div>
    <div class="sig-col" style="align-items:flex-end"><div style="margin-bottom:26px">Certified by;</div><div class="sig-line"></div><div class="sig-name"><input class="ef lg" id="so-c-puno"></div><div class="sig-name">Punong Barangay</div></div>
  </div>
  <?= wave() ?>
</div></div>

<!-- ══ 10. LOW INCOME ══ -->
<div class="toolbar toolbar-section" id="tb-lowincome">
  <h3>✏️ Proof of Low Income</h3>
  <div class="field-group"><label>Full Name</label><input id="lo-f-name" value="<?= htmlspecialchars($resName) ?>"></div>
  <div class="field-group"><label>Age</label><input id="lo-f-age" value="<?= htmlspecialchars($resAge) ?>"></div>
  <div class="field-group"><label>Purok #</label><input id="lo-f-purok" value="<?= htmlspecialchars($resPurok) ?>"></div>
  <div class="field-group"><label>Date of Birth</label><input id="lo-f-bday" value="<?= htmlspecialchars($resBday) ?>"></div>
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
    <button class="btn btn-secondary" onclick="window.print()">🖨️ Print</button>
    <button class="btn btn-danger" onclick="window.close()">✕ Close</button>
  </div>
</div>
<div class="cert-section" id="cert-lowincome"><div class="certificate">
  <div class="corner tl"></div><div class="corner tr"></div><div class="corner bl"></div><div class="corner br"></div>
  <?= hdr($L1,$L2,$BRGY,'lo-c-brgy') ?>
  <div class="title-section"><span class="dots-icon">⠿</span><div class="cert-title" style="font-size:20px">PROOF OF LOW INCOME</div></div>
  <div class="body">
    <p>Sa Kinauukulan;</p>
    <p class="indent">Ito ay pagpapatunay na si <input class="ef xl" id="lo-c-name">, <input class="ef sm" id="lo-c-age"> taong gulang, Residente sa Purok <input class="ef sm" id="lo-c-purok">, ng Barangay <?= $BRGY ?>, Talavera, Nueva Ecija, ipinanganak noong <input class="ef lg" id="lo-c-bday">, ay kasalukuyang nagtatrabaho bilang isang <input class="ef lg" id="lo-c-work"> at siya ay kumikita ng hindi tataas sa <strong>Php</strong> <input class="ef md" id="lo-c-income"> sa loob ng isang buwan.</p>
    <p class="indent">Ito ay iginawad ngayon ika-<input class="ef sm" id="lo-c-day"> ng <input class="ef md" id="lo-c-month">, <input class="ef sm" id="lo-c-year" style="width:60px"> sa Tanggapan ng Barangay <input class="ef lg" id="lo-c-ibrgy">, Talavera, Nueva Ecija.</p>
  </div>
  <div class="sig-section"><div style="font-style:italic;margin-bottom:28px">Inihanda ni,</div><div style="display:inline-block"><div class="sig-line"></div><div><input class="ef lg" id="lo-c-sec"></div><div>Barangay Kalihim</div></div></div>
  <div class="sig-right"><div class="sig-label-r">Pinatotohanan ni,</div><div class="sig-line-r"></div><br><div><input class="ef lg" id="lo-c-puno"></div><div>Punong Barangay</div></div>
  <?= wave() ?>
</div></div>

<script>
const certMaps = {
  clearance:  [['cl-f-name','cl-c-name'],['cl-f-ibrgy','cl-c-ibrgy'],['cl-f-day','cl-c-day'],['cl-f-month','cl-c-month'],['cl-f-year','cl-c-year'],['cl-f-purpose','cl-c-purpose'],['cl-f-gender','cl-c-gender'],['cl-f-age','cl-c-age'],['cl-f-dob','cl-c-dob'],['cl-f-pob','cl-c-pob'],['cl-f-purok','cl-c-purok'],['cl-f-civil','cl-c-civil'],['cl-f-tct','cl-c-tct'],['cl-f-issued-at','cl-c-issued-at'],['cl-f-issued-on','cl-c-issued-on'],['cl-f-sec','cl-c-sec'],['cl-f-sec-cp','cl-c-sec-cp'],['cl-f-puno','cl-c-puno'],['cl-f-puno-cp','cl-c-puno-cp']],
  indigency:  [['in-f-name','in-c-name'],['in-f-age','in-c-age'],['in-f-purok','in-c-purok'],['in-f-brgy','in-c-brgy'],['in-f-purpose','in-c-purpose'],['in-f-day','in-c-day'],['in-f-month','in-c-month'],['in-f-year','in-c-year'],['in-f-ibrgy','in-c-ibrgy'],['in-f-sec','in-c-sec'],['in-f-sec-cp','in-c-sec-cp'],['in-f-puno','in-c-puno'],['in-f-puno-cp','in-c-puno-cp']],
  residency:  [['re-f-name','re-c-name'],['re-f-name','re-c-det-name'],['re-f-age','re-c-age'],['re-f-purok','re-c-purok'],['re-f-purok','re-c-det-purok'],['re-f-brgy','re-c-brgy'],['re-f-brgy','re-c-det-brgy'],['re-f-day','re-c-day'],['re-f-month','re-c-month'],['re-f-year','re-c-year'],['re-f-ibrgy','re-c-ibrgy'],['re-f-civil','re-c-civil'],['re-f-sex','re-c-sex'],['re-f-bday','re-c-bday'],['re-f-bplace','re-c-bplace'],['re-f-height','re-c-height'],['re-f-weight','re-c-weight'],['re-f-father','re-c-father'],['re-f-mother','re-c-mother'],['re-f-purpose','re-c-purpose'],['re-f-sec','re-c-sec'],['re-f-puno','re-c-puno']],
  livein:     [['li-f-name1','li-c-name1'],['li-f-name2','li-c-name2'],['li-f-purok','li-c-purok'],['li-f-brgy','li-c-brgy'],['li-f-since','li-c-since'],['li-f-day','li-c-day'],['li-f-month','li-c-month'],['li-f-year','li-c-year'],['li-f-ibrgy','li-c-ibrgy'],['li-f-sec','li-c-sec'],['li-f-puno','li-c-puno']],
  sameperson: [['sp-f-name','sp-c-name'],['sp-f-purok','sp-c-purok'],['sp-f-brgy','sp-c-brgy'],['sp-f-name1','sp-c-name1'],['sp-f-name2','sp-c-name2'],['sp-f-day','sp-c-day'],['sp-f-month','sp-c-month'],['sp-f-year','sp-c-year'],['sp-f-ibrgy','sp-c-ibrgy'],['sp-f-sec','sp-c-sec'],['sp-f-puno','sp-c-puno']],
  business:   [['bu-f-date','bu-c-date'],['bu-f-time','bu-c-time'],['bu-f-business','bu-c-business'],['bu-f-owner','bu-c-owner'],['bu-f-purok1','bu-c-purok1'],['bu-f-brgy1','bu-c-brgy1'],['bu-f-purok2','bu-c-purok2'],['bu-f-brgy2','bu-c-brgy2'],['bu-f-day','bu-c-day'],['bu-f-month','bu-c-month'],['bu-f-year','bu-c-year'],['bu-f-ibrgy','bu-c-ibrgy'],['bu-f-sec','bu-c-sec'],['bu-f-puno','bu-c-puno'],['bu-f-sb','bu-c-sb']],
  barc:       [['ba-f-name','ba-c-name'],['ba-f-purok','ba-c-purok'],['ba-f-brgy','ba-c-brgy'],['ba-f-name2','ba-c-name2'],['ba-f-lot','ba-c-lot'],['ba-f-plano','ba-c-plano'],['ba-f-sukat','ba-c-sukat'],['ba-f-recipient','ba-c-recipient'],['ba-f-day','ba-c-day'],['ba-f-month','ba-c-month'],['ba-f-year','ba-c-year'],['ba-f-sec','ba-c-sec'],['ba-f-barc','ba-c-barc'],['ba-f-puno','ba-c-puno']],
  soloparent: [['so-f-name','so-c-name'],['so-f-age','so-c-age'],['so-f-purok','so-c-purok'],['so-f-solo','so-c-solo'],['so-f-child1','so-c-child1'],['so-f-child2','so-c-child2'],['so-f-child3','so-c-child3'],['so-f-day','so-c-day'],['so-f-month','so-c-month'],['so-f-year','so-c-year'],['so-f-ibrgy','so-c-ibrgy'],['so-f-sec','so-c-sec'],['so-f-puno','so-c-puno']],
  lowincome:  [['lo-f-name','lo-c-name'],['lo-f-age','lo-c-age'],['lo-f-purok','lo-c-purok'],['lo-f-bday','lo-c-bday'],['lo-f-work','lo-c-work'],['lo-f-income','lo-c-income'],['lo-f-day','lo-c-day'],['lo-f-month','lo-c-month'],['lo-f-year','lo-c-year'],['lo-f-ibrgy','lo-c-ibrgy'],['lo-f-sec','lo-c-sec'],['lo-f-puno','lo-c-puno']]
};

// Live sync
Object.values(certMaps).forEach(map => {
  map.forEach(([s,d]) => {
    const src = document.getElementById(s), dest = document.getElementById(d);
    if (!src || !dest) return;
    src.addEventListener('input', () => dest.value = src.value);
    dest.addEventListener('input', () => src.value = dest.value);
  });
});

// Apply all at once
function applyFields(cert) {
  certMaps[cert].forEach(([s,d]) => {
    const src = document.getElementById(s), dest = document.getElementById(d);
    if (src && dest) dest.value = src.value;
  });
}

function clearFields(cert) {
  certMaps[cert].forEach(([s,d]) => {
    const src = document.getElementById(s), dest = document.getElementById(d);
    if (src) src.value = '';
    if (dest) dest.value = '';
  });
}

let current = 'clearance';
function showCert(name, btn) {
  document.getElementById('cert-' + current).classList.remove('active');
  document.getElementById('tb-' + current).classList.remove('active');
  document.getElementById('cert-' + name).classList.add('active');
  document.getElementById('tb-' + name).classList.add('active');
  document.querySelectorAll('.cert-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  current = name;
  window.scrollTo({top: 0, behavior: 'smooth'});
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
    lbl.style.display = 'none';
  };
  reader.readAsDataURL(file);
}

// Auto-apply on load
document.addEventListener('DOMContentLoaded', () => applyFields('clearance'));
</script>
</body>
</html>
 