<?php
declare(strict_types=1);

if (defined('TP_AUTOLOAD_READY')) {
    return;
}
define('TP_AUTOLOAD_READY', true);

$projectRoot = dirname(__DIR__);
$candidates = [
    $projectRoot . '/vendor/autoload.php',
    dirname($projectRoot) . '/vendor/autoload.php',
    dirname(dirname($projectRoot)) . '/vendor/autoload.php',
];

foreach ($candidates as $autoload) {
    if (is_file($autoload)) {
        require_once $autoload;
        return;
    }
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'TierphysioManager\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $relative = str_replace('\\', '/', $relative);

    // Explicit map for case-sensitive disambiguation on Linux/Mac servers
    $map = [
        'Template' => __DIR__ . '/Template.php',
        'Auth'     => __DIR__ . '/Auth.php',
    ];

    if (isset($map[$relative]) && is_file($map[$relative])) {
        require_once $map[$relative];
        return;
    }

    $path = __DIR__ . '/' . $relative . '.php';

    if (is_file($path)) {
        require_once $path;
    }
});
