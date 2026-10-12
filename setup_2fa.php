<?php
// setup_2fa.php is no longer used — 2FA is managed in Settings > Authenticator
require_once __DIR__ . '/config/config.php';
redirect(BASE_URL . '/super_admin/settings.php?tab=authenticator');
