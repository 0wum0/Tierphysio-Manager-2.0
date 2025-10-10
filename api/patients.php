<?php
/**
 * Tierphysio Manager 2.0
 * Patients API Endpoint - Unified JSON Response Format
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$action = $_GET['action'] ?? '';

// Get database connection
$pdo = get_pdo();

try {
    
    switch ($action) {
        case 'list':
        case 'search':
            try {
                $keyword = '';
                $sql = "
                    SELECT 
                        p.id, p.name, p.species, p.image, p.is_active,
                        CONCAT(o.first_name, ' ', o.last_name) AS owner_full_name,
                        (SELECT MIN(a.appointment_date)
                           FROM tp_appointments a
                           WHERE a.patient_id = p.id
                           AND a.status IN ('scheduled','confirmed')) AS next_appointment,
                        (SELECT CASE WHEN COUNT(*)>0 THEN 'open' ELSE 'paid' END
                           FROM tp_invoices i
                           WHERE i.patient_id = p.id
                           AND i.status != 'paid') AS invoice_status
                    FROM tp_patients p
                    LEFT JOIN tp_owners o ON o.id = p.owner_id
                ";

                // Falls eine Suchanfrage vorliegt
                if ($action === 'search' && !empty($_GET['q'])) {
                    $keyword = trim($_GET['q']);
                    $sql .= " WHERE p.name LIKE :kw OR o.first_name LIKE :kw OR o.last_name LIKE :kw ";
                }

                $sql .= " ORDER BY p.created_at DESC";
                $stmt = $pdo->prepare($sql);

                if ($keyword) $stmt->bindValue(':kw', "%{$keyword}%");
                $stmt->execute();

                $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
                api_success([
                    'patients' => $patients ?: [],
                    'count' => count($patients)
                ]);

            } catch (Exception $e) {
                error_log('[PatientsAPI]['.$action.'] '.$e->getMessage(), 3, __DIR__.'/../logs/api.log');
                api_error('Fehler beim Laden der Patienten: '.$e->getMessage());
            }
            break;
            
        case 'get':
            $id = intval($_GET['id'] ?? 0);
            
            if (!$id) {
                api_error('Patient ID fehlt', 400);
            }
            
            $stmt = $pdo->prepare("
                SELECT p.*, 
                    CONCAT_WS(' ', o.first_name, o.last_name) AS owner_full_name,
                    o.customer_number,
                    o.first_name AS owner_first_name,
                    o.last_name AS owner_last_name,
                    o.phone AS owner_phone,
                    o.mobile AS owner_mobile,
                    o.email AS owner_email,
                    o.street AS owner_street,
                    o.house_number AS owner_house_number,
                    o.postal_code AS owner_postal_code,
                    o.city AS owner_city,
                    (SELECT DATE_FORMAT(MIN(a.appointment_date), '%d.%m.%Y') 
                     FROM tp_appointments a 
                     WHERE a.patient_id = p.id 
                     AND a.appointment_date >= CURDATE() 
                     AND a.status IN ('scheduled','confirmed')) AS next_appointment,
                    (SELECT CASE 
                        WHEN COUNT(*) > 0 THEN 'open' 
                        ELSE 'paid' 
                     END 
                     FROM tp_invoices i 
                     WHERE i.patient_id = p.id 
                     AND i.status != 'paid') AS invoice_status
                FROM tp_patients p
                LEFT JOIN tp_owners o ON o.id = p.owner_id
                WHERE p.id = :id
            ");
            $stmt->execute(['id' => $id]);
            $patient = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$patient) {
                api_error('Patient nicht gefunden', 404);
            }
            
            // Get treatment history
            $stmt = $pdo->prepare("
                SELECT t.*, u.first_name AS therapist_first_name, u.last_name AS therapist_last_name
                FROM tp_treatments t
                LEFT JOIN tp_users u ON t.therapist_id = u.id
                WHERE t.patient_id = ?
                ORDER BY t.treatment_date DESC
                LIMIT 10
            ");
            $stmt->execute([$id]);
            $patient['recent_treatments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format boolean value
            $patient['is_active'] = (bool) ($patient['is_active'] ?? true);
            
            // Format the response with patient object directly for backward compatibility
            api_success(['patient' => $patient, 'items' => [$patient]]);
            break;
            
        case 'create':
            // Get input data (support both form-data and JSON)
            $input = $_POST;
            if (empty($input)) {
                $json = file_get_contents('php://input');
                $input = json_decode($json, true) ?? [];
            }
            
            // Patient data
            $patient_name = trim($input['name'] ?? $input['patient_name'] ?? '');
            $species = trim($input['species'] ?? 'other');
            $breed = trim($input['breed'] ?? '');
            $color = trim($input['color'] ?? '');
            $gender = trim($input['gender'] ?? 'unknown');
            $birth_date = trim($input['birth_date'] ?? $input['birthdate'] ?? '');
            $weight = floatval($input['weight'] ?? 0);
            $microchip = trim($input['microchip'] ?? '');
            $notes = trim($input['notes'] ?? '');
            $medical_history = trim($input['medical_history'] ?? '');
            $allergies = trim($input['allergies'] ?? '');
            $medications = trim($input['medications'] ?? '');
            
            // Owner handling
            $owner_mode = trim($input['owner_mode'] ?? 'existing');
            $owner_id = intval($input['owner_id'] ?? 0);
            
            // Validate required fields
            if (!$patient_name) {
                api_error('Patientenname ist erforderlich', 400);
            }
            
            if (!$species) {
                api_error('Tierart ist erforderlich', 400);
            }
            
            // Begin transaction
            $pdo->beginTransaction();
            
            try {
                // Handle owner creation/validation
                if ($owner_mode === 'new') {
                    // Create new owner
                    $owner_first = trim($input['owner_first_name'] ?? '');
                    $owner_last = trim($input['owner_last_name'] ?? '');
                    $owner_company = trim($input['owner_company'] ?? '');
                    $owner_phone = trim($input['owner_phone'] ?? '');
                    $owner_mobile = trim($input['owner_mobile'] ?? '');
                    $owner_email = trim($input['owner_email'] ?? '');
                    $owner_street = trim($input['owner_street'] ?? '');
                    $owner_house_number = trim($input['owner_house_number'] ?? '');
                    $owner_postal_code = trim($input['owner_postal_code'] ?? '');
                    $owner_city = trim($input['owner_city'] ?? '');
                    $owner_salutation = trim($input['owner_salutation'] ?? 'Herr');
                    
                    // Validate owner data
                    if (!$owner_first && !$owner_last && !$owner_company) {
                        $pdo->rollBack();
                        api_error('Besitzer: Vor-/Nachname oder Firma erforderlich', 400);
                    }
                    
                    // Generate customer number
                    $customer_number = generateCustomerNumber($pdo);
                    
                    // Create new owner
                    $stmt = $pdo->prepare("
                        INSERT INTO tp_owners (
                            customer_number, salutation, first_name, last_name, company,
                            phone, mobile, email, street, house_number, 
                            postal_code, city, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    
                    $stmt->execute([
                        $customer_number, $owner_salutation, $owner_first, $owner_last, $owner_company,
                        $owner_phone, $owner_mobile, $owner_email, $owner_street, $owner_house_number,
                        $owner_postal_code, $owner_city
                    ]);
                    
                    $owner_id = $pdo->lastInsertId();
                } else if ($owner_mode === 'existing') {
                    // Verify owner exists
                    if (!$owner_id) {
                        $pdo->rollBack();
                        api_error('Besitzer ID erforderlich', 400);
                    }
                    
                    $stmt = $pdo->prepare("SELECT id FROM tp_owners WHERE id = ?");
                    $stmt->execute([$owner_id]);
                    if (!$stmt->fetch()) {
                        $pdo->rollBack();
                        api_error('Besitzer nicht gefunden', 404);
                    }
                }
                
                // Generate patient number
                $patient_number = generatePatientNumber($pdo);
                
                // Create patient
                $stmt = $pdo->prepare("
                    INSERT INTO tp_patients (
                        patient_number, owner_id, name, species, breed, color,
                        gender, birth_date, weight, microchip, medical_history,
                        allergies, medications, notes, is_active, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
                ");
                
                $stmt->execute([
                    $patient_number, $owner_id, $patient_name, $species, $breed, $color,
                    $gender, $birth_date ?: null, $weight ?: null, $microchip,
                    $medical_history, $allergies, $medications, $notes
                ]);
                
                $patient_id = $pdo->lastInsertId();
                
                // Commit transaction
                $pdo->commit();
                
                // Get created patient with owner info
                $stmt = $pdo->prepare("
                    SELECT p.*, 
                        CONCAT_WS(' ', o.first_name, o.last_name) AS owner_full_name,
                        o.customer_number, o.email AS owner_email
                    FROM tp_patients p
                    LEFT JOIN tp_owners o ON p.owner_id = o.id
                    WHERE p.id = ?
                ");
                $stmt->execute([$patient_id]);
                $patient = $stmt->fetch(PDO::FETCH_ASSOC);
                
                api_success(['items' => [$patient]]);
                
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;
            
        case 'update':
            // Get input data
            $input = $_POST;
            if (empty($input)) {
                $json = file_get_contents('php://input');
                $input = json_decode($json, true) ?? [];
            }
            
            $id = intval($input['id'] ?? 0);
            
            if (!$id) {
                api_error('Patient ID fehlt', 400);
            }
            
            // Check if patient exists
            $stmt = $pdo->prepare("SELECT * FROM tp_patients WHERE id = ?");
            $stmt->execute([$id]);
            if (!$stmt->fetch()) {
                api_error('Patient nicht gefunden', 404);
            }
            
            // Get update data
            $patient_name = trim($input['name'] ?? $input['patient_name'] ?? '');
            $species = trim($input['species'] ?? 'other');
            $breed = trim($input['breed'] ?? '');
            $color = trim($input['color'] ?? '');
            $gender = trim($input['gender'] ?? 'unknown');
            $birth_date = trim($input['birth_date'] ?? $input['birthdate'] ?? '');
            $weight = floatval($input['weight'] ?? 0);
            $microchip = trim($input['microchip'] ?? '');
            $notes = trim($input['notes'] ?? '');
            $medical_history = trim($input['medical_history'] ?? '');
            $allergies = trim($input['allergies'] ?? '');
            $medications = trim($input['medications'] ?? '');
            
            if (!$patient_name) {
                api_error('Patientenname ist erforderlich', 400);
            }
            
            // Update patient
            $stmt = $pdo->prepare("
                UPDATE tp_patients SET 
                    name=?, species=?, breed=?, color=?, gender=?,
                    birth_date=?, weight=?, microchip=?, notes=?,
                    medical_history=?, allergies=?, medications=?,
                    updated_at=NOW()
                WHERE id=?
            ");
            
            $stmt->execute([
                $patient_name, $species, $breed, $color, $gender,
                $birth_date ?: null, $weight ?: null, $microchip, $notes,
                $medical_history, $allergies, $medications, $id
            ]);
            
            // Get updated patient
            $stmt = $pdo->prepare("
                SELECT p.*, 
                    CONCAT_WS(' ', o.first_name, o.last_name) AS owner_full_name
                FROM tp_patients p
                LEFT JOIN tp_owners o ON p.owner_id = o.id
                WHERE p.id = ?
            ");
            $stmt->execute([$id]);
            $patient = $stmt->fetch(PDO::FETCH_ASSOC);
            
            api_success(['items' => [$patient]]);
            break;
            
        case 'get_notes':
            $patient_id = intval($_GET['patient_id'] ?? $_GET['id'] ?? 0);
            
            if (!$patient_id) {
                api_error('Patient ID fehlt', 400);
            }
            
            $stmt = $pdo->prepare("
                SELECT id, title, content, type, created_at 
                FROM tp_notes 
                WHERE patient_id = ? AND type = 'general'
                ORDER BY created_at DESC
            ");
            $stmt->execute([$patient_id]);
            $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            api_success(['notes' => $notes]);
            break;
            
        case 'get_records':
            $patient_id = intval($_GET['patient_id'] ?? $_GET['id'] ?? 0);
            
            if (!$patient_id) {
                api_error('Patient ID fehlt', 400);
            }
            
            $stmt = $pdo->prepare("
                SELECT id, title, content, type, created_at 
                FROM tp_notes 
                WHERE patient_id = ? AND type = 'medical'
                ORDER BY created_at DESC
            ");
            $stmt->execute([$patient_id]);
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            api_success(['records' => $records]);
            break;
            
        case 'get_documents':
            $patient_id = intval($_GET['patient_id'] ?? $_GET['id'] ?? 0);
            
            if (!$patient_id) {
                api_error('Patient ID fehlt', 400);
            }
            
            $stmt = $pdo->prepare("
                SELECT id, type, title, file_path, created_at 
                FROM tp_documents 
                WHERE patient_id = ? 
                ORDER BY created_at DESC
            ");
            $stmt->execute([$patient_id]);
            $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            api_success(['documents' => $documents]);
            break;
            
        case 'save_record':
            $input = json_decode(file_get_contents("php://input"), true);
            $patient_id = intval($input['patient_id'] ?? 0);
            $content = trim($input['content'] ?? '');
            
            if (!$patient_id || !$content) {
                api_error('Patient ID und Inhalt sind erforderlich', 400);
            }
            
            // Get user ID from session or use default
            session_start();
            $user_id = $_SESSION['user_id'] ?? 1;
            
            $stmt = $pdo->prepare("
                INSERT INTO tp_notes (patient_id, type, content, created_by, created_at) 
                VALUES (?, 'medical', ?, ?, NOW())
            ");
            $stmt->execute([$patient_id, $content, $user_id]);
            $id = $pdo->lastInsertId();
            
            $stmt = $pdo->prepare("SELECT * FROM tp_notes WHERE id = ?");
            $stmt->execute([$id]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);
            
            api_success(['record' => $record]);
            break;
            
        case 'save_note':
            $input = json_decode(file_get_contents("php://input"), true);
            $patient_id = intval($input['patient_id'] ?? 0);
            $content = trim($input['content'] ?? '');
            
            if (!$patient_id || !$content) {
                api_error('Patient ID und Inhalt sind erforderlich', 400);
            }
            
            // Get user ID from session or use default
            session_start();
            $user_id = $_SESSION['user_id'] ?? 1;
            
            $stmt = $pdo->prepare("
                INSERT INTO tp_notes (patient_id, type, content, created_by, created_at) 
                VALUES (?, 'general', ?, ?, NOW())
            ");
            $stmt->execute([$patient_id, $content, $user_id]);
            $id = $pdo->lastInsertId();
            
            $stmt = $pdo->prepare("SELECT * FROM tp_notes WHERE id = ?");
            $stmt->execute([$id]);
            $note = $stmt->fetch(PDO::FETCH_ASSOC);
            
            api_success(['note' => $note]);
            break;
            
        case 'upload_pdf':
            if (!isset($_FILES['file'])) {
                api_error("Keine Datei empfangen", 400);
            }
            
            $patient_id = intval($_POST['patient_id'] ?? 0);
            if (!$patient_id) {
                api_error('Patient ID fehlt', 400);
            }
            
            $file = $_FILES['file'];
            $filename = basename($file['name']);
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            
            // Validate file type
            $allowed_extensions = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
            if (!in_array(strtolower($extension), $allowed_extensions)) {
                api_error('Ungültiger Dateityp', 400);
            }
            
            // Generate unique filename
            $unique_filename = uniqid() . '_' . $filename;
            $target_dir = __DIR__ . '/../public/uploads/docs/';
            $target_file = $target_dir . $unique_filename;
            
            // Create directory if not exists
            if (!file_exists($target_dir)) {
                mkdir($target_dir, 0755, true);
            }
            
            if (move_uploaded_file($file['tmp_name'], $target_file)) {
                // Get user ID from session or use default
                session_start();
                $user_id = $_SESSION['user_id'] ?? 1;
                
                $stmt = $pdo->prepare("
                    INSERT INTO tp_documents (patient_id, type, title, file_path, uploaded_by, created_at) 
                    VALUES (?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $patient_id, 
                    $extension, 
                    $filename, 
                    '/public/uploads/docs/' . $unique_filename, 
                    $user_id
                ]);
                $id = $pdo->lastInsertId();
                
                $stmt = $pdo->prepare("SELECT * FROM tp_documents WHERE id = ?");
                $stmt->execute([$id]);
                $doc = $stmt->fetch(PDO::FETCH_ASSOC);
                
                api_success(['doc' => $doc]);
            } else {
                api_error('Fehler beim Hochladen der Datei', 500);
            }
            break;
            
        case 'get_appointments':
            $id = intval($_GET['id'] ?? 0);
            
            if (!$id) {
                api_error('Patient ID fehlt', 400);
            }
            
            $stmt = $pdo->prepare("
                SELECT 
                    id, 
                    DATE_FORMAT(appointment_date, '%d.%m.%Y') as appointment_date, 
                    TIME_FORMAT(start_time, '%H:%i') as start_time, 
                    status 
                FROM tp_appointments 
                WHERE patient_id = ? 
                AND appointment_date >= CURDATE() 
                ORDER BY appointment_date ASC, start_time ASC
            ");
            $stmt->execute([$id]);
            $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            api_success(['appointments' => $appointments]);
            break;
            
        case 'delete':
            // Get input data
            $input = $_POST;
            if (empty($input)) {
                $json = file_get_contents('php://input');
                $input = json_decode($json, true) ?? [];
            }
            
            $id = intval($input['id'] ?? $_GET['id'] ?? 0);
            
            if (!$id) {
                api_error('Patient ID fehlt', 400);
            }
            
            // Begin transaction
            $pdo->beginTransaction();
            
            try {
                // Check for related invoices
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tp_invoices WHERE patient_id = ?");
                $stmt->execute([$id]);
                $result = $stmt->fetch();
                
                if ($result['count'] > 0) {
                    $pdo->rollBack();
                    api_error("Patient kann nicht gelöscht werden - hat noch " . $result['count'] . " Rechnung(en)", 400);
                }
                
                // Delete patient (cascades to appointments, treatments, notes)
                $stmt = $pdo->prepare("DELETE FROM tp_patients WHERE id=?");
                $stmt->execute([$id]);
                
                $pdo->commit();
                
                api_success(['items' => []]);
                
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;
            
        default:
            api_error('Unbekannte Aktion: '.$action);
            break;
    }
    
} catch (PDOException $e) {
    error_log("Patients API PDO Error (" . $action . "): " . $e->getMessage());
    api_error('Datenbankfehler aufgetreten');
} catch (Throwable $e) {
    error_log("Patients API Error (" . $action . "): " . $e->getMessage());
    api_error('Serverfehler aufgetreten');
}

// Should never reach here
exit;