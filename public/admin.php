<?php
/**
 * Tierphysio Manager 2.0
 * Professional Admin Panel with Settings Integration
 * 
 * @author TierPhysio Team
 * @version 2.0.0
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

// Require admin authentication
$auth->requireLogin();
if (!$auth->isAdmin()) {
    Template::setFlash('error', 'Zugriff verweigert. Admin-Rechte erforderlich.');
    header('Location: index.php');
    exit;
}

// Get current user
$user = $auth->getUser();

// Initialize template data
$data = [
    'page_title' => 'Admin-Panel',
    'user' => $user,
    'csrf_token' => $auth->getCSRFToken(),
    'is_admin' => true,
    'current_page' => ['path' => '/public/admin.php']
];

// Render the admin template
$template->display('pages/admin.twig', $data);