<?php
/**
 * Backup API
 * Database backup functionality
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
            // List available backups
            $backupDir = __DIR__ . '/../backups';
            
            if (!is_dir($backupDir)) {
                mkdir($backupDir, 0755, true);
            }
            
            $backups = [];
            $files = glob($backupDir . '/backup_*.sql');
            
            foreach ($files as $file) {
                $backups[] = [
                    'filename' => basename($file),
                    'size' => filesize($file),
                    'created' => date('Y-m-d H:i:s', filemtime($file))
                ];
            }
            
            // Sort by date descending
            usort($backups, function($a, $b) {
                return strtotime($b['created']) - strtotime($a['created']);
            });
            
            echo json_encode([
                'status' => 'success',
                'data' => $backups
            ]);
            break;
            
        case 'POST':
            // Create new backup
            $backupDir = __DIR__ . '/../backups';
            
            if (!is_dir($backupDir)) {
                mkdir($backupDir, 0755, true);
            }
            
            $timestamp = date('Y-m-d_H-i-s');
            $filename = "backup_{$timestamp}.sql";
            $filepath = $backupDir . '/' . $filename;
            
            // Get database configuration
            $config = include __DIR__ . '/../includes/new.config.php';
            $dbConfig = $config['database'];
            
            // Create backup content
            $backup = "-- TierPhysio Manager 2.0 Database Backup\n";
            $backup .= "-- Created: " . date('Y-m-d H:i:s') . "\n";
            $backup .= "-- User: {$user['username']}\n\n";
            
            $backup .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
            $backup .= "SET time_zone = \"+00:00\";\n\n";
            
            // Get all tables
            $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($tables as $table) {
                // Table structure
                $backup .= "\n-- --------------------------------------------------------\n";
                $backup .= "-- Table structure for table `$table`\n";
                $backup .= "-- --------------------------------------------------------\n\n";
                
                $createTable = $db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
                $backup .= "DROP TABLE IF EXISTS `$table`;\n";
                $backup .= $createTable['Create Table'] . ";\n\n";
                
                // Table data
                $stmt = $db->query("SELECT * FROM `$table`");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (count($rows) > 0) {
                    $backup .= "-- --------------------------------------------------------\n";
                    $backup .= "-- Data for table `$table`\n";
                    $backup .= "-- --------------------------------------------------------\n\n";
                    
                    foreach ($rows as $row) {
                        $columns = array_keys($row);
                        $values = array_map(function($value) use ($db) {
                            if ($value === null) {
                                return 'NULL';
                            }
                            return $db->quote($value);
                        }, array_values($row));
                        
                        $backup .= "INSERT INTO `$table` (`" . implode('`, `', $columns) . "`) VALUES (";
                        $backup .= implode(', ', $values) . ");\n";
                    }
                    $backup .= "\n";
                }
            }
            
            // Save backup file
            file_put_contents($filepath, $backup);
            
            // Update last backup setting
            $stmt = $db->prepare("
                INSERT INTO tp_settings (category, `key`, value, type, description, updated_by, created_at)
                VALUES ('system', 'last_backup', ?, 'string', 'Letztes Backup', ?, NOW())
                ON DUPLICATE KEY UPDATE value = ?, updated_by = ?, updated_at = NOW()
            ");
            $stmt->execute([$timestamp, $user['id'], $timestamp, $user['id']]);
            
            // Log activity
            $stmt = $db->prepare("
                INSERT INTO tp_activity_log (user_id, action, entity_type, details, ip_address, created_at)
                VALUES (?, 'create', 'backup', ?, ?, NOW())
            ");
            $stmt->execute([$user['id'], $filename, $_SERVER['REMOTE_ADDR'] ?? '']);
            
            echo json_encode([
                'status' => 'success',
                'message' => 'Backup erfolgreich erstellt',
                'data' => [
                    'filename' => $filename,
                    'size' => filesize($filepath),
                    'created' => date('Y-m-d H:i:s')
                ]
            ]);
            break;
            
        case 'DELETE':
            // Delete backup file
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($input['filename'])) {
                http_response_code(400);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Dateiname erforderlich'
                ]);
                exit;
            }
            
            $filename = basename($input['filename']);
            $filepath = __DIR__ . '/../backups/' . $filename;
            
            if (!file_exists($filepath)) {
                http_response_code(404);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Backup-Datei nicht gefunden'
                ]);
                exit;
            }
            
            // Delete file
            unlink($filepath);
            
            // Log activity
            $stmt = $db->prepare("
                INSERT INTO tp_activity_log (user_id, action, entity_type, details, ip_address, created_at)
                VALUES (?, 'delete', 'backup', ?, ?, NOW())
            ");
            $stmt->execute([$user['id'], $filename, $_SERVER['REMOTE_ADDR'] ?? '']);
            
            echo json_encode([
                'status' => 'success',
                'message' => 'Backup gelöscht'
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
    error_log('Backup API Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Serverfehler: ' . $e->getMessage()
    ]);
}