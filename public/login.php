<?php
/**
 * Tierphysio Manager 2.0
 * Login Page
 */

require_once __DIR__ . '/../includes/bootstrap.php';

// Auth instance is already created in bootstrap.php
// $auth is available

// Redirect if already logged in - handled by bootstrap.php now
// This code is kept as fallback only
if ($auth->isLoggedIn()) {
    if ($auth->isAdmin()) {
        header('Location: /admin/index.php');
    } else {
        header('Location: /public/dashboard.php');
    }
    exit;
}

// Process login form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);
    
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        flash('error', 'Ungültiger Sicherheitstoken. Bitte versuchen Sie es erneut.');
    } else {
        $result = $auth->login($username, $password, $remember);
        
        if ($result['success']) {
            // Redirect based on user role
            if ($auth->isAdmin()) {
                header('Location: /admin/index.php');
            } else {
                header('Location: /public/dashboard.php');
            }
            exit;
        } else {
            flash('error', $result['message']);
        }
    }
}

// Display login page
echo $twig->render('pages/login.twig', [
    'csrf_token' => $auth->generateCSRFToken()
]);