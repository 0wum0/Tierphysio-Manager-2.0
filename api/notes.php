<?php
/**
 * Tierphysio Manager 2.0
 * Notes API Endpoint - Hardened with tp_ prefix & proper JSON responses
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
            $patient_id = intval($_GET['patient_id'] ?? 0);
            $note_type = $_GET['type'] ?? '';
            $limit = intval($_GET['limit'] ?? 50);
            $offset = intval($_GET['offset'] ?? 0);
            
            $sql = "
                SELECT 
                    n.*,
                    p.name as patient_name,
                    p.patient_number,
                    u.first_name as author_first_name,
                    u.last_name as author_last_name
                FROM tp_notes n
                LEFT JOIN tp_patients p ON n.patient_id = p.id
                LEFT JOIN tp_users u ON n.user_id = u.id
                WHERE 1=1
            ";
            
            $params = [];
            
            if ($patient_id) {
                $sql .= " AND n.patient_id = :patient_id";
                $params['patient_id'] = $patient_id;
            }
            
            if ($note_type) {
                $sql .= " AND n.note_type = :note_type";
                $params['note_type'] = $note_type;
            }
            
            $sql .= " ORDER BY n.created_at DESC LIMIT :limit OFFSET :offset";
            
            $stmt = $pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            
            $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format notes
            foreach ($notes as &$note) {
                $note['is_important'] = (bool) $note['is_important'];
                $note['author_full_name'] = trim($note['author_first_name'] . ' ' . $note['author_last_name']);
            }
            
            api_success(['data' => $notes, 'count' => count($notes)]);
            break;
            
        case 'get':
            $id = intval($_GET['id'] ?? 0);
            if (!$id) {
                api_error('Notiz-ID fehlt', 400);
            }
            
            $stmt = $pdo->prepare("
                SELECT 
                    n.*,
                    p.name as patient_name,
                    p.patient_number,
                    p.species,
                    u.first_name as author_first_name,
                    u.last_name as author_last_name,
                    CONCAT_WS(' ', o.first_name, o.last_name) AS owner_full_name
                FROM tp_notes n
                LEFT JOIN tp_patients p ON n.patient_id = p.id
                LEFT JOIN tp_owners o ON p.owner_id = o.id
                LEFT JOIN tp_users u ON n.user_id = u.id
                WHERE n.id = ?
            ");
            $stmt->execute([$id]);
            $note = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$note) {
                api_error('Notiz nicht gefunden', 404);
            }
            
            $note['is_important'] = (bool) $note['is_important'];
            
            api_success($note);
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
            $content = trim($input['content'] ?? '');
            $note_type = $input['note_type'] ?? 'general';
            
            if (!$patient_id) {
                api_error('Patient ID fehlt', 400);
            }
            
            if (!$content) {
                api_error('Notizinhalt fehlt', 400);
            }
            
            // Verify patient exists
            $stmt = $pdo->prepare("SELECT id FROM tp_patients WHERE id = ?");
            $stmt->execute([$patient_id]);
            if (!$stmt->fetch()) {
                api_error('Patient nicht gefunden', 404);
            }
            
            // Create note
            $stmt = $pdo->prepare("
                INSERT INTO tp_notes (
                    patient_id, user_id, note_type, content,
                    is_important, created_at
                ) VALUES (?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $patient_id,
                intval($input['user_id'] ?? 1),
                $note_type,
                $content,
                intval($input['is_important'] ?? 0)
            ]);
            
            $note_id = $pdo->lastInsertId();
            
            // Get created note
            $stmt = $pdo->prepare("
                SELECT n.*, p.name as patient_name,
                    u.first_name as author_first_name,
                    u.last_name as author_last_name
                FROM tp_notes n
                LEFT JOIN tp_patients p ON n.patient_id = p.id
                LEFT JOIN tp_users u ON n.user_id = u.id
                WHERE n.id = ?
            ");
            $stmt->execute([$note_id]);
            $note = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $note['is_important'] = (bool) $note['is_important'];
            
            api_success(['note_id' => $note_id, 'note' => $note]);
            break;
            
        case 'update':
            $input = $_POST;
            if (empty($input)) {
                $json = file_get_contents('php://input');
                $input = json_decode($json, true) ?? [];
            }
            
            $id = intval($input['id'] ?? 0);
            if (!$id) {
                api_error('Notiz-ID fehlt', 400);
            }
            
            // Check if note exists
            $stmt = $pdo->prepare("SELECT * FROM tp_notes WHERE id = ?");
            $stmt->execute([$id]);
            if (!$stmt->fetch()) {
                api_error('Notiz nicht gefunden', 404);
            }
            
            $content = trim($input['content'] ?? '');
            if (!$content) {
                api_error('Notizinhalt fehlt', 400);
            }
            
            // Update note
            $stmt = $pdo->prepare("
                UPDATE tp_notes SET
                    note_type = ?,
                    content = ?,
                    is_important = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([
                $input['note_type'] ?? 'general',
                $content,
                intval($input['is_important'] ?? 0),
                $id
            ]);
            
            api_success(['message' => 'Notiz erfolgreich aktualisiert']);
            break;
            
        case 'delete':
            $input = $_POST;
            if (empty($input)) {
                $json = file_get_contents('php://input');
                $input = json_decode($json, true) ?? [];
            }
            
            $id = intval($input['id'] ?? $_GET['id'] ?? 0);
            if (!$id) {
                api_error('Notiz-ID fehlt', 400);
            }
            
            $stmt = $pdo->prepare("DELETE FROM tp_notes WHERE id = ?");
            $stmt->execute([$id]);
            
            api_success(['message' => 'Notiz erfolgreich gelöscht']);
            break;
            
        case 'recent':
            // Get recent notes across all patients
            $limit = intval($_GET['limit'] ?? 10);
            $days = intval($_GET['days'] ?? 7);
            
            $stmt = $pdo->prepare("
                SELECT 
                    n.*,
                    p.name as patient_name,
                    p.patient_number,
                    u.first_name as author_first_name,
                    u.last_name as author_last_name
                FROM tp_notes n
                LEFT JOIN tp_patients p ON n.patient_id = p.id
                LEFT JOIN tp_users u ON n.user_id = u.id
                WHERE n.created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
                ORDER BY n.created_at DESC
                LIMIT :limit
            ");
            
            $stmt->bindValue(':days', $days, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            
            $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($notes as &$note) {
                $note['is_important'] = (bool) $note['is_important'];
                $note['author_full_name'] = trim($note['author_first_name'] . ' ' . $note['author_last_name']);
            }
            
            api_success(['data' => $notes, 'count' => count($notes)]);
            break;
            
        default:
            api_error("Unbekannte Aktion: " . $action, 400);
    }
    
} catch (PDOException $e) {
    error_log("Notes API PDO Error (" . $action . "): " . $e->getMessage());
    api_error('Datenbankfehler aufgetreten', 500, ['details' => APP_DEBUG ? $e->getMessage() : null]);
} catch (Throwable $e) {
    error_log("Notes API Error (" . $action . "): " . $e->getMessage());
    api_error('Serverfehler aufgetreten', 500, ['details' => APP_DEBUG ? $e->getMessage() : null]);
}

// Should never reach here
exit;