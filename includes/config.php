<?php
/**
 * Configuration file for Tierphysio Manager 2.0
 */

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'tierphysio_app');
define('DB_USER', 'root');
define('DB_PASS', 'root');
define('DB_CHARSET', 'utf8mb4');

// Application settings
define('APP_NAME', 'Tierphysio Manager 2.0');
define('APP_URL', 'https://ew.makeit.uno');
define('APP_DEBUG', false);  // Set to true for development
define('APP_TIMEZONE', 'Europe/Berlin');

// File paths
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('LOG_DIR', __DIR__ . '/../logs/');

// Session settings
define('SESSION_NAME', 'tierphysio_session');
define('SESSION_LIFETIME', 86400); // 24 hours

// API settings
define('API_RATE_LIMIT', 100); // requests per minute
define('API_KEY_REQUIRED', false); // Set to true for production