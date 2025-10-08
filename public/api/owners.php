<?php
/**
 * Tierphysio Manager 2.0
 * Owners API Endpoint - Fixed with tp_ prefix & hardened
 */

// Set JSON header immediately
header('Content-Type: application/json; charset=utf-8');

// Error reporting for production
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Catch any output errors
ob_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/response.php';

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
            // Pagination parameters
            $page = max(1, intval($_GET['page'] ?? 1));
            $per_page = max(1, min(100, intval($_GET['per_page'] ?? 20)));
            $offset = ($page - 1) * $per_page;
            
            // Get total count
            $count_sql = "SELECT COUNT(*) as total FROM tp_owners";
            $stmt = $pdo->query($count_sql);
            $total = $stmt->fetch()['total'];
            
            // Get owners with patient count
            $sql = "SELECT o.*, 
                    COUNT(p.id) as patient_count
                    FROM tp_owners o 
                    LEFT JOIN tp_patients p ON o.id = p.owner_id
                    GROUP BY o.id
                    ORDER BY o.created_at DESC
                    LIMIT :limit OFFSET :offset";
            
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $owners = $stmt->fetchAll();
            
            ob_end_clean();
            echo json_encode([
                'ok' => true,
                'data' => $owners,
                'pagination' => [
                    'page' => $page,
                    'per_page' => $per_page,
                    'total' => $total,
                    'total_pages' => ceil($total / $per_page)
                ]
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'get':
            $id = intval($_GET['id'] ?? 0);
            
            if (!$id) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'error' => 'Besitzer ID fehlt'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            // Get owner with patients
            $stmt = $pdo->prepare("SELECT * FROM tp_owners WHERE id = ?");
            $stmt->execute([$id]);
            $owner = $stmt->fetch();
            
            if (!$owner) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'error' => 'Besitzer nicht gefunden'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            // Get owner's patients
            $stmt = $pdo->prepare("SELECT * FROM tp_patients WHERE owner_id = ? ORDER BY name");
            $stmt->execute([$id]);
            $owner['patients'] = $stmt->fetchAll();
            
            ob_end_clean();
            echo json_encode(['ok' => true, 'data' => $owner], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'create':
            // Get POST data (support both form-data and JSON)
            $input = $_POST;
            if (empty($input)) {
                $json = file_get_contents('php://input');
                $input = json_decode($json, true) ?? [];
            }
            
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
            $country = trim($input['country'] ?? 'Deutschland');
            $notes = trim($input['notes'] ?? '');
            $salutation = $input['salutation'] ?? 'Herr';
            
            // Validate required fields (first_name & last_name OR company)
            if ((!$first_name || !$last_name) && !$company) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'error' => 'Vor-/Nachname oder Firma sind erforderlich'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            // Begin transaction
            $pdo->beginTransaction();
            
            try {
                // Generate customer number
                $customer_number = generateCustomerNumber($pdo);
                
                // Create owner
                $stmt = $pdo->prepare("
                    INSERT INTO tp_owners (
                        customer_number, salutation, first_name, last_name, company,
                        email, phone, mobile, street, house_number, postal_code, 
                        city, country, notes, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                
                $stmt->execute([
                    $customer_number, $salutation, $first_name, $last_name, $company,
                    $email, $phone, $mobile, $street, $house_number, $postal_code, 
                    $city, $country, $notes
                ]);
                
                $owner_id = $pdo->lastInsertId();
                
                // Commit transaction
                $pdo->commit();
                
                // Get created owner
                $stmt = $pdo->prepare("SELECT * FROM tp_owners WHERE id = ?");
                $stmt->execute([$owner_id]);
                $owner = $stmt->fetch();
                
                ob_end_clean();
                echo json_encode([
                    'ok' => true, 
                    'owner_id' => $owner_id,
                    'data' => $owner,
                    'message' => 'Besitzer erfolgreich angelegt'
                ], JSON_UNESCAPED_UNICODE);
                
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
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
                ob_end_clean();
                echo json_encode(['ok' => false, 'error' => 'Besitzer ID fehlt'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            // Check if owner exists
            $stmt = $pdo->prepare("SELECT * FROM tp_owners WHERE id = ?");
            $stmt->execute([$id]);
            if (!$stmt->fetch()) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'error' => 'Besitzer nicht gefunden'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            // Get update data
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
            $country = trim($input['country'] ?? 'Deutschland');
            $notes = trim($input['notes'] ?? '');
            $salutation = $input['salutation'] ?? 'Herr';
            
            if ((!$first_name || !$last_name) && !$company) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'error' => 'Vor-/Nachname oder Firma sind erforderlich'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            // Update owner
            $stmt = $pdo->prepare("
                UPDATE tp_owners SET 
                    salutation=?, first_name=?, last_name=?, company=?, 
                    phone=?, mobile=?, email=?, street=?, house_number=?, 
                    postal_code=?, city=?, country=?, notes=?, updated_at=NOW() 
                WHERE id=?
            ");
            
            $stmt->execute([
                $salutation, $first_name, $last_name, $company, 
                $phone, $mobile, $email, $street, $house_number, 
                $postal_code, $city, $country, $notes, $id
            ]);
            
            // Get updated owner
            $stmt = $pdo->prepare("SELECT * FROM tp_owners WHERE id = ?");
            $stmt->execute([$id]);
            $owner = $stmt->fetch();
            
            ob_end_clean();
            echo json_encode([
                'ok' => true,
                'data' => $owner,
                'message' => 'Besitzer erfolgreich aktualisiert'
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'delete':
            // Get input data
            $input = $_POST;
            if (empty($input)) {
                $json = file_get_contents('php://input');
                $input = json_decode($json, true) ?? [];
            }
            
            $id = intval($input['id'] ?? 0);
            
            if (!$id) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'error' => 'Besitzer ID fehlt'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            // Check if owner has patients
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tp_patients WHERE owner_id = ?");
            $stmt->execute([$id]);
            $result = $stmt->fetch();
            
            if ($result['count'] > 0) {
                ob_end_clean();
                echo json_encode([
                    'ok' => false, 
                    'error' => "Besitzer kann nicht gelöscht werden - hat noch " . $result['count'] . " Patient(en)"
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            // Delete owner
            $stmt = $pdo->prepare("DELETE FROM tp_owners WHERE id=?");
            $stmt->execute([$id]);
            
            ob_end_clean();
            echo json_encode([
                'ok' => true,
                'message' => 'Besitzer erfolgreich gelöscht'
            ], JSON_UNESCAPED_UNICODE);
            break;
            
        default:
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => "Unbekannte Aktion: " . $action], JSON_UNESCAPED_UNICODE);
    }
    
} catch (PDOException $e) {
    error_log("Owners API PDO Error (" . $action . "): " . $e->getMessage());
    ob_end_clean();
    http_response_code(500);
    echo json_encode([
        'ok' => false, 
        'error' => 'Datenbankfehler aufgetreten',
        'details' => APP_DEBUG ? $e->getMessage() : null
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log("Owners API Error (" . $action . "): " . $e->getMessage());
    ob_end_clean();
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Serverfehler aufgetreten',
        'details' => APP_DEBUG ? $e->getMessage() : null
    ], JSON_UNESCAPED_UNICODE);
}

// Ensure no further output
exit;