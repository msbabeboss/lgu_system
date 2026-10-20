<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/mailer.php';
requireRole('barangay_admin');

$db  = Database::getInstance()->getConnection();
$u   = currentUser();
$bid = (int)$u['barangay_id'];

// ── Safe schema migrations (run once, silently) ──────────────────────────
$migrations = [
    "ALTER TABLE blotter_cases MODIFY COLUMN status ENUM('Open','Under Investigation','Referred','Escalated','Settled','Dismissed','Closed','Archived') DEFAULT 'Open'",
    "ALTER TABLE blotter_cases ADD COLUMN case_title VARCHAR(200) NULL AFTER case_number",
    "ALTER TABLE blotter_cases ADD COLUMN incident_type VARCHAR(50) DEFAULT 'Other' AFTER case_title",
    "ALTER TABLE blotter_cases ADD COLUMN complainant_name_manual VARCHAR(150) AFTER respondent_id",
    "ALTER TABLE blotter_cases ADD COLUMN complainant_address VARCHAR(200) AFTER complainant_name_manual",
    "ALTER TABLE blotter_cases ADD COLUMN complainant_contact VARCHAR(20) AFTER complainant_address",
    "ALTER TABLE blotter_cases ADD COLUMN complainant_gender VARCHAR(10) AFTER complainant_contact",
    "ALTER TABLE blotter_cases ADD COLUMN complainant_age TINYINT UNSIGNED AFTER complainant_gender",
    "ALTER TABLE blotter_cases ADD COLUMN respondent_name_manual VARCHAR(150) AFTER complainant_age",
    "ALTER TABLE blotter_cases ADD COLUMN respondent_address VARCHAR(200) AFTER respondent_name_manual",
    "ALTER TABLE blotter_cases ADD COLUMN respondent_contact VARCHAR(20) AFTER respondent_address",
    "ALTER TABLE blotter_cases ADD COLUMN respondent_relationship VARCHAR(100) AFTER respondent_contact",
    "ALTER TABLE blotter_cases ADD COLUMN officer_assigned VARCHAR(150) AFTER action_taken",
    "ALTER TABLE blotter_cases ADD COLUMN date_of_action DATE AFTER officer_assigned",
    "ALTER TABLE blotter_cases ADD COLUMN settlement_agreement TINYINT(1) DEFAULT 0 AFTER date_of_action",
    "ALTER TABLE blotter_cases ADD COLUMN resolution VARCHAR(30) DEFAULT 'Pending' AFTER settlement_agreement",
    "ALTER TABLE blotter_cases ADD COLUMN remarks TEXT AFTER resolution",
    "ALTER TABLE blotter_cases ADD COLUMN date_closed DATE AFTER remarks",
    "ALTER TABLE blotter_cases ADD COLUMN date_reported DATETIME DEFAULT CURRENT_TIMESTAMP AFTER date_closed",
    "ALTER TABLE blotter_cases ADD COLUMN priority VARCHAR(10) DEFAULT 'Medium' AFTER incident_type",
    "ALTER TABLE blotter_cases ADD COLUMN incident_summary TEXT AFTER narrative",
    "ALTER TABLE blotter_cases ADD COLUMN detailed_narrative TEXT AFTER incident_summary",
    "ALTER TABLE blotter_cases ADD COLUMN recorded_by VARCHAR(150) AFTER detailed_narrative",
    "ALTER TABLE blotter_cases ADD COLUMN date_recorded DATETIME AFTER recorded_by",
    "ALTER TABLE blotter_cases ADD COLUMN settlement_date DATE AFTER settlement_agreement",
    "CREATE TABLE IF NOT EXISTS blotter_attachments (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, blotter_id INT UNSIGNED NOT NULL, file_name VARCHAR(255) NOT NULL, file_path VARCHAR(255) NOT NULL, file_type VARCHAR(50), description VARCHAR(255), uploaded_by VARCHAR(150), uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (blotter_id) REFERENCES blotter_cases(id) ON DELETE CASCADE) ENGINE=InnoDB",
    "ALTER TABLE blotter_attachments ADD COLUMN description VARCHAR(255) AFTER file_type",
    "ALTER TABLE blotter_attachments ADD COLUMN uploaded_by VARCHAR(150) AFTER description",
    "CREATE TABLE IF NOT EXISTS blotter_persons_involved (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, blotter_id INT UNSIGNED NOT NULL, resident_id INT UNSIGNED DEFAULT NULL, full_name VARCHAR(200) NOT NULL, address VARCHAR(255) DEFAULT NULL, contact VARCHAR(20) DEFAULT NULL, gender VARCHAR(10) DEFAULT NULL, age TINYINT UNSIGNED DEFAULT NULL, role VARCHAR(50) DEFAULT 'Person Involved', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (blotter_id) REFERENCES blotter_cases(id) ON DELETE CASCADE) ENGINE=InnoDB",
    "CREATE TABLE IF NOT EXISTS blotter_timeline (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, blotter_id INT UNSIGNED NOT NULL, event VARCHAR(200) NOT NULL, status VARCHAR(50) DEFAULT NULL, actor VARCHAR(150) DEFAULT NULL, note TEXT DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (blotter_id) REFERENCES blotter_cases(id) ON DELETE CASCADE) ENGINE=InnoDB",
];
foreach ($migrations as $sql) { try { $db->exec($sql); } catch (PDOException $e) {} }

$incidentTypes    = ['Theft','Physical Assault','Noise Complaint','Domestic Violence','Trespassing','Vandalism','Threat / Intimidation','Estafa / Fraud','Illegal Drugs','Neighborhood Dispute','Animal Bite','Traffic Incident','Other'];
$statusOptions    = ['Open','Under Investigation','Referred','Escalated','Settled','Dismissed','Closed'];
$resolutionOptions= ['Pending','Settled','Referred','Dismissed'];
$statusColors     = ['Open'=>'warning','Under Investigation'=>'info','Referred'=>'primary','Escalated'=>'danger','Settled'=>'success','Dismissed'=>'dark','Closed'=>'secondary'];
$priorityColors   = ['Low'=>'success','Medium'=>'warning','High'=>'danger'];

// ── View single case (GET ?view=ID) ─────────────────────────────────────
$viewCaseId = (int)($_GET['view'] ?? 0);
$viewCase = null; $witnesses = []; $attachments = []; $timeline = []; $personsInvolved = [];
if ($viewCaseId) {
    $stmt = $db->prepare("SELECT bc.*,
        COALESCE(CONCAT(c.last_name,', ',c.first_name,' ',COALESCE(c.middle_name,'')), bc.complainant_name_manual) as complainant_display,
        COALESCE(CONCAT(r.last_name,', ',r.first_name,' ',COALESCE(r.middle_name,'')), bc.respondent_name_manual) as respondent_display,
        c.birthdate as complainant_birthdate, c.gender as complainant_gender_db,
        c.contact_number as complainant_contact_db,
        r.birthdate as respondent_birthdate, r.gender as respondent_gender_db,
        r.contact_number as respondent_contact_db
        FROM blotter_cases bc
        LEFT JOIN residents c ON bc.complainant_id=c.id
        LEFT JOIN residents r ON bc.respondent_id=r.id
        WHERE bc.id=? AND bc.barangay_id=?");
    $stmt->execute([$viewCaseId, $bid]);
    $viewCase = $stmt->fetch();

    if ($viewCase) {
        $wStmt = $db->prepare("SELECT * FROM blotter_witnesses WHERE blotter_id=?");
        $wStmt->execute([$viewCaseId]);
        $witnesses = $wStmt->fetchAll();

        $aStmt = $db->prepare("SELECT * FROM blotter_attachments WHERE blotter_id=? ORDER BY uploaded_at DESC");
        $aStmt->execute([$viewCaseId]);
        $attachments = $aStmt->fetchAll();

        // Persons involved
        $piStmt = $db->prepare("SELECT bpi.*, COALESCE(CONCAT(res.last_name,', ',res.first_name), bpi.full_name) as display_name FROM blotter_persons_involved bpi LEFT JOIN residents res ON bpi.resident_id=res.id WHERE bpi.blotter_id=? ORDER BY bpi.id");
        $piStmt->execute([$viewCaseId]);
        $personsInvolved = $piStmt->fetchAll();

        $tStmt = $db->prepare("SELECT bt.*, bt.actor as user_name
            FROM blotter_timeline bt
            WHERE bt.blotter_id=?
            ORDER BY bt.created_at ASC");
        $tStmt->execute([$viewCaseId]);
        $timeline = $tStmt->fetchAll();
    }
}

// ── POST handlers ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrAbort();
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $data = [
            'case_title'              => sanitize($_POST['case_title'] ?? ''),
            'incident_type'           => $_POST['incident_type'] ?? 'Other',
            'priority'                => $_POST['priority'] ?? 'Medium',
            'incident_date'           => $_POST['incident_date'],
            'incident_time'           => $_POST['incident_time'] ?: null,
            'date_reported'           => $_POST['date_reported'] ?: date('Y-m-d H:i:s'),
            'location'                => sanitize($_POST['location']),
            'narrative'               => sanitize($_POST['narrative']),
            'incident_summary'        => sanitize($_POST['incident_summary'] ?? ''),
            'detailed_narrative'      => sanitize($_POST['detailed_narrative'] ?? ''),
            'status'                  => $_POST['status'],
            'action_taken'            => sanitize($_POST['action_taken'] ?? ''),
            'officer_assigned'        => sanitize($_POST['officer_assigned'] ?? ''),
            'date_of_action'          => $_POST['date_of_action'] ?: null,
            'settlement_agreement'    => (int)($_POST['settlement_agreement'] ?? 0) === 1 ? 1 : 0,
            'settlement_date'         => $_POST['settlement_date'] ?: null,
            'resolution'              => $_POST['resolution'] ?? 'Pending',
            'remarks'                 => sanitize($_POST['remarks'] ?? ''),
            'date_closed'             => $_POST['date_closed'] ?: null,
            'recorded_by'             => sanitize($_POST['recorded_by'] ?? $u['name']),
            'date_recorded'           => $_POST['date_recorded'] ?: date('Y-m-d H:i:s'),
            'complainant_id'          => (int)$_POST['complainant_id'] ?: null,
            'complainant_name_manual' => sanitize($_POST['complainant_name_manual'] ?? ''),
            'complainant_address'     => sanitize($_POST['complainant_address'] ?? ''),
            'complainant_contact'     => sanitize($_POST['complainant_contact'] ?? ''),
            'complainant_gender'      => $_POST['complainant_gender'] ?? '',
            'complainant_age'         => (int)$_POST['complainant_age'] ?: null,
            'respondent_id'           => (int)$_POST['respondent_id'] ?: null,
            'respondent_name_manual'  => sanitize($_POST['respondent_name_manual'] ?? ''),
            'respondent_address'      => sanitize($_POST['respondent_address'] ?? ''),
            'respondent_contact'      => sanitize($_POST['respondent_contact'] ?? ''),
            'respondent_relationship' => sanitize($_POST['respondent_relationship'] ?? ''),
        ];

        if ($action === 'add') {
            $caseNum = generateCaseNumber($bid);
            $db->prepare("INSERT INTO blotter_cases (barangay_id,case_number,case_title,incident_type,priority,complainant_id,complainant_name_manual,complainant_address,complainant_contact,complainant_gender,complainant_age,respondent_id,respondent_name_manual,respondent_address,respondent_contact,respondent_relationship,incident_date,incident_time,date_reported,location,narrative,incident_summary,detailed_narrative,status,action_taken,officer_assigned,date_of_action,settlement_agreement,settlement_date,resolution,remarks,date_closed,recorded_by,date_recorded,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
              ->execute([$bid,$caseNum,$data['case_title'],$data['incident_type'],$data['priority'],$data['complainant_id'],$data['complainant_name_manual'],$data['complainant_address'],$data['complainant_contact'],$data['complainant_gender'],$data['complainant_age'],$data['respondent_id'],$data['respondent_name_manual'],$data['respondent_address'],$data['respondent_contact'],$data['respondent_relationship'],$data['incident_date'],$data['incident_time'],$data['date_reported'],$data['location'],$data['narrative'],$data['incident_summary'],$data['detailed_narrative'],$data['status'],$data['action_taken'],$data['officer_assigned'],$data['date_of_action'],$data['settlement_agreement'],$data['settlement_date'],$data['resolution'],$data['remarks'],$data['date_closed'],$data['recorded_by'],$data['date_recorded'],$u['id']]);
            $blotterId = $db->lastInsertId();

            foreach ($_POST['witness_name'] ?? [] as $k => $wn) {
                if (trim($wn)) {
                    $db->prepare("INSERT INTO blotter_witnesses (blotter_id,name,address,contact) VALUES (?,?,?,?)")
                       ->execute([$blotterId, sanitize($wn), sanitize($_POST['witness_address'][$k]??''), sanitize($_POST['witness_contact'][$k]??'')]);
                }
            }

            // Persons Involved
            foreach ($_POST['pi_name'] ?? [] as $k => $pn) {
                if (trim($pn)) {
                    $db->prepare("INSERT INTO blotter_persons_involved (blotter_id,resident_id,full_name,address,contact,gender,age,role) VALUES (?,?,?,?,?,?,?,?)")
                       ->execute([$blotterId,
                           (int)($_POST['pi_resident_id'][$k] ?? 0) ?: null,
                           sanitize($pn),
                           sanitize($_POST['pi_address'][$k] ?? ''),
                           sanitize($_POST['pi_contact'][$k] ?? ''),
                           sanitize($_POST['pi_gender'][$k] ?? ''),
                           (int)($_POST['pi_age'][$k] ?? 0) ?: null,
                           sanitize($_POST['pi_role'][$k] ?? 'Person Involved'),
                       ]);
                }
            }
            if (!empty($_FILES['attachments']['name'][0])) {
                $uploadDir = __DIR__ . '/../uploads/blotter/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                foreach ($_FILES['attachments']['name'] as $k => $fname) {
                    if ($_FILES['attachments']['error'][$k] === 0 && $fname) {
                        $ext  = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
                        $safe = 'blotter_' . $blotterId . '_' . time() . '_' . $k . '.' . $ext;
                        if (move_uploaded_file($_FILES['attachments']['tmp_name'][$k], $uploadDir . $safe)) {
                            $evType  = sanitize($_POST['ev_type'][$k] ?? '');
                            $evDesc  = sanitize($_POST['ev_description'][$k] ?? '');
                            $evDate  = $_POST['ev_date_submitted'][$k] ?? null;
                            $evBy    = sanitize($_POST['ev_uploaded_by'][$k] ?? $u['name']);
                            // Combine evidence type + description for the description field
                            $descFull = trim(($evType ? "[$evType] " : '') . $evDesc);
                            $uploadedAt = ($evDate && strtotime($evDate)) ? $evDate . ' 00:00:00' : date('Y-m-d H:i:s');
                            $db->prepare("INSERT INTO blotter_attachments (blotter_id,file_name,file_path,file_type,description,uploaded_by,uploaded_at) VALUES (?,?,?,?,?,?,?)")
                               ->execute([$blotterId, $fname, 'uploads/blotter/' . $safe, $ext, $descFull, $evBy, $uploadedAt]);
                        }
                    }
                }
            }
            logActivity($u['id'], 'ADD_BLOTTER', 'Blotter', "Case: $caseNum");
            // ── Auto-log: Case Created ──
            $db->prepare("INSERT INTO blotter_timeline (blotter_id,event,status,actor,note) VALUES (?,?,?,?,?)")
               ->execute([$blotterId, 'Case Created', $data['status'], $u['name'],
                   'Case #' . $caseNum . ' filed. Incident: ' . ($data['incident_type'] ?? '') . ' at ' . ($data['location'] ?? '')]);
            flash('blotter', "Blotter case <strong>$caseNum</strong> created successfully.", 'success');
        } else {
            $id = (int)$_POST['blotter_id'];
            $row2 = $db->prepare("SELECT case_number FROM blotter_cases WHERE id=? AND barangay_id=?");
            $row2->execute([$id, $bid]);
            $caseNum = $row2->fetchColumn() ?: 'unknown';
            $db->prepare("UPDATE blotter_cases SET case_title=?,incident_type=?,priority=?,complainant_id=?,complainant_name_manual=?,complainant_address=?,complainant_contact=?,complainant_gender=?,complainant_age=?,respondent_id=?,respondent_name_manual=?,respondent_address=?,respondent_contact=?,respondent_relationship=?,incident_date=?,incident_time=?,date_reported=?,location=?,narrative=?,incident_summary=?,detailed_narrative=?,status=?,action_taken=?,officer_assigned=?,date_of_action=?,settlement_agreement=?,settlement_date=?,resolution=?,remarks=?,date_closed=?,recorded_by=?,date_recorded=? WHERE id=? AND barangay_id=?")
              ->execute([$data['case_title'],$data['incident_type'],$data['priority'],$data['complainant_id'],$data['complainant_name_manual'],$data['complainant_address'],$data['complainant_contact'],$data['complainant_gender'],$data['complainant_age'],$data['respondent_id'],$data['respondent_name_manual'],$data['respondent_address'],$data['respondent_contact'],$data['respondent_relationship'],$data['incident_date'],$data['incident_time'],$data['date_reported'],$data['location'],$data['narrative'],$data['incident_summary'],$data['detailed_narrative'],$data['status'],$data['action_taken'],$data['officer_assigned'],$data['date_of_action'],$data['settlement_agreement'],$data['settlement_date'],$data['resolution'],$data['remarks'],$data['date_closed'],$data['recorded_by'],$data['date_recorded'],$id,$bid]);
            logActivity($u['id'], 'EDIT_BLOTTER', 'Blotter', "Updated blotter case $caseNum");
            // ── Auto-log: detect what changed ──
            $prev = $db->prepare("SELECT status, resolution, officer_assigned, action_taken FROM blotter_cases WHERE id=?");
            // We already ran the UPDATE, so fetch current saved values to compare with prior
            // Use a before-snapshot approach: re-fetch after update and compare with $data
            $snap = $db->prepare("SELECT status, resolution, officer_assigned FROM blotter_cases WHERE id=?");
            $snap->execute([$id]);
            $saved = $snap->fetch();
            // Fetch old timeline last status to detect change
            $lastEvt = $db->prepare("SELECT status FROM blotter_timeline WHERE blotter_id=? ORDER BY id DESC LIMIT 1");
            $lastEvt->execute([$id]);
            $lastStatus = $lastEvt->fetchColumn() ?: null;

            $tEvent = 'Case Updated';
            $tNote  = [];
            if ($lastStatus && $lastStatus !== $data['status']) {
                $tEvent  = 'Status Changed: ' . $lastStatus . ' → ' . $data['status'];
                $tNote[] = 'Status changed from "' . $lastStatus . '" to "' . $data['status'] . '"';
            }
            if (!empty($data['action_taken'])) {
                $tNote[] = 'Action: ' . mb_substr($data['action_taken'], 0, 120);
            }
            if (!empty($data['officer_assigned'])) {
                $tNote[] = 'Officer: ' . $data['officer_assigned'];
            }
            if (!empty($data['resolution']) && $data['resolution'] !== 'Pending') {
                $tNote[] = 'Resolution: ' . $data['resolution'];
            }
            $db->prepare("INSERT INTO blotter_timeline (blotter_id,event,status,actor,note) VALUES (?,?,?,?,?)")
               ->execute([$id, $tEvent, $data['status'], $u['name'], implode(' · ', $tNote) ?: null]);
            // Re-save persons involved
            $db->prepare("DELETE FROM blotter_persons_involved WHERE blotter_id=?")->execute([$id]);
            foreach ($_POST['pi_name'] ?? [] as $k => $pn) {
                if (trim($pn)) {
                    $db->prepare("INSERT INTO blotter_persons_involved (blotter_id,resident_id,full_name,address,contact,gender,age,role) VALUES (?,?,?,?,?,?,?,?)")
                       ->execute([$id,
                           (int)($_POST['pi_resident_id'][$k] ?? 0) ?: null,
                           sanitize($pn),
                           sanitize($_POST['pi_address'][$k] ?? ''),
                           sanitize($_POST['pi_contact'][$k] ?? ''),
                           sanitize($_POST['pi_gender'][$k] ?? ''),
                           (int)($_POST['pi_age'][$k] ?? 0) ?: null,
                           sanitize($_POST['pi_role'][$k] ?? 'Person Involved'),
                       ]);
                }
            }
            flash('blotter', 'Blotter case updated.', 'success');
        }

        // ── Real-time notification for add/edit blotter ──
        if ($action === 'add') {
            $notifMsg  = "New blotter case filed: <strong>$caseNum</strong> — {$data['case_title']}";
            $notifType = ($data['priority'] === 'High' || $data['priority'] === 'Critical') ? 'danger' : 'warning';
        } else {
            $notifMsg  = "Blotter case <strong>$caseNum</strong> updated — Status: {$data['status']}";
            $notifType = 'info';
        }
        sendNotification($u['id'], $notifMsg, $notifType, 'transaction');
        notifyBarangayAdmins($bid, $notifMsg, $notifType, 'transaction', $u['id']);

        // Email super admins when a NEW blotter case is filed
        if ($action === 'add') {
            $superAdmins = $db->query("SELECT id, email, CONCAT(first_name,' ',last_name) as name FROM users WHERE role='super_admin' AND status='active' AND email != ''")->fetchAll();
            if (!empty($superAdmins)) {
                $brgyNameForEmail = $db->query("SELECT barangay_name FROM barangays WHERE id=$bid")->fetchColumn() ?: '';
                sendBlotterFiledEmail(
                    $superAdmins,
                    $caseNum,
                    $data['case_title'],
                    $data['incident_type'],
                    $data['priority'],
                    $data['location'],
                    $data['incident_date'],
                    $brgyNameForEmail,
                    $u['name'],
                    $u['email']
                );
            }
        }

        redirect(BASE_URL . '/barangay_admin/blotter.php');
    }

    if ($action === 'archive') {
        $id  = (int)$_POST['blotter_id'];
        $row = $db->prepare("SELECT * FROM blotter_cases WHERE id=? AND barangay_id=?");
        $row->execute([$id, $bid]);
        $case = $row->fetch();
        if ($case) {
            archiveRecord($u['id'], 'blotter_cases', $id, $case);
            $db->prepare("UPDATE blotter_cases SET status='Archived' WHERE id=? AND barangay_id=?")->execute([$id, $bid]);
            flash('blotter', 'Blotter case archived.', 'success');
        }
        redirect(BASE_URL . '/barangay_admin/blotter.php');
    }
}

