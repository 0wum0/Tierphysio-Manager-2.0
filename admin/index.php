<?php
/**
 * Admin Index - Redirect to dashboard
 * 
 * Bootstrap.php already handles authentication and admin checks
 */

require_once __DIR__ . '/../includes/bootstrap.php';

// At this point, user is already logged in and is admin (checked by bootstrap.php)
// Simply redirect to admin dashboard
header('Location: /admin/dashboard.php');
exit;