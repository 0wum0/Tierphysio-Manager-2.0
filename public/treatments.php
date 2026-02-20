<?php
/**
 * Tierphysio Manager 2.0
 * Treatments Management Page
 */

require_once __DIR__ . '/../includes/autoload.php';
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
    'page_title' => 'Behandlungen',
    'user' => $user,
    'action' => $action,
    'csrf_token' => $auth->getCSRFToken()
];

// Process based on action
switch ($action) {
    case 'list':
    default:
        // Get filter parameters
        $page = max(1, intval($_GET['page'] ?? 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;
        $patient_id = $_GET['patient_id'] ?? '';
        $therapist_id = $_GET['therapist_id'] ?? '';
        $date_from = $_GET['date_from'] ?? '';
        $date_to = $_GET['date_to'] ?? '';
        
        // Build query
        $whereConditions = [];
        $params = [];
        
        if ($patient_id) {
            $whereConditions[] = "t.patient_id = :patient_id";
            $params['patient_id'] = $patient_id;
        }
        
        if ($therapist_id) {
            $whereConditions[] = "t.therapist_id = :therapist_id";
            $params['therapist_id'] = $therapist_id;
        }
        
        if ($date_from) {
            $whereConditions[] = "t.treatment_date >= :date_from";
            $params['date_from'] = $date_from;
        }
        
        if ($date_to) {
            $whereConditions[] = "t.treatment_date <= :date_to";
            $params['date_to'] = $date_to;
        }
        
        $whereClause = $whereConditions ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
        
        // Get total count
        $countQuery = "SELECT COUNT(*) as total FROM tp_treatments t $whereClause";
        $stmt = $db->query($countQuery, $params);
        $totalCount = $stmt->fetch()['total'];
        
        // Get treatments
        $query = "SELECT t.*, 
                 p.name as patient_name, 
                 p.species as patient_species,
                 o.first_name as owner_first_name,
                 o.last_name as owner_last_name,
                 u.first_name as therapist_first_name,
                 u.last_name as therapist_last_name
                 FROM tp_treatments t 
                 LEFT JOIN tp_patients p ON t.patient_id = p.id
                 LEFT JOIN tp_owners o ON p.owner_id = o.id
                 LEFT JOIN tp_users u ON t.therapist_id = u.id
                 $whereClause 
                 ORDER BY t.treatment_date DESC, t.created_at DESC 
                 LIMIT $perPage OFFSET $offset";
        
        $stmt = $db->query($query, $params);
        $treatments = $stmt->fetchAll();
        
        $data['treatments'] = $treatments;
        $data['pagination'] = [
            'current' => $page,
            'total' => ceil($totalCount / $perPage),
            'perPage' => $perPage,
            'totalCount' => $totalCount
        ];
        $data['filters'] = [
            'patient_id' => $patient_id,
            'therapist_id' => $therapist_id,
            'date_from' => $date_from,
            'date_to' => $date_to
        ];
        break;
        
    case 'view':
        if (!$id) {
            header('Location: treatments.php');
            exit;
        }
        
        // Get treatment details
        $treatment = $db->query(
            "SELECT t.*, 
             p.name as patient_name, 
             p.species as patient_species,
             p.breed as patient_breed,
             p.birth_date as patient_birth_date,
             o.first_name as owner_first_name,
             o.last_name as owner_last_name,
             u.first_name as therapist_first_name,
             u.last_name as therapist_last_name
             FROM tp_treatments t 
             LEFT JOIN tp_patients p ON t.patient_id = p.id
             LEFT JOIN tp_owners o ON p.owner_id = o.id
             LEFT JOIN tp_users u ON t.therapist_id = u.id
             WHERE t.id = :id",
            ['id' => $id]
        )->fetch();
        
        if (!$treatment) {
            Template::setFlash('error', 'Behandlung nicht gefunden.');
            header('Location: treatments.php');
            exit;
        }
        
        $data['treatment'] = $treatment;
        break;
}

// Get patients for filter
$data['patients'] = $db->query(
    "SELECT p.id, p.name, o.last_name as owner_last_name 
     FROM tp_patients p 
     LEFT JOIN tp_owners o ON p.owner_id = o.id 
     WHERE p.is_active = 1 
     ORDER BY p.name"
)->fetchAll();

// Get therapists for filter
$data['therapists'] = $db->query(
    "SELECT id, first_name, last_name 
     FROM tp_users 
     WHERE role IN ('admin', 'employee') 
     ORDER BY last_name, first_name"
)->fetchAll();

// Display template
$template->display('pages/treatments.twig', $data);