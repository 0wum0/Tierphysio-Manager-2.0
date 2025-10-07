<?php
/**
 * Tierphysio Manager 2.0
 * Simple Database Connection for API
 */

// Load configuration
require_once __DIR__ . '/config.php';

// PDO connection function
function pdo() {
    static $pdo = null;
    
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET
            ];
            
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Check if this is an API call
            $is_api = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false;
            
            if ($is_api) {
                // Return JSON error for API calls
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(500);
                echo json_encode([
                    "status" => "error",
                    "message" => APP_DEBUG ? "Database connection failed: " . $e->getMessage() : "Database connection failed",
                    "data" => null
                ]);
                exit;
            } else {
                // For non-API calls, throw exception to be handled by the application
                throw $e;
            }
        }
    }
    
    return $pdo;
}

// Set timezone
date_default_timezone_set(APP_TIMEZONE);

// Simple session check function for API authentication
function checkApiAuth() {
    session_start();
    
    // For development/testing, we'll allow all requests
    // In production, this should check for valid session or API token
    if (APP_DEBUG) {
        return true;
    }
    
    // Check if user is logged in
    if (!isset($_SESSION['user_id'])) {
        // Only send JSON response for API calls
        if (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(401);
            echo json_encode([
                "status" => "error",
                "message" => "Unauthorized - Please login",
                "data" => null
            ]);
            exit;
        } else {
            // Redirect to login for non-API calls
            header('Location: /public/login.php');
            exit;
        }
    }
    
    return true;
}
