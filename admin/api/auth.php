<?php
/**
 * Admin Authentication API
 */

// Skip default admin check for auth endpoints
$skipAdminCheck = true;

require_once __DIR__ . '/../../includes/bootstrap.php';

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$auth = new StandaloneAuth($pdo);

// Helper functions from _bootstrap
require_once __DIR__ . '/_bootstrap_helpers.php';

// Get action
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'login':
        csrf_check();
        
        $data = getJsonInput();
        $email = $data['email'] ?? $_POST['email'] ?? '';
        $password = $data['password'] ?? $_POST['password'] ?? '';
        
        if (empty($email) || empty($password)) {
            api_error('E-Mail und Passwort sind erforderlich');
        }
        
        if ($auth->login($email, $password)) {
            $userId = $auth->getUserId();
            
            // Check admin role
            $stmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM tp_user_roles ur 
                JOIN tp_roles r ON ur.role_id = r.id 
                WHERE ur.user_id = ? AND r.name = 'admin'
            ");
            $stmt->execute([$userId]);
            
            if ($stmt->fetchColumn() > 0) {
                $_SESSION['admin']['user_id'] = $userId;
                $_SESSION['admin']['logged_in'] = true;
                $_SESSION['admin']['csrf_token'] = bin2hex(random_bytes(32));
                
                api_success([
                    'user' => $auth->getUser(),
                    'csrf_token' => $_SESSION['admin']['csrf_token']
                ], 'Erfolgreich angemeldet');
            } else {
                $auth->logout();
                api_error('Keine Administratorrechte', 403);
            }
        } else {
            api_error('Ungültige Anmeldedaten', 401);
        }
        break;
        
    case 'logout':
        unset($_SESSION['admin']);
        $auth->logout();
        api_success(null, 'Erfolgreich abgemeldet');
        break;
        
    case 'check':
        if ($auth->isLoggedIn() && isAdmin()) {
            api_success([
                'logged_in' => true,
                'user' => $auth->getUser(),
                'csrf_token' => getCsrfToken()
            ]);
        } else {
            api_success(['logged_in' => false]);
        }
        break;
        
    case 'refresh_token':
        requireAdmin();
        $newToken = generateCsrfToken();
        api_success(['csrf_token' => $newToken], 'Token erneuert');
        break;
        
    default:
        api_error('Unbekannte Aktion', 400);
}