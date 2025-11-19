<?php
declare(strict_types=1);

/**
 * OneLogin PHP-SAML settings array.
 * Fill the 'idp' section with your Identity Provider details.
 * Update 'sp' URLs to match your deployed base path (BASE_URL + /saml/...).
 */
$base = (defined('BASE_URL') ? rtrim(BASE_URL, '/') : '') . '/saml';

return [
    'strict' => true,
    'debug' => true,

    'sp' => [
        'entityId' => $base . '/metadata.php',
        'assertionConsumerService' => [
            'url' => $base . '/acs.php',
        ],
        'singleLogoutService' => [
            'url' => $base . '/logout.php',
        ],
        // Generate an SP certificate if you want signed authn requests or SLO; otherwise leave empty.
        'x509cert' => '',
        'privateKey' => '',
        'NameIDFormat' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',
    ],

    'idp' => [
        'entityId' => 'https://sts.windows.net/<TENANTID>/',
        'singleSignOnService' => [
            'url' => 'https://login.microsoftonline.com/<TENANTID>/saml2',
        ],
        'singleLogoutService' => [
            'url' => '',
        ],
        'x509cert' => '',
    ],

    'security' => [
        'nameIdEncrypted' => false,
        'authnRequestsSigned' => false,
        'logoutRequestSigned' => false,
        'logoutResponseSigned' => false,
        'wantMessagesSigned' => false,
        'wantAssertionsSigned' => true,
        'wantNameId' => true,
        'relaxDestinationValidation' => false,
        'lowercaseUrlencoding' => false,
        'requestedAuthnContext' => false,
    ],
];
