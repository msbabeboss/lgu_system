<?php
// Redirect to landing page — works on both localhost (/lgu_system/) and Tailscale (/)
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
header('Location: ' . $base . '/home.php');
exit;
