<?php
/**
 * Tierphysio Manager 2.0
 * Logout Page
 */

require_once __DIR__ . '/../vendor/autoload.php';

use TierphysioManager\Auth;

// Initialize auth
$auth = Auth::getInstance();

// Logout user
$auth->logout();

// Redirect to login page
header('Location: login.php');
exit;