<?php
/**
 * Tierphysio Manager 2.0
 * Treatments API Endpoint
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
            // Get all treatments with optional filters
            $patient_id = intval($_GET['patient_id'] ?? 0);
            $therapist_id = intval($_GET['therapist_id'] ?? 0);
            $date_from = $_GET['date_from'] ?? date('Y-m-01'); // First day of current month
            $date_to = $_GET['date_to'] ?? date('Y-m-t'); // Last day of current month
            $treatment_type = $_GET['treatment_type'] ?? '';
            $limit = intval($_GET['limit'] ?? 100);
            $offset = intval($_GET['offset'] ?? 0);
            
            $sql = "SELECT t.*, 
                    p.name as patient_name, 
                    p.species as patient_species,
                    o.first_name as owner_first_name,
                    o.last_name as owner_last_name,
                    u.first_name as therapist_first_name,
                    u.last_name as therapist_last_name,
                    a.appointment_date,
                    a.start_time as appointment_start_time,
                    a.end_time as appointment_end_time
                    FROM tp_treatments t 
                    LEFT JOIN tp_patients p ON t.patient_id = p.id
                    LEFT JOIN tp_owners o ON p.owner_id = o.id
                    LEFT JOIN tp_users u ON t.therapist_id = u.id
                    LEFT JOIN tp_appointments a ON t.appointment_id = a.id
                    WHERE t.treatment_date BETWEEN :date_from AND :date_to";
            
            $params = [
                'date_from' => $date_from,
                'date_to' => $date_to
            ];
            
            if ($patient_id) {
                $sql .= " AND t.patient_id = :patient_id";
                $params['patient_id'] = $patient_id;
            }
            
            if ($therapist_id) {
                $sql .= " AND t.therapist_id = :therapist_id";
                $params['therapist_id'] = $therapist_id;
            }
            
            if ($treatment_type) {
                $sql .= " AND t.treatment_type = :treatment_type";
                $params['treatment_type'] = $treatment_type;
            }
            
            $sql .= " ORDER BY t.treatment_date DESC, t.created_at DESC LIMIT :limit OFFSET :offset";
            
            $stmt = $pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            
            $treatments = $stmt->fetchAll();
            
            echo json_encode([
                "status" => "success",
                "data" => $treatments,
                "message" => count($treatments) . " Behandlungen gefunden"
            ]);
            break;
            
        case 'get_by_id':
            $id = intval($_GET['id'] ?? 0);
            
            if (!$id) {
                throw new Exception('Behandlung ID fehlt');
            }
            
            $sql = "SELECT t.*, 
                    p.name as patient_name, 
                    p.species as patient_species,
                    p.breed as patient_breed,
                    p.weight as patient_weight,
                    p.birth_date as patient_birth_date,
                    o.id as owner_id,
                    o.first_name as owner_first_name,
                    o.last_name as owner_last_name,
                    o.phone as owner_phone,
                    o.email as owner_email,
                    u.first_name as therapist_first_name,
                    u.last_name as therapist_last_name,
                    u.email as therapist_email,
                    a.appointment_date,
                    a.start_time as appointment_start_time,
                    a.end_time as appointment_end_time
                    FROM tp_treatments t 
                    LEFT JOIN tp_patients p ON t.patient_id = p.id
                    LEFT JOIN tp_owners o ON p.owner_id = o.id
                    LEFT JOIN tp_users u ON t.therapist_id = u.id
                    LEFT JOIN tp_appointments a ON t.appointment_id = a.id
                    WHERE t.id = :id";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['id' => $id]);
            $treatment = $stmt->fetch();
            
            if (!$treatment) {
                throw new Exception('Behandlung nicht gefunden');
            }
            
            // Get related documents
            $sql = "SELECT * FROM tp_documents WHERE treatment_id = :treatment_id ORDER BY created_at DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['treatment_id' => $id]);
            $treatment['documents'] = $stmt->fetchAll();
            
            // Get related notes
            $sql = "SELECT n.*, u.first_name as creator_first_name, u.last_name as creator_last_name 
                    FROM tp_notes n 
                    LEFT JOIN tp_users u ON n.created_by = u.id 
                    WHERE n.treatment_id = :treatment_id 
                    ORDER BY n.created_at DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['treatment_id' => $id]);
            $treatment['notes'] = $stmt->fetchAll();
            
            echo json_encode([
                "status" => "success",
                "data" => $treatment,
                "message" => "Behandlung gefunden"
            ]);
            break;
            
        case 'create':
            if ($method !== 'POST') {
                throw new Exception('Nur POST-Methode erlaubt');
            }
            
            // Validate required fields
            if (empty($_POST['patient_id']) || empty($_POST['therapist_id']) || 
                empty($_POST['treatment_date']) || empty($_POST['treatment_type'])) {
                throw new Exception('Pflichtfelder fehlen');
            }
            
            $sql = "INSERT INTO tp_treatments (
                        patient_id, therapist_id, appointment_id, treatment_date, treatment_type, 
                        duration, diagnosis, treatment_methods, exercises_given, homework, 
                        progress_notes, next_appointment_recommendation, pain_level_before, 
                        pain_level_after, mobility_assessment, notes, created_by
                    ) VALUES (
                        :patient_id, :therapist_id, :appointment_id, :treatment_date, :treatment_type, 
                        :duration, :diagnosis, :treatment_methods, :exercises_given, :homework, 
                        :progress_notes, :next_appointment_recommendation, :pain_level_before, 
                        :pain_level_after, :mobility_assessment, :notes, :created_by
                    )";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'patient_id' => $_POST['patient_id'],
                'therapist_id' => $_POST['therapist_id'],
                'appointment_id' => $_POST['appointment_id'] ?? null,
                'treatment_date' => $_POST['treatment_date'],
                'treatment_type' => $_POST['treatment_type'],
                'duration' => $_POST['duration'] ?? 30,
                'diagnosis' => $_POST['diagnosis'] ?? null,
                'treatment_methods' => $_POST['treatment_methods'] ?? null,
                'exercises_given' => $_POST['exercises_given'] ?? null,
                'homework' => $_POST['homework'] ?? null,
                'progress_notes' => $_POST['progress_notes'] ?? null,
                'next_appointment_recommendation' => $_POST['next_appointment_recommendation'] ?? null,
                'pain_level_before' => $_POST['pain_level_before'] ?? null,
                'pain_level_after' => $_POST['pain_level_after'] ?? null,
                'mobility_assessment' => $_POST['mobility_assessment'] ?? null,
                'notes' => $_POST['notes'] ?? null,
                'created_by' => $_SESSION['user_id'] ?? 1
            ]);
            
            $treatmentId = $pdo->lastInsertId();
            
            // Update appointment status if linked
            if (!empty($_POST['appointment_id'])) {
                $sql = "UPDATE tp_appointments SET status = 'completed' WHERE id = :appointment_id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['appointment_id' => $_POST['appointment_id']]);
            }
            
            echo json_encode([
                "status" => "success",
                "data" => ["id" => $treatmentId],
                "message" => "Behandlung erfolgreich angelegt"
            ]);
            break;
            
        case 'update':
            if ($method !== 'POST' && $method !== 'PUT') {
                throw new Exception('Nur POST/PUT-Methode erlaubt');
            }
            
            $id = intval($_POST['id'] ?? $_GET['id'] ?? 0);
            if (!$id) {
                throw new Exception('Behandlung ID fehlt');
            }
            
            // Parse PUT data if necessary
            if ($method === 'PUT') {
                parse_str(file_get_contents("php://input"), $_POST);
            }
            
            $sql = "UPDATE tp_treatments SET 
                        patient_id = :patient_id,
                        therapist_id = :therapist_id,
                        appointment_id = :appointment_id,
                        treatment_date = :treatment_date,
                        treatment_type = :treatment_type,
                        duration = :duration,
                        diagnosis = :diagnosis,
                        treatment_methods = :treatment_methods,
                        exercises_given = :exercises_given,
                        homework = :homework,
                        progress_notes = :progress_notes,
                        next_appointment_recommendation = :next_appointment_recommendation,
                        pain_level_before = :pain_level_before,
                        pain_level_after = :pain_level_after,
                        mobility_assessment = :mobility_assessment,
                        notes = :notes,
                        updated_at = NOW()
                    WHERE id = :id";
            
            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute([
                'id' => $id,
                'patient_id' => $_POST['patient_id'] ?? 0,
                'therapist_id' => $_POST['therapist_id'] ?? 0,
                'appointment_id' => $_POST['appointment_id'] ?? null,
                'treatment_date' => $_POST['treatment_date'] ?? date('Y-m-d'),
                'treatment_type' => $_POST['treatment_type'] ?? '',
                'duration' => $_POST['duration'] ?? 30,
                'diagnosis' => $_POST['diagnosis'] ?? null,
                'treatment_methods' => $_POST['treatment_methods'] ?? null,
                'exercises_given' => $_POST['exercises_given'] ?? null,
                'homework' => $_POST['homework'] ?? null,
                'progress_notes' => $_POST['progress_notes'] ?? null,
                'next_appointment_recommendation' => $_POST['next_appointment_recommendation'] ?? null,
                'pain_level_before' => $_POST['pain_level_before'] ?? null,
                'pain_level_after' => $_POST['pain_level_after'] ?? null,
                'mobility_assessment' => $_POST['mobility_assessment'] ?? null,
                'notes' => $_POST['notes'] ?? null
            ]);
            
            echo json_encode([
                "status" => "success",
                "data" => ["id" => $id],
                "message" => "Behandlung erfolgreich aktualisiert"
            ]);
            break;
            
        case 'delete':
            if ($method !== 'DELETE' && $method !== 'POST') {
                throw new Exception('Nur DELETE/POST-Methode erlaubt');
            }
            
            $id = intval($_REQUEST['id'] ?? 0);
            if (!$id) {
                throw new Exception('Behandlung ID fehlt');
            }
            
            // Check for related records
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tp_documents WHERE treatment_id = :id");
            $stmt->execute(['id' => $id]);
            $documents = $stmt->fetch()['count'];
            
            if ($documents > 0) {
                throw new Exception('Behandlung kann nicht gelöscht werden. Es existieren noch ' . $documents . ' verknüpfte Dokumente.');
            }
            
            // Delete treatment
            $sql = "DELETE FROM tp_treatments WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['id' => $id]);
            
            echo json_encode([
                "status" => "success",
                "data" => ["id" => $id],
                "message" => "Behandlung erfolgreich gelöscht"
            ]);
            break;
            
        case 'get_types':
            // Get available treatment types
            $sql = "SELECT DISTINCT treatment_type FROM tp_treatments 
                    WHERE treatment_type IS NOT NULL AND treatment_type != '' 
                    ORDER BY treatment_type";
            $stmt = $pdo->query($sql);
            $types = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Add default types if not already in database
            $defaultTypes = [
                'Erstbehandlung',
                'Folgebehandlung',
                'Massage',
                'Manuelle Therapie',
                'Krankengymnastik',
                'Lymphdrainage',
                'Elektrotherapie',
                'Thermotherapie',
                'Hydrotherapie',
                'Osteopathie',
                'Akupunktur',
                'Lasertherapie'
            ];
            
            $types = array_unique(array_merge($types, $defaultTypes));
            sort($types);
            
            echo json_encode([
                "status" => "success",
                "data" => $types,
                "message" => count($types) . " Behandlungsarten verfügbar"
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