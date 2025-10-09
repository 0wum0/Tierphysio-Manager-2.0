<?php
/**
 * Tierphysio Manager 2.0
 * Simple Template Rendering with Twig (Standalone)
 */

// Include Twig via vendor autoload (for Twig only)
require_once __DIR__ . '/../vendor/autoload.php';

use Twig\Loader\FilesystemLoader;
use Twig\Environment;

/**
 * Render a Twig template
 * @param string $path Template path relative to templates directory
 * @param array $data Data to pass to template
 * @return void
 */
function render_template($path, $data = []) {
    try {
        // Setup Twig loader with multiple search paths
        $loader = new FilesystemLoader([
            __DIR__ . '/../templates',
            __DIR__ . '/../templates/layouts',
            __DIR__ . '/../templates/partials'
        ]);
        
        // Setup Twig environment
        $twig = new Environment($loader, [
            'cache' => false, // Disable cache for development
            'debug' => true,
            'auto_reload' => true
        ]);
        
        // Add debug extension
        $twig->addExtension(new \Twig\Extension\DebugExtension());
        
        // Add custom functions
        $twig->addFunction(new \Twig\TwigFunction('csrf_token', function() {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            return $_SESSION['csrf_token'] ?? '';
        }));
        
        $twig->addFunction(new \Twig\TwigFunction('asset', function($path) {
            return '/' . ltrim($path, '/');
        }));
        
        $twig->addFunction(new \Twig\TwigFunction('url', function($path) {
            return '/' . ltrim($path, '/');
        }));
        
        $twig->addFunction(new \Twig\TwigFunction('route', function($name, $params = []) {
            // Simple route function for compatibility
            $routes = [
                'dashboard' => '/public/index.php',
                'owners' => '/public/owners.php',
                'patients' => '/public/patients.php',
                'appointments' => '/public/appointments.php',
                'invoices' => '/public/invoices.php',
                'settings' => '/public/settings.php',
                'login' => '/public/login.php',
                'logout' => '/public/logout.php'
            ];
            $url = $routes[$name] ?? '/public/index.php';
            if (!empty($params)) {
                $url .= '?' . http_build_query($params);
            }
            return $url;
        }));
        
        // Add global variables
        $twig->addGlobal('base_url', '');
        $twig->addGlobal('current_year', date('Y'));
        $twig->addGlobal('app', [
            'name' => 'Tierphysio Manager',
            'version' => '2.0.0',
            'locale' => 'de_DE',
            'description' => 'Moderne Praxisverwaltung für Tierphysiotherapie'
        ]);
        
        // Add flash messages if available
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (isset($_SESSION['flash_success'])) {
                $data['flash_success'] = $_SESSION['flash_success'];
                unset($_SESSION['flash_success']);
            }
            if (isset($_SESSION['flash_error'])) {
                $data['flash_error'] = $_SESSION['flash_error'];
                unset($_SESSION['flash_error']);
            }
            if (isset($_SESSION['flash_warning'])) {
                $data['flash_warning'] = $_SESSION['flash_warning'];
                unset($_SESSION['flash_warning']);
            }
            if (isset($_SESSION['flash_info'])) {
                $data['flash_info'] = $_SESSION['flash_info'];
                unset($_SESSION['flash_info']);
            }
        }
        
        // Render template
        echo $twig->render($path, $data);
        
    } catch (Exception $e) {
        // In development, show error
        if (defined('APP_DEBUG') && APP_DEBUG) {
            echo '<pre style="color: red; background: #fff; padding: 20px; border: 2px solid red;">';
            echo 'Template Error: ' . htmlspecialchars($e->getMessage());
            echo "\n\nStack Trace:\n";
            echo htmlspecialchars($e->getTraceAsString());
            echo '</pre>';
        } else {
            // In production, show generic error
            echo '<div style="padding: 20px; text-align: center;">';
            echo '<h1>Ein Fehler ist aufgetreten</h1>';
            echo '<p>Bitte versuchen Sie es später erneut oder kontaktieren Sie den Administrator.</p>';
            echo '</div>';
        }
        exit;
    }
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