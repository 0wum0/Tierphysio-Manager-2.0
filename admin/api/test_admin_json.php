<?php
/**
 * Admin API Test Endpoint
 * Returns JSON with admin panel statistics
 */

// Skip default admin check for test endpoint
$skipAdminCheck = true;

require_once __DIR__ . '/../../includes/bootstrap.php';

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Helper functions
require_once __DIR__ . '/_bootstrap_helpers.php';

// Set JSON header
json_header();

try {
    // Count roles
    $stmt = $pdo->query("SELECT COUNT(*) FROM tp_roles");
    $rolesCount = $stmt->fetchColumn();
    
    // Count permissions
    $stmt = $pdo->query("SELECT COUNT(*) FROM tp_permissions");
    $permissionsCount = $stmt->fetchColumn();
    
    // Count email templates
    $stmt = $pdo->query("SELECT COUNT(*) FROM tp_email_templates");
    $templatesCount = $stmt->fetchColumn();
    
    // Count cron jobs
    $stmt = $pdo->query("SELECT COUNT(*) FROM tp_cron_jobs");
    $cronJobsCount = $stmt->fetchColumn();
    
    // Count modules
    $stmt = $pdo->query("SELECT COUNT(*) FROM tp_modules");
    $modulesCount = $stmt->fetchColumn();
    
    // Check if admin tables exist
    $tables = [
        'tp_roles',
        'tp_permissions',
        'tp_role_permissions',
        'tp_user_roles',
        'tp_email_templates',
        'tp_cron_jobs',
        'tp_cron_logs',
        'tp_backups',
        'tp_modules',
        'tp_finance_items',
        'tp_invoice_design'
    ];
    
    $existingTables = [];
    foreach ($tables as $table) {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        if ($stmt->rowCount() > 0) {
            $existingTables[] = $table;
        }
    }
    
    api_success([
        'roles' => $rolesCount,
        'permissions' => $permissionsCount,
        'email_templates' => $templatesCount,
        'cron_jobs' => $cronJobsCount,
        'modules' => $modulesCount,
        'tables_created' => count($existingTables),
        'tables_expected' => count($tables),
        'admin_panel_ready' => count($existingTables) === count($tables)
    ], 'Admin panel test successful');
    
} catch (Exception $e) {
    api_error('Test failed: ' . $e->getMessage(), 500);
}