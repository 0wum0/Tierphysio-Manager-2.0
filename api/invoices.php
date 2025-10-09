<?php
/**
 * Tierphysio Manager 2.0
 * Invoices API Endpoint - Hardened with tp_ prefix & proper JSON responses
 */

// Set JSON header immediately
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

// Error reporting for production
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Clear any existing output
if (ob_get_length()) ob_end_clean();
ob_start();

require_once __DIR__ . '/../includes/db.php';

// API Helper Functions
function api_success($data = [], $extra = []) {
    if (ob_get_length()) ob_end_clean();
    $response = array_merge(['status' => 'success', 'data' => $data], $extra);
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_error($message = 'Unbekannter Fehler', $code = 400, $extra = []) {
    if (ob_get_length()) ob_end_clean();
    http_response_code($code);
    $response = array_merge(['status' => 'error', 'message' => $message], $extra);
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Helper function to generate invoice number
function generateInvoiceNumber($pdo) {
    $year = date('Y');
    $stmt = $pdo->prepare("
        SELECT MAX(CAST(SUBSTRING(invoice_number, -4) AS UNSIGNED)) as max_nr 
        FROM tp_invoices 
        WHERE invoice_number LIKE ?
    ");
    $stmt->execute([$year . '-%']);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $nextNumber = ($result['max_nr'] ?? 0) + 1;
    return sprintf('%s-%04d', $year, $nextNumber);
}

// Get action from request
$action = $_GET['action'] ?? 'list';

try {
    $pdo = get_pdo();
    
    switch ($action) {
        case 'list':
            $date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
            $date_to = $_GET['date_to'] ?? date('Y-m-d');
            $status = $_GET['status'] ?? '';
            $patient_id = intval($_GET['patient_id'] ?? 0);
            $limit = intval($_GET['limit'] ?? 100);
            $offset = intval($_GET['offset'] ?? 0);
            
            $sql = "
                SELECT 
                    i.*,
                    p.name as patient_name,
                    p.patient_number,
                    CONCAT_WS(' ', o.first_name, o.last_name) AS owner_full_name,
                    o.customer_number,
                    o.email as owner_email,
                    o.phone as owner_phone
                FROM tp_invoices i
                LEFT JOIN tp_patients p ON i.patient_id = p.id
                LEFT JOIN tp_owners o ON p.owner_id = o.id
                WHERE i.invoice_date BETWEEN :date_from AND :date_to
            ";
            
            $params = [
                'date_from' => $date_from,
                'date_to' => $date_to
            ];
            
            if ($status) {
                $sql .= " AND i.status = :status";
                $params['status'] = $status;
            }
            
            if ($patient_id) {
                $sql .= " AND i.patient_id = :patient_id";
                $params['patient_id'] = $patient_id;
            }
            
            $sql .= " ORDER BY i.invoice_date DESC, i.created_at DESC LIMIT :limit OFFSET :offset";
            
            $stmt = $pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            
            $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format amounts
            foreach ($invoices as &$invoice) {
                $invoice['total_amount'] = floatval($invoice['total_amount']);
                $invoice['tax_amount'] = floatval($invoice['tax_amount']);
                $invoice['net_amount'] = floatval($invoice['net_amount']);
            }
            
            api_success(['items' => $invoices, 'count' => count($invoices)]);
            break;
            
        case 'get':
            $id = intval($_GET['id'] ?? 0);
            if (!$id) {
                api_error('Rechnungs-ID fehlt', 400);
            }
            
            $stmt = $pdo->prepare("
                SELECT 
                    i.*,
                    p.name as patient_name,
                    p.patient_number,
                    p.species,
                    o.salutation,
                    o.first_name as owner_first_name,
                    o.last_name as owner_last_name,
                    o.company as owner_company,
                    o.street as owner_street,
                    o.house_number as owner_house_number,
                    o.postal_code as owner_postal_code,
                    o.city as owner_city,
                    o.email as owner_email,
                    o.phone as owner_phone
                FROM tp_invoices i
                LEFT JOIN tp_patients p ON i.patient_id = p.id
                LEFT JOIN tp_owners o ON p.owner_id = o.id
                WHERE i.id = ?
            ");
            $stmt->execute([$id]);
            $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$invoice) {
                api_error('Rechnung nicht gefunden', 404);
            }
            
            // Get invoice items
            $stmt = $pdo->prepare("
                SELECT * FROM tp_invoice_items 
                WHERE invoice_id = ? 
                ORDER BY position
            ");
            $stmt->execute([$id]);
            $invoice['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format amounts
            $invoice['total_amount'] = floatval($invoice['total_amount']);
            $invoice['tax_amount'] = floatval($invoice['tax_amount']);
            $invoice['net_amount'] = floatval($invoice['net_amount']);
            
            foreach ($invoice['items'] as &$item) {
                $item['quantity'] = floatval($item['quantity']);
                $item['unit_price'] = floatval($item['unit_price']);
                $item['total_price'] = floatval($item['total_price']);
                $item['tax_rate'] = floatval($item['tax_rate']);
            }
            
            api_success($invoice);
            break;
            
        case 'create':
            // Get input data
            $input = $_POST;
            if (empty($input)) {
                $json = file_get_contents('php://input');
                $input = json_decode($json, true) ?? [];
            }
            
            // Validate required fields
            $patient_id = intval($input['patient_id'] ?? 0);
            $invoice_date = $input['invoice_date'] ?? date('Y-m-d');
            $items = $input['items'] ?? [];
            
            if (!$patient_id) {
                api_error('Patient ID fehlt', 400);
            }
            
            if (empty($items)) {
                api_error('Mindestens eine Position erforderlich', 400);
            }
            
            // Begin transaction
            $pdo->beginTransaction();
            
            try {
                // Generate invoice number
                $invoice_number = generateInvoiceNumber($pdo);
                
                // Calculate totals
                $net_amount = 0;
                $tax_amount = 0;
                
                foreach ($items as $item) {
                    $item_total = floatval($item['quantity']) * floatval($item['unit_price']);
                    $item_tax = $item_total * (floatval($item['tax_rate'] ?? 19) / 100);
                    $net_amount += $item_total;
                    $tax_amount += $item_tax;
                }
                
                $total_amount = $net_amount + $tax_amount;
                
                // Create invoice
                $stmt = $pdo->prepare("
                    INSERT INTO tp_invoices (
                        invoice_number, patient_id, invoice_date, due_date,
                        net_amount, tax_amount, total_amount, 
                        status, payment_method, notes, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                
                $due_date = $input['due_date'] ?? date('Y-m-d', strtotime('+14 days'));
                
                $stmt->execute([
                    $invoice_number,
                    $patient_id,
                    $invoice_date,
                    $due_date,
                    $net_amount,
                    $tax_amount,
                    $total_amount,
                    $input['status'] ?? 'draft',
                    $input['payment_method'] ?? null,
                    $input['notes'] ?? null
                ]);
                
                $invoice_id = $pdo->lastInsertId();
                
                // Add invoice items
                $position = 1;
                foreach ($items as $item) {
                    $stmt = $pdo->prepare("
                        INSERT INTO tp_invoice_items (
                            invoice_id, position, description, quantity,
                            unit_price, tax_rate, total_price
                        ) VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    $quantity = floatval($item['quantity']);
                    $unit_price = floatval($item['unit_price']);
                    $tax_rate = floatval($item['tax_rate'] ?? 19);
                    $total_price = $quantity * $unit_price * (1 + $tax_rate / 100);
                    
                    $stmt->execute([
                        $invoice_id,
                        $position++,
                        $item['description'],
                        $quantity,
                        $unit_price,
                        $tax_rate,
                        $total_price
                    ]);
                }
                
                $pdo->commit();
                
                // Get created invoice
                $stmt = $pdo->prepare("
                    SELECT i.*, p.name as patient_name,
                        CONCAT_WS(' ', o.first_name, o.last_name) AS owner_full_name
                    FROM tp_invoices i
                    LEFT JOIN tp_patients p ON i.patient_id = p.id
                    LEFT JOIN tp_owners o ON p.owner_id = o.id
                    WHERE i.id = ?
                ");
                $stmt->execute([$invoice_id]);
                $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
                
                api_success([
                    'invoice_id' => $invoice_id,
                    'invoice_number' => $invoice_number,
                    'invoice' => $invoice
                ]);
                
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;
            
        case 'update':
            $input = $_POST;
            if (empty($input)) {
                $json = file_get_contents('php://input');
                $input = json_decode($json, true) ?? [];
            }
            
            $id = intval($input['id'] ?? 0);
            if (!$id) {
                api_error('Rechnungs-ID fehlt', 400);
            }
            
            // Check if invoice exists
            $stmt = $pdo->prepare("SELECT * FROM tp_invoices WHERE id = ?");
            $stmt->execute([$id]);
            if (!$stmt->fetch()) {
                api_error('Rechnung nicht gefunden', 404);
            }
            
            // Update invoice
            $stmt = $pdo->prepare("
                UPDATE tp_invoices SET
                    invoice_date = ?,
                    due_date = ?,
                    status = ?,
                    payment_method = ?,
                    payment_date = ?,
                    notes = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([
                $input['invoice_date'] ?? date('Y-m-d'),
                $input['due_date'] ?? date('Y-m-d', strtotime('+14 days')),
                $input['status'] ?? 'draft',
                $input['payment_method'] ?? null,
                $input['payment_date'] ?? null,
                $input['notes'] ?? null,
                $id
            ]);
            
            api_success(['message' => 'Rechnung erfolgreich aktualisiert']);
            break;
            
        case 'delete':
            $input = $_POST;
            if (empty($input)) {
                $json = file_get_contents('php://input');
                $input = json_decode($json, true) ?? [];
            }
            
            $id = intval($input['id'] ?? $_GET['id'] ?? 0);
            if (!$id) {
                api_error('Rechnungs-ID fehlt', 400);
            }
            
            // Begin transaction
            $pdo->beginTransaction();
            
            try {
                // Delete invoice items first
                $stmt = $pdo->prepare("DELETE FROM tp_invoice_items WHERE invoice_id = ?");
                $stmt->execute([$id]);
                
                // Delete invoice
                $stmt = $pdo->prepare("DELETE FROM tp_invoices WHERE id = ?");
                $stmt->execute([$id]);
                
                $pdo->commit();
                
                api_success(['message' => 'Rechnung erfolgreich gelöscht']);
                
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;
            
        case 'statistics':
            // Get invoice statistics
            $year = intval($_GET['year'] ?? date('Y'));
            $month = intval($_GET['month'] ?? 0);
            
            $sql = "
                SELECT 
                    COUNT(*) as total_invoices,
                    SUM(total_amount) as total_revenue,
                    SUM(CASE WHEN status = 'paid' THEN total_amount ELSE 0 END) as paid_revenue,
                    SUM(CASE WHEN status = 'pending' THEN total_amount ELSE 0 END) as pending_revenue,
                    SUM(CASE WHEN status = 'overdue' THEN total_amount ELSE 0 END) as overdue_revenue
                FROM tp_invoices
                WHERE YEAR(invoice_date) = :year
            ";
            
            $params = ['year' => $year];
            
            if ($month > 0) {
                $sql .= " AND MONTH(invoice_date) = :month";
                $params['month'] = $month;
            }
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Format amounts
            foreach ($stats as &$value) {
                if (strpos($value, 'revenue') !== false || $value === null) {
                    $value = floatval($value ?? 0);
                }
            }
            
            api_success($stats);
            break;
            
        default:
            api_error("Unbekannte Aktion: " . $action, 400);
    }
    
} catch (PDOException $e) {
    error_log("Invoices API PDO Error (" . $action . "): " . $e->getMessage());
    api_error('Datenbankfehler aufgetreten', 500, ['details' => APP_DEBUG ? $e->getMessage() : null]);
} catch (Throwable $e) {
    error_log("Invoices API Error (" . $action . "): " . $e->getMessage());
    api_error('Serverfehler aufgetreten', 500, ['details' => APP_DEBUG ? $e->getMessage() : null]);
}

// Should never reach here
exit;