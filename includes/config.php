<?php
/**
 * Tierphysio Manager 2.0
 * Configuration File
 */

// Set error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set timezone
date_default_timezone_set('Europe/Berlin');

// Application constants
define('APP_NAME', 'Tierphysio Manager');
define('APP_VERSION', '2.0.0');
define('APP_DEBUG', true);

// Session configuration
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}