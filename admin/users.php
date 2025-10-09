<?php
/**
 * Admin Users Management
 */

require_once __DIR__ . '/../includes/bootstrap.php';

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$auth = new StandaloneAuth($pdo);
$auth->requireLogin();

// Check admin role
$userId = $auth->getUserId();
$stmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM tp_user_roles ur 
    JOIN tp_roles r ON ur.role_id = r.id 
    WHERE ur.user_id = ? AND r.name = 'admin'
");
$stmt->execute([$userId]);

if ($stmt->fetchColumn() == 0) {
    header('Location: /admin/login.php');
    exit;
}

// Generate CSRF token
$csrf_token = $_SESSION['admin']['csrf_token'] ?? '';
if (empty($csrf_token)) {
    $csrf_token = bin2hex(random_bytes(32));
    $_SESSION['admin']['csrf_token'] = $csrf_token;
}

// Get all users with their roles
$stmt = $pdo->query("
    SELECT u.*, GROUP_CONCAT(r.name) as roles
    FROM tp_users u
    LEFT JOIN tp_user_roles ur ON u.id = ur.user_id
    LEFT JOIN tp_roles r ON ur.role_id = r.id
    GROUP BY u.id
    ORDER BY u.created_at DESC
");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all available roles
$stmt = $pdo->query("SELECT * FROM tp_roles ORDER BY name");
$roles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Prepare template data
$templateData = [
    'title' => 'Benutzerverwaltung',
    'csrf_token' => $csrf_token,
    'users' => $users,
    'roles' => $roles,
    'user' => $auth->getUser()
];

// Render users template
echo $twig->render('admin/pages/users.twig', $templateData);