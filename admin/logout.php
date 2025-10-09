<?php
/**
 * Admin Logout
 */

require_once __DIR__ . '/../includes/bootstrap.php';

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$auth = new StandaloneAuth($pdo);

// Clear admin session
unset($_SESSION['admin']);

// Logout from main auth system
$auth->logout();

// Redirect to login page
header('Location: /admin/login.php');
exit;