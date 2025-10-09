<?php
/**
 * Admin Index - Redirect to login or dashboard
 */

require_once __DIR__ . '/../includes/bootstrap.php';

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$auth = new StandaloneAuth($pdo);

// Check if logged in as admin
if ($auth->isLoggedIn()) {
    $userId = $auth->getUserId();
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM tp_user_roles ur 
        JOIN tp_roles r ON ur.role_id = r.id 
        WHERE ur.user_id = ? AND r.name = 'admin'
    ");
    $stmt->execute([$userId]);
    
    if ($stmt->fetchColumn() > 0) {
        header('Location: /admin/dashboard.php');
        exit;
    }
}

// Not logged in or not admin - redirect to login
header('Location: /admin/login.php');
exit;