<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$auth = saml_auth();
$settings = $auth->getSettings();
$metadata = $settings->getSPMetadata();
$errors = $settings->validateMetadata($metadata);

header('Content-Type: application/xml');
if (!empty($errors)) {
    http_response_code(500);
    echo "Invalid SP metadata: " . implode(', ', $errors);
    exit;
}
echo $metadata;
