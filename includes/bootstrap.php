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

// Session starten wenn noch nicht gestartet
if (session_status() === PHP_SESSION_NONE) {
    session_name(defined('SESSION_NAME') ? SESSION_NAME : 'tierphysio_session');
    session_start();
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

// Login Check für Admin-Bereich
$current_file = basename($_SERVER['PHP_SELF'] ?? '');
$is_admin_area = strpos($_SERVER['REQUEST_URI'] ?? '', '/admin/') !== false;
$is_login_page = $current_file === 'login.php';
$is_api = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false;

// Prüfe Authentifizierung für Admin-Bereich (außer Login-Seite und API)
if ($is_admin_area && !$is_login_page && !$is_api) {
    if (!is_logged_in()) {
        // Speichere die ursprüngliche URL für Redirect nach Login
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        redirect('/admin/login.php');
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