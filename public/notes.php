<?php
/**
 * Tierphysio Manager 2.0
 * Notes API Endpoint
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
            // Get all notes with optional filters
            $patient_id = intval($_GET['patient_id'] ?? 0);
            $owner_id = intval($_GET['owner_id'] ?? 0);
            $appointment_id = intval($_GET['appointment_id'] ?? 0);
            $treatment_id = intval($_GET['treatment_id'] ?? 0);
            $type = $_GET['type'] ?? '';
            $is_pinned = isset($_GET['is_pinned']) ? intval($_GET['is_pinned']) : null;
            $limit = intval($_GET['limit'] ?? 100);
            $offset = intval($_GET['offset'] ?? 0);
            
            $sql = "SELECT n.*, 
                    p.name as patient_name,
                    CONCAT(o.first_name, ' ', o.last_name) as owner_name,
                    CONCAT(u.first_name, ' ', u.last_name) as creator_name,
                    u.email as creator_email
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
            
            if ($appointment_id) {
                $sql .= " AND n.appointment_id = :appointment_id";
                $params['appointment_id'] = $appointment_id;
            }
            
            if ($treatment_id) {
                $sql .= " AND n.treatment_id = :treatment_id";
                $params['treatment_id'] = $treatment_id;
            }
            
            if ($type) {
                $sql .= " AND n.type = :type";
                $params['type'] = $type;
            }
            
            if ($is_pinned !== null) {
                $sql .= " AND n.is_pinned = :is_pinned";
                $params['is_pinned'] = $is_pinned;
            }
            
            // Check for private notes
            if (!isset($_GET['include_private']) || !$_GET['include_private']) {
                $sql .= " AND (n.is_private = 0 OR n.created_by = :user_id)";
                $params['user_id'] = $_SESSION['user_id'] ?? 1;
            }
            
            $sql .= " ORDER BY n.is_pinned DESC, n.created_at DESC LIMIT :limit OFFSET :offset";
            
            $stmt = $pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            
            $notes = $stmt->fetchAll();
            
            // Check for reminders that are due
            foreach ($notes as &$note) {
                if ($note['reminder_date']) {
                    $note['reminder_due'] = strtotime($note['reminder_date']) <= time();
                }
            }
            
            echo json_encode([
                "status" => "success",
                "data" => $notes,
                "message" => count($notes) . " Notizen gefunden"
            ]);
            break;
            
        case 'get_by_id':
            $id = intval($_GET['id'] ?? 0);
            
            if (!$id) {
                throw new Exception('Notiz ID fehlt');
            }
            
            $sql = "SELECT n.*, 
                    p.name as patient_name,
                    p.species as patient_species,
                    CONCAT(o.first_name, ' ', o.last_name) as owner_name,
                    o.customer_number as owner_customer_number,
                    CONCAT(u.first_name, ' ', u.last_name) as creator_name,
                    u.email as creator_email
                    FROM tp_notes n 
                    LEFT JOIN tp_patients p ON n.patient_id = p.id
                    LEFT JOIN tp_owners o ON n.owner_id = o.id
                    LEFT JOIN tp_users u ON n.created_by = u.id
                    WHERE n.id = :id";
            
            // Check if user can access private notes
            $sql .= " AND (n.is_private = 0 OR n.created_by = :user_id)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'id' => $id,
                'user_id' => $_SESSION['user_id'] ?? 1
            ]);
            $note = $stmt->fetch();
            
            if (!$note) {
                throw new Exception('Notiz nicht gefunden oder Zugriff verweigert');
            }
            
            echo json_encode([
                "status" => "success",
                "data" => $note,
                "message" => "Notiz gefunden"
            ]);
            break;
            
        case 'create':
            if ($method !== 'POST') {
                throw new Exception('Nur POST-Methode erlaubt');
            }
            
            // Validate required fields
            if (empty($_POST['content'])) {
                throw new Exception('Notizinhalt fehlt');
            }
            
            // At least one entity must be linked
            if (empty($_POST['patient_id']) && empty($_POST['owner_id']) && 
                empty($_POST['appointment_id']) && empty($_POST['treatment_id'])) {
                throw new Exception('Notiz muss mit mindestens einem Datensatz verknüpft sein');
            }
            
            $sql = "INSERT INTO tp_notes (
                        patient_id, appointment_id, treatment_id, owner_id, 
                        type, title, content, is_pinned, is_private, 
                        reminder_date, attachments, created_by
                    ) VALUES (
                        :patient_id, :appointment_id, :treatment_id, :owner_id, 
                        :type, :title, :content, :is_pinned, :is_private, 
                        :reminder_date, :attachments, :created_by
                    )";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'patient_id' => $_POST['patient_id'] ?? null,
                'appointment_id' => $_POST['appointment_id'] ?? null,
                'treatment_id' => $_POST['treatment_id'] ?? null,
                'owner_id' => $_POST['owner_id'] ?? null,
                'type' => $_POST['type'] ?? 'general',
                'title' => $_POST['title'] ?? null,
                'content' => $_POST['content'],
                'is_pinned' => isset($_POST['is_pinned']) ? 1 : 0,
                'is_private' => isset($_POST['is_private']) ? 1 : 0,
                'reminder_date' => $_POST['reminder_date'] ?? null,
                'attachments' => $_POST['attachments'] ?? null,
                'created_by' => $_SESSION['user_id'] ?? 1
            ]);
            
            $noteId = $pdo->lastInsertId();
            
            echo json_encode([
                "status" => "success",
                "data" => ["id" => $noteId],
                "message" => "Notiz erfolgreich erstellt"
            ]);
            break;
            
        case 'update':
            if ($method !== 'POST' && $method !== 'PUT') {
                throw new Exception('Nur POST/PUT-Methode erlaubt');
            }
            
            $id = intval($_POST['id'] ?? $_GET['id'] ?? 0);
            if (!$id) {
                throw new Exception('Notiz ID fehlt');
            }
            
            // Parse PUT data if necessary
            if ($method === 'PUT') {
                parse_str(file_get_contents("php://input"), $_POST);
            }
            
            // Check if user owns the note or is admin
            $stmt = $pdo->prepare("SELECT created_by FROM tp_notes WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $note = $stmt->fetch();
            
            if (!$note) {
                throw new Exception('Notiz nicht gefunden');
            }
            
            // Only creator can edit their notes (in production, add admin check)
            if ($note['created_by'] != ($_SESSION['user_id'] ?? 1)) {
                throw new Exception('Sie können nur Ihre eigenen Notizen bearbeiten');
            }
            
            $sql = "UPDATE tp_notes SET 
                        type = :type,
                        title = :title,
                        content = :content,
                        is_pinned = :is_pinned,
                        is_private = :is_private,
                        reminder_date = :reminder_date,
                        attachments = :attachments,
                        updated_at = NOW()
                    WHERE id = :id";
            
            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute([
                'id' => $id,
                'type' => $_POST['type'] ?? 'general',
                'title' => $_POST['title'] ?? null,
                'content' => $_POST['content'] ?? '',
                'is_pinned' => isset($_POST['is_pinned']) ? 1 : 0,
                'is_private' => isset($_POST['is_private']) ? 1 : 0,
                'reminder_date' => $_POST['reminder_date'] ?? null,
                'attachments' => $_POST['attachments'] ?? null
            ]);
            
            echo json_encode([
                "status" => "success",
                "data" => ["id" => $id],
                "message" => "Notiz erfolgreich aktualisiert"
            ]);
            break;
            
        case 'delete':
            if ($method !== 'DELETE' && $method !== 'POST') {
                throw new Exception('Nur DELETE/POST-Methode erlaubt');
            }
            
            $id = intval($_REQUEST['id'] ?? 0);
            if (!$id) {
                throw new Exception('Notiz ID fehlt');
            }
            
            // Check if user owns the note
            $stmt = $pdo->prepare("SELECT created_by FROM tp_notes WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $note = $stmt->fetch();
            
            if (!$note) {
                throw new Exception('Notiz nicht gefunden');
            }
            
            // Only creator can delete their notes (in production, add admin check)
            if ($note['created_by'] != ($_SESSION['user_id'] ?? 1)) {
                throw new Exception('Sie können nur Ihre eigenen Notizen löschen');
            }
            
            // Delete note
            $sql = "DELETE FROM tp_notes WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['id' => $id]);
            
            echo json_encode([
                "status" => "success",
                "data" => ["id" => $id],
                "message" => "Notiz erfolgreich gelöscht"
            ]);
            break;
            
        case 'toggle_pin':
            if ($method !== 'POST') {
                throw new Exception('Nur POST-Methode erlaubt');
            }
            
            $id = intval($_POST['id'] ?? 0);
            if (!$id) {
                throw new Exception('Notiz ID fehlt');
            }
            
            // Toggle pin status
            $sql = "UPDATE tp_notes SET is_pinned = NOT is_pinned WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['id' => $id]);
            
            // Get new status
            $stmt = $pdo->prepare("SELECT is_pinned FROM tp_notes WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $pinned = $stmt->fetch()['is_pinned'];
            
            echo json_encode([
                "status" => "success",
                "data" => ["id" => $id, "is_pinned" => $pinned],
                "message" => $pinned ? "Notiz angeheftet" : "Notiz gelöst"
            ]);
            break;
            
        case 'get_reminders':
            // Get notes with reminders due today or overdue
            $sql = "SELECT n.*, 
                    p.name as patient_name,
                    CONCAT(o.first_name, ' ', o.last_name) as owner_name,
                    CONCAT(u.first_name, ' ', u.last_name) as creator_name
                    FROM tp_notes n 
                    LEFT JOIN tp_patients p ON n.patient_id = p.id
                    LEFT JOIN tp_owners o ON n.owner_id = o.id
                    LEFT JOIN tp_users u ON n.created_by = u.id
                    WHERE n.reminder_date IS NOT NULL 
                    AND n.reminder_date <= NOW()
                    AND (n.created_by = :user_id OR n.is_private = 0)
                    ORDER BY n.reminder_date";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['user_id' => $_SESSION['user_id'] ?? 1]);
            $reminders = $stmt->fetchAll();
            
            echo json_encode([
                "status" => "success",
                "data" => $reminders,
                "message" => count($reminders) . " fällige Erinnerungen"
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