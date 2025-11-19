<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$auth = saml_auth();
$auth->processResponse();
$errors = $auth->getErrors();
if (!empty($errors)) {
    http_response_code(400);
    echo "SAML ACS error: " . implode(', ', $errors);
    exit;
}
if (!$auth->isAuthenticated()) {
    http_response_code(401);
    echo "Not authenticated via SAML";
    exit;
}

// Resolve email (primary key) and display name
$email = saml_extract_email($auth);
$name  = saml_extract_name($auth);

if (!$email) {
    http_response_code(400);
    echo "SAML response missing an email (NameID/mail/upn).";
    exit;
}

// Attach to app session
$_SESSION['saml_authenticated'] = true;
$_SESSION['saml_email'] = $email;

// Auto-provision user if not found
$u = q("SELECT id FROM users WHERE email=?", [$email])->fetch();
if (!$u && (defined('SAML_AUTOPROVISION') ? SAML_AUTOPROVISION : true)) {
    q("INSERT INTO users (name, email, role, is_active) VALUES (?,?, 'user', 1)", [$name ?: null, $email]);
    $uid = (int)db()->lastInsertId();
    q("INSERT INTO audit_log (actor_user_id, action, entity_type, entity_id, details) VALUES (?,?,?,?,?)",
      [$uid, 'autoprovision', 'user', $uid, json_encode(['email'=>$email, 'name'=>$name])]);
} elseif ($u) {
    $_SESSION['user_id'] = (int)$u['id']; // Bind to existing account if found
}

// Redirect to homepage
$relay = $_GET['RelayState'] ?? (BASE_URL . '/');
header('Location: ' . $relay);
exit;
