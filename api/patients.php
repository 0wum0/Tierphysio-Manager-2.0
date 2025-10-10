<?php
/**
 * Tierphysio Manager 2.0
 * Patients API Endpoint - Restored Working Version
 */

// 1️⃣ Full safe header + JSON enforcement at top
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Define API response functions if not already defined
if (!function_exists('api_success')) {
    function api_success($data = []) {
        echo json_encode(array_merge(['status' => 'success'], $data), JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('api_error')) {
    function api_error($msg) {
        echo json_encode(['status' => 'error', 'message' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// 3️⃣ Create log directory if missing
$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0775, true);
}

// Get action parameter
$action = $_GET['action'] ?? 'list';

// Get database connection
$pdo = get_pdo();

// Set charset for proper UTF-8 handling (only for MySQL)
try {
    if (!defined('DB_TYPE') || DB_TYPE !== 'sqlite') {
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    }
} catch (Exception $e) {
    api_error('DB-Verbindung fehlgeschlagen: ' . $e->getMessage());
}

// 2️⃣ Restore clean action handlers
switch ($action) {
    case 'list':
        try {
            $sql = "
                SELECT 
                    p.id,
                    p.name,
                    p.species,
                    p.image,
                    p.is_active,
                    COALESCE(CONCAT(o.first_name, ' ', o.last_name), '') AS owner_full_name,
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
                ORDER BY p.created_at DESC
            ";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Ensure proper string formatting
            foreach ($patients as &$p) {
                if (is_numeric($p['owner_full_name'])) {
                    $p['owner_full_name'] = '';
                }
            }
            
            // Format JSON exactly like frontend expects
            echo json_encode([
                "status" => "success",
                "data" => [
                    "items" => $patients
                ],
                "count" => count($patients)
            ], JSON_UNESCAPED_UNICODE);
            exit;
            
        } catch (Exception $e) {
            error_log('[PATIENTS][LIST] ' . $e->getMessage(), 3, __DIR__ . '/../logs/api.log');
            echo json_encode(["status" => "error", "message" => "Fehler beim Laden der Patientenliste"], JSON_UNESCAPED_UNICODE);
            exit;
        }
        break;
        
    case 'search':
        try {
            $keyword = trim($_GET['q'] ?? '');
            
            $sql = "
                SELECT 
                    p.id,
                    p.name,
                    p.species,
                    p.image,
                    p.is_active,
                    COALESCE(CONCAT(o.first_name, ' ', o.last_name), '') AS owner_full_name,
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
            
            if ($keyword) {
                $sql .= " WHERE p.name LIKE :kw OR o.first_name LIKE :kw OR o.last_name LIKE :kw ";
            }
            
            $sql .= " ORDER BY p.created_at DESC";
            $stmt = $pdo->prepare($sql);
            
            if ($keyword) {
                $stmt->bindValue(':kw', "%{$keyword}%");
            }
            $stmt->execute();
            
            $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Ensure proper string formatting
            foreach ($patients as &$p) {
                if (is_numeric($p['owner_full_name'])) {
                    $p['owner_full_name'] = '';
                }
            }
            
            // Format JSON exactly like frontend expects
            echo json_encode([
                "status" => "success",
                "data" => [
                    "items" => $patients
                ],
                "count" => count($patients)
            ], JSON_UNESCAPED_UNICODE);
            exit;
            
        } catch (Exception $e) {
            error_log('[PATIENTS][SEARCH] ' . $e->getMessage(), 3, __DIR__ . '/../logs/api.log');
            echo json_encode(["status" => "error", "message" => "Fehler bei der Suche"], JSON_UNESCAPED_UNICODE);
            exit;
        }
        break;

    case 'get':
        try {
            $id = intval($_GET['id'] ?? 0);
            
            if (!$id) {
                api_error('Patient ID fehlt');
            }
            
            // SQLite-compatible query
            $sql = "
                SELECT p.*, 
                    o.first_name || ' ' || o.last_name AS owner_full_name,
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
                    (SELECT MIN(a.appointment_date)
                     FROM tp_appointments a 
                     WHERE a.patient_id = p.id 
                     AND a.appointment_date >= date('now')
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
                WHERE p.id = ?
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$id]);
            $patient = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($patient) {
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
                
                api_success(['patient' => $patient]);
            } else {
                api_error('Patient nicht gefunden.');
            }
        } catch (Exception $e) {
            error_log('[PATIENTS][GET] ' . $e->getMessage(), 3, __DIR__ . '/../logs/api.log');
            api_error('Fehler beim Laden des Patienten.');
        }
        break;
        
    case 'create':
        try {
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
                api_error('Patientenname ist erforderlich');
            }
            
            if (!$species) {
                api_error('Tierart ist erforderlich');
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
                        api_error('Besitzer: Vor-/Nachname oder Firma erforderlich');
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
                        api_error('Besitzer ID erforderlich');
                    }
                    
                    $stmt = $pdo->prepare("SELECT id FROM tp_owners WHERE id = ?");
                    $stmt->execute([$owner_id]);
                    if (!$stmt->fetch()) {
                        $pdo->rollBack();
                        api_error('Besitzer nicht gefunden');
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
                
                api_success(['patient' => $patient]);
                
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
        } catch (Exception $e) {
            error_log('[PATIENTS][CREATE] ' . $e->getMessage(), 3, __DIR__ . '/../logs/api.log');
            api_error('Fehler beim Erstellen des Patienten.');
        }
        break;
        
    case 'update':
        try {
            // Get input data
            $input = $_POST;
            if (empty($input)) {
                $json = file_get_contents('php://input');
                $input = json_decode($json, true) ?? [];
            }
            
            $id = intval($input['id'] ?? 0);
            
            if (!$id) {
                api_error('Patient ID fehlt');
            }
            
            // Check if patient exists
            $stmt = $pdo->prepare("SELECT * FROM tp_patients WHERE id = ?");
            $stmt->execute([$id]);
            if (!$stmt->fetch()) {
                api_error('Patient nicht gefunden');
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
                api_error('Patientenname ist erforderlich');
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
            
            api_success(['patient' => $patient]);
        } catch (Exception $e) {
            error_log('[PATIENTS][UPDATE] ' . $e->getMessage(), 3, __DIR__ . '/../logs/api.log');
            api_error('Fehler beim Aktualisieren des Patienten.');
        }
        break;
        
    case 'get_notes':
        try {
            $patient_id = intval($_GET['patient_id'] ?? $_GET['id'] ?? 0);
            
            if (!$patient_id) {
                api_error('Patient ID fehlt');
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
        } catch (Exception $e) {
            error_log('[PATIENTS][GET_NOTES] ' . $e->getMessage(), 3, __DIR__ . '/../logs/api.log');
            api_error('Fehler beim Laden der Notizen.');
        }
        break;
        
    case 'get_records':
        try {
            $patient_id = intval($_GET['patient_id'] ?? $_GET['id'] ?? 0);
            
            if (!$patient_id) {
                api_error('Patient ID fehlt');
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
        } catch (Exception $e) {
            error_log('[PATIENTS][GET_RECORDS] ' . $e->getMessage(), 3, __DIR__ . '/../logs/api.log');
            api_error('Fehler beim Laden der Krankenakte.');
        }
        break;
        
    case 'get_documents':
        try {
            $patient_id = intval($_GET['patient_id'] ?? $_GET['id'] ?? 0);
            
            if (!$patient_id) {
                api_error('Patient ID fehlt');
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
        } catch (Exception $e) {
            error_log('[PATIENTS][GET_DOCUMENTS] ' . $e->getMessage(), 3, __DIR__ . '/../logs/api.log');
            api_error('Fehler beim Laden der Dokumente.');
        }
        break;
        
    case 'save_record':
        try {
            $input = json_decode(file_get_contents("php://input"), true);
            $patient_id = intval($input['patient_id'] ?? 0);
            $content = trim($input['content'] ?? '');
            
            if (!$patient_id || !$content) {
                api_error('Patient ID und Inhalt sind erforderlich');
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
        } catch (Exception $e) {
            error_log('[PATIENTS][SAVE_RECORD] ' . $e->getMessage(), 3, __DIR__ . '/../logs/api.log');
            api_error('Fehler beim Speichern des Eintrags.');
        }
        break;
        
    case 'save_note':
        try {
            $input = json_decode(file_get_contents("php://input"), true);
            $patient_id = intval($input['patient_id'] ?? 0);
            $content = trim($input['content'] ?? '');
            
            if (!$patient_id || !$content) {
                api_error('Patient ID und Inhalt sind erforderlich');
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
        } catch (Exception $e) {
            error_log('[PATIENTS][SAVE_NOTE] ' . $e->getMessage(), 3, __DIR__ . '/../logs/api.log');
            api_error('Fehler beim Speichern der Notiz.');
        }
        break;
        
    case 'upload_pdf':
        try {
            if (!isset($_FILES['file'])) {
                api_error("Keine Datei empfangen");
            }
            
            $patient_id = intval($_POST['patient_id'] ?? 0);
            if (!$patient_id) {
                api_error('Patient ID fehlt');
            }
            
            $file = $_FILES['file'];
            $filename = basename($file['name']);
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            
            // Validate file type
            $allowed_extensions = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
            if (!in_array(strtolower($extension), $allowed_extensions)) {
                api_error('Ungültiger Dateityp');
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
                api_error('Fehler beim Hochladen der Datei');
            }
        } catch (Exception $e) {
            error_log('[PATIENTS][UPLOAD_PDF] ' . $e->getMessage(), 3, __DIR__ . '/../logs/api.log');
            api_error('Fehler beim Datei-Upload.');
        }
        break;
        
    case 'get_appointments':
        try {
            $id = intval($_GET['id'] ?? 0);
            
            if (!$id) {
                api_error('Patient ID fehlt');
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
        } catch (Exception $e) {
            error_log('[PATIENTS][GET_APPOINTMENTS] ' . $e->getMessage(), 3, __DIR__ . '/../logs/api.log');
            api_error('Fehler beim Laden der Termine.');
        }
        break;
        
    case 'upload_image':
        try {
            if (empty($_FILES['image']) || empty($_POST['id'])) {
                api_error('Kein Bild oder keine ID übergeben.');
            }

            $id = intval($_POST['id']);
            $file = $_FILES['image'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            // Nur erlaubte Formate
            $allowed = ['jpg','jpeg','png','gif','webp'];
            if (!in_array($ext, $allowed)) {
                api_error('Ungültiges Dateiformat.');
            }

            // Upload-Verzeichnis erstellen falls nicht vorhanden
            $uploadDir = __DIR__ . '/../uploads/patients';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0775, true);
            }

            // Sicherer Dateiname
            $filename = 'patient_'.$id.'_'.time().'.'.$ext;
            $target = $uploadDir . '/' . $filename;

            if (!move_uploaded_file($file['tmp_name'], $target)) {
                api_error('Upload fehlgeschlagen.');
            }

            // Pfad relativ speichern
            $relativePath = 'uploads/patients/'.$filename;
            $stmt = $pdo->prepare("UPDATE tp_patients SET image=? WHERE id=?");
            $stmt->execute([$relativePath, $id]);

            api_success(['message' => 'Bild erfolgreich hochgeladen.', 'path' => $relativePath]);

        } catch (Exception $e) {
            error_log('[PATIENTS][UPLOAD] '.$e->getMessage(), 3, __DIR__.'/../logs/api.log');
            api_error('Fehler beim Hochladen des Bildes.');
        }
        break;
        
    case 'delete':
        try {
            // Get input data
            $input = $_POST;
            if (empty($input)) {
                $json = file_get_contents('php://input');
                $input = json_decode($json, true) ?? [];
            }
            
            $id = intval($input['id'] ?? $_GET['id'] ?? 0);
            
            if (!$id) {
                api_error('Patient ID fehlt');
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
                    api_error("Patient kann nicht gelöscht werden - hat noch " . $result['count'] . " Rechnung(en)");
                }
                
                // Delete patient (cascades to appointments, treatments, notes)
                $stmt = $pdo->prepare("DELETE FROM tp_patients WHERE id=?");
                $stmt->execute([$id]);
                
                $pdo->commit();
                
                api_success(['message' => 'Patient erfolgreich gelöscht']);
                
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
        } catch (Exception $e) {
            error_log('[PATIENTS][DELETE] ' . $e->getMessage(), 3, __DIR__ . '/../logs/api.log');
            api_error('Fehler beim Löschen des Patienten.');
        }
        break;

    default:
        api_error('Unbekannte Aktion: ' . $action);
        break;
}

// Should never reach here
exit;