<?php
/**
 * Tierphysio Manager 2.0
 * Invoices API Endpoint
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
            // Get all invoices with optional filters
            $status = $_GET['status'] ?? '';
            $owner_id = intval($_GET['owner_id'] ?? 0);
            $patient_id = intval($_GET['patient_id'] ?? 0);
            $date_from = $_GET['date_from'] ?? date('Y-m-01'); // First day of current month
            $date_to = $_GET['date_to'] ?? date('Y-m-t'); // Last day of current month
            $limit = intval($_GET['limit'] ?? 100);
            $offset = intval($_GET['offset'] ?? 0);
            
            $sql = "SELECT i.*, 
                    o.first_name as owner_first_name,
                    o.last_name as owner_last_name,
                    o.customer_number,
                    p.name as patient_name,
                    p.species as patient_species
                    FROM tp_invoices i 
                    LEFT JOIN tp_owners o ON i.owner_id = o.id
                    LEFT JOIN tp_patients p ON i.patient_id = p.id
                    WHERE i.invoice_date BETWEEN :date_from AND :date_to";
            
            $params = [
                'date_from' => $date_from,
                'date_to' => $date_to
            ];
            
            if ($status) {
                $sql .= " AND i.status = :status";
                $params['status'] = $status;
            }
            
            if ($owner_id) {
                $sql .= " AND i.owner_id = :owner_id";
                $params['owner_id'] = $owner_id;
            }
            
            if ($patient_id) {
                $sql .= " AND i.patient_id = :patient_id";
                $params['patient_id'] = $patient_id;
            }
            
            // Check for overdue invoices
            if ($status === 'overdue' || empty($status)) {
                $sql_overdue = "UPDATE tp_invoices 
                               SET status = 'overdue' 
                               WHERE status = 'sent' 
                               AND due_date < CURDATE()";
                $pdo->exec($sql_overdue);
            }
            
            $sql .= " ORDER BY i.invoice_date DESC, i.invoice_number DESC LIMIT :limit OFFSET :offset";
            
            $stmt = $pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            
            $invoices = $stmt->fetchAll();
            
            // Calculate totals
            $total_sum = 0;
            $paid_sum = 0;
            $open_sum = 0;
            foreach ($invoices as &$invoice) {
                $total_sum += $invoice['total'];
                $paid_sum += $invoice['paid_amount'];
                $open_sum += ($invoice['total'] - $invoice['paid_amount']);
            }
            
            echo json_encode([
                "status" => "success",
                "data" => [
                    'invoices' => $invoices,
                    'summary' => [
                        'total_sum' => $total_sum,
                        'paid_sum' => $paid_sum,
                        'open_sum' => $open_sum,
                        'count' => count($invoices)
                    ]
                ],
                "message" => count($invoices) . " Rechnungen gefunden"
            ]);
            break;
            
        case 'get_by_id':
            $id = intval($_GET['id'] ?? 0);
            
            if (!$id) {
                throw new Exception('Rechnung ID fehlt');
            }
            
            $sql = "SELECT i.*, 
                    o.first_name as owner_first_name,
                    o.last_name as owner_last_name,
                    o.customer_number,
                    o.email as owner_email,
                    o.phone as owner_phone,
                    o.street, o.house_number, o.postal_code, o.city, o.country,
                    p.name as patient_name,
                    p.species as patient_species
                    FROM tp_invoices i 
                    LEFT JOIN tp_owners o ON i.owner_id = o.id
                    LEFT JOIN tp_patients p ON i.patient_id = p.id
                    WHERE i.id = :id";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['id' => $id]);
            $invoice = $stmt->fetch();
            
            if (!$invoice) {
                throw new Exception('Rechnung nicht gefunden');
            }
            
            // Get invoice items
            $sql = "SELECT ii.*, t.treatment_date, t.treatment_methods 
                    FROM tp_invoice_items ii
                    LEFT JOIN tp_treatments t ON ii.treatment_id = t.id
                    WHERE ii.invoice_id = :invoice_id
                    ORDER BY ii.position, ii.id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['invoice_id' => $id]);
            $invoice['items'] = $stmt->fetchAll();
            
            echo json_encode([
                "status" => "success",
                "data" => $invoice,
                "message" => "Rechnung gefunden"
            ]);
            break;
            
        case 'create':
            if ($method !== 'POST') {
                throw new Exception('Nur POST-Methode erlaubt');
            }
            
            // Validate required fields
            if (empty($_POST['owner_id'])) {
                throw new Exception('Besitzer ID fehlt');
            }
            
            // Generate invoice number
            $stmt = $pdo->query("SELECT value FROM tp_settings WHERE `key` = 'next_invoice_number'");
            $next_number = $stmt->fetch()['value'] ?? '10001';
            $invoice_prefix = 'RE-';
            $invoice_number = $invoice_prefix . $next_number;
            
            // Update next invoice number
            $pdo->exec("UPDATE tp_settings SET value = '" . ($next_number + 1) . "' WHERE `key` = 'next_invoice_number'");
            
            // Calculate dates
            $invoice_date = $_POST['invoice_date'] ?? date('Y-m-d');
            $payment_terms = intval($_POST['payment_terms'] ?? 14);
            $due_date = date('Y-m-d', strtotime($invoice_date . ' + ' . $payment_terms . ' days'));
            
            // Start transaction
            $pdo->beginTransaction();
            
            try {
                // Create invoice
                $sql = "INSERT INTO tp_invoices (
                            invoice_number, owner_id, patient_id, invoice_date, due_date, 
                            status, payment_method, subtotal, tax_rate, tax_amount, 
                            discount_percent, discount_amount, total, notes, internal_notes, 
                            created_by
                        ) VALUES (
                            :invoice_number, :owner_id, :patient_id, :invoice_date, :due_date, 
                            :status, :payment_method, :subtotal, :tax_rate, :tax_amount, 
                            :discount_percent, :discount_amount, :total, :notes, :internal_notes, 
                            :created_by
                        )";
                
                $subtotal = floatval($_POST['subtotal'] ?? 0);
                $tax_rate = floatval($_POST['tax_rate'] ?? 19);
                $discount_percent = floatval($_POST['discount_percent'] ?? 0);
                
                $discount_amount = $subtotal * ($discount_percent / 100);
                $subtotal_after_discount = $subtotal - $discount_amount;
                $tax_amount = $subtotal_after_discount * ($tax_rate / 100);
                $total = $subtotal_after_discount + $tax_amount;
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    'invoice_number' => $invoice_number,
                    'owner_id' => $_POST['owner_id'],
                    'patient_id' => $_POST['patient_id'] ?? null,
                    'invoice_date' => $invoice_date,
                    'due_date' => $due_date,
                    'status' => $_POST['status'] ?? 'draft',
                    'payment_method' => $_POST['payment_method'] ?? null,
                    'subtotal' => $subtotal,
                    'tax_rate' => $tax_rate,
                    'tax_amount' => $tax_amount,
                    'discount_percent' => $discount_percent,
                    'discount_amount' => $discount_amount,
                    'total' => $total,
                    'notes' => $_POST['notes'] ?? null,
                    'internal_notes' => $_POST['internal_notes'] ?? null,
                    'created_by' => $_SESSION['user_id'] ?? 1
                ]);
                
                $invoiceId = $pdo->lastInsertId();
                
                // Add invoice items if provided
                if (!empty($_POST['items']) && is_array($_POST['items'])) {
                    $position = 0;
                    foreach ($_POST['items'] as $item) {
                        $sql = "INSERT INTO tp_invoice_items (
                                    invoice_id, treatment_id, description, quantity, unit, 
                                    price, tax_rate, discount_percent, total, position
                                ) VALUES (
                                    :invoice_id, :treatment_id, :description, :quantity, :unit, 
                                    :price, :tax_rate, :discount_percent, :total, :position
                                )";
                        
                        $item_quantity = floatval($item['quantity'] ?? 1);
                        $item_price = floatval($item['price'] ?? 0);
                        $item_tax_rate = floatval($item['tax_rate'] ?? $tax_rate);
                        $item_discount = floatval($item['discount_percent'] ?? 0);
                        
                        $item_subtotal = $item_quantity * $item_price;
                        $item_discount_amount = $item_subtotal * ($item_discount / 100);
                        $item_total = $item_subtotal - $item_discount_amount;
                        
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute([
                            'invoice_id' => $invoiceId,
                            'treatment_id' => $item['treatment_id'] ?? null,
                            'description' => $item['description'] ?? '',
                            'quantity' => $item_quantity,
                            'unit' => $item['unit'] ?? 'Stück',
                            'price' => $item_price,
                            'tax_rate' => $item_tax_rate,
                            'discount_percent' => $item_discount,
                            'total' => $item_total,
                            'position' => $position++
                        ]);
                    }
                }
                
                $pdo->commit();
                
                echo json_encode([
                    "status" => "success",
                    "data" => ["id" => $invoiceId, "invoice_number" => $invoice_number],
                    "message" => "Rechnung erfolgreich erstellt"
                ]);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;
            
        case 'update':
            if ($method !== 'POST' && $method !== 'PUT') {
                throw new Exception('Nur POST/PUT-Methode erlaubt');
            }
            
            $id = intval($_POST['id'] ?? $_GET['id'] ?? 0);
            if (!$id) {
                throw new Exception('Rechnung ID fehlt');
            }
            
            // Parse PUT data if necessary
            if ($method === 'PUT') {
                parse_str(file_get_contents("php://input"), $_POST);
            }
            
            // Recalculate totals if needed
            $subtotal = floatval($_POST['subtotal'] ?? 0);
            $tax_rate = floatval($_POST['tax_rate'] ?? 19);
            $discount_percent = floatval($_POST['discount_percent'] ?? 0);
            
            $discount_amount = $subtotal * ($discount_percent / 100);
            $subtotal_after_discount = $subtotal - $discount_amount;
            $tax_amount = $subtotal_after_discount * ($tax_rate / 100);
            $total = $subtotal_after_discount + $tax_amount;
            
            $sql = "UPDATE tp_invoices SET 
                        invoice_date = :invoice_date,
                        due_date = :due_date,
                        status = :status,
                        payment_method = :payment_method,
                        payment_date = :payment_date,
                        subtotal = :subtotal,
                        tax_rate = :tax_rate,
                        tax_amount = :tax_amount,
                        discount_percent = :discount_percent,
                        discount_amount = :discount_amount,
                        total = :total,
                        paid_amount = :paid_amount,
                        notes = :notes,
                        internal_notes = :internal_notes,
                        updated_at = NOW()
                    WHERE id = :id";
            
            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute([
                'id' => $id,
                'invoice_date' => $_POST['invoice_date'] ?? date('Y-m-d'),
                'due_date' => $_POST['due_date'] ?? date('Y-m-d', strtotime('+14 days')),
                'status' => $_POST['status'] ?? 'draft',
                'payment_method' => $_POST['payment_method'] ?? null,
                'payment_date' => $_POST['payment_date'] ?? null,
                'subtotal' => $subtotal,
                'tax_rate' => $tax_rate,
                'tax_amount' => $tax_amount,
                'discount_percent' => $discount_percent,
                'discount_amount' => $discount_amount,
                'total' => $total,
                'paid_amount' => $_POST['paid_amount'] ?? 0,
                'notes' => $_POST['notes'] ?? null,
                'internal_notes' => $_POST['internal_notes'] ?? null
            ]);
            
            echo json_encode([
                "status" => "success",
                "data" => ["id" => $id],
                "message" => "Rechnung erfolgreich aktualisiert"
            ]);
            break;
            
        case 'delete':
            if ($method !== 'DELETE' && $method !== 'POST') {
                throw new Exception('Nur DELETE/POST-Methode erlaubt');
            }
            
            $id = intval($_REQUEST['id'] ?? 0);
            if (!$id) {
                throw new Exception('Rechnung ID fehlt');
            }
            
            // Check if invoice can be deleted
            $stmt = $pdo->prepare("SELECT status FROM tp_invoices WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $invoice = $stmt->fetch();
            
            if (!$invoice) {
                throw new Exception('Rechnung nicht gefunden');
            }
            
            if (in_array($invoice['status'], ['paid', 'partially_paid'])) {
                throw new Exception('Bezahlte Rechnungen können nicht gelöscht werden');
            }
            
            // Delete invoice items first
            $pdo->prepare("DELETE FROM tp_invoice_items WHERE invoice_id = :id")->execute(['id' => $id]);
            
            // Delete invoice
            $sql = "DELETE FROM tp_invoices WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['id' => $id]);
            
            echo json_encode([
                "status" => "success",
                "data" => ["id" => $id],
                "message" => "Rechnung erfolgreich gelöscht"
            ]);
            break;
            
        case 'mark_paid':
            if ($method !== 'POST') {
                throw new Exception('Nur POST-Methode erlaubt');
            }
            
            $id = intval($_POST['id'] ?? 0);
            if (!$id) {
                throw new Exception('Rechnung ID fehlt');
            }
            
            $sql = "UPDATE tp_invoices SET 
                        status = 'paid',
                        payment_date = :payment_date,
                        payment_method = :payment_method,
                        paid_amount = total,
                        updated_at = NOW()
                    WHERE id = :id";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'id' => $id,
                'payment_date' => $_POST['payment_date'] ?? date('Y-m-d'),
                'payment_method' => $_POST['payment_method'] ?? 'transfer'
            ]);
            
            echo json_encode([
                "status" => "success",
                "data" => ["id" => $id],
                "message" => "Rechnung als bezahlt markiert"
            ]);
            break;
            
        case 'send_reminder':
            $id = intval($_POST['id'] ?? 0);
            if (!$id) {
                throw new Exception('Rechnung ID fehlt');
            }
            
            // Update reminder count
            $sql = "UPDATE tp_invoices SET 
                        reminder_count = reminder_count + 1,
                        last_reminder_date = CURDATE(),
                        updated_at = NOW()
                    WHERE id = :id";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['id' => $id]);
            
            // Here you would typically send an email reminder
            
            echo json_encode([
                "status" => "success",
                "data" => ["id" => $id],
                "message" => "Zahlungserinnerung versendet"
            ]);
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