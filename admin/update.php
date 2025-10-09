<?php
/**
 * Admin Update Management
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

// Get current version
$version_file = __DIR__ . '/../includes/version.php';
$current_version = 'Unknown';
$db_version = 'Unknown';

if (file_exists($version_file)) {
    include $version_file;
    $current_version = defined('APP_VERSION') ? APP_VERSION : 'Unknown';
    $db_version = defined('DB_VERSION') ? DB_VERSION : 'Unknown';
}

// Check for pending migrations
$migrations_dir = __DIR__ . '/../migrations';
$pending_migrations = [];
$executed_migrations = [];

if (is_dir($migrations_dir)) {
    // Get executed migrations
    $stmt = $pdo->query("SELECT migration, executed_at FROM tp_migrations ORDER BY executed_at DESC");
    $executed = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($executed as $row) {
        $executed_migrations[$row['migration']] = $row['executed_at'];
    }
    
    // Check for pending migrations
    $files = glob($migrations_dir . '/*.sql');
    foreach ($files as $file) {
        $migration_name = basename($file);
        if (!isset($executed_migrations[$migration_name])) {
            $pending_migrations[] = [
                'name' => $migration_name,
                'path' => $file,
                'size' => filesize($file),
                'created' => date('Y-m-d H:i:s', filemtime($file))
            ];
        }
    }
}

// System requirements check
$requirements = [
    'php_version' => [
        'name' => 'PHP Version',
        'required' => '7.4.0',
        'current' => PHP_VERSION,
        'passed' => version_compare(PHP_VERSION, '7.4.0', '>=')
    ],
    'pdo_mysql' => [
        'name' => 'PDO MySQL',
        'required' => 'Aktiviert',
        'current' => extension_loaded('pdo_mysql') ? 'Aktiviert' : 'Deaktiviert',
        'passed' => extension_loaded('pdo_mysql')
    ],
    'json' => [
        'name' => 'JSON',
        'required' => 'Aktiviert',
        'current' => extension_loaded('json') ? 'Aktiviert' : 'Deaktiviert',
        'passed' => extension_loaded('json')
    ],
    'mbstring' => [
        'name' => 'Multibyte String',
        'required' => 'Aktiviert',
        'current' => extension_loaded('mbstring') ? 'Aktiviert' : 'Deaktiviert',
        'passed' => extension_loaded('mbstring')
    ],
    'writable_backups' => [
        'name' => 'Backups Ordner',
        'required' => 'Beschreibbar',
        'current' => is_writable(__DIR__ . '/../backups') ? 'Beschreibbar' : 'Nicht beschreibbar',
        'passed' => is_writable(__DIR__ . '/../backups')
    ]
];

// Prepare template data
$templateData = [
    'title' => 'System Updates',
    'csrf_token' => $csrf_token,
    'current_version' => $current_version,
    'db_version' => $db_version,
    'pending_migrations' => $pending_migrations,
    'executed_migrations' => $executed_migrations,
    'requirements' => $requirements,
    'user' => $auth->getUser()
];

// Render update template
echo $twig->render('admin/pages/update.twig', $templateData);