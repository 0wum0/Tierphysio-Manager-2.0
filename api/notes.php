<?php
/**
 * Tierphysio Manager 2.0
 * Notes API Endpoint - Unified JSON Response Format
 */

require_once __DIR__ . '/_bootstrap.php';

// Get action from request
$action = $_GET['action'] ?? 'list';

// Special handling for notes - always return empty if table issues
if ($action === 'list') {
    try {
        $pdo = get_pdo();
        // Quick check if table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'tp_notes'");
        if ($stmt->rowCount() === 0) {
            // Table doesn't exist, return empty result
            api_success(['items' => [], 'count' => 0]);
        }
    } catch (Exception $e) {
        // Any database issue, return empty result for list
        api_success(['items' => [], 'count' => 0]);
    }
}

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
                    '' as author_first_name,
                    '' as author_last_name
                FROM tp_notes n
                LEFT JOIN tp_patients p ON n.patient_id = p.id
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
            
            api_success(['items' => $notes, 'count' => count($notes)]);
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
                    '' as author_first_name,
                    '' as author_last_name,
                    CONCAT_WS(' ', o.first_name, o.last_name) AS owner_full_name
                FROM tp_notes n
                LEFT JOIN tp_patients p ON n.patient_id = p.id
                LEFT JOIN tp_owners o ON p.owner_id = o.id
                WHERE n.id = ?
            ");
            $stmt->execute([$id]);
            $note = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$note) {
                api_error('Notiz nicht gefunden', 404);
            }
            
            $note['is_important'] = (bool) $note['is_important'];
            
            api_success(['items' => [$note]]);
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
                    '' as author_first_name,
                    '' as author_last_name
                FROM tp_notes n
                LEFT JOIN tp_patients p ON n.patient_id = p.id
                WHERE n.id = ?
            ");
            $stmt->execute([$note_id]);
            $note = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $note['is_important'] = (bool) $note['is_important'];
            
            api_success(['items' => [$note]]);
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
            
            api_success(['items' => []]);
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
            
            api_success(['items' => []]);
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
                    '' as author_first_name,
                    '' as author_last_name
                FROM tp_notes n
                LEFT JOIN tp_patients p ON n.patient_id = p.id
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
            
            api_success(['items' => $notes, 'count' => count($notes)]);
            break;
            
        default:
            api_error("Unbekannte Aktion: " . $action, 400);
    }
    
} catch (PDOException $e) {
    error_log("Notes API PDO Error (" . $action . "): " . $e->getMessage());
    $debug_details = (defined('APP_DEBUG') && APP_DEBUG) ? $e->getMessage() : null;
    api_error('Datenbankfehler aufgetreten');
} catch (Throwable $e) {
    error_log("Notes API Error (" . $action . "): " . $e->getMessage());
    $debug_details = (defined('APP_DEBUG') && APP_DEBUG) ? $e->getMessage() : null;
    api_error('Serverfehler aufgetreten');
}

// Should never reach here
exit;