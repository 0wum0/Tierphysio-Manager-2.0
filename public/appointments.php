<?php
/**
 * Tierphysio Manager 2.0
 * Appointments API Endpoint
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
            // Get all appointments with optional filters
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
            
            $appointments = $stmt->fetchAll();
            
            echo json_encode([
                "status" => "success",
                "data" => $appointments,
                "message" => count($appointments) . " Termine gefunden"
            ]);
            break;
            
        case 'get_by_id':
            $id = intval($_GET['id'] ?? 0);
            
            if (!$id) {
                throw new Exception('Termin ID fehlt');
            }
            
            $sql = "SELECT a.*, 
                    p.name as patient_name, 
                    p.species as patient_species,
                    p.breed as patient_breed,
                    o.id as owner_id,
                    o.first_name as owner_first_name,
                    o.last_name as owner_last_name,
                    o.phone as owner_phone,
                    o.mobile as owner_mobile,
                    o.email as owner_email,
                    u.first_name as therapist_first_name,
                    u.last_name as therapist_last_name,
                    u.email as therapist_email
                    FROM tp_appointments a 
                    LEFT JOIN tp_patients p ON a.patient_id = p.id
                    LEFT JOIN tp_owners o ON p.owner_id = o.id
                    LEFT JOIN tp_users u ON a.therapist_id = u.id
                    WHERE a.id = :id";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['id' => $id]);
            $appointment = $stmt->fetch();
            
            if (!$appointment) {
                throw new Exception('Termin nicht gefunden');
            }
            
            // Get associated treatment if exists
            $sql = "SELECT * FROM tp_treatments WHERE appointment_id = :appointment_id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['appointment_id' => $id]);
            $appointment['treatment'] = $stmt->fetch();
            
            echo json_encode([
                "status" => "success",
                "data" => $appointment,
                "message" => "Termin gefunden"
            ]);
            break;
            
        case 'create':
            if ($method !== 'POST') {
                throw new Exception('Nur POST-Methode erlaubt');
            }
            
            // Validate required fields
            if (empty($_POST['patient_id']) || empty($_POST['therapist_id']) || 
                empty($_POST['appointment_date']) || empty($_POST['start_time']) || 
                empty($_POST['end_time'])) {
                throw new Exception('Pflichtfelder fehlen');
            }
            
            // Check for conflicts
            $sql = "SELECT COUNT(*) as count FROM tp_appointments 
                    WHERE therapist_id = :therapist_id 
                    AND appointment_date = :appointment_date 
                    AND (
                        (start_time <= :start_time AND end_time > :start_time) OR
                        (start_time < :end_time AND end_time >= :end_time) OR
                        (start_time >= :start_time AND end_time <= :end_time)
                    )
                    AND status NOT IN ('cancelled', 'no_show')";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'therapist_id' => $_POST['therapist_id'],
                'appointment_date' => $_POST['appointment_date'],
                'start_time' => $_POST['start_time'],
                'end_time' => $_POST['end_time']
            ]);
            
            if ($stmt->fetch()['count'] > 0) {
                throw new Exception('Zeitkonflikt: Der Therapeut hat bereits einen Termin in diesem Zeitraum');
            }
            
            $sql = "INSERT INTO tp_appointments (
                        patient_id, therapist_id, appointment_date, start_time, end_time, 
                        type, status, treatment_type, room, notes, created_by
                    ) VALUES (
                        :patient_id, :therapist_id, :appointment_date, :start_time, :end_time, 
                        :type, :status, :treatment_type, :room, :notes, :created_by
                    )";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'patient_id' => $_POST['patient_id'],
                'therapist_id' => $_POST['therapist_id'],
                'appointment_date' => $_POST['appointment_date'],
                'start_time' => $_POST['start_time'],
                'end_time' => $_POST['end_time'],
                'type' => $_POST['type'] ?? 'followup',
                'status' => $_POST['status'] ?? 'scheduled',
                'treatment_type' => $_POST['treatment_type'] ?? null,
                'room' => $_POST['room'] ?? null,
                'notes' => $_POST['notes'] ?? null,
                'created_by' => $_SESSION['user_id'] ?? 1
            ]);
            
            $appointmentId = $pdo->lastInsertId();
            
            echo json_encode([
                "status" => "success",
                "data" => ["id" => $appointmentId],
                "message" => "Termin erfolgreich angelegt"
            ]);
            break;
            
        case 'update':
            if ($method !== 'POST' && $method !== 'PUT') {
                throw new Exception('Nur POST/PUT-Methode erlaubt');
            }
            
            $id = intval($_POST['id'] ?? $_GET['id'] ?? 0);
            if (!$id) {
                throw new Exception('Termin ID fehlt');
            }
            
            // Parse PUT data if necessary
            if ($method === 'PUT') {
                parse_str(file_get_contents("php://input"), $_POST);
            }
            
            // Check for conflicts if date/time changed
            if (isset($_POST['appointment_date']) || isset($_POST['start_time']) || isset($_POST['end_time'])) {
                $sql = "SELECT * FROM tp_appointments WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['id' => $id]);
                $current = $stmt->fetch();
                
                $check_date = $_POST['appointment_date'] ?? $current['appointment_date'];
                $check_start = $_POST['start_time'] ?? $current['start_time'];
                $check_end = $_POST['end_time'] ?? $current['end_time'];
                $check_therapist = $_POST['therapist_id'] ?? $current['therapist_id'];
                
                $sql = "SELECT COUNT(*) as count FROM tp_appointments 
                        WHERE therapist_id = :therapist_id 
                        AND appointment_date = :appointment_date 
                        AND id != :id
                        AND (
                            (start_time <= :start_time AND end_time > :start_time) OR
                            (start_time < :end_time AND end_time >= :end_time) OR
                            (start_time >= :start_time AND end_time <= :end_time)
                        )
                        AND status NOT IN ('cancelled', 'no_show')";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    'id' => $id,
                    'therapist_id' => $check_therapist,
                    'appointment_date' => $check_date,
                    'start_time' => $check_start,
                    'end_time' => $check_end
                ]);
                
                if ($stmt->fetch()['count'] > 0) {
                    throw new Exception('Zeitkonflikt: Der Therapeut hat bereits einen Termin in diesem Zeitraum');
                }
            }
            
            $sql = "UPDATE tp_appointments SET 
                        patient_id = :patient_id,
                        therapist_id = :therapist_id,
                        appointment_date = :appointment_date,
                        start_time = :start_time,
                        end_time = :end_time,
                        type = :type,
                        status = :status,
                        treatment_type = :treatment_type,
                        room = :room,
                        notes = :notes,
                        updated_at = NOW()
                    WHERE id = :id";
            
            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute([
                'id' => $id,
                'patient_id' => $_POST['patient_id'] ?? $current['patient_id'] ?? 0,
                'therapist_id' => $_POST['therapist_id'] ?? $current['therapist_id'] ?? 0,
                'appointment_date' => $_POST['appointment_date'] ?? $current['appointment_date'] ?? '',
                'start_time' => $_POST['start_time'] ?? $current['start_time'] ?? '',
                'end_time' => $_POST['end_time'] ?? $current['end_time'] ?? '',
                'type' => $_POST['type'] ?? $current['type'] ?? 'followup',
                'status' => $_POST['status'] ?? $current['status'] ?? 'scheduled',
                'treatment_type' => $_POST['treatment_type'] ?? $current['treatment_type'] ?? null,
                'room' => $_POST['room'] ?? $current['room'] ?? null,
                'notes' => $_POST['notes'] ?? $current['notes'] ?? null
            ]);
            
            // Handle cancellation
            if (isset($_POST['status']) && $_POST['status'] === 'cancelled') {
                $sql = "UPDATE tp_appointments SET 
                        cancelled_reason = :reason,
                        cancelled_at = NOW(),
                        cancelled_by = :cancelled_by
                        WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    'id' => $id,
                    'reason' => $_POST['cancelled_reason'] ?? 'Vom Benutzer storniert',
                    'cancelled_by' => $_SESSION['user_id'] ?? 1
                ]);
            }
            
            echo json_encode([
                "status" => "success",
                "data" => ["id" => $id],
                "message" => "Termin erfolgreich aktualisiert"
            ]);
            break;
            
        case 'delete':
            if ($method !== 'DELETE' && $method !== 'POST') {
                throw new Exception('Nur DELETE/POST-Methode erlaubt');
            }
            
            $id = intval($_REQUEST['id'] ?? 0);
            if (!$id) {
                throw new Exception('Termin ID fehlt');
            }
            
            // Check if treatment exists
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tp_treatments WHERE appointment_id = :id");
            $stmt->execute(['id' => $id]);
            if ($stmt->fetch()['count'] > 0) {
                throw new Exception('Termin kann nicht gelöscht werden. Es existiert eine verknüpfte Behandlung.');
            }
            
            // Delete appointment
            $sql = "DELETE FROM tp_appointments WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['id' => $id]);
            
            echo json_encode([
                "status" => "success",
                "data" => ["id" => $id],
                "message" => "Termin erfolgreich gelöscht"
            ]);
            break;
            
        case 'get_availability':
            // Get available time slots for a specific date and therapist
            $date = $_GET['date'] ?? date('Y-m-d');
            $therapist_id = intval($_GET['therapist_id'] ?? 0);
            
            if (!$therapist_id) {
                throw new Exception('Therapeut ID fehlt');
            }
            
            // Get working hours from settings
            $working_start = '08:00:00';
            $working_end = '18:00:00';
            $slot_duration = 30; // minutes
            
            // Get existing appointments
            $sql = "SELECT start_time, end_time FROM tp_appointments 
                    WHERE therapist_id = :therapist_id 
                    AND appointment_date = :date 
                    AND status NOT IN ('cancelled', 'no_show')
                    ORDER BY start_time";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'therapist_id' => $therapist_id,
                'date' => $date
            ]);
            $appointments = $stmt->fetchAll();
            
            // Generate available slots
            $slots = [];
            $current = strtotime($date . ' ' . $working_start);
            $end = strtotime($date . ' ' . $working_end);
            
            while ($current < $end) {
                $slot_start = date('H:i:s', $current);
                $slot_end = date('H:i:s', $current + ($slot_duration * 60));
                
                // Check if slot is available
                $available = true;
                foreach ($appointments as $apt) {
                    if (($slot_start >= $apt['start_time'] && $slot_start < $apt['end_time']) ||
                        ($slot_end > $apt['start_time'] && $slot_end <= $apt['end_time'])) {
                        $available = false;
                        break;
                    }
                }
                
                if ($available && $slot_end <= $working_end) {
                    $slots[] = [
                        'start' => substr($slot_start, 0, 5),
                        'end' => substr($slot_end, 0, 5),
                        'available' => true
                    ];
                }
                
                $current += ($slot_duration * 60);
            }
            
            echo json_encode([
                "status" => "success",
                "data" => $slots,
                "message" => count($slots) . " verfügbare Zeitslots"
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