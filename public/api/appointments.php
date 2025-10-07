<?php
/**
 * Tierphysio Manager 2.0
 * Appointments API Endpoint
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
            
            json_success($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;
            
        case 'get':
            $id = intval($_GET['id'] ?? 0);
            if (!$id) {
                json_error('Termin ID fehlt', 400);
            }
            
            $sql = "SELECT a.*, 
                    p.name as patient_name, 
                    p.species as patient_species,
                    o.first_name as owner_first_name,
                    o.last_name as owner_last_name,
                    u.first_name as therapist_first_name,
                    u.last_name as therapist_last_name
                    FROM tp_appointments a 
                    LEFT JOIN tp_patients p ON a.patient_id = p.id
                    LEFT JOIN tp_owners o ON p.owner_id = o.id
                    LEFT JOIN tp_users u ON a.therapist_id = u.id
                    WHERE a.id = :id";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['id' => $id]);
            $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$appointment) {
                json_error('Termin nicht gefunden', 404);
            }
            
            json_success($appointment);
            break;
            
        case 'create':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                json_error('Nur POST erlaubt', 405);
            }
            
            require_csrf();
            $data = get_post_data();
            validate_required($data, ['patient_id', 'therapist_id', 'appointment_date', 'start_time', 'end_time']);
            
            $insertData = [
                'patient_id' => intval($data['patient_id']),
                'therapist_id' => intval($data['therapist_id']),
                'appointment_date' => $data['appointment_date'],
                'start_time' => $data['start_time'],
                'end_time' => $data['end_time'],
                'type' => sanitize_input($data['type'] ?? 'followup'),
                'status' => 'scheduled',
                'treatment_type' => sanitize_input($data['treatment_type'] ?? null),
                'room' => sanitize_input($data['room'] ?? null),
                'notes' => sanitize_input($data['notes'] ?? null),
                'created_by' => $_SESSION['user_id'] ?? null,
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            $columns = array_keys($insertData);
            $placeholders = array_map(function($col) { return ':' . $col; }, $columns);
            
            $sql = "INSERT INTO tp_appointments (" . implode(', ', $columns) . ") 
                    VALUES (" . implode(', ', $placeholders) . ")";
            
            $stmt = $pdo->prepare($sql);
            foreach ($insertData as $key => $value) {
                $stmt->bindValue(':' . $key, $value);
            }
            
            if ($stmt->execute()) {
                $appointmentId = $pdo->lastInsertId();
                $stmt = $pdo->prepare("SELECT * FROM tp_appointments WHERE id = :id");
                $stmt->execute(['id' => $appointmentId]);
                json_success($stmt->fetch(PDO::FETCH_ASSOC), 'Termin erfolgreich angelegt');
            } else {
                json_error('Fehler beim Anlegen des Termins', 500);
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
                json_error('Termin ID fehlt', 400);
            }
            
            $updateData = [];
            $allowedFields = [
                'patient_id', 'therapist_id', 'appointment_date', 'start_time', 'end_time',
                'type', 'status', 'treatment_type', 'room', 'notes'
            ];
            
            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    if (in_array($field, ['patient_id', 'therapist_id'])) {
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
            $sql = "UPDATE tp_appointments SET " . implode(', ', $setParts) . " WHERE id = :id";
            
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            foreach ($updateData as $key => $value) {
                $stmt->bindValue(':' . $key, $value);
            }
            
            if ($stmt->execute()) {
                $stmt = $pdo->prepare("SELECT * FROM tp_appointments WHERE id = :id");
                $stmt->execute(['id' => $id]);
                json_success($stmt->fetch(PDO::FETCH_ASSOC), 'Termin erfolgreich aktualisiert');
            } else {
                json_error('Fehler beim Aktualisieren des Termins', 500);
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
                json_error('Termin ID fehlt', 400);
            }
            
            // Update status to cancelled instead of deleting
            $stmt = $pdo->prepare("UPDATE tp_appointments SET status = 'cancelled', cancelled_at = NOW(), cancelled_by = :user_id WHERE id = :id");
            if ($stmt->execute(['id' => $id, 'user_id' => $_SESSION['user_id'] ?? null])) {
                json_success(null, 'Termin erfolgreich storniert');
            } else {
                json_error('Fehler beim Stornieren des Termins', 500);
            }
            break;
            
        default:
            json_error('Unbekannte Aktion: ' . $action, 400);
    }
    
} catch (PDOException $e) {
    error_log("API appointments " . $action . ": " . $e->getMessage());
    ob_end_clean(); // Clear any output buffer
    json_error('Datenbankfehler aufgetreten', 500);
} catch (Throwable $e) {
    error_log("API appointments " . $action . ": " . $e->getMessage());
    ob_end_clean(); // Clear any output buffer
    json_error('Unerwarteter Fehler: ' . $e->getMessage(), 500);
}

// Ensure no further output
exit;