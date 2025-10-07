<?php
/**
 * Tierphysio Manager 2.0
 * Settings Management Page
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/version.php';

use TierphysioManager\Auth;
use TierphysioManager\Database;
use TierphysioManager\Template;

// Initialize services
$auth = Auth::getInstance();
$db = Database::getInstance();
$template = Template::getInstance();

// Require login
$auth->requireLogin();
$auth->requirePermission('manage_settings');

// Get action
$action = $_GET['action'] ?? 'list';
$category = $_GET['category'] ?? 'general';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$auth->verifyCSRFToken($_POST['_csrf_token'] ?? '')) {
        Template::setFlash('error', 'Ungültiger Sicherheitstoken.');
    } else {
        try {
            // Update settings
            foreach ($_POST['settings'] ?? [] as $key => $value) {
                $stmt = $db->prepare("
                    UPDATE tp_settings 
                    SET value = :value, 
                        updated_by = :updated_by,
                        updated_at = NOW()
                    WHERE category = :category 
                    AND `key` = :key
                ");
                
                $stmt->execute([
                    'value' => is_array($value) ? json_encode($value) : $value,
                    'category' => $category,
                    'key' => $key,
                    'updated_by' => $auth->getUserId()
                ]);
            }
            
            Template::setFlash('success', 'Einstellungen erfolgreich gespeichert.');
            header('Location: /public/settings.php?category=' . $category);
            exit;
        } catch (Exception $e) {
            Template::setFlash('error', 'Fehler beim Speichern: ' . $e->getMessage());
        }
    }
}

// Get settings categories
$categories = $db->query("
    SELECT DISTINCT category, COUNT(*) as count 
    FROM tp_settings 
    WHERE is_system = 0 OR is_system IS NULL
    GROUP BY category 
    ORDER BY category
")->fetchAll();

// Get settings for current category
$settings = $db->query("
    SELECT * FROM tp_settings 
    WHERE category = :category 
    AND (is_system = 0 OR is_system IS NULL)
    ORDER BY `key`
", ['category' => $category])->fetchAll();

// Prepare template data
$data = [
    'user' => $auth->getUser(),
    'page_title' => 'Einstellungen',
    'current_page' => 'settings',
    'categories' => $categories,
    'current_category' => $category,
    'settings' => $settings,
    'csrf_token' => $auth->getCSRFToken()
];

// Render template
$template->render('pages/settings', $data);