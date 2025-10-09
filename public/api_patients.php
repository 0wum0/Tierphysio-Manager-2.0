<?php
/**
 * Tierphysio Manager 2.0
 * API Wrapper for Patients - Maps old API calls to new structure
 */

// Map get_all action to list action
if (isset($_GET['action']) && $_GET['action'] === 'get_all') {
    $_GET['action'] = 'list';
}

// Forward the request to the actual API
require_once __DIR__ . '/api/patients.php';