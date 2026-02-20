<?php
/**
 * Tierphysio Manager 2.0
 * Patients Management Page (Modal-first)
 *
 * - action=view&id=XX wird NICHT mehr als eigene Seite gerendert
 * - stattdessen wird die Liste geladen und das Modal automatisch geöffnet
 */

declare(strict_types=1);

/**
 * Robust autoload resolver (weil dein Projekt teils Root vs public hat)
 */
$autoloadCandidates = [
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
];

$autoloadPath = null;
foreach ($autoloadCandidates as $p) {
    if (is_file($p)) {
        $autoloadPath = $p;
        break;
    }
}

if (!$autoloadPath) {
    http_response_code(500);
    echo "Autoload nicht gefunden. Bitte prüfen: vendor/autoload.php (Root/Public Pfadproblem).";
    exit;
}

require_once $autoloadPath;

// version.php ebenfalls robust suchen
$versionCandidates = [
    __DIR__ . '/includes/version.php',
    __DIR__ . '/../includes/version.php',
    __DIR__ . '/../../includes/version.php',
];
foreach ($versionCandidates as $p) {
    if (is_file($p)) {
        require_once $p;
        break;
    }
}

use TierphysioManager\Auth;
use TierphysioManager\Database;
use TierphysioManager\Template;

// Initialize services
$auth = Auth::getInstance();
$db = Database::getInstance();
$template = Template::getInstance();

// Require login + permission
$auth->requireLogin();
$auth->requirePermission('view_patients');

// action + id
$action = $_GET['action'] ?? 'list';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

/**
 * IMPORTANT:
 * Früher: action=view => patient_detail.twig (komische Seite)
 * Jetzt: action=view => Liste laden + Modal öffnen
 */
$openPatientId = null;
if ($action === 'view' && $id > 0) {
    $openPatientId = $id;
    $action = 'list';
}

// Optional: CSRF Token für deine JS FormData (wenn du ihn im Layout als meta setzt)
$csrfToken = null;
try {
    // Wenn Auth so eine Methode hat – falls nicht, bleibt es null
    if (method_exists($auth, 'getCSRFToken')) {
        $csrfToken = $auth->getCSRFToken();
    }
} catch (Throwable $e) {
    $csrfToken = null;
}

// Data for template
$data = [
    'action' => $action,
    'open_patient_id' => $openPatientId, // <- wird in Twig genutzt um Modal automatisch zu öffnen
    'csrf_token' => $csrfToken,
];

// Render ALWAYS the patients list page (modal-first)
$template->display('pages/patients.twig', $data);
