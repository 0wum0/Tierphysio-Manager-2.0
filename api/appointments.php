<?php
/**
 * Tierphysio Manager 2.0
 * Appointments API Endpoint - Hardened with tp_ prefix & proper JSON responses
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

// Get action from request
$action = $_GET['action'] ?? 'list';

try {
    $pdo = get_pdo();
    
    switch ($action) {
        case 'list':
            $date_from = $_GET['date_from'] ?? date('Y-m-d');
            $date_to = $_GET['date_to'] ?? date('Y-m-d', strtotime('+30 days'));
            $patient_id = intval($_GET['patient_id'] ?? 0);
            $therapist_id = intval($_GET['therapist_id'] ?? 0);
            $status = $_GET['status'] ?? '';
            $limit = intval($_GET['limit'] ?? 100);
            $offset = intval($_GET['offset'] ?? 0);
            
            $sql = "SELECT a.*, 
                    p.name as patient_name, 
                    p.species as patient_species,
                    o.first_name as owner_first_name,
                    o.last_name as owner_last_name,
                    CONCAT_WS(' ', o.first_name, o.last_name) AS owner_full_name,
                    o.phone as owner_phone,
                    u.first_name as therapist_first_name,
                    u.last_name as therapist_last_name
                    FROM tp_appointments a 
                    LEFT JOIN tp_patients p ON a.patient_id = p.id
                    LEFT JOIN tp_owners o ON p.owner_id = o.id
                    LEFT JOIN tp_users u ON a.therapist_id = u.id
                    WHERE a.appointment_date BETWEEN :date_from AND :date_to";
            
            $params = [
                'date_from' => $date_from,
                'date_to' => $date_to
            ];
            
            if ($patient_id) {
                $sql .= " AND a.patient_id = :patient_id";
                $params['patient_id'] = $patient_id;
            }
            
            if ($therapist_id) {
                $sql .= " AND a.therapist_id = :therapist_id";
                $params['therapist_id'] = $therapist_id;
            }
            
            if ($status) {
                $sql .= " AND a.status = :status";
                $params['status'] = $status;
            }
            
            $sql .= " ORDER BY a.appointment_date, a.start_time LIMIT :limit OFFSET :offset";
            
            $stmt = $pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            
            $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            api_success(['items' => $appointments, 'count' => count($appointments)]);
            break;
            
        case 'get':
            $id = intval($_GET['id'] ?? 0);
            if (!$id) {
                api_error('Appointment ID fehlt', 400);
            }
            
            $stmt = $pdo->prepare("
                SELECT a.*, 
                    p.name as patient_name,
                    p.species as patient_species,
                    o.first_name as owner_first_name,
                    o.last_name as owner_last_name,
                    CONCAT_WS(' ', o.first_name, o.last_name) AS owner_full_name,
                    o.phone as owner_phone,
                    o.email as owner_email,
                    u.first_name as therapist_first_name,
                    u.last_name as therapist_last_name
                FROM tp_appointments a
                LEFT JOIN tp_patients p ON a.patient_id = p.id
                LEFT JOIN tp_owners o ON p.owner_id = o.id
                LEFT JOIN tp_users u ON a.therapist_id = u.id
                WHERE a.id = ?
            ");
            $stmt->execute([$id]);
            $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$appointment) {
                api_error('Appointment nicht gefunden', 404);
            }
            
            api_success($appointment);
            break;
            
        case 'create':
            // Get input data
            $input = $_POST;
            if (empty($input)) {
                $json = file_get_contents('php://input');
                $input = json_decode($json, true) ?? [];
            }
            
            // Validate required fields
            $patient_id = intval($input['patient_id'] ?? 0);
            $appointment_date = $input['appointment_date'] ?? '';
            $start_time = $input['start_time'] ?? '';
            $duration = intval($input['duration'] ?? 30);
            
            if (!$patient_id || !$appointment_date || !$start_time) {
                api_error('Pflichtfelder fehlen', 400);
            }
            
            // Calculate end time
            $start = new DateTime("$appointment_date $start_time");
            $end = clone $start;
            $end->add(new DateInterval("PT{$duration}M"));
            $end_time = $end->format('H:i:s');
            
            // Insert appointment
            $stmt = $pdo->prepare("
                INSERT INTO tp_appointments (
                    patient_id, therapist_id, appointment_date, start_time, end_time, 
                    duration, type, status, treatment_focus, notes, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $patient_id,
                intval($input['therapist_id'] ?? 1),
                $appointment_date,
                $start_time,
                $end_time,
                $duration,
                $input['type'] ?? 'treatment',
                $input['status'] ?? 'scheduled',
                $input['treatment_focus'] ?? null,
                $input['notes'] ?? null
            ]);
            
            $id = $pdo->lastInsertId();
            
            // Get created appointment
            $stmt = $pdo->prepare("
                SELECT a.*, p.name as patient_name, 
                    CONCAT_WS(' ', o.first_name, o.last_name) AS owner_full_name
                FROM tp_appointments a
                LEFT JOIN tp_patients p ON a.patient_id = p.id
                LEFT JOIN tp_owners o ON p.owner_id = o.id
                WHERE a.id = ?
            ");
            $stmt->execute([$id]);
            $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
            
            api_success(['appointment' => $appointment, 'appointment_id' => $id], 201);
            break;
            
        case 'update':
            $input = $_POST;
            if (empty($input)) {
                $json = file_get_contents('php://input');
                $input = json_decode($json, true) ?? [];
            }
            
            $id = intval($input['id'] ?? 0);
            if (!$id) {
                api_error('Appointment ID fehlt', 400);
            }
            
            // Check if appointment exists
            $stmt = $pdo->prepare("SELECT * FROM tp_appointments WHERE id = ?");
            $stmt->execute([$id]);
            if (!$stmt->fetch()) {
                api_error('Appointment nicht gefunden', 404);
            }
            
            // Update appointment
            $stmt = $pdo->prepare("
                UPDATE tp_appointments SET
                    appointment_date = ?,
                    start_time = ?,
                    end_time = ?,
                    duration = ?,
                    status = ?,
                    treatment_focus = ?,
                    notes = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            
            // Calculate end time
            $duration = intval($input['duration'] ?? 30);
            $start = new DateTime($input['appointment_date'] . ' ' . $input['start_time']);
            $end = clone $start;
            $end->add(new DateInterval("PT{$duration}M"));
            $end_time = $end->format('H:i:s');
            
            $stmt->execute([
                $input['appointment_date'],
                $input['start_time'],
                $end_time,
                $duration,
                $input['status'] ?? 'scheduled',
                $input['treatment_focus'] ?? null,
                $input['notes'] ?? null,
                $id
            ]);
            
            api_success(['message' => 'Appointment erfolgreich aktualisiert']);
            break;
            
        case 'delete':
            $input = $_POST;
            if (empty($input)) {
                $json = file_get_contents('php://input');
                $input = json_decode($json, true) ?? [];
            }
            
            $id = intval($input['id'] ?? $_GET['id'] ?? 0);
            if (!$id) {
                api_error('Appointment ID fehlt', 400);
            }
            
            $stmt = $pdo->prepare("DELETE FROM tp_appointments WHERE id = ?");
            $stmt->execute([$id]);
            
            api_success(['message' => 'Appointment erfolgreich gelöscht']);
            break;
            
        default:
            api_error("Unbekannte Aktion: " . $action, 400);
    }
    
} catch (PDOException $e) {
    error_log("Appointments API PDO Error (" . $action . "): " . $e->getMessage());
    api_error('Datenbankfehler aufgetreten', 500, ['details' => APP_DEBUG ? $e->getMessage() : null]);
} catch (Throwable $e) {
    error_log("Appointments API Error (" . $action . "): " . $e->getMessage());
    api_error('Serverfehler aufgetreten', 500, ['details' => APP_DEBUG ? $e->getMessage() : null]);
}

// Should never reach here
exit;