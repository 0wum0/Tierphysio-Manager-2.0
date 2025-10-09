<?php
/**
 * Tierphysio Manager 2.0
 * Class Collision Checker
 * 
 * Dieses Skript prüft auf doppelte Klassendefinitionen und ungeschützte Konstanten
 */

$errors = [];
$warnings = [];
$classDefinitions = [];
$constantDefinitions = [];

// Funktion zum Scannen von PHP-Dateien
function scanFile($filepath) {
    global $classDefinitions, $constantDefinitions, $errors, $warnings;
    
    $content = file_get_contents($filepath);
    $relPath = str_replace(__DIR__ . '/../', '', $filepath);
    
    // Suche nach Klassendefinitionen
    if (preg_match_all('/^\s*(?:abstract\s+|final\s+)?class\s+(\w+)/m', $content, $matches)) {
        foreach ($matches[1] as $className) {
            if (!isset($classDefinitions[$className])) {
                $classDefinitions[$className] = [];
            }
            $classDefinitions[$className][] = $relPath;
        }
    }
    
    // Suche nach ungeschützten define() Anweisungen
    if (preg_match_all('/^(?!\s*if\s*\(\s*!defined).*define\s*\(\s*[\'"](\w+)[\'"]/m', $content, $matches)) {
        foreach ($matches[1] as $constantName) {
            if (!isset($constantDefinitions[$constantName])) {
                $constantDefinitions[$constantName] = [];
            }
            $constantDefinitions[$constantName][] = $relPath;
        }
    }
}

// Funktion zum rekursiven Scannen von Verzeichnissen
function scanDirectory($dir) {
    $files = scandir($dir);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..' || $file === 'vendor') continue;
        
        $path = $dir . '/' . $file;
        if (is_dir($path)) {
            scanDirectory($path);
        } elseif (pathinfo($path, PATHINFO_EXTENSION) === 'php') {
            scanFile($path);
        }
    }
}

// Starte Scan
echo "=== Tierphysio Manager 2.0 - Integritäts-Check ===\n\n";
echo "Scanne Verzeichnisse...\n";

$baseDir = __DIR__ . '/..';
scanDirectory($baseDir . '/includes');
scanDirectory($baseDir . '/public');
scanDirectory($baseDir . '/api');
scanDirectory($baseDir . '/admin');
scanDirectory($baseDir . '/integrity');

// Analysiere Ergebnisse
echo "\n--- Analyse der Klassendefinitionen ---\n";
foreach ($classDefinitions as $className => $files) {
    if (count($files) > 1) {
        $errors[] = "FEHLER: Klasse '$className' ist mehrfach definiert in: " . implode(', ', $files);
        echo "✗ Klasse '$className' mehrfach definiert:\n";
        foreach ($files as $file) {
            echo "  - $file\n";
        }
    } else {
        echo "✓ Klasse '$className' einmalig in: {$files[0]}\n";
    }
}

if (empty($classDefinitions)) {
    echo "Keine Klassendefinitionen gefunden.\n";
}

echo "\n--- Analyse der Konstantendefinitionen ---\n";
foreach ($constantDefinitions as $constantName => $files) {
    if (count($files) > 1) {
        $warnings[] = "WARNUNG: Konstante '$constantName' mehrfach ohne Guard definiert in: " . implode(', ', $files);
        echo "⚠ Konstante '$constantName' mehrfach ohne Guard:\n";
        foreach ($files as $file) {
            echo "  - $file\n";
        }
    }
}

if (empty($constantDefinitions)) {
    echo "✓ Alle Konstanten sind geschützt oder keine ungeschützten gefunden.\n";
}

// Spezielle Prüfungen
echo "\n--- Spezielle Prüfungen ---\n";

// Prüfe ob Auth-Klasse korrekt ist
if (isset($classDefinitions['Auth'])) {
    if (count($classDefinitions['Auth']) > 1) {
        echo "✗ KRITISCH: Mehrere Auth-Klassen gefunden!\n";
    } else {
        echo "✓ Nur eine Auth-Klasse gefunden in: {$classDefinitions['Auth'][0]}\n";
    }
}

// Prüfe ob StandaloneAuth neutralisiert wurde
if (file_exists($baseDir . '/includes/StandaloneAuth.php')) {
    $content = file_get_contents($baseDir . '/includes/StandaloneAuth.php');
    if (strpos($content, "if (class_exists('\\TierphysioManager\\Auth'))") !== false) {
        echo "✓ StandaloneAuth.php ist korrekt neutralisiert\n";
    } else {
        $warnings[] = "StandaloneAuth.php ist möglicherweise nicht neutralisiert";
        echo "⚠ StandaloneAuth.php ist möglicherweise nicht neutralisiert\n";
    }
}

// Zusammenfassung
echo "\n=== ZUSAMMENFASSUNG ===\n";
if (empty($errors) && empty($warnings)) {
    echo "✅ ERFOLG: Keine Kollisionen oder Probleme gefunden!\n";
    echo "Alle Klassen sind eindeutig definiert.\n";
    echo "Alle Konstanten sind geschützt.\n";
    exit(0);
} else {
    if (!empty($errors)) {
        echo "\n❌ FEHLER (" . count($errors) . "):\n";
        foreach ($errors as $error) {
            echo "  - $error\n";
        }
    }
    if (!empty($warnings)) {
        echo "\n⚠️  WARNUNGEN (" . count($warnings) . "):\n";
        foreach ($warnings as $warning) {
            echo "  - $warning\n";
        }
    }
    exit(1);
}