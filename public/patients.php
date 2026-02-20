<?php
/**
 * Tierphysio Manager 2.0
 * Patients Management Page
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include template functions
require_once __DIR__ . '/../includes/template.php';

// Check if user is logged in
$user = isset($_SESSION['user']) ? $_SESSION['user'] : null;

// Basic permission check
if (!$user) {
    header('Location: /public/login.php');
    exit;
}

// Get action
$action = $_GET['action'] ?? 'list';
$id = $_GET['id'] ?? null;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Form submissions are handled by the API now
    // This page is just for displaying the interface
}

// Prepare data for the template
$data = [
    'action' => $action,
    'user' => $user
];

// Render the patients page template
// All data loading is done via JavaScript/AJAX
render_template('pages/patients.twig', $data);