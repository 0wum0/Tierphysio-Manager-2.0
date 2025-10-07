<?php
/**
 * Tierphysio Manager 2.0
 * Appointments Management Page
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

// Get current user
$user = $auth->getUser();

// Get action
$action = $_GET['action'] ?? 'list';
$id = $_GET['id'] ?? null;

// Initialize data array for template
$data = [
    'page_title' => 'Termine',
    'user' => $user,
    'action' => $action,
    'csrf_token' => $auth->getCSRFToken()
];

// Process based on action
switch ($action) {
    case 'list':
    default:
        // Get filter parameters
        $date = $_GET['date'] ?? date('Y-m-d');
        $view = $_GET['view'] ?? 'day'; // day, week, month
        
        // Get appointments
        $query = "SELECT a.*, 
                 p.name as patient_name, 
                 p.species as patient_species,
                 o.first_name as owner_first_name,
                 o.last_name as owner_last_name,
                 u.first_name as therapist_first_name,
                 u.last_name as therapist_last_name
                 FROM tp_appointments a 
                 LEFT JOIN tp_patients p ON a.patient_id = p.id
                 LEFT JOIN tp_owners o ON p.owner_id = o.id
                 LEFT JOIN tp_users u ON a.therapist_id = u.id
                 WHERE a.appointment_date = :date
                 ORDER BY a.start_time";
        
        $stmt = $db->query($query, ['date' => $date]);
        $appointments = $stmt->fetchAll();
        
        // Get therapists for calendar view
        $therapists = $db->query("SELECT * FROM tp_users WHERE role IN ('admin', 'employee') ORDER BY last_name, first_name")->fetchAll();
        
        $data['appointments'] = $appointments;
        $data['therapists'] = $therapists;
        $data['current_date'] = $date;
        $data['view_type'] = $view;
        break;
        
    case 'view':
        if (!$id) {
            header('Location: appointments.php');
            exit;
        }
        
        // Get appointment details
        $appointment = $db->query(
            "SELECT a.*, 
             p.name as patient_name, 
             p.species as patient_species,
             p.breed as patient_breed,
             o.first_name as owner_first_name,
             o.last_name as owner_last_name,
             o.phone as owner_phone,
             o.mobile as owner_mobile,
             o.email as owner_email,
             u.first_name as therapist_first_name,
             u.last_name as therapist_last_name
             FROM tp_appointments a 
             LEFT JOIN tp_patients p ON a.patient_id = p.id
             LEFT JOIN tp_owners o ON p.owner_id = o.id
             LEFT JOIN tp_users u ON a.therapist_id = u.id
             WHERE a.id = :id",
            ['id' => $id]
        )->fetch();
        
        if (!$appointment) {
            Template::setFlash('error', 'Termin nicht gefunden.');
            header('Location: appointments.php');
            exit;
        }
        
        $data['appointment'] = $appointment;
        break;
}

// Get patients and therapists for forms
$data['patients'] = $db->query(
    "SELECT p.*, o.first_name as owner_first_name, o.last_name as owner_last_name 
     FROM tp_patients p 
     LEFT JOIN tp_owners o ON p.owner_id = o.id 
     WHERE p.is_active = 1 
     ORDER BY p.name"
)->fetchAll();

$data['available_therapists'] = $db->query(
    "SELECT * FROM tp_users 
     WHERE role IN ('admin', 'employee') AND is_active = 1 
     ORDER BY last_name, first_name"
)->fetchAll();

// Display template
$template->display('pages/appointments.twig', $data);