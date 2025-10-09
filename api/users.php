<?php
/**
 * Users API
 * User management functionality
 */

require_once __DIR__ . '/_bootstrap.php';

// Set JSON headers
header('Content-Type: application/json; charset=UTF-8');

// Admin-only endpoint
if (!$auth->isAdmin()) {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'message' => 'Admin-Rechte erforderlich'
    ]);
    exit;
}

// Get request method
$method = $_SERVER['REQUEST_METHOD'];
$userId = $_GET['id'] ?? null;

try {
    switch ($method) {
        case 'GET':
            if ($userId) {
                // Get single user
                $stmt = $db->prepare("
                    SELECT id, username, first_name, last_name, email, role, is_active, created_at, updated_at,
                           (SELECT MAX(created_at) FROM tp_activity_log WHERE user_id = tp_users.id AND action = 'login') as last_login
                    FROM tp_users 
                    WHERE id = ?
                ");
                $stmt->execute([$userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$user) {
                    http_response_code(404);
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Benutzer nicht gefunden'
                    ]);
                    exit;
                }
                
                unset($user['password_hash']);
                $user['is_active'] = (bool) $user['is_active'];
                
                echo json_encode([
                    'status' => 'success',
                    'data' => $user
                ]);
            } else {
                // Get all users
                $stmt = $db->query("
                    SELECT id, username, first_name, last_name, email, role, is_active, created_at, updated_at,
                           (SELECT MAX(created_at) FROM tp_activity_log WHERE user_id = tp_users.id AND action = 'login') as last_login
                    FROM tp_users
                    ORDER BY last_name, first_name
                ");
                $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($users as &$u) {
                    unset($u['password_hash']);
                    $u['is_active'] = (bool) $u['is_active'];
                }
                
                echo json_encode([
                    'status' => 'success',
                    'data' => $users
                ]);
            }
            break;
            
        case 'POST':
            // Create new user
            $input = json_decode(file_get_contents('php://input'), true);
            
            // Validate input
            $required = ['username', 'first_name', 'last_name', 'email', 'password', 'role'];
            foreach ($required as $field) {
                if (empty($input[$field])) {
                    http_response_code(400);
                    echo json_encode([
                        'status' => 'error',
                        'message' => "Feld '$field' ist erforderlich"
                    ]);
                    exit;
                }
            }
            
            // Check if username exists
            $stmt = $db->prepare("SELECT id FROM tp_users WHERE username = ?");
            $stmt->execute([$input['username']]);
            if ($stmt->fetch()) {
                http_response_code(400);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Benutzername bereits vergeben'
                ]);
                exit;
            }
            
            // Check if email exists
            $stmt = $db->prepare("SELECT id FROM tp_users WHERE email = ?");
            $stmt->execute([$input['email']]);
            if ($stmt->fetch()) {
                http_response_code(400);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'E-Mail-Adresse bereits registriert'
                ]);
                exit;
            }
            
            // Create user
            $stmt = $db->prepare("
                INSERT INTO tp_users (username, first_name, last_name, email, password_hash, role, is_active, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $input['username'],
                $input['first_name'],
                $input['last_name'],
                $input['email'],
                password_hash($input['password'], PASSWORD_DEFAULT),
                $input['role'],
                $input['is_active'] ?? 1
            ]);
            
            $newUserId = $db->lastInsertId();
            
            // Log activity
            $stmt = $db->prepare("
                INSERT INTO tp_activity_log (user_id, action, entity_type, entity_id, ip_address, created_at)
                VALUES (?, 'create', 'user', ?, ?, NOW())
            ");
            $stmt->execute([$user['id'], $newUserId, $_SERVER['REMOTE_ADDR'] ?? '']);
            
            echo json_encode([
                'status' => 'success',
                'message' => 'Benutzer erfolgreich erstellt',
                'data' => ['id' => $newUserId]
            ]);
            break;
            
        case 'PUT':
            // Update user
            if (!$userId) {
                http_response_code(400);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Benutzer-ID erforderlich'
                ]);
                exit;
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            // Build update query
            $updates = [];
            $params = [];
            
            $allowedFields = ['username', 'first_name', 'last_name', 'email', 'role', 'is_active'];
            foreach ($allowedFields as $field) {
                if (isset($input[$field])) {
                    $updates[] = "`$field` = ?";
                    $params[] = $input[$field];
                }
            }
            
            // Update password if provided
            if (!empty($input['password'])) {
                $updates[] = "`password_hash` = ?";
                $params[] = password_hash($input['password'], PASSWORD_DEFAULT);
            }
            
            if (empty($updates)) {
                http_response_code(400);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Keine Änderungen angegeben'
                ]);
                exit;
            }
            
            $updates[] = "`updated_at` = NOW()";
            $params[] = $userId;
            
            $stmt = $db->prepare("
                UPDATE tp_users 
                SET " . implode(', ', $updates) . "
                WHERE id = ?
            ");
            $stmt->execute($params);
            
            // Log activity
            $stmt = $db->prepare("
                INSERT INTO tp_activity_log (user_id, action, entity_type, entity_id, ip_address, created_at)
                VALUES (?, 'update', 'user', ?, ?, NOW())
            ");
            $stmt->execute([$user['id'], $userId, $_SERVER['REMOTE_ADDR'] ?? '']);
            
            echo json_encode([
                'status' => 'success',
                'message' => 'Benutzer aktualisiert'
            ]);
            break;
            
        case 'DELETE':
            // Delete/deactivate user
            if (!$userId) {
                http_response_code(400);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Benutzer-ID erforderlich'
                ]);
                exit;
            }
            
            // Don't delete, just deactivate
            $stmt = $db->prepare("UPDATE tp_users SET is_active = 0, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$userId]);
            
            // Log activity
            $stmt = $db->prepare("
                INSERT INTO tp_activity_log (user_id, action, entity_type, entity_id, ip_address, created_at)
                VALUES (?, 'deactivate', 'user', ?, ?, NOW())
            ");
            $stmt->execute([$user['id'], $userId, $_SERVER['REMOTE_ADDR'] ?? '']);
            
            echo json_encode([
                'status' => 'success',
                'message' => 'Benutzer deaktiviert'
            ]);
            break;
            
        case 'PATCH':
            // Special actions
            if (!$userId) {
                http_response_code(400);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Benutzer-ID erforderlich'
                ]);
                exit;
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $action = $input['action'] ?? '';
            
            switch ($action) {
                case 'toggle_status':
                    // Toggle active status
                    $stmt = $db->prepare("
                        UPDATE tp_users 
                        SET is_active = NOT is_active, updated_at = NOW() 
                        WHERE id = ?
                    ");
                    $stmt->execute([$userId]);
                    
                    echo json_encode([
                        'status' => 'success',
                        'message' => 'Status geändert'
                    ]);
                    break;
                    
                case 'reset_password':
                    // Generate new password
                    $newPassword = bin2hex(random_bytes(4));
                    
                    $stmt = $db->prepare("
                        UPDATE tp_users 
                        SET password_hash = ?, updated_at = NOW() 
                        WHERE id = ?
                    ");
                    $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
                    
                    // Log activity
                    $stmt = $db->prepare("
                        INSERT INTO tp_activity_log (user_id, action, entity_type, entity_id, ip_address, created_at)
                        VALUES (?, 'reset_password', 'user', ?, ?, NOW())
                    ");
                    $stmt->execute([$user['id'], $userId, $_SERVER['REMOTE_ADDR'] ?? '']);
                    
                    echo json_encode([
                        'status' => 'success',
                        'message' => 'Passwort zurückgesetzt',
                        'data' => ['new_password' => $newPassword]
                    ]);
                    break;
                    
                default:
                    http_response_code(400);
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'Unbekannte Aktion'
                    ]);
                    break;
            }
            break;
            
        default:
            http_response_code(405);
            echo json_encode([
                'status' => 'error',
                'message' => 'Methode nicht erlaubt'
            ]);
            break;
    }
    
} catch (Exception $e) {
    error_log('Users API Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Serverfehler: ' . $e->getMessage()
    ]);
}