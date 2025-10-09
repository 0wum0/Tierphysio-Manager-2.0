<?php
/**
 * Tierphysio Manager 2.0
 * Treatments API Endpoint - Hardened with tp_ prefix & proper JSON responses
 */

// Set JSON header immediately
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Error reporting for production
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Clear any existing output
if (ob_get_length()) ob_end_clean();
ob_start();

require_once __DIR__ . '/../includes/db.php';

// Unified JSON responders
function json_ok($data = [], $code = 200) {
    if (ob_get_length()) ob_end_clean();
    http_response_code($code);
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function json_err($msg, $code = 400, $extra = []) {
    if (ob_get_length()) ob_end_clean();
    http_response_code($code);
    $response = ['ok' => false, 'error' => $msg];
    if (!empty($extra)) {
        $response = array_merge($response, $extra);
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// Get action from request
$action = $_GET['action'] ?? 'list';

try {
    $pdo = get_pdo();
    
    switch ($action) {
        case 'list':
            $patient_id = intval($_GET['patient_id'] ?? 0);
            $therapist_id = intval($_GET['therapist_id'] ?? 0);
            $date_from = $_GET['date_from'] ?? '';
            $date_to = $_GET['date_to'] ?? '';
            $limit = intval($_GET['limit'] ?? 50);
            $offset = intval($_GET['offset'] ?? 0);
            
            $sql = "SELECT t.*, 
                    p.name as patient_name,
                    p.patient_number,
                    CONCAT_WS(' ', o.first_name, o.last_name) AS owner_full_name,
                    u.first_name as therapist_first_name,
                    u.last_name as therapist_last_name
                    FROM tp_treatments t
                    LEFT JOIN tp_patients p ON t.patient_id = p.id
                    LEFT JOIN tp_owners o ON p.owner_id = o.id
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
            
            if ($date_from && $date_to) {
                $sql .= " AND t.treatment_date BETWEEN :date_from AND :date_to";
                $params['date_from'] = $date_from;
                $params['date_to'] = $date_to;
            }
            
            $sql .= " ORDER BY t.treatment_date DESC, t.created_at DESC LIMIT :limit OFFSET :offset";
            
            $stmt = $pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            
            $treatments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            json_ok(['items' => $treatments, 'total' => count($treatments)]);
            break;
            
        case 'get':
            $id = intval($_GET['id'] ?? 0);
            if (!$id) {
                json_err('Treatment ID fehlt', 400);
            }
            
            $stmt = $pdo->prepare("
                SELECT t.*,
                    p.name as patient_name,
                    p.patient_number,
                    p.species,
                    CONCAT_WS(' ', o.first_name, o.last_name) AS owner_full_name,
                    o.phone as owner_phone,
                    o.email as owner_email,
                    u.first_name as therapist_first_name,
                    u.last_name as therapist_last_name
                FROM tp_treatments t
                LEFT JOIN tp_patients p ON t.patient_id = p.id
                LEFT JOIN tp_owners o ON p.owner_id = o.id
                LEFT JOIN tp_users u ON t.therapist_id = u.id
                WHERE t.id = ?
            ");
            $stmt->execute([$id]);
            $treatment = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$treatment) {
                json_err('Treatment nicht gefunden', 404);
            }
            
            json_ok($treatment);
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
            $treatment_date = $input['treatment_date'] ?? '';
            $treatment_type = $input['treatment_type'] ?? '';
            
            if (!$patient_id || !$treatment_date || !$treatment_type) {
                json_err('Pflichtfelder fehlen', 400);
            }
            
            // Insert treatment
            $stmt = $pdo->prepare("
                INSERT INTO tp_treatments (
                    patient_id, therapist_id, appointment_id, treatment_date,
                    treatment_type, treatment_focus, techniques_used, 
                    duration, findings, recommendations, homework, notes,
                    follow_up_date, billing_status, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $patient_id,
                intval($input['therapist_id'] ?? 1),
                intval($input['appointment_id'] ?? null),
                $treatment_date,
                $treatment_type,
                $input['treatment_focus'] ?? null,
                $input['techniques_used'] ?? null,
                intval($input['duration'] ?? 30),
                $input['findings'] ?? null,
                $input['recommendations'] ?? null,
                $input['homework'] ?? null,
                $input['notes'] ?? null,
                $input['follow_up_date'] ?? null,
                $input['billing_status'] ?? 'pending'
            ]);
            
            $id = $pdo->lastInsertId();
            
            // Get created treatment
            $stmt = $pdo->prepare("
                SELECT t.*, p.name as patient_name,
                    CONCAT_WS(' ', o.first_name, o.last_name) AS owner_full_name
                FROM tp_treatments t
                LEFT JOIN tp_patients p ON t.patient_id = p.id
                LEFT JOIN tp_owners o ON p.owner_id = o.id
                WHERE t.id = ?
            ");
            $stmt->execute([$id]);
            $treatment = $stmt->fetch(PDO::FETCH_ASSOC);
            
            json_ok(['treatment' => $treatment, 'treatment_id' => $id], 201);
            break;
            
        case 'update':
            $input = $_POST;
            if (empty($input)) {
                $json = file_get_contents('php://input');
                $input = json_decode($json, true) ?? [];
            }
            
            $id = intval($input['id'] ?? 0);
            if (!$id) {
                json_err('Treatment ID fehlt', 400);
            }
            
            // Check if treatment exists
            $stmt = $pdo->prepare("SELECT * FROM tp_treatments WHERE id = ?");
            $stmt->execute([$id]);
            if (!$stmt->fetch()) {
                json_err('Treatment nicht gefunden', 404);
            }
            
            // Update treatment
            $stmt = $pdo->prepare("
                UPDATE tp_treatments SET
                    treatment_date = ?,
                    treatment_type = ?,
                    treatment_focus = ?,
                    techniques_used = ?,
                    duration = ?,
                    findings = ?,
                    recommendations = ?,
                    homework = ?,
                    notes = ?,
                    follow_up_date = ?,
                    billing_status = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([
                $input['treatment_date'],
                $input['treatment_type'],
                $input['treatment_focus'] ?? null,
                $input['techniques_used'] ?? null,
                intval($input['duration'] ?? 30),
                $input['findings'] ?? null,
                $input['recommendations'] ?? null,
                $input['homework'] ?? null,
                $input['notes'] ?? null,
                $input['follow_up_date'] ?? null,
                $input['billing_status'] ?? 'pending',
                $id
            ]);
            
            json_ok(['message' => 'Treatment erfolgreich aktualisiert']);
            break;
            
        case 'delete':
            $input = $_POST;
            if (empty($input)) {
                $json = file_get_contents('php://input');
                $input = json_decode($json, true) ?? [];
            }
            
            $id = intval($input['id'] ?? $_GET['id'] ?? 0);
            if (!$id) {
                json_err('Treatment ID fehlt', 400);
            }
            
            $stmt = $pdo->prepare("DELETE FROM tp_treatments WHERE id = ?");
            $stmt->execute([$id]);
            
            json_ok(['message' => 'Treatment erfolgreich gelöscht']);
            break;
            
        default:
            json_err("Unbekannte Aktion: " . $action, 400);
    }
    
} catch (PDOException $e) {
    error_log("Treatments API PDO Error (" . $action . "): " . $e->getMessage());
    json_err('Datenbankfehler aufgetreten', 500, ['details' => APP_DEBUG ? $e->getMessage() : null]);
} catch (Throwable $e) {
    error_log("Treatments API Error (" . $action . "): " . $e->getMessage());
    json_err('Serverfehler aufgetreten', 500, ['details' => APP_DEBUG ? $e->getMessage() : null]);
}

// Should never reach here
exit;