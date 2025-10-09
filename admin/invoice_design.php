<?php
/**
 * Admin Invoice Design Management
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

// Get current invoice design settings
$stmt = $pdo->query("SELECT * FROM tp_invoice_design WHERE id = 1");
$design = $stmt->fetch(PDO::FETCH_ASSOC);

// Default values if not set
if (!$design) {
    $design = [
        'logo_path' => '',
        'color_primary' => '#9b5de5',
        'color_accent' => '#7C4DFF',
        'header_text' => "Tierphysiotherapie Praxis\nMusterstraße 123\n12345 Musterstadt\nTel: 0123-456789\nE-Mail: info@praxis.de",
        'footer_text' => "Bankverbindung: Musterbank\nIBAN: DE12 3456 7890 1234 5678 90\nBIC: MUSTDEFF\nSteuernummer: 123/456/78901"
    ];
}

// Get practice info for preview
$practice_info = [];
$stmt = $pdo->query("SELECT `key`, value FROM tp_settings WHERE `key` IN ('practice_name', 'practice_address', 'practice_phone', 'practice_email')");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $practice_info[$row['key']] = $row['value'];
}

// Sample invoice data for preview
$sample_invoice = [
    'invoice_number' => 'RE-2025-0001',
    'invoice_date' => date('d.m.Y'),
    'due_date' => date('d.m.Y', strtotime('+14 days')),
    'patient_name' => 'Max Mustermann',
    'patient_address' => "Beispielweg 42\n98765 Musterstadt",
    'items' => [
        ['description' => 'Physiotherapie Erstbehandlung', 'quantity' => 1, 'price' => 75.00, 'tax' => 19],
        ['description' => 'Manuelle Therapie', 'quantity' => 2, 'price' => 45.00, 'tax' => 19],
        ['description' => 'Elektrotherapie', 'quantity' => 1, 'price' => 30.00, 'tax' => 19]
    ],
    'subtotal' => 195.00,
    'tax_amount' => 37.05,
    'total' => 232.05
];

// Prepare template data
$templateData = [
    'title' => 'Rechnungsdesign',
    'csrf_token' => $csrf_token,
    'design' => $design,
    'practice_info' => $practice_info,
    'sample_invoice' => $sample_invoice,
    'user' => $auth->getUser()
];

// Render invoice design template
echo $twig->render('admin/pages/invoice_design.twig', $templateData);