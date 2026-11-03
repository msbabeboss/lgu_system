<?php
require_once __DIR__ . '/config/config.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method Not Allowed');
}

verifyCsrfOrAbort();

$u = currentUser();
logActivity($u['id'], 'LOGOUT', 'Auth', 'User logged out');
logoutUser();
redirect(BASE_URL . '/home.php');
