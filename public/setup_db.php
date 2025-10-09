<?php
/**
 * Tierphysio Manager 2.0
 * Database Setup Script - Run this to create tables and insert dummy data
 */

// Disable time limit for setup
set_time_limit(0);

require_once __DIR__ . '/../includes/db.php';

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Setup - Tierphysio Manager 2.0</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        .container {
            background: white;
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
        }
        h1 {
            color: #667eea;
            margin-bottom: 30px;
        }
        .status {
            padding: 15px;
            border-radius: 5px;
            margin: 10px 0;
        }
        .success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        .warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        button {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            margin: 10px 0;
        }
        button:hover {
            opacity: 0.9;
        }
        pre {
            background: #f4f4f4;
            padding: 10px;
            border-radius: 5px;
            overflow-x: auto;
        }
        .step {
            margin: 20px 0;
            padding: 15px;
            background: #f8f9fa;
            border-left: 4px solid #667eea;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🐾 Tierphysio Manager 2.0 - Database Setup</h1>
        
        <?php
        if (isset($_POST['setup'])) {
            echo '<div class="step">Starte Datenbank-Setup...</div>';
            
            try {
                $pdo = pdo();
                echo '<div class="status success">✅ Datenbankverbindung erfolgreich!</div>';
                
                // Drop existing tables
                echo '<div class="step">Lösche bestehende Tabellen...</div>';
                $pdo->exec("DROP TABLE IF EXISTS patients");
                $pdo->exec("DROP TABLE IF EXISTS owners");
                echo '<div class="status info">Tabellen gelöscht (falls vorhanden)</div>';
                
                // Create owners table
                echo '<div class="step">Erstelle Besitzer-Tabelle...</div>';
                $sql = "CREATE TABLE owners (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    first_name VARCHAR(100) NOT NULL,
                    last_name VARCHAR(100) NOT NULL,
                    phone VARCHAR(50),
                    email VARCHAR(100),
                    address TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
                $pdo->exec($sql);
                echo '<div class="status success">✅ Tabelle "owners" erstellt</div>';
                
                // Create patients table
                echo '<div class="step">Erstelle Patienten-Tabelle...</div>';
                $sql = "CREATE TABLE patients (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    owner_id INT NOT NULL,
                    name VARCHAR(100) NOT NULL,
                    species VARCHAR(50),
                    breed VARCHAR(100),
                    birthdate DATE,
                    gender VARCHAR(20) DEFAULT 'unknown',
                    weight DECIMAL(5,2),
                    microchip VARCHAR(50),
                    color VARCHAR(50),
                    notes TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (owner_id) REFERENCES owners(id) ON DELETE CASCADE,
                    INDEX idx_owner (owner_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
                $pdo->exec($sql);
                echo '<div class="status success">✅ Tabelle "patients" erstellt</div>';
                
                // Insert dummy owners
                echo '<div class="step">Füge Dummy-Besitzer ein...</div>';
                $owners = [
                    ['Max', 'Mustermann', '0171-1234567', 'max@example.com', 'Musterstraße 1, 12345 Berlin'],
                    ['Anna', 'Schmidt', '0172-2345678', 'anna.schmidt@example.com', 'Hauptstraße 15, 10115 Berlin'],
                    ['Thomas', 'Weber', '0173-3456789', 'thomas.weber@example.com', 'Gartenweg 8, 10178 Berlin'],
                    ['Sarah', 'Meyer', '0174-4567890', 'sarah.meyer@example.com', 'Parkstraße 22, 10435 Berlin'],
                    ['Michael', 'Wagner', '0175-5678901', 'michael.wagner@example.com', 'Waldweg 5, 10999 Berlin'],
                    ['Julia', 'Becker', '0176-6789012', 'julia.becker@example.com', 'Seestraße 18, 12047 Berlin'],
                    ['Stefan', 'Schulz', '0177-7890123', 'stefan.schulz@example.com', 'Bergstraße 9, 13347 Berlin'],
                    ['Laura', 'Hoffmann', '0178-8901234', 'laura.hoffmann@example.com', 'Talweg 3, 14195 Berlin'],
                    ['Markus', 'Schäfer', '0179-9012345', 'markus.schaefer@example.com', 'Wiesenstraße 7, 10827 Berlin'],
                    ['Nina', 'Koch', '0170-0123456', 'nina.koch@example.com', 'Feldweg 12, 10965 Berlin']
                ];
                
                $stmt = $pdo->prepare("INSERT INTO tp_owners (first_name, last_name, phone, email, address) VALUES (?, ?, ?, ?, ?)");
                foreach ($owners as $owner) {
                    $stmt->execute($owner);
                }
                echo '<div class="status success">✅ ' . count($owners) . ' Besitzer eingefügt</div>';
                
                // Insert dummy patients
                echo '<div class="step">Füge Dummy-Patienten ein...</div>';
                $patients = [
                    [1, 'Bella', 'dog', 'Labrador Retriever', '2018-03-15', 'female', 28.5, '276098106234567', 'Golden', 'Sehr freundlich, mag Wasser'],
                    [1, 'Max', 'cat', 'Maine Coon', '2020-07-22', 'male', 6.2, '276098106234568', 'Grau getigert', 'Scheu bei Fremden'],
                    [2, 'Luna', 'dog', 'Golden Retriever', '2019-11-08', 'female', 30.0, '276098106234569', 'Creme', 'Hüftdysplasie, regelmäßige Kontrolle'],
                    [3, 'Charlie', 'dog', 'Beagle', '2021-02-14', 'male', 12.8, '276098106234570', 'Tricolor', 'Sehr verspielt'],
                    [3, 'Mimi', 'cat', 'Perser', '2017-09-30', 'female', 4.5, '276098106234571', 'Weiß', 'Langhaar, benötigt regelmäßige Pflege'],
                    [4, 'Rocky', 'dog', 'Deutscher Schäferhund', '2016-05-20', 'male', 35.2, '276098106234572', 'Schwarz-braun', 'Ausgebildeter Schutzhund'],
                    [5, 'Emma', 'horse', 'Hannoveraner', '2012-04-10', 'female', 520.0, '276098106234573', 'Braun', 'Springpferd, Turniere'],
                    [6, 'Felix', 'cat', 'Europäisch Kurzhaar', '2019-12-01', 'male', 5.1, '276098106234574', 'Schwarz', 'Freigänger'],
                    [7, 'Buddy', 'dog', 'Jack Russell Terrier', '2020-08-17', 'male', 7.5, '276098106234575', 'Weiß mit braunen Flecken', 'Sehr energiegeladen'],
                    [8, 'Nala', 'rabbit', 'Zwergwidder', '2021-03-25', 'female', 1.8, null, 'Grau', 'Wohnungshaltung'],
                    [9, 'Oscar', 'dog', 'Mops', '2018-10-12', 'male', 8.9, '276098106234576', 'Beige', 'Atemprobleme, benötigt Spezialbehandlung'],
                    [10, 'Coco', 'bird', 'Wellensittich', '2022-01-05', 'unknown', 0.04, null, 'Grün-gelb', 'Paar mit Kiwi'],
                    [10, 'Kiwi', 'bird', 'Wellensittich', '2022-01-05', 'unknown', 0.04, null, 'Blau-weiß', 'Paar mit Coco'],
                    [2, 'Shadow', 'cat', 'Britisch Kurzhaar', '2020-06-18', 'male', 5.8, '276098106234577', 'Blau', 'Ruhig, verschmust'],
                    [5, 'Duke', 'dog', 'Rottweiler', '2017-11-29', 'male', 45.0, '276098106234578', 'Schwarz mit braun', 'Gut sozialisiert, kinderfreundlich']
                ];
                
                $stmt = $pdo->prepare("INSERT INTO tp_patients (owner_id, name, species, breed, birthdate, gender, weight, microchip, color, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                foreach ($patients as $patient) {
                    $stmt->execute($patient);
                }
                echo '<div class="status success">✅ ' . count($patients) . ' Patienten eingefügt</div>';
                
                // Show summary
                $stmt = $pdo->query("SELECT COUNT(*) as count FROM tp_owners");
                $ownerCount = $stmt->fetch()['count'];
                
                $stmt = $pdo->query("SELECT COUNT(*) as count FROM tp_patients");
                $patientCount = $stmt->fetch()['count'];
                
                echo '<div class="step"><h3>📊 Zusammenfassung:</h3>';
                echo '<ul>';
                echo '<li>' . $ownerCount . ' Besitzer in der Datenbank</li>';
                echo '<li>' . $patientCount . ' Patienten in der Datenbank</li>';
                echo '</ul></div>';
                
                echo '<div class="status success" style="font-size: 18px; padding: 20px; margin-top: 20px;">
                    🎉 <strong>Setup erfolgreich abgeschlossen!</strong><br><br>
                    Sie können jetzt:<br>
                    • <a href="/public/test_api.php">API testen</a><br>
                    • <a href="/public/patients.php">Zur Patientenverwaltung</a><br>
                    • <a href="/public/index.php">Zum Dashboard</a>
                </div>';
                
            } catch (PDOException $e) {
                echo '<div class="status error">❌ Datenbankfehler: ' . htmlspecialchars($e->getMessage()) . '</div>';
                echo '<div class="status warning">Bitte überprüfen Sie die Datenbankverbindung in /includes/config.php</div>';
                echo '<pre>Host: ' . DB_HOST . '
Database: ' . DB_NAME . '
User: ' . DB_USER . '</pre>';
            } catch (Exception $e) {
                echo '<div class="status error">❌ Fehler: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
        } else {
            ?>
            <div class="status info">
                <h3>📋 Was wird passieren:</h3>
                <ol>
                    <li>Bestehende Tabellen (patients, owners) werden gelöscht</li>
                    <li>Neue Tabellen werden erstellt</li>
                    <li>10 Dummy-Besitzer werden eingefügt</li>
                    <li>15 Dummy-Patienten werden eingefügt</li>
                </ol>
            </div>
            
            <div class="status warning">
                ⚠️ <strong>Achtung:</strong> Alle bestehenden Daten in den Tabellen "patients" und "owners" werden gelöscht!
            </div>
            
            <form method="post">
                <button type="submit" name="setup" value="1">🚀 Setup starten</button>
            </form>
            
            <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd;">
                <h3>Aktuelle Konfiguration:</h3>
                <pre>Host: <?php echo DB_HOST; ?>
Database: <?php echo DB_NAME; ?>
User: <?php echo DB_USER; ?>
Debug: <?php echo APP_DEBUG ? 'ON' : 'OFF'; ?></pre>
            </div>
            <?php
        }
        ?>
    </div>
</body>
</html>