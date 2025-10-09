<?php
/**
 * Admin Email Management
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

// Load SMTP settings
$smtp_settings = [];
$stmt = $pdo->query("SELECT `key`, value FROM tp_settings WHERE category = 'email'");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $key = str_replace('email_', '', $row['key']);
    $smtp_settings[$key] = $row['value'];
}

// Default SMTP values
$smtp_settings['smtp_host'] = $smtp_settings['smtp_host'] ?? '';
$smtp_settings['smtp_port'] = $smtp_settings['smtp_port'] ?? '587';
$smtp_settings['smtp_username'] = $smtp_settings['smtp_username'] ?? '';
$smtp_settings['smtp_password'] = $smtp_settings['smtp_password'] ?? '';
$smtp_settings['smtp_encryption'] = $smtp_settings['smtp_encryption'] ?? 'tls';
$smtp_settings['from_email'] = $smtp_settings['from_email'] ?? '';
$smtp_settings['from_name'] = $smtp_settings['from_name'] ?? '';

// Load email templates
$stmt = $pdo->query("SELECT * FROM tp_email_templates ORDER BY `key`");
$templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Prepare template data
$templateData = [
    'title' => 'E-Mail Verwaltung',
    'csrf_token' => $csrf_token,
    'smtp_settings' => $smtp_settings,
    'email_templates' => $templates,
    'user' => $auth->getUser()
];

// Render email template
echo $twig->render('admin/pages/email.twig', $templateData);