<?php
/**
 * Tierphysio Manager 2.0 - Migration Runner
 * Führt alle ausstehenden Migrationen aus
 */

require_once __DIR__ . '/includes/db.php';

// Farben für Terminal-Output
$green = "\033[0;32m";
$red = "\033[0;31m";
$yellow = "\033[0;33m";
$blue = "\033[0;34m";
$reset = "\033[0m";

echo "{$blue}===========================================\n";
echo "TIERPHYSIO MANAGER 2.0 - MIGRATION RUNNER\n";
echo "==========================================={$reset}\n\n";

try {
    $pdo = pdo();
    
    // Stelle sicher, dass die migrations Tabelle existiert
    $stmt = $pdo->query("SHOW TABLES LIKE 'tp_migrations'");
    if (!$stmt->fetch()) {
        echo "{$yellow}Erstelle tp_migrations Tabelle...{$reset}\n";
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `tp_migrations` (
                `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `version` varchar(20) NOT NULL,
                `name` varchar(255) NOT NULL,
                `executed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `idx_version` (`version`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        echo "{$green}✓ tp_migrations Tabelle erstellt{$reset}\n\n";
    }
    
    // Hole alle bereits ausgeführten Migrationen
    $stmt = $pdo->query("SELECT version FROM tp_migrations");
    $executed = [];
    while ($row = $stmt->fetch()) {
        $executed[] = $row['version'];
    }
    
    echo "Bereits ausgeführte Migrationen: " . count($executed) . "\n\n";
    
    // Scanne migrations Ordner
    $migration_files = glob(__DIR__ . '/migrations/*.sql');
    sort($migration_files);
    
    $migrations_run = 0;
    $migrations_skipped = 0;
    $migrations_failed = 0;
    
    foreach ($migration_files as $file) {
        $filename = basename($file);
        
        // Extrahiere Version aus Dateiname (z.B. 001_initial_schema.sql -> 001)
        if (preg_match('/^(\d+)_(.+)\.sql$/', $filename, $matches)) {
            $version = $matches[1];
            $name = $matches[2];
            
            if (in_array($version, $executed)) {
                echo "{$yellow}⊘{$reset} Migration $version ($name) - bereits ausgeführt\n";
                $migrations_skipped++;
                continue;
            }
            
            echo "{$blue}→{$reset} Führe Migration $version ($name) aus...\n";
            
            try {
                // Lese SQL-Datei
                $sql = file_get_contents($file);
                
                // Führe Migration aus
                // Teile bei Semikolon auf, aber ignoriere Semikola in Strings
                $statements = preg_split('/;(?=([^"]*"[^"]*")*[^"]*$)/', $sql);
                
                foreach ($statements as $statement) {
                    $statement = trim($statement);
                    if (!empty($statement)) {
                        $pdo->exec($statement);
                    }
                }
                
                // Markiere Migration als ausgeführt
                $stmt = $pdo->prepare("INSERT INTO tp_migrations (version, name) VALUES (?, ?)");
                $stmt->execute([$version, $name]);
                
                echo "{$green}  ✓ Migration $version erfolgreich ausgeführt{$reset}\n";
                $migrations_run++;
                
            } catch (PDOException $e) {
                echo "{$red}  ✗ Fehler bei Migration $version: " . $e->getMessage() . "{$reset}\n";
                $migrations_failed++;
                
                // Bei Fehler abbrechen
                break;
            }
        }
    }
    
    // Zusammenfassung
    echo "\n{$blue}==========================================={$reset}\n";
    echo "MIGRATION ZUSAMMENFASSUNG\n";
    echo "{$blue}==========================================={$reset}\n";
    
    echo "{$green}Ausgeführt:{$reset} $migrations_run Migrationen\n";
    echo "{$yellow}Übersprungen:{$reset} $migrations_skipped Migrationen\n";
    
    if ($migrations_failed > 0) {
        echo "{$red}Fehlgeschlagen:{$reset} $migrations_failed Migrationen\n";
        echo "\n{$red}⚠ WARNUNG: Einige Migrationen sind fehlgeschlagen!{$reset}\n";
    } elseif ($migrations_run > 0) {
        echo "\n{$green}✓ Alle Migrationen erfolgreich ausgeführt!{$reset}\n";
    } else {
        echo "\n{$blue}ℹ Keine neuen Migrationen gefunden.{$reset}\n";
    }
    
    // Zeige aktuellen Datenbankstatus
    echo "\n{$blue}DATENBANKSTATUS:{$reset}\n";
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM tp_migrations");
    $result = $stmt->fetch();
    echo "Ausgeführte Migrationen: {$result['count']}\n";
    
    // Prüfe wichtige Tabellen
    $tables = ['tp_users', 'tp_owners', 'tp_patients', 'tp_appointments', 'tp_treatments', 'tp_invoices'];
    $all_tables_exist = true;
    
    foreach ($tables as $table) {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        if ($stmt->fetch()) {
            echo "{$green}✓{$reset} $table existiert\n";
        } else {
            echo "{$red}✗{$reset} $table fehlt\n";
            $all_tables_exist = false;
        }
    }
    
    if ($all_tables_exist) {
        echo "\n{$green}✓ Datenbank ist bereit!{$reset}\n";
    } else {
        echo "\n{$red}⚠ Einige Tabellen fehlen. Bitte prüfen Sie die Installation.{$reset}\n";
    }
    
} catch (Exception $e) {
    echo "{$red}✗ Kritischer Fehler: " . $e->getMessage() . "{$reset}\n";
    exit(1);
}

echo "{$blue}==========================================={$reset}\n";