<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$auth = saml_auth();

// Clear local app session first
$_SESSION = [];
session_destroy();

// Initiate SLO if configured; otherwise just redirect home
try {
    $returnTo = BASE_URL . '/';
    $auth->logout($returnTo);
} catch (Throwable $e) {
    header('Location: ' . $returnTo);
    exit;
}
