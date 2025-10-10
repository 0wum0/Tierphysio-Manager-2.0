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
        'debug' => true
    ]);
    
    $twig->addGlobal('session', $_SESSION ?? []);
    $twig->addGlobal('base_url', '/');
    
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