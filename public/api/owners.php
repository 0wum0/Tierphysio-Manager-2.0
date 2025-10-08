<?php
/**
 * Tierphysio Manager 2.0
 * Owners API Endpoint - Simplified & Fixed Version
 */

// Set JSON header immediately
header('Content-Type: application/json; charset=utf-8');

// Error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Catch any output errors
ob_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/response.php';

// Get action from request
$action = $_GET['action'] ?? 'list';

try {
    $pdo = pdo();
    
    switch ($action) {
        case 'list':
            // Get all owners with patient count
            $sql = "SELECT o.*, 
                    COUNT(p.id) as patient_count
                    FROM owners o 
                    LEFT JOIN patients p ON o.id = p.owner_id
                    GROUP BY o.id
                    ORDER BY o.last_name, o.first_name";
            
            $stmt = $pdo->query($sql);
            $owners = $stmt->fetchAll();
            
            ob_end_clean();
            json_success($owners);
            break;
            
        case 'get':
            $id = intval($_GET['id'] ?? 0);
            
            if (!$id) {
                ob_end_clean();
                json_error('Besitzer ID fehlt', 400);
            }
            
            // Get owner with patients
            $stmt = $pdo->prepare("SELECT * FROM owners WHERE id = ?");
            $stmt->execute([$id]);
            $owner = $stmt->fetch();
            
            if (!$owner) {
                ob_end_clean();
                json_error('Besitzer nicht gefunden', 404);
            }
            
            // Get owner's patients
            $stmt = $pdo->prepare("SELECT * FROM patients WHERE owner_id = ? ORDER BY name");
            $stmt->execute([$id]);
            $owner['patients'] = $stmt->fetchAll();
            
            ob_end_clean();
            json_success($owner);
            break;
            
        case 'create':
            // Get POST data
            $first_name = trim($_POST['first_name'] ?? '');
            $last_name = trim($_POST['last_name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $address = trim($_POST['address'] ?? '');
            
            // Validate required fields
            if (!$first_name || !$last_name) {
                ob_end_clean();
                json_error("Vor- und Nachname sind erforderlich");
            }
            
            // Check if owner already exists
            $stmt = $pdo->prepare("SELECT id FROM owners WHERE first_name=? AND last_name=? LIMIT 1");
            $stmt->execute([$first_name, $last_name]);
            
            if ($stmt->fetch()) {
                ob_end_clean();
                json_error("Ein Besitzer mit diesem Namen existiert bereits");
            }
            
            // Create owner
            $stmt = $pdo->prepare("INSERT INTO owners (first_name, last_name, phone, email, address, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$first_name, $last_name, $phone, $email, $address]);
            $owner_id = $pdo->lastInsertId();
            
            // Get created owner
            $stmt = $pdo->prepare("SELECT * FROM owners WHERE id = ?");
            $stmt->execute([$owner_id]);
            $owner = $stmt->fetch();
            
            ob_end_clean();
            json_success($owner, "Besitzer erfolgreich angelegt");
            break;
            
        case 'update':
            $id = intval($_POST['id'] ?? 0);
            
            if (!$id) {
                ob_end_clean();
                json_error('Besitzer ID fehlt', 400);
            }
            
            // Get update data
            $first_name = trim($_POST['first_name'] ?? '');
            $last_name = trim($_POST['last_name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $address = trim($_POST['address'] ?? '');
            
            if (!$first_name || !$last_name) {
                ob_end_clean();
                json_error("Vor- und Nachname sind erforderlich");
            }
            
            // Update owner
            $stmt = $pdo->prepare("UPDATE owners SET first_name=?, last_name=?, phone=?, email=?, address=?, updated_at=NOW() WHERE id=?");
            $stmt->execute([$first_name, $last_name, $phone, $email, $address, $id]);
            
            // Get updated owner
            $stmt = $pdo->prepare("SELECT * FROM owners WHERE id = ?");
            $stmt->execute([$id]);
            $owner = $stmt->fetch();
            
            ob_end_clean();
            json_success($owner, "Besitzer erfolgreich aktualisiert");
            break;
            
        case 'delete':
            $id = intval($_POST['id'] ?? 0);
            
            if (!$id) {
                ob_end_clean();
                json_error('Besitzer ID fehlt', 400);
            }
            
            // Check if owner has patients
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM patients WHERE owner_id = ?");
            $stmt->execute([$id]);
            $result = $stmt->fetch();
            
            if ($result['count'] > 0) {
                ob_end_clean();
                json_error("Besitzer kann nicht gelöscht werden - hat noch " . $result['count'] . " Patient(en)");
            }
            
            // Delete owner
            $stmt = $pdo->prepare("DELETE FROM owners WHERE id=?");
            $stmt->execute([$id]);
            
            ob_end_clean();
            json_success([], "Besitzer gelöscht");
            break;
            
        default:
            ob_end_clean();
            json_error("Unbekannte Aktion: " . $action);
    }
    
} catch (PDOException $e) {
    error_log("Owners API PDO Error (" . $action . "): " . $e->getMessage());
    ob_end_clean();
    json_error("Datenbankfehler: " . $e->getMessage());
} catch (Throwable $e) {
    error_log("Owners API Error (" . $action . "): " . $e->getMessage());
    ob_end_clean();
    json_error("Serverfehler: " . $e->getMessage());
}

// Ensure no further output
exit;