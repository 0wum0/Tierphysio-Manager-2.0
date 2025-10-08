<?php
/**
 * Tierphysio Manager 2.0 - Complete System Test
 * Testet alle wichtigen Funktionen und API-Endpoints
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/response.php';

// Farben für Terminal-Output
$green = "\033[0;32m";
$red = "\033[0;31m";
$yellow = "\033[0;33m";
$blue = "\033[0;34m";
$reset = "\033[0m";

echo "{$blue}===========================================\n";
echo "TIERPHYSIO MANAGER 2.0 - SYSTEM TEST\n";
echo "==========================================={$reset}\n\n";

$tests_passed = 0;
$tests_failed = 0;
$test_data = [];

function test_assert($condition, $message) {
    global $green, $red, $reset, $tests_passed, $tests_failed;
    if ($condition) {
        echo "{$green}✓{$reset} $message\n";
        $tests_passed++;
        return true;
    } else {
        echo "{$red}✗{$reset} $message\n";
        $tests_failed++;
        return false;
    }
}

function section($title) {
    global $yellow, $reset;
    echo "\n{$yellow}$title{$reset}\n";
    echo str_repeat('-', strlen($title)) . "\n";
}

try {
    $pdo = pdo();
    test_assert(true, "Datenbankverbindung erfolgreich");
    
    // ===========================================
    // TEST 1: TABELLEN-STRUKTUR
    // ===========================================
    section("1. TABELLEN-STRUKTUR PRÜFUNG");
    
    $required_tables = [
        'tp_users' => ['id', 'username', 'email', 'password', 'first_name', 'last_name'],
        'tp_owners' => ['id', 'customer_number', 'first_name', 'last_name', 'street', 'house_number', 'postal_code', 'city'],
        'tp_patients' => ['id', 'patient_number', 'name', 'species', 'breed', 'gender', 'birth_date', 'owner_id'],
        'tp_appointments' => ['id', 'patient_id', 'therapist_id', 'appointment_date', 'start_time', 'end_time'],
        'tp_treatments' => ['id', 'patient_id', 'therapist_id', 'treatment_date', 'duration_minutes'],
        'tp_invoices' => ['id', 'invoice_number', 'owner_id', 'invoice_date', 'due_date', 'total'],
        'tp_invoice_items' => ['id', 'invoice_id', 'description', 'quantity', 'price', 'total'],
        'tp_notes' => ['id', 'type', 'content', 'created_by'],
        'tp_documents' => ['id', 'title', 'file_name', 'file_path'],
        'tp_settings' => ['id', 'category', 'key', 'value'],
        'tp_activity_log' => ['id', 'user_id', 'action', 'created_at'],
        'tp_sessions' => ['id', 'user_id', 'payload', 'last_activity'],
        'tp_migrations' => ['id', 'version', 'name', 'executed_at']
    ];
    
    foreach ($required_tables as $table => $required_columns) {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        if ($stmt->fetch()) {
            test_assert(true, "Tabelle $table existiert");
            
            // Prüfe wichtige Spalten
            $stmt = $pdo->query("SHOW COLUMNS FROM $table");
            $columns = [];
            while ($col = $stmt->fetch()) {
                $columns[] = $col['Field'];
            }
            
            foreach ($required_columns as $col) {
                test_assert(in_array($col, $columns), "  └─ Spalte $table.$col vorhanden");
            }
        } else {
            test_assert(false, "Tabelle $table fehlt!");
        }
    }
    
    // ===========================================
    // TEST 2: OWNER CRUD
    // ===========================================
    section("2. OWNER CRUD OPERATIONEN");
    
    // Create Owner
    $customer_number = 'O' . date('ymd') . rand(10000, 99999);
    $stmt = $pdo->prepare("INSERT INTO tp_owners (customer_number, first_name, last_name, email, phone, street, house_number, postal_code, city, country, created_at) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    $result = $stmt->execute([
        $customer_number,
        'Test',
        'Owner_' . rand(1000, 9999),
        'test' . rand(1000, 9999) . '@example.com',
        '0171' . rand(1000000, 9999999),
        'Teststraße',
        rand(1, 999),
        '12345',
        'Teststadt',
        'Deutschland'
    ]);
    
    if (test_assert($result, "Owner erstellen")) {
        $test_data['owner_id'] = $pdo->lastInsertId();
        test_assert(true, "  └─ Owner ID: {$test_data['owner_id']}, Kundennummer: $customer_number");
    }
    
    // Read Owner
    if (isset($test_data['owner_id'])) {
        $stmt = $pdo->prepare("SELECT * FROM tp_owners WHERE id = ?");
        $stmt->execute([$test_data['owner_id']]);
        $owner = $stmt->fetch();
        test_assert($owner !== false, "Owner abrufen");
        test_assert($owner['customer_number'] === $customer_number, "  └─ Kundennummer korrekt");
    }
    
    // Update Owner
    if (isset($test_data['owner_id'])) {
        $stmt = $pdo->prepare("UPDATE tp_owners SET city = ? WHERE id = ?");
        $result = $stmt->execute(['Neue Stadt', $test_data['owner_id']]);
        test_assert($result, "Owner aktualisieren");
    }
    
    // ===========================================
    // TEST 3: PATIENT CRUD
    // ===========================================
    section("3. PATIENT CRUD OPERATIONEN");
    
    if (isset($test_data['owner_id'])) {
        // Create Patient
        $patient_number = 'P' . date('ymd') . rand(10000, 99999);
        $species = ['dog', 'cat', 'horse', 'rabbit', 'bird', 'reptile', 'other'];
        $genders = ['male', 'female', 'neutered_male', 'spayed_female', 'unknown'];
        
        $stmt = $pdo->prepare("INSERT INTO tp_patients (patient_number, owner_id, name, species, breed, color, gender, birth_date, created_at) 
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $result = $stmt->execute([
            $patient_number,
            $test_data['owner_id'],
            'Test Patient ' . rand(100, 999),
            $species[array_rand($species)],
            'Test Breed',
            'Braun',
            $genders[array_rand($genders)],
            date('Y-m-d', strtotime('-2 years'))
        ]);
        
        if (test_assert($result, "Patient erstellen")) {
            $test_data['patient_id'] = $pdo->lastInsertId();
            test_assert(true, "  └─ Patient ID: {$test_data['patient_id']}, Patientennummer: $patient_number");
        }
        
        // Read Patient with Owner
        if (isset($test_data['patient_id'])) {
            $stmt = $pdo->prepare("
                SELECT p.*, o.first_name, o.last_name, o.customer_number 
                FROM tp_patients p 
                LEFT JOIN tp_owners o ON p.owner_id = o.id 
                WHERE p.id = ?
            ");
            $stmt->execute([$test_data['patient_id']]);
            $patient = $stmt->fetch();
            test_assert($patient !== false, "Patient mit Owner-Daten abrufen");
            test_assert($patient['patient_number'] === $patient_number, "  └─ Patientennummer korrekt");
            test_assert($patient['customer_number'] === $customer_number, "  └─ Owner-Verknüpfung korrekt");
        }
    }
    
    // ===========================================
    // TEST 4: ENUM VALIDIERUNG
    // ===========================================
    section("4. ENUM VALIDIERUNG");
    
    // Test ungültige Species
    $invalid_species = 'unicorn';
    $stmt = $pdo->prepare("INSERT INTO tp_patients (patient_number, owner_id, name, species, gender, created_at) 
                          VALUES (?, ?, ?, ?, ?, NOW())");
    try {
        $stmt->execute(['P999999', $test_data['owner_id'] ?? 1, 'Invalid', $invalid_species, 'unknown']);
        test_assert(false, "Ungültige Species sollte Fehler werfen");
    } catch (PDOException $e) {
        test_assert(true, "Ungültige Species wird abgelehnt");
    }
    
    // Test ungültiges Gender
    $invalid_gender = 'alien';
    $stmt = $pdo->prepare("INSERT INTO tp_patients (patient_number, owner_id, name, species, gender, created_at) 
                          VALUES (?, ?, ?, ?, ?, NOW())");
    try {
        $stmt->execute(['P999998', $test_data['owner_id'] ?? 1, 'Invalid', 'dog', $invalid_gender]);
        test_assert(false, "Ungültiges Gender sollte Fehler werfen");
    } catch (PDOException $e) {
        test_assert(true, "Ungültiges Gender wird abgelehnt");
    }
    
    // ===========================================
    // TEST 5: FOREIGN KEY CONSTRAINTS
    // ===========================================
    section("5. FOREIGN KEY CONSTRAINTS");
    
    // Test: Patient mit nicht-existierendem Owner
    $stmt = $pdo->prepare("INSERT INTO tp_patients (patient_number, owner_id, name, species, gender, created_at) 
                          VALUES (?, ?, ?, ?, ?, NOW())");
    try {
        $stmt->execute(['P999997', 999999, 'Orphan', 'dog', 'unknown']);
        test_assert(false, "Patient mit ungültigem Owner sollte fehlschlagen");
    } catch (PDOException $e) {
        test_assert(true, "Foreign Key Constraint für owner_id funktioniert");
    }
    
    // ===========================================
    // TEST 6: DASHBOARD QUERIES
    // ===========================================
    section("6. DASHBOARD QUERIES");
    
    // Aktive Patienten
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM tp_patients WHERE is_active = 1");
    $result = $stmt->fetch();
    test_assert(is_numeric($result['count']), "Aktive Patienten zählen: {$result['count']}");
    
    // Heutige Termine
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tp_appointments WHERE appointment_date = ? AND status = 'scheduled'");
    $stmt->execute([date('Y-m-d')]);
    $result = $stmt->fetch();
    test_assert(is_numeric($result['count']), "Heutige Termine zählen: {$result['count']}");
    
    // Offene Rechnungen
    $stmt = $pdo->query("
        SELECT COUNT(*) as count, COALESCE(SUM(total - paid_amount), 0) as total 
        FROM tp_invoices 
        WHERE status IN ('sent', 'partially_paid', 'overdue')
    ");
    $result = $stmt->fetch();
    test_assert(is_numeric($result['count']), "Offene Rechnungen: {$result['count']} (Summe: " . number_format($result['total'], 2) . " €)");
    
    // Monats-Umsatz
    $stmt = $pdo->query("
        SELECT COALESCE(SUM(paid_amount), 0) as revenue 
        FROM tp_invoices 
        WHERE MONTH(payment_date) = MONTH(CURRENT_DATE()) 
        AND YEAR(payment_date) = YEAR(CURRENT_DATE())
        AND status IN ('paid', 'partially_paid')
    ");
    $result = $stmt->fetch();
    test_assert(is_numeric($result['revenue']), "Monatsumsatz: " . number_format($result['revenue'], 2) . " €");
    
    // ===========================================
    // TEST 7: CLEANUP
    // ===========================================
    section("7. CLEANUP");
    
    // Lösche Test-Daten
    if (isset($test_data['patient_id'])) {
        $stmt = $pdo->prepare("DELETE FROM tp_patients WHERE id = ?");
        $stmt->execute([$test_data['patient_id']]);
        test_assert($stmt->rowCount() > 0, "Test-Patient gelöscht");
    }
    
    if (isset($test_data['owner_id'])) {
        $stmt = $pdo->prepare("DELETE FROM tp_owners WHERE id = ?");
        $stmt->execute([$test_data['owner_id']]);
        test_assert($stmt->rowCount() > 0, "Test-Owner gelöscht");
    }
    
} catch (Exception $e) {
    echo "{$red}✗ Kritischer Fehler: " . $e->getMessage() . "{$reset}\n";
    $tests_failed++;
}

// ===========================================
// ZUSAMMENFASSUNG
// ===========================================
echo "\n{$blue}==========================================={$reset}\n";
echo "TEST-ZUSAMMENFASSUNG\n";
echo "{$blue}==========================================={$reset}\n";

$total_tests = $tests_passed + $tests_failed;
$success_rate = $total_tests > 0 ? round(($tests_passed / $total_tests) * 100, 1) : 0;

echo "{$green}Erfolgreich:{$reset} $tests_passed Tests\n";
echo "{$red}Fehlgeschlagen:{$reset} $tests_failed Tests\n";
echo "Erfolgsrate: $success_rate%\n";

if ($tests_failed === 0) {
    echo "\n{$green}✓ ALLE TESTS BESTANDEN!{$reset}\n";
} else {
    echo "\n{$yellow}⚠ EINIGE TESTS FEHLGESCHLAGEN!{$reset}\n";
}

echo "{$blue}==========================================={$reset}\n";