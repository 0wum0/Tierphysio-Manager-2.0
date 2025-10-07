<?php
/**
 * Tierphysio Manager 2.0
 * Patients API Endpoint
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
            // Get all patients with optional filters
            $search = $_GET['search'] ?? '';
            $species = $_GET['species'] ?? '';
            $limit = intval($_GET['limit'] ?? 100);
            $offset = intval($_GET['offset'] ?? 0);
            
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
            
            $patients = $stmt->fetchAll();
            
            echo json_encode([
                "status" => "success",
                "data" => $patients,
                "message" => count($patients) . " Patienten gefunden"
            ]);
            break;
            
        case 'get_by_id':
            $id = intval($_GET['id'] ?? 0);
            
            if (!$id) {
                throw new Exception('Patient ID fehlt');
            }
            
            $sql = "SELECT p.*, 
                    o.first_name as owner_first_name, 
                    o.last_name as owner_last_name,
                    o.email as owner_email,
                    o.phone as owner_phone,
                    o.customer_number as owner_customer_number
                    FROM tp_patients p 
                    LEFT JOIN tp_owners o ON p.owner_id = o.id 
                    WHERE p.id = :id";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['id' => $id]);
            $patient = $stmt->fetch();
            
            if (!$patient) {
                throw new Exception('Patient nicht gefunden');
            }
            
            // Get recent appointments
            $sql = "SELECT * FROM tp_appointments 
                    WHERE patient_id = :patient_id 
                    ORDER BY appointment_date DESC, start_time DESC 
                    LIMIT 10";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['patient_id' => $id]);
            $patient['appointments'] = $stmt->fetchAll();
            
            // Get recent treatments
            $sql = "SELECT t.*, 
                    u.first_name as therapist_first_name, 
                    u.last_name as therapist_last_name 
                    FROM tp_treatments t 
                    LEFT JOIN tp_users u ON t.therapist_id = u.id 
                    WHERE t.patient_id = :patient_id 
                    ORDER BY t.treatment_date DESC 
                    LIMIT 10";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['patient_id' => $id]);
            $patient['treatments'] = $stmt->fetchAll();
            
            // Get notes
            $sql = "SELECT n.*, 
                    u.first_name as creator_first_name, 
                    u.last_name as creator_last_name 
                    FROM tp_notes n 
                    LEFT JOIN tp_users u ON n.created_by = u.id 
                    WHERE n.patient_id = :patient_id 
                    ORDER BY n.created_at DESC 
                    LIMIT 10";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['patient_id' => $id]);
            $patient['notes'] = $stmt->fetchAll();
            
            echo json_encode([
                "status" => "success",
                "data" => $patient,
                "message" => "Patient gefunden"
            ]);
            break;
            
        case 'create':
            if ($method !== 'POST') {
                throw new Exception('Nur POST-Methode erlaubt');
            }
            
            // Validate required fields
            if (empty($_POST['name']) || empty($_POST['owner_id']) || empty($_POST['species'])) {
                throw new Exception('Pflichtfelder fehlen');
            }
            
            // Generate patient number
            $patient_number = 'P' . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
            
            $sql = "INSERT INTO tp_patients (
                        patient_number, owner_id, name, species, breed, color, 
                        gender, birth_date, weight, microchip, insurance_name, 
                        insurance_number, veterinarian, veterinarian_phone, 
                        medical_history, allergies, medications, notes, created_by
                    ) VALUES (
                        :patient_number, :owner_id, :name, :species, :breed, :color, 
                        :gender, :birth_date, :weight, :microchip, :insurance_name, 
                        :insurance_number, :veterinarian, :veterinarian_phone, 
                        :medical_history, :allergies, :medications, :notes, :created_by
                    )";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'patient_number' => $patient_number,
                'owner_id' => $_POST['owner_id'],
                'name' => $_POST['name'],
                'species' => $_POST['species'],
                'breed' => $_POST['breed'] ?? null,
                'color' => $_POST['color'] ?? null,
                'gender' => $_POST['gender'] ?? 'unknown',
                'birth_date' => $_POST['birth_date'] ?? null,
                'weight' => $_POST['weight'] ?? null,
                'microchip' => $_POST['microchip'] ?? null,
                'insurance_name' => $_POST['insurance_name'] ?? null,
                'insurance_number' => $_POST['insurance_number'] ?? null,
                'veterinarian' => $_POST['veterinarian'] ?? null,
                'veterinarian_phone' => $_POST['veterinarian_phone'] ?? null,
                'medical_history' => $_POST['medical_history'] ?? null,
                'allergies' => $_POST['allergies'] ?? null,
                'medications' => $_POST['medications'] ?? null,
                'notes' => $_POST['notes'] ?? null,
                'created_by' => $_SESSION['user_id'] ?? 1
            ]);
            
            $patientId = $pdo->lastInsertId();
            
            echo json_encode([
                "status" => "success",
                "data" => ["id" => $patientId, "patient_number" => $patient_number],
                "message" => "Patient erfolgreich angelegt"
            ]);
            break;
            
        case 'update':
            if ($method !== 'POST' && $method !== 'PUT') {
                throw new Exception('Nur POST/PUT-Methode erlaubt');
            }
            
            $id = intval($_POST['id'] ?? $_GET['id'] ?? 0);
            if (!$id) {
                throw new Exception('Patient ID fehlt');
            }
            
            // Parse PUT data if necessary
            if ($method === 'PUT') {
                parse_str(file_get_contents("php://input"), $_POST);
            }
            
            $sql = "UPDATE tp_patients SET 
                        name = :name,
                        species = :species,
                        breed = :breed,
                        color = :color,
                        gender = :gender,
                        birth_date = :birth_date,
                        weight = :weight,
                        microchip = :microchip,
                        insurance_name = :insurance_name,
                        insurance_number = :insurance_number,
                        veterinarian = :veterinarian,
                        veterinarian_phone = :veterinarian_phone,
                        medical_history = :medical_history,
                        allergies = :allergies,
                        medications = :medications,
                        notes = :notes,
                        updated_at = NOW()
                    WHERE id = :id";
            
            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute([
                'id' => $id,
                'name' => $_POST['name'] ?? '',
                'species' => $_POST['species'] ?? '',
                'breed' => $_POST['breed'] ?? null,
                'color' => $_POST['color'] ?? null,
                'gender' => $_POST['gender'] ?? 'unknown',
                'birth_date' => $_POST['birth_date'] ?? null,
                'weight' => $_POST['weight'] ?? null,
                'microchip' => $_POST['microchip'] ?? null,
                'insurance_name' => $_POST['insurance_name'] ?? null,
                'insurance_number' => $_POST['insurance_number'] ?? null,
                'veterinarian' => $_POST['veterinarian'] ?? null,
                'veterinarian_phone' => $_POST['veterinarian_phone'] ?? null,
                'medical_history' => $_POST['medical_history'] ?? null,
                'allergies' => $_POST['allergies'] ?? null,
                'medications' => $_POST['medications'] ?? null,
                'notes' => $_POST['notes'] ?? null
            ]);
            
            echo json_encode([
                "status" => "success",
                "data" => ["id" => $id],
                "message" => "Patient erfolgreich aktualisiert"
            ]);
            break;
            
        case 'delete':
            if ($method !== 'DELETE' && $method !== 'POST') {
                throw new Exception('Nur DELETE/POST-Methode erlaubt');
            }
            
            $id = intval($_REQUEST['id'] ?? 0);
            if (!$id) {
                throw new Exception('Patient ID fehlt');
            }
            
            // Check for related records
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tp_appointments WHERE patient_id = :id");
            $stmt->execute(['id' => $id]);
            $appointments = $stmt->fetch()['count'];
            
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tp_treatments WHERE patient_id = :id");
            $stmt->execute(['id' => $id]);
            $treatments = $stmt->fetch()['count'];
            
            if ($appointments > 0 || $treatments > 0) {
                throw new Exception('Patient kann nicht gelöscht werden. Es existieren noch ' . 
                                  $appointments . ' Termine und ' . $treatments . ' Behandlungen.');
            }
            
            // Soft delete
            $sql = "UPDATE tp_patients SET is_active = 0, updated_at = NOW() WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['id' => $id]);
            
            echo json_encode([
                "status" => "success",
                "data" => ["id" => $id],
                "message" => "Patient erfolgreich gelöscht"
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