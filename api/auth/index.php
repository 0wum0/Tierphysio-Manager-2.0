<?php
/**
 * Tierphysio Manager 2.0
 * Authentication API
 */

// Parse request body
$input = json_decode(file_get_contents('php://input'), true);

switch ($action) {
    case 'login':
        if ($method !== 'POST') {
            apiError('Method not allowed', 405);
        }
        
        $username = $input['username'] ?? '';
        $password = $input['password'] ?? '';
        $remember = $input['remember'] ?? false;
        
        if (empty($username) || empty($password)) {
            apiError('Username and password are required');
        }
        
        $result = $auth->login($username, $password, $remember);
        
        if ($result['success']) {
            apiResponse([
                'token' => $result['token'],
                'user' => $result['user']
            ], 200, $result['message']);
        } else {
            apiError($result['message'], 401);
        }
        break;
        
    case 'logout':
        if ($method !== 'POST') {
            apiError('Method not allowed', 405);
        }
        
        $auth->logout();
        apiResponse(null, 200, 'Logged out successfully');
        break;
        
    case 'register':
        if ($method !== 'POST') {
            apiError('Method not allowed', 405);
        }
        
        // Check if registration is allowed
        if (!$auth->isAdmin()) {
            apiError('Only administrators can register new users', 403);
        }
        
        $requiredFields = ['username', 'email', 'password', 'first_name', 'last_name', 'role'];
        foreach ($requiredFields as $field) {
            if (empty($input[$field])) {
                apiError("Field '$field' is required");
            }
        }
        
        // Check if username or email already exists
        $existingUser = $db->selectOne('tp_users', ['username' => $input['username']]);
        if ($existingUser) {
            apiError('Username already exists');
        }
        
        $existingEmail = $db->selectOne('tp_users', ['email' => $input['email']]);
        if ($existingEmail) {
            apiError('Email already exists');
        }
        
        // Create user
        try {
            $userId = $db->insert('tp_users', [
                'username' => $input['username'],
                'email' => $input['email'],
                'password' => password_hash($input['password'], PASSWORD_BCRYPT),
                'first_name' => $input['first_name'],
                'last_name' => $input['last_name'],
                'role' => $input['role'],
                'phone' => $input['phone'] ?? null,
                'address' => $input['address'] ?? null,
                'is_active' => 1
            ]);
            
            $auth->logActivity('user_created', 'users', $userId);
            
            apiResponse(['user_id' => $userId], 201, 'User created successfully');
        } catch (Exception $e) {
            apiError('Failed to create user');
        }
        break;
        
    case 'forgot-password':
        if ($method !== 'POST') {
            apiError('Method not allowed', 405);
        }
        
        $email = $input['email'] ?? '';
        
        if (empty($email)) {
            apiError('Email is required');
        }
        
        $result = $auth->requestPasswordReset($email);
        apiResponse(null, 200, $result['message']);
        break;
        
    case 'reset-password':
        if ($method !== 'POST') {
            apiError('Method not allowed', 405);
        }
        
        $token = $input['token'] ?? '';
        $password = $input['password'] ?? '';
        
        if (empty($token) || empty($password)) {
            apiError('Token and password are required');
        }
        
        if (strlen($password) < 8) {
            apiError('Password must be at least 8 characters long');
        }
        
        $result = $auth->resetPassword($token, $password);
        
        if ($result['success']) {
            apiResponse(null, 200, $result['message']);
        } else {
            apiError($result['message']);
        }
        break;
        
    case 'verify':
        if ($method !== 'GET') {
            apiError('Method not allowed', 405);
        }
        
        if ($auth->isLoggedIn()) {
            apiResponse([
                'authenticated' => true,
                'user' => $auth->getUser()
            ]);
        } else {
            apiResponse(['authenticated' => false]);
        }
        break;
        
    case 'change-password':
        if ($method !== 'POST') {
            apiError('Method not allowed', 405);
        }
        
        $currentPassword = $input['current_password'] ?? '';
        $newPassword = $input['new_password'] ?? '';
        
        if (empty($currentPassword) || empty($newPassword)) {
            apiError('Current and new passwords are required');
        }
        
        if (strlen($newPassword) < 8) {
            apiError('Password must be at least 8 characters long');
        }
        
        // Verify current password
        $user = $db->selectOne('tp_users', ['id' => $auth->getUserId()]);
        
        if (!password_verify($currentPassword, $user['password'])) {
            apiError('Current password is incorrect');
        }
        
        // Update password
        try {
            $db->update('tp_users', [
                'password' => password_hash($newPassword, PASSWORD_BCRYPT)
            ], ['id' => $auth->getUserId()]);
            
            $auth->logActivity('password_changed', 'users', $auth->getUserId());
            
            apiResponse(null, 200, 'Password changed successfully');
        } catch (Exception $e) {
            apiError('Failed to change password');
        }
        break;
        
    default:
        apiError('Invalid action', 400);
}