// ── Filters & Pagination ─────────────────────────────────────────────────
$search       = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$typeFilter   = trim($_GET['type'] ?? '');
$dateFrom     = trim($_GET['date_from'] ?? '');
$dateTo       = trim($_GET['date_to'] ?? '');

// Whitelist status to prevent SQL injection (only allow known values)
$allowedStatuses = ['Open','Under Investigation','Referred','Escalated','Settled','Dismissed','Closed'];
if ($statusFilter && !in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}
// Whitelist incident type
if ($typeFilter && !in_array($typeFilter, $incidentTypes, true)) {
    $typeFilter = '';
}
// Sanitize for display only
$searchDisplay  = htmlspecialchars($search, ENT_QUOTES, 'UTF-8');
$dateFromDisplay= htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8');
$dateToDisplay  = htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8');

// Resolve preset to date range
$datePreset = trim($_GET['date_preset'] ?? '');
if ($datePreset && $datePreset !== 'custom') {
    $today = date('Y-m-d');
    switch ($datePreset) {
        case 'today':    $dateFrom = $today; $dateTo = $today; break;
        case 'last_day': $dateFrom = date('Y-m-d', strtotime('-1 day')); $dateTo = $today; break;
        case 'week':     $dateFrom = date('Y-m-d', strtotime('-7 days')); $dateTo = $today; break;
        case 'month':    $dateFrom = date('Y-m-d', strtotime('-30 days')); $dateTo = $today; break;
        case 'quarter':  $dateFrom = date('Y-m-d', strtotime('-90 days')); $dateTo = $today; break;
        case 'year':     $dateFrom = date('Y-01-01'); $dateTo = $today; break;
    }
}

// ── Build query with proper prepared-statement bindings ──────────────────
$whereClauses = ["bc.barangay_id = ?", "bc.status != 'Archived'"];
$params       = [$bid];

if ($search !== '') {
    $like = '%' . $search . '%';
    $whereClauses[] = "(
        bc.case_number LIKE ? OR bc.location LIKE ? OR
        COALESCE(c.first_name,'') LIKE ? OR COALESCE(c.last_name,'') LIKE ? OR COALESCE(c.middle_name,'') LIKE ? OR
        COALESCE(CONCAT(c.first_name,' ',c.last_name),'') LIKE ? OR
        COALESCE(CONCAT(c.last_name,' ',c.first_name),'') LIKE ? OR
        COALESCE(CONCAT(c.first_name,' ',c.middle_name,' ',c.last_name),'') LIKE ? OR
        COALESCE(CONCAT(c.last_name,', ',c.first_name),'') LIKE ? OR
        COALESCE(bc.complainant_name_manual,'') LIKE ? OR
        COALESCE(r.first_name,'') LIKE ? OR COALESCE(r.last_name,'') LIKE ? OR COALESCE(r.middle_name,'') LIKE ? OR
        COALESCE(CONCAT(r.first_name,' ',r.last_name),'') LIKE ? OR
        COALESCE(CONCAT(r.last_name,' ',r.first_name),'') LIKE ? OR
        COALESCE(CONCAT(r.first_name,' ',r.middle_name,' ',r.last_name),'') LIKE ? OR
        COALESCE(CONCAT(r.last_name,', ',r.first_name),'') LIKE ? OR
        COALESCE(bc.respondent_name_manual,'') LIKE ?
    )";
    // 18 placeholders
    for ($i = 0; $i < 18; $i++) $params[] = $like;
}
if ($statusFilter !== '') {
    $whereClauses[] = "bc.status = ?";
    $params[]       = $statusFilter;
}
if ($typeFilter !== '') {
    $whereClauses[] = "bc.incident_type = ?";
    $params[]       = $typeFilter;
}
if ($dateFrom !== '') {
    $whereClauses[] = "bc.incident_date >= ?";
    $params[]       = $dateFrom;
}
if ($dateTo !== '') {
    $whereClauses[] = "bc.incident_date <= ?";
    $params[]       = $dateTo;
}

$whereSQL = implode(' AND ', $whereClauses);

$perPage     = 10;
$currentPage = max(1, (int)($_GET['page'] ?? 1));

$countStmt = $db->prepare("SELECT COUNT(*) FROM blotter_cases bc LEFT JOIN residents c ON bc.complainant_id=c.id LEFT JOIN residents r ON bc.respondent_id=r.id WHERE $whereSQL");
$countStmt->execute($params);
$totalRows   = (int)$countStmt->fetchColumn();
$totalPages  = max(1, (int)ceil($totalRows / $perPage));
$currentPage = min($currentPage, $totalPages);
$offset      = ($currentPage - 1) * $perPage;

$dataStmt = $db->prepare("SELECT bc.*, COALESCE(CONCAT(c.last_name,', ',c.first_name), bc.complainant_name_manual) as complainant_display, COALESCE(CONCAT(r.last_name,', ',r.first_name), bc.respondent_name_manual) as respondent_display FROM blotter_cases bc LEFT JOIN residents c ON bc.complainant_id=c.id LEFT JOIN residents r ON bc.respondent_id=r.id WHERE $whereSQL ORDER BY bc.created_at DESC LIMIT $perPage OFFSET $offset");
$dataStmt->execute($params);
$cases = $dataStmt->fetchAll();

$residents = $db->prepare("SELECT id, CONCAT(last_name,', ',first_name) as full_name FROM residents WHERE barangay_id=? AND resident_status='Active' ORDER BY last_name");
$residents->execute([$bid]);
$residents = $residents->fetchAll();

