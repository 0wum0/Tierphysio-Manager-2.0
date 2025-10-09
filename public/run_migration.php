<?php
/**
 * Run database migration for tp_ tables
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: text/html; charset=utf-8');

echo "<h1>Database Migration</h1>";
echo "<pre>";

try {
    $pdo = get_pdo();
    
    // Read migration file
    $migration = file_get_contents(__DIR__ . '/../migrations/002_create_tp_tables.sql');
    
    // Split into individual statements
    $statements = array_filter(
        array_map('trim', explode(';', $migration)),
        function($stmt) { 
            return !empty($stmt) && stripos($stmt, '--') !== 0;
        }
    );
    
    $successCount = 0;
    $errorCount = 0;
    
    foreach ($statements as $statement) {
        if (empty(trim($statement))) continue;
        
        try {
            $pdo->exec($statement);
            echo "✅ Executed: " . substr($statement, 0, 50) . "...\n";
            $successCount++;
        } catch (PDOException $e) {
            echo "❌ Error: " . $e->getMessage() . "\n";
            echo "   Statement: " . substr($statement, 0, 100) . "...\n";
            $errorCount++;
        }
    }
    
    echo "\n";
    echo "=====================================\n";
    echo "Migration Summary:\n";
    echo "✅ Successful: $successCount\n";
    echo "❌ Failed: $errorCount\n";
    echo "=====================================\n\n";
    
    // Check table structure
    echo "Checking tp_owners table:\n";
    $stmt = $pdo->query("SHOW TABLES LIKE 'tp_owners'");
    if ($stmt->fetch()) {
        echo "✅ Table tp_owners exists\n";
        
        // Count records
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM tp_owners");
        $count = $stmt->fetch()['count'];
        echo "   Records: $count\n";
        
        // Show columns
        $stmt = $pdo->query("SHOW COLUMNS FROM tp_owners");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo "   Columns: " . implode(', ', $columns) . "\n";
    } else {
        echo "❌ Table tp_owners does not exist\n";
    }
    
    echo "\n";
    echo "Checking tp_patients table:\n";
    $stmt = $pdo->query("SHOW TABLES LIKE 'tp_patients'");
    if ($stmt->fetch()) {
        echo "✅ Table tp_patients exists\n";
        
        // Count records
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM tp_patients");
        $count = $stmt->fetch()['count'];
        echo "   Records: $count\n";
    } else {
        echo "❌ Table tp_patients does not exist\n";
    }
    
    echo "\n";
    echo "<a href='/public/test_owners_api.html'>Test Owners API</a> | ";
    echo "<a href='/public/owners.php'>View Owners Page</a>";
    
} catch (Exception $e) {
    echo "❌ Fatal Error: " . $e->getMessage() . "\n";
}

echo "</pre>";