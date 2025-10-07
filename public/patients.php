<?php
/**
 * Tierphysio Manager 2.0
 * Patients Management - View & API
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/version.php';
require_once __DIR__ . '/../includes/db.php';

use TierphysioManager\Auth;
use TierphysioManager\Database;
use TierphysioManager\Template;

// Initialize services
$auth = Auth::getInstance();
$db = Database::getInstance();
$template = Template::getInstance();

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check authentication
$auth->requireLogin();

// Determine if this is an API request
$isApiRequest = (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) 
                || (isset($_GET['action']) && in_array($_GET['action'], ['get_all', 'get_by_id', 'create', 'update', 'delete']));

if ($isApiRequest) {
    // API Mode
    header('Content-Type: application/json; charset=utf-8');
    
    $action = $_GET['action'] ?? '';
    $response = ['status' => 'error', 'message' => 'Invalid action'];
    
    try {
        switch ($action) {
            case 'get_all':
                $search = $_GET['search'] ?? '';
                $species = $_GET['species'] ?? '';
                
                $query = "SELECT p.*, 
                         o.first_name as owner_first_name, 
                         o.last_name as owner_last_name
                         FROM tp_patients p 
                         LEFT JOIN tp_owners o ON p.owner_id = o.id 
                         WHERE p.is_active = 1";
                
                $params = [];
                
                if ($search) {
                    $query .= " AND (p.name LIKE :search OR p.patient_number LIKE :search)";
                    $params['search'] = "%$search%";
                }
                
                if ($species) {
                    $query .= " AND p.species = :species";
                    $params['species'] = $species;
                }
                
                $query .= " ORDER BY p.id DESC";
                
                $stmt = $db->query($query, $params);
                $patients = $stmt->fetchAll();
                
                $response = [
                    'status' => 'success',
                    'data' => $patients
                ];
                break;
                
            case 'get_by_id':
                $id = intval($_GET['id'] ?? 0);
                if (!$id) {
                    throw new Exception('Patient ID fehlt');
                }
                
                $patient = $db->selectOne('tp_patients', ['id' => $id]);
                if (!$patient) {
                    throw new Exception('Patient nicht gefunden');
                }
                
                // Get owner info
                if ($patient['owner_id']) {
                    $owner = $db->selectOne('tp_owners', ['id' => $patient['owner_id']]);
                    $patient['owner'] = $owner;
                }
                
                $response = [
                    'status' => 'success',
                    'data' => $patient
                ];
                break;
                
            case 'create':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    throw new Exception('Nur POST-Methode erlaubt');
                }
                
                $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
                
                // Validate required fields
                if (empty($data['name']) || empty($data['species']) || empty($data['owner_id'])) {
                    throw new Exception('Pflichtfelder fehlen');
                }
                
                // Generate patient number
                $patient_number = 'P' . str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);
                
                $patientData = [
                    'patient_number' => $patient_number,
                    'name' => $data['name'],
                    'species' => $data['species'],
                    'breed' => $data['breed'] ?? null,
                    'gender' => $data['gender'] ?? 'unknown',
                    'birth_date' => $data['birth_date'] ?? null,
                    'weight' => $data['weight'] ?? null,
                    'microchip' => $data['microchip'] ?? null,
                    'owner_id' => $data['owner_id'],
                    'medical_history' => $data['medical_history'] ?? null,
                    'allergies' => $data['allergies'] ?? null,
                    'medications' => $data['medications'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'created_by' => $_SESSION['user_id'] ?? 1
                ];
                
                $patientId = $db->insert('tp_patients', $patientData);
                
                $response = [
                    'status' => 'success',
                    'message' => 'Patient erfolgreich angelegt',
                    'data' => ['id' => $patientId, 'patient_number' => $patient_number]
                ];
                break;
                
            case 'update':
                if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                    throw new Exception('Nur POST-Methode erlaubt');
                }
                
                $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
                
                $id = intval($data['id'] ?? $_GET['id'] ?? 0);
                if (!$id) {
                    throw new Exception('Patient ID fehlt');
                }
                
                $updateData = [
                    'name' => $data['name'],
                    'species' => $data['species'],
                    'breed' => $data['breed'] ?? null,
                    'gender' => $data['gender'] ?? 'unknown',
                    'birth_date' => $data['birth_date'] ?? null,
                    'weight' => $data['weight'] ?? null,
                    'microchip' => $data['microchip'] ?? null,
                    'owner_id' => $data['owner_id'],
                    'medical_history' => $data['medical_history'] ?? null,
                    'allergies' => $data['allergies'] ?? null,
                    'medications' => $data['medications'] ?? null,
                    'notes' => $data['notes'] ?? null
                ];
                
                $db->update('tp_patients', $updateData, ['id' => $id]);
                
                $response = [
                    'status' => 'success',
                    'message' => 'Patient erfolgreich aktualisiert',
                    'data' => ['id' => $id]
                ];
                break;
                
            case 'delete':
                $id = intval($_GET['id'] ?? 0);
                if (!$id) {
                    throw new Exception('Patient ID fehlt');
                }
                
                // Check for related records
                $appointments = $db->count('tp_appointments', ['patient_id' => $id]);
                $treatments = $db->count('tp_treatments', ['patient_id' => $id]);
                
                if ($appointments > 0 || $treatments > 0) {
                    throw new Exception('Patient kann nicht gelöscht werden. Es existieren noch Termine oder Behandlungen.');
                }
                
                // Soft delete
                $db->update('tp_patients', ['is_active' => 0], ['id' => $id]);
                
                $response = [
                    'status' => 'success',
                    'message' => 'Patient erfolgreich gelöscht',
                    'data' => ['id' => $id]
                ];
                break;
                
            default:
                throw new Exception('Unbekannte Aktion: ' . $action);
        }
    } catch (Exception $e) {
        $response = [
            'status' => 'error',
            'message' => $e->getMessage()
        ];
    }
    
    echo json_encode($response);
    exit;
}

// View Mode - Display the patients page
$template->display('pages/patients.twig', [
    'title' => 'Patientenverwaltung',
    'user' => $auth->getUser()
]);