$flash     = getFlash('blotter');
?>
<?php
$pageIcon     = 'bi-shield-fill-exclamation';
$pageTitle    = 'Blotter Management';
$pageSubtitle = 'Record and track blotter cases and complaints.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favcon.png">
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/favcon.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrfMeta() ?>">
    <title><?= $pageTitle ?> — <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=3.0">
    <style>
        .btn-xs { padding:2px 8px; font-size:0.75rem; }
        .priority-card { border:2px solid #dee2e6; border-radius:10px; padding:10px 16px; cursor:pointer; transition:all 0.2s; text-align:center; }
        .priority-card.selected-low  { border-color:#198754; background:#d1e7dd; }
        .priority-card.selected-med  { border-color:#ffc107; background:#fff3cd; }
        .priority-card.selected-high { border-color:#dc3545; background:#f8d7da; }
        .tab-content-step { display:none; }
        .tab-content-step.active { display:block; }

        /* ── Wizard progress bar ── */
        .wizard-progress-bar { display:flex; align-items:center; background:#fff; border-radius:12px; padding:14px 16px; box-shadow:0 2px 8px rgba(0,0,0,0.06); border:1px solid #d0ecd6; }
        .wizard-step-btn { background:none; border:none; cursor:pointer; display:flex; flex-direction:column; align-items:center; gap:5px; flex:0 0 auto; padding:0 2px; }
        .wizard-step-circle { width:30px; height:30px; border-radius:50%; background:#dee2e6; color:#666; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:0.78rem; transition:all 0.3s; flex-shrink:0; }
        .wizard-step-circle.active { background:#1a4f7a; color:#fff; box-shadow:0 0 0 3px rgba(26,79,122,0.2); }
        .wizard-step-circle.done   { background:#198754; color:#fff; }
        .wizard-step-label { font-size:0.66rem; color:#3a7fa8; font-weight:600; white-space:nowrap; }
        .wizard-step-line { flex:1; height:2px; background:#dee2e6; transition:background 0.3s; min-width:8px; }
        .wizard-step-line.done { background:#198754; }

        /* ── Detail view tabs ── */
        .detail-tab-btn { background:none; border:none; border-bottom:3px solid transparent; padding:8px 12px; font-size:0.82rem; font-weight:600; color:#3a7fa8; cursor:pointer; transition:all 0.2s; white-space:nowrap; }
        .detail-tab-btn.active { color:#1a4f7a; border-bottom-color:#1a4f7a; }

        .timeline-item { display:flex; gap:12px; padding:10px 0; border-left:2px solid #d0ecd6; margin-left:14px; padding-left:16px; position:relative; }
        .timeline-dot  { position:absolute; left:-9px; top:14px; width:16px; height:16px; border-radius:50%; background:#1a4f7a; border:2px solid #fff; box-shadow:0 0 0 2px #1a4f7a; }
        .resident-toggle-btn { flex:1; padding:8px; border-radius:8px; border:2px solid #dee2e6; background:#fff; color:#3a7fa8; font-weight:600; cursor:pointer; font-size:0.82rem; transition:all 0.2s; }
        .resident-toggle-btn.active { border-color:#1a4f7a; background:#1a4f7a; color:#fff; }
        @media (min-width:769px) { .modal { padding-left: var(--sidebar-width) !important; } }
        .person-card { background:rgba(26,79,122,0.04); border:1px solid rgba(26,79,122,0.12); border-radius:8px; padding:12px; margin-top:8px; font-size:0.82rem; }
        .evidence-type-badge { font-size:0.7rem; padding:2px 8px; border-radius:20px; }

        /* ── Evidence grid (Step 4) ── */
        .ev-grid { display:grid; grid-template-columns:120px 1fr 150px 106px 120px 32px; gap:5px; align-items:center; }
        .ev-grid-header { background:#f4f7fa; border-radius:7px 7px 0 0; padding:7px 10px; font-size:0.68rem; font-weight:700; text-transform:uppercase; color:#3a7fa8; letter-spacing:0.05em; border:1px solid #d0ecd6; border-bottom:none; }
        .ev-grid-row { padding:6px 10px; border-bottom:1px solid #eef4f8; background:#fff; }
        .ev-grid-row:last-child { border-bottom:none; }
        .ev-subtext { font-weight:400; text-transform:none; font-size:0.63rem; color:#6c757d; display:block; margin-top:1px; }
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

<?php if ($viewCase): ?>
<!-- ══════════════════════════════════════════════════════
     CASE DETAIL VIEW
     ══════════════════════════════════════════════════════ -->
<?php
$vc = $viewCase;
$statusBadge   = $statusColors[$vc['status']] ?? 'secondary';
$priorityBadge = $priorityColors[$vc['priority'] ?? 'Medium'] ?? 'warning';
$compName    = trim($vc['complainant_display'] ?? $vc['complainant_name_manual'] ?? 'N/A');
$respName    = trim($vc['respondent_display']  ?? $vc['respondent_name_manual']  ?? 'N/A');
$compGender  = $vc['complainant_gender_db'] ?? $vc['complainant_gender'] ?? '—';
$compContact = $vc['complainant_contact_db'] ?? $vc['complainant_contact'] ?? '—';
$respGender  = $vc['respondent_gender_db'] ?? $vc['respondent_relationship'] ?? '—';
$respContact = $vc['respondent_contact_db'] ?? $vc['respondent_contact'] ?? '—';
$compAge = '—';
if (!empty($vc['complainant_birthdate'])) {
    $compAge = (new DateTime())->diff(new DateTime($vc['complainant_birthdate']))->y . ' yrs';
} elseif (!empty($vc['complainant_age'])) {
    $compAge = $vc['complainant_age'] . ' yrs';
}
?>
<!-- Back & Header Card -->
<div class="mb-3 d-flex align-items-center gap-2">
    <a href="<?= BASE_URL ?>/barangay_admin/blotter.php" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back to Cases
    </a>
    <span class="text-muted small"></span>
</div>

<div style="background:#fff;border-radius:14px;box-shadow:0 2px 16px rgba(0,0,0,0.08);border:1px solid #d0ecd6;margin-bottom:16px;overflow:hidden;">
    <div style="background:linear-gradient(90deg,#1a4f7a,#3a7fa8);padding:18px 24px;">
        <div class="d-flex align-items-start justify-content-between flex-wrap gap-3">
            <div>
                <div style="font-size:0.75rem;color:rgba(255,255,255,0.7);text-transform:uppercase;letter-spacing:0.08em;margin-bottom:4px;">Blotter Case</div>
                <div style="font-size:1.3rem;font-weight:800;color:#fff;letter-spacing:0.02em;">CASE # <?= htmlspecialchars($vc['case_number']) ?></div>
                <?php if (!empty($vc['case_title'])): ?>
                <div style="font-size:0.9rem;color:rgba(255,255,255,0.85);margin-top:3px;font-weight:500;"><?= htmlspecialchars($vc['case_title']) ?></div>
                <?php endif; ?>
                <div class="mt-2 d-flex gap-2 flex-wrap">
                    <span class="badge bg-<?= $statusBadge ?>" style="font-size:0.78rem;padding:4px 12px;"><?= $vc['status'] ?></span>
                    <span class="badge bg-secondary" style="font-size:0.78rem;padding:4px 12px;"><?= $vc['priority'] ?? 'Medium' ?> Priority</span>
                    <?php if ($vc['settlement_agreement']): ?>
                    <span class="badge bg-secondary" style="font-size:0.78rem;padding:4px 12px;"><i class="bi bi-check-circle me-1"></i>Settlement Signed</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <button class="btn btn-sm btn-light fw-semibold" onclick="editCase(<?= htmlspecialchars(json_encode($vc)) ?>)">
                    <i class="bi bi-pencil me-1"></i>Edit Case
                </button>
                <a href="<?= BASE_URL ?>/barangay_admin/export_blotter.php?id=<?= $vc['id'] ?>&amp;print=1" target="_blank" class="btn btn-sm btn-light fw-semibold">
                    <i class="bi bi-printer me-1"></i>Print Case
                </a>
                <?php if ($vc['status'] === 'Closed'): ?>
                <button class="btn btn-sm btn-outline-light" onclick="openBlotterArchiveModal(<?= $vc['id'] ?>,'<?= addslashes($vc['case_number']) ?>')">
                    <i class="bi bi-archive me-1"></i>Archive
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <!-- Meta row -->
    <div style="padding:12px 24px;background:#f8fafc;border-bottom:1px solid #e0f0e4;display:flex;gap:20px;flex-wrap:wrap;font-size:0.8rem;">
        <div><span style="color:#3a7fa8;font-weight:600;">Type:</span> <?= htmlspecialchars($vc['incident_type'] ?? 'N/A') ?></div>
        <div><span style="color:#3a7fa8;font-weight:600;">Reported:</span> <?= $vc['date_reported'] ? date('M d, Y H:i', strtotime($vc['date_reported'])) : '—' ?></div>
        <div><span style="color:#3a7fa8;font-weight:600;">Incident:</span> <?= date('M d, Y', strtotime($vc['incident_date'])) ?> <?= $vc['incident_time'] ? date('g:i A', strtotime($vc['incident_time'])) : '' ?></div>
        <div><span style="color:#3a7fa8;font-weight:600;">Location:</span> <?= htmlspecialchars($vc['location']) ?></div>
        <?php if ($vc['officer_assigned']): ?>
        <div><span style="color:#3a7fa8;font-weight:600;">Officer:</span> <?= htmlspecialchars($vc['officer_assigned']) ?></div>
        <?php endif; ?>
        <div><span style="color:#3a7fa8;font-weight:600;">Resolution:</span> <?= htmlspecialchars($vc['resolution'] ?? 'Pending') ?></div>
        <?php if (!empty($vc['date_of_action'])): ?>
        <div><span style="color:#3a7fa8;font-weight:600;">Action Date:</span> <?= date('M d, Y', strtotime($vc['date_of_action'])) ?></div>
        <?php endif; ?>
        <?php if (!empty($vc['date_closed'])): ?>
        <div><span style="color:#3a7fa8;font-weight:600;">Closed:</span> <?= date('M d, Y', strtotime($vc['date_closed'])) ?></div>
        <?php endif; ?>
        <?php if ($vc['settlement_agreement']): ?>
        <div><span style="color:#198754;font-weight:600;"><i class="bi bi-check-circle me-1"></i>Settlement Signed</span><?php if (!empty($vc['settlement_date'])): ?> <?= date('M d, Y', strtotime($vc['settlement_date'])) ?><?php endif; ?></div>
        <?php endif; ?>
    </div>
    <!-- Tab bar -->
    <div style="padding:0 24px;border-bottom:1px solid #e0f0e4;overflow-x:auto;white-space:nowrap;display:flex;gap:4px;">
        <?php foreach (['overview'=>'Overview','persons'=>'Persons Involved','witnesses'=>'Witnesses','narrative'=>'Narrative','evidence'=>'Evidence','timeline'=>'Timeline'] as $tabId => $tabLabel): ?>
        <button class="detail-tab-btn <?= $tabId==='overview'?'active':'' ?>" onclick="switchDetailTab('<?= $tabId ?>')" id="dtab-<?= $tabId ?>">
            <?= $tabLabel ?>
            <?php if ($tabId === 'evidence' && count($attachments)): ?>
            <span class="badge bg-primary ms-1" style="font-size:0.65rem;"><?= count($attachments) ?></span>
            <?php endif; ?>
            <?php if ($tabId === 'witnesses' && count($witnesses)): ?>
            <span class="badge bg-secondary ms-1" style="font-size:0.65rem;"><?= count($witnesses) ?></span>
            <?php endif; ?>
        </button>
        <?php endforeach; ?>
    </div>
</div>

<!-- Tab: Overview -->
<div id="dtabcontent-overview" class="tab-content-step active">
<div class="row g-3">

    <!-- LEFT col: Parties -->
    <div class="col-lg-4">

        <!-- Complainant -->
        <div style="background:#fff;border-radius:12px;border:1px solid #d0ecd6;overflow:hidden;margin-bottom:12px;">
            <div style="background:rgba(26,79,122,0.06);border-bottom:1px solid rgba(26,79,122,0.1);padding:9px 14px;display:flex;align-items:center;gap:8px;">
                <i class="bi bi-person-fill" style="color:#1a4f7a;font-size:0.85rem;"></i>
                <span style="font-weight:700;font-size:0.78rem;color:#1a4f7a;text-transform:uppercase;letter-spacing:0.06em;">Complainant</span>
            </div>
            <div style="padding:12px 14px;font-size:0.82rem;">
                <div style="font-weight:700;font-size:0.9rem;color:#1a2e1f;margin-bottom:4px;"><?= htmlspecialchars($compName) ?></div>
                <?php if (!empty($vc['complainant_address'])): ?><div class="text-muted mb-1"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($vc['complainant_address']) ?></div><?php endif; ?>
                <?php if (!empty($compContact) && $compContact !== '—'): ?><div class="text-muted mb-1"><i class="bi bi-telephone me-1"></i><?= htmlspecialchars($compContact) ?></div><?php endif; ?>
                <div class="d-flex gap-3 mt-1 text-muted" style="font-size:0.78rem;">
                    <?php if ($compAge !== '—'): ?><span><strong>Age:</strong> <?= $compAge ?></span><?php endif; ?>
                    <?php if (!empty($compGender) && $compGender !== '—'): ?><span><strong>Sex:</strong> <?= htmlspecialchars($compGender) ?></span><?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Respondent -->
        <div style="background:#fff;border-radius:12px;border:1px solid #ffd6d6;overflow:hidden;margin-bottom:12px;">
            <div style="background:rgba(220,53,69,0.05);border-bottom:1px solid rgba(220,53,69,0.1);padding:9px 14px;display:flex;align-items:center;gap:8px;">
                <i class="bi bi-person-fill-exclamation" style="color:#dc3545;font-size:0.85rem;"></i>
                <span style="font-weight:700;font-size:0.78rem;color:#dc3545;text-transform:uppercase;letter-spacing:0.06em;">Respondent</span>
            </div>
            <div style="padding:12px 14px;font-size:0.82rem;">
                <div style="font-weight:700;font-size:0.9rem;color:#1a2e1f;margin-bottom:4px;"><?= htmlspecialchars($respName) ?></div>
                <?php if (!empty($vc['respondent_address'])): ?><div class="text-muted mb-1"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($vc['respondent_address']) ?></div><?php endif; ?>
                <?php if (!empty($respContact) && $respContact !== '—'): ?><div class="text-muted mb-1"><i class="bi bi-telephone me-1"></i><?= htmlspecialchars($respContact) ?></div><?php endif; ?>
                <?php if (!empty($vc['respondent_relationship'])): ?><div class="text-muted" style="font-size:0.78rem;"><i class="bi bi-diagram-2 me-1"></i><?= htmlspecialchars($vc['respondent_relationship']) ?></div><?php endif; ?>
            </div>
        </div>

        <!-- Suspect (if any) -->
        <?php
        $suspectOv = array_values(array_filter($personsInvolved, fn($p) => ($p['role'] ?? '') === 'Suspect'));
        if (!empty($suspectOv)): ?>
        <div style="background:#fff;border-radius:12px;border:1px solid #e8d5f5;overflow:hidden;margin-bottom:12px;">
            <div style="background:rgba(124,58,237,0.04);border-bottom:1px solid rgba(124,58,237,0.12);padding:9px 14px;display:flex;align-items:center;gap:8px;">
                <i class="bi bi-person-fill-lock" style="color:#7c3aed;font-size:0.85rem;"></i>
                <span style="font-weight:700;font-size:0.78rem;color:#7c3aed;text-transform:uppercase;letter-spacing:0.06em;">Suspect</span>
            </div>
            <div style="padding:12px 14px;font-size:0.82rem;">
                <?php foreach ($suspectOv as $s): ?>
                <div style="font-weight:700;color:#1a2e1f;margin-bottom:3px;"><?= htmlspecialchars($s['display_name'] ?? $s['full_name']) ?></div>
                <?php if ($s['address']): ?><div class="text-muted mb-1"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($s['address']) ?></div><?php endif; ?>
                <?php if ($s['contact']): ?><div class="text-muted"><i class="bi bi-telephone me-1"></i><?= htmlspecialchars($s['contact']) ?></div><?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Incident Details -->
        <div style="background:#fff;border-radius:12px;border:1px solid #d0ecd6;overflow:hidden;margin-bottom:12px;">
            <div style="background:rgba(26,79,122,0.06);border-bottom:1px solid rgba(26,79,122,0.1);padding:9px 14px;display:flex;align-items:center;gap:8px;">
                <i class="bi bi-exclamation-triangle-fill" style="color:#1a4f7a;font-size:0.85rem;"></i>
                <span style="font-weight:700;font-size:0.78rem;color:#1a4f7a;text-transform:uppercase;letter-spacing:0.06em;">Incident Details</span>
            </div>
            <div style="padding:12px 14px;font-size:0.82rem;">
                <div class="mb-2"><span class="text-muted">Date &amp; Time:</span><br><strong><?= date('M d, Y', strtotime($vc['incident_date'])) ?><?= $vc['incident_time'] ? ' ' . date('g:i A', strtotime($vc['incident_time'])) : '' ?></strong></div>
                <div class="mb-2"><span class="text-muted">Location:</span><br><strong><?= htmlspecialchars($vc['location']) ?></strong></div>
                <div class="mb-2"><span class="text-muted">Incident Type:</span><br><strong><?= htmlspecialchars($vc['incident_type'] ?? 'N/A') ?></strong></div>
                <?php if ($vc['officer_assigned']): ?>
                <div class="mb-2"><span class="text-muted">Officer Assigned:</span><br><strong><?= htmlspecialchars($vc['officer_assigned']) ?></strong></div>
                <?php endif; ?>
                <div style="font-size:0.75rem;color:#888;margin-top:8px;border-top:1px solid #f0f4f8;padding-top:6px;">
                    <i class="bi bi-person-badge me-1"></i>Recorded by <strong><?= htmlspecialchars($vc['recorded_by'] ?? $u['name']) ?></strong>
                    <?php if ($vc['date_recorded']): ?>&nbsp;· <?= date('M d, Y', strtotime($vc['date_recorded'])) ?><?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Timeline (below Incident Details) -->
        <div style="background:#fff;border-radius:12px;border:1px solid #d0ecd6;overflow:hidden;">
            <div style="background:rgba(26,79,122,0.06);border-bottom:1px solid rgba(26,79,122,0.1);padding:9px 14px;display:flex;align-items:center;gap:8px;">
                <i class="bi bi-clock-history" style="color:#1a4f7a;font-size:0.85rem;"></i>
                <span style="font-weight:700;font-size:0.78rem;color:#1a4f7a;text-transform:uppercase;letter-spacing:0.06em;">Case Timeline</span>
                <?php if (!empty($timeline)): ?><span class="badge bg-secondary ms-auto" style="font-size:0.6rem;"><?= count($timeline) ?></span><?php endif; ?>
            </div>
            <div style="padding:12px 14px;">
                <?php if (empty($timeline)): ?>
                <div class="text-muted text-center" style="font-size:0.78rem;padding:8px 0;">No history yet.</div>
                <?php else: ?>
                <?php foreach (array_slice($timeline, 0, 6) as $tl):
                    $tlDot = strpos($tl['event'],'Created')!==false ? '#1a4f7a' :
                            (strpos($tl['event'],'Status')!==false  ? '#e67e22' :
                            (strpos($tl['event'],'Closed')!==false  ? '#198754' : '#3a7fa8'));
                    $tlBadge = $statusColors[$tl['status'] ?? ''] ?? 'secondary';
                ?>
                <div class="timeline-item">
                    <div class="timeline-dot" style="background:<?= $tlDot ?>;"></div>
                    <div style="font-size:0.78rem;padding-bottom:6px;">
                        <div style="font-weight:700;color:#1a2e1f;"><?= htmlspecialchars($tl['event']) ?></div>
                        <?php if (!empty($tl['status'])): ?>
                        <span class="badge bg-secondary" style="font-size:0.62rem;"><?= htmlspecialchars($tl['status']) ?></span>
                        <?php endif; ?>
                        <div class="text-muted" style="font-size:0.71rem;margin-top:2px;">
                            <?= date('M d, Y g:i A', strtotime($tl['created_at'])) ?><br>
                            <?= htmlspecialchars($tl['actor'] ?? '—') ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (count($timeline) > 6): ?>
                <div style="font-size:0.74rem;color:#3a7fa8;text-align:center;margin-top:6px;cursor:pointer;" onclick="switchDetailTab('timeline')">
                    +<?= count($timeline) - 6 ?> more &mdash; <u>View all</u>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- RIGHT col: Narrative + Case Status (widened since timeline moved left) -->
    <div class="col-lg-8">

        <!-- Narrative -->
        <div style="background:#fff;border-radius:12px;border:1px solid #d0ecd6;overflow:hidden;margin-bottom:12px;">
            <div style="background:rgba(26,79,122,0.06);border-bottom:1px solid rgba(26,79,122,0.1);padding:9px 14px;display:flex;align-items:center;gap:8px;">
                <i class="bi bi-file-text-fill" style="color:#1a4f7a;font-size:0.85rem;"></i>
                <span style="font-weight:700;font-size:0.78rem;color:#1a4f7a;text-transform:uppercase;letter-spacing:0.06em;">Narrative</span>
            </div>
            <div style="padding:14px;font-size:0.82rem;">
                <?php if (!empty($vc['incident_summary'])): ?>
                <div class="mb-3">
                    <div style="font-size:0.7rem;font-weight:700;color:#3a7fa8;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:3px;">Incident Summary</div>
                    <div style="color:#1a2e1f;line-height:1.6;"><?= nl2br(htmlspecialchars($vc['incident_summary'])) ?></div>
                </div>
                <?php endif; ?>
                <div>
                    <div style="font-size:0.7rem;font-weight:700;color:#3a7fa8;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:3px;">Detailed Narrative</div>
                    <div style="color:#1a2e1f;line-height:1.6;"><?= nl2br(htmlspecialchars($vc['detailed_narrative'] ?? $vc['narrative'] ?? '<em>No narrative recorded.</em>')) ?></div>
                </div>
            </div>
        </div>

        <!-- Case Status -->
        <div style="background:#fff;border-radius:12px;border:1px solid #d0ecd6;overflow:hidden;">
            <div style="background:rgba(26,79,122,0.06);border-bottom:1px solid rgba(26,79,122,0.1);padding:9px 14px;display:flex;align-items:center;gap:8px;">
                <i class="bi bi-clipboard2-check-fill" style="color:#1a4f7a;font-size:0.85rem;"></i>
                <span style="font-weight:700;font-size:0.78rem;color:#1a4f7a;text-transform:uppercase;letter-spacing:0.06em;">Case Status</span>
            </div>
            <div style="padding:14px;font-size:0.82rem;">
                <?php
                $resColors2 = ['Pending'=>'secondary','Settled'=>'success','Referred'=>'primary','Dismissed'=>'dark'];
                ?>
                <!-- Status row -->
                <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
                    <span class="badge bg-secondary" style="font-size:0.8rem;padding:5px 12px;"><?= htmlspecialchars($vc['status']) ?></span>
                    <span class="badge bg-secondary" style="font-size:0.78rem;padding:4px 10px;"><?= htmlspecialchars($vc['resolution'] ?? 'Pending') ?></span>
                    <?php if ($vc['settlement_agreement']): ?>
                    <span class="badge bg-secondary" style="font-size:0.78rem;padding:4px 10px;"><i class="bi bi-check-circle me-1"></i>Settlement Signed</span>
                    <?php endif; ?>
                </div>

                <!-- Date fields -->
                <div class="row g-2 mb-2" style="font-size:0.8rem;">
                    <?php if (!empty($vc['date_of_action'])): ?>
                    <div class="col-6"><span class="text-muted">Date of Action:</span><br><strong><?= date('M d, Y', strtotime($vc['date_of_action'])) ?></strong></div>
                    <?php endif; ?>
                    <?php if (!empty($vc['date_closed'])): ?>
                    <div class="col-6"><span class="text-muted">Date Closed:</span><br><strong><?= date('M d, Y', strtotime($vc['date_closed'])) ?></strong></div>
                    <?php endif; ?>
                    <?php if (!empty($vc['settlement_date'])): ?>
                    <div class="col-6"><span class="text-muted">Agreement Signed:</span><br><strong><?= date('M d, Y', strtotime($vc['settlement_date'])) ?></strong></div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($vc['action_taken'])): ?>
                <div class="mb-2">
                    <div style="font-size:0.7rem;font-weight:700;color:#3a7fa8;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:3px;">Action Taken</div>
                    <div style="color:#1a2e1f;line-height:1.6;"><?= nl2br(htmlspecialchars($vc['action_taken'])) ?></div>
                </div>
                <?php endif; ?>

                <?php if (!empty($vc['remarks'])): ?>
                <div>
                    <div style="font-size:0.7rem;font-weight:700;color:#3a7fa8;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:3px;">Remarks</div>
                    <div style="color:#444;line-height:1.6;"><?= nl2br(htmlspecialchars($vc['remarks'])) ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<!-- Evidence table at bottom -->
<div style="background:#fff;border-radius:12px;border:1px solid #d0ecd6;overflow:hidden;margin-top:16px;">
    <div style="background:rgba(26,79,122,0.06);border-bottom:1px solid rgba(26,79,122,0.1);padding:10px 16px;display:flex;align-items:center;gap:8px;">
        <i class="bi bi-paperclip" style="color:#1a4f7a;"></i>
        <span style="font-weight:700;font-size:0.8rem;color:#1a4f7a;text-transform:uppercase;letter-spacing:0.06em;">Evidence &amp; Attachments</span>
        <span class="badge bg-secondary ms-1" style="font-size:0.65rem;"><?= count($attachments) ?></span>
    </div>
    <?php if (empty($attachments)): ?>
    <div class="text-center text-muted py-4" style="font-size:0.85rem;"><i class="bi bi-paperclip" style="font-size:1.8rem;opacity:0.3;display:block;margin-bottom:6px;"></i>No attachments uploaded.</div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table mb-0" style="font-size:0.82rem;">
            <thead><tr style="background:#f4f7fa;font-size:0.72rem;font-weight:700;text-transform:uppercase;color:#3a7fa8;">
                <th class="ps-4 py-2" style="border:none;">Evidence Type</th>
                <th class="py-2" style="border:none;">Description</th>
                <th class="py-2" style="border:none;">Supporting Documents<br><span style="font-weight:400;text-transform:none;font-size:0.68rem;color:#6c757d;">Photos / Files</span></th>
                <th class="py-2" style="border:none;">Date Submitted</th>
                <th class="py-2" style="border:none;">Uploaded By</th>
                <th class="pe-4 py-2" style="border:none;">Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ($attachments as $att):
                $rawDesc = $att['description'] ?? '';
                $evType  = $att['file_type'] ? strtoupper($att['file_type']) : '—';
                $evDesc  = $rawDesc;
                if (preg_match('/^\[([^\]]+)\]\s*(.*)$/s', $rawDesc, $m)) {
                    $evType = $m[1];
                    $evDesc = trim($m[2]);
                }
            ?>
            <tr style="border-bottom:1px solid #e8f4fb;">
                <td class="ps-4 py-2" style="border:none;"><span class="badge bg-secondary evidence-type-badge"><?= htmlspecialchars($evType) ?></span></td>
                <td class="py-2" style="border:none;"><?= htmlspecialchars($evDesc ?: '—') ?></td>
                <td class="py-2" style="border:none;"><i class="bi bi-file-earmark me-1 text-muted"></i><?= htmlspecialchars($att['file_name']) ?></td>
                <td class="py-2" style="border:none;"><?= $att['uploaded_at'] ? date('M d, Y', strtotime($att['uploaded_at'])) : '—' ?></td>
                <td class="py-2" style="border:none;"><?= htmlspecialchars($att['uploaded_by'] ?? '—') ?></td>
                <td class="pe-4 py-2" style="border:none;">
                    <a href="<?= BASE_URL ?>/<?= htmlspecialchars($att['file_path']) ?>" target="_blank" class="btn btn-xs btn-outline-info"><i class="bi bi-eye"></i></a>
                    <a href="<?= BASE_URL ?>/<?= htmlspecialchars($att['file_path']) ?>" download class="btn btn-xs btn-outline-success"><i class="bi bi-download"></i></a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
</div><!-- /overview tab -->

<!-- Tab: Persons Involved -->
<div id="dtabcontent-persons" class="tab-content-step">
<div class="row g-3">
    <!-- Complainant -->
    <div class="col-md-4">
        <div style="background:#fff;border-radius:12px;border:1px solid #d0ecd6;overflow:hidden;height:100%;">
            <div style="background:rgba(26,79,122,0.06);border-bottom:1px solid rgba(26,79,122,0.1);padding:10px 14px;display:flex;align-items:center;gap:7px;">
                <i class="bi bi-person-fill" style="color:#1a4f7a;font-size:0.85rem;"></i>
                <span style="font-weight:700;font-size:0.78rem;color:#1a4f7a;text-transform:uppercase;letter-spacing:0.06em;">Complainant / Reporting Person</span>
            </div>
            <div class="p-3" style="font-size:0.83rem;">
                <div class="mb-2"><span class="text-muted" style="font-size:0.75rem;">Name</span><div class="fw-semibold"><?= htmlspecialchars($compName) ?></div></div>
                <div class="mb-2"><span class="text-muted" style="font-size:0.75rem;">Address</span><div><?= htmlspecialchars($vc['complainant_address'] ?? '—') ?></div></div>
                <div class="mb-2"><span class="text-muted" style="font-size:0.75rem;">Contact</span><div><?= htmlspecialchars($compContact) ?></div></div>
                <div class="mb-2"><span class="text-muted" style="font-size:0.75rem;">Gender / Age</span><div><?= htmlspecialchars($compGender) ?><?= $compAge !== '—' ? ', '.$compAge.' yrs' : '' ?></div></div>
            </div>
        </div>
    </div>
    <!-- Respondent -->
    <div class="col-md-4">
        <div style="background:#fff;border-radius:12px;border:1px solid #ffd6d6;overflow:hidden;height:100%;">
            <div style="background:rgba(220,53,69,0.05);border-bottom:1px solid rgba(220,53,69,0.12);padding:10px 14px;display:flex;align-items:center;gap:7px;">
                <i class="bi bi-person-fill-exclamation" style="color:#dc3545;font-size:0.85rem;"></i>
                <span style="font-weight:700;font-size:0.78rem;color:#dc3545;text-transform:uppercase;letter-spacing:0.06em;">Respondent</span>
            </div>
            <div class="p-3" style="font-size:0.83rem;">
                <div class="mb-2"><span class="text-muted" style="font-size:0.75rem;">Name</span><div class="fw-semibold"><?= htmlspecialchars($respName) ?></div></div>
                <div class="mb-2"><span class="text-muted" style="font-size:0.75rem;">Address</span><div><?= htmlspecialchars($vc['respondent_address'] ?? '—') ?></div></div>
                <div class="mb-2"><span class="text-muted" style="font-size:0.75rem;">Contact</span><div><?= htmlspecialchars($respContact) ?></div></div>
                <div class="mb-2"><span class="text-muted" style="font-size:0.75rem;">Relationship</span><div><?= htmlspecialchars($vc['respondent_relationship'] ?? '—') ?></div></div>
            </div>
        </div>
    </div>
    <!-- Suspect (from persons_involved with role=Suspect) -->
    <?php $suspects = array_filter($personsInvolved, fn($p) => ($p['role'] ?? '') === 'Suspect'); ?>
    <div class="col-md-4">
        <div style="background:#fff;border-radius:12px;border:1px solid #e8d5f5;overflow:hidden;height:100%;">
            <div style="background:rgba(124,58,237,0.04);border-bottom:1px solid rgba(124,58,237,0.12);padding:10px 14px;display:flex;align-items:center;gap:7px;">
                <i class="bi bi-person-fill-lock" style="color:#7c3aed;font-size:0.85rem;"></i>
                <span style="font-weight:700;font-size:0.78rem;color:#7c3aed;text-transform:uppercase;letter-spacing:0.06em;">Suspect</span>
            </div>
            <?php if (empty($suspects)): ?>
            <div class="text-center text-muted py-3" style="font-size:0.82rem;">—</div>
            <?php else: ?>
            <div class="p-3" style="font-size:0.83rem;">
                <?php foreach ($suspects as $s): ?>
                <div class="mb-2"><span class="text-muted" style="font-size:0.75rem;">Name</span><div class="fw-semibold"><?= htmlspecialchars($s['display_name'] ?? $s['full_name']) ?></div></div>
                <?php if ($s['address']): ?><div class="mb-2"><span class="text-muted" style="font-size:0.75rem;">Address</span><div><?= htmlspecialchars($s['address']) ?></div></div><?php endif; ?>
                <?php if ($s['contact']): ?><div class="mb-2"><span class="text-muted" style="font-size:0.75rem;">Contact</span><div><?= htmlspecialchars($s['contact']) ?></div></div><?php endif; ?>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <!-- Other Persons Involved (excluding Suspect) -->
    <?php $otherPI = array_filter($personsInvolved, fn($p) => ($p['role'] ?? '') !== 'Suspect'); ?>
    <div class="col-md-4">
        <div style="background:#fff;border-radius:12px;border:1px solid #e0eef5;overflow:hidden;height:100%;">
            <div style="background:#f4f7fa;border-bottom:1px solid #d8eaf5;padding:10px 14px;display:flex;align-items:center;gap:7px;">
                <i class="bi bi-people-fill" style="color:#3a7fa8;font-size:0.85rem;"></i>
                <span style="font-weight:700;font-size:0.78rem;color:#3a7fa8;text-transform:uppercase;letter-spacing:0.06em;">Other Persons Involved</span>
                <?php if (!empty($otherPI)): ?>
                <span class="badge bg-secondary ms-auto" style="font-size:0.65rem;"><?= count($otherPI) ?></span>
                <?php endif; ?>
            </div>
            <?php if (empty($otherPI)): ?>
            <div class="text-center text-muted py-3" style="font-size:0.82rem;">None recorded.</div>
            <?php else: ?>
            <div class="p-3">
                <?php foreach ($otherPI as $pi): ?>
                <div style="border:1px solid #e0eef5;border-radius:7px;padding:7px 10px;margin-bottom:7px;font-size:0.82rem;">
                    <div class="fw-semibold"><?= htmlspecialchars($pi['display_name'] ?? $pi['full_name']) ?></div>
                    <div class="text-muted" style="font-size:0.74rem;"><?= htmlspecialchars($pi['role'] ?? 'Person Involved') ?></div>
                    <?php if ($pi['address']): ?><div style="font-size:0.78rem;"><?= htmlspecialchars($pi['address']) ?></div><?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</div>

<!-- Tab: Witnesses -->
<div id="dtabcontent-witnesses" class="tab-content-step">
<div style="background:#fff;border-radius:12px;border:1px solid #d0ecd6;overflow:hidden;">
    <div style="background:rgba(26,79,122,0.06);border-bottom:1px solid rgba(26,79,122,0.1);padding:10px 16px;">
        <span style="font-weight:700;font-size:0.82rem;color:#1a4f7a;">Witnesses</span>
    </div>
    <?php if (empty($witnesses)): ?>
    <div class="text-center text-muted py-4"><i class="bi bi-people" style="font-size:2rem;opacity:0.3;display:block;margin-bottom:6px;"></i>No witnesses recorded.</div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table mb-0" style="font-size:0.83rem;">
            <thead><tr style="background:#f4f7fa;font-size:0.72rem;font-weight:700;text-transform:uppercase;color:#3a7fa8;">
                <th class="ps-4 py-2" style="border:none;">#</th>
                <th class="py-2" style="border:none;">Name</th>
                <th class="py-2" style="border:none;">Address</th>
                <th class="pe-4 py-2" style="border:none;">Contact</th>
            </tr></thead>
            <tbody>
            <?php foreach ($witnesses as $wi => $w): ?>
            <tr style="border-bottom:1px solid #e8f4fb;">
                <td class="ps-4 py-2" style="border:none;"><?= $wi+1 ?></td>
                <td class="py-2" style="border:none;font-weight:600;"><?= htmlspecialchars($w['name']) ?></td>
                <td class="py-2" style="border:none;"><?= htmlspecialchars($w['address'] ?? '—') ?></td>
                <td class="pe-4 py-2" style="border:none;"><?= htmlspecialchars($w['contact'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
</div>

<!-- Tab: Narrative -->
<div id="dtabcontent-narrative" class="tab-content-step">
<div style="background:#fff;border-radius:12px;border:1px solid #d0ecd6;padding:20px;">
    <?php if (!empty($vc['incident_summary'])): ?>
    <h6 class="fw-bold text-primary mb-2">Incident Summary</h6>
    <p style="font-size:0.85rem;line-height:1.7;"><?= nl2br(htmlspecialchars($vc['incident_summary'])) ?></p>
    <hr>
    <?php endif; ?>
    <h6 class="fw-bold text-primary mb-2">Detailed Narrative</h6>
    <p style="font-size:0.85rem;line-height:1.7;"><?= nl2br(htmlspecialchars($vc['detailed_narrative'] ?? $vc['narrative'] ?? '—')) ?></p>
    <div class="text-muted mt-3" style="font-size:0.78rem;border-top:1px solid #f0f4f8;padding-top:8px;">
        <i class="bi bi-person-badge me-1"></i>Recorded by <strong><?= htmlspecialchars($vc['recorded_by'] ?? $u['name']) ?></strong>
        <?php if ($vc['date_recorded']): ?>&nbsp;· <?= date('M d, Y H:i', strtotime($vc['date_recorded'])) ?><?php endif; ?>
    </div>
</div>
</div>

<!-- Tab: Evidence -->
<div id="dtabcontent-evidence" class="tab-content-step">
<div style="background:#fff;border-radius:12px;border:1px solid #d0ecd6;overflow:hidden;">
    <div style="background:rgba(26,79,122,0.06);border-bottom:1px solid rgba(26,79,122,0.1);padding:10px 16px;">
        <span style="font-weight:700;font-size:0.82rem;color:#1a4f7a;">Evidence &amp; Attachments (<?= count($attachments) ?>)</span>
    </div>
    <?php if (empty($attachments)): ?>
    <div class="text-center text-muted py-4"><i class="bi bi-paperclip" style="font-size:2rem;opacity:0.3;display:block;margin-bottom:6px;"></i>No attachments uploaded.</div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table mb-0" style="font-size:0.82rem;">
            <thead><tr style="background:#f4f7fa;font-size:0.72rem;font-weight:700;text-transform:uppercase;color:#3a7fa8;">
                <th class="ps-4 py-2" style="border:none;">Evidence Type</th>
                <th class="py-2" style="border:none;">Description</th>
                <th class="py-2" style="border:none;">Supporting Documents<br><span style="font-weight:400;text-transform:none;font-size:0.68rem;color:#6c757d;">Photos / Files</span></th>
                <th class="py-2" style="border:none;">Date Submitted</th>
                <th class="py-2" style="border:none;">Uploaded By</th>
                <th class="pe-4 py-2" style="border:none;">Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ($attachments as $att):
                // Parse evidence type from description field: "[Type] description"
                $rawDesc = $att['description'] ?? '';
                $evType  = $att['file_type'] ? strtoupper($att['file_type']) : '—';
                $evDesc  = $rawDesc;
                if (preg_match('/^\[([^\]]+)\]\s*(.*)$/s', $rawDesc, $m)) {
                    $evType = $m[1];
                    $evDesc = trim($m[2]);
                }
            ?>
            <tr style="border-bottom:1px solid #e8f4fb;">
                <td class="ps-4 py-2" style="border:none;"><span class="badge bg-secondary evidence-type-badge"><?= htmlspecialchars($evType) ?></span></td>
                <td class="py-2" style="border:none;"><?= htmlspecialchars($evDesc ?: '—') ?></td>
                <td class="py-2" style="border:none;"><i class="bi bi-file-earmark me-1 text-muted"></i><?= htmlspecialchars($att['file_name']) ?></td>
                <td class="py-2" style="border:none;"><?= $att['uploaded_at'] ? date('M d, Y', strtotime($att['uploaded_at'])) : '—' ?></td>
                <td class="py-2" style="border:none;"><?= htmlspecialchars($att['uploaded_by'] ?? '—') ?></td>
                <td class="pe-4 py-2" style="border:none;">
                    <a href="<?= BASE_URL ?>/<?= htmlspecialchars($att['file_path']) ?>" target="_blank" class="btn btn-xs btn-outline-info"><i class="bi bi-eye"></i></a>
                    <a href="<?= BASE_URL ?>/<?= htmlspecialchars($att['file_path']) ?>" download class="btn btn-xs btn-outline-success"><i class="bi bi-download"></i></a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
</div>

<!-- Tab: Timeline -->
<div id="dtabcontent-timeline" class="tab-content-step">
<div style="background:#fff;border-radius:12px;border:1px solid #d0ecd6;overflow:hidden;">
    <div style="background:rgba(26,79,122,0.06);border-bottom:1px solid rgba(26,79,122,0.1);padding:10px 16px;display:flex;align-items:center;gap:8px;">
        <i class="bi bi-clock-history" style="color:#1a4f7a;"></i>
        <span style="font-weight:700;font-size:0.82rem;color:#1a4f7a;">Case History</span>
        <span class="badge bg-secondary ms-1" style="font-size:0.65rem;"><?= count($timeline) ?></span>
    </div>
    <div style="padding:20px 24px;">
<?php
$statusColors = ['Open'=>'warning','Under Investigation'=>'info','Referred'=>'primary','Escalated'=>'danger','Settled'=>'success','Dismissed'=>'dark','Closed'=>'secondary','Archived'=>'secondary'];
$dotColors    = ['Case Created'=>'#1a4f7a','Status Changed'=>'#e67e22','Case Updated'=>'#3a7fa8','Case Closed'=>'#198754'];

// Build combined list: timeline entries
$allEvents = [];
foreach ($timeline as $tl) {
    $allEvents[] = [
        'event'      => $tl['event'],
        'status'     => $tl['status'],
        'actor'      => $tl['actor'] ?? $tl['user_name'] ?? '—',
        'note'       => $tl['note'] ?? '',
        'created_at' => $tl['created_at'],
        'type'       => strpos($tl['event'], 'Status Changed') !== false ? 'status' :
                       (strpos($tl['event'], 'Case Created') !== false ? 'created' :
                       (strpos($tl['event'], 'Closed') !== false ? 'closed' : 'update')),
    ];
}

if (empty($allEvents)): ?>
    <div class="text-center text-muted py-3" style="font-size:0.85rem;">
        <i class="bi bi-clock-history" style="font-size:2rem;opacity:0.3;display:block;margin-bottom:8px;"></i>
        No history recorded yet.
    </div>
<?php else:
    $total = count($allEvents);
    foreach ($allEvents as $i => $ev):
        $isLast   = ($i === $total - 1);
        $dotColor = $ev['type'] === 'created' ? '#1a4f7a' :
                   ($ev['type'] === 'status'  ? '#e67e22' :
                   ($ev['type'] === 'closed'  ? '#198754' : '#3a7fa8'));
        $statusBadge = $statusColors[$ev['status']] ?? 'secondary';
        $dt = $ev['created_at'] ? date('M d, Y g:i A', strtotime($ev['created_at'])) : '—';
?>
    <div style="display:flex;gap:14px;<?= $isLast ? '' : 'padding-bottom:20px;' ?>">
        <!-- Dot + line -->
        <div style="display:flex;flex-direction:column;align-items:center;flex-shrink:0;">
            <div style="width:14px;height:14px;border-radius:50%;background:<?= $dotColor ?>;border:2px solid #fff;box-shadow:0 0 0 2px <?= $dotColor ?>;margin-top:3px;flex-shrink:0;"></div>
            <?php if (!$isLast): ?>
            <div style="flex:1;width:2px;background:#d0ecd6;margin-top:4px;min-height:20px;"></div>
            <?php endif; ?>
        </div>
        <!-- Content -->
        <div style="flex:1;min-width:0;padding-bottom:<?= $isLast ? '0' : '0' ?>px;">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:3px;">
                <span style="font-size:0.85rem;font-weight:700;color:#1a2e1f;"><?= htmlspecialchars($ev['event']) ?></span>
                <?php if ($ev['status']): ?>
                <span class="badge bg-secondary" style="font-size:0.67rem;padding:2px 8px;"><?= htmlspecialchars($ev['status']) ?></span>
                <?php endif; ?>
            </div>
            <div style="font-size:0.78rem;color:#6c757d;margin-bottom:<?= $ev['note'] ? '4px' : '0' ?>;">
                <i class="bi bi-clock me-1" style="font-size:0.7rem;"></i><?= $dt ?>
                &nbsp;·&nbsp;<i class="bi bi-person me-1" style="font-size:0.7rem;"></i><?= htmlspecialchars($ev['actor']) ?>
            </div>
            <?php if (!empty($ev['note'])): ?>
            <div style="font-size:0.78rem;color:#444;background:#f8fafc;border-left:3px solid <?= $dotColor ?>;padding:4px 10px;border-radius:0 4px 4px 0;margin-top:4px;">
                <?= htmlspecialchars($ev['note']) ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; endif; ?>
    </div>
</div>
</div>

<?php else: ?>
<!-- ══════════════════════════════════════════════════════
     CASES LIST VIEW
     ══════════════════════════════════════════════════════ -->
<!-- Filter bar -->
<?php $activePreset = sanitize($_GET['date_preset'] ?? ''); ?>
<div style="background:#fff;border-radius:12px;box-shadow:0 1px 8px rgba(0,0,0,0.06);border:1px solid #e0eef5;padding:10px 14px;margin-bottom:14px;">
    <form method="GET" id="blotterFilterForm" class="d-flex gap-2 flex-wrap align-items-center">
        <!-- Search (auto-submits after 400ms pause) -->
        <div class="input-group input-group-sm ac-wrapper" style="width:240px;">
                <span class="input-group-text bg-white border-end-0" style="border-color:#dee2e6;">
                    <i class="bi bi-search text-muted" style="font-size:0.8rem;"></i>
                </span>
                <input type="text" name="search" id="filterSearch"
                    class="form-control form-control-sm border-start-0 border-end-0 ps-0"
                    style="border-color:#dee2e6;"
                    placeholder="Search cases, names, keywords..."
                    autocomplete="off"
                    value="<?= htmlspecialchars($search) ?>">
                <span class="input-group-text bg-white border-start-0 p-0" style="border-color:#dee2e6;">
                    <span id="blotterSearchClear" title="Clear search"
                          style="width:28px;height:28px;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;color:#9ca3af;border-radius:4px;<?= $search ? '' : 'visibility:hidden;' ?>"
                          onmouseover="this.style.color='#dc2626';this.style.background='#fee2e2';"
                          onmouseout="this.style.color='#9ca3af';this.style.background='transparent';">
                        <i class="bi bi-x-lg" style="font-size:0.75rem;"></i>
                    </span>
                </span>
                <div class="ac-list" id="blotterAcList"></div>
        </div>

        <!-- Type — auto-submits on change -->
        <select name="type" id="filterType" class="form-select form-select-sm" style="width:150px;border-radius:8px;"
            onchange="document.getElementById('blotterFilterForm').submit()">
            <option value="">All Types</option>
            <?php foreach ($incidentTypes as $t): ?>
                <option value="<?= htmlspecialchars($t) ?>" <?= $typeFilter===$t?'selected':'' ?>><?= htmlspecialchars($t) ?></option>
            <?php endforeach; ?>
        </select>

        <!-- Status — auto-submits on change -->
        <select name="status" id="filterStatus" class="form-select form-select-sm" style="width:155px;border-radius:8px;"
            onchange="document.getElementById('blotterFilterForm').submit()">
            <option value="">All Status</option>
            <?php foreach ($statusOptions as $s): ?>
                <option value="<?= htmlspecialchars($s) ?>" <?= $statusFilter===$s?'selected':'' ?>><?= htmlspecialchars($s) ?></option>
            <?php endforeach; ?>
        </select>

        <!-- Hidden date fields populated by date-range picker -->
        <!-- Date Range Picker (shared component) -->
        <?php
        $drpId = 'blotter'; $drpDateFrom = $dateFrom; $drpDateTo = $dateTo;
        $drpPreset = $activePreset; $drpMinDate = ''; $drpMaxDate = date('Y-m-d');
        include __DIR__ . '/../includes/date_range_picker.php';
        ?>

        <!-- Clear filters link (shown only when any filter is active) -->
        <?php if ($search || $statusFilter || $typeFilter || $dateFrom || $dateTo || $activePreset): ?>
        <a href="?" class="btn btn-sm btn-outline-secondary" style="border-radius:8px;padding:4px 10px;" title="Clear filters">
            <i class="bi bi-x-lg"></i>
        </a>
        <?php endif; ?>

        <!-- New Case button — same row as filters -->
        <button type="button" class="btn btn-success btn-sm fw-semibold ms-auto flex-shrink-0" onclick="openBlotterOverlay()">
            <i class="bi bi-plus-lg me-1"></i>New Case
        </button>
    </form>
</div>

<!-- Bulk action bar (appears when rows are selected) -->
<div id="bulkBar" style="display:none;background:#1a4f7a;color:#fff;border-radius:10px;padding:10px 16px;margin-bottom:10px;display:none;align-items:center;gap:12px;box-shadow:0 2px 10px rgba(26,79,122,0.3);">
    <span id="bulkCount" style="font-size:0.82rem;font-weight:600;"></span>
    <div style="flex:1;"></div>
    <button onclick="bulkExportPDF()" class="btn btn-sm fw-semibold" style="background:rgba(255,255,255,0.15);color:#fff;border:1px solid rgba(255,255,255,0.3);border-radius:7px;">
        <i class="bi bi-file-pdf me-1"></i>Export Selected (PDF)
    </button>
    <button onclick="bulkExportCSV()" class="btn btn-sm fw-semibold" style="background:rgba(255,255,255,0.15);color:#fff;border:1px solid rgba(255,255,255,0.3);border-radius:7px;">
        <i class="bi bi-filetype-csv me-1"></i>Export CSV
    </button>
    <button onclick="clearSelection()" style="background:none;border:none;color:rgba(255,255,255,0.6);cursor:pointer;font-size:1rem;line-height:1;" title="Clear selection">
        <i class="bi bi-x-lg"></i>
    </button>
</div>

<!-- Cases Table -->
<div id="logs-table" style="background:#fff;border-radius:14px;box-shadow:0 2px 12px rgba(0,0,0,0.07);border:1px solid #e0eef5;overflow:hidden;">
    <div class="table-responsive">
        <table class="table mb-0" style="font-size:0.82rem;">
            <thead>
                <tr style="background:#f4f7fa;border-bottom:2px solid #d0ecd6;">
                    <th class="ps-3 py-3" style="font-size:0.68rem;font-weight:700;color:#3a7fa8;border:none;width:36px;">
                        <input type="checkbox" id="selectAll" class="form-check-input" style="width:17px;height:17px;cursor:pointer;border-radius:4px;" title="Select all">
                    </th>
                    <th class="py-3" style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;width:40px;">#</th>
                    <th class="py-3" style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">Case No.</th>
                    <th class="py-3" style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">Type</th>
                    <th class="py-3" style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">Complainant</th>
                    <th class="py-3" style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">Respondent</th>
                    <th class="py-3" style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">Incident Date</th>
                    <th class="py-3" style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">Priority</th>
                    <th class="py-3" style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">Status</th>
                    <th class="pe-4 py-3 text-end" style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.07em;color:#3a7fa8;border:none;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($cases)): ?>
                <tr><td colspan="10" class="text-center text-muted py-5"><i class="bi bi-inbox" style="font-size:2.5rem;opacity:0.3;display:block;margin-bottom:10px;"></i>No blotter cases found.</td></tr>
            <?php else: ?>
                <?php foreach ($cases as $i => $c): ?>
                <?php $rowUrl = BASE_URL . '/barangay_admin/blotter.php?view=' . $c['id']; ?>
                <tr class="blotter-row" style="border-bottom:1px solid #eef4f8;cursor:pointer;transition:background 0.12s;"
                    onmouseover="this.style.background='#f5f9fc'" onmouseout="rowHoverOut(this)">
                    <td class="ps-3 py-2" style="border:none;" onclick="event.stopPropagation()">
                        <input type="checkbox" class="row-check form-check-input" value="<?= $c['id'] ?>"
                            data-case="<?= htmlspecialchars($c['case_number']) ?>"
                            style="width:17px;height:17px;cursor:pointer;border-radius:4px;"
                            onchange="updateBulkBar()">
                    </td>
                    <td class="py-2" style="border:none;color:#6c90a8;font-size:0.78rem;" onclick="location.href='<?= $rowUrl ?>'"><?= ($offset + $i + 1) ?></td>
                    <td class="py-2" style="border:none;" onclick="location.href='<?= $rowUrl ?>'">
                        <code style="font-size:0.78rem;color:#1a4f7a;background:rgba(26,79,122,0.07);padding:2px 7px;border-radius:5px;font-weight:600;"><?= htmlspecialchars($c['case_number']) ?></code>
                    </td>
                    <td class="py-2" style="border:none;" onclick="location.href='<?= $rowUrl ?>'">
                        <span style="font-size:0.76rem;color:#444;background:#f0f4f8;padding:2px 8px;border-radius:20px;"><?= htmlspecialchars($c['incident_type'] ?? 'Other') ?></span>
                    </td>
                    <td class="py-2" style="border:none;font-weight:600;color:#1a2e1f;" onclick="location.href='<?= $rowUrl ?>'"><?= htmlspecialchars($c['complainant_display'] ?? 'N/A') ?></td>
                    <td class="py-2" style="border:none;color:#2a5070;" onclick="location.href='<?= $rowUrl ?>'"><?= htmlspecialchars($c['respondent_display'] ?? 'N/A') ?></td>
                    <td class="py-2" style="border:none;font-size:0.8rem;color:#2a5070;" onclick="location.href='<?= $rowUrl ?>'"><?= date('M d, Y', strtotime($c['incident_date'])) ?></td>
                    <td class="py-2" style="border:none;" onclick="location.href='<?= $rowUrl ?>'">
                        <span class="badge bg-secondary" style="font-size:0.7rem;"><?= htmlspecialchars($c['priority'] ?? 'Medium') ?></span>
                    </td>
                    <td class="py-2" style="border:none;" onclick="location.href='<?= $rowUrl ?>'">
                        <span class="badge bg-secondary" style="font-size:0.7rem;"><?= $c['status'] ?></span>
                    </td>
                    <td class="pe-4 py-2 text-end" style="border:none;" onclick="event.stopPropagation()">
                        <div class="d-flex gap-1 justify-content-end">
                            <button class="btn btn-xs btn-outline-primary" onclick="editCase(<?= htmlspecialchars(json_encode($c)) ?>)" title="Edit"><i class="bi bi-pencil"></i></button>
                            <a href="<?= BASE_URL ?>/barangay_admin/export_blotter.php?id=<?= $c['id'] ?>&amp;print=1" target="_blank" class="btn btn-xs btn-outline-secondary" title="Print PDF"><i class="bi bi-printer"></i></a>
                            <?php if ($c['status'] === 'Closed'): ?>
                            <button class="btn btn-xs btn-outline-warning" onclick="openBlotterArchiveModal(<?= $c['id'] ?>,'<?= addslashes($c['case_number']) ?>')" title="Archive"><i class="bi bi-archive"></i></button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<?php
    $queryParams = $_GET; unset($queryParams['page']);
    $baseQuery   = http_build_query($queryParams);
    $baseUrl     = '?' . ($baseQuery ? $baseQuery . '&' : '');
    $window      = 2;
?>
<?php $page = $currentPage; $totalCount = $totalRows; $offset = ($page-1)*$perPage; $paginationBase = $baseUrl; $paginationLabel = 'record'; include __DIR__ . '/../includes/pagination.php'; ?>
<?php endif; ?>

<?php endif; // end list / detail view ?>

</div><!-- /main-content -->

<!-- ══════════════════════════════════════════════════════
     MULTI-STEP WIZARD OVERLAY
     ══════════════════════════════════════════════════════ -->
<div id="blotterOverlay" style="display:none;position:fixed;top:var(--topbar-height);left:var(--sidebar-width);right:0;bottom:0;background:#e8f4fb;z-index:998;overflow-y:auto;">

  <!-- Overlay Top Bar -->
  <div style="position:sticky;top:0;z-index:999;background:#1a4f7a;color:#fff;padding:10px 20px;display:flex;align-items:center;gap:12px;box-shadow:0 2px 8px rgba(0,0,0,0.3);">
    <i class="bi bi-shield-fill-exclamation" style="font-size:1.2rem;"></i>
    <span style="font-weight:700;font-size:1rem;" id="overlayBlotterTitle">New Blotter Case</span>
    <div style="flex:1;"></div>
    <button onclick="closeBlotterOverlay()" style="padding:6px 16px;background:rgba(255,255,255,0.15);color:#fff;border:1px solid rgba(255,255,255,0.3);border-radius:6px;cursor:pointer;font-size:13px;">
      <i class="bi bi-x-lg me-1"></i>Close
    </button>
  </div>

  <!-- Step Progress Bar -->
  <div style="max-width:760px;margin:12px auto 0;padding:0 12px;">
    <div class="wizard-progress-bar">
      <?php foreach ([['1','Basic Details'],['2','Parties'],['3','Narrative'],['4','Evidence'],['5','Case Status'],['6','Review']] as $si => $st): ?>
        <button type="button" class="wizard-step-btn" onclick="goToWizardStep(<?= $si+1 ?>)" id="wsbtn-<?= $si+1 ?>">
          <div class="wizard-step-circle <?= $si===0?'active':'' ?>" id="wscircle-<?= $si+1 ?>"><?= $st[0] ?></div>
          <span class="wizard-step-label"><?= $st[1] ?></span>
        </button>
        <?php if ($si < 5): ?>
        <div class="wizard-step-line" id="wsline-<?= $si+1 ?>"></div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </div>

  <form method="POST" id="blotterMainForm" enctype="multipart/form-data">
    <input type="hidden" name="action" id="bAction" value="add">
    <?= csrfField() ?>
    <input type="hidden" name="blotter_id" id="bId">
    <!-- Complainant hidden fields synced from UI on submit -->
    <input type="hidden" name="complainant_id"          id="bComplainantId">
    <input type="hidden" name="complainant_name_manual" id="bCompNameHidden">
    <input type="hidden" name="complainant_address"     id="bCompAddrHidden">
    <input type="hidden" name="complainant_contact"     id="bCompContactHidden">
    <input type="hidden" name="complainant_gender"      id="bCompGenderHidden">
    <input type="hidden" name="complainant_age"         id="bCompAgeHidden">

  <!-- ═══════════════ STEP 1: Basic Details ═══════════════ -->
  <div id="wizStep1" style="max-width:760px;margin:10px auto 6px;padding:0 12px;">
    <div style="background:#fff;border-radius:10px;box-shadow:0 2px 12px rgba(0,0,0,0.07);border:1px solid #d0ecd6;margin-bottom:12px;overflow:hidden;">
      <div style="background:linear-gradient(90deg,rgba(26,79,122,0.08),rgba(58,127,168,0.05));border-bottom:1px solid rgba(26,79,122,0.15);padding:9px 16px;display:flex;align-items:center;gap:8px;">
        <i class="bi bi-info-circle-fill" style="color:#1a4f7a;"></i>
        <span style="color:#1a4f7a;font-weight:700;font-size:0.88rem;">Step 1 — Basic Case Details</span>
      </div>
      <div style="padding:2px 0;">

        <?php
        $fieldRow = function(string $label, string $inputHtml, bool $required = false) {
            $req = $required ? ' <span style="color:#dc3545;">*</span>' : '';
            echo '<div style="display:flex;align-items:center;border-bottom:1px solid #f0f4f8;padding:7px 16px;gap:0;">'
               . '<div style="width:180px;min-width:180px;font-size:0.81rem;font-weight:600;color:#2d3748;">' . $label . $req . '</div>'
               . '<div style="flex:1;">' . $inputHtml . '</div>'
               . '</div>';
        };
        ?>

        <?php $fieldRow('Case Title', '<input type="text" name="case_title" id="bCaseTitle" class="form-control form-control-sm" required placeholder="e.g. Physical Assault between neighbors">', true); ?>

        <?php $fieldRow('Case Type', '<select name="incident_type" id="bType" class="form-select form-select-sm" required>'
            . implode('', array_map(fn($t) => '<option value="'.htmlspecialchars($t).'">'.htmlspecialchars($t).'</option>', $incidentTypes))
            . '</select>', true); ?>

        <?php $fieldRow('Date &amp; Time Reported', '<input type="datetime-local" name="date_reported" id="bDateReported" class="form-control form-control-sm" required>', true); ?>

        <?php $fieldRow('Incident Date', '<input type="date" name="incident_date" id="bDate" class="form-control form-control-sm" required>', true); ?>

        <?php $fieldRow('Incident Time', '<input type="time" name="incident_time" id="bTime" class="form-control form-control-sm">'); ?>

        <?php $fieldRow('Incident Location', '<input type="text" name="location" id="bLocation" class="form-control form-control-sm" required placeholder="e.g. Purok 3, near the market">', true); ?>

        <?php $fieldRow('Officer Assigned', '<input type="text" name="officer_assigned" id="bOfficer" class="form-control form-control-sm" placeholder="Full name of assigned officer">'); ?>

        <?php $fieldRow('Priority', '<select name="priority" id="bPriority" class="form-select form-select-sm">'
            . '<option value="Low">Low</option>'
            . '<option value="Medium" selected>Medium</option>'
            . '<option value="High">High</option>'
            . '</select>'); ?>

        <div style="height:4px;"></div>
      </div>
    </div>
    <div style="display:flex;gap:10px;justify-content:flex-end;padding:0 0 20px;">
      <button type="button" onclick="closeBlotterOverlay()" class="btn btn-outline-secondary btn-sm">Cancel</button>
      <button type="button" onclick="wizardNext(1)" class="btn btn-primary btn-sm fw-semibold"><i class="bi bi-arrow-right me-1"></i>Next: Parties</button>
    </div>
  </div>

  <!-- ═══════════════ STEP 2: Parties ═══════════════ -->
  <div id="wizStep2" style="display:none;max-width:760px;margin:10px auto 6px;padding:0 12px;">

    <?php
    $partyRow = function(string $label, string $inputHtml) {
        echo '<div style="display:flex;align-items:center;border-bottom:1px solid #f0f4f8;padding:7px 16px;gap:0;">'
           . '<div style="width:180px;min-width:180px;font-size:0.81rem;font-weight:600;color:#2d3748;">' . $label . '</div>'
           . '<div style="flex:1;">' . $inputHtml . '</div>'
           . '</div>';
    };
    $partyRowReq = function(string $label, string $inputHtml) {
        echo '<div style="display:flex;align-items:center;border-bottom:1px solid #f0f4f8;padding:7px 16px;gap:0;">'
           . '<div style="width:180px;min-width:180px;font-size:0.81rem;font-weight:600;color:#2d3748;">' . $label . ' <span style="color:#dc3545;">*</span></div>'
           . '<div style="flex:1;">' . $inputHtml . '</div>'
           . '</div>';
    };
    ?>

    <!-- ── TAB BAR ── -->
    <div style="background:#fff;border:1px solid #d0ecd6;border-bottom:none;border-radius:10px 10px 0 0;display:flex;overflow-x:auto;box-shadow:0 2px 12px rgba(0,0,0,0.07);">
      <button type="button" class="detail-tab-btn active" id="p2tab-complainant" onclick="switchPartiesTab('complainant')" style="border-radius:0;"><i class="bi bi-person-fill me-1"></i>Complainant</button>
      <button type="button" class="detail-tab-btn" id="p2tab-respondent"  onclick="switchPartiesTab('respondent')"  style="border-radius:0;"><i class="bi bi-person-fill-exclamation me-1"></i>Respondent</button>
      <button type="button" class="detail-tab-btn" id="p2tab-suspect"     onclick="switchPartiesTab('suspect')"     style="border-radius:0;"><i class="bi bi-person-fill-lock me-1"></i>Suspect</button>
      <button type="button" class="detail-tab-btn" id="p2tab-witnesses"   onclick="switchPartiesTab('witnesses')"   style="border-radius:0;"><i class="bi bi-eye-fill me-1"></i>Witnesses</button>
      <button type="button" class="detail-tab-btn" id="p2tab-others"      onclick="switchPartiesTab('others')"      style="border-radius:0;"><i class="bi bi-people-fill me-1"></i>Others</button>
    </div>

    <!-- ── COMPLAINANT panel ── -->
    <div id="p2panel-complainant" style="background:#fff;border:1px solid #d0ecd6;border-top:none;border-radius:0 0 10px 10px;margin-bottom:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.07);">
      <!-- Toggle row -->
      <div style="display:flex;align-items:center;border-bottom:1px solid #f0f4f8;padding:7px 16px;gap:0;">
        <div style="width:180px;min-width:180px;font-size:0.81rem;font-weight:600;color:#2d3748;">Entry Mode</div>
        <div style="flex:1;display:flex;gap:8px;">
          <button type="button" id="btnIsResident" class="resident-toggle-btn active" onclick="setComplainantMode('resident')" style="flex:none;padding:4px 14px;font-size:0.8rem;"><i class="bi bi-person-check-fill me-1"></i>Registered Resident</button>
          <button type="button" id="btnIsManual"   class="resident-toggle-btn"        onclick="setComplainantMode('manual')"   style="flex:none;padding:4px 14px;font-size:0.8rem;"><i class="bi bi-person-plus me-1"></i>Manual</button>
        </div>
      </div>
      <!-- Resident picker -->
      <div id="residentPickerSection">
        <?php $partyRowReq('Resident', '<select id="step1ResidentSelect" class="form-select form-select-sm" onchange="onBlotterResidentChange(this.value)"><option value="">— Search and select a resident —</option>' . implode('', array_map(fn($r) => '<option value="' . $r['id'] . '">' . htmlspecialchars($r['full_name']) . '</option>', $residents)) . '</select>'); ?>
        <div id="residentPreviewCard" style="display:none;margin:0 20px 10px;background:#f4f9ff;border:1px solid #d0e4f0;border-radius:6px;padding:10px 14px;font-size:0.81rem;">
          <div style="font-weight:700;color:#1a4f7a;margin-bottom:6px;font-size:0.72rem;text-transform:uppercase;">Resident Details</div>
          <div class="row g-2" id="residentPreviewContent"></div>
        </div>
      </div>
      <!-- Manual entry -->
      <div id="manualEntrySection" style="display:none;">
        <?php $partyRowReq('Full Name', '<input type="text" id="step1ManualName" class="form-control form-control-sm" placeholder="Last, First Middle">'); ?>
        <?php $partyRow('Address',    '<input type="text" id="step1ManualAddr" class="form-control form-control-sm" placeholder="Purok / Street, Barangay">'); ?>
        <?php $partyRow('Contact No.','<input type="text" id="step1ManualContact" class="form-control form-control-sm" placeholder="09XX-XXX-XXXX" inputmode="numeric" maxlength="11" oninput="this.value=this.value.replace(/[^0-9]/g,\'\')">'); ?>
        <?php $partyRow('Sex',        '<select id="step1ManualGender" class="form-select form-select-sm"><option value="">—</option><option>Male</option><option>Female</option><option>Other</option></select>'); ?>
        <?php $partyRow('Age',        '<input type="number" id="step1ManualAge" class="form-control form-control-sm" min="1" max="120" placeholder="Age" oninput="this.value=this.value.replace(/[^0-9]/g,\'\')">'); ?>
      </div>
      <div style="height:4px;"></div>
    </div>

    <!-- ── RESPONDENT panel ── -->
    <div id="p2panel-respondent" style="display:none;background:#fff;border:1px solid #d0ecd6;border-top:none;border-radius:0 0 10px 10px;margin-bottom:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.07);">
      <div style="display:flex;align-items:center;border-bottom:1px solid #f0f4f8;padding:7px 16px;gap:0;">
        <div style="width:180px;min-width:180px;font-size:0.81rem;font-weight:600;color:#2d3748;">Entry Mode</div>
        <div style="flex:1;display:flex;gap:8px;">
          <button type="button" id="btnRespResident" class="resident-toggle-btn active" onclick="setRespondentMode('resident')" style="flex:none;padding:4px 14px;font-size:0.8rem;"><i class="bi bi-person-check-fill me-1"></i>Registered Resident</button>
          <button type="button" id="btnRespManual"   class="resident-toggle-btn"        onclick="setRespondentMode('manual')"   style="flex:none;padding:4px 14px;font-size:0.8rem;"><i class="bi bi-person-plus me-1"></i>Manual</button>
        </div>
      </div>
      <div id="respResidentSection">
        <?php $partyRow('Resident', '<select name="respondent_id" id="bRespondent" class="form-select form-select-sm" onchange="onRespondentChange(this.value)"><option value="">— Select resident (optional) —</option>' . implode('', array_map(fn($r) => '<option value="' . $r['id'] . '">' . htmlspecialchars($r['full_name']) . '</option>', $residents)) . '</select>'); ?>
        <div id="respResidentPreview" style="display:none;margin:0 20px 10px;background:#fff5f5;border:1px solid #fdd;border-radius:6px;padding:10px 14px;font-size:0.81rem;">
          <div style="font-weight:700;color:#dc3545;margin-bottom:6px;font-size:0.72rem;text-transform:uppercase;">Respondent Details</div>
          <div class="row g-2" id="respResidentPreviewContent"></div>
        </div>
      </div>
      <div id="respManualSection" style="display:none;">
        <?php $partyRow('Full Name',    '<input type="text" name="respondent_name_manual" id="bRespName" class="form-control form-control-sm" placeholder="Full name">'); ?>
        <?php $partyRow('Address',      '<input type="text" name="respondent_address" id="bRespAddr" class="form-control form-control-sm" placeholder="Address">'); ?>
        <?php $partyRow('Contact No.',  '<input type="text" name="respondent_contact" id="bRespContact" class="form-control form-control-sm" placeholder="09XX-XXX-XXXX" inputmode="numeric" maxlength="11">'); ?>
        <?php $partyRow('Gender',       '<select name="respondent_gender" class="form-select form-select-sm"><option value="">—</option><option>Male</option><option>Female</option><option>Other</option></select>'); ?>
        <?php $partyRow('Relationship', '<input type="text" name="respondent_relationship" id="bRespRel" class="form-control form-control-sm" placeholder="e.g. Neighbor">'); ?>
      </div>
      <input type="hidden" id="bRespNameHidden" value="">
      <input type="hidden" id="bRespAddrHidden" value="">
      <input type="hidden" id="bRespContactHidden" value="">
      <div style="height:4px;"></div>
    </div>

    <!-- ── SUSPECT panel ── -->
    <div id="p2panel-suspect" style="display:none;background:#fff;border:1px solid #d0ecd6;border-top:none;border-radius:0 0 10px 10px;margin-bottom:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.07);">
      <div style="display:flex;align-items:center;border-bottom:1px solid #f0f4f8;padding:7px 16px;gap:0;">
        <div style="width:180px;min-width:180px;font-size:0.81rem;font-weight:600;color:#2d3748;">Entry Mode</div>
        <div style="flex:1;display:flex;gap:8px;">
          <button type="button" id="btnSuspResident" class="resident-toggle-btn active" onclick="setSuspectMode('resident')" style="flex:none;padding:4px 14px;font-size:0.8rem;"><i class="bi bi-person-check-fill me-1"></i>Registered Resident</button>
          <button type="button" id="btnSuspManual"   class="resident-toggle-btn"        onclick="setSuspectMode('manual')"   style="flex:none;padding:4px 14px;font-size:0.8rem;"><i class="bi bi-person-plus me-1"></i>Manual</button>
        </div>
      </div>
      <div id="suspResidentSection">
        <?php $partyRow('Resident', '<select id="bSuspectResident" class="form-select form-select-sm" onchange="onSuspectResidentChange(this.value)"><option value="">— Select resident (optional) —</option>' . implode('', array_map(fn($r) => '<option value="' . $r['id'] . '">' . htmlspecialchars($r['full_name']) . '</option>', $residents)) . '</select>'); ?>
        <div id="suspResidentPreview" style="display:none;margin:0 20px 10px;background:#f9f5ff;border:1px solid #e8d5f5;border-radius:6px;padding:10px 14px;font-size:0.81rem;">
          <div style="font-weight:700;color:#7c3aed;margin-bottom:6px;font-size:0.72rem;text-transform:uppercase;">Suspect Details</div>
          <div class="row g-2" id="suspResidentPreviewContent"></div>
        </div>
      </div>
      <div id="suspManualSection" style="display:none;">
        <?php $partyRow('Full Name',   '<input type="text" id="bSuspName" class="form-control form-control-sm" placeholder="Full name">'); ?>
        <?php $partyRow('Address',     '<input type="text" id="bSuspAddr" class="form-control form-control-sm" placeholder="Address">'); ?>
        <?php $partyRow('Contact No.', '<input type="text" id="bSuspContact" class="form-control form-control-sm" placeholder="09XX-XXX-XXXX" inputmode="numeric" maxlength="11" oninput="this.value=this.value.replace(/[^0-9]/g,\'\')">'); ?>
        <?php $partyRow('Gender',      '<select id="bSuspGender" class="form-select form-select-sm"><option value="">—</option><option>Male</option><option>Female</option><option>Other</option></select>'); ?>
        <?php $partyRow('Age',         '<input type="number" id="bSuspAge" class="form-control form-control-sm" min="1" max="120" placeholder="Age" oninput="this.value=this.value.replace(/[^0-9]/g,\'\')">'); ?>
      </div>
      <div style="height:4px;"></div>
    </div>

    <!-- ── WITNESSES panel ── -->
    <div id="p2panel-witnesses" style="display:none;background:#fff;border:1px solid #d0ecd6;border-top:none;border-radius:0 0 10px 10px;margin-bottom:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.07);">
      <div style="padding:8px 14px;border-bottom:1px solid #f0f4f8;display:flex;align-items:center;justify-content:space-between;">
        <span style="font-size:0.82rem;color:#6c757d;"><i class="bi bi-info-circle me-1"></i>Add persons who witnessed the incident.</span>
        <button type="button" onclick="addWitness()" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus me-1"></i>Add Witness</button>
      </div>
      <div id="witnessContainer" style="padding:0;">
        <div class="witness-row" style="display:flex;align-items:center;border-bottom:1px solid #f0f4f8;padding:7px 16px;gap:6px;">
          <input type="text"  name="witness_name[]"    class="form-control form-control-sm" placeholder="Witness Name" style="flex:2;">
          <input type="text"  name="witness_address[]" class="form-control form-control-sm" placeholder="Address"       style="flex:2;">
          <input type="text"  name="witness_contact[]" class="form-control form-control-sm" placeholder="Contact No."   style="flex:1;" inputmode="numeric" maxlength="11">
          <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeWitness(this)"><i class="bi bi-trash"></i></button>
        </div>
      </div>
      <div style="height:4px;"></div>
    </div>

    <!-- ── OTHERS INVOLVED panel ── -->
    <div id="p2panel-others" style="display:none;background:#fff;border:1px solid #d0ecd6;border-top:none;border-radius:0 0 10px 10px;margin-bottom:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.07);">
      <div style="padding:8px 14px;border-bottom:1px solid #f0f4f8;display:flex;align-items:center;justify-content:space-between;">
        <span style="font-size:0.82rem;color:#6c757d;"><i class="bi bi-info-circle me-1"></i>Others not listed as complainant, respondent, or suspect.</span>
        <button type="button" onclick="addPersonInvolved()" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus me-1"></i>Add Person</button>
      </div>
      <div id="piContainer" style="padding:0;"></div>
      <div style="height:4px;"></div>
    </div>

    <div style="display:flex;gap:10px;justify-content:flex-end;padding:0 0 20px;">
      <button type="button" onclick="goToWizardStep(1)" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</button>
      <button type="button" onclick="wizardNext(2)" class="btn btn-primary btn-sm fw-semibold"><i class="bi bi-arrow-right me-1"></i>Next: Narrative</button>
    </div>
  </div>

  <!-- ═══════════════ STEP 3: Narrative & Evidence ═══════════════ -->

  <!-- ═══════════════ STEP 3: Narrative ═══════════════ -->
  <div id="wizStep3" style="display:none;max-width:760px;margin:10px auto 6px;padding:0 12px;">

    <div style="background:#fff;border-radius:10px;box-shadow:0 2px 12px rgba(0,0,0,0.07);border:1px solid #d0ecd6;margin-bottom:12px;overflow:hidden;">

      <!-- Header -->
      <div style="background:linear-gradient(90deg,rgba(26,79,122,0.08),rgba(58,127,168,0.05));border-bottom:1px solid rgba(26,79,122,0.15);padding:9px 16px;display:flex;align-items:center;gap:8px;">
        <i class="bi bi-file-text-fill" style="color:#1a4f7a;"></i>
        <span style="color:#1a4f7a;font-weight:700;font-size:0.88rem;">Step 3 — Incident Details &amp; Narrative</span>
      </div>

      <!-- Rows -->
      <div style="padding:2px 0;">

        <!-- Incident Description -->
        <div style="display:flex;align-items:flex-start;border-bottom:1px solid #f0f4f8;padding:7px 16px;gap:0;">
          <div style="width:180px;min-width:180px;font-size:0.81rem;font-weight:600;color:#2d3748;padding-top:6px;">Incident Description</div>
          <div style="flex:1;">
            <textarea name="incident_summary" id="bSummary" class="form-control form-control-sm" rows="3"
              placeholder="Brief description of the incident..."></textarea>
          </div>
        </div>

        <!-- Narrative Report -->
        <div style="display:flex;align-items:flex-start;border-bottom:1px solid #f0f4f8;padding:7px 16px;gap:0;">
          <div style="width:180px;min-width:180px;font-size:0.81rem;font-weight:600;color:#2d3748;padding-top:6px;">Narrative Report <span style="color:#dc3545;">*</span></div>
          <div style="flex:1;">
            <textarea name="narrative" id="bNarrative" class="form-control form-control-sm" rows="5" required
              placeholder="Full narrative — what happened, when, where, and how..."></textarea>
          </div>
        </div>

        <!-- Circumstances of the Incident -->
        <div style="display:flex;align-items:flex-start;padding:7px 16px;gap:0;">
          <div style="width:180px;min-width:180px;font-size:0.81rem;font-weight:600;color:#2d3748;padding-top:6px;">Circumstances of the Incident</div>
          <div style="flex:1;">
            <textarea name="detailed_narrative" id="bDetailedNarr" class="form-control form-control-sm" rows="4"
              placeholder="Circumstances, background, or context of the incident..."></textarea>
          </div>
        </div>

        <div style="height:4px;"></div>
      </div>
    </div>

    <!-- Hidden fields (non-status) for form submission -->
    <input type="hidden" name="recorded_by"          id="bRecordedBy"   value="<?= htmlspecialchars($u['name'] ?? '') ?>">
    <input type="hidden" name="date_recorded"        id="bDateRecorded" value="">

    <div style="display:flex;gap:10px;justify-content:flex-end;padding:0 0 20px;">
      <button type="button" onclick="goToWizardStep(2)" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</button>
      <button type="button" onclick="wizardNext(3)" class="btn btn-primary btn-sm fw-semibold"><i class="bi bi-arrow-right me-1"></i>Next: Evidence</button>
    </div>
  </div>

  <!-- ═══════════════ STEP 4: Evidence & Attachments ═══════════════ -->
  <div id="wizStep4" style="display:none;max-width:760px;margin:10px auto 6px;padding:0 12px;">
    <div style="background:#fff;border-radius:10px;box-shadow:0 2px 12px rgba(0,0,0,0.07);border:1px solid #d0ecd6;margin-bottom:12px;overflow:hidden;">

      <!-- Header -->
      <div style="background:linear-gradient(90deg,rgba(26,79,122,0.08),rgba(58,127,168,0.05));border-bottom:1px solid rgba(26,79,122,0.15);padding:9px 16px;display:flex;align-items:center;gap:8px;">
        <i class="bi bi-paperclip" style="color:#1a4f7a;"></i>
        <span style="color:#1a4f7a;font-weight:700;font-size:0.88rem;">Step 4 — Evidence &amp; Attachments</span>
      </div>

      <div style="padding:2px 0;">

        <!-- Info row -->
        <div style="display:flex;align-items:center;border-bottom:1px solid #f0f4f8;padding:7px 16px;gap:0;">
          <div style="width:180px;min-width:180px;font-size:0.81rem;font-weight:600;color:#2d3748;">Evidence Items</div>
          <div style="flex:1;">
            <span style="font-size:0.8rem;color:#6c757d;"><i class="bi bi-info-circle me-1"></i>Optional — add one or more items below.</span>
          </div>
        </div>

        <!-- Evidence items container — each item rendered as field rows -->
        <div id="evidenceItemsContainer">
          <!-- items injected by JS -->
        </div>

        <!-- Add button row -->
        <div style="display:flex;align-items:center;padding:7px 16px;gap:0;border-top:1px solid #f0f4f8;">
          <div style="width:180px;min-width:180px;"></div>
          <div style="flex:1;">
            <button type="button" onclick="addEvidenceRow()" class="btn btn-sm btn-outline-primary" style="border-radius:7px;">
              <i class="bi bi-plus-circle me-1"></i>Add Evidence Item
            </button>
          </div>
        </div>

        <!-- Existing attachments (edit mode) -->
        <div id="existingAttachments" style="padding:0 20px 8px;"></div>

        <div style="height:4px;"></div>
      </div>
    </div>

    <div style="display:flex;gap:10px;justify-content:flex-end;padding:0 0 20px;">
      <button type="button" onclick="goToWizardStep(3)" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</button>
      <button type="button" onclick="wizardNext(4)" class="btn btn-primary btn-sm fw-semibold"><i class="bi bi-arrow-right me-1"></i>Next: Case Status</button>
    </div>
  </div>

  <!-- ═══════════════ STEP 5: Case Status ═══════════════ -->
  <div id="wizStep5" style="display:none;max-width:760px;margin:10px auto 6px;padding:0 12px;">
    <div style="background:#fff;border-radius:10px;box-shadow:0 2px 12px rgba(0,0,0,0.07);border:1px solid #d0ecd6;margin-bottom:12px;overflow:hidden;">

      <!-- Header -->
      <div style="background:linear-gradient(90deg,rgba(26,79,122,0.08),rgba(58,127,168,0.05));border-bottom:1px solid rgba(26,79,122,0.15);padding:9px 16px;display:flex;align-items:center;gap:8px;">
        <i class="bi bi-clipboard2-check-fill" style="color:#1a4f7a;"></i>
        <span style="color:#1a4f7a;font-weight:700;font-size:0.88rem;">Step 5 — Case Status</span>
      </div>

      <div style="padding:2px 0;">

        <!-- Case Status -->
        <div style="display:flex;align-items:center;border-bottom:1px solid #f0f4f8;padding:7px 16px;gap:0;">
          <div style="width:180px;min-width:180px;font-size:0.81rem;font-weight:600;color:#2d3748;">Case Status <span style="color:#dc3545;">*</span></div>
          <div style="flex:1;">
            <select name="status" id="bStatus" class="form-select form-select-sm" required>
              <?php foreach ($statusOptions as $s): ?>
              <option value="<?= $s ?>"><?= $s ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <!-- Action Taken -->
        <div style="display:flex;align-items:flex-start;border-bottom:1px solid #f0f4f8;padding:7px 16px;gap:0;">
          <div style="width:180px;min-width:180px;font-size:0.81rem;font-weight:600;color:#2d3748;padding-top:6px;">Action Taken</div>
          <div style="flex:1;">
            <textarea name="action_taken" id="bActionTaken" class="form-control form-control-sm" rows="3"
              placeholder="Actions taken by the barangay or officer..."></textarea>
          </div>
        </div>

        <!-- Resolution -->
        <div style="display:flex;align-items:center;border-bottom:1px solid #f0f4f8;padding:7px 16px;gap:0;">
          <div style="width:180px;min-width:180px;font-size:0.81rem;font-weight:600;color:#2d3748;">Resolution</div>
          <div style="flex:1;">
            <select name="resolution" id="bResolution" class="form-select form-select-sm">
              <?php foreach ($resolutionOptions as $r): ?>
              <option value="<?= $r ?>"><?= $r ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <!-- Settlement Agreement -->
        <div style="display:flex;align-items:center;border-bottom:1px solid #f0f4f8;padding:7px 16px;gap:0;">
          <div style="width:180px;min-width:180px;font-size:0.81rem;font-weight:600;color:#2d3748;">Settlement Agreement</div>
          <div style="flex:1;">
            <select name="settlement_agreement" id="bSettlement" class="form-select form-select-sm" onchange="toggleSettlementDate(this.value)">
              <option value="0">Not Signed</option>
              <option value="1">Agreement Signed</option>
            </select>
          </div>
        </div>

        <!-- Agreement Signed Date (shown only when settlement = 1) -->
        <div id="settlementDateRow" style="display:none;align-items:center;border-bottom:1px solid #f0f4f8;padding:7px 16px;gap:0;">
          <div style="width:180px;min-width:180px;font-size:0.81rem;font-weight:600;color:#2d3748;">Agreement Signed Date</div>
          <div style="flex:1;">
            <input type="date" name="settlement_date" id="bSettlementDate" class="form-control form-control-sm">
          </div>
        </div>

        <!-- Date of Action -->
        <div style="display:flex;align-items:center;border-bottom:1px solid #f0f4f8;padding:7px 16px;gap:0;">
          <div style="width:180px;min-width:180px;font-size:0.81rem;font-weight:600;color:#2d3748;">Date of Action</div>
          <div style="flex:1;">
            <input type="date" name="date_of_action" id="bDateAction" class="form-control form-control-sm">
          </div>
        </div>

        <!-- Date Closed -->
        <div style="display:flex;align-items:center;border-bottom:1px solid #f0f4f8;padding:7px 16px;gap:0;">
          <div style="width:180px;min-width:180px;font-size:0.81rem;font-weight:600;color:#2d3748;">Date Closed</div>
          <div style="flex:1;">
            <input type="date" name="date_closed" id="bDateClosed" class="form-control form-control-sm">
            <div style="font-size:0.73rem;color:#6c757d;margin-top:3px;">Leave blank if the case is still open.</div>
          </div>
        </div>

        <!-- Remarks -->
        <div style="display:flex;align-items:flex-start;padding:7px 16px;gap:0;">
          <div style="width:180px;min-width:180px;font-size:0.81rem;font-weight:600;color:#2d3748;padding-top:6px;">Remarks</div>
          <div style="flex:1;">
            <textarea name="remarks" id="bRemarks" class="form-control form-control-sm" rows="3"
              placeholder="Additional notes or remarks..."></textarea>
          </div>
        </div>

        <div style="height:4px;"></div>
      </div>
    </div>

    <div style="display:flex;gap:10px;justify-content:flex-end;padding:0 0 20px;">
      <button type="button" onclick="goToWizardStep(4)" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</button>
      <button type="button" onclick="wizardNext(5)" class="btn btn-primary btn-sm fw-semibold"><i class="bi bi-arrow-right me-1"></i>Next: Review</button>
    </div>
  </div>

  <!-- ═══════════════ STEP 6: Review & Submit ═══════════════ -->
  <div id="wizStep6" style="display:none;max-width:760px;margin:10px auto 6px;padding:0 12px;">
    <div style="background:#fff;border-radius:14px;box-shadow:0 2px 12px rgba(0,0,0,0.07);border:1px solid #d0ecd6;margin-bottom:12px;overflow:hidden;">
      <div style="background:linear-gradient(90deg,rgba(26,79,122,0.08),rgba(58,127,168,0.05));border-bottom:1px solid rgba(26,79,122,0.15);padding:12px 20px;display:flex;align-items:center;gap:10px;">
        <i class="bi bi-check2-circle" style="color:#1a4f7a;"></i>
        <span style="color:#1a4f7a;font-weight:700;font-size:0.9rem;">Step 6 — Review &amp; Submit</span>
      </div>
      <div class="p-3" id="reviewSummary">
        <!-- Filled dynamically by buildReviewSummary() -->
      </div>
    </div>
    <div style="display:flex;gap:10px;justify-content:flex-end;padding:0 0 30px;">
      <button type="button" onclick="goToWizardStep(5)" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</button>
      <button type="submit" class="btn btn-success fw-semibold px-4" style="min-width:160px;">
        <i class="bi bi-check-lg me-1"></i><span id="bSubmitLabel">Submit Case</span>
      </button>
    </div>
  </div>

  </form><!-- /blotterMainForm -->
</div><!-- /blotterOverlay -->

<!-- ══ ARCHIVE MODAL ══ -->
<form method="POST" id="archiveBlotterForm" style="display:none;">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="archive">
  <input type="hidden" name="blotter_id" id="archiveBlotterId">
</form>
<div class="modal fade" id="archiveBlotterModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:16px;overflow:hidden;">
      <div class="modal-header" style="background:linear-gradient(135deg,#1a4f7a,#3a7fa8);border:none;padding:18px 24px;">
        <div class="d-flex align-items-center gap-3">
          <div style="width:40px;height:40px;background:rgba(255,255,255,0.15);border-radius:10px;display:flex;align-items:center;justify-content:center;">
            <i class="bi bi-archive-fill text-white" style="font-size:1.1rem;"></i>
          </div>
          <div>
            <h5 class="modal-title text-white fw-bold mb-0">Archive Case</h5>
            <div class="text-white-50" style="font-size:0.75rem;">This action is reversible</div>
          </div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-4">
        <p class="text-muted small mb-1">You are about to archive:</p>
        <p class="fw-bold mb-3" id="archiveBlotterName" style="color:#1a4f7a;"></p>
        <div class="alert alert-warning py-2 small mb-0"><i class="bi bi-exclamation-triangle me-1"></i>This case will be moved to the archive. It can be restored from the Archive section.</div>
      </div>
      <div class="modal-footer border-0 px-4 pb-4 pt-2 gap-2">
        <button type="button" class="btn btn-outline-secondary flex-fill" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary flex-fill fw-semibold" onclick="submitBlotterArchive()"><i class="bi bi-archive me-1"></i>Yes, Archive</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── CSRF token for fetch requests ─────────────────────────
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content || '';

// ── Wizard state ──────────────────────────────────────────
let _currentStep = 1;
let _complainantMode = 'resident';
let _respondentMode  = 'resident';

// ── DETAIL TAB SWITCHER ───────────────────────────────────
function switchDetailTab(tabId) {
    document.querySelectorAll('[id^="dtabcontent-"]').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('[id^="dtab-"]').forEach(el => el.classList.remove('active'));
    const tc = document.getElementById('dtabcontent-' + tabId);
    if (tc) tc.classList.add('active');
    const tb = document.getElementById('dtab-' + tabId);
    if (tb) tb.classList.add('active');
}

// ── PARTIES TAB SWITCHER (Step 2) ─────────────────────────
function switchPartiesTab(tabId) {
    ['complainant','respondent','suspect','witnesses','others'].forEach(function(id) {
        const panel = document.getElementById('p2panel-' + id);
        const btn   = document.getElementById('p2tab-' + id);
        if (panel) panel.style.display = id === tabId ? 'block' : 'none';
        if (btn)   btn.classList.toggle('active', id === tabId);
    });
}
// ── NARRATIVE SUB-TAB (removed — no longer used) ─────────
function switchNarrTab(tabId) {}

// ── WIZARD NAVIGATION ─────────────────────────────────────
function goToWizardStep(step) {
    for (let i = 1; i <= 6; i++) {
        const el = document.getElementById('wizStep' + i);
        if (el) el.style.display = i === step ? 'block' : 'none';
        const circle = document.getElementById('wscircle-' + i);
        if (circle) {
            circle.classList.remove('active','done');
            if (i < step)       circle.classList.add('done');
            else if (i === step) circle.classList.add('active');
        }
        if (i < 6) {
            const line = document.getElementById('wsline-' + i);
            if (line) line.classList.toggle('done', i < step);
        }
    }
    _currentStep = step;
    document.getElementById('blotterOverlay').scrollTop = 0;
}

function wizardNext(fromStep) {
    if (fromStep === 1) {
        const title   = document.getElementById('bCaseTitle').value.trim();
        const incDate = document.getElementById('bDate').value;
        const loc     = document.getElementById('bLocation').value.trim();
        const reported= document.getElementById('bDateReported').value;
        if (!title)   { alert('Please enter a Case Title.');            return; }
        if (!reported){ alert('Please fill in Date & Time Reported.');  return; }
        if (!incDate) { alert('Please fill in Incident Date.');         return; }
        if (!loc)     { alert('Please fill in Incident Location.');     return; }
    }
    if (fromStep === 2) {
        const resId = document.getElementById('step1ResidentSelect').value;
        const manName = document.getElementById('step1ManualName').value.trim();
        if (_complainantMode === 'resident' && !resId) {
            alert('Please select a complainant resident or switch to manual entry.'); return;
        }
        if (_complainantMode === 'manual' && !manName) {
            alert('Please enter the complainant\'s full name.'); return;
        }
    }
    if (fromStep === 3) {
        const narrative = document.getElementById('bNarrative').value.trim();
        if (!narrative) { alert('Please enter the detailed narrative.'); return; }
    }
    if (fromStep === 4) {
        // Evidence step — optional, no validation needed
    }
    if (fromStep === 5) {
        buildReviewSummary();
    }
    goToWizardStep(fromStep + 1);
}

// ── EVIDENCE & ATTACHMENTS (Step 4) ──────────────────────
let _evidenceRowCount = 0;

const EVIDENCE_TYPES = [
    'Photograph','Video Recording','Audio Recording','Document','Physical Evidence',
    'Medical Certificate','Police Report','Affidavit','Witness Statement','CCTV Footage','Other'
];

function evidenceRowHTML(idx) {
    const opts = EVIDENCE_TYPES.map(t => `<option value="${t}">${t}</option>`).join('');
    const today = new Date().toISOString().split('T')[0];
    const recBy = document.getElementById('bRecordedBy')?.value || '';
    // Each evidence item is a mini-card using the same label-left / input-right row format
    return `<div id="evRow${idx}" style="border-top:2px solid #e8f4fb;background:#fafcff;">
      <!-- Item header -->
      <div style="display:flex;align-items:center;padding:7px 16px;background:rgba(26,79,122,0.04);border-bottom:1px solid #f0f4f8;">
        <div style="flex:1;font-size:0.78rem;font-weight:700;color:#1a4f7a;"><i class="bi bi-paperclip me-1"></i>Evidence Item #${idx + 1}</div>
        <button type="button" onclick="removeEvidenceRow(${idx})" class="btn btn-xs btn-outline-danger" title="Remove this item"><i class="bi bi-trash3 me-1"></i>Remove</button>
      </div>
      <!-- Evidence Type -->
      <div style="display:flex;align-items:center;border-bottom:1px solid #f0f4f8;padding:7px 16px;gap:0;">
        <div style="width:180px;min-width:180px;font-size:0.81rem;font-weight:600;color:#2d3748;">Evidence Type</div>
        <div style="flex:1;">
          <select name="ev_type[]" class="form-select form-select-sm">
            <option value="">— Select type —</option>${opts}
          </select>
        </div>
      </div>
      <!-- Description -->
      <div style="display:flex;align-items:center;border-bottom:1px solid #f0f4f8;padding:7px 16px;gap:0;">
        <div style="width:180px;min-width:180px;font-size:0.81rem;font-weight:600;color:#2d3748;">Description</div>
        <div style="flex:1;">
          <input type="text" name="ev_description[]" class="form-control form-control-sm" placeholder="Brief description of the evidence">
        </div>
      </div>
      <!-- Supporting Documents -->
      <div style="display:flex;align-items:center;border-bottom:1px solid #f0f4f8;padding:7px 16px;gap:0;">
        <div style="width:180px;min-width:180px;font-size:0.81rem;font-weight:600;color:#2d3748;">Supporting Documents<span style="display:block;font-size:0.74rem;font-weight:400;color:#6c757d;">Photos / Files</span></div>
        <div style="flex:1;">
          <input type="file" name="attachments[]" class="form-control form-control-sm" accept="image/*,.pdf,.doc,.docx">
          <div style="font-size:0.73rem;color:#6c757d;margin-top:3px;">Accepted: images, PDF, Word documents.</div>
        </div>
      </div>
      <!-- Date Submitted -->
      <div style="display:flex;align-items:center;border-bottom:1px solid #f0f4f8;padding:7px 16px;gap:0;">
        <div style="width:180px;min-width:180px;font-size:0.81rem;font-weight:600;color:#2d3748;">Date Submitted</div>
        <div style="flex:1;">
          <input type="date" name="ev_date_submitted[]" class="form-control form-control-sm" value="${today}">
        </div>
      </div>
      <!-- Uploaded By -->
      <div style="display:flex;align-items:center;padding:7px 16px;gap:0;">
        <div style="width:180px;min-width:180px;font-size:0.81rem;font-weight:600;color:#2d3748;">Uploaded By</div>
        <div style="flex:1;">
          <input type="text" name="ev_uploaded_by[]" class="form-control form-control-sm" placeholder="Name of submitter" value="${recBy}">
        </div>
      </div>
    </div>`;
}

function addEvidenceRow() {
    const container = document.getElementById('evidenceItemsContainer');
    if (!container) return;
    // Remove empty placeholder if present
    const placeholder = container.querySelector('.ev-empty-msg');
    if (placeholder) placeholder.remove();
    container.insertAdjacentHTML('beforeend', evidenceRowHTML(_evidenceRowCount));
    _evidenceRowCount++;
}

function removeEvidenceRow(idx) {
    const row = document.getElementById('evRow' + idx);
    if (row) row.remove();
    const container = document.getElementById('evidenceItemsContainer');
    if (container && !container.querySelector('[id^="evRow"]')) {
        container.innerHTML = '<div class="ev-empty-msg" style="display:flex;align-items:center;border-bottom:1px solid #f0f4f8;padding:7px 16px;gap:0;"><div style="width:180px;min-width:180px;"></div><div style="flex:1;font-size:0.82rem;color:#6c757d;"><i class="bi bi-paperclip me-1"></i>No evidence added yet.</div></div>';
        _evidenceRowCount = 0;
    }
}

function initEvidenceStep() {
    _evidenceRowCount = 0;
    const container = document.getElementById('evidenceItemsContainer');
    if (container) container.innerHTML = '<div class="ev-empty-msg" style="display:flex;align-items:center;border-bottom:1px solid #f0f4f8;padding:7px 16px;gap:0;"><div style="width:180px;min-width:180px;"></div><div style="flex:1;font-size:0.82rem;color:#6c757d;"><i class="bi bi-paperclip me-1"></i>No evidence added yet.</div></div>';
}

// ── SETTLEMENT DATE TOGGLE ────────────────────────────────
function toggleSettlementDate(val) {
    const row = document.getElementById('settlementDateRow');
    if (row) row.style.display = val === '1' ? 'flex' : 'none';
}

function buildReviewSummary() {
    const g = id => document.getElementById(id);
    const v = id => g(id)?.value || '—';

    // Case info
    const typeVal     = v('bType');
    const dateVal     = v('bDate');
    const timeVal     = v('bTime');
    const locVal      = v('bLocation');
    const priVal      = v('bPriority') || 'Medium';
    const titleVal    = v('bCaseTitle');
    const officerVal  = v('bOfficer');
    const reportedVal = v('bDateReported');

    // Parties
    let compName = '—';
    if (_complainantMode === 'resident') {
        const sel = g('step1ResidentSelect');
        compName = sel ? (sel.options[sel.selectedIndex]?.text || '—') : '—';
        if (compName === '— Search and select a resident —') compName = '—';
    } else {
        compName = g('step1ManualName')?.value || '—';
    }
    let respName = '—';
    if (_respondentMode === 'resident') {
        const sel = g('bRespondent');
        respName = sel ? (sel.options[sel.selectedIndex]?.text || '—') : '—';
        if (respName === '— Select resident (optional) —') respName = '—';
    } else {
        respName = g('bRespName')?.value || '—';
    }

    // Narrative
    const narrEl      = g('bNarrative');
    const narrPreview = (narrEl?.value || '').substring(0, 180) + ((narrEl?.value?.length > 180) ? '…' : '');

    // Case Status
    const statusVal     = v('bStatus');
    const resolutionVal = v('bResolution');
    const settlVal      = g('bSettlement')?.value === '1' ? 'Agreement Signed' : 'Not Signed';
    const settlDate     = g('bSettlementDate')?.value || '';
    const actionVal     = g('bActionTaken')?.value?.trim() || '—';
    const dateActionVal = v('bDateAction');
    const dateClosedVal = v('bDateClosed');

    // Evidence count
    const evCount = document.querySelectorAll('#evidenceItemsContainer [id^="evRow"]').length;

    const priColors    = {Low:'secondary', Medium:'secondary', High:'secondary'};
    const statusColors = {Open:'secondary','Under Investigation':'secondary',Referred:'secondary',Escalated:'secondary',Settled:'secondary',Dismissed:'secondary',Closed:'secondary'};
    const resColors    = {Pending:'secondary', Settled:'secondary', Referred:'secondary', Dismissed:'secondary'};

    g('reviewSummary').innerHTML = `
      <div style="font-size:0.83rem;">

        <!-- Row 1: Case Info + Parties -->
        <div class="row g-3 mb-3">
          <div class="col-md-6">
            <div style="border:1px solid #d0ecd6;border-radius:8px;overflow:hidden;">
              <div style="background:rgba(26,79,122,0.06);padding:7px 12px;font-size:0.7rem;font-weight:700;color:#1a4f7a;text-transform:uppercase;letter-spacing:0.06em;">📋 Case Info</div>
              <div style="padding:10px 12px;">
                ${titleVal !== '—' ? `<div class="mb-1"><span class="text-muted">Title:</span> <strong>${titleVal}</strong></div>` : ''}
                <div class="mb-1"><span class="text-muted">Type:</span> <strong>${typeVal}</strong></div>
                <div class="mb-1"><span class="text-muted">Incident Date:</span> <strong>${dateVal}${timeVal !== '—' ? ' ' + timeVal : ''}</strong></div>
                <div class="mb-1"><span class="text-muted">Location:</span> <strong>${locVal}</strong></div>
                <div class="mb-1"><span class="text-muted">Reported:</span> <strong>${reportedVal.replace('T',' ')}</strong></div>
                ${officerVal !== '—' ? `<div class="mb-1"><span class="text-muted">Officer:</span> <strong>${officerVal}</strong></div>` : ''}
                <div class="mb-1"><span class="text-muted">Priority:</span> <span class="badge bg-${priColors[priVal]||'warning'}">${priVal}</span></div>
              </div>
            </div>
          </div>
          <div class="col-md-6">
            <div style="border:1px solid #d0ecd6;border-radius:8px;overflow:hidden;">
              <div style="background:rgba(26,79,122,0.06);padding:7px 12px;font-size:0.7rem;font-weight:700;color:#1a4f7a;text-transform:uppercase;letter-spacing:0.06em;">👥 Parties</div>
              <div style="padding:10px 12px;">
                <div class="mb-1"><span class="text-muted">Complainant:</span> <strong>${compName}</strong></div>
                <div class="mb-1"><span class="text-muted">Respondent:</span> <strong>${respName}</strong></div>
              </div>
            </div>
          </div>
        </div>

        <!-- Row 2: Case Status -->
        <div class="row g-3 mb-3">
          <div class="col-12">
            <div style="border:1px solid #d0ecd6;border-radius:8px;overflow:hidden;">
              <div style="background:rgba(26,79,122,0.06);padding:7px 12px;font-size:0.7rem;font-weight:700;color:#1a4f7a;text-transform:uppercase;letter-spacing:0.06em;">⚖️ Case Status</div>
              <div style="padding:10px 12px;" class="row g-2">
                <div class="col-md-4"><span class="text-muted">Status:</span> <span class="badge bg-${statusColors[statusVal]||'secondary'}">${statusVal}</span></div>
                <div class="col-md-4"><span class="text-muted">Resolution:</span> <span class="badge bg-${resColors[resolutionVal]||'secondary'}">${resolutionVal}</span></div>
                <div class="col-md-4"><span class="text-muted">Settlement:</span> <strong>${settlVal}</strong></div>
                ${settlDate ? `<div class="col-md-4"><span class="text-muted">Signed Date:</span> <strong>${settlDate}</strong></div>` : ''}
                ${dateActionVal !== '—' ? `<div class="col-md-4"><span class="text-muted">Date of Action:</span> <strong>${dateActionVal}</strong></div>` : ''}
                ${dateClosedVal !== '—' ? `<div class="col-md-4"><span class="text-muted">Date Closed:</span> <strong>${dateClosedVal}</strong></div>` : ''}
                ${actionVal !== '—' ? `<div class="col-12 mt-1"><span class="text-muted">Action Taken:</span><div style="color:#1a2e1f;margin-top:2px;">${actionVal}</div></div>` : ''}
              </div>
            </div>
          </div>
        </div>

        <!-- Row 3: Narrative + Evidence count -->
        <div class="row g-3">
          <div class="col-md-8">
            <div style="border:1px solid #d0ecd6;border-radius:8px;overflow:hidden;">
              <div style="background:rgba(26,79,122,0.06);padding:7px 12px;font-size:0.7rem;font-weight:700;color:#1a4f7a;text-transform:uppercase;letter-spacing:0.06em;">📝 Narrative Preview</div>
              <div style="padding:10px 12px;color:#444;line-height:1.6;">${narrPreview || '<span class="text-muted">No narrative entered.</span>'}</div>
            </div>
          </div>
          <div class="col-md-4">
            <div style="border:1px solid #d0ecd6;border-radius:8px;overflow:hidden;height:100%;">
              <div style="background:rgba(26,79,122,0.06);padding:7px 12px;font-size:0.7rem;font-weight:700;color:#1a4f7a;text-transform:uppercase;letter-spacing:0.06em;">📎 Evidence</div>
              <div style="padding:10px 12px;">
                <div style="font-size:1.5rem;font-weight:800;color:#1a4f7a;">${evCount}</div>
                <div class="text-muted" style="font-size:0.78rem;">item${evCount !== 1 ? 's' : ''} attached</div>
              </div>
            </div>
          </div>
        </div>

      </div>`;
}

// ── PRIORITY (simple select — no cards needed) ───────────
function setPriority(val) {
    const el = document.getElementById('bPriority');
    if (el) el.value = val || 'Medium';
}

// ── OPEN / CLOSE OVERLAY ──────────────────────────────────
function openBlotterOverlay(editData) {
    // ── 1. Show overlay at step 1 first ───────────────────
    document.getElementById('blotterOverlay').style.display = 'block';
    document.body.style.overflow = 'hidden';
    goToWizardStep(1);

    // ── 2. Full form reset ─────────────────────────────────
    document.getElementById('blotterMainForm').reset();

    // Clear all preview cards
    const hide = id => { const el = document.getElementById(id); if (el) el.style.display = 'none'; };
    hide('residentPreviewCard');
    hide('respResidentPreview');
    hide('suspResidentPreview');

    // Clear preview contents
    ['residentPreviewContent','respResidentPreviewContent','suspResidentPreviewContent'].forEach(id => {
        const el = document.getElementById(id); if (el) el.innerHTML = '';
    });

    // Reset hidden resp fields
    ['bRespNameHidden','bRespAddrHidden','bRespContactHidden'].forEach(id => {
        const el = document.getElementById(id); if (el) el.value = '';
    });

    // Reset all party selects
    ['step1ResidentSelect','bRespondent','bSuspectResident'].forEach(id => {
        const el = document.getElementById(id); if (el) el.value = '';
    });

    // Reset modes back to defaults
    setComplainantMode('resident');
    setRespondentMode('resident');
    setSuspectMode('resident');
    switchPartiesTab('complainant');

    // Reset dynamic containers
    document.getElementById('witnessContainer').innerHTML = witnessRowHTML();
    const piCont = document.getElementById('piContainer');
    if (piCont) piCont.innerHTML = '';
    document.getElementById('existingAttachments').innerHTML = '';
    initEvidenceStep();

    // Remove any previously injected suspect hidden inputs
    document.querySelectorAll('#blotterMainForm input[name="pi_name[]"],' +
        '#blotterMainForm input[name="pi_resident_id[]"],' +
        '#blotterMainForm input[name="pi_address[]"],' +
        '#blotterMainForm input[name="pi_contact[]"],' +
        '#blotterMainForm input[name="pi_gender[]"],' +
        '#blotterMainForm input[name="pi_age[]"],' +
        '#blotterMainForm input[name="pi_role[]"]').forEach(el => el.remove());

    // Reset complainant hidden fields
    ['bComplainantId','bCompNameHidden','bCompAddrHidden','bCompContactHidden','bCompGenderHidden','bCompAgeHidden'].forEach(id => {
        const el = document.getElementById(id); if (el) el.value = '';
    });

    // ── 3. Populate for edit OR set defaults for new ───────
    if (editData) {
        document.getElementById('bAction').value = 'edit';
        document.getElementById('bId').value     = editData.id;
        document.getElementById('overlayBlotterTitle').textContent = 'Edit Case: ' + editData.case_number;
        document.getElementById('bSubmitLabel').textContent = 'Update Blotter Case';

        // Complainant
        if (editData.complainant_id) {
            setComplainantMode('resident');
            document.getElementById('step1ResidentSelect').value = editData.complainant_id;
            onBlotterResidentChange(editData.complainant_id);
        } else {
            setComplainantMode('manual');
            document.getElementById('step1ManualName').value    = editData.complainant_name_manual || '';
            document.getElementById('step1ManualAddr').value    = editData.complainant_address    || '';
            document.getElementById('step1ManualContact').value = editData.complainant_contact    || '';
            document.getElementById('step1ManualGender').value  = editData.complainant_gender     || '';
            document.getElementById('step1ManualAge').value     = editData.complainant_age        || '';
        }
        document.getElementById('bComplainantId').value        = editData.complainant_id            || '';
        document.getElementById('bCompNameHidden').value       = editData.complainant_name_manual   || '';
        document.getElementById('bCompAddrHidden').value       = editData.complainant_address       || '';
        document.getElementById('bCompContactHidden').value    = editData.complainant_contact       || '';
        document.getElementById('bCompGenderHidden').value     = editData.complainant_gender        || '';
        document.getElementById('bCompAgeHidden').value        = editData.complainant_age           || '';

        fillBlotterForm(editData);
    } else {
        document.getElementById('bAction').value = 'add';
        document.getElementById('bId').value     = '';
        document.getElementById('overlayBlotterTitle').textContent = 'New Blotter Case';
        document.getElementById('bSubmitLabel').textContent = 'Submit Case';

        setPriority('Medium');
        const now = new Date();
        now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
        document.getElementById('bDateReported').value  = now.toISOString().slice(0, 16);
        document.getElementById('bDateRecorded').value  = now.toISOString().slice(0, 16);
        document.getElementById('bStatus').value        = 'Open';
        document.getElementById('bResolution').value    = 'Pending';
        document.getElementById('bSettlement').value    = '0';
        toggleSettlementDate('0');
        const sdEl = document.getElementById('bSettlementDate'); if (sdEl) sdEl.value = '';
        document.getElementById('bRecordedBy').value    = '<?= addslashes($u['name'] ?? '') ?>';
    }
}

function closeBlotterOverlay() {
    document.getElementById('blotterOverlay').style.display = 'none';
    document.body.style.overflow = '';
}

// ── COMPLAINANT MODE ──────────────────────────────────────
function setComplainantMode(mode) {
    _complainantMode = mode;
    const btnRes = document.getElementById('btnIsResident');
    const btnMan = document.getElementById('btnIsManual');
    const resSec = document.getElementById('residentPickerSection');
    const manSec = document.getElementById('manualEntrySection');
    if (btnRes) { btnRes.classList.toggle('active', mode === 'resident'); }
    if (btnMan) { btnMan.classList.toggle('active', mode === 'manual'); }
    if (resSec) resSec.style.display = mode === 'resident' ? 'block' : 'none';
    if (manSec) manSec.style.display = mode === 'manual' ? 'block' : 'none';
}

// ── RESPONDENT MODE ───────────────────────────────────────
function setRespondentMode(mode) {
    _respondentMode = mode;
    const btnRes = document.getElementById('btnRespResident');
    const btnMan = document.getElementById('btnRespManual');
    const resSec = document.getElementById('respResidentSection');
    const manSec = document.getElementById('respManualSection');
    if (btnRes) btnRes.classList.toggle('active', mode === 'resident');
    if (btnMan) btnMan.classList.toggle('active', mode === 'manual');
    if (resSec) resSec.style.display = mode === 'resident' ? 'block' : 'none';
    if (manSec) manSec.style.display = mode === 'manual' ? 'block' : 'none';
}

// ── SUSPECT MODE ──────────────────────────────────────────
let _suspectMode = 'resident';
function setSuspectMode(mode) {
    _suspectMode = mode;
    const btnRes = document.getElementById('btnSuspResident');
    const btnMan = document.getElementById('btnSuspManual');
    const resSec = document.getElementById('suspResidentSection');
    const manSec = document.getElementById('suspManualSection');
    if (btnRes) btnRes.classList.toggle('active', mode === 'resident');
    if (btnMan) btnMan.classList.toggle('active', mode === 'manual');
    if (resSec) resSec.style.display = mode === 'resident' ? 'block' : 'none';
    if (manSec) manSec.style.display = mode === 'manual' ? 'block' : 'none';
}

function onSuspectResidentChange(id) {
    const preview = document.getElementById('suspResidentPreview');
    if (!id) { if (preview) preview.style.display = 'none'; return; }
    fetch(`<?= BASE_URL ?>/ajax/get_resident.php?id=${id}`, { headers: {'X-CSRF-Token': CSRF_TOKEN} })
    .then(r => r.json())
    .then(d => {
        if (!d || !d.id) { if (preview) preview.style.display = 'none'; return; }
        const age  = d.birthdate ? Math.floor((new Date() - new Date(d.birthdate)) / 31557600000) : (d.age || '');
        const addr = [d.purok ? 'Purok '+d.purok : '', d.street, d.addr_barangay].filter(Boolean).join(', ');
        document.getElementById('suspResidentPreviewContent').innerHTML = `
          <div class="col-12"><span style="color:#7c3aed;font-size:0.72rem;">FULL NAME</span><div style="font-weight:700;">${d.last_name}, ${d.first_name} ${d.middle_name||''}</div></div>
          <div class="col-md-6"><span style="color:#7c3aed;font-size:0.72rem;">ADDRESS</span><div>${addr||'—'}</div></div>
          <div class="col-md-3"><span style="color:#7c3aed;font-size:0.72rem;">AGE</span><div>${age||'—'}</div></div>
          <div class="col-md-3"><span style="color:#7c3aed;font-size:0.72rem;">SEX</span><div>${d.gender||'—'}</div></div>`;
        if (preview) preview.style.display = 'block';
    });
}

// ── RESIDENT AUTO-FILL: COMPLAINANT ───────────────────────
function onBlotterResidentChange(id) {
    const card = document.getElementById('residentPreviewCard');
    if (!id) { if (card) card.style.display = 'none'; return; }
    fetch(`<?= BASE_URL ?>/ajax/get_resident.php?id=${id}`, {
        headers: { 'X-CSRF-Token': CSRF_TOKEN }
    })
    .then(r => r.json())
    .then(d => {
        if (!d || !d.id) { if (card) card.style.display = 'none'; return; }
        const age = d.birthdate ? Math.floor((new Date() - new Date(d.birthdate)) / 31557600000) : (d.age || '');
        const addr = [d.purok ? 'Purok ' + d.purok : '', d.street, d.addr_barangay].filter(Boolean).join(', ');
        document.getElementById('residentPreviewContent').innerHTML = `
          <div class="col-md-6"><span style="color:#3a7fa8;font-size:0.72rem;">FULL NAME</span><div style="font-weight:700;color:#1a4f7a;">${d.last_name}, ${d.first_name} ${d.middle_name||''}</div></div>
          <div class="col-md-3"><span style="color:#3a7fa8;font-size:0.72rem;">AGE</span><div>${age||'—'}</div></div>
          <div class="col-md-3"><span style="color:#3a7fa8;font-size:0.72rem;">SEX</span><div>${d.gender||'—'}</div></div>
          <div class="col-12"><span style="color:#3a7fa8;font-size:0.72rem;">ADDRESS</span><div>${addr||'—'}</div></div>
          <div class="col-md-6"><span style="color:#3a7fa8;font-size:0.72rem;">CONTACT</span><div>${d.contact_number||'—'}</div></div>`;
        if (card) card.style.display = 'block';
        document.getElementById('bComplainantId').value = id;
        document.getElementById('bCompNameHidden').value = `${d.last_name}, ${d.first_name} ${d.middle_name||''}`.trim();
        document.getElementById('bCompAddrHidden').value = addr;
        document.getElementById('bCompContactHidden').value = d.contact_number || '';
        document.getElementById('bCompGenderHidden').value = d.gender || '';
        document.getElementById('bCompAgeHidden').value = age;
    });
}

// ── RESIDENT AUTO-FILL: RESPONDENT ────────────────────────
function onRespondentChange(id) {
    const preview = document.getElementById('respResidentPreview');
    if (!id) { if (preview) preview.style.display = 'none'; return; }
    fetch(`<?= BASE_URL ?>/ajax/get_resident.php?id=${id}`, {
        headers: { 'X-CSRF-Token': CSRF_TOKEN }
    })
    .then(r => r.json())
    .then(d => {
        if (!d || !d.id) { if (preview) preview.style.display = 'none'; return; }
        const age  = d.birthdate ? Math.floor((new Date() - new Date(d.birthdate)) / 31557600000) : (d.age || '');
        const addr = [d.purok ? 'Purok ' + d.purok : '', d.street, d.addr_barangay].filter(Boolean).join(', ');
        const previewContent = document.getElementById('respResidentPreviewContent');
        if (previewContent) previewContent.innerHTML = `
          <div class="col-12"><span style="color:#dc3545;font-size:0.72rem;">FULL NAME</span><div style="font-weight:700;color:#dc3545;">${d.last_name}, ${d.first_name} ${d.middle_name||''}</div></div>
          <div class="col-md-6"><span style="color:#dc3545;font-size:0.72rem;">ADDRESS</span><div>${addr||'—'}</div></div>
          <div class="col-md-6"><span style="color:#dc3545;font-size:0.72rem;">CONTACT</span><div>${d.contact_number||'—'}</div></div>`;
        if (preview) preview.style.display = 'block';
        // Sync hidden manual fields for respondent (when resident mode)
        const nameH = document.getElementById('bRespNameHidden');
        const addrH = document.getElementById('bRespAddrHidden');
        const contH = document.getElementById('bRespContactHidden');
        if (nameH) nameH.value = `${d.last_name}, ${d.first_name} ${d.middle_name||''}`.trim();
        if (addrH) addrH.value = addr;
        if (contH) contH.value = d.contact_number || '';
    });
}

// ── FORM SUBMIT: sync hidden fields ───────────────────────
document.addEventListener('DOMContentLoaded', function() {
    setPriority('Medium');
    const form = document.getElementById('blotterMainForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            // Sync complainant hidden fields from UI
            if (_complainantMode === 'manual') {
                document.getElementById('bComplainantId').value = '';
                document.getElementById('bCompNameHidden').value    = document.getElementById('step1ManualName')?.value.trim() || '';
                document.getElementById('bCompAddrHidden').value    = document.getElementById('step1ManualAddr')?.value.trim() || '';
                document.getElementById('bCompContactHidden').value = document.getElementById('step1ManualContact')?.value.trim() || '';
                document.getElementById('bCompGenderHidden').value  = document.getElementById('step1ManualGender')?.value || '';
                document.getElementById('bCompAgeHidden').value     = document.getElementById('step1ManualAge')?.value || '';
            }
            // Sync respondent fields: clear manual fields when in resident mode to avoid conflicts
            if (_respondentMode === 'resident') {
                const rn = document.getElementById('bRespName');
                const ra = document.getElementById('bRespAddr');
                const rc = document.getElementById('bRespContact');
                const rr = document.getElementById('bRespRel');
                // Pre-fill from cached resident data so DB has the name even if respondent_id is used
                const nameH = document.getElementById('bRespNameHidden');
                const addrH = document.getElementById('bRespAddrHidden');
                const contH = document.getElementById('bRespContactHidden');
                if (rn && nameH) rn.value = nameH.value;
                if (ra && addrH) ra.value = addrH.value;
                if (rc && contH) rc.value = contH.value;
            }
            const narrative = document.getElementById('bNarrative')?.value.trim();
            const isEdit = document.getElementById('bAction')?.value === 'edit';
            if (!narrative && !isEdit) {
                alert('Please enter the narrative in Step 3.');
                goToWizardStep(3);
                e.preventDefault(); return;
            }

            // Inject suspect as a pi_*[] entry with role='Suspect'
            let suspName = '', suspAddr = '', suspContact = '', suspGender = '', suspResId = '';
            if (_suspectMode === 'resident') {
                const sel = document.getElementById('bSuspectResident');
                if (sel && sel.value) {
                    suspResId = sel.value;
                    const pc = document.getElementById('suspResidentPreviewContent');
                    if (pc) {
                        const nm = pc.querySelector('div[style*="font-weight:700"]');
                        if (nm) suspName = nm.textContent.trim();
                    }
                }
            } else {
                suspName    = document.getElementById('bSuspName')?.value.trim() || '';
                suspAddr    = document.getElementById('bSuspAddr')?.value.trim() || '';
                suspContact = document.getElementById('bSuspContact')?.value.trim() || '';
                suspGender  = document.getElementById('bSuspGender')?.value || '';
            }
            if (suspName || suspResId) {
                const frm = document.getElementById('blotterMainForm');
                const mkH = (n, v) => { const i = document.createElement('input'); i.type='hidden'; i.name=n; i.value=v; frm.appendChild(i); };
                mkH('pi_name[]',        suspName);
                mkH('pi_resident_id[]', suspResId);
                mkH('pi_address[]',     suspAddr);
                mkH('pi_contact[]',     suspContact);
                mkH('pi_gender[]',      suspGender);
                mkH('pi_age[]',         '');
                mkH('pi_role[]',        'Suspect');
            }
        });
    }

    // Pagination scroll
    document.querySelectorAll('.page-nav').forEach(function(link) {
        link.addEventListener('click', function(ev) {
            if (this.parentElement.classList.contains('disabled')) return;
            ev.preventDefault();
            var t = document.getElementById('logs-table');
            if (t) t.scrollIntoView({ behavior:'smooth', block:'start' });
            var href = this.getAttribute('href');
            setTimeout(function(){ window.location.href = href; }, 350);
        });
    });
    if (window.location.hash === '#logs-table') {
        setTimeout(function(){ var t=document.getElementById('logs-table'); if(t) t.scrollIntoView({behavior:'smooth',block:'start'}); }, 80);
    }
});

// ── FILL FORM (edit mode) ─────────────────────────────────
function fillBlotterForm(c) {
    const set = (id, val) => { const el = document.getElementById(id); if (el) el.value = val || ''; };
    set('bCaseTitle',     c.case_title || '');
    set('bType',          c.incident_type || 'Other');
    set('bStatus',        c.status);
    set('bDate',          c.incident_date);
    set('bTime',          c.incident_time || '');
    set('bDateReported',  (c.date_reported || '').slice(0,16));
    set('bLocation',      c.location);
    set('bRespondent',    c.respondent_id || '');
    set('bRespName',      c.respondent_name_manual || '');
    set('bRespAddr',      c.respondent_address || '');
    set('bRespContact',   c.respondent_contact || '');
    set('bRespRel',       c.respondent_relationship || '');
    set('bNarrative',     c.narrative || '');
    set('bSummary',       c.incident_summary || '');
    set('bDetailedNarr',  c.detailed_narrative || '');
    set('bActionTaken',   c.action_taken || '');
    set('bOfficer',       c.officer_assigned || '');
    set('bDateAction',    c.date_of_action || '');
    set('bResolution',    c.resolution || 'Pending');
    set('bRemarks',       c.remarks || '');
    set('bDateClosed',    c.date_closed || '');
    set('bSettlementDate', c.settlement_date || '');
    set('bRecordedBy',    c.recorded_by || '');
    set('bDateRecorded',  (c.date_recorded || '').slice(0,16));
    const settl = document.getElementById('bSettlement');
    if (settl) settl.value = (c.settlement_agreement == 1) ? '1' : '0';
    toggleSettlementDate((c.settlement_agreement == 1) ? '1' : '0');
    setPriority(c.priority || 'Medium');
    if (c.respondent_id) {
        setRespondentMode('resident');
        onRespondentChange(c.respondent_id);
    } else if (c.respondent_name_manual) {
        setRespondentMode('manual');
    }
}

function editCase(c) { openBlotterOverlay(c); }

// ── WITNESSES ─────────────────────────────────────────────
function witnessRowHTML() {
    return `<div class="witness-row" style="display:flex;align-items:center;border-bottom:1px solid #f0f4f8;padding:7px 16px;gap:6px;">
      <input type="text"  name="witness_name[]"    class="form-control form-control-sm" placeholder="Witness Name" style="flex:2;">
      <input type="text"  name="witness_address[]" class="form-control form-control-sm" placeholder="Address"       style="flex:2;">
      <input type="text"  name="witness_contact[]" class="form-control form-control-sm" placeholder="Contact No."   style="flex:1;" inputmode="numeric" maxlength="11">
      <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeWitness(this)"><i class="bi bi-trash"></i></button>
    </div>`;
}
function addWitness()   { document.getElementById('witnessContainer').insertAdjacentHTML('beforeend', witnessRowHTML()); }
function removeWitness(btn) { btn.closest('.witness-row').remove(); }

// ── PERSONS INVOLVED ──────────────────────────────────────
function piRowHTML() {
    const residents = <?= json_encode(array_map(fn($r) => ['id'=>$r['id'],'name'=>$r['full_name']], $residents)) ?>;
    let opts = '<option value="">— Select resident (optional) —</option>';
    residents.forEach(r => { opts += `<option value="${r.id}">${r.name}</option>`; });

    return `<div class="pi-row" style="background:#f8fafc;border:1px solid #e0eef5;border-radius:8px;padding:8px 12px;margin-bottom:8px;">
      <div class="row g-2 align-items-end">
        <div class="col-md-12 mb-1">
          <label style="font-size:0.72rem;font-weight:700;color:#3a7fa8;text-transform:uppercase;">Select Resident (if registered)</label>
          <select class="form-select form-select-sm pi-resident-select" name="pi_resident_id[]" onchange="onPiResidentChange(this)">
            ${opts}
          </select>
        </div>
        <div class="col-md-5">
          <label style="font-size:0.72rem;color:#6b7280;">Full Name *</label>
          <input type="text" name="pi_name[]" class="form-control form-control-sm pi-name" placeholder="Full Name" required>
        </div>
        <div class="col-md-4">
          <label style="font-size:0.72rem;color:#6b7280;">Address</label>
          <input type="text" name="pi_address[]" class="form-control form-control-sm" placeholder="Address">
        </div>
        <div class="col-md-3">
          <label style="font-size:0.72rem;color:#6b7280;">Contact</label>
          <input type="text" name="pi_contact[]" class="form-control form-control-sm" placeholder="09XX" inputmode="numeric" maxlength="11">
        </div>
        <div class="col-md-3">
          <label style="font-size:0.72rem;color:#6b7280;">Gender</label>
          <select name="pi_gender[]" class="form-select form-select-sm">
            <option value="">—</option><option>Male</option><option>Female</option><option>Other</option>
          </select>
        </div>
        <div class="col-md-2">
          <label style="font-size:0.72rem;color:#6b7280;">Age</label>
          <input type="number" name="pi_age[]" class="form-control form-control-sm" min="1" max="120" placeholder="Age" oninput="this.value=this.value.replace(/[^0-9]/g,'')">
        </div>
        <div class="col-md-4">
          <label style="font-size:0.72rem;color:#6b7280;">Role / Involvement</label>
          <select name="pi_role[]" class="form-select form-select-sm">
            <option value="Person Involved">Person Involved</option>
            <option value="Victim">Victim</option>
            <option value="Bystander">Bystander</option>
            <option value="Co-Respondent">Co-Respondent</option>
            <option value="Guardian">Guardian / Parent</option>
            <option value="Other">Other</option>
          </select>
        </div>
        <div class="col-md-3 d-flex align-items-end">
          <button type="button" class="btn btn-sm btn-outline-danger w-100" onclick="removePiRow(this)">
            <i class="bi bi-trash me-1"></i>Remove
          </button>
        </div>
      </div>
    </div>`;
}

function addPersonInvolved() {
    document.getElementById('piContainer').insertAdjacentHTML('beforeend', piRowHTML());
}

function removePiRow(btn) { btn.closest('.pi-row').remove(); }

function onPiResidentChange(sel) {
    const id = sel.value;
    if (!id) return;
    const row = sel.closest('.pi-row');
    fetch(`<?= BASE_URL ?>/ajax/get_resident.php?id=${id}`, { headers: {'X-CSRF-Token': CSRF_TOKEN} })
        .then(r => r.json())
        .then(d => {
            if (!d || !d.id) return;
            const age  = d.birthdate ? Math.floor((new Date() - new Date(d.birthdate)) / 31557600000) : (d.age || '');
            const addr = [d.purok ? 'Purok '+d.purok : '', d.street, d.addr_barangay].filter(Boolean).join(', ');
            const nm   = row.querySelector('.pi-name');
            const adEl = row.querySelector('input[name="pi_address[]"]');
            const ctEl = row.querySelector('input[name="pi_contact[]"]');
            const agEl = row.querySelector('input[name="pi_age[]"]');
            const gnEl = row.querySelector('select[name="pi_gender[]"]');
            if (nm)   nm.value   = `${d.last_name}, ${d.first_name} ${d.middle_name||''}`.trim();
            if (adEl) adEl.value = addr;
            if (ctEl) ctEl.value = d.contact_number || '';
            if (agEl) agEl.value = age || '';
            if (gnEl) gnEl.value = d.gender || '';
        });
}

// ── ARCHIVE MODAL ─────────────────────────────────────────
function openBlotterArchiveModal(id, caseNum) {
    document.getElementById('archiveBlotterId').value = id;
    document.getElementById('archiveBlotterName').textContent = 'Case #' + caseNum;
    new bootstrap.Modal(document.getElementById('archiveBlotterModal')).show();
}
function submitBlotterArchive() {
    const modal = bootstrap.Modal.getInstance(document.getElementById('archiveBlotterModal'));
    if (modal) modal.hide();
    document.getElementById('archiveBlotterForm').submit();
}
// ── FILTER AUTO-SUBMIT (search + type + status) ──────────
(function() {
    const searchInput = document.getElementById('filterSearch');
    const filterForm  = document.getElementById('blotterFilterForm');
    if (searchInput && filterForm) {
        // Auto-submit disabled — autocomplete.js handles the input event
    }
})();

function toggleSidebar() { document.getElementById('sidebar').classList.toggle('show'); }

// ── BULK SELECTION & EXPORT ───────────────────────────────
function updateBulkBar() {
    const checks  = document.querySelectorAll('.row-check:checked');
    const all     = document.querySelectorAll('.row-check');
    const bar     = document.getElementById('bulkBar');
    const counter = document.getElementById('bulkCount');
    const selAll  = document.getElementById('selectAll');

    if (checks.length > 0) {
        bar.style.display = 'flex';
        counter.textContent = checks.length + ' case' + (checks.length !== 1 ? 's' : '') + ' selected';
    } else {
        bar.style.display = 'none';
    }
    // Set select-all state
    if (selAll) {
        selAll.indeterminate = checks.length > 0 && checks.length < all.length;
        selAll.checked = checks.length === all.length && all.length > 0;
    }
    // Highlight selected rows
    document.querySelectorAll('.row-check').forEach(function(cb) {
        const row = cb.closest('tr');
        if (row) row.style.background = cb.checked ? '#eef5ff' : '';
    });
}

function rowHoverOut(row) {
    const cb = row.querySelector('.row-check');
    row.style.background = (cb && cb.checked) ? '#eef5ff' : '';
}

function clearSelection() {
    document.querySelectorAll('.row-check').forEach(cb => { cb.checked = false; });
    const selAll = document.getElementById('selectAll');
    if (selAll) { selAll.checked = false; selAll.indeterminate = false; }
    document.querySelectorAll('.blotter-row').forEach(r => r.style.background = '');
    document.getElementById('bulkBar').style.display = 'none';
}

document.addEventListener('DOMContentLoaded', function() {
    // Select-all checkbox
    const selAll = document.getElementById('selectAll');
    if (selAll) {
        selAll.addEventListener('change', function() {
            document.querySelectorAll('.row-check').forEach(cb => {
                cb.checked = this.checked;
                const row = cb.closest('tr');
                if (row) row.style.background = this.checked ? '#eef5ff' : '';
            });
            updateBulkBar();
        });
    }
});

function getSelectedIds() {
    return Array.from(document.querySelectorAll('.row-check:checked')).map(cb => cb.value);
}

function bulkExportPDF() {
    const ids = getSelectedIds();
    if (!ids.length) return;
    // Open each case in a new tab and auto-trigger the print dialog
    ids.forEach(function(id) {
        window.open('<?= BASE_URL ?>/barangay_admin/export_blotter.php?id=' + id + '&print=1', '_blank');
    });
}

function bulkExportCSV() {
    const ids = getSelectedIds();
    if (!ids.length) return;
    const url = '<?= BASE_URL ?>/barangay_admin/export_blotter.php?format=csv&ids=' + ids.join(',');
    window.location.href = url;
}
</script>

<script src="<?= BASE_URL ?>/assets/js/autocomplete.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    initAutocomplete('filterSearch', 'blotterAcList', '<?= BASE_URL ?>/ajax/search_blotter.php', 'blotterSearchClear');
});
</script>
</body>
