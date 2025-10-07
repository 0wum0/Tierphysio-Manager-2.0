<?php
/**
 * Tierphysio Manager 2.0
 * Treatments API Endpoint
 */

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
            $patient_id = intval($_GET['patient_id'] ?? 0);
            $therapist_id = intval($_GET['therapist_id'] ?? 0);
            $limit = intval($_GET['limit'] ?? 100);
            $offset = intval($_GET['offset'] ?? 0);
            
            $sql = "SELECT t.*, 
                    p.name as patient_name,
                    p.species as patient_species,
                    u.first_name as therapist_first_name,
                    u.last_name as therapist_last_name
                    FROM tp_treatments t 
                    LEFT JOIN tp_patients p ON t.patient_id = p.id
                    LEFT JOIN tp_users u ON t.therapist_id = u.id
                    WHERE 1=1";
            
            $params = [];
            
            if ($patient_id) {
                $sql .= " AND t.patient_id = :patient_id";
                $params['patient_id'] = $patient_id;
            }
            
            if ($therapist_id) {
                $sql .= " AND t.therapist_id = :therapist_id";
                $params['therapist_id'] = $therapist_id;
            }
            
            $sql .= " ORDER BY t.treatment_date DESC LIMIT :limit OFFSET :offset";
            
            $stmt = $pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            
            json_success($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;
            
        case 'get':
            $id = intval($_GET['id'] ?? 0);
            if (!$id) {
                json_error('Behandlung ID fehlt', 400);
            }
            
            $sql = "SELECT t.*, 
                    p.name as patient_name,
                    u.first_name as therapist_first_name,
                    u.last_name as therapist_last_name
                    FROM tp_treatments t 
                    LEFT JOIN tp_patients p ON t.patient_id = p.id
                    LEFT JOIN tp_users u ON t.therapist_id = u.id
                    WHERE t.id = :id";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['id' => $id]);
            $treatment = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$treatment) {
                json_error('Behandlung nicht gefunden', 404);
            }
            
            json_success($treatment);
            break;
            
        case 'create':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                json_error('Nur POST erlaubt', 405);
            }
            
            require_csrf();
            $data = get_post_data();
            validate_required($data, ['patient_id', 'therapist_id', 'treatment_date']);
            
            $insertData = [
                'appointment_id' => isset($data['appointment_id']) ? intval($data['appointment_id']) : null,
                'patient_id' => intval($data['patient_id']),
                'therapist_id' => intval($data['therapist_id']),
                'treatment_date' => $data['treatment_date'],
                'duration_minutes' => intval($data['duration_minutes'] ?? 30),
                'diagnosis' => sanitize_input($data['diagnosis'] ?? null),
                'symptoms' => sanitize_input($data['symptoms'] ?? null),
                'treatment_goals' => sanitize_input($data['treatment_goals'] ?? null),
                'treatment_methods' => sanitize_input($data['treatment_methods'] ?? null),
                'exercises_homework' => sanitize_input($data['exercises_homework'] ?? null),
                'progress_notes' => sanitize_input($data['progress_notes'] ?? null),
                'next_steps' => sanitize_input($data['next_steps'] ?? null),
                'pain_level_before' => isset($data['pain_level_before']) ? intval($data['pain_level_before']) : null,
                'pain_level_after' => isset($data['pain_level_after']) ? intval($data['pain_level_after']) : null,
                'created_by' => $_SESSION['user_id'] ?? null,
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            $columns = array_keys($insertData);
            $placeholders = array_map(function($col) { return ':' . $col; }, $columns);
            
            $sql = "INSERT INTO tp_treatments (" . implode(', ', $columns) . ") 
                    VALUES (" . implode(', ', $placeholders) . ")";
            
            $stmt = $pdo->prepare($sql);
            foreach ($insertData as $key => $value) {
                $stmt->bindValue(':' . $key, $value);
            }
            
            if ($stmt->execute()) {
                $treatmentId = $pdo->lastInsertId();
                $stmt = $pdo->prepare("SELECT * FROM tp_treatments WHERE id = :id");
                $stmt->execute(['id' => $treatmentId]);
                json_success($stmt->fetch(PDO::FETCH_ASSOC), 'Behandlung erfolgreich angelegt');
            } else {
                json_error('Fehler beim Anlegen der Behandlung', 500);
            }
            break;
            
        case 'update':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                json_error('Nur POST erlaubt', 405);
            }
            
            require_csrf();
            $data = get_post_data();
            
            $id = intval($data['id'] ?? 0);
            if (!$id) {
                json_error('Behandlung ID fehlt', 400);
            }
            
            $updateData = [];
            $allowedFields = [
                'appointment_id', 'patient_id', 'therapist_id', 'treatment_date', 'duration_minutes',
                'diagnosis', 'symptoms', 'treatment_goals', 'treatment_methods', 'exercises_homework',
                'progress_notes', 'next_steps', 'pain_level_before', 'pain_level_after'
            ];
            
            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    if (in_array($field, ['appointment_id', 'patient_id', 'therapist_id', 'duration_minutes', 'pain_level_before', 'pain_level_after'])) {
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
            
            $setParts = array_map(function($col) { return $col . ' = :' . $col; }, array_keys($updateData));
            $sql = "UPDATE tp_treatments SET " . implode(', ', $setParts) . " WHERE id = :id";
            
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            foreach ($updateData as $key => $value) {
                $stmt->bindValue(':' . $key, $value);
            }
            
            if ($stmt->execute()) {
                $stmt = $pdo->prepare("SELECT * FROM tp_treatments WHERE id = :id");
                $stmt->execute(['id' => $id]);
                json_success($stmt->fetch(PDO::FETCH_ASSOC), 'Behandlung erfolgreich aktualisiert');
            } else {
                json_error('Fehler beim Aktualisieren der Behandlung', 500);
            }
            break;
            
        case 'delete':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                json_error('Nur POST erlaubt', 405);
            }
            
            require_csrf();
            $data = get_post_data();
            
            $id = intval($data['id'] ?? 0);
            if (!$id) {
                json_error('Behandlung ID fehlt', 400);
            }
            
            $stmt = $pdo->prepare("DELETE FROM tp_treatments WHERE id = :id");
            if ($stmt->execute(['id' => $id])) {
                json_success(null, 'Behandlung erfolgreich gelöscht');
            } else {
                json_error('Fehler beim Löschen der Behandlung', 500);
            }
            break;
            
        default:
            json_error('Unbekannte Aktion: ' . $action, 400);
    }
    
} catch (PDOException $e) {
    error_log("API treatments " . $action . ": " . $e->getMessage());
    json_error('Datenbankfehler aufgetreten', 500);
} catch (Exception $e) {
    error_log("API treatments " . $action . ": " . $e->getMessage());
    json_error('Unerwarteter Fehler: ' . $e->getMessage(), 500);
}