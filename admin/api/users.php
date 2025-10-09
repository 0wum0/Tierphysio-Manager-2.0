<?php
/**
 * Admin Users Management API
 */

require_once __DIR__ . '/_bootstrap.php';

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

switch ($action) {
    case 'list':
        $stmt = $pdo->query("
            SELECT u.id, u.email, u.name, u.role, u.status, u.created_at,
                   GROUP_CONCAT(r.name) as roles
            FROM tp_users u
            LEFT JOIN tp_user_roles ur ON u.id = ur.user_id
            LEFT JOIN tp_roles r ON ur.role_id = r.id
            GROUP BY u.id
            ORDER BY u.created_at DESC
        ");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        api_success($users, null, count($users));
        break;
        
    case 'get':
        $id = sanitize($_GET['id'] ?? 0, 'int');
        if (!$id) {
            api_error('User ID required');
        }
        
        $stmt = $pdo->prepare("
            SELECT u.*, GROUP_CONCAT(r.id) as role_ids, GROUP_CONCAT(r.name) as role_names
            FROM tp_users u
            LEFT JOIN tp_user_roles ur ON u.id = ur.user_id
            LEFT JOIN tp_roles r ON ur.role_id = r.id
            WHERE u.id = ?
            GROUP BY u.id
        ");
        $stmt->execute([$id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            api_error('User not found', 404);
        }
        
        api_success($user);
        break;
        
    case 'create':
        csrf_check();
        requirePermission('users.manage');
        
        $data = getJsonInput();
        $email = sanitize($data['email'] ?? '', 'email');
        $name = sanitize($data['name'] ?? '');
        $password = $data['password'] ?? '';
        $role = sanitize($data['role'] ?? 'patient');
        $status = sanitize($data['status'] ?? 'active');
        $roleIds = $data['role_ids'] ?? [];
        
        if (!$email || !$name || !$password) {
            api_error('Email, Name und Passwort sind erforderlich');
        }
        
        // Check if email exists
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tp_users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetchColumn() > 0) {
            api_error('E-Mail-Adresse existiert bereits');
        }
        
        try {
            $pdo->beginTransaction();
            
            // Create user
            $stmt = $pdo->prepare("
                INSERT INTO tp_users (email, name, password, role, status, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$email, $name, password_hash($password, PASSWORD_DEFAULT), $role, $status]);
            $userId = $pdo->lastInsertId();
            
            // Assign roles
            if (!empty($roleIds)) {
                $stmt = $pdo->prepare("INSERT INTO tp_user_roles (user_id, role_id) VALUES (?, ?)");
                foreach ($roleIds as $roleId) {
                    $stmt->execute([$userId, $roleId]);
                }
            }
            
            $pdo->commit();
            api_success(['id' => $userId], 'Benutzer erfolgreich erstellt');
        } catch (Exception $e) {
            $pdo->rollBack();
            api_error('Fehler beim Erstellen des Benutzers: ' . $e->getMessage());
        }
        break;
        
    case 'update':
        csrf_check();
        requirePermission('users.manage');
        
        $data = getJsonInput();
        $id = sanitize($data['id'] ?? 0, 'int');
        
        if (!$id) {
            api_error('User ID required');
        }
        
        $updates = [];
        $params = [];
        
        if (isset($data['name'])) {
            $updates[] = 'name = ?';
            $params[] = sanitize($data['name']);
        }
        
        if (isset($data['email'])) {
            $email = sanitize($data['email'], 'email');
            // Check if email exists for another user
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM tp_users WHERE email = ? AND id != ?");
            $stmt->execute([$email, $id]);
            if ($stmt->fetchColumn() > 0) {
                api_error('E-Mail-Adresse wird bereits verwendet');
            }
            $updates[] = 'email = ?';
            $params[] = $email;
        }
        
        if (isset($data['password']) && !empty($data['password'])) {
            $updates[] = 'password = ?';
            $params[] = password_hash($data['password'], PASSWORD_DEFAULT);
        }
        
        if (isset($data['role'])) {
            $updates[] = 'role = ?';
            $params[] = sanitize($data['role']);
        }
        
        if (isset($data['status'])) {
            $updates[] = 'status = ?';
            $params[] = sanitize($data['status']);
        }
        
        try {
            $pdo->beginTransaction();
            
            // Update user
            if (!empty($updates)) {
                $params[] = $id;
                $stmt = $pdo->prepare("UPDATE tp_users SET " . implode(', ', $updates) . " WHERE id = ?");
                $stmt->execute($params);
            }
            
            // Update roles if provided
            if (isset($data['role_ids'])) {
                // Remove existing roles
                $stmt = $pdo->prepare("DELETE FROM tp_user_roles WHERE user_id = ?");
                $stmt->execute([$id]);
                
                // Add new roles
                if (!empty($data['role_ids'])) {
                    $stmt = $pdo->prepare("INSERT INTO tp_user_roles (user_id, role_id) VALUES (?, ?)");
                    foreach ($data['role_ids'] as $roleId) {
                        $stmt->execute([$id, $roleId]);
                    }
                }
            }
            
            $pdo->commit();
            api_success(null, 'Benutzer erfolgreich aktualisiert');
        } catch (Exception $e) {
            $pdo->rollBack();
            api_error('Fehler beim Aktualisieren: ' . $e->getMessage());
        }
        break;
        
    case 'delete':
        csrf_check();
        requirePermission('users.manage');
        
        $id = sanitize($_POST['id'] ?? 0, 'int');
        
        if (!$id) {
            api_error('User ID required');
        }
        
        // Don't delete current admin
        if ($id == $auth->getUserId()) {
            api_error('Sie können sich nicht selbst löschen');
        }
        
        try {
            $stmt = $pdo->prepare("DELETE FROM tp_users WHERE id = ?");
            $stmt->execute([$id]);
            
            if ($stmt->rowCount() > 0) {
                api_success(null, 'Benutzer erfolgreich gelöscht');
            } else {
                api_error('Benutzer nicht gefunden', 404);
            }
        } catch (Exception $e) {
            api_error('Fehler beim Löschen: ' . $e->getMessage());
        }
        break;
        
    case 'roles':
        $stmt = $pdo->query("SELECT * FROM tp_roles ORDER BY name");
        $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        api_success($roles, null, count($roles));
        break;
        
    default:
        api_error('Unbekannte Aktion', 400);
}