<?php
/**
 * Tierphysio Manager 2.0
 * Invoices API Endpoint
 */

// Set JSON header immediately
header('Content-Type: application/json; charset=utf-8');

// Catch any output errors
ob_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/response.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/auth.php';

// Check authentication
checkApiAuth();

// Get action from request
$action = $_REQUEST['action'] ?? 'list';

try {
    $pdo = pdo();
    
    switch ($action) {
        case 'list':
            $status = $_GET['status'] ?? '';
            $owner_id = intval($_GET['owner_id'] ?? 0);
            $limit = intval($_GET['limit'] ?? 100);
            $offset = intval($_GET['offset'] ?? 0);
            
            $sql = "SELECT i.*, 
                    o.first_name as owner_first_name,
                    o.last_name as owner_last_name,
                    o.customer_number,
                    p.name as patient_name
                    FROM tp_invoices i 
                    LEFT JOIN tp_owners o ON i.owner_id = o.id
                    LEFT JOIN tp_patients p ON i.patient_id = p.id
                    WHERE 1=1";
            
            $params = [];
            
            if ($status) {
                $sql .= " AND i.status = :status";
                $params['status'] = $status;
            }
            
            if ($owner_id) {
                $sql .= " AND i.owner_id = :owner_id";
                $params['owner_id'] = $owner_id;
            }
            
            $sql .= " ORDER BY i.invoice_date DESC, i.invoice_number DESC LIMIT :limit OFFSET :offset";
            
            $stmt = $pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            
            json_success($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;
            
        case 'get':
            $id = intval($_GET['id'] ?? 0);
            if (!$id) {
                json_error('Rechnung ID fehlt', 400);
            }
            
            $sql = "SELECT i.*, 
                    o.* as owner_data,
                    p.name as patient_name
                    FROM tp_invoices i 
                    LEFT JOIN tp_owners o ON i.owner_id = o.id
                    LEFT JOIN tp_patients p ON i.patient_id = p.id
                    WHERE i.id = :id";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['id' => $id]);
            $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$invoice) {
                json_error('Rechnung nicht gefunden', 404);
            }
            
            // Get invoice items
            $stmt = $pdo->prepare("SELECT * FROM tp_invoice_items WHERE invoice_id = :invoice_id ORDER BY position");
            $stmt->execute(['invoice_id' => $id]);
            $invoice['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            json_success($invoice);
            break;
            
        case 'create':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                json_error('Nur POST erlaubt', 405);
            }
            
            require_csrf();
            $data = get_post_data();
            validate_required($data, ['owner_id', 'invoice_date']);
            
            // Generate invoice number
            $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(invoice_number, 4) AS UNSIGNED)) as max_num FROM tp_invoices WHERE invoice_number LIKE 'RE-%'");
            $maxNum = $stmt->fetch(PDO::FETCH_ASSOC)['max_num'] ?? 0;
            $invoiceNumber = 'RE-' . str_pad($maxNum + 1, 5, '0', STR_PAD_LEFT);
            
            // Calculate due date
            $dueDate = date('Y-m-d', strtotime($data['invoice_date'] . ' + 14 days'));
            
            $insertData = [
                'invoice_number' => $invoiceNumber,
                'owner_id' => intval($data['owner_id']),
                'patient_id' => isset($data['patient_id']) ? intval($data['patient_id']) : null,
                'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'] ?? $dueDate,
                'status' => 'draft',
                'subtotal' => floatval($data['subtotal'] ?? 0),
                'tax_rate' => floatval($data['tax_rate'] ?? 19),
                'tax_amount' => floatval($data['tax_amount'] ?? 0),
                'discount_percent' => floatval($data['discount_percent'] ?? 0),
                'discount_amount' => floatval($data['discount_amount'] ?? 0),
                'total' => floatval($data['total'] ?? 0),
                'notes' => sanitize_input($data['notes'] ?? null),
                'internal_notes' => sanitize_input($data['internal_notes'] ?? null),
                'created_by' => $_SESSION['user_id'] ?? null,
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            $columns = array_keys($insertData);
            $placeholders = array_map(function($col) { return ':' . $col; }, $columns);
            
            $sql = "INSERT INTO tp_invoices (" . implode(', ', $columns) . ") 
                    VALUES (" . implode(', ', $placeholders) . ")";
            
            $stmt = $pdo->prepare($sql);
            foreach ($insertData as $key => $value) {
                $stmt->bindValue(':' . $key, $value);
            }
            
            if ($stmt->execute()) {
                $invoiceId = $pdo->lastInsertId();
                $stmt = $pdo->prepare("SELECT * FROM tp_invoices WHERE id = :id");
                $stmt->execute(['id' => $invoiceId]);
                json_success($stmt->fetch(PDO::FETCH_ASSOC), 'Rechnung erfolgreich angelegt');
            } else {
                json_error('Fehler beim Anlegen der Rechnung', 500);
            }
            break;
            
        case 'update':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                json_error('Nur POST erlaubt', 405);
            }
            
            require_csrf();
            $data = get_post_data();
            
            $id = intval($data['id'] ?? 0);
            if (!$id) {
                json_error('Rechnung ID fehlt', 400);
            }
            
            $updateData = [];
            $allowedFields = [
                'owner_id', 'patient_id', 'invoice_date', 'due_date', 'status', 'payment_method',
                'payment_date', 'subtotal', 'tax_rate', 'tax_amount', 'discount_percent',
                'discount_amount', 'total', 'paid_amount', 'notes', 'internal_notes'
            ];
            
            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    if (in_array($field, ['owner_id', 'patient_id'])) {
                        $updateData[$field] = intval($data[$field]);
                    } elseif (in_array($field, ['subtotal', 'tax_rate', 'tax_amount', 'discount_percent', 'discount_amount', 'total', 'paid_amount'])) {
                        $updateData[$field] = floatval($data[$field]);
                    } else {
                        $updateData[$field] = sanitize_input($data[$field]);
                    }
                }
            }
            
            if (empty($updateData)) {
                json_error('Keine Daten zum Aktualisieren', 400);
            }
            
            $updateData['updated_at'] = date('Y-m-d H:i:s');
            
            $setParts = array_map(function($col) { return $col . ' = :' . $col; }, array_keys($updateData));
            $sql = "UPDATE tp_invoices SET " . implode(', ', $setParts) . " WHERE id = :id";
            
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            foreach ($updateData as $key => $value) {
                $stmt->bindValue(':' . $key, $value);
            }
            
            if ($stmt->execute()) {
                $stmt = $pdo->prepare("SELECT * FROM tp_invoices WHERE id = :id");
                $stmt->execute(['id' => $id]);
                json_success($stmt->fetch(PDO::FETCH_ASSOC), 'Rechnung erfolgreich aktualisiert');
            } else {
                json_error('Fehler beim Aktualisieren der Rechnung', 500);
            }
            break;
            
        case 'delete':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                json_error('Nur POST erlaubt', 405);
            }
            
            require_csrf();
            $data = get_post_data();
            
            $id = intval($data['id'] ?? 0);
            if (!$id) {
                json_error('Rechnung ID fehlt', 400);
            }
            
            // Only allow deletion of draft invoices
            $stmt = $pdo->prepare("SELECT status FROM tp_invoices WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$invoice) {
                json_error('Rechnung nicht gefunden', 404);
            }
            
            if ($invoice['status'] !== 'draft') {
                // Cancel instead of delete
                $stmt = $pdo->prepare("UPDATE tp_invoices SET status = 'cancelled', updated_at = NOW() WHERE id = :id");
                if ($stmt->execute(['id' => $id])) {
                    json_success(null, 'Rechnung erfolgreich storniert');
                } else {
                    json_error('Fehler beim Stornieren der Rechnung', 500);
                }
            } else {
                // Delete draft invoice
                $pdo->beginTransaction();
                try {
                    // Delete invoice items first
                    $stmt = $pdo->prepare("DELETE FROM tp_invoice_items WHERE invoice_id = :id");
                    $stmt->execute(['id' => $id]);
                    
                    // Delete invoice
                    $stmt = $pdo->prepare("DELETE FROM tp_invoices WHERE id = :id");
                    $stmt->execute(['id' => $id]);
                    
                    $pdo->commit();
                    json_success(null, 'Rechnung erfolgreich gelöscht');
                } catch (Exception $e) {
                    $pdo->rollBack();
                    throw $e;
                }
            }
            break;
            
        default:
            json_error('Unbekannte Aktion: ' . $action, 400);
    }
    
} catch (PDOException $e) {
    error_log("API invoices " . $action . ": " . $e->getMessage());
    ob_end_clean(); // Clear any output buffer
    json_error('Datenbankfehler aufgetreten', 500);
} catch (Throwable $e) {
    error_log("API invoices " . $action . ": " . $e->getMessage());
    ob_end_clean(); // Clear any output buffer
    json_error('Unerwarteter Fehler: ' . $e->getMessage(), 500);
}

// Ensure no further output
exit;