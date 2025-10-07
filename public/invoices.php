<?php
/**
 * Tierphysio Manager 2.0
 * Invoices Management Page
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
    'page_title' => 'Rechnungen',
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
        $status = $_GET['status'] ?? '';
        $search = $_GET['search'] ?? '';
        $date_from = $_GET['date_from'] ?? '';
        $date_to = $_GET['date_to'] ?? '';
        
        // Build query
        $whereConditions = [];
        $params = [];
        
        if ($status) {
            $whereConditions[] = "i.status = :status";
            $params['status'] = $status;
        }
        
        if ($search) {
            $whereConditions[] = "(i.invoice_number LIKE :search OR o.last_name LIKE :search OR o.first_name LIKE :search)";
            $params['search'] = '%' . $search . '%';
        }
        
        if ($date_from) {
            $whereConditions[] = "i.invoice_date >= :date_from";
            $params['date_from'] = $date_from;
        }
        
        if ($date_to) {
            $whereConditions[] = "i.invoice_date <= :date_to";
            $params['date_to'] = $date_to;
        }
        
        $whereClause = $whereConditions ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
        
        // Get total count
        $countQuery = "SELECT COUNT(*) as total FROM tp_invoices i LEFT JOIN tp_owners o ON i.owner_id = o.id $whereClause";
        $stmt = $db->query($countQuery, $params);
        $totalCount = $stmt->fetch()['total'];
        
        // Get invoices
        $query = "SELECT i.*, 
                 o.first_name as owner_first_name,
                 o.last_name as owner_last_name,
                 o.customer_number,
                 p.name as patient_name
                 FROM tp_invoices i 
                 LEFT JOIN tp_owners o ON i.owner_id = o.id
                 LEFT JOIN tp_patients p ON i.patient_id = p.id
                 $whereClause 
                 ORDER BY i.invoice_date DESC, i.invoice_number DESC 
                 LIMIT $perPage OFFSET $offset";
        
        $stmt = $db->query($query, $params);
        $invoices = $stmt->fetchAll();
        
        // Calculate statistics
        $statsQuery = "SELECT 
                      SUM(CASE WHEN status IN ('sent', 'partially_paid') THEN total - paid_amount ELSE 0 END) as open_amount,
                      SUM(CASE WHEN status = 'overdue' THEN total - paid_amount ELSE 0 END) as overdue_amount,
                      COUNT(CASE WHEN status = 'overdue' THEN 1 END) as overdue_count
                      FROM tp_invoices";
        $stats = $db->query($statsQuery)->fetch();
        
        $data['invoices'] = $invoices;
        $data['stats'] = $stats;
        $data['pagination'] = [
            'current' => $page,
            'total' => ceil($totalCount / $perPage),
            'perPage' => $perPage,
            'totalCount' => $totalCount
        ];
        $data['filters'] = [
            'status' => $status,
            'search' => $search,
            'date_from' => $date_from,
            'date_to' => $date_to
        ];
        break;
        
    case 'view':
        if (!$id) {
            header('Location: invoices.php');
            exit;
        }
        
        // Get invoice details
        $invoice = $db->query(
            "SELECT i.*, 
             o.salutation, o.first_name as owner_first_name, o.last_name as owner_last_name,
             o.street, o.house_number, o.postal_code, o.city, o.country,
             o.email as owner_email, o.customer_number,
             p.name as patient_name
             FROM tp_invoices i 
             LEFT JOIN tp_owners o ON i.owner_id = o.id
             LEFT JOIN tp_patients p ON i.patient_id = p.id
             WHERE i.id = :id",
            ['id' => $id]
        )->fetch();
        
        if (!$invoice) {
            Template::setFlash('error', 'Rechnung nicht gefunden.');
            header('Location: invoices.php');
            exit;
        }
        
        // Get invoice items
        $items = $db->query(
            "SELECT ii.*, t.treatment_date 
             FROM tp_invoice_items ii 
             LEFT JOIN tp_treatments t ON ii.treatment_id = t.id 
             WHERE ii.invoice_id = :invoice_id 
             ORDER BY ii.position",
            ['invoice_id' => $id]
        )->fetchAll();
        
        $data['invoice'] = $invoice;
        $data['items'] = $items;
        break;
}

// Display template
$template->display('pages/invoices.twig', $data);