<?php
/**
 * Tierphysio Manager 2.0
 * Global Search API Endpoint
 * 
 * Provides unified search across patients and owners
 */

// Set JSON header first
header('Content-Type: application/json; charset=utf-8');

// Include required files
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Define API response functions if not already defined
if (!function_exists('api_success')) {
    function api_success($data = []) {
        echo json_encode(array_merge(['status' => 'success'], $data), JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('api_error')) {
    function api_error($msg) {
        echo json_encode(['status' => 'error', 'message' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Get search query
$q = trim($_GET['q'] ?? '');

// Return empty results if no query
if ($q === '') {
    api_success(['results' => []]);
}

try {
    // Get database connection
    $pdo = get_pdo();
    
    // Set charset for proper UTF-8 handling (only for MySQL)
    if (!defined('DB_TYPE') || DB_TYPE !== 'sqlite') {
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    }
    
    $results = [];
    
    // Search patients
    $stmt = $pdo->prepare("
        SELECT 
            id,
            name,
            'patient' AS type,
            species,
            image
        FROM tp_patients 
        WHERE name LIKE ? 
        ORDER BY name ASC
        LIMIT 5
    ");
    $stmt->execute(["%$q%"]);
    $patientResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Add species emoji to patient results
    foreach ($patientResults as &$patient) {
        $speciesEmoji = [
            'dog' => '🐕',
            'cat' => '🐈',
            'horse' => '🐴',
            'rabbit' => '🐰',
            'bird' => '🦜',
            'reptile' => '🦎',
            'other' => '🐾'
        ];
        $patient['icon'] = $speciesEmoji[$patient['species']] ?? '🐾';
        $patient['subtitle'] = $patient['icon'] . ' Patient';
    }
    
    $results = array_merge($results, $patientResults);
    
    // Search owners
    // SQLite vs MySQL compatibility for string concatenation
    if (defined('DB_TYPE') && DB_TYPE === 'sqlite') {
        $ownerNameField = "first_name || ' ' || last_name";
    } else {
        $ownerNameField = "CONCAT(first_name, ' ', last_name)";
    }
    
    $stmt = $pdo->prepare("
        SELECT 
            id,
            $ownerNameField AS name,
            'owner' AS type,
            customer_number
        FROM tp_owners 
        WHERE first_name LIKE ? 
           OR last_name LIKE ?
           OR customer_number LIKE ?
        ORDER BY last_name, first_name ASC
        LIMIT 5
    ");
    $stmt->execute(["%$q%", "%$q%", "%$q%"]);
    $ownerResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Add subtitle to owner results
    foreach ($ownerResults as &$owner) {
        $owner['icon'] = '👤';
        $owner['subtitle'] = '👤 Besitzer' . ($owner['customer_number'] ? ' - ' . $owner['customer_number'] : '');
    }
    
    $results = array_merge($results, $ownerResults);
    
    // Also search for patients by owner name
    $stmt = $pdo->prepare("
        SELECT DISTINCT
            p.id,
            p.name,
            'patient' AS type,
            p.species,
            p.image,
            $ownerNameField AS owner_name
        FROM tp_patients p
        LEFT JOIN tp_owners o ON o.id = p.owner_id
        WHERE o.first_name LIKE ? 
           OR o.last_name LIKE ?
        ORDER BY p.name ASC
        LIMIT 3
    ");
    $stmt->execute(["%$q%", "%$q%"]);
    $ownerPatientResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Add owner info to subtitle
    foreach ($ownerPatientResults as &$patient) {
        $speciesEmoji = [
            'dog' => '🐕',
            'cat' => '🐈',
            'horse' => '🐴',
            'rabbit' => '🐰',
            'bird' => '🦜',
            'reptile' => '🦎',
            'other' => '🐾'
        ];
        $patient['icon'] = $speciesEmoji[$patient['species']] ?? '🐾';
        $patient['subtitle'] = $patient['icon'] . ' Patient von ' . $patient['owner_name'];
    }
    
    $results = array_merge($results, $ownerPatientResults);
    
    // Remove duplicates by ID and type
    $uniqueResults = [];
    $seen = [];
    foreach ($results as $result) {
        $key = $result['type'] . '_' . $result['id'];
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $uniqueResults[] = $result;
        }
    }
    
    // Limit total results to 10
    $uniqueResults = array_slice($uniqueResults, 0, 10);
    
    api_success(['results' => $uniqueResults]);
    
} catch (Exception $e) {
    error_log('[SEARCH] Error: ' . $e->getMessage());
    api_error('Suchfehler: ' . $e->getMessage());
}