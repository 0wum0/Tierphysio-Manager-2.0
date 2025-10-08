<?php
/**
 * Test Script für Tierphysio Manager 2.0 API Endpoints
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/response.php';

echo "=== Tierphysio Manager 2.0 - API Test ===\n\n";

try {
    $pdo = pdo();
    echo "✓ Datenbankverbindung erfolgreich\n\n";
    
    // Test 1: Prüfe ob tp_* Tabellen existieren
    echo "Test 1: Tabellen-Check\n";
    echo str_repeat('-', 40) . "\n";
    
    $tables = ['tp_users', 'tp_owners', 'tp_patients', 'tp_appointments', 'tp_treatments', 'tp_invoices'];
    foreach ($tables as $table) {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        if ($stmt->fetch()) {
            echo "✓ Tabelle $table existiert\n";
        } else {
            echo "✗ Tabelle $table fehlt!\n";
        }
    }
    
    echo "\n";
    
    // Test 2: Owner erstellen
    echo "Test 2: Owner erstellen\n";
    echo str_repeat('-', 40) . "\n";
    
    $customer_number = 'O' . date('ymd') . rand(1000, 9999);
    $stmt = $pdo->prepare("INSERT INTO tp_owners (customer_number, first_name, last_name, phone, email, street, house_number, postal_code, city, country, created_at) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    $result = $stmt->execute([
        $customer_number,
        'Test',
        'Owner',
        '0123456789',
        'test@example.com',
        'Teststraße',
        '123',
        '12345',
        'Teststadt',
        'Deutschland'
    ]);
    
    if ($result) {
        $owner_id = $pdo->lastInsertId();
        echo "✓ Owner erstellt (ID: $owner_id, Kundennummer: $customer_number)\n";
    } else {
        echo "✗ Fehler beim Erstellen des Owners\n";
        $owner_id = null;
    }
    
    echo "\n";
    
    // Test 3: Patient erstellen
    if ($owner_id) {
        echo "Test 3: Patient erstellen\n";
        echo str_repeat('-', 40) . "\n";
        
        $patient_number = 'P' . date('ymd') . rand(1000, 9999);
        $stmt = $pdo->prepare("INSERT INTO tp_patients (patient_number, name, species, breed, color, gender, birth_date, owner_id, notes, created_at) 
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $result = $stmt->execute([
            $patient_number,
            'Bello',
            'dog',
            'Labrador',
            'Schwarz',
            'male',
            '2020-01-15',
            $owner_id,
            'Testpatient'
        ]);
        
        if ($result) {
            $patient_id = $pdo->lastInsertId();
            echo "✓ Patient erstellt (ID: $patient_id, Patientennummer: $patient_number)\n";
        } else {
            echo "✗ Fehler beim Erstellen des Patienten\n";
            $patient_id = null;
        }
        
        echo "\n";
    }
    
    // Test 4: Patienten abrufen
    echo "Test 4: Patienten abrufen\n";
    echo str_repeat('-', 40) . "\n";
    
    $stmt = $pdo->query("SELECT p.*, o.first_name, o.last_name, o.customer_number 
                        FROM tp_patients p 
                        LEFT JOIN tp_owners o ON p.owner_id = o.id 
                        ORDER BY p.id DESC 
                        LIMIT 5");
    $patients = $stmt->fetchAll();
    
    echo "✓ " . count($patients) . " Patienten gefunden\n";
    foreach ($patients as $patient) {
        echo "  - {$patient['name']} ({$patient['species']}) - Besitzer: {$patient['first_name']} {$patient['last_name']}\n";
    }
    
    echo "\n";
    
    // Test 5: Dashboard-Queries
    echo "Test 5: Dashboard-Queries\n";
    echo str_repeat('-', 40) . "\n";
    
    // Aktive Patienten
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM tp_patients WHERE is_active = 1");
    $result = $stmt->fetch();
    echo "✓ Aktive Patienten: {$result['count']}\n";
    
    // Offene Rechnungen
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM tp_invoices WHERE status IN ('sent', 'partially_paid', 'overdue')");
    $result = $stmt->fetch();
    echo "✓ Offene Rechnungen: {$result['count']}\n";
    
    // Umsatz diesen Monat
    $stmt = $pdo->query("SELECT SUM(paid_amount) as revenue 
                        FROM tp_invoices 
                        WHERE MONTH(payment_date) = MONTH(CURRENT_DATE()) 
                        AND YEAR(payment_date) = YEAR(CURRENT_DATE())");
    $result = $stmt->fetch();
    $revenue = $result['revenue'] ?? 0;
    echo "✓ Umsatz diesen Monat: " . number_format($revenue, 2) . " €\n";
    
    echo "\n";
    
    // Cleanup Test-Daten
    if (isset($patient_id)) {
        $pdo->exec("DELETE FROM tp_patients WHERE id = $patient_id");
        echo "✓ Test-Patient gelöscht\n";
    }
    if (isset($owner_id)) {
        $pdo->exec("DELETE FROM tp_owners WHERE id = $owner_id");
        echo "✓ Test-Owner gelöscht\n";
    }
    
    echo "\n=== Alle Tests abgeschlossen ===\n";
    
} catch (Exception $e) {
    echo "✗ Fehler: " . $e->getMessage() . "\n";
}