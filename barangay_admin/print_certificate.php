<?php
require_once __DIR__ . '/../config/config.php';
requireRole('barangay_admin');

$db  = Database::getInstance()->getConnection();
$u   = currentUser();
$bid = (int)$u['barangay_id'];
$id  = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("
    SELECT c.*, r.first_name, r.middle_name, r.last_name, r.suffix,
           r.age, r.gender, r.civil_status, r.birthdate, r.photo,
           a.purok, a.street, a.barangay AS addr_barangay, a.city, a.province,
           b.barangay_name, b.municipality
    FROM certificates c
    LEFT JOIN residents r ON c.resident_id = r.id
    LEFT JOIN addresses a ON r.address_id  = a.id
    LEFT JOIN barangays b ON c.barangay_id = b.id
    WHERE c.id = ? AND c.barangay_id = ?
");
$stmt->execute([$id, $bid]);
$cert = $stmt->fetch();
if (!$cert) { die('<p style="font-family:sans-serif;padding:2rem;color:#c0392b;">Certificate not found.</p>'); }

/* ── Officials ── */
$offQ = $db->prepare("SELECT * FROM officials WHERE barangay_id=? AND status='active'");
$offQ->execute([$bid]);
$secretary = $punong = '';
foreach ($offQ->fetchAll() as $o) {
    if (stripos($o['position'],'secretary') !== false) $secretary = $o['full_name'];
    if (stripos($o['position'],'punong') !== false || stripos($o['position'],'captain') !== false) $punong = $o['full_name'];
}

/* ── Derived values ── */
$fullName = strtoupper(
    $cert['last_name'] . ', ' . $cert['first_name'] .
    ($cert['middle_name'] ? ' ' . $cert['middle_name'] : '') .
    ($cert['suffix']      ? ' ' . $cert['suffix']      : '')
);
$certType = $cert['certificate_type'] ?? 'Clearance';
$brgyName = strtoupper($cert['barangay_name'] ?? '');
$purok    = $cert['purok']        ?? '';
$age      = $cert['age']          ?? '';
$civil    = $cert['civil_status'] ?? '';
$gender   = $cert['gender']       ?? '';
$bday     = $cert['birthdate'] ? date('F d, Y', strtotime($cert['birthdate'])) : '';
$purpose  = htmlspecialchars($cert['purpose'] ?? '');
$dayNum   = date('j', strtotime($cert['date_issued']));
$monthStr = date('F', strtotime($cert['date_issued']));
$yearStr  = date('Y', strtotime($cert['date_issued']));
$yearLast = substr($yearStr, -1);
$today    = date('F d, Y', strtotime($cert['date_issued']));
$BRGY     = htmlspecialchars($brgyName);
$SEC      = htmlspecialchars($secretary);
$PUNO     = htmlspecialchars($punong);
$L1       = BASE_URL . '/assets/img/logo 1.jpg';
$L2       = BASE_URL . '/assets/img/logo 2.jpg';

/* ── Helpers ── */
function wave() {
    return '<div class="footer-wave"><svg viewBox="0 0 720 200" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">
<path d="M0,120 C80,80 180,150 300,110 C420,70 520,140 720,100 L720,200 L0,200 Z" fill="#a5d6a7" opacity="0.6"/>
<path d="M0,140 C100,100 220,160 360,125 C480,95 600,155 720,120 L720,200 L0,200 Z" fill="#6ab04c"/>
<path d="M0,158 C60,130 160,170 280,148 C400,126 500,165 620,145 C680,135 710,150 720,148 L720,200 L0,200 Z" fill="#4a7c2f"/>
<path d="M0,172 C80,155 200,182 340,165 C460,150 580,178 720,162 L720,200 L0,200 Z" fill="#2e6b0e"/>
</svg></div>';
}
/* Static input — renders like a filled .ef input (value pre-filled, read-only) */
function ei($id, $cls, $val, $extra = '') {
    $s = $extra ? " style=\"$extra\"" : '';
    return '<input class="ef '.htmlspecialchars($cls).'" id="'.htmlspecialchars($id).'" value="'.htmlspecialchars((string)$val).'" readonly'.$s.'>';
}
/* Same but value already HTML-escaped */
function eir($id, $cls, $val, $extra = '') {
    $s = $extra ? " style=\"$extra\"" : '';
    return '<input class="ef '.htmlspecialchars($cls).'" id="'.htmlspecialchars($id).'" value="'.$val.'" readonly'.$s.'>';
}
/* Static .if input */
function ii($id, $cls, $val) {
    return '<input class="'.htmlspecialchars($cls).'" id="'.htmlspecialchars($id).'" value="'.htmlspecialchars((string)$val).'" readonly>';
}
/* Static .cl-if input */
function ci($id, $val) {
    return '<input class="cl-if" id="'.htmlspecialchars($id).'" value="'.htmlspecialchars((string)$val).'" readonly>';
}

function hdr($L1, $L2, $BRGY) {
    return '<div class="header">
<img class="logo" src="'.$L1.'" alt="" onerror="this.style.visibility=\'hidden\'">
<div class="header-text">Republika ng Pilipinas<br>Lalawigan ng Nueva Ecija<br>Bayan ng TALAVERA<br>
<span class="barangay-name">BARANGAY <input class="ef brgy-hdr" value="'.$BRGY.'" readonly></span></div>
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
<title><?= htmlspecialchars($certType) ?> — <?= htmlspecialchars($cert['last_name'].', '.$cert['first_name']) ?></title>
<style>
/* ══ Exact copy of issue_certificate.php / certificates.php designer CSS ══ */
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;-webkit-print-color-adjust:exact;print-color-adjust:exact}
body{font-family:'Times New Roman',Times,serif;background:#f0f0f0;display:flex;flex-direction:column;align-items:center;padding:20px;gap:16px}
.no-print{width:210mm;display:flex;gap:8px;align-items:center;font-family:Arial,sans-serif}
.no-print button{display:inline-flex;align-items:center;gap:6px;padding:7px 18px;border:none;border-radius:5px;cursor:pointer;font-size:13px;font-weight:bold}
.btn-print{background:#4a7c2f;color:#fff}.btn-print:hover{background:#2e6b0e}
.btn-close-bar{background:#e5e7eb;color:#374151}
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
/* .ef — identical to the designer's .ef rules (unscoped here since no overlay wrapper) */
/* text-align:left + width sized to content so values never overflow into surrounding text */
.ef{border:none;border-bottom:1px solid #000;outline:none;font-family:'Times New Roman',serif;font-size:13px;background:transparent;text-align:center;padding:0 2px;min-width:40px}
.ef.sm{min-width:50px;width:60px}.ef.md{min-width:110px}.ef.lg{min-width:160px}.ef.xl{min-width:200px}
.ef.ef-name{min-width:180px;width:auto;max-width:100%}
.ef.brgy-hdr{min-width:130px;font-weight:900;font-family:'Arial Black',Arial,sans-serif;font-size:15px}
.ef.block-field{width:100%;border:none;border-bottom:1px solid #000;text-align:center;font-size:14px;background:transparent;display:block}
.footer-wave{margin-top:auto;margin-left:-15mm;margin-right:-15mm;height:50mm;overflow:hidden;position:relative;flex-shrink:0;background:linear-gradient(to bottom,#fff 0%,#e8f5e0 40%,#c5e8a0 100%)}
.footer-wave svg{position:absolute;bottom:0;left:0;width:100%;height:100%}
/* Info section (Clearance) */
.info-section{display:flex;gap:20px;margin:10px 0;align-items:flex-start}
.photo-box{width:100px;min-height:120px;border:1.5px solid #000;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:11px;color:#aaa;position:relative;overflow:hidden}
.photo-box img{position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover}
.info-table{font-size:12px;line-height:2;flex:1}
.info-table table{border-collapse:collapse;width:100%}
.info-table td{padding:1px 4px;vertical-align:bottom}
.info-table td:first-child{font-weight:bold;white-space:nowrap;width:120px}
.info-table td.colon{width:14px;text-align:center}
.info-table .divider-row td{border-top:1px solid #000;padding-top:4px}
.if{display:inline-block;min-width:150px;border-bottom:1px solid #000;background:transparent;font-family:'Times New Roman',serif;font-size:12px}
.if-sm{display:inline-block;min-width:70px;border-bottom:1px solid #000;background:transparent;font-family:'Times New Roman',serif;font-size:12px}
/* Clearance redesigned layout */
.cl-main-row{display:flex;gap:20px;margin:14px 0 10px;align-items:flex-start}
.cl-info-col{flex:1;min-width:0}
.cl-right-col{display:flex;flex-direction:column;align-items:center;gap:8px;min-width:170px;width:170px;flex-shrink:0}
.cl-photo-box{width:145px;height:170px;border:1.5px solid #000;display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden;background:#fafafa}
.cl-photo-box img{position:absolute;top:0;left:0;width:100%;height:100%;object-fit:cover}
.cl-thumbmarks{display:flex;gap:10px;justify-content:center}
.cl-thumb-col{display:flex;flex-direction:column;align-items:center;gap:4px}
.cl-thumb-box{width:72px;height:88px;border:1.5px solid #000;background:#fafafa}
.cl-thumb-label{font-size:10px;text-align:center;font-family:'Times New Roman',serif;color:#333}
.cl-notice{font-size:9px;font-family:'Times New Roman',serif;text-align:center;line-height:1.4;color:#444;font-style:italic;width:100%;padding:2px 0}
.cl-if{display:inline-block;width:100%;border:none;border-bottom:1px solid #000;background:transparent;font-family:'Times New Roman',serif;font-size:12px;padding:0 2px;vertical-align:bottom}
.cl-sig-row{display:flex;justify-content:space-between;align-items:flex-end;margin-top:24px;font-family:'Times New Roman',Times,serif;font-size:13px}
.cl-sig-col{display:flex;flex-direction:column;align-items:flex-start;min-width:180px}
.cl-sig-col.cl-sig-right{align-items:flex-end}
.cl-sig-role{font-style:italic;font-size:12px;margin-bottom:0}
.cl-sig-space{height:36px}
.cl-sig-line{border-top:1.5px solid #000;width:200px;margin-bottom:3px}
.cl-sig-title{font-size:12px;font-weight:bold;text-align:center;width:200px}
/* Bottom section (Clearance old style — kept for compat) */
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
/* Shared sig styles */
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
@media print{
  @page{size:A4 portrait;margin:0}
  html,body{margin:0;padding:0;background:#fff;width:210mm}
  .no-print{display:none!important}
  .certificate{width:210mm;height:297mm;border:none;padding:10mm 15mm 0 15mm;margin:0;overflow:hidden;box-shadow:none;display:flex;flex-direction:column}
  .footer-wave{margin-left:-15mm;margin-right:-15mm;height:50mm;margin-top:auto;flex-shrink:0}
  .corner{display:none!important}
  .ef,.if,.if-sm,.cl-if{border-bottom:1px solid #000!important;background:transparent!important}
}
</style>
</head>
<body>

<div class="no-print">
  <button class="btn-print" id="btnPrintPDF" onclick="doPrintPDF()">🖨️ Print / PDF</button>
  <button class="btn-close-bar" onclick="window.close()">✕ Close</button>
</div>

<?php if ($certType === 'Clearance'): ?>
<!-- ══ CLEARANCE ══════════════════════════════════════════════════════════ -->
<div class="certificate">
  <div class="corner tl"></div><div class="corner tr"></div><div class="corner bl"></div><div class="corner br"></div>
  <?= hdr($L1,$L2,$BRGY) ?>
  <div class="title-section"><span class="dots-icon">⠿</span><div class="cert-title">BARANGAY CERTIFICATION/CLEARANCE</div></div>
  <div class="body">
    <p><strong>TO WHOM IT MAY CONCERN:</strong></p>
    <p class="indent">This is to certify that <?= ei('cl-c-name','ef-name',$fullName) ?>, is a <u>bonafide</u> resident of this Barangay. He/She is personally known to me to be a <u>peaceful</u> and law-abiding citizen of this community with good moral character.</p>
    <p class="indent">He/She has not been involved any crimes or illegal activities and is not a member of any subversive elements. This clearance is valid only for ninety (90) days from the date of issuance.</p>
    <p class="indent">Issued at Barangay <?= ei('cl-c-ibrgy','md',$brgyName) ?>, Talavera Nueva Ecija this <?= ei('cl-c-day','sm',$dayNum) ?> day of <?= ei('cl-c-month','md',$monthStr) ?>, <?= ei('cl-c-year','sm',$yearStr,'width:60px') ?> upon request of subject person for <?= eir('cl-c-purpose','md',$purpose) ?>, purposes.</p>
  </div>
  <div class="cl-main-row">
    <div class="cl-info-col">
      <div class="info-table"><table>
        <tr><td>GENDER</td><td class="colon">:</td><td><?= ci('cl-c-gender',$gender) ?></td></tr>
        <tr><td>AGE</td><td class="colon">:</td><td><?= ci('cl-c-age',$age) ?></td></tr>
        <tr><td>DATE OF BIRTH</td><td class="colon">:</td><td><?= ci('cl-c-dob',$bday) ?></td></tr>
        <tr><td>PLACE OF BIRTH</td><td class="colon">:</td><td><?= ci('cl-c-pob','') ?></td></tr>
        <tr><td>PUROK</td><td class="colon">:</td><td><?= ci('cl-c-purok',$purok) ?></td></tr>
        <tr><td>CIVIL STATUS</td><td class="colon">:</td><td><?= ci('cl-c-civil',$civil) ?></td></tr>
        <tr class="divider-row"><td>TCT NO.</td><td class="colon">:</td><td><?= ci('cl-c-tct','') ?></td></tr>
        <tr><td>ISSUED AT</td><td class="colon">:</td><td><?= ci('cl-c-issued-at','') ?></td></tr>
        <tr><td>ISSUED ON</td><td class="colon">:</td><td><?= ci('cl-c-issued-on',$today) ?></td></tr>
      </table></div>
      <div style="margin-top:20px"><div style="border-bottom:1px dashed #000;width:200px;margin-bottom:4px">&nbsp;</div><div style="font-size:11px">Signature Over Printed Name</div></div>
    </div>
    <div class="cl-right-col">
      <div class="cl-photo-box">
        <?php if (!empty($cert['photo'])): ?>
          <img src="<?= BASE_URL ?>/uploads/residents/<?= htmlspecialchars($cert['photo']) ?>" alt="">
        <?php else: ?>
          <span style="text-align:center;font-size:11px;color:#aaa">2x2<br>Photo</span>
        <?php endif; ?>
      </div>
      <div class="cl-thumbmarks">
        <div class="cl-thumb-col"><div class="cl-thumb-box"></div><div class="cl-thumb-label">Left Thumb</div></div>
        <div class="cl-thumb-col"><div class="cl-thumb-box"></div><div class="cl-thumb-label">Right Thumb</div></div>
      </div>
      <div class="cl-notice"><em>*Valid for 90 days from issuance.<br>Not valid without Barangay Dry Seal.</em></div>
    </div>
  </div>
  <div class="cl-sig-row">
    <div class="cl-sig-col">
      <div class="cl-sig-role">Prepared by:</div><div class="cl-sig-space"></div>
      <div class="cl-sig-line"></div><?= ei('cl-c-sec','lg',$secretary) ?><div class="cl-sig-title">Barangay Secretary</div>
    </div>
    <div class="cl-sig-col cl-sig-right">
      <div class="cl-sig-role">Noted and Approved by:</div><div class="cl-sig-space"></div>
      <div class="cl-sig-line"></div><?= ei('cl-c-puno','lg',$punong) ?><div class="cl-sig-title">Punong Barangay</div>
    </div>
  </div>
  <?= wave() ?>
</div>

<?php elseif ($certType === 'Indigency'): ?>
<!-- ══ INDIGENCY ═════════════════════════════════════════════════════════ -->
<div class="certificate">
  <div class="corner tl"></div><div class="corner tr"></div><div class="corner bl"></div><div class="corner br"></div>
  <?= hdr($L1,$L2,$BRGY) ?>
  <div class="title-section"><span class="dots-icon">⠿</span><div class="cert-title" style="text-decoration:underline">BARANGAY CERTIFICATE OF INDIGENCY</div></div>
  <div class="body">
    <div class="to-whom">TO WHOM IT MAY CONCERN:</div>
    <p class="indent">This is to CERTIFY that <?= ei('in-c-name','lg',$fullName) ?>, <?= ei('in-c-age','sm',$age) ?> of age, Filipino, and a resident of Purok <?= ei('in-c-purok','sm',$purok) ?>, Barangay <?= ei('in-c-brgy','md',$brgyName) ?>, Municipality of Talavera, Province of Nueva Ecija, is known to this office as a person belonging to an indigent family.</p>
    <p class="indent">Based on records and verification conducted by this barangay, the above-named individual has insufficient or no regular source of income and is therefore considered financially incapable of meeting basic needs.<br>This certification is issued upon the request of the above-named person for <?= eir('in-c-purpose','md',$purpose) ?>, and for whatever legal purpose it may serve.</p>
    <p class="indent">Issued this <?= ei('in-c-day','sm',$dayNum) ?> day of <?= ei('in-c-month','md',$monthStr) ?>, <?= ei('in-c-year','sm',$yearStr,'width:60px') ?> at Barangay <?= ei('in-c-ibrgy','md',$brgyName) ?>, Talavera, Nueva Ecija.</p>
  </div>
  <div class="signatures">
    <div style="margin-bottom:40px"><div class="sig-line"></div><div style="font-weight:bold"><?= ei('in-c-sec','lg',$secretary) ?></div><div style="font-size:12px">Barangay Secretary</div></div>
    <div class="noted">Noted and Approved by:</div>
    <div><div class="sig-line"></div><div style="font-weight:bold"><?= ei('in-c-puno','lg',$punong) ?></div><div style="font-size:12px">Punong Barangay</div></div>
  </div>
  <?= wave() ?>
</div>

<?php elseif ($certType === 'Residency'): ?>
<!-- ══ RESIDENCY ══════════════════════════════════════════════════════════ -->
<div class="certificate">
  <div class="corner tl"></div><div class="corner tr"></div><div class="corner bl"></div><div class="corner br"></div>
  <?= hdr($L1,$L2,$BRGY) ?>
  <div class="title-section"><span class="dots-icon">⠿</span><div class="cert-title">CERTIFICATE OF BARANGAY RESIDENCY</div></div>
  <div class="body">
    <p>To whom it may concern:</p>
    <p>This is to certify that <?= ei('re-c-name','lg',$fullName) ?>, <?= ei('re-c-age','sm',$age) ?> <strong>OF AGE</strong> is a resident of Purok <?= ei('re-c-purok','sm',$purok) ?>, Barangay <?= ei('re-c-brgy','md',$brgyName) ?>, Talavera, Nueva Ecija. We personally known She/He to be a person of good moral character, law-abiding and peaceful community resident.</p>
    <p>This certification is issued upon request of the said named person for any legal purposes it may serve.</p>
    <p>Issued this <?= ei('re-c-day','sm',$dayNum) ?> Day of <?= ei('re-c-month','md',$monthStr) ?> <?= ei('re-c-year','sm',$yearStr,'width:60px') ?>, here at Barangay <?= ei('re-c-ibrgy','md',$brgyName) ?>, Talavera, Nueva Ecija.</p>
  </div>
  <div class="personal-title">PERSONAL DETAILS</div>
  <div class="details-block">
    <div class="detail-row">Name: <?= ei('re-c-det-name','lg',$fullName) ?></div>
    <div class="detail-row">Address: Purok <?= ei('re-c-det-purok','sm',$purok) ?>, Barangay <?= ei('re-c-det-brgy','md',$brgyName) ?>, Talavera, Nueva Ecija</div>
    <div class="detail-row">Civil Status: <?= ei('re-c-civil','md',$civil) ?> &nbsp; Sex: <?= ei('re-c-sex','md',$gender) ?></div>
    <div class="detail-row">Birth Day: <?= ei('re-c-bday','md',$bday) ?> &nbsp; Birth Place: <?= ei('re-c-bplace','lg','') ?></div>
    <div class="detail-row">Height: <?= ei('re-c-height','md','') ?> &nbsp; Weight: <?= ei('re-c-weight','md','') ?></div>
    <div class="detail-row">Father: <?= ei('re-c-father','lg','') ?> &nbsp; Mother: <?= ei('re-c-mother','lg','') ?></div>
    <div class="detail-row">Purpose: For <?= eir('re-c-purpose','lg',$purpose) ?></div>
  </div>
  <div class="applicant-sig"><div class="sig-line"></div><div>Applicant Signature</div></div>
  <div class="sig-row">
    <div class="sig-col"><div class="sig-label">Attested by:</div><div class="sig-block"><span class="sig-line"></span><div><?= ei('re-c-sec','lg',$secretary) ?></div><div>Barangay Secretary</div></div></div>
    <div class="sig-col" style="align-items:flex-end"><div class="sig-label">Certified by:</div><div class="sig-block"><span class="sig-line"></span><div><?= ei('re-c-puno','lg',$punong) ?></div><div>Punong Barangay</div></div></div>
  </div>
  <?= wave() ?>
</div>

<?php elseif ($certType === 'Live-in'): ?>
<!-- ══ LIVE-IN ════════════════════════════════════════════════════════════ -->
<div class="certificate">
  <div class="corner tl"></div><div class="corner tr"></div><div class="corner bl"></div><div class="corner br"></div>
  <?= hdr($L1,$L2,$BRGY) ?>
  <div class="title-section"><span class="dots-icon">⠿</span><div class="cert-title">BARANGAY CERTIFICATE OF LIVE-IN</div></div>
  <div class="body">
    <p><strong>TO WHOM IT MAY CONCERN:</strong></p>
    <p class="indent">This is to <strong>CERTIFY</strong> that <?= ei('li-c-name1','xl',$fullName) ?>, of legal age, Filipino, and <?= ei('li-c-name2','xl','') ?>, of legal age, Filipino, are <strong>currently living together as live-in partners</strong> at Purok <?= ei('li-c-purok','sm',$purok) ?>, Barangay <?= ei('li-c-brgy','md',$brgyName) ?>, Municipality of Talavera, Province of Nueva Ecija.</p>
    <p class="indent">Based on records and to the best of the barangay's knowledge, the above-named individuals have been <strong>cohabiting peacefully</strong> at the stated address since <strong>20<?= ei('li-c-since','sm','','width:40px') ?></strong>.</p>
    <p class="indent">This certification is issued <strong>upon request of the above-named persons</strong> for <strong>whatever legal purpose it may serve</strong>.</p>
    <p class="indent">Issued this <?= ei('li-c-day','sm',$dayNum) ?> day of <?= ei('li-c-month','md',$monthStr) ?>, <?= ei('li-c-year','sm',$yearStr,'width:60px') ?>, at Barangay <?= ei('li-c-ibrgy','md',$brgyName) ?>, Municipality of Talavera, Province of Nueva Ecija, Philippines.</p>
  </div>
  <div class="sig-section"><div style="font-weight:bold;margin-bottom:28px">Prepared by:</div><div style="display:inline-block"><div class="sig-line"></div><div><?= ei('li-c-sec','lg',$secretary) ?></div><div>Barangay Secretary</div></div></div>
  <div class="sig-section" style="margin-top:24px"><div style="font-weight:bold;margin-bottom:28px">Noted by:</div><div style="display:inline-block"><div class="sig-line"></div><div><?= ei('li-c-puno','lg',$punong) ?></div><div>Punong Barangay</div></div></div>
  <?= wave() ?>
</div>

<?php elseif ($certType === 'Same Person'): ?>
<!-- ══ SAME PERSON ════════════════════════════════════════════════════════ -->
<div class="certificate">
  <div class="corner tl"></div><div class="corner tr"></div><div class="corner bl"></div><div class="corner br"></div>
  <?= hdr($L1,$L2,$BRGY) ?>
  <div style="padding:14px 0 4px"><div class="cert-title">BARANGAY CERTIFICATE OF SAME PERSON</div><div class="cert-subtitle">(Sertipikasyon ng Iisang Tao)</div></div>
  <div class="body">
    <p>Sa kinauukulan:</p>
    <p class="indent">Ito ay <strong>PINATUTUNAYAN</strong> na si <?= ei('sp-c-name','xl',$fullName) ?>, nasa wastong gulang, Pilipino, at kasalukuyang naninirahan sa <strong>Purok <?= ei('sp-c-purok','sm',$purok) ?>, Barangay <?= ei('sp-c-brgy','md',$brgyName) ?></strong>, Talavera, Nueva Ecija ay <strong>iisa at parehong tao</strong> na tinutukoy sa mga sumusunod na pangalan:</p>
  </div>
  <ol class="name-list"><li><?= ei('sp-c-name1','xl',$fullName) ?></li><li><?= ei('sp-c-name2','xl','') ?></li></ol>
  <div class="body">
    <p class="indent">Batay sa talaan ng aming barangay at sa personal na pahayag ng nabanggit na indibidwal, ang pagkakaiba sa baybay o paggamit ng pangalan ay dulot lamang ng kamalian sa dokumento at hindi nangangahulugang magkaibang tao.</p>
    <p class="indent">Ang sertipikasyong ito ay ipinagkakaloob para sa anumang legal at opisyal na layunin.</p>
    <p>Iginawad ngayong ika-<?= ei('sp-c-day','sm',$dayNum) ?> ng <?= ei('sp-c-month','md',$monthStr) ?>, <?= ei('sp-c-year','sm',$yearStr,'width:60px') ?>, sa Barangay <?= ei('sp-c-ibrgy','lg',$brgyName) ?>, Talavera, Nueva Ecija.</p>
    <p>Pinatutunsayan ni:</p>
  </div>
  <div class="sig-section"><div style="display:inline-block"><div class="sig-line"></div><div><?= ei('sp-c-sec','lg',$secretary) ?></div><div>Barangay Secretary</div></div></div>
  <div class="sig-center" style="margin-top:24px"><div style="margin-bottom:28px">Pinagtibay:</div><div style="display:inline-block;text-align:center"><div style="border-top:1.5px solid #000;width:200px;margin:0 auto 4px"></div><div><?= ei('sp-c-puno','lg',$punong) ?></div><div>Punong Barangay</div></div></div>
  <?= wave() ?>
</div>

<?php elseif ($certType === 'Business Permit'): ?>
<!-- ══ BUSINESS PERMIT ════════════════════════════════════════════════════ -->
<div class="certificate">
  <div class="corner tl"></div><div class="corner tr"></div><div class="corner bl"></div><div class="corner br"></div>
  <?= hdr($L1,$L2,$BRGY) ?>
  <div class="title-section"><span class="dots-icon">⠿</span><div class="cert-title" style="font-size:20px;letter-spacing:2px">BARANGAY BUSINESS &nbsp; PERMIT</div></div>
  <div class="date-center">DATE – <?= ei('bu-c-date','md',$today) ?></div>
  <div class="body">
    <p>To whom it may concern:</p>
    <p class="indent">This is to certify that the business or trade activity described below.</p>
    <div class="field-block"><?= eir('bu-c-business','block-field',$purpose) ?><div class="field-label">Business Name or Trade Activity</div></div>
    <div class="field-block" style="margin-top:14px"><?= ei('bu-c-owner','block-field',$fullName) ?><div class="field-label">Operator/Owner</div></div>
    <p style="margin-top:12px">Address: <strong>Purok <?= ei('bu-c-purok1','sm',$purok) ?>, Barangay <?= ei('bu-c-brgy1','md',$brgyName) ?>, Talavera, Nueva Ecija</strong><br>Business Location: <strong>Purok <?= ei('bu-c-purok2','sm','') ?>, Barangay <?= ei('bu-c-brgy2','md',$brgyName) ?>, Talavera, Nueva Ecija</strong></p>
    <p class="indent">Being applied for new or corresponding Business Mayor's Permit is hereby granted subject to the provisions of existing laws, Barangay Ordinances, Resolutions and rules and regulations whenever applicable.</p>
    <p class="indent">In case of any violation of the foregoing rules and after due process this permit is hereby revoked or cancelled.</p>
    <p class="indent">Issued this <?= ei('bu-c-day','sm',$dayNum) ?> day of <?= ei('bu-c-month','md',$monthStr) ?>, <?= ei('bu-c-year','sm',$yearStr,'width:60px') ?>, at Barangay <?= ei('bu-c-ibrgy','md',$brgyName) ?>, Municipality of Talavera, Province of Nueva Ecija, Philippines.</p>
  </div>
  <div class="sig-row">
    <div class="sig-col"><div class="sig-label">Prepared by:</div><div class="sig-line"></div><div class="sig-name"><?= ei('bu-c-sec','lg',$secretary) ?></div><div class="sig-name">Barangay Secretary</div></div>
    <div class="sig-col" style="align-items:flex-end"><div class="sig-label">Certified by:</div><div class="sig-line"></div><div class="sig-name"><?= ei('bu-c-puno','lg',$punong) ?></div><div class="sig-name">Punong Barangay</div></div>
  </div>
  <div style="text-align:center;margin-top:20px;font-size:13px"><div style="margin-bottom:26px"></div><div class="sig-line" style="margin:0 auto"></div><?= ei('bu-c-sb','lg','') ?><div>SB Member / Officer of the Day</div></div>
  <?= wave() ?>
</div>

<?php elseif ($certType === 'BARC'): ?>
<!-- ══ BARC ═══════════════════════════════════════════════════════════════ -->
<div class="certificate">
  <div class="corner tl"></div><div class="corner tr"></div><div class="corner bl"></div><div class="corner br"></div>
  <?= hdr($L1,$L2,$BRGY) ?>
  <div class="title-section"><span class="dots-icon">⠿</span><div class="cert-title" style="font-size:20px;color:#4a7c2f">PAGPAPATUNAY</div></div>
  <div class="body">
    <p>Sa kinauukulan;</p>
    <p class="indent">Pinatutunayan sa lahat na si <?= ei('ba-c-name','xl',$fullName) ?> lehitimong residente ng <strong>Purok <?= ei('ba-c-purok','sm',$purok) ?>, Barangay <?= ei('ba-c-brgy','md',$brgyName) ?>, Talavera, Nueva Ecija</strong>.</p>
    <p class="indent">Pinatutunayan parin na ang nasabing si <?= ei('ba-c-name2','xl',$fullName) ?> ay, may loteng sinasaka/tayuan ng bahay, <strong>Lot No.</strong> <?= ei('ba-c-lot','sm','') ?>, Plano Blg <?= ei('ba-c-plano','sm','') ?>, at may sukat na humigit kumulang sa <?= ei('ba-c-sukat','lg','') ?>, at aktuwal na sinasaka/tinitirhan at matatagpuan sa Barangay <?= $BRGY ?>, Talavera, Nueva Ecija.</p>
    <p class="indent">Ang pagpapatunay na ito ay ipinagkaloob kay <?= ei('ba-c-recipient','xl',$fullName) ?>, ngayong <strong>ika-<?= ei('ba-c-day','sm',$dayNum) ?></strong>, ng <?= ei('ba-c-month','md',$monthStr) ?> <strong><?= ei('ba-c-year','sm',$yearStr,'width:60px') ?></strong>, para magamit sa Banko.</p>
  </div>
  <div class="sig-row">
    <div class="sig-col"><div class="sig-label">Inihanda ni:</div><div class="sig-line"></div><div class="sig-name"><?= ei('ba-c-sec','lg',$secretary) ?></div><div class="sig-name">Barangay Secretary</div></div>
    <div class="sig-col" style="align-items:flex-end"><div class="sig-label">Pinatutunayan ni:</div><div class="sig-line"></div><div class="sig-name"><?= ei('ba-c-barc','lg','') ?></div><div class="sig-name">BARC Chairman</div></div>
  </div>
  <div class="sig-center" style="margin-top:24px"><div style="margin-bottom:28px;font-style:italic">Pinatotohanan ni:</div><div style="display:inline-block;text-align:center"><div style="border-top:1.5px solid #000;width:200px;margin:0 auto 4px"></div><div><?= ei('ba-c-puno','lg',$punong) ?></div><div>Punong Barangay</div></div></div>
  <?= wave() ?>
</div>

<?php elseif ($certType === 'Solo Parent'): ?>
<!-- ══ SOLO PARENT ════════════════════════════════════════════════════════ -->
<div class="certificate">
  <div class="corner tl"></div><div class="corner tr"></div><div class="corner bl"></div><div class="corner br"></div>
  <?= hdr($L1,$L2,$BRGY) ?>
  <div class="title-section"><span class="dots-icon">⠿</span><div class="cert-title" style="font-family:'Georgia',serif;font-size:24px;font-style:italic;color:#4a7c2f">Certificate for Solo Parent</div></div>
  <div class="body">
    <p>To whom it may concern;</p>
    <p class="indent">This is to certify that <?= ei('so-c-name','ef-name',$fullName) ?>, <?= ei('so-c-age','sm',$age) ?> years old and with postal address at Purok <?= ei('so-c-purok','sm',$purok) ?>, Barangay <?= $BRGY ?>, Talavera, Nueva Ecija.</p>
    <p class="indent">Further Certified that, <?= ei('so-c-solo','ef-name',$fullName) ?> is a Solo Parent of, Name of Children: <?= ei('so-c-child1','lg','') ?>, <?= ei('so-c-child2','lg','') ?>, <?= ei('so-c-child3','xl','') ?>, with the same address above.</p>
    <p class="indent">This certificate is being issued to the above named person as a requirement for Solo Parent Identification <strong>Card (ID)</strong> at <strong>MSWD</strong> and for whatever Legal Intent and Purposes.</p>
    <p class="indent">Issued this <?= ei('so-c-day','sm',$dayNum) ?> day of <?= ei('so-c-month','md',$monthStr) ?>, 202<?= ei('so-c-year','sm',$yearLast,'width:30px') ?> at Barangay <?= ei('so-c-ibrgy','md',$brgyName) ?>, Talavera, Nueva Ecija.</p>
  </div>
  <div class="sig-printed"><span class="sig-line"></span><div>Signature Over Printed Name</div></div>
  <div class="sig-row">
    <div class="sig-col"><div style="margin-bottom:26px">Prepared by;</div><div class="sig-line"></div><div class="sig-name"><?= ei('so-c-sec','lg',$secretary) ?></div><div class="sig-name">Barangay Secretary</div></div>
    <div class="sig-col" style="align-items:flex-end"><div style="margin-bottom:26px">Certified by;</div><div class="sig-line"></div><div class="sig-name"><?= ei('so-c-puno','lg',$punong) ?></div><div class="sig-name">Punong Barangay</div></div>
  </div>
  <?= wave() ?>
</div>

<?php elseif ($certType === 'Low Income'): ?>
<!-- ══ LOW INCOME ══════════════════════════════════════════════════════════ -->
<div class="certificate">
  <div class="corner tl"></div><div class="corner tr"></div><div class="corner bl"></div><div class="corner br"></div>
  <?= hdr($L1,$L2,$BRGY) ?>
  <div class="title-section"><span class="dots-icon">⠿</span><div class="cert-title" style="font-size:20px">PROOF OF LOW INCOME</div></div>
  <div class="body">
    <p>Sa Kinauukulan;</p>
    <p class="indent">Ito ay pagpapatunay na si <?= ei('lo-c-name','ef-name',$fullName) ?>, <?= ei('lo-c-age','sm',$age) ?> taong gulang, Residente sa Purok <?= ei('lo-c-purok','sm',$purok) ?>, ng Barangay <?= $BRGY ?>, Talavera, Nueva Ecija, ipinanganak noong <?= ei('lo-c-bday','lg',$bday) ?>, ay kasalukuyang nagtatrabaho bilang isang <?= ei('lo-c-work','lg','') ?> at siya ay kumikita ng hindi tataas sa <strong>Php</strong> <?= ei('lo-c-income','md','') ?> sa loob ng isang buwan.</p>
    <p class="indent">Ito ay iginawad ngayon ika-<?= ei('lo-c-day','sm',$dayNum) ?> ng <?= ei('lo-c-month','md',$monthStr) ?>, <?= ei('lo-c-year','sm',$yearStr,'width:60px') ?> sa Tanggapan ng Barangay <?= ei('lo-c-ibrgy','lg',$brgyName) ?>, Talavera, Nueva Ecija.</p>
  </div>
  <div class="sig-section"><div style="font-style:italic;margin-bottom:28px">Inihanda ni,</div><div style="display:inline-block"><div class="sig-line"></div><div><?= ei('lo-c-sec','lg',$secretary) ?></div><div>Barangay Kalihim</div></div></div>
  <div class="sig-right"><div class="sig-label-r">Pinatotohanan ni,</div><div class="sig-line-r"></div><br><div><?= ei('lo-c-puno','lg',$punong) ?></div><div>Punong Barangay</div></div>
  <?= wave() ?>
</div>

<?php else: ?>
<!-- ══ FALLBACK ════════════════════════════════════════════════════════════ -->
<div class="certificate">
  <div class="corner tl"></div><div class="corner tr"></div><div class="corner bl"></div><div class="corner br"></div>
  <?= hdr($L1,$L2,$BRGY) ?>
  <div class="title-section"><span class="dots-icon">⠿</span><div class="cert-title"><?= htmlspecialchars(strtoupper($certType)) ?></div></div>
  <div class="body">
    <p><strong>TO WHOM IT MAY CONCERN:</strong></p>
    <p class="indent">This is to certify that <?= ei('fb-c-name','ef-name',$fullName) ?>, a resident of Barangay <?= $BRGY ?>, Talavera, Nueva Ecija.</p>
    <p class="indent">This certification is issued upon request for <?= eir('fb-c-purpose','md',$purpose) ?> and for whatever legal purpose it may serve.</p>
    <p class="indent">Issued this <?= ei('fb-c-day','sm',$dayNum) ?> day of <?= ei('fb-c-month','md',$monthStr) ?>, <?= ei('fb-c-year','sm',$yearStr,'width:60px') ?> at Barangay <?= ei('fb-c-ibrgy','md',$brgyName) ?>, Talavera, Nueva Ecija.</p>
  </div>
  <div class="sig-row">
    <div class="sig-col"><div class="sig-label">Prepared by:</div><div class="sig-line"></div><div class="sig-name"><?= ei('fb-c-sec','lg',$secretary) ?></div><div class="sig-name">Barangay Secretary</div></div>
    <div class="sig-col" style="align-items:flex-end"><div class="sig-label">Certified by:</div><div class="sig-line"></div><div class="sig-name"><?= ei('fb-c-puno','lg',$punong) ?></div><div class="sig-name">Punong Barangay</div></div>
  </div>
  <?= wave() ?>
</div>
<?php endif; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script>
// Auto-size ALL .ef inputs to fit their value — prevents overflow into surrounding text
(function(){
  var ruler = document.createElement('span');
  ruler.style.cssText = 'position:absolute;visibility:hidden;white-space:pre;font-family:"Times New Roman",Times,serif;font-size:13px;padding:0 3px;pointer-events:none;';
  document.body.appendChild(ruler);

  document.querySelectorAll('.ef').forEach(function(el){
    if (el.classList.contains('block-field')) return; // block fields use 100% width
    var val = el.value || '';
    if (!val) return; // empty — keep min-width
    ruler.style.fontFamily = el.classList.contains('brgy-hdr') ? '"Arial Black",Arial,sans-serif' : '"Times New Roman",Times,serif';
    ruler.style.fontSize   = el.classList.contains('brgy-hdr') ? '15px' : '13px';
    ruler.style.fontWeight = el.classList.contains('brgy-hdr') ? '900' : 'normal';
    ruler.textContent = val;
    var measured = ruler.offsetWidth + 10;
    // enforce the class min-width, but always expand to fit content
    var minW = 40;
    if (el.classList.contains('sm'))       minW = 60;
    else if (el.classList.contains('md'))  minW = 110;
    else if (el.classList.contains('lg'))  minW = 160;
    else if (el.classList.contains('xl'))  minW = 200;
    else if (el.classList.contains('ef-name') || el.classList.contains('brgy-hdr')) minW = 130;
    el.style.width = Math.max(minW, measured) + 'px';
  });
})();

function doPrintPDF() {
  var certEl = document.querySelector('.certificate');
  if (!certEl) return;
  var btn = document.getElementById('btnPrintPDF');
  var orig = btn.innerHTML;
  btn.innerHTML = '⏳ Generating…'; btn.disabled = true;

  var origStyle = certEl.getAttribute('style') || '';
  certEl.style.width = '794px';
  certEl.style.minHeight = 'auto';

  html2canvas(certEl, {
    scale: 2, useCORS: true, allowTaint: true,
    backgroundColor: '#ffffff', logging: false,
    width: certEl.scrollWidth, height: certEl.scrollHeight
  }).then(function(canvas){
    certEl.setAttribute('style', origStyle);
    var imgData = canvas.toDataURL('image/jpeg', 0.95);
    var jsPDF = window.jspdf.jsPDF;
    var pdfW = 210;
    var pdfH = Math.round(pdfW * (canvas.height / canvas.width));
    var pdf = new jsPDF({ orientation: pdfH > pdfW ? 'portrait' : 'landscape', unit: 'mm', format: [pdfW, pdfH] });
    pdf.addImage(imgData, 'JPEG', 0, 0, pdfW, pdfH);
    var name = <?= json_encode(preg_replace('/[^A-Za-z0-9 _-]/', '', $fullName)) ?>;
    var type = <?= json_encode($certType) ?>;
    pdf.save(type.replace(/\s+/g,'_') + '_' + name.replace(/\s+/g,'_') + '.pdf');
    btn.innerHTML = orig; btn.disabled = false;
  }).catch(function(){
    certEl.setAttribute('style', origStyle);
    window.print();
    btn.innerHTML = orig; btn.disabled = false;
  });
}
</script>
</body>
</html>
