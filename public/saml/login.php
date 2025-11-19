<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
$auth = saml_auth();
$auth->login(); // Redirects to IdP
exit;
