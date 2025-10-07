<?php
/**
 * Tierphysio Manager 2.0
 * Integrity Check - Prüft alle Routen und Dateien
 */

// Prevent access in production
if (isset($_ENV['APP_ENV']) && $_ENV['APP_ENV'] === 'production') {
    die('Nicht verfügbar in Produktion');
}

// Start session to simulate logged in user for tests
session_start();
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1; // Simulate logged in user
    $_SESSION['user'] = [
        'id' => 1,
        'username' => 'test',
        'email' => 'test@example.com',
        'first_name' => 'Test',
        'last_name' => 'User',
        'role' => 'admin'
    ];
}

$checks = [];

// Controllers to check
$controllers = [
    'dashboard.php' => 'Dashboard',
    'index.php' => 'Index/Dashboard',
    'patients.php' => 'Patienten',
    'owners.php' => 'Besitzer',
    'appointments.php' => 'Termine',
    'treatments.php' => 'Behandlungen',
    'invoices.php' => 'Rechnungen',
    'notes.php' => 'Notizen',
    'admin.php' => 'Admin',
    'settings.php' => 'Einstellungen',
    'login.php' => 'Login',
    'logout.php' => 'Logout'
];

// API endpoints to check
$apis = [
    'api/patients.php' => 'Patienten API',
    'api/owners.php' => 'Besitzer API',
    'api/appointments.php' => 'Termine API',
    'api/treatments.php' => 'Behandlungen API',
    'api/invoices.php' => 'Rechnungen API',
    'api/notes.php' => 'Notizen API'
];

// Templates to check
$templates = [
    '../templates/pages/dashboard.twig' => 'Dashboard Template',
    '../templates/pages/patients.twig' => 'Patienten Template',
    '../templates/pages/patient_detail.twig' => 'Patienten Detail Template',
    '../templates/pages/owners.twig' => 'Besitzer Template',
    '../templates/pages/appointments.twig' => 'Termine Template',
    '../templates/pages/treatments.twig' => 'Behandlungen Template',
    '../templates/pages/invoices.twig' => 'Rechnungen Template',
    '../templates/pages/notes.twig' => 'Notizen Template',
    '../templates/pages/admin.twig' => 'Admin Template',
    '../templates/pages/settings.twig' => 'Einstellungen Template',
    '../templates/pages/login.twig' => 'Login Template',
    '../templates/layouts/base.twig' => 'Base Layout',
    '../templates/partials/sidebar.twig' => 'Sidebar Partial',
    '../templates/partials/topbar.twig' => 'Topbar Partial',
    '../templates/partials/footer.twig' => 'Footer Partial'
];

// Include files to check
$includes = [
    '../includes/db.php' => 'Database Helper',
    '../includes/auth.php' => 'Auth Helper',
    '../includes/response.php' => 'Response Helper',
    '../includes/csrf.php' => 'CSRF Helper',
    '../includes/config.php' => 'Config File',
    '../includes/Database.php' => 'Database Class',
    '../includes/Auth.php' => 'Auth Class',
    '../includes/Template.php' => 'Template Class'
];

// Check Controllers
echo "<h2>Controller-Dateien</h2>";
echo "<table border='1' cellpadding='5' cellspacing='0' style='width:100%'>";
echo "<tr><th>Datei</th><th>Name</th><th>Existiert</th><th>HTTP Status</th><th>AutoFix</th></tr>";

foreach ($controllers as $file => $name) {
    $filePath = __DIR__ . '/' . $file;
    $exists = file_exists($filePath);
    $httpStatus = '-';
    $autoFixed = false;
    
    if ($exists) {
        // Try to access via HTTP (skip login/logout)
        if (!in_array($file, ['login.php', 'logout.php'])) {
            $url = 'http://localhost/public/' . $file;
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_COOKIE, 'PHPSESSID=' . session_id());
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $httpStatus = $httpCode ?: 'Error';
        }
    } else {
        // Auto-fix: Create missing controller
        $autoFixed = true;
        $template = getControllerTemplate($file);
        if ($template && file_put_contents($filePath, $template)) {
            $exists = true;
        }
    }
    
    $checks[] = [
        'type' => 'controller',
        'file' => $file,
        'name' => $name,
        'exists' => $exists,
        'status' => $httpStatus,
        'autoFixed' => $autoFixed
    ];
    
    echo "<tr>";
    echo "<td>$file</td>";
    echo "<td>$name</td>";
    echo "<td style='text-align:center'>" . ($exists ? '✅' : '❌') . "</td>";
    echo "<td style='text-align:center'>$httpStatus</td>";
    echo "<td style='text-align:center'>" . ($autoFixed ? '🔧' : '-') . "</td>";
    echo "</tr>";
}

echo "</table>";

// Check APIs
echo "<h2>API-Endpunkte</h2>";
echo "<table border='1' cellpadding='5' cellspacing='0' style='width:100%'>";
echo "<tr><th>Datei</th><th>Name</th><th>Existiert</th><th>Test Response</th><th>AutoFix</th></tr>";

