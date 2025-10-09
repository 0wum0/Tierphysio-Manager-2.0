<?php
/**
 * Tierphysio Manager 2.0
 * Version Information
 */

if (!defined('APP_VERSION')) define('APP_VERSION', '2.0.0');
if (!defined('DB_VERSION')) define('DB_VERSION', '2.0.0');
if (!defined('APP_NAME')) define('APP_NAME', 'Tierphysio Manager');
if (!defined('APP_DESCRIPTION')) define('APP_DESCRIPTION', 'Moderne Praxisverwaltung für Tierphysiotherapie');
if (!defined('APP_AUTHOR')) define('APP_AUTHOR', 'TierphysioManager Team');
if (!defined('APP_YEAR')) define('APP_YEAR', '2024');
if (!defined('MIN_PHP_VERSION')) define('MIN_PHP_VERSION', '8.3.0');

// Feature Flags
if (!defined('FEATURE_PWA')) define('FEATURE_PWA', true);
if (!defined('FEATURE_DARK_MODE')) define('FEATURE_DARK_MODE', true);
if (!defined('FEATURE_BACKUP')) define('FEATURE_BACKUP', true);
if (!defined('FEATURE_API')) define('FEATURE_API', true);
if (!defined('FEATURE_MULTI_LANGUAGE')) define('FEATURE_MULTI_LANGUAGE', true);

// System Requirements
if (!defined('REQUIREMENTS')) define('REQUIREMENTS', [
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