<?php
/**
 * Simple Autoloader for Tierphysio Manager 2.0
 * Replaces Composer autoloader for Hostinger shared hosting
 */

// Register autoloader
spl_autoload_register(function ($class) {
    // Base namespace
    $prefix = 'TierphysioManager\\';
    
    // Base directory for namespace
    $base_dir = __DIR__ . '/../includes/';
    
    // Check if class uses namespace prefix
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    // Get relative class name
    $relative_class = substr($class, $len);
    
    // Replace namespace separator with directory separator
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    
    // If file exists, require it
    if (file_exists($file)) {
        require $file;
    }
});

// Load Twig if available
if (file_exists(__DIR__ . '/twig/autoload.php')) {
    require_once __DIR__ . '/twig/autoload.php';
} else {
    // Simple Twig loader for basic functionality
    spl_autoload_register(function ($class) {
        if (strpos($class, 'Twig\\') === 0) {
            $file = __DIR__ . '/' . str_replace('\\', '/', $class) . '.php';
            if (file_exists($file)) {
                require $file;
            }
        }
    });
}

// Load helper functions
$helpers = [
    'auth.php',
    'csrf.php',
    'response.php',
    'db.php'
];

foreach ($helpers as $helper) {
    $helperPath = __DIR__ . '/../includes/' . $helper;
    if (file_exists($helperPath)) {
        require_once $helperPath;
    }
}