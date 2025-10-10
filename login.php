<?php
/**
 * Root Login Redirect
 * Redirects to the appropriate login page
 */

// Check if we're trying to access admin area
$referer = $_SERVER['HTTP_REFERER'] ?? '';
$requestedUrl = $_GET['redirect'] ?? '';

// If coming from admin area or requesting admin access, redirect to admin login
if (str_contains($referer, '/admin/') || str_contains($requestedUrl, '/admin/')) {
    header('Location: /admin/login.php');
} else {
    // Otherwise redirect to public login
    header('Location: /public/login.php');
}
exit;