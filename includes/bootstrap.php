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
    'index.php',  // public/index.php
    'setup_db.php',
    'run_migration.php'
];

$currentFile = basename($_SERVER['PHP_SELF'] ?? '');
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$isAdminArea = strpos($requestUri, '/admin/') !== false;
$isPublicArea = strpos($requestUri, '/public/') !== false;
$isApiArea = strpos($requestUri, '/api/') !== false;

// Debug-Logging für Authentifizierung
error_log("[AUTH DEBUG] Page: $currentFile | URI: $requestUri | UserID: " . ($_SESSION['user_id'] ?? 'none') . " | isAdmin: " . ($isAdminArea ? 'yes' : 'no'));

// Weiterleitungslogik - NUR wenn NICHT in der Whitelist
if (!in_array($currentFile, $publicPages)) {
    // Admin-Bereich Prüfung
    if ($isAdminArea && !$isApiArea) {
        // Prüfe ob User eingeloggt ist
        if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
            error_log("[AUTH DEBUG] Redirect to login: No user_id in session for admin area");
            // Speichere die ursprüngliche URL für Redirect nach Login
            $_SESSION['redirect_after_login'] = $requestUri;
            header('Location: /admin/login.php');
            exit;
        }
    }
    
    // Public-Bereich (außer öffentliche Seiten) könnte auch Authentifizierung erfordern
    // Dies hängt von den spezifischen Anforderungen ab
}

// Wenn eingeloggt und auf login.php, weiterleiten zum Dashboard
if (in_array($currentFile, ['login.php']) && isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    error_log("[AUTH DEBUG] User already logged in, redirecting from login.php to admin dashboard");
    // Für Admin-Login-Seite
    if ($isAdminArea) {
        header('Location: /admin/dashboard.php');
        exit;
    }
    // Für Public-Login-Seite
    if ($isPublicArea) {
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