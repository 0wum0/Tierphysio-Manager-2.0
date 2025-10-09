<?php
/**
 * Tierphysio Manager 2.0
 * Patients API Endpoint - Fixed with tp_ prefix, transactions & hardened
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

// Helper function to generate patient number
function generatePatientNumber($pdo) {
    do {
        $patient_number = 'P' . date('ymd') . rand(1000, 9999);
        $stmt = $pdo->prepare("SELECT id FROM tp_patients WHERE patient_number = ?");
        $stmt->execute([$patient_number]);
    } while ($stmt->fetch());
    
    return $patient_number;
}

// Helper function to generate customer number for new owner
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
    // Try to get database connection
    try {
        $pdo = get_pdo();
    } catch (Exception $dbError) {
        // If database connection fails, use mock data for development
        if (defined('APP_DEBUG') && APP_DEBUG) {
            require __DIR__ . '/patients_mock.php';
            exit;
        }
        throw $dbError;
    }
    
    switch ($action) {
        case 'list':
            try {
                $stmt = $pdo->prepare("
                    SELECT 
                        p.id,
                        p.patient_number,
                        p.name AS patient_name,
                        p.species,
                        p.breed,
                        p.gender,
                        p.birth_date,
                        p.created_at,
                        p.is_active,
                        o.id AS owner_id,
                        CASE 
                            WHEN o.id IS NULL THEN '—'
                            WHEN TRIM(CONCAT(IFNULL(o.first_name, ''), ' ', IFNULL(o.last_name, ''))) = '' THEN '—'
                            ELSE TRIM(CONCAT(IFNULL(o.first_name, ''), ' ', IFNULL(o.last_name, '')))
                        END AS owner_name,
                        o.customer_number AS owner_customer_number
                    FROM tp_patients p
                    LEFT JOIN tp_owners o ON p.owner_id = o.id
                    ORDER BY p.created_at DESC
                ");
                $stmt->execute();
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                ob_end_clean();
                echo json_encode([
                    'ok' => true,
                    'items' => $rows,
                    'count' => count($rows)
                ], JSON_UNESCAPED_UNICODE);
            } catch (PDOException $e) {
                ob_end_clean();
                http_response_code(500);
                echo json_encode(['ok' => false, 'error' => 'DB Error: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
            }
            exit;
            
        case 'get':
            $id = intval($_GET['id'] ?? 0);
            
            if (!$id) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'error' => 'Patient ID fehlt'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            $sql = "SELECT p.*, 
                    o.id as owner_id,
                    o.customer_number,
                    o.first_name as owner_first_name, 
                    o.last_name as owner_last_name,
                    o.phone as owner_phone,
                    o.mobile as owner_mobile,
                    o.email as owner_email,
                    o.street as owner_street,
                    o.house_number as owner_house_number,
                    o.postal_code as owner_postal_code,
                    o.city as owner_city
                    FROM tp_patients p 
                    LEFT JOIN tp_owners o ON p.owner_id = o.id 
                    WHERE p.id = :id";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['id' => $id]);
            $patient = $stmt->fetch();
            
            if (!$patient) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'error' => 'Patient nicht gefunden'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            // Get treatment history
            $stmt = $pdo->prepare("
                SELECT t.*, u.first_name as therapist_first_name, u.last_name as therapist_last_name
                FROM tp_treatments t
                LEFT JOIN tp_users u ON t.therapist_id = u.id
                WHERE t.patient_id = ?
                ORDER BY t.treatment_date DESC
                LIMIT 10
            ");
            $stmt->execute([$id]);
            $patient['recent_treatments'] = $stmt->fetchAll();
            
            ob_end_clean();
            echo json_encode(['ok' => true, 'data' => $patient], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'create':
            // Get input data (support both form-data and JSON)
            $input = $_POST;
            if (empty($input)) {
                $json = file_get_contents('php://input');
                $input = json_decode($json, true) ?? [];
            }
            
            // Patient data
            $patient_name = trim($input['name'] ?? $input['patient_name'] ?? '');
            $species = trim($input['species'] ?? 'other');
            $breed = trim($input['breed'] ?? '');
            $color = trim($input['color'] ?? '');
            $gender = trim($input['gender'] ?? 'unknown');
            $birth_date = trim($input['birth_date'] ?? $input['birthdate'] ?? '');
            $weight = floatval($input['weight'] ?? 0);
            $microchip = trim($input['microchip'] ?? '');
            $notes = trim($input['notes'] ?? '');
            $medical_history = trim($input['medical_history'] ?? '');
            $allergies = trim($input['allergies'] ?? '');
            $medications = trim($input['medications'] ?? '');
            
            // Owner data (if owner_id is 0 or null, create new owner)
            $owner_id = intval($input['owner_id'] ?? 0);
            
            // Validate required fields
            if (!$patient_name) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'error' => 'Patientenname ist erforderlich'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            if (!$species) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'error' => 'Tierart ist erforderlich'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            // Begin transaction
            $pdo->beginTransaction();
            
            try {
                // If no owner_id provided, create new owner
                if (!$owner_id) {
                    $owner_first = trim($input['owner_first_name'] ?? '');
                    $owner_last = trim($input['owner_last_name'] ?? '');
                    $owner_company = trim($input['owner_company'] ?? '');
                    $owner_phone = trim($input['owner_phone'] ?? '');
                    $owner_mobile = trim($input['owner_mobile'] ?? '');
                    $owner_email = trim($input['owner_email'] ?? '');
                    $owner_street = trim($input['owner_street'] ?? '');
                    $owner_house_number = trim($input['owner_house_number'] ?? '');
                    $owner_postal_code = trim($input['owner_postal_code'] ?? '');
                    $owner_city = trim($input['owner_city'] ?? '');
                    $owner_salutation = trim($input['owner_salutation'] ?? 'Herr');
                    
                    // Validate owner data
                    if ((!$owner_first || !$owner_last) && !$owner_company) {
                        $pdo->rollBack();
                        ob_end_clean();
                        echo json_encode(['ok' => false, 'error' => 'Besitzer: Vor-/Nachname oder Firma erforderlich'], JSON_UNESCAPED_UNICODE);
                        exit;
                    }
                    
                    // Generate customer number
                    $customer_number = generateCustomerNumber($pdo);
                    
                    // Create new owner
                    $stmt = $pdo->prepare("
                        INSERT INTO tp_owners (
                            customer_number, salutation, first_name, last_name, company,
                            phone, mobile, email, street, house_number, 
                            postal_code, city, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    
                    $stmt->execute([
                        $customer_number, $owner_salutation, $owner_first, $owner_last, $owner_company,
                        $owner_phone, $owner_mobile, $owner_email, $owner_street, $owner_house_number,
                        $owner_postal_code, $owner_city
                    ]);
                    
                    $owner_id = $pdo->lastInsertId();
                } else {
                    // Verify owner exists
                    $stmt = $pdo->prepare("SELECT id FROM tp_owners WHERE id = ?");
                    $stmt->execute([$owner_id]);
                    if (!$stmt->fetch()) {
                        $pdo->rollBack();
                        ob_end_clean();
                        echo json_encode(['ok' => false, 'error' => 'Besitzer nicht gefunden'], JSON_UNESCAPED_UNICODE);
                        exit;
                    }
                }
                
                // Generate patient number
                $patient_number = generatePatientNumber($pdo);
                
                // Create patient
                $stmt = $pdo->prepare("
                    INSERT INTO tp_patients (
                        patient_number, owner_id, name, species, breed, color,
                        gender, birth_date, weight, microchip, medical_history,
                        allergies, medications, notes, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                
                $stmt->execute([
                    $patient_number, $owner_id, $patient_name, $species, $breed, $color,
                    $gender, $birth_date ?: null, $weight ?: null, $microchip,
                    $medical_history, $allergies, $medications, $notes
                ]);
                
                $patient_id = $pdo->lastInsertId();
                
                // Commit transaction
                $pdo->commit();
                
                // Get created patient with owner info
                $stmt = $pdo->prepare("
                    SELECT p.*, o.first_name as owner_first_name, o.last_name as owner_last_name,
                           o.customer_number, o.email as owner_email
                    FROM tp_patients p
                    JOIN tp_owners o ON p.owner_id = o.id
                    WHERE p.id = ?
                ");
                $stmt->execute([$patient_id]);
                $patient = $stmt->fetch();
                
                ob_end_clean();
                echo json_encode([
                    'ok' => true,
                    'patient_id' => $patient_id,
                    'owner_id' => $owner_id,
                    'data' => $patient,
                    'message' => 'Patient erfolgreich angelegt'
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
                echo json_encode(['ok' => false, 'error' => 'Patient ID fehlt'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            // Check if patient exists
            $stmt = $pdo->prepare("SELECT * FROM tp_patients WHERE id = ?");
            $stmt->execute([$id]);
            if (!$stmt->fetch()) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'error' => 'Patient nicht gefunden'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            // Get update data
            $patient_name = trim($input['name'] ?? $input['patient_name'] ?? '');
            $species = trim($input['species'] ?? 'other');
            $breed = trim($input['breed'] ?? '');
            $color = trim($input['color'] ?? '');
            $gender = trim($input['gender'] ?? 'unknown');
            $birth_date = trim($input['birth_date'] ?? $input['birthdate'] ?? '');
            $weight = floatval($input['weight'] ?? 0);
            $microchip = trim($input['microchip'] ?? '');
            $notes = trim($input['notes'] ?? '');
            $medical_history = trim($input['medical_history'] ?? '');
            $allergies = trim($input['allergies'] ?? '');
            $medications = trim($input['medications'] ?? '');
            
            if (!$patient_name) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'error' => 'Patientenname ist erforderlich'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            // Update patient
            $stmt = $pdo->prepare("
                UPDATE tp_patients SET 
                    name=?, species=?, breed=?, color=?, gender=?,
                    birth_date=?, weight=?, microchip=?, notes=?,
                    medical_history=?, allergies=?, medications=?,
                    updated_at=NOW()
                WHERE id=?
            ");
            
            $stmt->execute([
                $patient_name, $species, $breed, $color, $gender,
                $birth_date ?: null, $weight ?: null, $microchip, $notes,
                $medical_history, $allergies, $medications, $id
            ]);
            
            // Get updated patient
            $stmt = $pdo->prepare("
                SELECT p.*, o.first_name as owner_first_name, o.last_name as owner_last_name
                FROM tp_patients p
                JOIN tp_owners o ON p.owner_id = o.id
                WHERE p.id = ?
            ");
            $stmt->execute([$id]);
            $patient = $stmt->fetch();
            
            ob_end_clean();
            echo json_encode([
                'ok' => true,
                'data' => $patient,
                'message' => 'Patient erfolgreich aktualisiert'
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
                echo json_encode(['ok' => false, 'error' => 'Patient ID fehlt'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            
            // Begin transaction
            $pdo->beginTransaction();
            
            try {
                // Delete related records (appointments, treatments, notes will cascade)
                // But let's check if there are any invoices first
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tp_invoices WHERE patient_id = ?");
                $stmt->execute([$id]);
                $result = $stmt->fetch();
                
                if ($result['count'] > 0) {
                    $pdo->rollBack();
                    ob_end_clean();
                    echo json_encode([
                        'ok' => false,
                        'error' => "Patient kann nicht gelöscht werden - hat noch " . $result['count'] . " Rechnung(en)"
                    ], JSON_UNESCAPED_UNICODE);
                    exit;
                }
                
                // Delete patient (cascades to appointments, treatments, notes)
                $stmt = $pdo->prepare("DELETE FROM tp_patients WHERE id=?");
                $stmt->execute([$id]);
                
                $pdo->commit();
                
                ob_end_clean();
                echo json_encode([
                    'ok' => true,
                    'message' => 'Patient erfolgreich gelöscht'
                ], JSON_UNESCAPED_UNICODE);
                
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;
            
        default:
            ob_end_clean();
            echo json_encode(['ok' => false, 'error' => "Unbekannte Aktion: " . $action], JSON_UNESCAPED_UNICODE);
    }
    
} catch (PDOException $e) {
    error_log("Patients API PDO Error (" . $action . "): " . $e->getMessage());
    ob_end_clean();
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Datenbankfehler aufgetreten',
        'details' => APP_DEBUG ? $e->getMessage() : null
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log("Patients API Error (" . $action . "): " . $e->getMessage());
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