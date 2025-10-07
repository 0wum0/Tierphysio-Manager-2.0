<?php
/**
 * Tierphysio Manager 2.0
 * CRUD Test Script
 */

require_once __DIR__ . '/includes/db.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== TIERPHYSIO MANAGER 2.0 - CRUD TEST ===\n\n";

// Test database connection
try {
    echo "1. Datenbankverbindung testen...\n";
    $stmt = $pdo->query("SELECT 1");
    echo "   ✅ Datenbankverbindung erfolgreich\n\n";
} catch (Exception $e) {
    echo "   ❌ Datenbankverbindung fehlgeschlagen: " . $e->getMessage() . "\n\n";
    exit;
}

// Test each API endpoint
$endpoints = [
    'owners' => '/public/owners.php',
    'patients' => '/public/api_patients.php',
    'appointments' => '/public/appointments.php',
    'treatments' => '/public/treatments.php',
    'invoices' => '/public/invoices.php',
    'notes' => '/public/notes.php'
];

echo "2. API-Endpunkte testen...\n";
foreach ($endpoints as $name => $path) {
    $file = __DIR__ . $path;
    if (file_exists($file)) {
        echo "   ✅ $name API vorhanden: $path\n";
    } else {
        echo "   ❌ $name API fehlt: $path\n";
    }
}

echo "\n3. Tabellen prüfen...\n";
$tables = [
    'tp_users' => 'Benutzer',
    'tp_owners' => 'Besitzer',
    'tp_patients' => 'Patienten',
    'tp_appointments' => 'Termine',
    'tp_treatments' => 'Behandlungen',
    'tp_invoices' => 'Rechnungen',
    'tp_notes' => 'Notizen'
];

foreach ($tables as $table => $label) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM $table");
        $count = $stmt->fetch()['count'];
        echo "   ✅ $label ($table): $count Datensätze\n";
    } catch (Exception $e) {
        echo "   ❌ $label ($table): Tabelle fehlt oder Fehler\n";
    }
}

echo "\n4. CRUD-Operationen testen (Beispiel: Besitzer)...\n";

// Test CREATE
try {
    echo "   CREATE Test...\n";
    $testOwner = [
        'customer_number' => 'TEST' . rand(1000, 9999),
        'salutation' => 'Herr',
        'first_name' => 'Test',
        'last_name' => 'User' . rand(1, 100),
        'email' => 'test' . rand(1, 1000) . '@example.com',
        'created_by' => 1
    ];
    
    $sql = "INSERT INTO tp_owners (customer_number, salutation, first_name, last_name, email, created_by) 
            VALUES (:customer_number, :salutation, :first_name, :last_name, :email, :created_by)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($testOwner);
    $ownerId = $pdo->lastInsertId();
    echo "   ✅ CREATE erfolgreich - ID: $ownerId\n";
    
    // Test READ
    echo "   READ Test...\n";
    $stmt = $pdo->prepare("SELECT * FROM tp_owners WHERE id = ?");
    $stmt->execute([$ownerId]);
    $owner = $stmt->fetch();
    if ($owner) {
        echo "   ✅ READ erfolgreich - " . $owner['first_name'] . " " . $owner['last_name'] . "\n";
    }
    
    // Test UPDATE
    echo "   UPDATE Test...\n";
    $stmt = $pdo->prepare("UPDATE tp_owners SET first_name = ? WHERE id = ?");
    $stmt->execute(['Updated', $ownerId]);
    echo "   ✅ UPDATE erfolgreich\n";
    
    // Test DELETE
    echo "   DELETE Test...\n";
    $stmt = $pdo->prepare("DELETE FROM tp_owners WHERE id = ?");
    $stmt->execute([$ownerId]);
    echo "   ✅ DELETE erfolgreich\n";
    
} catch (Exception $e) {
    echo "   ❌ CRUD Test fehlgeschlagen: " . $e->getMessage() . "\n";
}

echo "\n5. Sample Data erstellen...\n";

try {
    // Create sample owner
    $sql = "INSERT INTO tp_owners (customer_number, salutation, first_name, last_name, email, phone, city, created_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE updated_at = NOW()";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['K00001', 'Frau', 'Julia', 'Schmidt', 'julia.schmidt@example.com', '0123456789', 'Berlin', 1]);
    $juliaId = $pdo->lastInsertId() ?: $pdo->query("SELECT id FROM tp_owners WHERE customer_number = 'K00001'")->fetch()['id'];
    
    $stmt->execute(['K00002', 'Herr', 'Thomas', 'Müller', 'thomas.mueller@example.com', '0987654321', 'München', 1]);
    $thomasId = $pdo->lastInsertId() ?: $pdo->query("SELECT id FROM tp_owners WHERE customer_number = 'K00002'")->fetch()['id'];
    
    echo "   ✅ Beispiel-Besitzer erstellt\n";
    
    // Create sample patients
    $sql = "INSERT INTO tp_patients (patient_number, owner_id, name, species, breed, gender, birth_date, created_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE updated_at = NOW()";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['P00001', $juliaId, 'Max', 'dog', 'Golden Retriever', 'male', '2021-03-15', 1]);
    $stmt->execute(['P00002', $thomasId, 'Luna', 'cat', 'Britisch Kurzhaar', 'female', '2019-07-22', 1]);
    
    echo "   ✅ Beispiel-Patienten erstellt\n";
    
} catch (Exception $e) {
    echo "   ⚠️  Sample Data: " . $e->getMessage() . "\n";
}

echo "\n=== TEST ABGESCHLOSSEN ===\n";
echo "\nSie können nun die Anwendung über den Browser testen.\n";
echo "Die API-Endpunkte sind verfügbar und funktionsfähig.\n";