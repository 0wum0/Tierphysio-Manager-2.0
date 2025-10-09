<?php
/**
 * Tierphysio Manager 2.0
 * Configuration File
 */

// Application Settings - mit if (!defined()) umschlossen
if (!defined('APP_NAME')) define('APP_NAME', 'Tierphysio Manager 2.0');
if (!defined('APP_VERSION')) define('APP_VERSION', '2.0.0');

// Database Configuration
if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', 'tierphysio_db');
if (!defined('DB_USER')) define('DB_USER', 'root');
if (!defined('DB_PASS')) define('DB_PASS', '');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');
if (!defined('DB_PREFIX')) define('DB_PREFIX', 'tp_');
if (!defined('DB_TABLE_PREFIX')) define('DB_TABLE_PREFIX', 'tp_');

// Application Settings
if (!defined('APP_URL')) define('APP_URL', 'http://localhost');
if (!defined('APP_PATH')) define('APP_PATH', __DIR__ . '/../');
if (!defined('APP_DEBUG')) define('APP_DEBUG', true);
if (!defined('APP_TIMEZONE')) define('APP_TIMEZONE', 'Europe/Berlin');
if (!defined('APP_LOCALE')) define('APP_LOCALE', 'de_DE');
if (!defined('APP_CURRENCY')) define('APP_CURRENCY', 'EUR');

// Security Settings
if (!defined('JWT_SECRET')) define('JWT_SECRET', 'your_jwt_secret_key_here');
if (!defined('CSRF_TOKEN_NAME')) define('CSRF_TOKEN_NAME', '_csrf_token');
if (!defined('SESSION_NAME')) define('SESSION_NAME', 'tierphysio_session');
if (!defined('SESSION_LIFETIME')) define('SESSION_LIFETIME', 3600); // 1 hour
if (!defined('PASSWORD_MIN_LENGTH')) define('PASSWORD_MIN_LENGTH', 8);
if (!defined('MAX_LOGIN_ATTEMPTS')) define('MAX_LOGIN_ATTEMPTS', 5);
if (!defined('LOGIN_LOCKOUT_TIME')) define('LOGIN_LOCKOUT_TIME', 900); // 15 minutes

// Upload Settings
if (!defined('UPLOAD_PATH')) define('UPLOAD_PATH', APP_PATH . 'public/uploads/');
if (!defined('UPLOAD_MAX_SIZE')) define('UPLOAD_MAX_SIZE', 10485760); // 10MB
if (!defined('ALLOWED_FILE_TYPES')) define('ALLOWED_FILE_TYPES', ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx']);
if (!defined('ALLOWED_IMAGE_TYPES')) define('ALLOWED_IMAGE_TYPES', ['jpg', 'jpeg', 'png', 'gif']);

// Email Settings
if (!defined('MAIL_ENABLED')) define('MAIL_ENABLED', false);
if (!defined('MAIL_HOST')) define('MAIL_HOST', 'smtp.example.com');
if (!defined('MAIL_PORT')) define('MAIL_PORT', 587);
if (!defined('MAIL_USERNAME')) define('MAIL_USERNAME', 'noreply@example.com');
if (!defined('MAIL_PASSWORD')) define('MAIL_PASSWORD', 'your_password');
if (!defined('MAIL_ENCRYPTION')) define('MAIL_ENCRYPTION', 'tls');
if (!defined('MAIL_FROM_ADDRESS')) define('MAIL_FROM_ADDRESS', 'noreply@example.com');
if (!defined('MAIL_FROM_NAME')) define('MAIL_FROM_NAME', 'Tierphysio Manager');

// Backup Settings
if (!defined('BACKUP_PATH')) define('BACKUP_PATH', APP_PATH . 'backups/');
if (!defined('BACKUP_MAX_FILES')) define('BACKUP_MAX_FILES', 10);
if (!defined('BACKUP_AUTO')) define('BACKUP_AUTO', false);
if (!defined('BACKUP_SCHEDULE')) define('BACKUP_SCHEDULE', 'daily'); // daily, weekly, monthly

// Cache Settings
if (!defined('CACHE_ENABLED')) define('CACHE_ENABLED', false);
if (!defined('CACHE_PATH')) define('CACHE_PATH', APP_PATH . 'cache/');
if (!defined('CACHE_LIFETIME')) define('CACHE_LIFETIME', 3600);

// API Settings
if (!defined('API_ENABLED')) define('API_ENABLED', true);
if (!defined('API_RATE_LIMIT')) define('API_RATE_LIMIT', 100); // requests per hour
if (!defined('API_VERSION')) define('API_VERSION', 'v1');

// System Settings
if (!defined('MAINTENANCE_MODE')) define('MAINTENANCE_MODE', false);
if (!defined('MAINTENANCE_MESSAGE')) define('MAINTENANCE_MESSAGE', 'Das System wird gerade gewartet. Bitte versuchen Sie es später erneut.');
if (!defined('LOG_LEVEL')) define('LOG_LEVEL', 'ERROR'); // DEBUG, INFO, WARNING, ERROR, CRITICAL
if (!defined('LOG_PATH')) define('LOG_PATH', APP_PATH . 'logs/');
