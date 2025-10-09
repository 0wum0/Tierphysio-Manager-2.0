<?php
/**
 * Admin Finance Management API
 */

require_once __DIR__ . '/_bootstrap.php';

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

switch ($action) {
    case 'list':
        $stmt = $pdo->query("SELECT * FROM tp_finance_items ORDER BY active DESC, name ASC");
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        api_success($items, null, count($items));
        break;
        
    case 'get':
        $id = sanitize($_GET['id'] ?? 0, 'int');
        if (!$id) {
            api_error('Item ID erforderlich');
        }
        
        $stmt = $pdo->prepare("SELECT * FROM tp_finance_items WHERE id = ?");
        $stmt->execute([$id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$item) {
            api_error('Artikel nicht gefunden', 404);
        }
        
        api_success($item);
        break;
        
    case 'create':
        csrf_check();
        requirePermission('finance.manage');
        
        $data = getJsonInput();
        $name = sanitize($data['name'] ?? '');
        $unit_price = sanitize($data['unit_price'] ?? 0, 'float');
        $tax_rate = sanitize($data['tax_rate'] ?? 19, 'float');
        $active = sanitize($data['active'] ?? true, 'bool');
        
        if (!$name) {
            api_error('Name ist erforderlich');
        }
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO tp_finance_items (name, unit_price, tax_rate, active, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$name, $unit_price, $tax_rate, $active ? 1 : 0]);
            
            api_success(['id' => $pdo->lastInsertId()], 'Artikel erfolgreich erstellt');
        } catch (Exception $e) {
            api_error('Fehler beim Erstellen: ' . $e->getMessage());
        }
        break;
        
    case 'update':
        csrf_check();
        requirePermission('finance.manage');
        
        $data = getJsonInput();
        $id = sanitize($data['id'] ?? 0, 'int');
        
        if (!$id) {
            api_error('Item ID erforderlich');
        }
        
        $updates = [];
        $params = [];
        
        if (isset($data['name'])) {
            $updates[] = 'name = ?';
            $params[] = sanitize($data['name']);
        }
        
        if (isset($data['unit_price'])) {
            $updates[] = 'unit_price = ?';
            $params[] = sanitize($data['unit_price'], 'float');
        }
        
        if (isset($data['tax_rate'])) {
            $updates[] = 'tax_rate = ?';
            $params[] = sanitize($data['tax_rate'], 'float');
        }
        
        if (isset($data['active'])) {
            $updates[] = 'active = ?';
            $params[] = sanitize($data['active'], 'bool') ? 1 : 0;
        }
        
        if (empty($updates)) {
            api_error('Keine Daten zum Aktualisieren');
        }
        
        $updates[] = 'updated_at = NOW()';
        $params[] = $id;
        
        try {
            $stmt = $pdo->prepare("UPDATE tp_finance_items SET " . implode(', ', $updates) . " WHERE id = ?");
            $stmt->execute($params);
            
            if ($stmt->rowCount() > 0) {
                api_success(null, 'Artikel erfolgreich aktualisiert');
            } else {
                api_error('Artikel nicht gefunden oder keine Änderungen', 404);
            }
        } catch (Exception $e) {
            api_error('Fehler beim Aktualisieren: ' . $e->getMessage());
        }
        break;
        
    case 'delete':
        csrf_check();
        requirePermission('finance.manage');
        
        $data = getJsonInput();
        $id = sanitize($data['id'] ?? 0, 'int');
        
        if (!$id) {
            api_error('Item ID erforderlich');
        }
        
        try {
            $stmt = $pdo->prepare("DELETE FROM tp_finance_items WHERE id = ?");
            $stmt->execute([$id]);
            
            if ($stmt->rowCount() > 0) {
                api_success(null, 'Artikel erfolgreich gelöscht');
            } else {
                api_error('Artikel nicht gefunden', 404);
            }
        } catch (Exception $e) {
            api_error('Fehler beim Löschen: ' . $e->getMessage());
        }
        break;
        
    case 'settings':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_check();
            requirePermission('finance.manage');
            
            $data = getJsonInput();
            
            try {
                $pdo->beginTransaction();
                
                $stmt = $pdo->prepare("
                    INSERT INTO tp_settings (`key`, value, category, updated_at)
                    VALUES (?, ?, 'finance', NOW())
                    ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()
                ");
                
                $fields = ['default_tax_rate', 'currency', 'currency_symbol'];
                
                foreach ($fields as $field) {
                    if (isset($data[$field])) {
                        $stmt->execute(['finance_' . $field, $data[$field]]);
                    }
                }
                
                $pdo->commit();
                api_success(null, 'Einstellungen gespeichert');
            } catch (Exception $e) {
                $pdo->rollBack();
                api_error('Fehler beim Speichern: ' . $e->getMessage());
            }
        } else {
            // GET settings
            $stmt = $pdo->query("SELECT `key`, value FROM tp_settings WHERE category = 'finance'");
            $settings = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $key = str_replace('finance_', '', $row['key']);
                $settings[$key] = $row['value'];
            }
            
            api_success($settings);
        }
        break;
        
    case 'statistics':
        $stats = [
            'total_revenue_month' => 0,
            'total_revenue_year' => 0,
            'total_revenue_all' => 0,
            'pending_invoices' => 0,
            'paid_invoices' => 0,
            'overdue_invoices' => 0
        ];
        
        // Check if invoices table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'tp_invoices'");
        if ($stmt->rowCount() > 0) {
            // Monthly revenue
            $stmt = $pdo->query("
                SELECT COALESCE(SUM(total_amount), 0)
                FROM tp_invoices 
                WHERE YEAR(created_at) = YEAR(CURDATE()) 
                AND MONTH(created_at) = MONTH(CURDATE())
                AND status = 'paid'
            ");
            $stats['total_revenue_month'] = $stmt->fetchColumn();
            
            // Yearly revenue
            $stmt = $pdo->query("
                SELECT COALESCE(SUM(total_amount), 0)
                FROM tp_invoices 
                WHERE YEAR(created_at) = YEAR(CURDATE())
                AND status = 'paid'
            ");
            $stats['total_revenue_year'] = $stmt->fetchColumn();
            
            // All-time revenue
            $stmt = $pdo->query("
                SELECT COALESCE(SUM(total_amount), 0)
                FROM tp_invoices 
                WHERE status = 'paid'
            ");
            $stats['total_revenue_all'] = $stmt->fetchColumn();
            
            // Invoice counts
            $stmt = $pdo->query("SELECT COUNT(*) FROM tp_invoices WHERE status = 'pending'");
            $stats['pending_invoices'] = $stmt->fetchColumn();
            
            $stmt = $pdo->query("SELECT COUNT(*) FROM tp_invoices WHERE status = 'paid'");
            $stats['paid_invoices'] = $stmt->fetchColumn();
            
            $stmt = $pdo->query("
                SELECT COUNT(*) FROM tp_invoices 
                WHERE status = 'pending' AND due_date < CURDATE()
            ");
            $stats['overdue_invoices'] = $stmt->fetchColumn();
        }
        
        api_success($stats);
        break;
        
    default:
        api_error('Unbekannte Aktion', 400);
}