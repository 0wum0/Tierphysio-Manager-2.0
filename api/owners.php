<?php
/**
 * Tierphysio Manager 2.0
 * Owners API Endpoint - Unified JSON Response Format
 */

require_once __DIR__ . '/_bootstrap.php';

// Get action from request
$action = $_GET['action'] ?? 'list';

try {
    $pdo = get_pdo();
    
    switch ($action) {
        case 'list':
            // Optional search parameter
            $search = trim($_GET['search'] ?? '');
            
            // Build query - only select minimal fields for list view
            if ($search) {
                $searchTerm = '%' . $search . '%';
                $stmt = $pdo->prepare("
                    SELECT 
                        id,
                        first_name,
                        last_name,
                        email,
                        phone
                    FROM tp_owners
                    WHERE first_name LIKE :search 
                       OR last_name LIKE :search
                       OR email LIKE :search
                       OR phone LIKE :search
                    ORDER BY last_name, first_name
                ");
                $stmt->execute(['search' => $searchTerm]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT 
                        id,
                        first_name,
                        last_name,
                        email,
                        phone
                    FROM tp_owners
                    ORDER BY last_name, first_name
                ");
                $stmt->execute();
            }
            
            $owners = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $total = count($owners);
            
            api_success(['items' => $owners, 'count' => $total]);
            break;
            
        case 'get':
            $id = intval($_GET['id'] ?? 0);
            
            if (!$id) {
                api_error('Besitzer ID fehlt', 400);
            }
            
            // Get owner with patients
            $stmt = $pdo->prepare("SELECT * FROM tp_owners WHERE id = ?");
            $stmt->execute([$id]);
            $owner = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$owner) {
                api_error('Besitzer nicht gefunden', 404);
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
            
            api_success(['items' => [$owner]]);
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
                api_error('Vor-/Nachname oder Firma erforderlich', 400);
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
            
            api_success(['items' => [$owner]]);
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
                api_error('Besitzer ID fehlt', 400);
            }
            
            // Check if owner exists
            $stmt = $pdo->prepare("SELECT * FROM tp_owners WHERE id = ?");
            $stmt->execute([$id]);
            if (!$stmt->fetch()) {
                api_error('Besitzer nicht gefunden', 404);
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
                api_error('Vor-/Nachname oder Firma erforderlich', 400);
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
            
            api_success(['items' => [$owner]]);
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
                api_error('Besitzer ID fehlt', 400);
            }
            
            // Check for related patients
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tp_patients WHERE owner_id = ?");
            $stmt->execute([$id]);
            $result = $stmt->fetch();
            
            if ($result['count'] > 0) {
                api_error("Besitzer kann nicht gelöscht werden - hat noch " . $result['count'] . " Patient(en)", 400);
            }
            
            // Delete owner
            $stmt = $pdo->prepare("DELETE FROM tp_owners WHERE id=?");
            $stmt->execute([$id]);
            
            api_success(['items' => []]);
            break;
            
        case 'search':
            $term = trim($_GET['term'] ?? '');
            
            if (strlen($term) < 2) {
                api_success(['data' => [], 'count' => 0]);
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
            
            api_success(['items' => $items, 'count' => count($items)]);
            break;
            
        default:
            api_error("Unbekannte Aktion: " . $action, 400);
    }
    
} catch (PDOException $e) {
    error_log("Owners API PDO Error (" . $action . "): " . $e->getMessage());
    api_error('Datenbankfehler aufgetreten');
} catch (Throwable $e) {
    error_log("Owners API Error (" . $action . "): " . $e->getMessage());
    api_error('Serverfehler aufgetreten');
}

// Should never reach here
exit;