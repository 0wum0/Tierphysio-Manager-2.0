<?php
/**
 * Tierphysio Manager 2.0
 * Notes API Endpoint
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
            $owner_id = intval($_GET['owner_id'] ?? 0);
            $type = $_GET['type'] ?? '';
            $limit = intval($_GET['limit'] ?? 100);
            $offset = intval($_GET['offset'] ?? 0);
            
            $sql = "SELECT n.*, 
                    p.name as patient_name,
                    o.first_name as owner_first_name,
                    o.last_name as owner_last_name,
                    u.first_name as creator_first_name,
                    u.last_name as creator_last_name
                    FROM tp_notes n 
                    LEFT JOIN tp_patients p ON n.patient_id = p.id
                    LEFT JOIN tp_owners o ON n.owner_id = o.id
                    LEFT JOIN tp_users u ON n.created_by = u.id
                    WHERE 1=1";
            
            $params = [];
            
            if ($patient_id) {
                $sql .= " AND n.patient_id = :patient_id";
                $params['patient_id'] = $patient_id;
            }
            
            if ($owner_id) {
                $sql .= " AND n.owner_id = :owner_id";
                $params['owner_id'] = $owner_id;
            }
            
            if ($type) {
                $sql .= " AND n.type = :type";
                $params['type'] = $type;
            }
            
            $sql .= " ORDER BY n.is_pinned DESC, n.created_at DESC LIMIT :limit OFFSET :offset";
            
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
                json_error('Notiz ID fehlt', 400);
            }
            
            $sql = "SELECT n.*, 
                    p.name as patient_name,
                    o.first_name as owner_first_name,
                    o.last_name as owner_last_name,
                    u.first_name as creator_first_name,
                    u.last_name as creator_last_name
                    FROM tp_notes n 
                    LEFT JOIN tp_patients p ON n.patient_id = p.id
                    LEFT JOIN tp_owners o ON n.owner_id = o.id
                    LEFT JOIN tp_users u ON n.created_by = u.id
                    WHERE n.id = :id";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['id' => $id]);
            $note = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$note) {
                json_error('Notiz nicht gefunden', 404);
            }
            
            json_success($note);
            break;
            
        case 'create':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                json_error('Nur POST erlaubt', 405);
            }
            
            require_csrf();
            $data = get_post_data();
            validate_required($data, ['content']);
            
            $insertData = [
                'patient_id' => isset($data['patient_id']) ? intval($data['patient_id']) : null,
                'appointment_id' => isset($data['appointment_id']) ? intval($data['appointment_id']) : null,
                'treatment_id' => isset($data['treatment_id']) ? intval($data['treatment_id']) : null,
                'owner_id' => isset($data['owner_id']) ? intval($data['owner_id']) : null,
                'type' => sanitize_input($data['type'] ?? 'general'),
                'title' => sanitize_input($data['title'] ?? null),
                'content' => sanitize_input($data['content']),
                'is_pinned' => isset($data['is_pinned']) ? 1 : 0,
                'is_private' => isset($data['is_private']) ? 1 : 0,
                'reminder_date' => $data['reminder_date'] ?? null,
                'created_by' => intval($data['created_by'] ?? $_SESSION['user_id'] ?? 0),
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            // At least one relation must be set
            if (!$insertData['patient_id'] && !$insertData['appointment_id'] && !$insertData['treatment_id'] && !$insertData['owner_id']) {
                json_error('Notiz muss einem Patienten, Termin, Behandlung oder Besitzer zugeordnet sein', 400);
            }
            
            $columns = array_keys($insertData);
            $placeholders = array_map(function($col) { return ':' . $col; }, $columns);
            
            $sql = "INSERT INTO tp_notes (" . implode(', ', $columns) . ") 
                    VALUES (" . implode(', ', $placeholders) . ")";
            
            $stmt = $pdo->prepare($sql);
            foreach ($insertData as $key => $value) {
                $stmt->bindValue(':' . $key, $value);
            }
            
            if ($stmt->execute()) {
                $noteId = $pdo->lastInsertId();
                $stmt = $pdo->prepare("SELECT * FROM tp_notes WHERE id = :id");
                $stmt->execute(['id' => $noteId]);
                json_success($stmt->fetch(PDO::FETCH_ASSOC), 'Notiz erfolgreich angelegt');
            } else {
                json_error('Fehler beim Anlegen der Notiz', 500);
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
                json_error('Notiz ID fehlt', 400);
            }
            
            $updateData = [];
            $allowedFields = [
                'patient_id', 'appointment_id', 'treatment_id', 'owner_id',
                'type', 'title', 'content', 'is_pinned', 'is_private', 'reminder_date'
            ];
            
            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    if (in_array($field, ['patient_id', 'appointment_id', 'treatment_id', 'owner_id'])) {
                        $updateData[$field] = intval($data[$field]);
                    } elseif (in_array($field, ['is_pinned', 'is_private'])) {
                        $updateData[$field] = $data[$field] ? 1 : 0;
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
            $sql = "UPDATE tp_notes SET " . implode(', ', $setParts) . " WHERE id = :id";
            
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            foreach ($updateData as $key => $value) {
                $stmt->bindValue(':' . $key, $value);
            }
            
            if ($stmt->execute()) {
                $stmt = $pdo->prepare("SELECT * FROM tp_notes WHERE id = :id");
                $stmt->execute(['id' => $id]);
                json_success($stmt->fetch(PDO::FETCH_ASSOC), 'Notiz erfolgreich aktualisiert');
            } else {
                json_error('Fehler beim Aktualisieren der Notiz', 500);
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
                json_error('Notiz ID fehlt', 400);
            }
            
            $stmt = $pdo->prepare("DELETE FROM tp_notes WHERE id = :id");
            if ($stmt->execute(['id' => $id])) {
                json_success(null, 'Notiz erfolgreich gelöscht');
            } else {
                json_error('Fehler beim Löschen der Notiz', 500);
            }
            break;
            
        default:
            json_error('Unbekannte Aktion: ' . $action, 400);
    }
    
} catch (PDOException $e) {
    error_log("API notes " . $action . ": " . $e->getMessage());
    json_error('Datenbankfehler aufgetreten', 500);
} catch (Exception $e) {
    error_log("API notes " . $action . ": " . $e->getMessage());
    json_error('Unerwarteter Fehler: ' . $e->getMessage(), 500);
}