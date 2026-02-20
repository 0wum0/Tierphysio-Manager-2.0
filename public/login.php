<?php
/**
 * Tierphysio Manager 2.0
 * Login Page
 */

require_once __DIR__ . '/../includes/autoload.php';
require_once __DIR__ . '/../includes/version.php';

use TierphysioManager\Auth;
use TierphysioManager\Template;

// Initialize services
$auth = Auth::getInstance();
$template = Template::getInstance();

// Redirect if already logged in
if ($auth->isLoggedIn()) {
    header('Location: index.php');
    exit;
}

// Process login form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);
    
    // Verify CSRF token
    if (!$auth->verifyCSRFToken($_POST['_csrf_token'] ?? '')) {
        Template::setFlash('error', 'Ungültiger Sicherheitstoken. Bitte versuchen Sie es erneut.');
    } else {
        $result = $auth->login($username, $password, $remember);
        
        if ($result['success']) {
            header('Location: index.php');
            exit;
        } else {
            Template::setFlash('error', $result['message']);
        }
    }
}

// Display login page
$template->display('pages/login.twig');