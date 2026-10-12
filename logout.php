<?php
require_once __DIR__ . '/config/config.php';
requireLogin();
$u = currentUser();
logActivity($u['id'], 'LOGOUT', 'Auth', 'User logged out');
logoutUser();
redirect(BASE_URL . '/home.php');
