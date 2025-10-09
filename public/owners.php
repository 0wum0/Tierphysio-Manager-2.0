<?php
/**
 * Tierphysio Manager 2.0 - Owners (Besitzer) Page
 * Standalone version (no Composer autoload dependency)
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/template.php';

// Initialize Auth
$auth = new Auth();
$auth->requireLogin();

// Get PDO connection
$pdo = get_pdo();

// Get request parameters
$action = $_GET['action'] ?? 'list';
$id = isset($_GET['id']) ? intval($_GET['id']) : null;
$user = $auth->getUser();

// Initialize template data
$data = [
    'page_title' => 'Besitzer',
    'user' => $user,
    'csrf_token' => $auth->getCSRFToken(),
    'filters' => [],
    'owners' => [],
    'pagination' => []
];

try {
    // Handle view action
    if ($action === 'view' && $id) {
        // Get owner details
        $stmt = $pdo->prepare("SELECT * FROM tp_owners WHERE id = ?");
        $stmt->execute([$id]);
        $owner = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$owner) {
            set_flash('error', 'Besitzer nicht gefunden.');
            header('Location: owners.php');
            exit;
        }
        
        // Get patients of this owner
        $stmt = $pdo->prepare("SELECT * FROM tp_patients WHERE owner_id = ? ORDER BY name");
        $stmt->execute([$id]);
        $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get recent invoices
        $stmt = $pdo->prepare("
            SELECT i.*, p.name as patient_name 
            FROM tp_invoices i 
            LEFT JOIN tp_patients p ON i.patient_id = p.id 
            WHERE i.owner_id = ? 
            ORDER BY i.invoice_date DESC 
            LIMIT 10
        ");
        $stmt->execute([$id]);
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get notes
        $stmt = $pdo->prepare("
            SELECT n.*, u.first_name as creator_first_name, u.last_name as creator_last_name 
            FROM tp_notes n 
            LEFT JOIN tp_users u ON n.created_by = u.id 
            WHERE n.owner_id = ? 
            ORDER BY n.created_at DESC 
            LIMIT 10
        ");
        $stmt->execute([$id]);
        $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Set template data
        $data['action'] = 'view';
        $data['owner'] = $owner;
        $data['patients'] = $patients;
        $data['invoices'] = $invoices;
        $data['notes'] = $notes;
        
        // Render owner view template
        render_template('pages/owner_view.twig', $data);
        exit;
    }
    
    // Handle list action (default)
    $page = max(1, intval($_GET['page'] ?? 1));
    $perPage = 20;
    $offset = ($page - 1) * $perPage;
    $search = trim($_GET['search'] ?? '');
    
    // Build where clause
    $where = '';
    $params = [];
    
    if ($search !== '') {
        $where = "WHERE o.first_name LIKE :search 
                  OR o.last_name LIKE :search 
                  OR o.email LIKE :search 
                  OR o.phone LIKE :search 
                  OR o.customer_number LIKE :search";
        $params['search'] = "%$search%";
    }
    
    // Get total count
    $countQuery = "SELECT COUNT(*) as total FROM tp_owners o $where";
    $stmt = $pdo->prepare($countQuery);
    foreach ($params as $key => $value) {
        $stmt->bindValue(":$key", $value);
    }
    $stmt->execute();
    $totalCount = (int) $stmt->fetchColumn();
    
    // Get owners with patient count
    $query = "SELECT o.*, 
              (SELECT COUNT(*) FROM tp_patients p WHERE p.owner_id = o.id) as patient_count
              FROM tp_owners o 
              $where 
              ORDER BY o.last_name, o.first_name 
              LIMIT :limit OFFSET :offset";
    
    $stmt = $pdo->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue(":$key", $value);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $owners = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Set template data
    $data['owners'] = $owners;
    $data['pagination'] = [
        'current' => $page,
        'total' => ceil($totalCount / $perPage),
        'perPage' => $perPage,
        'totalCount' => $totalCount
    ];
    $data['filters']['search'] = $search;
    
    // Render owners list template
    render_template('pages/owners.twig', $data);
    
} catch (PDOException $e) {
    // Database error
    error_log("Owners page DB error: " . $e->getMessage());
    echo "<pre style='color:red; background: white; padding: 20px; margin: 20px; border: 2px solid red;'>";
    echo "Datenbankfehler:\n";
    if (defined('APP_DEBUG') && APP_DEBUG) {
        echo htmlspecialchars($e->getMessage());
    } else {
        echo "Ein Datenbankfehler ist aufgetreten. Bitte kontaktieren Sie den Administrator.";
    }
    echo "</pre>";
    exit;
    
} catch (Throwable $e) {
    // General error
    error_log("Owners page error: " . $e->getMessage());
    echo "<pre style='color:red; background: white; padding: 20px; margin: 20px; border: 2px solid red;'>";
    echo "Fehler auf der Besitzer-Seite:\n";
    if (defined('APP_DEBUG') && APP_DEBUG) {
        echo htmlspecialchars($e->getMessage()) . "\n";
        echo "File: " . $e->getFile() . " on line " . $e->getLine();
    } else {
        echo "Ein Fehler ist aufgetreten. Bitte kontaktieren Sie den Administrator.";
    }
    echo "</pre>";
    exit;
}