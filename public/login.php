<?php
/**
 * Tierphysio Manager 2.0
 * Login Page
 */

require_once __DIR__ . '/../includes/bootstrap.php';

// Auth instance is already created in bootstrap.php
// $auth is available

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
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        flash('error', 'Ungültiger Sicherheitstoken. Bitte versuchen Sie es erneut.');
    } else {
        $result = $auth->login($username, $password, $remember);
        
        if ($result['success']) {
            header('Location: index.php');
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