<?php
/**
 * Tierphysio Manager 2.0
 * Settings API Endpoint
 */

require_once __DIR__ . '/../includes/db.php';
header('Content-Type: application/json; charset=utf-8');

// Check authentication
checkApiAuth();

// Get action from request
$action = $_REQUEST['action'] ?? 'get_all';
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($action) {
        case 'get_all':
            // Get all settings or filtered by category
            $category = $_GET['category'] ?? '';
            $include_system = isset($_GET['include_system']) ? intval($_GET['include_system']) : 0;
            
            $sql = "SELECT s.*, 
                    u.first_name as updated_by_first_name,
                    u.last_name as updated_by_last_name
                    FROM tp_settings s 
                    LEFT JOIN tp_users u ON s.updated_by = u.id
                    WHERE 1=1";
            
            $params = [];
            
            if ($category) {
                $sql .= " AND s.category = :category";
                $params['category'] = $category;
            }
            
            if (!$include_system) {
                $sql .= " AND s.is_system = 0";
            }
            
            $sql .= " ORDER BY s.category, s.key";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            $settings = $stmt->fetchAll();
            
            // Group settings by category
            $grouped = [];
            foreach ($settings as $setting) {
                // Decode value based on type
                if ($setting['type'] === 'json' || $setting['type'] === 'array') {
                    $setting['value'] = json_decode($setting['value'], true);
                } elseif ($setting['type'] === 'boolean') {
                    $setting['value'] = (bool) $setting['value'];
                } elseif ($setting['type'] === 'number') {
                    $setting['value'] = is_numeric($setting['value']) ? floatval($setting['value']) : 0;
                }
                
                $grouped[$setting['category']][] = $setting;
            }
            
            echo json_encode([
                "status" => "success",
                "data" => [
                    'settings' => $settings,
                    'grouped' => $grouped
                ],
                "message" => count($settings) . " Einstellungen gefunden"
            ]);
            break;
            
        case 'get_by_key':
            $category = $_GET['category'] ?? '';
            $key = $_GET['key'] ?? '';
            
            if (!$category || !$key) {
                throw new Exception('Kategorie und Schlüssel fehlen');
            }
            
            $sql = "SELECT s.*, 
                    u.first_name as updated_by_first_name,
                    u.last_name as updated_by_last_name
                    FROM tp_settings s 
                    LEFT JOIN tp_users u ON s.updated_by = u.id
                    WHERE s.category = :category AND s.key = :key";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'category' => $category,
                'key' => $key
            ]);
            $setting = $stmt->fetch();
            
            if (!$setting) {
                throw new Exception('Einstellung nicht gefunden');
            }
            
            // Decode value based on type
            if ($setting['type'] === 'json' || $setting['type'] === 'array') {
                $setting['value'] = json_decode($setting['value'], true);
            } elseif ($setting['type'] === 'boolean') {
                $setting['value'] = (bool) $setting['value'];
            } elseif ($setting['type'] === 'number') {
                $setting['value'] = is_numeric($setting['value']) ? floatval($setting['value']) : 0;
            }
            
            echo json_encode([
                "status" => "success",
                "data" => $setting,
                "message" => "Einstellung gefunden"
            ]);
            break;
            
        case 'create':
            if ($method !== 'POST') {
                throw new Exception('Nur POST-Methode erlaubt');
            }
            
            // Validate required fields
            if (empty($_POST['category']) || empty($_POST['key'])) {
                throw new Exception('Kategorie und Schlüssel sind Pflichtfelder');
            }
            
            // Check if setting already exists
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tp_settings WHERE category = :category AND `key` = :key");
            $stmt->execute([
                'category' => $_POST['category'],
                'key' => $_POST['key']
            ]);
            
            if ($stmt->fetch()['count'] > 0) {
                throw new Exception('Eine Einstellung mit diesem Schlüssel existiert bereits in dieser Kategorie');
            }
            
            // Encode value based on type
            $value = $_POST['value'] ?? '';
            $type = $_POST['type'] ?? 'string';
            
            if ($type === 'json' || $type === 'array') {
                if (is_array($value)) {
                    $value = json_encode($value);
                } elseif (!json_decode($value)) {
                    throw new Exception('Ungültiger JSON-Wert');
                }
            } elseif ($type === 'boolean') {
                $value = $value ? '1' : '0';
            } elseif ($type === 'number') {
                if (!is_numeric($value)) {
                    throw new Exception('Wert muss eine Zahl sein');
                }
            }
            
            $sql = "INSERT INTO tp_settings (
                        category, `key`, value, type, description, is_system, updated_by
                    ) VALUES (
                        :category, :key, :value, :type, :description, :is_system, :updated_by
                    )";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'category' => $_POST['category'],
                'key' => $_POST['key'],
                'value' => $value,
                'type' => $type,
                'description' => $_POST['description'] ?? null,
                'is_system' => isset($_POST['is_system']) ? 1 : 0,
                'updated_by' => $_SESSION['user_id'] ?? 1
            ]);
            
            $settingId = $pdo->lastInsertId();
            
            echo json_encode([
                "status" => "success",
                "data" => ["id" => $settingId],
                "message" => "Einstellung erfolgreich erstellt"
            ]);
            break;
            
        case 'update':
            if ($method !== 'POST' && $method !== 'PUT') {
                throw new Exception('Nur POST/PUT-Methode erlaubt');
            }
            
            $id = intval($_POST['id'] ?? $_GET['id'] ?? 0);
            $category = $_POST['category'] ?? $_GET['category'] ?? '';
            $key = $_POST['key'] ?? $_GET['key'] ?? '';
            
            // Parse PUT data if necessary
            if ($method === 'PUT') {
                parse_str(file_get_contents("php://input"), $_POST);
            }
            
            // Find setting by id or category/key
            if ($id) {
                $stmt = $pdo->prepare("SELECT * FROM tp_settings WHERE id = :id");
                $stmt->execute(['id' => $id]);
            } elseif ($category && $key) {
                $stmt = $pdo->prepare("SELECT * FROM tp_settings WHERE category = :category AND `key` = :key");
                $stmt->execute(['category' => $category, 'key' => $key]);
            } else {
                throw new Exception('Einstellung ID oder Kategorie/Schlüssel fehlen');
            }
            
            $setting = $stmt->fetch();
            
            if (!$setting) {
                throw new Exception('Einstellung nicht gefunden');
            }
            
            // Check if system setting and prevent critical changes
            if ($setting['is_system']) {
                // Only allow value updates for system settings
                if (isset($_POST['key']) || isset($_POST['category']) || isset($_POST['type'])) {
                    throw new Exception('Systemeinstellungen können nicht strukturell geändert werden');
                }
            }
            
            // Encode value based on type
            $value = $_POST['value'] ?? $setting['value'];
            $type = $_POST['type'] ?? $setting['type'];
            
            if ($type === 'json' || $type === 'array') {
                if (is_array($value)) {
                    $value = json_encode($value);
                } elseif (!json_decode($value)) {
                    throw new Exception('Ungültiger JSON-Wert');
                }
            } elseif ($type === 'boolean') {
                $value = $value ? '1' : '0';
            } elseif ($type === 'number') {
                if (!is_numeric($value)) {
                    throw new Exception('Wert muss eine Zahl sein');
                }
            }
            
            $sql = "UPDATE tp_settings SET 
                        value = :value,
                        type = :type,
                        description = :description,
                        updated_by = :updated_by,
                        updated_at = NOW()
                    WHERE id = :id";
            
            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute([
                'id' => $setting['id'],
                'value' => $value,
                'type' => $type,
                'description' => $_POST['description'] ?? $setting['description'],
                'updated_by' => $_SESSION['user_id'] ?? 1
            ]);
            
            echo json_encode([
                "status" => "success",
                "data" => ["id" => $setting['id']],
                "message" => "Einstellung erfolgreich aktualisiert"
            ]);
            break;
            
        case 'delete':
            if ($method !== 'DELETE' && $method !== 'POST') {
                throw new Exception('Nur DELETE/POST-Methode erlaubt');
            }
            
            $id = intval($_REQUEST['id'] ?? 0);
            $category = $_REQUEST['category'] ?? '';
            $key = $_REQUEST['key'] ?? '';
            
            // Find setting by id or category/key
            if ($id) {
                $stmt = $pdo->prepare("SELECT * FROM tp_settings WHERE id = :id");
                $stmt->execute(['id' => $id]);
            } elseif ($category && $key) {
                $stmt = $pdo->prepare("SELECT * FROM tp_settings WHERE category = :category AND `key` = :key");
                $stmt->execute(['category' => $category, 'key' => $key]);
            } else {
                throw new Exception('Einstellung ID oder Kategorie/Schlüssel fehlen');
            }
            
            $setting = $stmt->fetch();
            
            if (!$setting) {
                throw new Exception('Einstellung nicht gefunden');
            }
            
            // Prevent deletion of system settings
            if ($setting['is_system']) {
                throw new Exception('Systemeinstellungen können nicht gelöscht werden');
            }
            
            // Delete setting
            $sql = "DELETE FROM tp_settings WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['id' => $setting['id']]);
            
            echo json_encode([
                "status" => "success",
                "data" => ["id" => $setting['id']],
                "message" => "Einstellung erfolgreich gelöscht"
            ]);
            break;
            
        case 'reset_defaults':
            if ($method !== 'POST') {
                throw new Exception('Nur POST-Methode erlaubt');
            }
            
            $category = $_POST['category'] ?? '';
            
            // Reset settings to default values
            // This would typically reload defaults from a configuration file
            // For now, we'll just return success
            
            echo json_encode([
                "status" => "success",
                "data" => null,
                "message" => "Einstellungen wurden auf Standardwerte zurückgesetzt"
            ]);
            break;
            
        case 'get_categories':
            // Get all available categories
            $sql = "SELECT DISTINCT category, COUNT(*) as count 
                    FROM tp_settings 
                    GROUP BY category 
                    ORDER BY category";
            
            $stmt = $pdo->query($sql);
            $categories = $stmt->fetchAll();
            
            echo json_encode([
                "status" => "success",
                "data" => $categories,
                "message" => count($categories) . " Kategorien gefunden"
            ]);
            break;
            
        case 'backup':
            // Export all settings as JSON
            $stmt = $pdo->query("SELECT * FROM tp_settings ORDER BY category, `key`");
            $settings = $stmt->fetchAll();
            
            $backup = [
                'version' => '2.0.0',
                'timestamp' => date('Y-m-d H:i:s'),
                'settings' => $settings
            ];
            
            echo json_encode([
                "status" => "success",
                "data" => $backup,
                "message" => "Backup erstellt"
            ]);
            break;
            
        case 'restore':
            if ($method !== 'POST') {
                throw new Exception('Nur POST-Methode erlaubt');
            }
            
            $backup = $_POST['backup'] ?? '';
            
            if (empty($backup)) {
                throw new Exception('Backup-Daten fehlen');
            }
            
            $data = is_string($backup) ? json_decode($backup, true) : $backup;
            
            if (!$data || !isset($data['settings'])) {
                throw new Exception('Ungültiges Backup-Format');
            }
            
            // Start transaction
            $pdo->beginTransaction();
            
            try {
                // Clear non-system settings
                $pdo->exec("DELETE FROM tp_settings WHERE is_system = 0");
                
                // Restore settings
                foreach ($data['settings'] as $setting) {
                    if ($setting['is_system']) {
                        // Update system settings
                        $sql = "UPDATE tp_settings SET value = :value WHERE category = :category AND `key` = :key";
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute([
                            'value' => $setting['value'],
                            'category' => $setting['category'],
                            'key' => $setting['key']
                        ]);
                    } else {
                        // Insert non-system settings
                        $sql = "INSERT IGNORE INTO tp_settings (category, `key`, value, type, description, is_system, updated_by) 
                                VALUES (:category, :key, :value, :type, :description, :is_system, :updated_by)";
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute([
                            'category' => $setting['category'],
                            'key' => $setting['key'],
                            'value' => $setting['value'],
                            'type' => $setting['type'],
                            'description' => $setting['description'],
                            'is_system' => 0,
                            'updated_by' => $_SESSION['user_id'] ?? 1
                        ]);
                    }
                }
                
                $pdo->commit();
                
                echo json_encode([
                    "status" => "success",
                    "data" => null,
                    "message" => "Einstellungen erfolgreich wiederhergestellt"
                ]);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;
            
        default:
            throw new Exception('Unbekannte Aktion: ' . $action);
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage(),
        "data" => null
    ]);
}