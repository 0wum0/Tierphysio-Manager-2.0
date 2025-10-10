<?php
/**
 * Admin Login Page
 */

require_once __DIR__ . '/../includes/bootstrap.php';

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$auth = new StandaloneAuth($pdo);

// Debug-Logging
error_log("[AUTH DEBUG] admin/login.php - Session user_id: " . ($_SESSION['user_id'] ?? 'none'));

// If already logged in as admin, redirect to dashboard
if ($auth->isLoggedIn()) {
    $userId = $auth->getUserId();
    error_log("[AUTH DEBUG] admin/login.php - User is logged in with ID: $userId");
    
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM tp_user_roles ur 
        JOIN tp_roles r ON ur.role_id = r.id 
        WHERE ur.user_id = ? AND r.name = 'admin'
    ");
    $stmt->execute([$userId]);
    
    if ($stmt->fetchColumn() > 0) {
        error_log("[AUTH DEBUG] admin/login.php - User is admin, redirecting to dashboard");
        header('Location: /admin/dashboard.php');
        exit;
    } else {
        error_log("[AUTH DEBUG] admin/login.php - User is not admin");
    }
}

$error = '';
$csrf_token = $_SESSION['admin_login_csrf'] ?? '';

// Generate CSRF token for login form
if (empty($csrf_token)) {
    $csrf_token = bin2hex(random_bytes(32));
    $_SESSION['admin_login_csrf'] = $csrf_token;
}

// Handle login submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Basic rate limiting
    if (isset($_SESSION['admin_login_attempts'])) {
        $attempts = $_SESSION['admin_login_attempts'];
        $last_attempt = $_SESSION['admin_last_attempt'] ?? 0;
        
        if ($attempts >= 5 && (time() - $last_attempt) < 300) {
            $error = 'Zu viele fehlgeschlagene Anmeldeversuche. Bitte versuchen Sie es später erneut.';
        } elseif ((time() - $last_attempt) >= 300) {
            // Reset attempts after 5 minutes
            $_SESSION['admin_login_attempts'] = 0;
        }
    }
    
    if (empty($error)) {
        // Verify CSRF token
        $submitted_token = $_POST['csrf_token'] ?? '';
        if (!hash_equals($csrf_token, $submitted_token)) {
            $error = 'Sicherheitsvalidierung fehlgeschlagen. Bitte versuchen Sie es erneut.';
        } else {
            $email = $_POST['email'] ?? '';
            $password = $_POST['password'] ?? '';
            
            if ($auth->login($email, $password)) {
                $userId = $auth->getUserId();
                
                // Check if user has admin role
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM tp_user_roles ur 
                    JOIN tp_roles r ON ur.role_id = r.id 
                    WHERE ur.user_id = ? AND r.name = 'admin'
                ");
                $stmt->execute([$userId]);
                
                if ($stmt->fetchColumn() > 0) {
                    // Admin login successful
                    // Setze normale Session-Variablen (auth->login hat bereits user_id gesetzt)
                    $_SESSION['admin']['user_id'] = $userId;
                    $_SESSION['admin']['logged_in'] = true;
                    $_SESSION['admin']['csrf_token'] = bin2hex(random_bytes(32));
                    
                    error_log("[AUTH DEBUG] admin/login.php - Admin login successful for user $userId");
                    
                    // Clear login attempts
                    unset($_SESSION['admin_login_attempts']);
                    unset($_SESSION['admin_last_attempt']);
                    unset($_SESSION['admin_login_csrf']);
                    
                    // Regenerate session ID for security
                    session_regenerate_id(true);
                    
                    header('Location: /admin/dashboard.php');
                    exit;
                } else {
                    // User exists but is not an admin
                    $auth->logout();
                    $error = 'Zugriff verweigert. Administratorrechte erforderlich.';
                }
            } else {
                // Login failed
                $error = 'Ungültige E-Mail-Adresse oder Passwort.';
                
                // Increment attempts
                $_SESSION['admin_login_attempts'] = ($_SESSION['admin_login_attempts'] ?? 0) + 1;
                $_SESSION['admin_last_attempt'] = time();
                
                // Add delay on failed attempts
                if ($_SESSION['admin_login_attempts'] > 2) {
                    sleep(1);
                }
            }
        }
    }
    
    // Regenerate CSRF token after failed attempt
    $csrf_token = bin2hex(random_bytes(32));
    $_SESSION['admin_login_csrf'] = $csrf_token;
}

// Prepare template data
$templateData = [
    'error' => $error,
    'csrf_token' => $csrf_token,
    'title' => 'Admin Login'
];

// Render login template
echo $twig->render('admin/pages/login.twig', $templateData);