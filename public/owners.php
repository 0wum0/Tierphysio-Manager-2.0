<?php
/**
 * Tierphysio Manager 2.0
 * Owners Management Page
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

// Get action
$action = $_GET['action'] ?? 'list';
$id = $_GET['id'] ?? null;

// Get current user
$user = $auth->getUser();

// Initialize data array for template
$data = [
    'page_title' => 'Besitzer',
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
        $search = $_GET['search'] ?? '';
        
        // Build query
        $whereClause = '';
        $params = [];
        
        if ($search) {
            $whereClause = "WHERE o.first_name LIKE :search 
                          OR o.last_name LIKE :search 
                          OR o.customer_number LIKE :search 
                          OR o.email LIKE :search 
                          OR o.phone LIKE :search";
            $params['search'] = '%' . $search . '%';
        }
        
        // Get total count
        $countQuery = "SELECT COUNT(*) as total FROM tp_owners o $whereClause";
        $stmt = $db->query($countQuery, $params);
        $totalCount = $stmt->fetch()['total'];
        
        // Get owners
        $query = "SELECT o.*, 
                 (SELECT COUNT(*) FROM tp_patients WHERE owner_id = o.id AND is_active = 1) as patient_count
                 FROM tp_owners o 
                 $whereClause 
                 ORDER BY o.last_name, o.first_name 
                 LIMIT $perPage OFFSET $offset";
        
        $stmt = $db->query($query, $params);
        $owners = $stmt->fetchAll();
        
        $data['owners'] = $owners;
        $data['pagination'] = [
            'current' => $page,
            'total' => ceil($totalCount / $perPage),
            'perPage' => $perPage,
            'totalCount' => $totalCount
        ];
        $data['filters'] = [
            'search' => $search
        ];
        break;
        
    case 'view':
        if (!$id) {
            header('Location: owners.php');
            exit;
        }
        
        // Get owner details
        $owner = $db->selectOne('tp_owners', ['id' => $id]);
        if (!$owner) {
            Template::setFlash('error', 'Besitzer nicht gefunden.');
            header('Location: owners.php');
            exit;
        }
        
        // Get patients of this owner
        $patients = $db->query(
            "SELECT * FROM tp_patients 
             WHERE owner_id = :owner_id 
             ORDER BY name",
            ['owner_id' => $id]
        )->fetchAll();
        
        // Get recent invoices
        $invoices = $db->query(
            "SELECT i.*, p.name as patient_name 
             FROM tp_invoices i 
             LEFT JOIN tp_patients p ON i.patient_id = p.id 
             WHERE i.owner_id = :owner_id 
             ORDER BY i.invoice_date DESC 
             LIMIT 10",
            ['owner_id' => $id]
        )->fetchAll();
        
        // Get notes
        $notes = $db->query(
            "SELECT n.*, u.first_name as creator_first_name, u.last_name as creator_last_name 
             FROM tp_notes n 
             LEFT JOIN tp_users u ON n.created_by = u.id 
             WHERE n.owner_id = :owner_id 
             ORDER BY n.created_at DESC 
             LIMIT 10",
            ['owner_id' => $id]
        )->fetchAll();
        
        $data['owner'] = $owner;
        $data['patients'] = $patients;
        $data['invoices'] = $invoices;
        $data['notes'] = $notes;
        break;
}

// Display template
$template->display('pages/owners.twig', $data);