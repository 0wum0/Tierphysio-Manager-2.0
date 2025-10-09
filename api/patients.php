<?php
/**
 * Tierphysio Manager 2.0
 * Patients API Endpoint - Hardened with tp_ prefix & proper JSON responses
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
    // Get database connection
    $pdo = get_pdo();
    
    switch ($action) {
        case 'list':
            $stmt = $pdo->prepare("
                SELECT 
                    p.id,
                    p.patient_number,
                    p.name,
                    p.species,
                    p.breed,
                    p.gender,
                    p.birth_date,
                    p.weight,
                    p.microchip,
                    p.is_active,
                    p.created_at,
                    o.id AS owner_id,
                    CONCAT_WS(' ', o.first_name, o.last_name) AS owner_full_name,
                    o.customer_number AS owner_customer_number,
                    o.phone AS owner_phone,
                    o.email AS owner_email
                FROM tp_patients p
                LEFT JOIN tp_owners o ON o.id = p.owner_id
                ORDER BY p.created_at DESC
            ");
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format data
            foreach ($rows as &$row) {
                $row['owner_full_name'] = $row['owner_full_name'] ?: '—';
                $row['is_active'] = (bool) $row['is_active'];
            }
            
            api_success(['items' => $rows, 'count' => count($rows)]);
            break;
            
        case 'get':
            $id = intval($_GET['id'] ?? 0);
            
            if (!$id) {
                api_error('Patient ID fehlt', 400);
            }
            
            $stmt = $pdo->prepare("
                SELECT p.*, 
                    CONCAT_WS(' ', o.first_name, o.last_name) AS owner_full_name,
                    o.customer_number,
                    o.first_name AS owner_first_name,
                    o.last_name AS owner_last_name,
                    o.phone AS owner_phone,
                    o.mobile AS owner_mobile,
                    o.email AS owner_email,
                    o.street AS owner_street,
                    o.house_number AS owner_house_number,
                    o.postal_code AS owner_postal_code,
                    o.city AS owner_city
                FROM tp_patients p
                LEFT JOIN tp_owners o ON o.id = p.owner_id
                WHERE p.id = :id
            ");
            $stmt->execute(['id' => $id]);
            $patient = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$patient) {
                api_error('Patient nicht gefunden', 404);
            }
            
            // Get treatment history
            $stmt = $pdo->prepare("
                SELECT t.*, u.first_name AS therapist_first_name, u.last_name AS therapist_last_name
                FROM tp_treatments t
                LEFT JOIN tp_users u ON t.therapist_id = u.id
                WHERE t.patient_id = ?
                ORDER BY t.treatment_date DESC
                LIMIT 10
            ");
            $stmt->execute([$id]);
            $patient['recent_treatments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            api_success($patient);
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
            
            // Owner handling
            $owner_mode = trim($input['owner_mode'] ?? 'existing');
            $owner_id = intval($input['owner_id'] ?? 0);
            
            // Validate required fields
            if (!$patient_name) {
                api_error('Patientenname ist erforderlich', 400);
            }
            
            if (!$species) {
                api_error('Tierart ist erforderlich', 400);
            }
            
            // Begin transaction
            $pdo->beginTransaction();
            
            try {
                // Handle owner creation/validation
                if ($owner_mode === 'new') {
                    // Create new owner
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
                    if (!$owner_first && !$owner_last && !$owner_company) {
                        $pdo->rollBack();
                        api_error('Besitzer: Vor-/Nachname oder Firma erforderlich', 400);
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
                } else if ($owner_mode === 'existing') {
                    // Verify owner exists
                    if (!$owner_id) {
                        $pdo->rollBack();
                        api_error('Besitzer ID erforderlich', 400);
                    }
                    
                    $stmt = $pdo->prepare("SELECT id FROM tp_owners WHERE id = ?");
                    $stmt->execute([$owner_id]);
                    if (!$stmt->fetch()) {
                        $pdo->rollBack();
                        api_error('Besitzer nicht gefunden', 404);
                    }
                }
                
                // Generate patient number
                $patient_number = generatePatientNumber($pdo);
                
                // Create patient
                $stmt = $pdo->prepare("
                    INSERT INTO tp_patients (
                        patient_number, owner_id, name, species, breed, color,
                        gender, birth_date, weight, microchip, medical_history,
                        allergies, medications, notes, is_active, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
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
                    SELECT p.*, 
                        CONCAT_WS(' ', o.first_name, o.last_name) AS owner_full_name,
                        o.customer_number, o.email AS owner_email
                    FROM tp_patients p
                    LEFT JOIN tp_owners o ON p.owner_id = o.id
                    WHERE p.id = ?
                ");
                $stmt->execute([$patient_id]);
                $patient = $stmt->fetch(PDO::FETCH_ASSOC);
                
                api_success([
                    'patient_id' => $patient_id,
                    'owner_id' => $owner_id,
                    'patient' => $patient
                ], 201);
                
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
                api_error('Patient ID fehlt', 400);
            }
            
            // Check if patient exists
            $stmt = $pdo->prepare("SELECT * FROM tp_patients WHERE id = ?");
            $stmt->execute([$id]);
            if (!$stmt->fetch()) {
                api_error('Patient nicht gefunden', 404);
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
                api_error('Patientenname ist erforderlich', 400);
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
                SELECT p.*, 
                    CONCAT_WS(' ', o.first_name, o.last_name) AS owner_full_name
                FROM tp_patients p
                LEFT JOIN tp_owners o ON p.owner_id = o.id
                WHERE p.id = ?
            ");
            $stmt->execute([$id]);
            $patient = $stmt->fetch(PDO::FETCH_ASSOC);
            
            api_success(['patient' => $patient, 'message' => 'Patient erfolgreich aktualisiert']);
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
                api_error('Patient ID fehlt', 400);
            }
            
            // Begin transaction
            $pdo->beginTransaction();
            
            try {
                // Check for related invoices
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tp_invoices WHERE patient_id = ?");
                $stmt->execute([$id]);
                $result = $stmt->fetch();
                
                if ($result['count'] > 0) {
                    $pdo->rollBack();
                    api_error("Patient kann nicht gelöscht werden - hat noch " . $result['count'] . " Rechnung(en)", 400);
                }
                
                // Delete patient (cascades to appointments, treatments, notes)
                $stmt = $pdo->prepare("DELETE FROM tp_patients WHERE id=?");
                $stmt->execute([$id]);
                
                $pdo->commit();
                
                api_success(['message' => 'Patient erfolgreich gelöscht']);
                
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;
            
        default:
            api_error("Unbekannte Aktion: " . $action, 400);
    }
    
} catch (PDOException $e) {
    error_log("Patients API PDO Error (" . $action . "): " . $e->getMessage());
    api_error('Datenbankfehler aufgetreten', 500, ['details' => APP_DEBUG ? $e->getMessage() : null]);
} catch (Throwable $e) {
    error_log("Patients API Error (" . $action . "): " . $e->getMessage());
    api_error('Serverfehler aufgetreten', 500, ['details' => APP_DEBUG ? $e->getMessage() : null]);
}

// Should never reach here
exit;