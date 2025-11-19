<?php
declare(strict_types=1);

// Loads Composer autoloader for OneLogin toolkit and our app bootstrap
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../lib/bootstrap.php';

use OneLogin\Saml2\Auth;
use OneLogin\Saml2\Utils;
use OneLogin\Saml2\Error;

function saml_auth(): Auth {
    $settings = require __DIR__ . '/settings.php';
    return new Auth($settings);
}

/**
 * Given a OneLogin\Saml2\Auth instance, resolve the user's email from SAML response.
 * Checks NameID then common email attributes.
 */
function saml_extract_email(Auth $auth): ?string {
    $nameId = $auth->getNameId();
    if (is_string($nameId) && $nameId !== '') { return strtolower($nameId); }

    $attrs = $auth->getAttributes();
    $candidates = ['email','mail','upn','User.email','http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress'];
    foreach ($candidates as $k) {
        if (!empty($attrs[$k][0])) {
            return strtolower(trim((string)$attrs[$k][0]));
        }
    }
    return null;
}

/**
 * Optionally extract a display name (best-effort).
 */
function saml_extract_name(Auth $auth): ?string {
    $attrs = $auth->getAttributes();
    $candidates = ['name','displayName','givenName','http://schemas.xmlsoap.org/ws/2005/05/identity/claims/name'];
    foreach ($candidates as $k) {
        if (!empty($attrs[$k][0])) {
            return trim((string)$attrs[$k][0]);
        }
    }
    return null;
}
