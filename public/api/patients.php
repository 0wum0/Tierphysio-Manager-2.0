<?php
/**
 * Tierphysio Manager 2.0
 * Patients API Endpoint - Simplified & Fixed Version
 */

// Set JSON header immediately
header('Content-Type: application/json; charset=utf-8');

// Error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Catch any output errors
ob_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/response.php';

// Get action from request
$action = $_GET['action'] ?? 'list';

try {
    $pdo = pdo();
    
    switch ($action) {
        case 'list':
            // Get all patients with owner information
            $sql = "SELECT p.id, p.patient_number, p.name, p.species, p.breed, p.gender, p.birth_date,
                    o.first_name, o.last_name, o.customer_number, o.phone, o.email
                    FROM tp_patients p 
                    LEFT JOIN tp_owners o ON p.owner_id = o.id 
                    ORDER BY p.id DESC";
            
            $stmt = $pdo->query($sql);
            $patients = $stmt->fetchAll();
            
            // Clean output buffer before sending response
            ob_end_clean();
            json_success(['items' => $patients]);
            break;
            
        case 'get':
            $id = intval($_GET['id'] ?? 0);
            
            if (!$id) {
                ob_end_clean();
                json_error('Patient ID fehlt', 400);
            }
            
            $sql = "SELECT p.*, 
                    o.first_name, o.last_name, o.customer_number,
                    o.email, o.phone,
                    o.street, o.house_number, o.postal_code, o.city, o.country
                    FROM tp_patients p 
                    LEFT JOIN tp_owners o ON p.owner_id = o.id 
                    WHERE p.id = :id";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['id' => $id]);
            $patient = $stmt->fetch();
            
            if (!$patient) {
                ob_end_clean();
                json_error('Patient nicht gefunden', 404);
            }
            
            ob_end_clean();
            json_success($patient);
            break;
            
        case 'create':
            // Get POST data
            $owner_first = trim($_POST['owner_first_name'] ?? $_POST['owner_first'] ?? '');
            $owner_last = trim($_POST['owner_last_name'] ?? $_POST['owner_last'] ?? '');
            $phone = trim($_POST['owner_phone'] ?? $_POST['phone'] ?? '');
            $email = trim($_POST['owner_email'] ?? $_POST['email'] ?? '');
            $street = trim($_POST['street'] ?? '');
            $house_number = trim($_POST['house_number'] ?? '');
            $postal_code = trim($_POST['postal_code'] ?? '');
            $city = trim($_POST['city'] ?? '');
            $country = trim($_POST['country'] ?? 'Deutschland');
            
            $patient_name = trim($_POST['patient_name'] ?? $_POST['name'] ?? '');
            $species = trim($_POST['species'] ?? 'other');
            $breed = trim($_POST['breed'] ?? '');
            $color = trim($_POST['color'] ?? '');
            $gender = trim($_POST['gender'] ?? 'unknown');
            $birth_date = trim($_POST['birth_date'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            
            // Validate required fields
            if (!$patient_name || !$owner_first || !$species) {
                ob_end_clean();
                json_error("Pflichtfelder fehlen (Patient Name, Species und Besitzer Vorname sind erforderlich)", 400);
            }
            
            // Validate enums
            $valid_species = ['dog', 'cat', 'horse', 'rabbit', 'bird', 'reptile', 'other'];
            $valid_genders = ['male', 'female', 'neutered_male', 'spayed_female', 'unknown'];
            
            if (!in_array($species, $valid_species)) {
                ob_end_clean();
                json_error("Ungültige Species: $species", 400);
            }
            
            if (!in_array($gender, $valid_genders)) {
                ob_end_clean();
                json_error("Ungültiges Geschlecht: $gender", 400);
            }
            
            // Check if owner exists or create new one
            $stmt = $pdo->prepare("SELECT id FROM tp_owners WHERE first_name=? AND last_name=? AND (phone=? OR email=?) LIMIT 1");
            $stmt->execute([$owner_first, $owner_last, $phone ?: '', $email ?: '']);
            $owner = $stmt->fetch();
            
            if (!$owner) {
                // Generate customer number
                $customer_number = 'O' . date('ymd') . rand(1000, 9999);
                
                // Create new owner
                $stmt = $pdo->prepare("INSERT INTO tp_owners (customer_number, first_name, last_name, phone, email, street, house_number, postal_code, city, country, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$customer_number, $owner_first, $owner_last, $phone, $email, $street, $house_number, $postal_code, $city, $country]);
                $owner_id = $pdo->lastInsertId();
            } else {
                $owner_id = $owner['id'];
            }
            
            // Generate patient number
            $patient_number = 'P' . date('ymd') . rand(1000, 9999);
            
            // Create patient
            $stmt = $pdo->prepare("INSERT INTO tp_patients (patient_number, name, species, breed, color, gender, birth_date, owner_id, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$patient_number, $patient_name, $species, $breed, $color, $gender, $birth_date ?: null, $owner_id, $notes]);
            $patient_id = $pdo->lastInsertId();
            
            ob_end_clean();
            json_success([
                "patient_id" => $patient_id,
                "owner_id" => $owner_id
            ], "Patient erfolgreich angelegt", 201);
            break;
            
        case 'update':
            $id = intval($_POST['id'] ?? 0);
            
            if (!$id) {
                ob_end_clean();
                json_error('Patient ID fehlt', 400);
            }
            
            // Get update data
            $patient_name = trim($_POST['patient_name'] ?? $_POST['name'] ?? '');
            $species = trim($_POST['species'] ?? '');
            $breed = trim($_POST['breed'] ?? '');
            $color = trim($_POST['color'] ?? '');
            $gender = trim($_POST['gender'] ?? 'unknown');
            $birth_date = trim($_POST['birth_date'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            
            if (!$patient_name) {
                ob_end_clean();
                json_error("Patient Name ist erforderlich", 400);
            }
            
            // Validate enums if provided
            if ($species) {
                $valid_species = ['dog', 'cat', 'horse', 'rabbit', 'bird', 'reptile', 'other'];
                if (!in_array($species, $valid_species)) {
                    ob_end_clean();
                    json_error("Ungültige Species: $species", 400);
                }
            }
            
            if ($gender) {
                $valid_genders = ['male', 'female', 'neutered_male', 'spayed_female', 'unknown'];
                if (!in_array($gender, $valid_genders)) {
                    ob_end_clean();
                    json_error("Ungültiges Geschlecht: $gender", 400);
                }
            }
            
            // Update patient
            $stmt = $pdo->prepare("UPDATE tp_patients SET name=?, species=?, breed=?, color=?, gender=?, birth_date=?, notes=?, updated_at=NOW() WHERE id=?");
            $stmt->execute([$patient_name, $species, $breed, $color, $gender, $birth_date ?: null, $notes, $id]);
            
            ob_end_clean();
            json_success(["patient_id" => $id], "Patient erfolgreich aktualisiert");
            break;
            
        case 'delete':
            $id = intval($_POST['id'] ?? 0);
            
            if (!$id) {
                ob_end_clean();
                json_error('Patient ID fehlt', 400);
            }
            
            // Delete patient
            $stmt = $pdo->prepare("DELETE FROM tp_patients WHERE id=?");
            $stmt->execute([$id]);
            
            ob_end_clean();
            json_success([], "Patient gelöscht");
            break;
            
        default:
            ob_end_clean();
            json_error("Unbekannte Aktion: " . $action, 400);
    }
    
} catch (PDOException $e) {
    error_log("Patients API PDO Error (" . $action . "): " . $e->getMessage());
    ob_end_clean();
    json_error("Datenbankfehler: " . $e->getMessage());
} catch (Throwable $e) {
    error_log("Patients API Error (" . $action . "): " . $e->getMessage());
    ob_end_clean();
    json_error("Serverfehler: " . $e->getMessage());
}

// Ensure no further output
exit;