<?php
/**
 * Admin Index - Redirect to login or dashboard
 */

require_once __DIR__ . '/../includes/bootstrap.php';

// Session wird bereits in bootstrap.php gestartet
// Debug-Logging
error_log("[AUTH DEBUG] admin/index.php - Session user_id: " . ($_SESSION['user_id'] ?? 'none'));

$auth = new StandaloneAuth($pdo);

// Check if logged in as admin
if ($auth->isLoggedIn()) {
    $userId = $auth->getUserId();
    error_log("[AUTH DEBUG] admin/index.php - User is logged in with ID: $userId");
    
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM tp_user_roles ur 
        JOIN tp_roles r ON ur.role_id = r.id 
        WHERE ur.user_id = ? AND r.name = 'admin'
    ");
    $stmt->execute([$userId]);
    
    if ($stmt->fetchColumn() > 0) {
        error_log("[AUTH DEBUG] admin/index.php - User is admin, redirecting to dashboard");
        header('Location: /admin/dashboard.php');
        exit;
    } else {
        error_log("[AUTH DEBUG] admin/index.php - User is not admin, redirecting to login");
    }
} else {
    error_log("[AUTH DEBUG] admin/index.php - User not logged in, redirecting to login");
}

// Not logged in or not admin - redirect to login
header('Location: /admin/login.php');
exit;