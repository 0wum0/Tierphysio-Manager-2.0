<?php
/**
 * Settings API
 * CRUD operations for application settings
 */

require_once __DIR__ . '/_bootstrap.php';

// Set JSON headers
header('Content-Type: application/json; charset=UTF-8');

// Get request method
$method = $_SERVER['REQUEST_METHOD'];

try {
    // Admin-only endpoint
    if (!$auth->isAdmin()) {
        http_response_code(403);
        echo json_encode([
            'status' => 'error',
            'message' => 'Admin-Rechte erforderlich'
        ]);
        exit;
    }
    
    switch ($method) {
        case 'GET':
            // Get settings by category or all
            $category = $_GET['category'] ?? null;
            
            if ($category) {
                $stmt = $db->prepare("SELECT * FROM tp_settings WHERE category = ? ORDER BY `key`");
                $stmt->execute([$category]);
            } else {
                $stmt = $db->query("SELECT * FROM tp_settings ORDER BY category, `key`");
            }
            
            $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Transform settings to key-value pairs if requested
            if (isset($_GET['format']) && $_GET['format'] === 'object') {
                $result = [];
                foreach ($settings as $setting) {
                    $key = $setting['category'] . '.' . $setting['key'];
                    $result[$key] = [
                        'value' => $setting['value'],
                        'type' => $setting['type'],
                        'description' => $setting['description'],
                        'is_system' => (bool) $setting['is_system']
                    ];
                }
                $settings = $result;
            }
            
            echo json_encode([
                'status' => 'success',
                'data' => $settings
            ]);
            break;
            
        case 'POST':
        case 'PUT':
            // Update settings
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input || !isset($input['settings'])) {
                http_response_code(400);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Keine Einstellungen zum Speichern'
                ]);
                exit;
            }
            
            // Begin transaction
            $db->beginTransaction();
            
            try {
                $updatedCount = 0;
                
                foreach ($input['settings'] as $setting) {
                    if (!isset($setting['category'], $setting['key'], $setting['value'])) {
                        continue;
                    }
                    
                    // Check if setting exists
                    $stmt = $db->prepare("SELECT id, is_system FROM tp_settings WHERE category = ? AND `key` = ?");
                    $stmt->execute([$setting['category'], $setting['key']]);
                    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($existing) {
                        // Don't update system settings
                        if ($existing['is_system']) {
                            continue;
                        }
                        
                        // Update existing setting
                        $stmt = $db->prepare("
                            UPDATE tp_settings 
                            SET value = ?, 
                                type = ?,
                                description = ?,
                                updated_by = ?,
                                updated_at = NOW()
                            WHERE id = ?
                        ");
                        $stmt->execute([
                            $setting['value'],
                            $setting['type'] ?? 'string',
                            $setting['description'] ?? null,
                            $user['id'],
                            $existing['id']
                        ]);
                    } else {
                        // Insert new setting
                        $stmt = $db->prepare("
                            INSERT INTO tp_settings (category, `key`, value, type, description, updated_by, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, NOW())
                        ");
                        $stmt->execute([
                            $setting['category'],
                            $setting['key'],
                            $setting['value'],
                            $setting['type'] ?? 'string',
                            $setting['description'] ?? null,
                            $user['id']
                        ]);
                    }
                    
                    $updatedCount++;
                }
                
                $db->commit();
                
                // Log activity
                $stmt = $db->prepare("
                    INSERT INTO tp_activity_log (user_id, action, entity_type, ip_address, created_at)
                    VALUES (?, 'update', 'settings', ?, NOW())
                ");
                $stmt->execute([$user['id'], $_SERVER['REMOTE_ADDR'] ?? '']);
                
                echo json_encode([
                    'status' => 'success',
                    'message' => "$updatedCount Einstellungen aktualisiert",
                    'data' => ['updated' => $updatedCount]
                ]);
                
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
            break;
            
        case 'DELETE':
            // Delete a setting (non-system only)
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($input['category'], $input['key'])) {
                http_response_code(400);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Kategorie und Schlüssel erforderlich'
                ]);
                exit;
            }
            
            // Check if it's a system setting
            $stmt = $db->prepare("SELECT id, is_system FROM tp_settings WHERE category = ? AND `key` = ?");
            $stmt->execute([$input['category'], $input['key']]);
            $setting = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$setting) {
                http_response_code(404);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Einstellung nicht gefunden'
                ]);
                exit;
            }
            
            if ($setting['is_system']) {
                http_response_code(403);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'System-Einstellungen können nicht gelöscht werden'
                ]);
                exit;
            }
            
            // Delete the setting
            $stmt = $db->prepare("DELETE FROM tp_settings WHERE id = ?");
            $stmt->execute([$setting['id']]);
            
            echo json_encode([
                'status' => 'success',
                'message' => 'Einstellung gelöscht'
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
    error_log('Settings API Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Serverfehler: ' . $e->getMessage()
    ]);
}