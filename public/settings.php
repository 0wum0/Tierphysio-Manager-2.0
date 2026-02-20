<?php
/**
 * Tierphysio Manager 2.0
 * Settings Management Page (public)
 *
 * Ziel:
 * - Seite /public/settings.php rendert die Twig-Seite pages/settings.twig (Shell/UI)
 * - Daten kommen aus /api/settings.php + settings.js / Alpine
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/autoload.php';
require_once __DIR__ . '/../includes/version.php';

use TierphysioManager\Auth;
use TierphysioManager\Database;
use TierphysioManager\Template;

// Initialize services
$auth = Auth::getInstance();
$db = Database::getInstance(); // bewusst geladen wie bei invoices/emails
$template = Template::getInstance();

// Require login + permission
$auth->requireLogin();

// Permission optional, aber bei dir vorhanden:
if (method_exists($auth, 'requirePermission')) {
    $auth->requirePermission('manage_settings');
}

// Get current user
$user = method_exists($auth, 'getUser') ? $auth->getUser() : null;

// Get action (falls du später Router-Logik willst)
$action = (string)($_GET['action'] ?? 'list');

// CSRF
$csrf = '';
try {
    if (method_exists($auth, 'getCSRFToken')) {
        $csrf = (string)$auth->getCSRFToken();
    }
} catch (Throwable $e) {
    $csrf = '';
}

// Base data for template
$data = [
    'page_title'   => 'Einstellungen',
    'user'         => $user,
    'action'       => $action,
    'csrf_token'   => $csrf,

    // Für Sidebar Active-State
    'current_page' => ['path' => '/public/settings.php'],

    // API Endpoint für settings.js / Alpine
    'api_url'      => '/api/settings.php',
];

// Render settings shell (Twig)
$template->display('pages/settings.twig', $data);