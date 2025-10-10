<?php
/**
 * Tierphysio Manager 2.0 Bootstrap Loader
 * Zentrale Initialisierungsdatei für das gesamte System
 */

// Error reporting für Entwicklung
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Basis-Pfad definieren
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

// Konfiguration laden - verwende new.config.php wenn config.php nicht existiert
$config_file = __DIR__ . '/config.php';
if (!file_exists($config_file)) {
    $config_file = __DIR__ . '/new.config.php';
}
require_once $config_file;

// Datenbankverbindung
require_once __DIR__ . '/db.php';

// Globale PDO-Instanz für Kompatibilität
$pdo = pdo();

// Authentifizierung
require_once __DIR__ . '/auth.php';

// Template-System
require_once __DIR__ . '/template.php';

// KRITISCH: Session MUSS VOR jeder Weiterleitungslogik gestartet werden
if (session_status() === PHP_SESSION_NONE) {
    session_name(defined('SESSION_NAME') ? SESSION_NAME : 'tierphysio_session');
    session_start();
    error_log("[AUTH DEBUG] Session started in bootstrap.php");
}

// Auth-Instanz erstellen
$auth = new Auth();

// CSRF-Token generieren falls nicht vorhanden
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Zeitzone setzen
date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Berlin');

// Locale setzen
setlocale(LC_ALL, defined('APP_LOCALE') ? APP_LOCALE : 'de_DE.UTF-8');

/**
 * Globale Helper-Funktionen
 */

// Redirect Helper
if (!function_exists('redirect')) {
    function redirect($url) {
        header("Location: $url");
        exit;
    }
}

// Base URL Helper
if (!function_exists('base_url')) {
    function base_url($path = '') {
        $base = defined('APP_URL') ? APP_URL : '';
        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }
}

// Asset URL Helper
if (!function_exists('asset')) {
    function asset($path) {
        return base_url($path);
    }
}

// Debug Helper
if (!function_exists('dd')) {
    function dd(...$vars) {
        echo '<pre style="background: #333; color: #fff; padding: 15px; margin: 10px; border-radius: 5px;">';
        foreach ($vars as $var) {
            var_dump($var);
        }
        echo '</pre>';
        die();
    }
}

// Dump Helper (ohne die())
if (!function_exists('dump')) {
    function dump(...$vars) {
        echo '<pre style="background: #333; color: #fff; padding: 15px; margin: 10px; border-radius: 5px;">';
        foreach ($vars as $var) {
            var_dump($var);
        }
        echo '</pre>';
    }
}

// Sanitize Input Helper
if (!function_exists('clean_input')) {
    function clean_input($data) {
        $data = trim($data);
        $data = stripslashes($data);
        $data = htmlspecialchars($data);
        return $data;
    }
}

// CSRF Token Validation
if (!function_exists('verify_csrf_token')) {
    function verify_csrf_token($token) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}

// JSON Response Helper
if (!function_exists('json_response')) {
    function json_response($data, $status = 200) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
}

// Format Date Helper
if (!function_exists('format_date')) {
    function format_date($date, $format = 'd.m.Y') {
        if (empty($date)) return '';
        return date($format, strtotime($date));
    }
}

// Format Currency Helper
if (!function_exists('format_currency')) {
    function format_currency($amount) {
        return number_format($amount, 2, ',', '.') . ' €';
    }
}

// Flash Message Helper (falls noch nicht definiert)
if (!function_exists('flash')) {
    function flash($type, $message = null) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if ($message === null) {
            // Get flash message
            if (isset($_SESSION['flash_' . $type])) {
                $msg = $_SESSION['flash_' . $type];
                unset($_SESSION['flash_' . $type]);
                return $msg;
            }
            return null;
        } else {
            // Set flash message
            $_SESSION['flash_' . $type] = $message;
        }
    }
}

// Globale Whitelist für öffentliche Seiten (keine Authentifizierung erforderlich)
$publicPages = [
    'login.php',
    'logout.php',
    'install.php',
    'forgot_password.php',
    'setup_db.php',
    'run_migration.php'
];

$currentFile = basename($_SERVER['PHP_SELF'] ?? '');
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$isAdminPage = str_contains($scriptName, '/admin/');
$isPublicArea = str_contains($scriptName, '/public/');
$isApiArea = str_contains($scriptName, '/api/');

// Debug-Logging für Authentifizierung
error_log("[AUTH DEBUG] Page: $currentFile | URI: $requestUri | UserID: " . ($_SESSION['user_id'] ?? 'none') . " | Role: " . ($_SESSION['role'] ?? 'none') . " | isAdmin: " . ($isAdminPage ? 'yes' : 'no'));

// Redirect logic - NUR für Protected Pages
if (!in_array($currentFile, $publicPages)) {
    // Nicht eingeloggt -> zum entsprechenden Login
    if (!$auth->isLoggedIn()) {
        error_log("[AUTH DEBUG] User not logged in, redirecting to login");
        
        if ($isAdminPage) {
            // Admin-Bereich -> Admin Login
            header('Location: /admin/login.php');
            exit;
        } else {
            // Public-Bereich -> Public Login
            header('Location: /public/login.php');
            exit;
        }
    }

    // Eingeloggt aber im Admin-Bereich ohne Admin-Rechte
    if ($isAdminPage && !$auth->isAdmin()) {
        error_log("[AUTH DEBUG] Non-admin user trying to access admin area, redirecting to public dashboard");
        header('Location: /public/dashboard.php');
        exit;
    }
}

// Prevent login redirect loop - nur für eingeloggte User auf Login-Seiten
if ($currentFile === 'login.php' && $auth->isLoggedIn()) {
    error_log("[AUTH DEBUG] User already logged in, redirecting from login.php");
    
    // Bestimme Ziel basierend auf Rolle
    if ($auth->isAdmin()) {
        // Admin kann wählen - wenn er von Admin-Login kommt, zum Admin-Dashboard
        if ($isAdminPage) {
            header('Location: /admin/index.php');
        } else {
            header('Location: /public/dashboard.php');
        }
        exit;
    } else {
        // Normale User immer zum Public Dashboard
        header('Location: /public/dashboard.php');
        exit;
    }
}

// Setze globale Variablen für Templates
if (!isset($GLOBALS['app'])) {
    $GLOBALS['app'] = [
        'name' => defined('APP_NAME') ? APP_NAME : 'Tierphysio Manager 2.0',
        'version' => defined('APP_VERSION') ? APP_VERSION : '2.0.0',
        'url' => defined('APP_URL') ? APP_URL : '',
        'debug' => defined('APP_DEBUG') ? APP_DEBUG : false,
        'user' => current_user()
    ];
}
?>