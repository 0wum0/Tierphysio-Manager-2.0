<?php
/**
 * Admin Login Page
 */

// Temporär die Bootstrap-Umleitung überschreiben für Login-Seite
$_SKIP_AUTH_CHECK = true;

// Session muss VOR bootstrap.php gestartet werden
if (session_status() === PHP_SESSION_NONE) {
    session_name('tierphysio_session');
    session_start();
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/StandaloneAuth.php';

// PDO-Verbindung herstellen
$pdo = pdo();

// Auth-Instanz erstellen  
$auth = new Auth();

// Debug-Logging
error_log("[AUTH DEBUG] admin/login.php - Session user_id: " . ($_SESSION['user_id'] ?? 'none'));
error_log("[AUTH DEBUG] admin/login.php - Session role: " . ($_SESSION['role'] ?? 'none'));

// Wenn bereits als Admin eingeloggt, weiterleiten zum Dashboard
if ($auth->isLoggedIn() && $auth->isAdmin()) {
    error_log("[AUTH DEBUG] admin/login.php - User is admin, redirecting to dashboard");
    header('Location: /admin/index.php');
    exit;
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
            
            $result = $auth->login($email, $password);
            
            if ($result['success']) {
                // Login war erfolgreich, prüfe Admin-Rolle
                if ($auth->isAdmin()) {
                    // Admin login successful
                    error_log("[AUTH DEBUG] admin/login.php - Admin login successful for user " . $auth->getUserId());
                    
                    // Clear login attempts
                    unset($_SESSION['admin_login_attempts']);
                    unset($_SESSION['admin_last_attempt']);
                    unset($_SESSION['admin_login_csrf']);
                    
                    // Regenerate session ID for security
                    session_regenerate_id(true);
                    
                    header('Location: /admin/index.php');
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

// Template Engine laden
require_once __DIR__ . '/../includes/template.php';

// Render login template
echo render_template('admin/pages/login.twig', $templateData);