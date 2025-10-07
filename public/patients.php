<?php
/**
 * Tierphysio Manager 2.0
 * Patients Management Page
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/version.php';

use TierphysioManager\Auth;
use TierphysioManager\Database;
use TierphysioManager\Template;

// Initialize services
$auth = Auth::getInstance();
$db = Database::getInstance();
$template = Template::getInstance();

// Require login
$auth->requireLogin();
$auth->requirePermission('view_patients');

// Get action
$action = $_GET['action'] ?? 'list';
$id = $_GET['id'] ?? null;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$auth->verifyCSRFToken($_POST['_csrf_token'] ?? '')) {
        Template::setFlash('error', 'Ungültiger Sicherheitstoken.');
    } else {
        switch ($action) {
            case 'create':
                if ($auth->hasPermission('edit_patients')) {
                    try {
                        $patientData = [
                            'patient_number' => 'P' . str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT),
                            'owner_id' => $_POST['owner_id'],
                            'name' => $_POST['name'],
                            'species' => $_POST['species'],
                            'breed' => $_POST['breed'] ?? null,
                            'color' => $_POST['color'] ?? null,
                            'gender' => $_POST['gender'] ?? 'unknown',
                            'birth_date' => $_POST['birth_date'] ?? null,
                            'weight' => $_POST['weight'] ?? null,
                            'microchip' => $_POST['microchip'] ?? null,
                            'medical_history' => $_POST['medical_history'] ?? null,
                            'allergies' => $_POST['allergies'] ?? null,
                            'medications' => $_POST['medications'] ?? null,
                            'notes' => $_POST['notes'] ?? null,
                            'created_by' => $auth->getUserId()
                        ];
                        
                        $patientId = $db->insert('tp_patients', $patientData);
                        $auth->logActivity('patient_created', 'patients', $patientId);
                        
                        Template::setFlash('success', 'Patient erfolgreich angelegt.');
                        header('Location: patients.php?action=view&id=' . $patientId);
                        exit;
                    } catch (Exception $e) {
                        Template::setFlash('error', 'Fehler beim Anlegen des Patienten.');
                    }
                }
                break;
                
            case 'update':
                if ($auth->hasPermission('edit_patients') && $id) {
                    try {
                        $updateData = [
                            'name' => $_POST['name'],
                            'species' => $_POST['species'],
                            'breed' => $_POST['breed'] ?? null,
                            'color' => $_POST['color'] ?? null,
                            'gender' => $_POST['gender'] ?? 'unknown',
                            'birth_date' => $_POST['birth_date'] ?? null,
                            'weight' => $_POST['weight'] ?? null,
                            'microchip' => $_POST['microchip'] ?? null,
                            'medical_history' => $_POST['medical_history'] ?? null,
                            'allergies' => $_POST['allergies'] ?? null,
                            'medications' => $_POST['medications'] ?? null,
                            'notes' => $_POST['notes'] ?? null
                        ];
                        
                        $db->update('tp_patients', $updateData, ['id' => $id]);
                        $auth->logActivity('patient_updated', 'patients', $id);
                        
                        Template::setFlash('success', 'Patient erfolgreich aktualisiert.');
                        header('Location: patients.php?action=view&id=' . $id);
                        exit;
                    } catch (Exception $e) {
                        Template::setFlash('error', 'Fehler beim Aktualisieren des Patienten.');
                    }
                }
                break;
                
            case 'delete':
                if ($auth->hasPermission('edit_patients') && $id) {
                    try {
                        // Check for related records
                        $appointments = $db->count('tp_appointments', ['patient_id' => $id]);
                        $treatments = $db->count('tp_treatments', ['patient_id' => $id]);
                        
                        if ($appointments > 0 || $treatments > 0) {
                            Template::setFlash('error', 'Patient kann nicht gelöscht werden. Es existieren noch Termine oder Behandlungen.');
                        } else {
                            $db->delete('tp_patients', ['id' => $id]);
                            $auth->logActivity('patient_deleted', 'patients', $id);
                            
                            Template::setFlash('success', 'Patient erfolgreich gelöscht.');
                            header('Location: patients.php');
                            exit;
                        }
                    } catch (Exception $e) {
                        Template::setFlash('error', 'Fehler beim Löschen des Patienten.');
                    }
                }
                break;
        }
    }
}

// Prepare data based on action
$data = ['action' => $action];

switch ($action) {
    case 'list':
        // Get filter parameters
        $search = $_GET['search'] ?? '';
        $species = $_GET['species'] ?? '';
        $page = max(1, intval($_GET['page'] ?? 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;
        
        // Build query
        $whereClause = "WHERE p.is_active = 1";
        $params = [];
        
        if ($search) {
            $whereClause .= " AND (p.name LIKE :search OR p.patient_number LIKE :search OR o.last_name LIKE :search)";
            $params['search'] = "%$search%";
        }
        
        if ($species) {
            $whereClause .= " AND p.species = :species";
            $params['species'] = $species;
        }
        
        // Get total count
        $countQuery = "SELECT COUNT(*) as total FROM tp_patients p 
                      LEFT JOIN tp_owners o ON p.owner_id = o.id 
                      $whereClause";
        $stmt = $db->query($countQuery, $params);
        $totalCount = $stmt->fetch()['total'];
        
        // Get patients
        $query = "SELECT p.*, o.first_name as owner_first_name, o.last_name as owner_last_name 
                 FROM tp_patients p 
                 LEFT JOIN tp_owners o ON p.owner_id = o.id 
                 $whereClause 
                 ORDER BY p.created_at DESC 
                 LIMIT $perPage OFFSET $offset";
        
        $stmt = $db->query($query, $params);
        $patients = $stmt->fetchAll();
        
        $data['patients'] = $patients;
        $data['pagination'] = [
            'current' => $page,
            'total' => ceil($totalCount / $perPage),
            'perPage' => $perPage,
            'totalCount' => $totalCount
        ];
        $data['filters'] = [
            'search' => $search,
            'species' => $species
        ];
        break;
        
    case 'view':
        if (!$id) {
            header('Location: patients.php');
            exit;
        }
        
        // Get patient details
        $patient = $db->selectOne('tp_patients', ['id' => $id]);
        if (!$patient) {
            Template::setFlash('error', 'Patient nicht gefunden.');
            header('Location: patients.php');
            exit;
        }
        
        // Get owner
        $owner = $db->selectOne('tp_owners', ['id' => $patient['owner_id']]);
        
        // Get recent appointments
        $appointments = $db->query(
            "SELECT * FROM tp_appointments 
             WHERE patient_id = :patient_id 
             ORDER BY appointment_date DESC, start_time DESC 
             LIMIT 10",
            ['patient_id' => $id]
        )->fetchAll();
        
        // Get recent treatments
        $treatments = $db->query(
            "SELECT t.*, u.first_name as therapist_first_name, u.last_name as therapist_last_name 
             FROM tp_treatments t 
             LEFT JOIN tp_users u ON t.therapist_id = u.id 
             WHERE t.patient_id = :patient_id 
             ORDER BY t.treatment_date DESC 
             LIMIT 10",
            ['patient_id' => $id]
        )->fetchAll();
        
        // Get documents
        $documents = $db->select('tp_documents', ['patient_id' => $id], ['*'], 'created_at DESC');
        
        // Get notes
        $notes = $db->query(
            "SELECT n.*, u.first_name as creator_first_name, u.last_name as creator_last_name 
             FROM tp_notes n 
             LEFT JOIN tp_users u ON n.created_by = u.id 
             WHERE n.patient_id = :patient_id 
             ORDER BY n.created_at DESC 
             LIMIT 10",
            ['patient_id' => $id]
        )->fetchAll();
        
        $data['patient'] = $patient;
        $data['owner'] = $owner;
        $data['appointments'] = $appointments;
        $data['treatments'] = $treatments;
        $data['documents'] = $documents;
        $data['notes'] = $notes;
        break;
        
    case 'new':
    case 'edit':
        if ($action === 'edit' && $id) {
            $patient = $db->selectOne('tp_patients', ['id' => $id]);
            if (!$patient) {
                Template::setFlash('error', 'Patient nicht gefunden.');
                header('Location: patients.php');
                exit;
            }
            $data['patient'] = $patient;
        }
        
        // Get all owners for dropdown
        $owners = $db->select('tp_owners', [], ['id', 'first_name', 'last_name', 'customer_number'], 'last_name, first_name');
        $data['owners'] = $owners;
        break;
}

// Display template
$template->display('pages/patients.twig', $data);