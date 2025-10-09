<?php
/**
 * Migration API
 * Database migration functionality
 */

require_once __DIR__ . '/_bootstrap.php';

// Set JSON headers
header('Content-Type: application/json; charset=UTF-8');

// Admin-only endpoint
if (!$auth->isAdmin()) {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'message' => 'Admin-Rechte erforderlich'
    ]);
    exit;
}

// Get request method
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            // List available migrations and their status
            $migrationDir = __DIR__ . '/../migrations';
            $migrations = [];
            
            // Get executed migrations from database
            $executedMigrations = [];
            try {
                $stmt = $db->query("SELECT * FROM tp_migrations ORDER BY executed_at DESC");
                $executedMigrations = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            } catch (Exception $e) {
                // Migration table might not exist yet
            }
            
            // Get all migration files
            $files = glob($migrationDir . '/*.sql');
            
            foreach ($files as $file) {
                $filename = basename($file);
                $migrations[] = [
                    'filename' => $filename,
                    'name' => str_replace(['.sql', '_'], ['', ' '], $filename),
                    'executed' => isset($executedMigrations[$filename]),
                    'executed_at' => $executedMigrations[$filename] ?? null
                ];
            }
            
            // Sort by filename
            usort($migrations, function($a, $b) {
                return strcmp($a['filename'], $b['filename']);
            });
            
            echo json_encode([
                'status' => 'success',
                'data' => $migrations
            ]);
            break;
            
        case 'POST':
            // Run migrations
            $migrationDir = __DIR__ . '/../migrations';
            $executed = [];
            $errors = [];
            
            // Create migrations table if not exists
            $db->exec("
                CREATE TABLE IF NOT EXISTS `tp_migrations` (
                    `filename` varchar(255) NOT NULL,
                    `executed_at` datetime NOT NULL,
                    PRIMARY KEY (`filename`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // Get already executed migrations
            $stmt = $db->query("SELECT filename FROM tp_migrations");
            $executedMigrations = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Get all migration files
            $files = glob($migrationDir . '/*.sql');
            sort($files);
            
            foreach ($files as $file) {
                $filename = basename($file);
                
                // Skip if already executed
                if (in_array($filename, $executedMigrations)) {
                    continue;
                }
                
                // Read migration file
                $sql = file_get_contents($file);
                
                // Split into individual statements
                $statements = array_filter(array_map('trim', explode(';', $sql)));
                
                // Begin transaction
                $db->beginTransaction();
                
                try {
                    foreach ($statements as $statement) {
                        if (!empty($statement)) {
                            $db->exec($statement);
                        }
                    }
                    
                    // Record migration as executed
                    $stmt = $db->prepare("INSERT INTO tp_migrations (filename, executed_at) VALUES (?, NOW())");
                    $stmt->execute([$filename]);
                    
                    $db->commit();
                    $executed[] = $filename;
                    
                } catch (Exception $e) {
                    $db->rollBack();
                    $errors[] = [
                        'file' => $filename,
                        'error' => $e->getMessage()
                    ];
                    error_log("Migration error in $filename: " . $e->getMessage());
                }
            }
            
            // Log activity
            if (count($executed) > 0) {
                $stmt = $db->prepare("
                    INSERT INTO tp_activity_log (user_id, action, entity_type, details, ip_address, created_at)
                    VALUES (?, 'execute', 'migration', ?, ?, NOW())
                ");
                $stmt->execute([
                    $user['id'],
                    json_encode(['executed' => $executed]),
                    $_SERVER['REMOTE_ADDR'] ?? ''
                ]);
            }
            
            echo json_encode([
                'status' => count($errors) === 0 ? 'success' : 'partial',
                'message' => count($executed) . ' Migration(en) ausgeführt',
                'data' => [
                    'executed' => $executed,
                    'errors' => $errors
                ]
            ]);
            break;
            
        case 'DELETE':
            // Reset migration status (development only)
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($input['filename'])) {
                http_response_code(400);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Dateiname erforderlich'
                ]);
                exit;
            }
            
            // Only allow in development
            if (!isset($_ENV['APP_ENV']) || $_ENV['APP_ENV'] !== 'development') {
                http_response_code(403);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Nur in der Entwicklungsumgebung erlaubt'
                ]);
                exit;
            }
            
            $stmt = $db->prepare("DELETE FROM tp_migrations WHERE filename = ?");
            $stmt->execute([$input['filename']]);
            
            echo json_encode([
                'status' => 'success',
                'message' => 'Migrationsstatus zurückgesetzt'
            ]);
            break;
            
        default:
            http_response_code(405);
            echo json_encode([
                'status' => 'error',
                'message' => 'Methode nicht erlaubt'
            ]);
            break;
    }
    
} catch (Exception $e) {
    error_log('Migration API Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Serverfehler: ' . $e->getMessage()
    ]);
}