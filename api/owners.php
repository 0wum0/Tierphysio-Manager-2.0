<?php
/**
 * Tierphysio Manager 2.0
 * Owners API Endpoint - Hardened with tp_ prefix & proper JSON responses
 */

// Set JSON header immediately
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Error reporting for production
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Clear any existing output
if (ob_get_length()) ob_end_clean();
ob_start();

require_once __DIR__ . '/../includes/db.php';

// Unified JSON responders
function json_ok($data = [], $code = 200) {
    if (ob_get_length()) ob_end_clean();
    http_response_code($code);
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function json_err($msg, $code = 400, $extra = []) {
    if (ob_get_length()) ob_end_clean();
    http_response_code($code);
    $response = ['ok' => false, 'error' => $msg];
    if (!empty($extra)) {
        $response = array_merge($response, $extra);
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// Helper function to generate customer number
function generateCustomerNumber($pdo) {
    do {
        $customer_number = 'K' . date('ymd') . rand(1000, 9999);
        $stmt = $pdo->prepare("SELECT id FROM tp_owners WHERE customer_number = ?");
        $stmt->execute([$customer_number]);
    } while ($stmt->fetch());
    
    return $customer_number;
}

// Get action from request
$action = $_GET['action'] ?? 'list';

try {
    $pdo = get_pdo();
    
    switch ($action) {
        case 'list':
            // Optional search parameter
            $search = trim($_GET['search'] ?? '');
            
            // Build query
            if ($search) {
                $searchTerm = '%' . $search . '%';
                $stmt = $pdo->prepare("
                    SELECT 
                        o.id,
                        o.customer_number,
                        o.salutation,
                        o.first_name,
                        o.last_name,
                        o.company,
                        o.email,
                        o.phone,
                        o.mobile,
                        o.street,
                        o.house_number,
                        o.postal_code,
                        o.city,
                        o.created_at,
                        COUNT(p.id) AS patient_count
                    FROM tp_owners o
                    LEFT JOIN tp_patients p ON o.id = p.owner_id
                    WHERE o.first_name LIKE :search 
                       OR o.last_name LIKE :search
                       OR o.company LIKE :search
                       OR o.email LIKE :search
                       OR o.customer_number LIKE :search
                    GROUP BY o.id
                    ORDER BY o.created_at DESC
                ");
                $stmt->execute(['search' => $searchTerm]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT 
                        o.id,
                        o.customer_number,
                        o.salutation,
                        o.first_name,
                        o.last_name,
                        o.company,
                        o.email,
                        o.phone,
                        o.mobile,
                        o.street,
                        o.house_number,
                        o.postal_code,
                        o.city,
                        o.created_at,
                        COUNT(p.id) AS patient_count
                    FROM tp_owners o
                    LEFT JOIN tp_patients p ON o.id = p.owner_id
                    GROUP BY o.id
                    ORDER BY o.created_at DESC
                ");
                $stmt->execute();
            }
            
            $owners = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format response
            $items = [];
            foreach ($owners as $owner) {
                $items[] = [
                    'id' => $owner['id'],
                    'customer_number' => $owner['customer_number'],
                    'salutation' => $owner['salutation'],
                    'first_name' => $owner['first_name'],
                    'last_name' => $owner['last_name'],
                    'full_name' => trim($owner['first_name'] . ' ' . $owner['last_name']),
                    'company' => $owner['company'],
                    'email' => $owner['email'],
                    'phone' => $owner['phone'],
                    'mobile' => $owner['mobile'],
                    'street' => $owner['street'],
                    'house_number' => $owner['house_number'],
                    'postal_code' => $owner['postal_code'],
                    'city' => $owner['city'],
                    'patient_count' => intval($owner['patient_count']),
                    'created_at' => $owner['created_at']
                ];
            }
            
            json_ok(['items' => $items, 'total' => count($items)]);
            break;
            
        case 'get':
            $id = intval($_GET['id'] ?? 0);
            
            if (!$id) {
                json_err('Besitzer ID fehlt', 400);
            }
            
            // Get owner with patients
            $stmt = $pdo->prepare("SELECT * FROM tp_owners WHERE id = ?");
            $stmt->execute([$id]);
            $owner = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$owner) {
                json_err('Besitzer nicht gefunden', 404);
            }
            
            // Get owner's patients
            $stmt = $pdo->prepare("
                SELECT id, patient_number, name, species, breed, gender, birth_date, is_active 
                FROM tp_patients 
                WHERE owner_id = ? 
                ORDER BY name
            ");
            $stmt->execute([$id]);
            $owner['patients'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            json_ok($owner);
            break;
            
        case 'create':
            // Get input data
            $input = $_POST;
            if (empty($input)) {
                $json = file_get_contents('php://input');
                $input = json_decode($json, true) ?? [];
            }
            
            // Get owner data
            $salutation = trim($input['salutation'] ?? 'Herr');
            $first_name = trim($input['first_name'] ?? '');
            $last_name = trim($input['last_name'] ?? '');
            $company = trim($input['company'] ?? '');
            $phone = trim($input['phone'] ?? '');
            $mobile = trim($input['mobile'] ?? '');
            $email = trim($input['email'] ?? '');
            $street = trim($input['street'] ?? '');
            $house_number = trim($input['house_number'] ?? '');
            $postal_code = trim($input['postal_code'] ?? '');
            $city = trim($input['city'] ?? '');
            $notes = trim($input['notes'] ?? '');
            
            // Validate required fields
            if (!$first_name && !$last_name && !$company) {
                json_err('Vor-/Nachname oder Firma erforderlich', 400);
            }
            
            // Generate customer number
            $customer_number = generateCustomerNumber($pdo);
            
            // Create owner
            $stmt = $pdo->prepare("
                INSERT INTO tp_owners (
                    customer_number, salutation, first_name, last_name, company,
                    phone, mobile, email, street, house_number, 
                    postal_code, city, notes, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $customer_number, $salutation, $first_name, $last_name, $company,
                $phone, $mobile, $email, $street, $house_number,
                $postal_code, $city, $notes
            ]);
            
            $owner_id = $pdo->lastInsertId();
            
            // Get created owner
            $stmt = $pdo->prepare("SELECT * FROM tp_owners WHERE id = ?");
            $stmt->execute([$owner_id]);
            $owner = $stmt->fetch(PDO::FETCH_ASSOC);
            
            json_ok(['owner' => $owner, 'owner_id' => $owner_id], 201);
            break;
            
        case 'update':
            // Get input data
            $input = $_POST;
            if (empty($input)) {
                $json = file_get_contents('php://input');
                $input = json_decode($json, true) ?? [];
            }
            
            $id = intval($input['id'] ?? 0);
            
            if (!$id) {
                json_err('Besitzer ID fehlt', 400);
            }
            
            // Check if owner exists
            $stmt = $pdo->prepare("SELECT * FROM tp_owners WHERE id = ?");
            $stmt->execute([$id]);
            if (!$stmt->fetch()) {
                json_err('Besitzer nicht gefunden', 404);
            }
            
            // Get update data
            $salutation = trim($input['salutation'] ?? 'Herr');
            $first_name = trim($input['first_name'] ?? '');
            $last_name = trim($input['last_name'] ?? '');
            $company = trim($input['company'] ?? '');
            $phone = trim($input['phone'] ?? '');
            $mobile = trim($input['mobile'] ?? '');
            $email = trim($input['email'] ?? '');
            $street = trim($input['street'] ?? '');
            $house_number = trim($input['house_number'] ?? '');
            $postal_code = trim($input['postal_code'] ?? '');
            $city = trim($input['city'] ?? '');
            $notes = trim($input['notes'] ?? '');
            
            // Validate
            if (!$first_name && !$last_name && !$company) {
                json_err('Vor-/Nachname oder Firma erforderlich', 400);
            }
            
            // Update owner
            $stmt = $pdo->prepare("
                UPDATE tp_owners SET 
                    salutation=?, first_name=?, last_name=?, company=?,
                    phone=?, mobile=?, email=?, street=?, house_number=?,
                    postal_code=?, city=?, notes=?, updated_at=NOW()
                WHERE id=?
            ");
            
            $stmt->execute([
                $salutation, $first_name, $last_name, $company,
                $phone, $mobile, $email, $street, $house_number,
                $postal_code, $city, $notes, $id
            ]);
            
            // Get updated owner
            $stmt = $pdo->prepare("SELECT * FROM tp_owners WHERE id = ?");
            $stmt->execute([$id]);
            $owner = $stmt->fetch(PDO::FETCH_ASSOC);
            
            json_ok(['owner' => $owner, 'message' => 'Besitzer erfolgreich aktualisiert']);
            break;
            
        case 'delete':
            // Get input data
            $input = $_POST;
            if (empty($input)) {
                $json = file_get_contents('php://input');
                $input = json_decode($json, true) ?? [];
            }
            
            $id = intval($input['id'] ?? $_GET['id'] ?? 0);
            
            if (!$id) {
                json_err('Besitzer ID fehlt', 400);
            }
            
            // Check for related patients
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tp_patients WHERE owner_id = ?");
            $stmt->execute([$id]);
            $result = $stmt->fetch();
            
            if ($result['count'] > 0) {
                json_err("Besitzer kann nicht gelöscht werden - hat noch " . $result['count'] . " Patient(en)", 400);
            }
            
            // Delete owner
            $stmt = $pdo->prepare("DELETE FROM tp_owners WHERE id=?");
            $stmt->execute([$id]);
            
            json_ok(['message' => 'Besitzer erfolgreich gelöscht']);
            break;
            
        case 'search':
            $term = trim($_GET['term'] ?? '');
            
            if (strlen($term) < 2) {
                json_ok(['items' => []]);
            }
            
            $searchTerm = '%' . $term . '%';
            $stmt = $pdo->prepare("
                SELECT id, customer_number, first_name, last_name, company, email, phone
                FROM tp_owners
                WHERE first_name LIKE :search 
                   OR last_name LIKE :search
                   OR company LIKE :search
                   OR customer_number LIKE :search
                ORDER BY last_name, first_name
                LIMIT 20
            ");
            $stmt->execute(['search' => $searchTerm]);
            $owners = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format for select dropdown
            $items = [];
            foreach ($owners as $owner) {
                $name = trim($owner['first_name'] . ' ' . $owner['last_name']);
                if ($owner['company']) {
                    $name = $owner['company'] . ($name ? ' (' . $name . ')' : '');
                }
                $items[] = [
                    'id' => $owner['id'],
                    'text' => $name . ' - ' . $owner['customer_number'],
                    'customer_number' => $owner['customer_number'],
                    'email' => $owner['email'],
                    'phone' => $owner['phone'] ?: $owner['mobile']
                ];
            }
            
            json_ok(['items' => $items]);
            break;
            
        default:
            json_err("Unbekannte Aktion: " . $action, 400);
    }
    
} catch (PDOException $e) {
    error_log("Owners API PDO Error (" . $action . "): " . $e->getMessage());
    json_err('Datenbankfehler aufgetreten', 500, ['details' => APP_DEBUG ? $e->getMessage() : null]);
} catch (Throwable $e) {
    error_log("Owners API Error (" . $action . "): " . $e->getMessage());
    json_err('Serverfehler aufgetreten', 500, ['details' => APP_DEBUG ? $e->getMessage() : null]);
}

// Should never reach here
exit;