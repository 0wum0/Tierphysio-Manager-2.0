<?php
/**
 * Tierphysio Manager 2.0
 * Patient Detail - View & API
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
                || (isset($_GET['action']) && $_GET['action'] === 'get_by_id');

if ($isApiRequest) {
    // API Mode
    header('Content-Type: application/json; charset=utf-8');
    
    $action = $_GET['action'] ?? 'get_by_id';
    $response = ['status' => 'error', 'message' => 'Invalid action'];
    
    try {
        if ($action === 'get_by_id') {
            $id = intval($_GET['id'] ?? 0);
            if (!$id) {
                throw new Exception('Patient ID fehlt');
            }
            
            // Get patient with owner info
            $query = "SELECT p.*, 
                     o.first_name as owner_first_name, 
                     o.last_name as owner_last_name,
                     o.email as owner_email,
                     o.phone as owner_phone,
                     o.mobile as owner_mobile,
                     o.street as owner_street,
                     o.house_number as owner_house_number,
                     o.postal_code as owner_postal_code,
                     o.city as owner_city
                     FROM tp_patients p 
                     LEFT JOIN tp_owners o ON p.owner_id = o.id 
                     WHERE p.id = :id AND p.is_active = 1";
            
            $stmt = $db->query($query, ['id' => $id]);
            $patient = $stmt->fetch();
            
            if (!$patient) {
                throw new Exception('Patient nicht gefunden');
            }
            
            // Get treatments
            $treatments = $db->query(
                "SELECT t.*, u.first_name as therapist_first_name, u.last_name as therapist_last_name 
                 FROM tp_treatments t 
                 LEFT JOIN tp_users u ON t.therapist_id = u.id 
                 WHERE t.patient_id = :patient_id 
                 ORDER BY t.treatment_date DESC 
                 LIMIT 20",
                ['patient_id' => $id]
            )->fetchAll();
            
            // Get appointments
            $appointments = $db->query(
                "SELECT a.*, u.first_name as therapist_first_name, u.last_name as therapist_last_name 
                 FROM tp_appointments a 
                 LEFT JOIN tp_users u ON a.therapist_id = u.id 
                 WHERE a.patient_id = :patient_id 
                 ORDER BY a.appointment_date DESC, a.start_time DESC 
                 LIMIT 20",
                ['patient_id' => $id]
            )->fetchAll();
            
            // Get invoices
            $invoices = $db->query(
                "SELECT * FROM tp_invoices 
                 WHERE patient_id = :patient_id 
                 ORDER BY invoice_date DESC 
                 LIMIT 20",
                ['patient_id' => $id]
            )->fetchAll();
            
            // Get notes
            $notes = $db->query(
                "SELECT n.*, u.first_name as creator_first_name, u.last_name as creator_last_name 
                 FROM tp_notes n 
                 LEFT JOIN tp_users u ON n.created_by = u.id 
                 WHERE n.patient_id = :patient_id 
                 ORDER BY n.created_at DESC 
                 LIMIT 20",
                ['patient_id' => $id]
            )->fetchAll();
            
            $response = [
                'status' => 'success',
                'data' => [
                    'patient' => $patient,
                    'treatments' => $treatments,
                    'appointments' => $appointments,
                    'invoices' => $invoices,
                    'notes' => $notes
                ]
            ];
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

// View Mode - Display the patient detail page
$id = intval($_GET['id'] ?? 0);
if (!$id) {
    header('Location: /public/patients.php');
    exit;
}

// Get patient data for view
$query = "SELECT p.*, 
         o.first_name as owner_first_name, 
         o.last_name as owner_last_name,
         o.email as owner_email,
         o.phone as owner_phone
         FROM tp_patients p 
         LEFT JOIN tp_owners o ON p.owner_id = o.id 
         WHERE p.id = :id AND p.is_active = 1";

$stmt = $db->query($query, ['id' => $id]);
$patient = $stmt->fetch();

if (!$patient) {
    Template::setFlash('error', 'Patient nicht gefunden');
    header('Location: /public/patients.php');
    exit;
}

$template->display('pages/patient_detail.twig', [
    'title' => 'Patientenakte: ' . $patient['name'],
    'patient' => $patient,
    'user' => $auth->getUser()
]);