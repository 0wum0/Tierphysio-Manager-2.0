<?php
/**
 * Admin Settings API
 */

require_once __DIR__ . '/_bootstrap.php';

$action = $_GET['action'] ?? $_POST['action'] ?? 'get';

switch ($action) {
    case 'get':
        $category = $_GET['category'] ?? 'general';
        
        $settings = [];
        if ($category === 'all') {
            $stmt = $pdo->query("SELECT `key`, value, category FROM tp_settings");
        } else {
            $stmt = $pdo->prepare("SELECT `key`, value FROM tp_settings WHERE category = ? OR category IS NULL");
            $stmt->execute([$category]);
        }
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['key']] = $row['value'];
        }
        
        api_success($settings);
        break;
        
    case 'update':
        csrf_check();
        requirePermission('settings.update');
        
        $data = getJsonInput();
        
        if (empty($data)) {
            api_error('Keine Daten zum Aktualisieren');
        }
        
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("
                INSERT INTO tp_settings (`key`, value, category, updated_at)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()
            ");
            
            foreach ($data as $key => $value) {
                // Skip CSRF token and other non-setting fields
                if (in_array($key, ['csrf_token', 'action'])) {
                    continue;
                }
                
                // Determine category from key prefix
                $category = 'general';
                if (strpos($key, 'email_') === 0) {
                    $category = 'email';
                } elseif (strpos($key, 'finance_') === 0) {
                    $category = 'finance';
                } elseif (strpos($key, 'theme_') === 0) {
                    $category = 'theme';
                }
                
                $stmt->execute([$key, $value, $category]);
            }
            
            $pdo->commit();
            api_success(null, 'Einstellungen erfolgreich gespeichert');
        } catch (Exception $e) {
            $pdo->rollBack();
            api_error('Fehler beim Speichern: ' . $e->getMessage());
        }
        break;
        
    case 'upload_logo':
        csrf_check();
        requirePermission('settings.update');
        
        if (!isset($_FILES['logo'])) {
            api_error('Keine Datei hochgeladen');
        }
        
        $file = $_FILES['logo'];
        
        // Validate file
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/svg+xml'];
        if (!in_array($file['type'], $allowedTypes)) {
            api_error('Ungültiger Dateityp. Erlaubt sind: JPG, PNG, GIF, SVG');
        }
        
        if ($file['size'] > 2 * 1024 * 1024) { // 2MB
            api_error('Datei zu groß. Maximal 2MB erlaubt');
        }
        
        // Create upload directory if not exists
        $uploadDir = __DIR__ . '/../../public/uploads';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        // Generate unique filename
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'logo_' . uniqid() . '.' . $extension;
        $filepath = $uploadDir . '/' . $filename;
        
        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            // Save path to settings
            $stmt = $pdo->prepare("
                INSERT INTO tp_settings (`key`, value, category, updated_at)
                VALUES ('logo_path', ?, 'general', NOW())
                ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()
            ");
            $stmt->execute(['/public/uploads/' . $filename]);
            
            api_success([
                'path' => '/public/uploads/' . $filename,
                'filename' => $filename
            ], 'Logo erfolgreich hochgeladen');
        } else {
            api_error('Fehler beim Hochladen der Datei');
        }
        break;
        
    case 'delete_logo':
        csrf_check();
        requirePermission('settings.update');
        
        // Get current logo path
        $stmt = $pdo->query("SELECT value FROM tp_settings WHERE `key` = 'logo_path'");
        $logoPath = $stmt->fetchColumn();
        
        if ($logoPath) {
            // Delete file if exists
            $fullPath = __DIR__ . '/../../' . $logoPath;
            if (file_exists($fullPath)) {
                unlink($fullPath);
            }
            
            // Remove from settings
            $stmt = $pdo->exec("DELETE FROM tp_settings WHERE `key` = 'logo_path'");
            
            api_success(null, 'Logo erfolgreich gelöscht');
        } else {
            api_error('Kein Logo vorhanden', 404);
        }
        break;
        
    default:
        api_error('Unbekannte Aktion', 400);
}