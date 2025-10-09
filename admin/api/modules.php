<?php
/**
 * Admin Modules Management API
 */

require_once __DIR__ . '/_bootstrap.php';

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

switch ($action) {
    case 'list':
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
        
        api_success($modules, null, count($modules));
        break;
        
    case 'toggle':
        csrf_check();
        requirePermission('modules.manage');
        
        $data = getJsonInput();
        $id = sanitize($data['id'] ?? 0, 'int');
        $enabled = sanitize($data['enabled'] ?? false, 'bool');
        
        if (!$id) {
            api_error('Module ID erforderlich');
        }
        
        try {
            $stmt = $pdo->prepare("UPDATE tp_modules SET enabled = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$enabled ? 1 : 0, $id]);
            
            if ($stmt->rowCount() > 0) {
                api_success(null, $enabled ? 'Modul aktiviert' : 'Modul deaktiviert');
            } else {
                api_error('Modul nicht gefunden', 404);
            }
        } catch (Exception $e) {
            api_error('Fehler beim Aktualisieren: ' . $e->getMessage());
        }
        break;
        
    case 'install':
        csrf_check();
        requirePermission('modules.manage');
        
        $data = getJsonInput();
        $key = sanitize($data['key'] ?? '');
        $name = sanitize($data['name'] ?? '');
        $version = sanitize($data['version'] ?? '1.0.0');
        
        if (!$key || !$name) {
            api_error('Module key und name erforderlich');
        }
        
        // Check if already installed
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tp_modules WHERE `key` = ?");
        $stmt->execute([$key]);
        if ($stmt->fetchColumn() > 0) {
            api_error('Modul bereits installiert');
        }
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO tp_modules (`key`, name, version, enabled, created_at)
                VALUES (?, ?, ?, 0, NOW())
            ");
            $stmt->execute([$key, $name, $version]);
            
            api_success(['id' => $pdo->lastInsertId()], 'Modul erfolgreich installiert');
        } catch (Exception $e) {
            api_error('Fehler bei der Installation: ' . $e->getMessage());
        }
        break;
        
    case 'uninstall':
        csrf_check();
        requirePermission('modules.manage');
        
        $data = getJsonInput();
        $id = sanitize($data['id'] ?? 0, 'int');
        
        if (!$id) {
            api_error('Module ID erforderlich');
        }
        
        try {
            $stmt = $pdo->prepare("DELETE FROM tp_modules WHERE id = ?");
            $stmt->execute([$id]);
            
            if ($stmt->rowCount() > 0) {
                api_success(null, 'Modul erfolgreich deinstalliert');
            } else {
                api_error('Modul nicht gefunden', 404);
            }
        } catch (Exception $e) {
            api_error('Fehler bei der Deinstallation: ' . $e->getMessage());
        }
        break;
        
    case 'configure':
        csrf_check();
        requirePermission('modules.manage');
        
        $data = getJsonInput();
        $id = sanitize($data['id'] ?? 0, 'int');
        $config = $data['config'] ?? [];
        
        if (!$id) {
            api_error('Module ID erforderlich');
        }
        
        $configJson = json_encode($config);
        if (json_last_error() !== JSON_ERROR_NONE) {
            api_error('Ungültige Konfiguration');
        }
        
        try {
            $stmt = $pdo->prepare("UPDATE tp_modules SET config = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$configJson, $id]);
            
            if ($stmt->rowCount() > 0) {
                api_success(null, 'Konfiguration gespeichert');
            } else {
                api_error('Modul nicht gefunden', 404);
            }
        } catch (Exception $e) {
            api_error('Fehler beim Speichern: ' . $e->getMessage());
        }
        break;
        
    case 'available':
        // List of available modules (hardcoded for now)
        $available = [
            [
                'key' => 'appointment_reminders',
                'name' => 'Terminerinnerungen',
                'description' => 'Automatische E-Mail und SMS Erinnerungen',
                'version' => '1.0.0',
                'author' => 'Tierphysio Manager',
                'installed' => false
            ],
            [
                'key' => 'online_booking',
                'name' => 'Online Terminbuchung',
                'description' => 'Patienten können online Termine buchen',
                'version' => '1.0.0',
                'author' => 'Tierphysio Manager',
                'installed' => false
            ],
            [
                'key' => 'patient_portal',
                'name' => 'Patientenportal',
                'description' => 'Selbstverwaltung für Patienten',
                'version' => '1.0.0',
                'author' => 'Tierphysio Manager',
                'installed' => false
            ],
            [
                'key' => 'analytics',
                'name' => 'Erweiterte Statistiken',
                'description' => 'Detaillierte Berichte und Analysen',
                'version' => '1.0.0',
                'author' => 'Tierphysio Manager',
                'installed' => false
            ],
            [
                'key' => 'inventory',
                'name' => 'Lagerverwaltung',
                'description' => 'Verwaltung von Produkten und Materialien',
                'version' => '1.0.0',
                'author' => 'Tierphysio Manager',
                'installed' => false
            ]
        ];
        
        // Check which are installed
        $stmt = $pdo->query("SELECT `key` FROM tp_modules");
        $installed = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($available as &$module) {
            $module['installed'] = in_array($module['key'], $installed);
        }
        
        api_success($available, null, count($available));
        break;
        
    default:
        api_error('Unbekannte Aktion', 400);
}