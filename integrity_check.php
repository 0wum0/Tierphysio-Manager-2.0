<?php
/**
 * Tierphysio Manager 2.0
 * Integrity Check Script
 */

echo "=== TIERPHYSIO MANAGER 2.0 - INTEGRITY CHECK ===\n\n";

// Required files to check
$requiredFiles = [
    // API Files
    '/public/patients.php' => 'Patients API & View',
    '/public/patient.php' => 'Patient Detail API & View',
    '/public/owners.php' => 'Owners API & View',
    '/public/appointments.php' => 'Appointments API & View',
    '/public/treatments.php' => 'Treatments API & View',
    '/public/invoices.php' => 'Invoices API & View',
    '/public/notes.php' => 'Notes API & View',
    
    // Templates
    '/templates/pages/patients.twig' => 'Patients Template',
    '/templates/pages/patient_detail.twig' => 'Patient Detail Template',
    '/templates/pages/owners.twig' => 'Owners Template',
    '/templates/pages/appointments.twig' => 'Appointments Template',
    '/templates/pages/treatments.twig' => 'Treatments Template',
    '/templates/pages/invoices.twig' => 'Invoices Template',
    '/templates/pages/notes.twig' => 'Notes Template',
    
    // Includes
    '/includes/db.php' => 'Database Connection',
    '/includes/Template.php' => 'Template Engine',
    '/includes/Auth.php' => 'Authentication',
    '/includes/Database.php' => 'Database Manager'
];

$allFilesExist = true;

echo "1. FILE CHECK:\n";
echo "==============\n";

foreach ($requiredFiles as $file => $description) {
    $fullPath = __DIR__ . $file;
    if (file_exists($fullPath)) {
        echo "✅ $description ($file)\n";
    } else {
        echo "❌ $description ($file) - MISSING!\n";
        $allFilesExist = false;
    }
}

echo "\n2. API ENDPOINTS CHECK:\n";
echo "========================\n";

// Test API endpoints (mock test - in real scenario would need running server)
$apiEndpoints = [
    'patients' => ['get_all', 'get_by_id', 'create', 'update', 'delete'],
    'owners' => ['get_all', 'get_by_id', 'create', 'update', 'delete'],
    'appointments' => ['get_all', 'get_by_id', 'create', 'update', 'delete'],
    'treatments' => ['get_all', 'get_by_id', 'create', 'update', 'delete'],
    'invoices' => ['get_all', 'get_by_id', 'create', 'update', 'delete'],
    'notes' => ['get_all', 'get_by_id', 'create', 'update', 'delete'],
    'patient' => ['get_by_id']
];

foreach ($apiEndpoints as $entity => $actions) {
    $file = __DIR__ . "/public/$entity.php";
    if (file_exists($file)) {
        $content = file_get_contents($file);
        echo "\n$entity.php:\n";
        foreach ($actions as $action) {
            if (strpos($content, "case '$action':") !== false || strpos($content, "action === '$action'") !== false) {
                echo "  ✅ $action action\n";
            } else {
                echo "  ⚠️  $action action might be missing\n";
            }
        }
    } else {
        echo "\n❌ $entity.php not found\n";
    }
}

echo "\n3. TEMPLATE FEATURES CHECK:\n";
echo "===========================\n";

// Check for Alpine.js integration in templates
$templates = [
    'patients.twig' => ['x-data', 'x-init', 'x-show', '@click'],
    'owners.twig' => ['x-data', 'x-init', 'x-show', '@click'],
    'patient_detail.twig' => ['x-data', 'x-init', 'activeTab', 'x-show']
];

foreach ($templates as $template => $features) {
    $file = __DIR__ . "/templates/pages/$template";
    if (file_exists($file)) {
        $content = file_get_contents($file);
        echo "\n$template:\n";
        foreach ($features as $feature) {
            if (strpos($content, $feature) !== false) {
                echo "  ✅ Uses $feature\n";
            } else {
                echo "  ⚠️  Missing $feature\n";
            }
        }
    }
}

echo "\n4. TWIG FILTERS CHECK:\n";
echo "=====================\n";

$templateFile = __DIR__ . '/includes/Template.php';
if (file_exists($templateFile)) {
    $content = file_get_contents($templateFile);
    $filters = ['species_icon', 'species_name', 'gender_name', 'age', 'time_format'];
    
    foreach ($filters as $filter) {
        if (strpos($content, "'$filter'") !== false) {
            echo "✅ Filter: $filter\n";
        } else {
            echo "❌ Filter: $filter missing\n";
        }
    }
}

echo "\n5. DATABASE TABLES CHECK:\n";
echo "=========================\n";

// Check migration file for table definitions
$migrationFile = __DIR__ . '/migrations/001_initial_schema.sql';
if (file_exists($migrationFile)) {
    $content = file_get_contents($migrationFile);
    $tables = ['tp_users', 'tp_owners', 'tp_patients', 'tp_appointments', 'tp_treatments', 'tp_invoices', 'tp_notes'];
    
    foreach ($tables as $table) {
        if (strpos($content, "CREATE TABLE IF NOT EXISTS `$table`") !== false) {
            echo "✅ Table: $table\n";
        } else {
            echo "❌ Table: $table definition missing\n";
        }
    }
}

echo "\n6. SUMMARY:\n";
echo "===========\n";

if ($allFilesExist) {
    echo "✅ All required files are present!\n";
} else {
    echo "❌ Some files are missing. Please create them.\n";
}

echo "\n7. RECOMMENDATIONS:\n";
echo "===================\n";
echo "- Test each API endpoint with real HTTP requests\n";
echo "- Verify database connection and tables exist\n";
echo "- Check that sessions and authentication work\n";
echo "- Test CRUD operations for each entity\n";
echo "- Verify modal forms open and submit correctly\n";
echo "- Test patient detail page navigation\n";

echo "\n=== CHECK COMPLETE ===\n";