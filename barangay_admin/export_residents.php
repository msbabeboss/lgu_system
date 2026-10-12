<?php
require_once __DIR__ . '/../config/config.php';
requireRole('barangay_admin');

$db = Database::getInstance()->getConnection();
$u = currentUser();
$bid = (int)$u['barangay_id'];

$residents = $db->prepare("SELECT r.*, a.purok, a.street, a.barangay as addr_barangay, a.city FROM residents r LEFT JOIN addresses a ON r.address_id=a.id WHERE r.barangay_id=? AND r.resident_status != 'Inactive' ORDER BY r.last_name, r.first_name");
$residents->execute([$bid]);
$residents = $residents->fetchAll();

$brgy = $db->prepare("SELECT * FROM barangays WHERE id=?");
$brgy->execute([$bid]);
$brgy = $brgy->fetch();

logActivity($u['id'], 'EXPORT_RESIDENTS', 'Residents', 'Exported residents list');
?>
<!DOCTYPE html>
<html>
<head>
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favcon.png">
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favcon.png">
    <meta charset="UTF-8">
    <title>Residents Report</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 11px; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { border: 1px solid #333; padding: 5px 8px; }
        th { background: #1a4f7a; color: white; }
        h2, p { text-align: center; margin: 2px; }
        .no-print { margin-bottom: 15px; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>
<div class="no-print">
    <button onclick="window.print()">🖨️ Print / Save as PDF</button>
    <button onclick="window.close()">✕ Close</button>
</div>
<h2>Barangay <?= htmlspecialchars($brgy['barangay_name']) ?></h2>
<h2>Residents Masterlist</h2>
<p><?= htmlspecialchars($brgy['municipality']) ?> | Generated: <?= date('F d, Y h:i A') ?></p>
<table>
    <thead>
        <tr><th>#</th><th>Full Name</th><th>Gender</th><th>Birthdate</th><th>Age</th><th>Civil Status</th><th>Address</th><th>Contact</th><th>Email</th><th>Voter</th><th>PWD</th><th>Senior</th><th>4Ps</th><th>Solo</th><th>Occupation</th><th>Status</th></tr>
    </thead>
    <tbody>
    <?php foreach ($residents as $i => $r): ?>
        <tr>
            <td><?= $i+1 ?></td>
            <td><?= htmlspecialchars($r['last_name'].', '.$r['first_name'].($r['middle_name']?' '.$r['middle_name']:'').($r['suffix']?' '.$r['suffix']:'')) ?></td>
            <td><?= $r['gender'] ?></td>
            <td><?= date('m/d/Y', strtotime($r['birthdate'])) ?></td>
            <td><?= $r['age'] ?></td>
            <td><?= $r['civil_status'] ?></td>
            <td><?= htmlspecialchars(($r['purok']?'Purok '.$r['purok'].', ':'').($r['addr_barangay']??'')) ?></td>
            <td><?= htmlspecialchars($r['contact_number'] ?? '') ?></td>
            <td><?= htmlspecialchars($r['email'] ?? '') ?></td>
            <td><?= $r['voter_status'] === 'Registered' ? 'Yes' : 'No' ?></td>
            <td><?= $r['is_pwd'] ? 'Yes' : 'No' ?></td>
            <td><?= $r['is_senior'] ? 'Yes' : 'No' ?></td>
            <td><?= $r['is_4ps'] ? 'Yes' : 'No' ?></td>
            <td><?= $r['is_solo_parent'] ? 'Yes' : 'No' ?></td>
            <td><?= $r['occupation_status'] ?></td>
            <td><?= $r['resident_status'] ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<p style="margin-top:10px;">Total: <?= count($residents) ?> residents</p>
</body>
</html>
