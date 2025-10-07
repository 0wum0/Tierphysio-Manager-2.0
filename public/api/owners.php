<?php
/**
 * Tierphysio Manager 2.0
 * Owners API Endpoint
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
            // Get all owners with optional filters
            $search = $_GET['q'] ?? $_GET['search'] ?? '';
            $page = max(1, intval($_GET['page'] ?? 1));
            $limit = intval($_GET['limit'] ?? 20);
            $offset = ($page - 1) * $limit;
            
            $sql = "SELECT o.*, 
                    COUNT(DISTINCT p.id) as patient_count,
                    COUNT(DISTINCT i.id) as invoice_count
                    FROM tp_owners o 
                    LEFT JOIN tp_patients p ON o.id = p.owner_id AND p.is_active = 1
                    LEFT JOIN tp_invoices i ON o.id = i.owner_id
                    WHERE 1=1";
            
            $params = [];
            
            if ($search) {
                $sql .= " AND (o.first_name LIKE :search OR o.last_name LIKE :search 
                         OR o.email LIKE :search OR o.customer_number LIKE :search 
                         OR o.phone LIKE :search OR o.mobile LIKE :search)";
                $params['search'] = "%$search%";
            }
            
            $sql .= " GROUP BY o.id ORDER BY o.last_name, o.first_name LIMIT :limit OFFSET :offset";
            
            $stmt = $pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $owners = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get total count
            $countSql = "SELECT COUNT(*) as total FROM tp_owners o WHERE 1=1";
            
            if ($search) {
                $countSql .= " AND (o.first_name LIKE :search OR o.last_name LIKE :search 
                             OR o.email LIKE :search OR o.customer_number LIKE :search 
                             OR o.phone LIKE :search OR o.mobile LIKE :search)";
            }
            
            $countStmt = $pdo->prepare($countSql);
            foreach ($params as $key => $value) {
                $countStmt->bindValue($key, $value);
            }
            $countStmt->execute();
            $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            json_success([
                'owners' => $owners,
                'pagination' => [
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => ceil($total / $limit)
                ]
            ]);
            break;
            
        case 'get':
            $id = intval($_GET['id'] ?? 0);
            
            if (!$id) {
                json_error('Besitzer ID fehlt', 400);
            }
            
            $sql = "SELECT o.*, 
                    COUNT(DISTINCT p.id) as patient_count,
                    COUNT(DISTINCT i.id) as invoice_count
                    FROM tp_owners o 
                    LEFT JOIN tp_patients p ON o.id = p.owner_id AND p.is_active = 1
                    LEFT JOIN tp_invoices i ON o.id = i.owner_id
                    WHERE o.id = :id
                    GROUP BY o.id";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['id' => $id]);
            $owner = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$owner) {
                json_error('Besitzer nicht gefunden', 404);
            }
            
            // Get patients
            $stmt = $pdo->prepare("SELECT * FROM tp_patients WHERE owner_id = :owner_id AND is_active = 1 ORDER BY name");
            $stmt->execute(['owner_id' => $id]);
            $owner['patients'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            json_success($owner);
            break;
            
        case 'create':
            // Check CSRF for POST
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                json_error('Nur POST erlaubt', 405);
            }
            
            require_csrf();
            
            // Get POST data
            $data = get_post_data();
            
            // Validate required fields
            validate_required($data, ['first_name', 'last_name']);
            
            // Generate customer number
            $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(customer_number, 2) AS UNSIGNED)) as max_num FROM tp_owners WHERE customer_number LIKE 'K%'");
            $maxNum = $stmt->fetch(PDO::FETCH_ASSOC)['max_num'] ?? 0;
            $customerNumber = 'K' . str_pad($maxNum + 1, 5, '0', STR_PAD_LEFT);
            
            // Prepare insert data
            $insertData = [
                'customer_number' => $customerNumber,
                'salutation' => sanitize_input($data['salutation'] ?? 'Herr'),
                'first_name' => sanitize_input($data['first_name']),
                'last_name' => sanitize_input($data['last_name']),
                'company' => sanitize_input($data['company'] ?? null),
                'email' => filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: null,
                'phone' => sanitize_input($data['phone'] ?? null),
                'mobile' => sanitize_input($data['mobile'] ?? null),
                'street' => sanitize_input($data['street'] ?? null),
                'house_number' => sanitize_input($data['house_number'] ?? null),
                'postal_code' => sanitize_input($data['postal_code'] ?? null),
                'city' => sanitize_input($data['city'] ?? null),
                'country' => sanitize_input($data['country'] ?? 'Deutschland'),
                'notes' => sanitize_input($data['notes'] ?? null),
                'newsletter' => isset($data['newsletter']) ? 1 : 0,
                'invoice_email' => filter_var($data['invoice_email'] ?? '', FILTER_VALIDATE_EMAIL) ?: null,
                'payment_method' => sanitize_input($data['payment_method'] ?? 'transfer'),
                'iban' => sanitize_input($data['iban'] ?? null),
                'bic' => sanitize_input($data['bic'] ?? null),
                'tax_number' => sanitize_input($data['tax_number'] ?? null),
                'created_by' => $_SESSION['user_id'] ?? null,
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            // Build insert query
            $columns = array_keys($insertData);
            $placeholders = array_map(function($col) { return ':' . $col; }, $columns);
            
            $sql = "INSERT INTO tp_owners (" . implode(', ', $columns) . ") 
                    VALUES (" . implode(', ', $placeholders) . ")";
            
            $stmt = $pdo->prepare($sql);
            
            foreach ($insertData as $key => $value) {
                $stmt->bindValue(':' . $key, $value);
            }
            
            if ($stmt->execute()) {
                $ownerId = $pdo->lastInsertId();
                
                // Get the created owner
                $stmt = $pdo->prepare("SELECT * FROM tp_owners WHERE id = :id");
                $stmt->execute(['id' => $ownerId]);
                $owner = $stmt->fetch(PDO::FETCH_ASSOC);
                
                json_success($owner, 'Besitzer erfolgreich angelegt');
            } else {
                json_error('Fehler beim Anlegen des Besitzers', 500);
            }
            break;
            
        case 'update':
            // Check CSRF for POST
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                json_error('Nur POST erlaubt', 405);
            }
            
            require_csrf();
            
            // Get POST data
            $data = get_post_data();
            
            // Validate ID
            $id = intval($data['id'] ?? 0);
            if (!$id) {
                json_error('Besitzer ID fehlt', 400);
            }
            
            // Check if owner exists
            $stmt = $pdo->prepare("SELECT * FROM tp_owners WHERE id = :id");
            $stmt->execute(['id' => $id]);
            if (!$stmt->fetch()) {
                json_error('Besitzer nicht gefunden', 404);
            }
            
            // Prepare update data
            $updateData = [];
            $allowedFields = [
                'salutation', 'first_name', 'last_name', 'company', 'email', 'phone', 'mobile',
                'street', 'house_number', 'postal_code', 'city', 'country', 'notes', 'newsletter',
                'invoice_email', 'payment_method', 'iban', 'bic', 'tax_number'
            ];
            
            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    if ($field === 'email' || $field === 'invoice_email') {
                        $updateData[$field] = filter_var($data[$field], FILTER_VALIDATE_EMAIL) ?: null;
                    } elseif ($field === 'newsletter') {
                        $updateData[$field] = $data[$field] ? 1 : 0;
                    } else {
                        $updateData[$field] = sanitize_input($data[$field]);
                    }
                }
            }
            
            if (empty($updateData)) {
                json_error('Keine Daten zum Aktualisieren', 400);
            }
            
            $updateData['updated_at'] = date('Y-m-d H:i:s');
            
            // Build update query
            $setParts = array_map(function($col) { return $col . ' = :' . $col; }, array_keys($updateData));
            
            $sql = "UPDATE tp_owners SET " . implode(', ', $setParts) . " WHERE id = :id";
            
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            
            foreach ($updateData as $key => $value) {
                $stmt->bindValue(':' . $key, $value);
            }
            
            if ($stmt->execute()) {
                // Get the updated owner
                $stmt = $pdo->prepare("SELECT * FROM tp_owners WHERE id = :id");
                $stmt->execute(['id' => $id]);
                $owner = $stmt->fetch(PDO::FETCH_ASSOC);
                
                json_success($owner, 'Besitzer erfolgreich aktualisiert');
            } else {
                json_error('Fehler beim Aktualisieren des Besitzers', 500);
            }
            break;
            
        case 'delete':
            // Check CSRF for POST
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                json_error('Nur POST erlaubt', 405);
            }
            
            require_csrf();
            
            // Get POST data
            $data = get_post_data();
            
            // Validate ID
            $id = intval($data['id'] ?? 0);
            if (!$id) {
                json_error('Besitzer ID fehlt', 400);
            }
            
            // Check for related records
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tp_patients WHERE owner_id = :id");
            $stmt->execute(['id' => $id]);
            $patients = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tp_invoices WHERE owner_id = :id");
            $stmt->execute(['id' => $id]);
            $invoices = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            if ($patients > 0 || $invoices > 0) {
                json_error('Besitzer kann nicht gelöscht werden (hat verknüpfte Patienten oder Rechnungen)', 400);
            } else {
                // Hard delete
                $stmt = $pdo->prepare("DELETE FROM tp_owners WHERE id = :id");
                if ($stmt->execute(['id' => $id])) {
                    json_success(null, 'Besitzer erfolgreich gelöscht');
                } else {
                    json_error('Fehler beim Löschen des Besitzers', 500);
                }
            }
            break;
            
        default:
            json_error('Unbekannte Aktion: ' . $action, 400);
    }
    
} catch (PDOException $e) {
    error_log("API owners " . $action . ": " . $e->getMessage());
    ob_end_clean(); // Clear any output buffer
    json_error('Datenbankfehler aufgetreten', 500);
} catch (Throwable $e) {
    error_log("API owners " . $action . ": " . $e->getMessage());
    ob_end_clean(); // Clear any output buffer
    json_error('Unerwarteter Fehler: ' . $e->getMessage(), 500);
}

// Ensure no further output
exit;