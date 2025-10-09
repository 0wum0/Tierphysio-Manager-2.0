<?php
/**
 * Admin Backup Management API
 */

require_once __DIR__ . '/_bootstrap.php';

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

switch ($action) {
    case 'list':
        $stmt = $pdo->query("
            SELECT b.*, u.name as created_by_name
            FROM tp_backups b
            LEFT JOIN tp_users u ON b.created_by = u.id
            ORDER BY b.created_at DESC
        ");
        $backups = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Check if files exist
        $backup_dir = __DIR__ . '/../../backups';
        foreach ($backups as &$backup) {
            $backup['file_exists'] = file_exists($backup_dir . '/' . $backup['file_name']);
        }
        
        api_success($backups, null, count($backups));
        break;
        
    case 'create':
        csrf_check();
        requirePermission('backup.manage');
        
        $backup_dir = __DIR__ . '/../../backups';
        if (!is_dir($backup_dir)) {
            mkdir($backup_dir, 0755, true);
        }
        
        $filename = 'backup_' . date('Y-m-d_His') . '.sql';
        $filepath = $backup_dir . '/' . $filename;
        
        // Get database connection details
        $db_host = DB_HOST;
        $db_name = DB_NAME;
        $db_user = DB_USER;
        $db_pass = DB_PASS;
        
        // Try mysqldump first if available
        $can_exec = function_exists('exec') && !in_array('exec', explode(',', ini_get('disable_functions')));
        
        if ($can_exec) {
            $command = sprintf(
                'mysqldump --host=%s --user=%s --password=%s %s > %s 2>&1',
                escapeshellarg($db_host),
                escapeshellarg($db_user),
                escapeshellarg($db_pass),
                escapeshellarg($db_name),
                escapeshellarg($filepath)
            );
            
            exec($command, $output, $return_var);
            
            if ($return_var !== 0) {
                // Fall back to PDO export
                $can_exec = false;
            }
        }
        
        if (!$can_exec) {
            // PDO-based export
            try {
                $dump = "-- Tierphysio Manager Database Backup\n";
                $dump .= "-- Date: " . date('Y-m-d H:i:s') . "\n\n";
                
                // Get all tables
                $stmt = $pdo->query("SHOW TABLES");
                $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                foreach ($tables as $table) {
                    // Skip non-tp tables
                    if (strpos($table, 'tp_') !== 0) {
                        continue;
                    }
                    
                    // Table structure
                    $stmt = $pdo->query("SHOW CREATE TABLE `$table`");
                    $create = $stmt->fetch(PDO::FETCH_ASSOC);
                    $dump .= "\n-- Table structure for `$table`\n";
                    $dump .= "DROP TABLE IF EXISTS `$table`;\n";
                    $dump .= $create['Create Table'] . ";\n\n";
                    
                    // Table data
                    $stmt = $pdo->query("SELECT * FROM `$table`");
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (!empty($rows)) {
                        $dump .= "-- Data for table `$table`\n";
                        foreach ($rows as $row) {
                            $values = array_map(function($value) use ($pdo) {
                                if ($value === null) {
                                    return 'NULL';
                                }
                                return $pdo->quote($value);
                            }, array_values($row));
                            
                            $dump .= "INSERT INTO `$table` VALUES (" . implode(', ', $values) . ");\n";
                        }
                        $dump .= "\n";
                    }
                }
                
                file_put_contents($filepath, $dump);
            } catch (Exception $e) {
                api_error('Fehler beim Erstellen des Backups: ' . $e->getMessage());
            }
        }
        
        // Check if file was created
        if (!file_exists($filepath)) {
            api_error('Backup-Datei konnte nicht erstellt werden');
        }
        
        $filesize = filesize($filepath);
        
        // Save to database
        $stmt = $pdo->prepare("
            INSERT INTO tp_backups (file_name, size_bytes, created_by, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$filename, $filesize, $auth->getUserId()]);
        
        api_success([
            'id' => $pdo->lastInsertId(),
            'filename' => $filename,
            'size' => $filesize
        ], 'Backup erfolgreich erstellt');
        break;
        
    case 'download':
        $id = sanitize($_GET['id'] ?? 0, 'int');
        if (!$id) {
            api_error('Backup ID erforderlich');
        }
        
        $stmt = $pdo->prepare("SELECT * FROM tp_backups WHERE id = ?");
        $stmt->execute([$id]);
        $backup = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$backup) {
            api_error('Backup nicht gefunden', 404);
        }
        
        $filepath = __DIR__ . '/../../backups/' . $backup['file_name'];
        
        if (!file_exists($filepath)) {
            api_error('Backup-Datei nicht gefunden', 404);
        }
        
        // Send file
        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="' . $backup['file_name'] . '"');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit;
        
    case 'delete':
        csrf_check();
        requirePermission('backup.manage');
        
        $data = getJsonInput();
        $id = sanitize($data['id'] ?? 0, 'int');
        
        if (!$id) {
            api_error('Backup ID erforderlich');
        }
        
        $stmt = $pdo->prepare("SELECT * FROM tp_backups WHERE id = ?");
        $stmt->execute([$id]);
        $backup = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$backup) {
            api_error('Backup nicht gefunden', 404);
        }
        
        // Delete file
        $filepath = __DIR__ . '/../../backups/' . $backup['file_name'];
        if (file_exists($filepath)) {
            unlink($filepath);
        }
        
        // Delete from database
        $stmt = $pdo->prepare("DELETE FROM tp_backups WHERE id = ?");
        $stmt->execute([$id]);
        
        api_success(null, 'Backup erfolgreich gelöscht');
        break;
        
    case 'restore':
        csrf_check();
        requirePermission('backup.manage');
        
        $data = getJsonInput();
        $id = sanitize($data['id'] ?? 0, 'int');
        $confirm = sanitize($data['confirm'] ?? false, 'bool');
        
        if (!$id) {
            api_error('Backup ID erforderlich');
        }
        
        if (!$confirm) {
            api_error('Wiederherstellung muss bestätigt werden');
        }
        
        $stmt = $pdo->prepare("SELECT * FROM tp_backups WHERE id = ?");
        $stmt->execute([$id]);
        $backup = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$backup) {
            api_error('Backup nicht gefunden', 404);
        }
        
        $filepath = __DIR__ . '/../../backups/' . $backup['file_name'];
        
        if (!file_exists($filepath)) {
            api_error('Backup-Datei nicht gefunden', 404);
        }
        
        // Read SQL file
        $sql = file_get_contents($filepath);
        
        try {
            // Split by semicolon but ignore those in strings
            $queries = preg_split('/;(?=(?:[^\'"]|\'[^\']*\'|"[^"]*")*$)/', $sql);
            
            $pdo->beginTransaction();
            
            foreach ($queries as $query) {
                $query = trim($query);
                if (!empty($query)) {
                    $pdo->exec($query);
                }
            }
            
            $pdo->commit();
            api_success(null, 'Backup erfolgreich wiederhergestellt');
        } catch (Exception $e) {
            $pdo->rollBack();
            api_error('Fehler bei der Wiederherstellung: ' . $e->getMessage());
        }
        break;
        
    default:
        api_error('Unbekannte Aktion', 400);
}