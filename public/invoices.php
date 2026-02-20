<?php
/**
 * Tierphysio Manager 2.0
 * Invoices Management Page (public)
 *
 * Ziel:
 * - Seite /public/invoices.php rendert die Twig-Seite pages/invoices.twig (Alpine + invoices.js)
 * - API läuft separat über /api/invoiced.php (JS lädt dort)
 *
 * Hinweis:
 * - "view" / "new" sind UI-seitig schon verlinkt, aber werden hier aktuell (noch) nicht gerendert.
 *   Damit am Tablet nix "hängt" oder Fehler wirft, leiten wir sauber zurück zur Liste.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/autoload.php';
require_once __DIR__ . '/../includes/version.php';

use TierphysioManager\Auth;
use TierphysioManager\Database;
use TierphysioManager\Template;

// Initialize services
$auth = Auth::getInstance();
$db = Database::getInstance(); // aktuell nicht zwingend genutzt, aber bewusst geladen
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
    'page_title' => 'Rechnungen',
    'user' => $user,
    'action' => $action,
    'id' => $id,
    'csrf_token' => $auth->getCSRFToken(),

    // Optional: falls du das später im Twig nutzen willst
    'api_invoices' => '/api/invoiced.php',
];

// Helper: safe redirect
$redirectToList = function () {
    header('Location: /public/invoices.php?action=list');
    exit;
};

// Route
switch ($action) {
    case 'list':
    default:
        // Wichtig: Twig-Seite übernimmt Laden/Filtern über JS (invoices.js) + API (/api/invoiced.php)
        $template->display('pages/invoices.twig', $data);
        exit;

    case 'view':
        // Detailansicht ist als nächster Schritt dran.
        // Damit nichts kaputt geht: zurück zur Liste.
        if (method_exists($template, 'setFlash')) {
            $template::setFlash('info', 'Detailansicht (Rechnung ansehen) ist noch nicht angebunden.');
        }
        $redirectToList();
        break;

    case 'new':
        // Neuerstellen ist als nächster Schritt dran.
        // Damit nichts kaputt geht: zurück zur Liste.
        if (method_exists($template, 'setFlash')) {
            $template::setFlash('info', 'Rechnung erstellen ist noch nicht angebunden.');
        }
        $redirectToList();
        break;
}