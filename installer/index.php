<?php
/**
 * Tierphysio Manager 2.0 - Installer
 * Modern installation wizard with step-by-step guidance
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if already installed
if (file_exists(__DIR__ . '/install.lock')) {
    header('Location: ../public/index.php');
    exit;
}

require_once __DIR__ . '/../includes/version.php';

// Initialize installation steps
$currentStep = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$totalSteps = 4;

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response = processInstallStep($currentStep);
    if ($response['success']) {
        header('Location: ?step=' . ($currentStep + 1));
        exit;
    } else {
        $error = $response['message'];
    }
}

function processInstallStep($step) {
    switch($step) {
        case 2:
            return testDatabaseConnection();
        case 3:
            return createDatabase();
        case 4:
            return createAdminAccount();
        default:
            return ['success' => false, 'message' => 'Ungültiger Schritt'];
    }
}

function testDatabaseConnection() {
    try {
        $dsn = "mysql:host=" . $_POST['db_host'] . ";charset=utf8mb4";
        $pdo = new PDO($dsn, $_POST['db_user'], $_POST['db_pass']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Save connection details to session
        $_SESSION['db_host'] = $_POST['db_host'];
        $_SESSION['db_name'] = $_POST['db_name'];
        $_SESSION['db_user'] = $_POST['db_user'];
        $_SESSION['db_pass'] = $_POST['db_pass'];
        
        return ['success' => true];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Datenbankverbindung fehlgeschlagen: ' . $e->getMessage()];
    }
}

function createDatabase() {
    try {
        $dsn = "mysql:host=" . $_SESSION['db_host'] . ";charset=utf8mb4";
        $pdo = new PDO($dsn, $_SESSION['db_user'], $_SESSION['db_pass']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Create database if not exists
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . $_SESSION['db_name'] . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `" . $_SESSION['db_name'] . "`");
        
        // Execute initial schema
        $sql = file_get_contents(__DIR__ . '/../migrations/001_initial_schema.sql');
        $pdo->exec($sql);
        
        // Execute additional migrations
        $migrations = [
            '/../migrations/002_create_tp_tables.sql',
            '/../migrations/003_default_settings.sql',
            '/../migrations/2025_10_09_admin_panel.sql'
        ];
        
        foreach ($migrations as $migrationFile) {
            $migrationPath = __DIR__ . $migrationFile;
            if (file_exists($migrationPath)) {
                $sql = file_get_contents($migrationPath);
                // Split by semicolon but ignore those in strings
                $queries = preg_split('/;(?=(?:[^\'"]|\'[^\']*\'|"[^"]*")*$)/', $sql);
                
                foreach ($queries as $query) {
                    $query = trim($query);
                    if (!empty($query)) {
                        try {
                            $pdo->exec($query);
                        } catch (PDOException $e) {
                            // Ignore duplicate key errors for INSERT IGNORE statements
                            if (strpos($e->getMessage(), 'Duplicate') === false) {
                                throw $e;
                            }
                        }
                    }
                }
            }
        }
        
        return ['success' => true];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Datenbank-Setup fehlgeschlagen: ' . $e->getMessage()];
    }
}

function createAdminAccount() {
    try {
        $dsn = "mysql:host=" . $_SESSION['db_host'] . ";dbname=" . $_SESSION['db_name'] . ";charset=utf8mb4";
        $pdo = new PDO($dsn, $_SESSION['db_user'], $_SESSION['db_pass']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Create admin user
        $stmt = $pdo->prepare("INSERT INTO tp_users (username, email, password, first_name, last_name, role, name) VALUES (?, ?, ?, ?, ?, 'admin', ?)");
        $stmt->execute([
            $_POST['admin_username'],
            $_POST['admin_email'],
            password_hash($_POST['admin_password'], PASSWORD_BCRYPT),
            $_POST['admin_firstname'],
            $_POST['admin_lastname'],
            $_POST['admin_firstname'] . ' ' . $_POST['admin_lastname']
        ]);
        $adminUserId = $pdo->lastInsertId();
        
        // Assign admin role to the created user
        $stmt = $pdo->prepare("INSERT INTO tp_user_roles (user_id, role_id) SELECT ?, id FROM tp_roles WHERE name = 'admin'");
        $stmt->execute([$adminUserId]);
        
        // Create config file
        $config = "<?php\n";
        $config .= "// Tierphysio Manager 2.0 Configuration\n";
        $config .= "// Generated: " . date('Y-m-d H:i:s') . "\n\n";
        $config .= "define('DB_HOST', '" . $_SESSION['db_host'] . "');\n";
        $config .= "define('DB_NAME', '" . $_SESSION['db_name'] . "');\n";
        $config .= "define('DB_USER', '" . $_SESSION['db_user'] . "');\n";
        $config .= "define('DB_PASS', '" . $_SESSION['db_pass'] . "');\n";
        $config .= "define('DB_CHARSET', 'utf8mb4');\n";
        $config .= "define('DB_PREFIX', 'tp_');\n\n";
        
        // Add other configuration constants
        $config .= "define('APP_URL', '" . $_POST['app_url'] . "');\n";
        $config .= "define('APP_PATH', __DIR__ . '/../');\n";
        $config .= "define('APP_DEBUG', false);\n";
        $config .= "define('APP_TIMEZONE', 'Europe/Berlin');\n";
        $config .= "define('APP_LOCALE', 'de_DE');\n";
        $config .= "define('APP_CURRENCY', 'EUR');\n\n";
        
        $config .= "define('JWT_SECRET', '" . bin2hex(random_bytes(32)) . "');\n";
        $config .= "define('CSRF_TOKEN_NAME', '_csrf_token');\n";
        $config .= "define('SESSION_NAME', 'tierphysio_session');\n";
        $config .= "define('SESSION_LIFETIME', 3600);\n\n";
        
        $config .= "define('UPLOAD_PATH', APP_PATH . 'public/uploads/');\n";
        $config .= "define('UPLOAD_MAX_SIZE', 10485760);\n";
        $config .= "define('BACKUP_PATH', APP_PATH . 'backups/');\n";
        $config .= "define('CACHE_PATH', APP_PATH . 'cache/');\n";
        $config .= "define('LOG_PATH', APP_PATH . 'logs/');\n";
        
        file_put_contents(__DIR__ . '/../includes/config.php', $config);
        
        // Create install lock
        file_put_contents(__DIR__ . '/install.lock', date('Y-m-d H:i:s'));
        
        // Clear session
        session_destroy();
        
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Admin-Account konnte nicht erstellt werden: ' . $e->getMessage()];
    }
}

function checkRequirements() {
    $requirements = [];
    
    // PHP Version
    $requirements['php'] = [
        'name' => 'PHP Version',
        'required' => MIN_PHP_VERSION,
        'current' => PHP_VERSION,
        'passed' => version_compare(PHP_VERSION, MIN_PHP_VERSION, '>=')
    ];
    
    // PHP Extensions
    foreach (REQUIREMENTS['extensions'] as $ext => $name) {
        $requirements[$ext] = [
            'name' => $name,
            'required' => 'Aktiviert',
            'current' => extension_loaded($ext) ? 'Aktiviert' : 'Fehlt',
            'passed' => extension_loaded($ext)
        ];
    }
    
    // Writable directories
    foreach (REQUIREMENTS['writable_dirs'] as $dir) {
        $path = __DIR__ . '/../' . $dir;
        $requirements['dir_' . $dir] = [
            'name' => 'Ordner ' . $dir,
            'required' => 'Schreibbar',
            'current' => is_writable($path) ? 'Schreibbar' : 'Nicht schreibbar',
            'passed' => is_writable($path)
        ];
    }
    
    return $requirements;
}
?>
<!DOCTYPE html>
<html lang="de" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tierphysio Manager 2.0 - Installation</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/animejs/3.2.1/anime.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        h1, h2, h3, h4, h5, h6 { font-family: 'Poppins', sans-serif; }
        .glass {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        .gradient-bg {
            background: linear-gradient(135deg, #9b5de5 0%, #7C4DFF 50%, #6c63ff 100%);
        }
        .text-gradient {
            background: linear-gradient(135deg, #9b5de5, #7C4DFF, #6c63ff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .loader {
            border-top-color: #9b5de5;
            animation: spinner 1.5s linear infinite;
        }
        @keyframes spinner {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .paw-loader {
            animation: paw-pulse 2s ease-in-out infinite;
        }
        @keyframes paw-pulse {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.1); opacity: 0.8; }
        }
    </style>
</head>
<body class="h-full gradient-bg">
    <div class="min-h-full flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8" x-data="{ darkMode: false }">
        <div class="max-w-4xl w-full space-y-8">
            <!-- Header -->
            <div class="text-center">
                <div class="flex justify-center mb-4">
                    <div class="w-20 h-20 bg-white/20 rounded-full flex items-center justify-center glass paw-loader">
                        <svg class="w-12 h-12 text-white" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                        </svg>
                    </div>
                </div>
                <h1 class="text-4xl font-bold text-white">Tierphysio Manager 2.0</h1>
                <p class="mt-2 text-white/80">Willkommen zur Installation</p>
            </div>

            <!-- Progress Bar -->
            <div class="w-full bg-white/20 rounded-full h-2.5 glass">
                <div class="h-2.5 rounded-full bg-white transition-all duration-500" 
                     style="width: <?php echo ($currentStep / $totalSteps) * 100; ?>%"></div>
            </div>

            <!-- Installation Card -->
            <div class="bg-white/95 backdrop-blur-md rounded-2xl shadow-2xl p-8">
                <!-- Step Navigation -->
                <div class="flex justify-between mb-8">
                    <?php for($i = 1; $i <= $totalSteps; $i++): ?>
                        <div class="flex items-center">
                            <div class="<?php echo $i <= $currentStep ? 'bg-purple-600 text-white' : 'bg-gray-200 text-gray-600'; ?> 
                                        rounded-full h-10 w-10 flex items-center justify-center font-semibold">
                                <?php echo $i; ?>
                            </div>
                            <?php if($i < $totalSteps): ?>
                                <div class="w-full h-1 <?php echo $i < $currentStep ? 'bg-purple-600' : 'bg-gray-200'; ?> mx-2"></div>
                            <?php endif; ?>
                        </div>
                    <?php endfor; ?>
                </div>

                <!-- Error Message -->
                <?php if(isset($error)): ?>
                    <div class="mb-6 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <!-- Step Content -->
                <?php if($currentStep == 1): ?>
                    <!-- Step 1: System Requirements -->
                    <div>
                        <h2 class="text-2xl font-bold text-gray-800 mb-6">Systemanforderungen</h2>
                        
                        <?php $requirements = checkRequirements(); ?>
                        <div class="space-y-3">
                            <?php foreach($requirements as $req): ?>
                                <div class="flex items-center justify-between p-3 rounded-lg 
                                            <?php echo $req['passed'] ? 'bg-green-50' : 'bg-red-50'; ?>">
                                    <div class="flex items-center">
                                        <?php if($req['passed']): ?>
                                            <svg class="w-5 h-5 text-green-500 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                            </svg>
                                        <?php else: ?>
                                            <svg class="w-5 h-5 text-red-500 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                            </svg>
                                        <?php endif; ?>
                                        <span class="font-medium"><?php echo $req['name']; ?></span>
                                    </div>
                                    <div class="text-sm">
                                        <span class="text-gray-600">Benötigt: <?php echo $req['required']; ?></span>
                                        <span class="mx-2">|</span>
                                        <span class="<?php echo $req['passed'] ? 'text-green-600' : 'text-red-600'; ?>">
                                            Aktuell: <?php echo $req['current']; ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php 
                        $allPassed = true;
                        foreach($requirements as $req) {
                            if(!$req['passed']) {
                                $allPassed = false;
                                break;
                            }
                        }
                        ?>

                        <?php if($allPassed): ?>
                            <div class="mt-8 flex justify-end">
                                <a href="?step=2" class="inline-flex items-center px-6 py-3 border border-transparent 
                                          text-base font-medium rounded-lg text-white bg-purple-600 hover:bg-purple-700 
                                          focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500 
                                          transition-colors duration-200">
                                    Weiter
                                    <svg class="ml-2 -mr-1 w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                    </svg>
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="mt-8 p-4 bg-red-50 border border-red-200 rounded-lg">
                                <p class="text-red-700">
                                    <strong>Installation nicht möglich!</strong><br>
                                    Bitte stellen Sie sicher, dass alle Systemanforderungen erfüllt sind.
                                </p>
                            </div>
                        <?php endif; ?>
                    </div>

                <?php elseif($currentStep == 2): ?>
                    <!-- Step 2: Database Configuration -->
                    <div>
                        <h2 class="text-2xl font-bold text-gray-800 mb-6">Datenbank-Konfiguration</h2>
                        
                        <form method="POST" class="space-y-6">
                            <div>
                                <label for="db_host" class="block text-sm font-medium text-gray-700 mb-2">
                                    Datenbank-Server
                                </label>
                                <input type="text" id="db_host" name="db_host" value="localhost" required
                                       class="appearance-none relative block w-full px-3 py-2 border border-gray-300 
                                              placeholder-gray-500 text-gray-900 rounded-lg focus:outline-none 
                                              focus:ring-purple-500 focus:border-purple-500 focus:z-10 sm:text-sm">
                                <p class="mt-1 text-xs text-gray-500">Normalerweise "localhost"</p>
                            </div>

                            <div>
                                <label for="db_name" class="block text-sm font-medium text-gray-700 mb-2">
                                    Datenbank-Name
                                </label>
                                <input type="text" id="db_name" name="db_name" required placeholder="tierphysio_db"
                                       class="appearance-none relative block w-full px-3 py-2 border border-gray-300 
                                              placeholder-gray-500 text-gray-900 rounded-lg focus:outline-none 
                                              focus:ring-purple-500 focus:border-purple-500 focus:z-10 sm:text-sm">
                            </div>

                            <div>
                                <label for="db_user" class="block text-sm font-medium text-gray-700 mb-2">
                                    Datenbank-Benutzer
                                </label>
                                <input type="text" id="db_user" name="db_user" required placeholder="username"
                                       class="appearance-none relative block w-full px-3 py-2 border border-gray-300 
                                              placeholder-gray-500 text-gray-900 rounded-lg focus:outline-none 
                                              focus:ring-purple-500 focus:border-purple-500 focus:z-10 sm:text-sm">
                            </div>

                            <div>
                                <label for="db_pass" class="block text-sm font-medium text-gray-700 mb-2">
                                    Datenbank-Passwort
                                </label>
                                <input type="password" id="db_pass" name="db_pass" placeholder="••••••••"
                                       class="appearance-none relative block w-full px-3 py-2 border border-gray-300 
                                              placeholder-gray-500 text-gray-900 rounded-lg focus:outline-none 
                                              focus:ring-purple-500 focus:border-purple-500 focus:z-10 sm:text-sm">
                            </div>

                            <div class="flex justify-between">
                                <a href="?step=1" class="inline-flex items-center px-6 py-3 border border-gray-300 
                                          text-base font-medium rounded-lg text-gray-700 bg-white hover:bg-gray-50 
                                          focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500 
                                          transition-colors duration-200">
                                    <svg class="mr-2 -ml-1 w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                                    </svg>
                                    Zurück
                                </a>
                                <button type="submit" class="inline-flex items-center px-6 py-3 border border-transparent 
                                               text-base font-medium rounded-lg text-white bg-purple-600 hover:bg-purple-700 
                                               focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500 
                                               transition-colors duration-200">
                                    Verbindung testen
                                    <svg class="ml-2 -mr-1 w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                    </svg>
                                </button>
                            </div>
                        </form>
                    </div>

                <?php elseif($currentStep == 3): ?>
                    <!-- Step 3: Create Database -->
                    <div>
                        <h2 class="text-2xl font-bold text-gray-800 mb-6">Datenbank erstellen</h2>
                        
                        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                            <p class="text-blue-700">
                                Die Datenbank wird nun mit allen benötigten Tabellen erstellt.
                                Dies kann einen Moment dauern...
                            </p>
                        </div>

                        <form method="POST" class="space-y-6">
                            <div class="text-center py-8">
                                <div class="inline-flex items-center justify-center w-16 h-16 bg-purple-100 rounded-full mb-4">
                                    <svg class="w-8 h-8 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                                              d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"></path>
                                    </svg>
                                </div>
                                <h3 class="text-lg font-medium text-gray-900 mb-2">Bereit zur Installation</h3>
                                <p class="text-gray-600">Klicken Sie auf "Datenbank erstellen" um fortzufahren.</p>
                            </div>

                            <div class="flex justify-between">
                                <a href="?step=2" class="inline-flex items-center px-6 py-3 border border-gray-300 
                                          text-base font-medium rounded-lg text-gray-700 bg-white hover:bg-gray-50 
                                          focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500 
                                          transition-colors duration-200">
                                    <svg class="mr-2 -ml-1 w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                                    </svg>
                                    Zurück
                                </a>
                                <button type="submit" class="inline-flex items-center px-6 py-3 border border-transparent 
                                               text-base font-medium rounded-lg text-white bg-purple-600 hover:bg-purple-700 
                                               focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500 
                                               transition-colors duration-200">
                                    Datenbank erstellen
                                    <svg class="ml-2 -mr-1 w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                    </svg>
                                </button>
                            </div>
                        </form>
                    </div>

                <?php elseif($currentStep == 4): ?>
                    <!-- Step 4: Admin Account -->
                    <div>
                        <h2 class="text-2xl font-bold text-gray-800 mb-6">Administrator-Account</h2>
                        
                        <form method="POST" class="space-y-6">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="admin_firstname" class="block text-sm font-medium text-gray-700 mb-2">
                                        Vorname
                                    </label>
                                    <input type="text" id="admin_firstname" name="admin_firstname" required
                                           class="appearance-none relative block w-full px-3 py-2 border border-gray-300 
                                                  placeholder-gray-500 text-gray-900 rounded-lg focus:outline-none 
                                                  focus:ring-purple-500 focus:border-purple-500 focus:z-10 sm:text-sm">
                                </div>

                                <div>
                                    <label for="admin_lastname" class="block text-sm font-medium text-gray-700 mb-2">
                                        Nachname
                                    </label>
                                    <input type="text" id="admin_lastname" name="admin_lastname" required
                                           class="appearance-none relative block w-full px-3 py-2 border border-gray-300 
                                                  placeholder-gray-500 text-gray-900 rounded-lg focus:outline-none 
                                                  focus:ring-purple-500 focus:border-purple-500 focus:z-10 sm:text-sm">
                                </div>
                            </div>

                            <div>
                                <label for="admin_username" class="block text-sm font-medium text-gray-700 mb-2">
                                    Benutzername
                                </label>
                                <input type="text" id="admin_username" name="admin_username" required
                                       class="appearance-none relative block w-full px-3 py-2 border border-gray-300 
                                              placeholder-gray-500 text-gray-900 rounded-lg focus:outline-none 
                                              focus:ring-purple-500 focus:border-purple-500 focus:z-10 sm:text-sm">
                            </div>

                            <div>
                                <label for="admin_email" class="block text-sm font-medium text-gray-700 mb-2">
                                    E-Mail-Adresse
                                </label>
                                <input type="email" id="admin_email" name="admin_email" required
                                       class="appearance-none relative block w-full px-3 py-2 border border-gray-300 
                                              placeholder-gray-500 text-gray-900 rounded-lg focus:outline-none 
                                              focus:ring-purple-500 focus:border-purple-500 focus:z-10 sm:text-sm">
                            </div>

                            <div>
                                <label for="admin_password" class="block text-sm font-medium text-gray-700 mb-2">
                                    Passwort
                                </label>
                                <input type="password" id="admin_password" name="admin_password" required minlength="8"
                                       class="appearance-none relative block w-full px-3 py-2 border border-gray-300 
                                              placeholder-gray-500 text-gray-900 rounded-lg focus:outline-none 
                                              focus:ring-purple-500 focus:border-purple-500 focus:z-10 sm:text-sm">
                                <p class="mt-1 text-xs text-gray-500">Mindestens 8 Zeichen</p>
                            </div>

                            <div>
                                <label for="app_url" class="block text-sm font-medium text-gray-700 mb-2">
                                    Application URL
                                </label>
                                <input type="url" id="app_url" name="app_url" required 
                                       value="<?php echo 'http' . (isset($_SERVER['HTTPS']) ? 's' : '') . '://' . $_SERVER['HTTP_HOST']; ?>"
                                       class="appearance-none relative block w-full px-3 py-2 border border-gray-300 
                                              placeholder-gray-500 text-gray-900 rounded-lg focus:outline-none 
                                              focus:ring-purple-500 focus:border-purple-500 focus:z-10 sm:text-sm">
                                <p class="mt-1 text-xs text-gray-500">Die URL unter der die Anwendung erreichbar ist</p>
                            </div>

                            <div class="flex justify-between">
                                <a href="?step=3" class="inline-flex items-center px-6 py-3 border border-gray-300 
                                          text-base font-medium rounded-lg text-gray-700 bg-white hover:bg-gray-50 
                                          focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500 
                                          transition-colors duration-200">
                                    <svg class="mr-2 -ml-1 w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                                    </svg>
                                    Zurück
                                </a>
                                <button type="submit" class="inline-flex items-center px-6 py-3 border border-transparent 
                                               text-base font-medium rounded-lg text-white bg-purple-600 hover:bg-purple-700 
                                               focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500 
                                               transition-colors duration-200">
                                    Installation abschließen
                                    <svg class="ml-2 -mr-1 w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                    </svg>
                                </button>
                            </div>
                        </form>
                    </div>

                <?php else: ?>
                    <!-- Installation Complete -->
                    <div class="text-center py-8">
                        <div class="inline-flex items-center justify-center w-20 h-20 bg-green-100 rounded-full mb-4">
                            <svg class="w-10 h-10 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                        </div>
                        <h2 class="text-2xl font-bold text-gray-800 mb-4">Installation erfolgreich!</h2>
                        <p class="text-gray-600 mb-8">
                            Tierphysio Manager 2.0 wurde erfolgreich installiert.<br>
                            Sie können sich nun mit Ihrem Administrator-Account anmelden.
                        </p>
                        <div class="flex justify-center space-x-4">
                            <a href="../public/index.php" class="inline-flex items-center px-8 py-3 border border-transparent 
                                      text-base font-medium rounded-lg text-white bg-purple-600 hover:bg-purple-700 
                                      focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500 
                                      transition-colors duration-200">
                                Zur Anwendung
                                <svg class="ml-2 -mr-1 w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                            </a>
                            <a href="../admin/login.php" class="inline-flex items-center px-8 py-3 border border-purple-600 
                                      text-base font-medium rounded-lg text-purple-600 bg-white hover:bg-purple-50 
                                      focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500 
                                      transition-colors duration-200">
                                Zum Admin-Panel
                                <svg class="ml-2 -mr-1 w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                </svg>
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Footer -->
            <div class="text-center text-white/80 text-sm">
                <p>&copy; <?php echo date('Y'); ?> Tierphysio Manager 2.0 - Version <?php echo APP_VERSION; ?></p>
            </div>
        </div>
    </div>

    <script>
        // Animate elements on load
        anime({
            targets: '.glass',
            translateY: [-20, 0],
            opacity: [0, 1],
            duration: 1000,
            easing: 'easeOutExpo',
            delay: anime.stagger(100)
        });
    </script>
</body>
</html>