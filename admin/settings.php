<?php
/**
 * Admin Settings Page
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

// Load current settings
$settings = [];
$stmt = $pdo->query("SELECT `key`, value FROM tp_settings");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['key']] = $row['value'];
}

// Default values
$settings['practice_name'] = $settings['practice_name'] ?? 'Tierphysiotherapie Praxis';
$settings['site_name'] = $settings['site_name'] ?? 'Tierphysio Manager';
$settings['domain'] = $settings['domain'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
$settings['theme_primary_color'] = $settings['theme_primary_color'] ?? '#9b5de5';
$settings['theme_accent_color'] = $settings['theme_accent_color'] ?? '#7C4DFF';
$settings['logo_path'] = $settings['logo_path'] ?? '';
$settings['imprint'] = $settings['imprint'] ?? '';
$settings['privacy_policy'] = $settings['privacy_policy'] ?? '';
$settings['terms_conditions'] = $settings['terms_conditions'] ?? '';

// Prepare template data
$templateData = [
    'title' => 'Einstellungen',
    'csrf_token' => $csrf_token,
    'settings' => $settings,
    'user' => $auth->getUser()
];

// Render settings template
echo $twig->render('admin/pages/settings.twig', $templateData);