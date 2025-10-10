<?php
/**
 * Tierphysio Manager 2.0
 * Template Engine Configuration
 */

// All use statements at the top of the file
use TierphysioManager\Auth;
use TierphysioManager\DB;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

// Include required files
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
} elseif (file_exists(__DIR__ . '/new.config.php')) {
    require_once __DIR__ . '/new.config.php';
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

// Autoload für Twig
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

// Globales Twig-Objekt für Kompatibilität
global $twig;
if (!isset($twig)) {
    $loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/../templates');
    $twig = new \Twig\Environment($loader, [
        'cache' => false,
        'debug' => true,
        'auto_reload' => true
    ]);
    
    // Globale Variablen setzen
    $twig->addGlobal('session', $_SESSION ?? []);
    $twig->addGlobal('base_url', '/');
    $twig->addGlobal('app', $GLOBALS['app'] ?? []);
    
    // Custom Functions
    $twig->addFunction(new \Twig\TwigFunction('is_admin', function() {
        return ($_SESSION['role'] ?? '') === 'admin' || 
               ($_SESSION['user']['role'] ?? '') === 'admin';
    }));
    
    $twig->addFunction(new \Twig\TwigFunction('__', function($text) {
        return $text;
    }));
    
    // CSRF Token Verifier
    $twig->addFunction(new \Twig\TwigFunction('csrf_token', function() {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }));
    
    // CSRF Field Generator
    $twig->addFunction(new \Twig\TwigFunction('csrf_field', function() {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        $token = htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="csrf_token" value="'.$token.'">';
    }, ['is_safe' => ['html']]));
    
    $twig->addFunction(new \Twig\TwigFunction('current_user', function() {
        if (function_exists('current_user')) {
            return current_user();
        }
        return $_SESSION['user'] ?? null;
    }));
    
    $twig->addFunction(new \Twig\TwigFunction('flash', function($type) {
        if (function_exists('flash')) {
            return flash($type);
        }
        if (function_exists('get_flash')) {
            return get_flash($type);
        }
        return null;
    }));
    
    $twig->addFunction(new \Twig\TwigFunction('asset', function($path) {
        return '/' . ltrim($path, '/');
    }));
    
    $twig->addFunction(new \Twig\TwigFunction('url', function($path) {
        return '/' . ltrim($path, '/');
    }));
}

/**
 * Render a Twig template
 * @param string $template Template path relative to templates directory
 * @param array $data Data to pass to template
 * @return string
 */
function render_template($template, $data = []) {
    $loader = new FilesystemLoader(__DIR__ . '/../templates');
    $twig = new Environment($loader, [
        'cache' => false,
        'debug' => true,
        'auto_reload' => true
    ]);
    
    // Globale Variablen
    $twig->addGlobal('session', $_SESSION ?? []);
    $twig->addGlobal('base_url', '/');
    $twig->addGlobal('app', $GLOBALS['app'] ?? []);
    
    // Custom Functions hinzufügen
    $twig->addFunction(new \Twig\TwigFunction('is_admin', function() {
        return ($_SESSION['role'] ?? '') === 'admin' || 
               ($_SESSION['user']['role'] ?? '') === 'admin';
    }));
    
    $twig->addFunction(new \Twig\TwigFunction('__', function($text) {
        // Platzhalter für Übersetzungsfunktion
        return $text;
    }));
    
    // CSRF Token Verifier
    $twig->addFunction(new \Twig\TwigFunction('csrf_token', function() {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }));
    
    // CSRF Field Generator
    $twig->addFunction(new \Twig\TwigFunction('csrf_field', function() {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        $token = htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="csrf_token" value="'.$token.'">';
    }, ['is_safe' => ['html']]));
    
    $twig->addFunction(new \Twig\TwigFunction('current_user', function() {
        if (function_exists('current_user')) {
            return current_user();
        }
        return $_SESSION['user'] ?? null;
    }));
    
    $twig->addFunction(new \Twig\TwigFunction('flash', function($type) {
        if (function_exists('flash')) {
            return flash($type);
        }
        return get_flash($type);
    }));
    
    $twig->addFunction(new \Twig\TwigFunction('asset', function($path) {
        return '/' . ltrim($path, '/');
    }));
    
    $twig->addFunction(new \Twig\TwigFunction('url', function($path) {
        return '/' . ltrim($path, '/');
    }));
    
    // Debug-Extension nur im Debug-Modus
    if (defined('APP_DEBUG') && APP_DEBUG) {
        $twig->addExtension(new \Twig\Extension\DebugExtension());
    }
    
    return $twig->render($template, $data);
}

/**
 * Set a flash message
 * @param string $type Type of message (success, error, warning, info)
 * @param string $message Message text
 * @return void
 */
function set_flash($type, $message) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['flash_' . $type] = $message;
}

/**
 * Get a flash message
 * @param string $type Type of message
 * @return string|null
 */
function get_flash($type) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (isset($_SESSION['flash_' . $type])) {
        $message = $_SESSION['flash_' . $type];
        unset($_SESSION['flash_' . $type]);
        return $message;
    }
    return null;
}