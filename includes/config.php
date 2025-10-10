<?php
/**
 * Tierphysio Manager 2.0
 * Configuration File - Test Environment
 */

// Database Configuration (SQLite for testing)
define('DB_TYPE', 'sqlite');
define('DB_PATH', __DIR__ . '/../data/tierphysio.db');
define('DB_HOST', 'localhost');
define('DB_NAME', 'tierphysio_db');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');
define('DB_PREFIX', 'tp_');

// Application Settings
define('APP_URL', 'http://localhost:8000');
define('APP_PATH', __DIR__ . '/../');
define('APP_DEBUG', true);
define('APP_TIMEZONE', 'Europe/Berlin');
define('APP_LOCALE', 'de_DE');
define('APP_CURRENCY', 'EUR');

// Security Settings
define('JWT_SECRET', 'test_secret_key_for_development_only');
define('CSRF_TOKEN_NAME', '_csrf_token');
define('SESSION_NAME', 'tierphysio_session');
define('SESSION_LIFETIME', 3600); // 1 hour
define('PASSWORD_MIN_LENGTH', 8);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15 minutes

// Upload Settings
define('UPLOAD_PATH', APP_PATH . 'public/uploads/');
define('UPLOAD_MAX_SIZE', 10485760); // 10MB
define('ALLOWED_FILE_TYPES', ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx']);
define('ALLOWED_IMAGE_TYPES', ['jpg', 'jpeg', 'png', 'gif']);

// Email Settings
define('MAIL_ENABLED', false);
define('MAIL_HOST', 'smtp.example.com');
define('MAIL_PORT', 587);
define('MAIL_USERNAME', 'noreply@example.com');
define('MAIL_PASSWORD', 'your_password');
define('MAIL_ENCRYPTION', 'tls');
define('MAIL_FROM_ADDRESS', 'noreply@example.com');
define('MAIL_FROM_NAME', 'Tierphysio Manager');

// Backup Settings
define('BACKUP_PATH', APP_PATH . 'backups/');
define('BACKUP_MAX_FILES', 10);
define('BACKUP_AUTO', false);
define('BACKUP_SCHEDULE', 'daily'); // daily, weekly, monthly

// Cache Settings
define('CACHE_ENABLED', false);
define('CACHE_PATH', APP_PATH . 'cache/');
define('CACHE_LIFETIME', 3600);

// API Settings
define('API_ENABLED', true);
define('API_RATE_LIMIT', 100); // requests per hour
define('API_VERSION', 'v1');

// System Settings
define('MAINTENANCE_MODE', false);
define('MAINTENANCE_MESSAGE', 'Das System wird gerade gewartet. Bitte versuchen Sie es später erneut.');
define('LOG_LEVEL', 'DEBUG'); // DEBUG, INFO, WARNING, ERROR, CRITICAL
define('LOG_PATH', APP_PATH . 'logs/');