<?php
/**
 * Tierphysio Manager 2.0
 * Configuration File
 */

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'tierphysio');
define('DB_USER', 'tierphysio');
define('DB_PASS', 'tierphysio_pass');
define('DB_CHARSET', 'utf8mb4');

// Application Settings
define('APP_NAME', 'Tierphysio Manager');
define('APP_VERSION', '2.0');
define('APP_TIMEZONE', 'Europe/Berlin');
define('APP_DEBUG', false);

// Table Prefix
define('DB_TABLE_PREFIX', 'tp_');

// Session Configuration
if (!defined('SESSION_LIFETIME')) {
    define('SESSION_LIFETIME', 3600); // 1 hour
}

// Global database connection variable
$db = null;

// Initialize database connection for API usage
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET
    ];
    
    $db = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    // Log error but don't expose details in production
    error_log("Database Connection Error: " . $e->getMessage());
    $db = null;
}
?>