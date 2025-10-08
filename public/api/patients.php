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
            $sql = "SELECT p.*, 
                    o.first_name, 
                    o.last_name,
                    o.phone,
                    o.email,
                    o.address
                    FROM patients p 
                    LEFT JOIN owners o ON p.owner_id = o.id 
                    ORDER BY p.id DESC";
            
            $stmt = $pdo->query($sql);
            $patients = $stmt->fetchAll();
            
            // Clean output buffer before sending response
            ob_end_clean();
            json_success($patients);
            break;
            
        case 'get':
            $id = intval($_GET['id'] ?? 0);
            
            if (!$id) {
                ob_end_clean();
                json_error('Patient ID fehlt', 400);
            }
            
            $sql = "SELECT p.*, 
                    o.first_name, 
                    o.last_name,
                    o.phone,
                    o.email,
                    o.address
                    FROM patients p 
                    LEFT JOIN owners o ON p.owner_id = o.id 
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
            $address = trim($_POST['owner_address'] ?? $_POST['address'] ?? '');
            
            $patient_name = trim($_POST['patient_name'] ?? $_POST['name'] ?? '');
            $species = trim($_POST['species'] ?? '');
            $breed = trim($_POST['breed'] ?? '');
            $birthdate = trim($_POST['birthdate'] ?? $_POST['birth_date'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            
            // Validate required fields
            if (!$patient_name || !$owner_first) {
                ob_end_clean();
                json_error("Pflichtfelder fehlen (Patient Name und Besitzer Vorname sind erforderlich)");
            }
            
            // Check if owner exists or create new one
            $stmt = $pdo->prepare("SELECT id FROM owners WHERE first_name=? AND last_name=? LIMIT 1");
            $stmt->execute([$owner_first, $owner_last]);
            $owner = $stmt->fetch();
            
            if (!$owner) {
                // Create new owner
                $stmt = $pdo->prepare("INSERT INTO owners (first_name, last_name, phone, email, address, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$owner_first, $owner_last, $phone, $email, $address]);
                $owner_id = $pdo->lastInsertId();
            } else {
                $owner_id = $owner['id'];
            }
            
            // Create patient
            $stmt = $pdo->prepare("INSERT INTO patients (name, species, breed, birthdate, owner_id, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$patient_name, $species, $breed, $birthdate ?: null, $owner_id, $notes]);
            $patient_id = $pdo->lastInsertId();
            
            ob_end_clean();
            json_success([
                "patient_id" => $patient_id,
                "owner_id" => $owner_id
            ], "Patient erfolgreich angelegt");
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
            $birthdate = trim($_POST['birthdate'] ?? $_POST['birth_date'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            
            if (!$patient_name) {
                ob_end_clean();
                json_error("Patient Name ist erforderlich");
            }
            
            // Update patient
            $stmt = $pdo->prepare("UPDATE patients SET name=?, species=?, breed=?, birthdate=?, notes=?, updated_at=NOW() WHERE id=?");
            $stmt->execute([$patient_name, $species, $breed, $birthdate ?: null, $notes, $id]);
            
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
            $stmt = $pdo->prepare("DELETE FROM patients WHERE id=?");
            $stmt->execute([$id]);
            
            ob_end_clean();
            json_success([], "Patient gelöscht");
            break;
            
        default:
            ob_end_clean();
            json_error("Unbekannte Aktion: " . $action);
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