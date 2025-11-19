<?php
declare(strict_types=1);

define('APP_NAME', 'Color War Dashboard');
define('SESSION_NAME', 'colorwar_session');
// Resolve DB path relative to this file
define('DB_PATH', __DIR__ . '/../data/app.sqlite');

// If your app is hosted in a subdirectory, set BASE_URL (no trailing slash), else keep empty.
define('BASE_URL', '');

// Session cookie params
ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');
// SameSite is set via session_set_cookie_params in auth.php

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');

//Restrict access to the site
define('SAML_AUTOPROVISION', false);