<?php
/**
 * Admin Modules Management
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

// Get installed modules
$stmt = $pdo->query("SELECT * FROM tp_modules ORDER BY enabled DESC, name ASC");
$modules = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Parse config JSON
foreach ($modules as &$module) {
    if ($module['config']) {
        $module['config'] = json_decode($module['config'], true) ?: [];
    } else {
        $module['config'] = [];
    }
}

// Available modules (hardcoded for now - would be dynamic in production)
$available_modules = [
    [
        'key' => 'appointment_reminders',
        'name' => 'Terminerinnerungen',
        'description' => 'Automatische E-Mail und SMS Erinnerungen für Termine',
        'version' => '1.0.0',
        'installed' => false
    ],
    [
        'key' => 'online_booking',
        'name' => 'Online Terminbuchung',
        'description' => 'Ermöglicht Patienten online Termine zu buchen',
        'version' => '1.0.0',
        'installed' => false
    ],
    [
        'key' => 'patient_portal',
        'name' => 'Patientenportal',
        'description' => 'Selbstverwaltung für Patienten mit Dokumentenzugriff',
        'version' => '1.0.0',
        'installed' => false
    ],
    [
        'key' => 'analytics',
        'name' => 'Erweiterte Statistiken',
        'description' => 'Detaillierte Auswertungen und Berichte',
        'version' => '1.0.0',
        'installed' => false
    ],
    [
        'key' => 'inventory',
        'name' => 'Lagerverwaltung',
        'description' => 'Verwaltung von Produkten und Materialien',
        'version' => '1.0.0',
        'installed' => false
    ]
];

// Mark installed modules
foreach ($available_modules as &$available) {
    foreach ($modules as $installed) {
        if ($installed['key'] === $available['key']) {
            $available['installed'] = true;
            $available['enabled'] = $installed['enabled'];
            $available['installed_version'] = $installed['version'];
            break;
        }
    }
}

// Prepare template data
$templateData = [
    'title' => 'Modulverwaltung',
    'csrf_token' => $csrf_token,
    'modules' => $modules,
    'available_modules' => $available_modules,
    'user' => $auth->getUser()
];

// Render modules template
echo $twig->render('admin/pages/modules.twig', $templateData);