<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

ob_start();
require_once __DIR__ . '/../config/config.php';
ob_end_flush();

$db = Database::getInstance()->getConnection();
$columns = $db->query("SHOW COLUMNS FROM certificates")->fetchAll(PDO::FETCH_COLUMN, 0);

if (in_array('certificate_data', $columns, true)) {
    echo "Certificate design data column already exists.\n";
    exit;
}

$db->exec("ALTER TABLE certificates ADD COLUMN certificate_data LONGTEXT NULL");
echo "Added certificates.certificate_data.\n";
