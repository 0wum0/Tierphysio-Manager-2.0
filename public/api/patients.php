<?php
/**
 * Tierphysio Manager 2.0
 * Patients API Endpoint
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
            // Get all patients with optional filters
            $search = $_GET['q'] ?? $_GET['search'] ?? '';
            $species = $_GET['species'] ?? '';
            $page = max(1, intval($_GET['page'] ?? 1));
            $limit = intval($_GET['limit'] ?? 20);
            $offset = ($page - 1) * $limit;
            
            $sql = "SELECT p.*, 
                    o.first_name as owner_first_name, 
                    o.last_name as owner_last_name,
                    o.email as owner_email,
                    o.phone as owner_phone
                    FROM tp_patients p 
                    LEFT JOIN tp_owners o ON p.owner_id = o.id 
                    WHERE p.is_active = 1";
            
            $params = [];
            
            if ($search) {
                $sql .= " AND (p.name LIKE :search OR p.patient_number LIKE :search OR o.last_name LIKE :search)";
                $params['search'] = "%$search%";
            }
            
            if ($species) {
                $sql .= " AND p.species = :species";
                $params['species'] = $species;
            }
            
            $sql .= " ORDER BY p.created_at DESC LIMIT :limit OFFSET :offset";
            
            $stmt = $pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get total count
            $countSql = "SELECT COUNT(*) as total FROM tp_patients p 
                        LEFT JOIN tp_owners o ON p.owner_id = o.id 
                        WHERE p.is_active = 1";
            
            if ($search) {
                $countSql .= " AND (p.name LIKE :search OR p.patient_number LIKE :search OR o.last_name LIKE :search)";
            }
            
            if ($species) {
                $countSql .= " AND p.species = :species";
            }
            
            $countStmt = $pdo->prepare($countSql);
            foreach ($params as $key => $value) {
                $countStmt->bindValue($key, $value);
            }
            $countStmt->execute();
            $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            json_success([
                'patients' => $patients,
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
                json_error('Patient ID fehlt', 400);
            }
            
            $sql = "SELECT p.*, 
                    o.first_name as owner_first_name, 
                    o.last_name as owner_last_name,
                    o.customer_number as owner_customer_number,
                    o.email as owner_email,
                    o.phone as owner_phone,
                    o.mobile as owner_mobile,
                    o.street as owner_street,
                    o.house_number as owner_house_number,
                    o.postal_code as owner_postal_code,
                    o.city as owner_city
                    FROM tp_patients p 
                    LEFT JOIN tp_owners o ON p.owner_id = o.id 
                    WHERE p.id = :id";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['id' => $id]);
            $patient = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$patient) {
                json_error('Patient nicht gefunden', 404);
            }
            
            json_success($patient);
            break;
            
        case 'create':
            // Check CSRF for POST
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                json_error('Nur POST erlaubt', 405);
            }
            
            require_csrf();
            
            // Get POST data
            $data = get_post_data();
            
            // Validate required fields for patient
            validate_required($data, ['name', 'species']);
            
            // Handle owner creation or selection
            $ownerId = null;
            
            // Check if owner_id is provided
            if (!empty($data['owner_id'])) {
                $ownerId = intval($data['owner_id']);
            } else {
                // Check if owner data is provided for new owner creation
                if (!empty($data['owner_first_name']) && !empty($data['owner_last_name'])) {
                    // Check if owner already exists with same name and phone
                    $checkSql = "SELECT id FROM tp_owners WHERE first_name = :first_name 
                                AND last_name = :last_name";
                    $checkParams = [
                        'first_name' => sanitize_input($data['owner_first_name']),
                        'last_name' => sanitize_input($data['owner_last_name'])
                    ];
                    
                    // Add phone to check if provided
                    if (!empty($data['owner_phone'])) {
                        $checkSql .= " AND (phone = :phone OR mobile = :phone)";
                        $checkParams['phone'] = sanitize_input($data['owner_phone']);
                    }
                    
                    $stmt = $pdo->prepare($checkSql);
                    $stmt->execute($checkParams);
                    $existingOwner = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($existingOwner) {
                        // Use existing owner
                        $ownerId = $existingOwner['id'];
                    } else {
                        // Create new owner
                        // Generate customer number
                        $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(customer_number, 2) AS UNSIGNED)) as max_num FROM tp_owners WHERE customer_number LIKE 'K%'");
                        $maxNum = $stmt->fetch(PDO::FETCH_ASSOC)['max_num'] ?? 0;
                        $customerNumber = 'K' . str_pad($maxNum + 1, 5, '0', STR_PAD_LEFT);
                        
                        $ownerData = [
                            'customer_number' => $customerNumber,
                            'salutation' => sanitize_input($data['owner_salutation'] ?? 'Herr'),
                            'first_name' => sanitize_input($data['owner_first_name']),
                            'last_name' => sanitize_input($data['owner_last_name']),
                            'email' => filter_var($data['owner_email'] ?? '', FILTER_VALIDATE_EMAIL) ?: null,
                            'phone' => sanitize_input($data['owner_phone'] ?? null),
                            'mobile' => sanitize_input($data['owner_mobile'] ?? null),
                            'street' => sanitize_input($data['owner_street'] ?? null),
                            'house_number' => sanitize_input($data['owner_house_number'] ?? null),
                            'postal_code' => sanitize_input($data['owner_postal_code'] ?? null),
                            'city' => sanitize_input($data['owner_city'] ?? null),
                            'created_by' => $_SESSION['user_id'] ?? null,
                            'created_at' => date('Y-m-d H:i:s')
                        ];
                        
                        // Build owner insert query
                        $ownerColumns = array_keys($ownerData);
                        $ownerPlaceholders = array_map(function($col) { return ':' . $col; }, $ownerColumns);
                        
                        $ownerSql = "INSERT INTO tp_owners (" . implode(', ', $ownerColumns) . ") 
                                    VALUES (" . implode(', ', $ownerPlaceholders) . ")";
                        
                        $stmt = $pdo->prepare($ownerSql);
                        foreach ($ownerData as $key => $value) {
                            $stmt->bindValue(':' . $key, $value);
                        }
                        
                        if ($stmt->execute()) {
                            $ownerId = $pdo->lastInsertId();
                        } else {
                            json_error('Fehler beim Anlegen des Besitzers', 500);
                        }
                    }
                } else {
                    json_error('Besitzer-Informationen fehlen', 400);
                }
            }
            
            if (!$ownerId) {
                json_error('Kein Besitzer zugeordnet', 400);
            }
            
            // Generate patient number
            $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(patient_number, 2) AS UNSIGNED)) as max_num FROM tp_patients WHERE patient_number LIKE 'P%'");
            $maxNum = $stmt->fetch(PDO::FETCH_ASSOC)['max_num'] ?? 0;
            $patientNumber = 'P' . str_pad($maxNum + 1, 5, '0', STR_PAD_LEFT);
            
            // Prepare insert data
            $insertData = [
                'patient_number' => $patientNumber,
                'owner_id' => $ownerId,
                'name' => sanitize_input($data['name']),
                'species' => sanitize_input($data['species']),
                'breed' => sanitize_input($data['breed'] ?? null),
                'color' => sanitize_input($data['color'] ?? null),
                'gender' => sanitize_input($data['gender'] ?? 'unknown'),
                'birth_date' => $data['birth_date'] ?? null,
                'weight' => isset($data['weight']) && $data['weight'] !== '' ? floatval($data['weight']) : null,
                'microchip' => sanitize_input($data['microchip'] ?? null),
                'insurance_name' => sanitize_input($data['insurance_name'] ?? null),
                'insurance_number' => sanitize_input($data['insurance_number'] ?? null),
                'veterinarian' => sanitize_input($data['veterinarian'] ?? null),
                'veterinarian_phone' => sanitize_input($data['veterinarian_phone'] ?? null),
                'medical_history' => sanitize_input($data['medical_history'] ?? null),
                'allergies' => sanitize_input($data['allergies'] ?? null),
                'medications' => sanitize_input($data['medications'] ?? null),
                'notes' => sanitize_input($data['notes'] ?? null),
                'is_active' => 1,
                'created_by' => $_SESSION['user_id'] ?? null,
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            // Build insert query
            $columns = array_keys($insertData);
            $placeholders = array_map(function($col) { return ':' . $col; }, $columns);
            
            $sql = "INSERT INTO tp_patients (" . implode(', ', $columns) . ") 
                    VALUES (" . implode(', ', $placeholders) . ")";
            
            $stmt = $pdo->prepare($sql);
            
            foreach ($insertData as $key => $value) {
                $stmt->bindValue(':' . $key, $value);
            }
            
            if ($stmt->execute()) {
                $patientId = $pdo->lastInsertId();
                
                // Get the created patient with owner info
                $stmt = $pdo->prepare("SELECT p.*, 
                                      o.first_name as owner_first_name, 
                                      o.last_name as owner_last_name,
                                      o.email as owner_email,
                                      o.phone as owner_phone
                                      FROM tp_patients p 
                                      LEFT JOIN tp_owners o ON p.owner_id = o.id 
                                      WHERE p.id = :id");
                $stmt->execute(['id' => $patientId]);
                $patient = $stmt->fetch(PDO::FETCH_ASSOC);
                
                json_success([
                    'patient' => $patient,
                    'patient_id' => $patientId,
                    'owner_id' => $ownerId
                ], 'Patient erfolgreich angelegt');
            } else {
                json_error('Fehler beim Anlegen des Patienten', 500);
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
                json_error('Patient ID fehlt', 400);
            }
            
            // Check if patient exists
            $stmt = $pdo->prepare("SELECT * FROM tp_patients WHERE id = :id");
            $stmt->execute(['id' => $id]);
            if (!$stmt->fetch()) {
                json_error('Patient nicht gefunden', 404);
            }
            
            // Prepare update data
            $updateData = [];
            $allowedFields = [
                'owner_id', 'name', 'species', 'breed', 'color', 'gender',
                'birth_date', 'weight', 'microchip', 'insurance_name', 'insurance_number',
                'veterinarian', 'veterinarian_phone', 'medical_history', 'allergies',
                'medications', 'notes', 'is_active'
            ];
            
            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    if ($field === 'weight') {
                        $updateData[$field] = $data[$field] ? floatval($data[$field]) : null;
                    } elseif ($field === 'owner_id' || $field === 'is_active') {
                        $updateData[$field] = intval($data[$field]);
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
            
            $sql = "UPDATE tp_patients SET " . implode(', ', $setParts) . " WHERE id = :id";
            
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            
            foreach ($updateData as $key => $value) {
                $stmt->bindValue(':' . $key, $value);
            }
            
            if ($stmt->execute()) {
                // Get the updated patient
                $stmt = $pdo->prepare("SELECT * FROM tp_patients WHERE id = :id");
                $stmt->execute(['id' => $id]);
                $patient = $stmt->fetch(PDO::FETCH_ASSOC);
                
                json_success($patient, 'Patient erfolgreich aktualisiert');
            } else {
                json_error('Fehler beim Aktualisieren des Patienten', 500);
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
                json_error('Patient ID fehlt', 400);
            }
            
            // Check for related records
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tp_appointments WHERE patient_id = :id");
            $stmt->execute(['id' => $id]);
            $appointments = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tp_treatments WHERE patient_id = :id");
            $stmt->execute(['id' => $id]);
            $treatments = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tp_invoices WHERE patient_id = :id");
            $stmt->execute(['id' => $id]);
            $invoices = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            if ($appointments > 0 || $treatments > 0 || $invoices > 0) {
                // Soft delete - mark as inactive
                $stmt = $pdo->prepare("UPDATE tp_patients SET is_active = 0, updated_at = NOW() WHERE id = :id");
                if ($stmt->execute(['id' => $id])) {
                    json_success(null, 'Patient erfolgreich deaktiviert (hat verknüpfte Datensätze)');
                } else {
                    json_error('Fehler beim Deaktivieren des Patienten', 500);
                }
            } else {
                // Hard delete
                $stmt = $pdo->prepare("DELETE FROM tp_patients WHERE id = :id");
                if ($stmt->execute(['id' => $id])) {
                    json_success(null, 'Patient erfolgreich gelöscht');
                } else {
                    json_error('Fehler beim Löschen des Patienten', 500);
                }
            }
            break;
            
        default:
            json_error('Unbekannte Aktion: ' . $action, 400);
    }
    
} catch (PDOException $e) {
    error_log("API patients " . $action . ": " . $e->getMessage());
    ob_end_clean(); // Clear any output buffer
    json_error('Datenbankfehler aufgetreten', 500);
} catch (Throwable $e) {
    error_log("API patients " . $action . ": " . $e->getMessage());
    ob_end_clean(); // Clear any output buffer
    json_error('Unerwarteter Fehler: ' . $e->getMessage(), 500);
}

// Ensure no further output
exit;