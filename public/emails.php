<?php
/**
 * Tierphysio Manager 2.0
 * Emails Management Page (public)
 *
 * Ziel:
 * - Seite /public/emails.php rendert die Twig-Seite pages/emails.twig (UI + JS lädt API)
 * - API läuft separat über /api/emails.php
 *
 * Hinweis:
 * - action=view/new können später ergänzt werden, aktuell bleiben wir "modal/JS-first"
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/version.php';

use TierphysioManager\Auth;
use TierphysioManager\Database;
use TierphysioManager\Template;

// Initialize services
$auth = Auth::getInstance();
$db = Database::getInstance(); // optional, aber bewusst geladen wie bei invoices.php
$template = Template::getInstance();

// Require login
$auth->requireLogin();

// Get current user
$user = $auth->getUser();

// Get action
$action = (string)($_GET['action'] ?? 'list');
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Base data for template
$data = [
    'page_title'   => 'Emails',
    'user'         => $user,
    'action'       => $action,
    'id'           => $id,
    'csrf_token'   => $auth->getCSRFToken(),

    // Für Sidebar Active-State (dein Sidebar-Twig nutzt current_page.path)
    'current_page' => ['path' => '/public/emails.php'],

    // Von emails.twig genutzt (apiUrl)
    'api_url'      => '/api/emails.php',
];

// Helper: safe redirect
$redirectToList = function (): void {
    header('Location: /public/emails.php?action=list');
    exit;
};

// Route
switch ($action) {
    case 'list':
    default:
        // Twig-Seite übernimmt Laden/Empfangen/Senden über JS + API (/api/emails.php)
        $template->display('pages/emails.twig', $data);
        exit;

    case 'view':
        // optional später – aktuell zurück zur Liste, damit nichts kaputt geht
        if (method_exists($template, 'setFlash')) {
            $template::setFlash('info', 'Detailansicht (Email ansehen) ist noch nicht als eigene Route angebunden.');
        }
        $redirectToList();
        break;

    case 'new':
        // optional später – aktuell zurück zur Liste
        if (method_exists($template, 'setFlash')) {
            $template::setFlash('info', 'Email verfassen läuft aktuell über das Compose-Modal.');
        }
        $redirectToList();
        break;
}