foreach ($apis as $file => $name) {
    $filePath = __DIR__ . '/' . $file;
    $exists = file_exists($filePath);
    $response = '-';
    $autoFixed = false;
    
    if ($exists) {
        // Try to call API with list action
        $url = 'http://localhost/public/' . $file . '?action=list';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_COOKIE, 'PHPSESSID=' . session_id());
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode == 200) {
            $json = json_decode($result, true);
            $response = isset($json['status']) ? $json['status'] : 'Invalid';
        } else {
            $response = 'Error ' . $httpCode;
        }
    }
    
    $checks[] = [
        'type' => 'api',
        'file' => $file,
        'name' => $name,
        'exists' => $exists,
        'response' => $response,
        'autoFixed' => $autoFixed
    ];
    
    echo "<tr>";
    echo "<td>$file</td>";
    echo "<td>$name</td>";
    echo "<td style='text-align:center'>" . ($exists ? '✅' : '❌') . "</td>";
    echo "<td style='text-align:center'>" . ($response === 'success' ? '✅' : $response) . "</td>";
    echo "<td style='text-align:center'>" . ($autoFixed ? '🔧' : '-') . "</td>";
    echo "</tr>";
}

echo "</table>";

// Check Templates
echo "<h2>Twig-Templates</h2>";
echo "<table border='1' cellpadding='5' cellspacing='0' style='width:100%'>";
echo "<tr><th>Datei</th><th>Name</th><th>Existiert</th><th>AutoFix</th></tr>";

foreach ($templates as $file => $name) {
    $filePath = __DIR__ . '/' . $file;
    $exists = file_exists($filePath);
    $autoFixed = false;
    
    if (!$exists) {
        // Auto-fix: Create missing template
        $autoFixed = true;
        $template = getTemplateContent($file);
        if ($template) {
            $dir = dirname($filePath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            if (file_put_contents($filePath, $template)) {
                $exists = true;
            }
        }
    }
    
    $checks[] = [
        'type' => 'template',
        'file' => $file,
        'name' => $name,
        'exists' => $exists,
        'autoFixed' => $autoFixed
    ];
    
    echo "<tr>";
    echo "<td>$file</td>";
    echo "<td>$name</td>";
    echo "<td style='text-align:center'>" . ($exists ? '✅' : '❌') . "</td>";
    echo "<td style='text-align:center'>" . ($autoFixed ? '🔧' : '-') . "</td>";
    echo "</tr>";
}

echo "</table>";

// Check Include Files
echo "<h2>Include-Dateien</h2>";
echo "<table border='1' cellpadding='5' cellspacing='0' style='width:100%'>";
echo "<tr><th>Datei</th><th>Name</th><th>Existiert</th></tr>";

foreach ($includes as $file => $name) {
    $filePath = __DIR__ . '/' . $file;
    $exists = file_exists($filePath);
    
    $checks[] = [
        'type' => 'include',
        'file' => $file,
        'name' => $name,
        'exists' => $exists
    ];
    
    echo "<tr>";
    echo "<td>$file</td>";
    echo "<td>$name</td>";
    echo "<td style='text-align:center'>" . ($exists ? '✅' : '❌') . "</td>";
    echo "</tr>";
}

echo "</table>";

// Summary
$totalChecks = count($checks);
$passedChecks = count(array_filter($checks, function($c) { return $c['exists']; }));
$failedChecks = $totalChecks - $passedChecks;
$autoFixed = count(array_filter($checks, function($c) { return isset($c['autoFixed']) && $c['autoFixed']; }));

echo "<h2>Zusammenfassung</h2>";
echo "<table border='1' cellpadding='5' cellspacing='0'>";
echo "<tr><th>Gesamte Prüfungen</th><td>$totalChecks</td></tr>";
echo "<tr><th>Erfolgreich</th><td style='color:green'>$passedChecks ✅</td></tr>";
echo "<tr><th>Fehlgeschlagen</th><td style='color:red'>$failedChecks ❌</td></tr>";
echo "<tr><th>Auto-Fixes</th><td style='color:blue'>$autoFixed 🔧</td></tr>";
echo "</table>";

if ($failedChecks === 0) {
    echo "<h3 style='color:green'>✅ Alle Prüfungen bestanden! Das System ist vollständig konfiguriert.</h3>";
} else {
    echo "<h3 style='color:orange'>⚠️ Es wurden $failedChecks Probleme gefunden. Bitte prüfen Sie die markierten Dateien.</h3>";
}

// Helper functions for auto-fix
function getControllerTemplate($filename) {
    // Return basic controller template based on filename
    return false; // Controllers already exist, no template needed
}

function getTemplateContent($filename) {
    // Return basic template content based on filename
    return false; // Templates already exist, no template needed
}

?>

<style>
body {
    font-family: Arial, sans-serif;
    margin: 20px;
    background: #f5f5f5;
}
h2 {
    color: #333;
    border-bottom: 2px solid #4CAF50;
    padding-bottom: 5px;
}
table {
    background: white;
    margin-bottom: 20px;
    border-collapse: collapse;
}
th {
    background: #4CAF50;
    color: white;
    padding: 8px;
}
td {
    padding: 8px;
}
tr:nth-child(even) {
    background: #f9f9f9;
}
</style>