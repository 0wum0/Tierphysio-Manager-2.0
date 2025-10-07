<?php
/**
 * Tierphysio Manager 2.0
 * Version Information
 */

define('APP_VERSION', '2.0.0');
define('DB_VERSION', '2.0.0');
define('APP_NAME', 'Tierphysio Manager');
define('APP_DESCRIPTION', 'Moderne Praxisverwaltung für Tierphysiotherapie');
define('APP_AUTHOR', 'TierphysioManager Team');
define('APP_YEAR', '2024');
define('MIN_PHP_VERSION', '8.3.0');

// Feature Flags
define('FEATURE_PWA', true);
define('FEATURE_DARK_MODE', true);
define('FEATURE_BACKUP', true);
define('FEATURE_API', true);
define('FEATURE_MULTI_LANGUAGE', true);

// System Requirements
define('REQUIREMENTS', [
    'php' => '8.3.0',
    'extensions' => [
        'pdo' => 'PDO',
        'pdo_mysql' => 'PDO MySQL',
        'mbstring' => 'Multibyte String',
        'json' => 'JSON',
        'fileinfo' => 'File Information',
        'openssl' => 'OpenSSL',
        'gd' => 'GD Library',
        'curl' => 'cURL'
    ],
    'writable_dirs' => [
        'public/uploads',
        'backups',
        'includes'
    ]
]);