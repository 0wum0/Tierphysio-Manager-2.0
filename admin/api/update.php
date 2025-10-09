<?php
/**
 * Admin Update Management API
 */

require_once __DIR__ . '/_bootstrap.php';

$action = $_GET['action'] ?? $_POST['action'] ?? 'check';

switch ($action) {
    case 'check':
        $migrations_dir = __DIR__ . '/../../migrations';
        $pending = [];
        $executed = [];
        
        // Get executed migrations
        $stmt = $pdo->query("SELECT migration, executed_at FROM tp_migrations ORDER BY executed_at DESC");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $executed[$row['migration']] = $row['executed_at'];
        }
        
        // Check for pending migrations
        if (is_dir($migrations_dir)) {
            $files = glob($migrations_dir . '/*.sql');
            foreach ($files as $file) {
                $name = basename($file);
                if (!isset($executed[$name])) {
                    $pending[] = [
                        'name' => $name,
                        'path' => $file,
                        'size' => filesize($file)
                    ];
                }
            }
        }
        
        // Get version info
        $version_file = __DIR__ . '/../../includes/version.php';
        $app_version = 'Unknown';
        $db_version = 'Unknown';
        
        if (file_exists($version_file)) {
            include $version_file;
            $app_version = defined('APP_VERSION') ? APP_VERSION : 'Unknown';
            $db_version = defined('DB_VERSION') ? DB_VERSION : 'Unknown';
        }
        
        api_success([
            'app_version' => $app_version,
            'db_version' => $db_version,
            'pending_migrations' => $pending,
            'executed_migrations' => $executed,
            'pending_count' => count($pending)
        ]);
        break;
        
    case 'run_migration':
        csrf_check();
        requirePermission('update.manage');
        
        $data = getJsonInput();
        $migration_name = sanitize($data['migration'] ?? '');
        
        if (!$migration_name) {
            api_error('Migration name erforderlich');
        }
        
        // Security: ensure migration name is safe
        if (!preg_match('/^[a-zA-Z0-9_\-]+\.sql$/', $migration_name)) {
            api_error('Ungültiger Migration-Name');
        }
        
        $migration_path = __DIR__ . '/../../migrations/' . $migration_name;
        
        if (!file_exists($migration_path)) {
            api_error('Migration-Datei nicht gefunden', 404);
        }
        
        // Check if already executed
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tp_migrations WHERE migration = ?");
        $stmt->execute([$migration_name]);
        if ($stmt->fetchColumn() > 0) {
            api_error('Migration wurde bereits ausgeführt');
        }
        
        // Read migration file
        $sql = file_get_contents($migration_path);
        
        try {
            $pdo->beginTransaction();
            
            // Execute migration
            $queries = preg_split('/;(?=(?:[^\'"]|\'[^\']*\'|"[^"]*")*$)/', $sql);
            
            foreach ($queries as $query) {
                $query = trim($query);
                if (!empty($query) && stripos($query, 'INSERT IGNORE INTO tp_migrations') === false) {
                    $pdo->exec($query);
                }
            }
            
            // Record migration
            $stmt = $pdo->prepare("INSERT INTO tp_migrations (migration, executed_at) VALUES (?, NOW())");
            $stmt->execute([$migration_name]);
            
            $pdo->commit();
            api_success(null, 'Migration erfolgreich ausgeführt');
        } catch (Exception $e) {
            $pdo->rollBack();
            api_error('Fehler bei der Migration: ' . $e->getMessage());
        }
        break;
        
    case 'run_all':
        csrf_check();
        requirePermission('update.manage');
        
        $migrations_dir = __DIR__ . '/../../migrations';
        $pending = [];
        
        // Get list of pending migrations
        $stmt = $pdo->query("SELECT migration FROM tp_migrations");
        $executed = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (is_dir($migrations_dir)) {
            $files = glob($migrations_dir . '/*.sql');
            foreach ($files as $file) {
                $name = basename($file);
                if (!in_array($name, $executed)) {
                    $pending[] = $file;
                }
            }
        }
        
        if (empty($pending)) {
            api_success(null, 'Keine ausstehenden Migrationen');
        }
        
        $successful = 0;
        $failed = [];
        
        foreach ($pending as $migration_path) {
            $migration_name = basename($migration_path);
            $sql = file_get_contents($migration_path);
            
            try {
                $pdo->beginTransaction();
                
                // Execute migration
                $queries = preg_split('/;(?=(?:[^\'"]|\'[^\']*\'|"[^"]*")*$)/', $sql);
                
                foreach ($queries as $query) {
                    $query = trim($query);
                    if (!empty($query) && stripos($query, 'INSERT IGNORE INTO tp_migrations') === false) {
                        $pdo->exec($query);
                    }
                }
                
                // Record migration
                $stmt = $pdo->prepare("INSERT INTO tp_migrations (migration, executed_at) VALUES (?, NOW())");
                $stmt->execute([$migration_name]);
                
                $pdo->commit();
                $successful++;
            } catch (Exception $e) {
                $pdo->rollBack();
                $failed[] = [
                    'migration' => $migration_name,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        if (empty($failed)) {
            api_success([
                'successful' => $successful,
                'failed' => 0
            ], "$successful Migration(en) erfolgreich ausgeführt");
        } else {
            api_error("$successful erfolgreich, " . count($failed) . " fehlgeschlagen", 500);
        }
        break;
        
    case 'version_info':
        $version_file = __DIR__ . '/../../includes/version.php';
        
        if (!file_exists($version_file)) {
            // Create default version file
            $content = "<?php\n";
            $content .= "define('APP_VERSION', '2.0.0');\n";
            $content .= "define('DB_VERSION', '2.0.0');\n";
            file_put_contents($version_file, $content);
        }
        
        include $version_file;
        
        api_success([
            'app_version' => defined('APP_VERSION') ? APP_VERSION : 'Unknown',
            'db_version' => defined('DB_VERSION') ? DB_VERSION : 'Unknown',
            'php_version' => PHP_VERSION,
            'mysql_version' => $pdo->query("SELECT VERSION()")->fetchColumn()
        ]);
        break;
        
    default:
        api_error('Unbekannte Aktion', 400);
}