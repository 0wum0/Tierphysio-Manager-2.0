<?php
/**
 * Admin Finance Management
 */

require_once __DIR__ . '/../includes/bootstrap.php';

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$auth = new StandaloneAuth($pdo);
$auth->requireLogin();

// Check admin role
$userId = $auth->getUserId();
$stmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM tp_user_roles ur 
    JOIN tp_roles r ON ur.role_id = r.id 
    WHERE ur.user_id = ? AND r.name = 'admin'
");
$stmt->execute([$userId]);

if ($stmt->fetchColumn() == 0) {
    header('Location: /admin/login.php');
    exit;
}

// Generate CSRF token
$csrf_token = $_SESSION['admin']['csrf_token'] ?? '';
if (empty($csrf_token)) {
    $csrf_token = bin2hex(random_bytes(32));
    $_SESSION['admin']['csrf_token'] = $csrf_token;
}

// Get finance items
$stmt = $pdo->query("SELECT * FROM tp_finance_items ORDER BY active DESC, name ASC");
$finance_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get tax settings
$tax_settings = [];
$stmt = $pdo->query("SELECT `key`, value FROM tp_settings WHERE category = 'finance'");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $key = str_replace('finance_', '', $row['key']);
    $tax_settings[$key] = $row['value'];
}

// Default tax values
$tax_settings['default_tax_rate'] = $tax_settings['default_tax_rate'] ?? '19.00';
$tax_settings['currency'] = $tax_settings['currency'] ?? 'EUR';
$tax_settings['currency_symbol'] = $tax_settings['currency_symbol'] ?? '€';

// Calculate some basic statistics (placeholder - would need invoices table)
$stats = [
    'total_revenue_month' => 0,
    'total_revenue_year' => 0,
    'pending_invoices' => 0,
    'overdue_invoices' => 0
];

// If invoices table exists, get real stats
$stmt = $pdo->query("SHOW TABLES LIKE 'tp_invoices'");
if ($stmt->rowCount() > 0) {
    // Monthly revenue
    $stmt = $pdo->query("
        SELECT COALESCE(SUM(total_amount), 0) as total 
        FROM tp_invoices 
        WHERE YEAR(created_at) = YEAR(CURDATE()) 
        AND MONTH(created_at) = MONTH(CURDATE())
        AND status = 'paid'
    ");
    $stats['total_revenue_month'] = $stmt->fetchColumn();
    
    // Yearly revenue
    $stmt = $pdo->query("
        SELECT COALESCE(SUM(total_amount), 0) as total 
        FROM tp_invoices 
        WHERE YEAR(created_at) = YEAR(CURDATE())
        AND status = 'paid'
    ");
    $stats['total_revenue_year'] = $stmt->fetchColumn();
    
    // Pending invoices
    $stmt = $pdo->query("
        SELECT COUNT(*) FROM tp_invoices WHERE status = 'pending'
    ");
    $stats['pending_invoices'] = $stmt->fetchColumn();
    
    // Overdue invoices
    $stmt = $pdo->query("
        SELECT COUNT(*) FROM tp_invoices 
        WHERE status = 'pending' 
        AND due_date < CURDATE()
    ");
    $stats['overdue_invoices'] = $stmt->fetchColumn();
}

// Prepare template data
$templateData = [
    'title' => 'Finanzverwaltung',
    'csrf_token' => $csrf_token,
    'finance_items' => $finance_items,
    'tax_settings' => $tax_settings,
    'stats' => $stats,
    'user' => $auth->getUser()
];

// Render finance template
echo $twig->render('admin/pages/finance.twig', $templateData);