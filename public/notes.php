<?php
/**
 * Tierphysio Manager 2.0
 * Notes Management Page
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
    'page_title' => 'Notizen',
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
        $type = $_GET['type'] ?? '';
        $search = $_GET['search'] ?? '';
        $patient_id = $_GET['patient_id'] ?? '';
        $owner_id = $_GET['owner_id'] ?? '';
        
        // Build query
        $whereConditions = [];
        $params = [];
        
        if ($type) {
            $whereConditions[] = "n.type = :type";
            $params['type'] = $type;
        }
        
        if ($search) {
            $whereConditions[] = "(n.title LIKE :search OR n.content LIKE :search)";
            $params['search'] = '%' . $search . '%';
        }
        
        if ($patient_id) {
            $whereConditions[] = "n.patient_id = :patient_id";
            $params['patient_id'] = $patient_id;
        }
        
        if ($owner_id) {
            $whereConditions[] = "n.owner_id = :owner_id";
            $params['owner_id'] = $owner_id;
        }
        
        $whereClause = $whereConditions ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
        
        // Get total count
        $countQuery = "SELECT COUNT(*) as total FROM tp_notes n $whereClause";
        $stmt = $db->query($countQuery, $params);
        $totalCount = $stmt->fetch()['total'];
        
        // Get notes
        $query = "SELECT n.*, 
                 p.name as patient_name,
                 o.first_name as owner_first_name,
                 o.last_name as owner_last_name,
                 u.first_name as creator_first_name,
                 u.last_name as creator_last_name
                 FROM tp_notes n 
                 LEFT JOIN tp_patients p ON n.patient_id = p.id
                 LEFT JOIN tp_owners o ON n.owner_id = o.id
                 LEFT JOIN tp_users u ON n.created_by = u.id
                 $whereClause 
                 ORDER BY n.is_pinned DESC, n.created_at DESC 
                 LIMIT $perPage OFFSET $offset";
        
        $stmt = $db->query($query, $params);
        $notes = $stmt->fetchAll();
        
        $data['notes'] = $notes;
        $data['pagination'] = [
            'current' => $page,
            'total' => ceil($totalCount / $perPage),
            'perPage' => $perPage,
            'totalCount' => $totalCount
        ];
        $data['filters'] = [
            'type' => $type,
            'search' => $search,
            'patient_id' => $patient_id,
            'owner_id' => $owner_id
        ];
        
        // Get patients and owners for filter
        $data['patients'] = $db->query(
            "SELECT id, name FROM tp_patients WHERE is_active = 1 ORDER BY name"
        )->fetchAll();
        
        $data['owners'] = $db->query(
            "SELECT id, first_name, last_name FROM tp_owners ORDER BY last_name, first_name"
        )->fetchAll();
        break;
        
    case 'view':
        if (!$id) {
            header('Location: notes.php');
            exit;
        }
        
        // Get note details
        $note = $db->query(
            "SELECT n.*, 
             p.name as patient_name,
             o.first_name as owner_first_name,
             o.last_name as owner_last_name,
             u.first_name as creator_first_name,
             u.last_name as creator_last_name
             FROM tp_notes n 
             LEFT JOIN tp_patients p ON n.patient_id = p.id
             LEFT JOIN tp_owners o ON n.owner_id = o.id
             LEFT JOIN tp_users u ON n.created_by = u.id
             WHERE n.id = :id",
            ['id' => $id]
        )->fetch();
        
        if (!$note) {
            Template::setFlash('error', 'Notiz nicht gefunden.');
            header('Location: notes.php');
            exit;
        }
        
        $data['note'] = $note;
        break;
}

// Display template
$template->display('pages/notes.twig', $data);