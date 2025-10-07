<?php
/**
 * Tierphysio Manager 2.0
 * Template Integrity Checker & Auto-Fix
 * 
 * Prüft und repariert Template-Dateien nach JSON-Fix-Problemen
 */

header('Content-Type: text/html; charset=utf-8');

// Configuration
$projectRoot = dirname(__DIR__);
$templatesDir = $projectRoot . '/templates';
$publicDir = $projectRoot . '/public';
$includesDir = $projectRoot . '/includes';
$apiDir = $projectRoot . '/public/api';

// Initialize report
$report = [
    'timestamp' => date('Y-m-d H:i:s'),
    'checks' => [],
    'fixes' => [],
    'errors' => []
];

/**
 * Check if file contains unwanted JSON headers
 */
function checkForJsonHeaders($file) {
    $content = file_get_contents($file);
    $issues = [];
    
    // Check for JSON content-type header (should not be in UI files)
    if (strpos($content, "header('Content-Type: application/json") !== false ||
        strpos($content, 'header("Content-Type: application/json') !== false) {
        $issues[] = 'Contains JSON Content-Type header';
    }
    
    // Check for json_encode with exit (problematic in UI files)
    if (preg_match('/echo\s+json_encode.*?exit;/s', $content)) {
        $issues[] = 'Contains json_encode with exit';
    }
    
    return $issues;
}

/**
 * Check Twig template structure
 */
function checkTwigTemplate($file) {
    $content = file_get_contents($file);
    $issues = [];
    $filename = basename($file);
    $dirname = basename(dirname($file));
    
    // Check for extends statement (except base.twig)
    if ($filename !== 'base.twig' && $dirname !== 'partials') {
        if (!preg_match('/\{%\s*extends\s+[\'"]/', $content)) {
            $issues[] = 'Missing {% extends %} statement';
        }
    }
    
    // Check for content block (for pages)
    if ($dirname === 'pages' && !preg_match('/\{%\s*block\s+content\s*%\}/', $content)) {
        $issues[] = 'Missing {% block content %} block';
    }
    
    // Check for JSON headers in templates (should never be there)
    if (strpos($content, 'application/json') !== false) {
        $issues[] = 'Contains JSON reference (should not be in templates)';
    }
    
    return $issues;
}

/**
 * Fix PHP file with JSON issues
 */
function fixPhpFile($file) {
    $content = file_get_contents($file);
    $original = $content;
    $fixes = [];
    
    // Remove JSON headers from non-API files
    if (strpos($file, '/api/') === false) {
        // Remove JSON content-type headers
        $patterns = [
            "/header\s*\(\s*['\"]Content-Type:\s*application\/json[^'\"]*['\"]\s*\)\s*;?\s*\n?/i",
            "/header\s*\(\s*['\"]Content-Type:\s*application\/json[^)]*\)\s*;?\s*\n?/i"
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                $content = preg_replace($pattern, '', $content);
                $fixes[] = 'Removed JSON Content-Type header';
            }
        }
        
        // Remove standalone exit statements after json_encode (careful!)
        if (preg_match('/echo\s+json_encode.*?\n\s*exit;/s', $content)) {
            $content = preg_replace('/(echo\s+json_encode[^;]*;)\s*\n\s*exit;/s', '$1', $content);
            $fixes[] = 'Removed exit after json_encode';
        }
    }
    
    // Save if changed
    if ($content !== $original) {
        file_put_contents($file, $content);
        return $fixes;
    }
    
    return false;
}

/**
 * Fix Twig template issues
 */
function fixTwigTemplate($file) {
    $content = file_get_contents($file);
    $original = $content;
    $fixes = [];
    $filename = basename($file);
    $dirname = basename(dirname($file));
    
    // Add extends statement if missing (for page templates)
    if ($dirname === 'pages' && !preg_match('/\{%\s*extends/', $content)) {
        $content = "{% extends 'layouts/base.twig' %}\n\n" . $content;
        $fixes[] = 'Added extends statement';
    }
    
    // Add content block if missing (for page templates)
    if ($dirname === 'pages' && !preg_match('/\{%\s*block\s+content\s*%\}/', $content)) {
        // Try to wrap existing content in block
        if (preg_match('/\{%\s*extends.*?%\}\s*\n(.*)/s', $content, $matches)) {
            $mainContent = $matches[1];
            $content = preg_replace(
                '/(\{%\s*extends.*?%\}\s*\n)/s',
                "$1\n{% block content %}\n",
                $content
            ) . "\n{% endblock %}";
            $fixes[] = 'Added content block wrapper';
        }
    }
    
    // Remove any JSON-related comments or headers
    if (strpos($content, 'application/json') !== false) {
        $content = str_replace(['application/json', 'Content-Type: application/json'], '', $content);
        $fixes[] = 'Removed JSON references';
    }
    
    // Save if changed
    if ($content !== $original) {
        file_put_contents($file, $content);
        return $fixes;
    }
    
    return false;
}

// Start HTML output
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Template Integrity Check - Tierphysio Manager 2.0</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .status-ok { color: #10b981; }
        .status-warning { color: #f59e0b; }
        .status-error { color: #ef4444; }
        .status-fixed { color: #3b82f6; }
    </style>
</head>
<body class="bg-gray-50 p-8">
    <div class="max-w-6xl mx-auto">
        <div class="bg-white rounded-lg shadow-lg p-6">
            <h1 class="text-3xl font-bold text-gray-800 mb-2">Template Integrity Check</h1>
            <p class="text-gray-600 mb-6">Automatische Überprüfung und Reparatur von Template-Problemen</p>
            
            <?php
            // 1. Check PHP files outside /api/ for JSON headers
            echo '<div class="mb-8">';
            echo '<h2 class="text-xl font-semibold mb-4">1. PHP-Dateien (außerhalb /api/)</h2>';
            echo '<div class="space-y-2">';
            
            $phpFiles = array_merge(
                glob($publicDir . '/*.php'),
                glob($includesDir . '/*.php')
            );
            
            foreach ($phpFiles as $file) {
                // Skip API files
                if (strpos($file, '/api/') !== false) continue;
                
                $relativePath = str_replace($projectRoot, '', $file);
                $issues = checkForJsonHeaders($file);
                
                echo '<div class="flex items-center justify-between p-3 bg-gray-50 rounded">';
                echo '<span class="font-mono text-sm">' . htmlspecialchars($relativePath) . '</span>';
                
                if (empty($issues)) {
                    echo '<span class="status-ok">✅ OK</span>';
                    $report['checks'][] = ['file' => $relativePath, 'status' => 'ok'];
                } else {
                    // Try to fix
                    $fixes = fixPhpFile($file);
                    if ($fixes) {
                        echo '<span class="status-fixed">🔧 FIXED: ' . implode(', ', $fixes) . '</span>';
                        $report['fixes'][] = ['file' => $relativePath, 'fixes' => $fixes];
                    } else {
                        echo '<span class="status-error">❌ ' . implode(', ', $issues) . '</span>';
                        $report['errors'][] = ['file' => $relativePath, 'issues' => $issues];
                    }
                }
                echo '</div>';
            }
            echo '</div>';
            echo '</div>';
            
            // 2. Check Twig templates
            echo '<div class="mb-8">';
            echo '<h2 class="text-xl font-semibold mb-4">2. Twig Templates</h2>';
            echo '<div class="space-y-2">';
            
            $twigFiles = [];
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($templatesDir)
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'twig') {
                    $twigFiles[] = $file->getPathname();
                }
            }
            
            foreach ($twigFiles as $file) {
                $relativePath = str_replace($projectRoot, '', $file);
                $issues = checkTwigTemplate($file);
                
                echo '<div class="flex items-center justify-between p-3 bg-gray-50 rounded">';
                echo '<span class="font-mono text-sm">' . htmlspecialchars($relativePath) . '</span>';
                
                if (empty($issues)) {
                    echo '<span class="status-ok">✅ OK</span>';
                    $report['checks'][] = ['file' => $relativePath, 'status' => 'ok'];
                } else {
                    // Try to fix
                    $fixes = fixTwigTemplate($file);
                    if ($fixes) {
                        echo '<span class="status-fixed">🔧 FIXED: ' . implode(', ', $fixes) . '</span>';
                        $report['fixes'][] = ['file' => $relativePath, 'fixes' => $fixes];
                    } else {
                        echo '<span class="status-warning">⚠️ ' . implode(', ', $issues) . '</span>';
                        $report['errors'][] = ['file' => $relativePath, 'issues' => $issues];
                    }
                }
                echo '</div>';
            }
            echo '</div>';
            echo '</div>';
            
            // 3. Check API files (should have JSON headers)
            echo '<div class="mb-8">';
            echo '<h2 class="text-xl font-semibold mb-4">3. API-Dateien (sollten JSON haben)</h2>';
            echo '<div class="space-y-2">';
            
            $apiFiles = glob($apiDir . '/*.php');
            
            foreach ($apiFiles as $file) {
                $relativePath = str_replace($projectRoot, '', $file);
                $content = file_get_contents($file);
                $hasJsonHeader = strpos($content, 'application/json') !== false;
                
                echo '<div class="flex items-center justify-between p-3 bg-gray-50 rounded">';
                echo '<span class="font-mono text-sm">' . htmlspecialchars($relativePath) . '</span>';
                
                if ($hasJsonHeader) {
                    echo '<span class="status-ok">✅ Has JSON header</span>';
                    $report['checks'][] = ['file' => $relativePath, 'status' => 'ok', 'api' => true];
                } else {
                    echo '<span class="status-warning">⚠️ Missing JSON header</span>';
                    $report['errors'][] = ['file' => $relativePath, 'issues' => ['Missing JSON header'], 'api' => true];
                }
                echo '</div>';
            }
            echo '</div>';
            echo '</div>';
            
            // 4. Summary
            $totalChecks = count($report['checks']);
            $totalFixes = count($report['fixes']);
            $totalErrors = count($report['errors']);
            
            echo '<div class="mt-8 p-6 bg-gradient-to-r from-purple-50 to-indigo-50 rounded-lg">';
            echo '<h2 class="text-xl font-semibold mb-4">Zusammenfassung</h2>';
            echo '<div class="grid grid-cols-3 gap-4">';
            
            echo '<div class="text-center">';
            echo '<div class="text-3xl font-bold text-green-600">' . $totalChecks . '</div>';
            echo '<div class="text-sm text-gray-600">Dateien OK</div>';
            echo '</div>';
            
            echo '<div class="text-center">';
            echo '<div class="text-3xl font-bold text-blue-600">' . $totalFixes . '</div>';
            echo '<div class="text-sm text-gray-600">Automatisch repariert</div>';
            echo '</div>';
            
            echo '<div class="text-center">';
            echo '<div class="text-3xl font-bold text-' . ($totalErrors > 0 ? 'red' : 'gray') . '-600">' . $totalErrors . '</div>';
            echo '<div class="text-sm text-gray-600">Manuelle Prüfung nötig</div>';
            echo '</div>';
            
            echo '</div>';
            
            // Test links
            echo '<div class="mt-6 pt-6 border-t border-gray-200">';
            echo '<h3 class="font-semibold mb-3">Quick Tests:</h3>';
            echo '<div class="flex flex-wrap gap-3">';
            echo '<a href="/public/index.php" target="_blank" class="px-4 py-2 bg-purple-600 text-white rounded hover:bg-purple-700">Dashboard</a>';
            echo '<a href="/public/patients.php" target="_blank" class="px-4 py-2 bg-purple-600 text-white rounded hover:bg-purple-700">Patienten</a>';
            echo '<a href="/public/appointments.php" target="_blank" class="px-4 py-2 bg-purple-600 text-white rounded hover:bg-purple-700">Termine</a>';
            echo '<a href="/public/settings.php" target="_blank" class="px-4 py-2 bg-purple-600 text-white rounded hover:bg-purple-700">Einstellungen</a>';
            echo '<a href="/public/api/patients.php?action=get_all" target="_blank" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">API Test</a>';
            echo '</div>';
            echo '</div>';
            
            echo '</div>';
            
            // Save report to file
            $reportFile = $projectRoot . '/integrity_report_' . date('Y-m-d_His') . '.json';
            file_put_contents($reportFile, json_encode($report, JSON_PRETTY_PRINT));
            ?>
            
            <div class="mt-6 text-sm text-gray-500">
                <p>Report gespeichert unter: <?php echo htmlspecialchars($reportFile); ?></p>
                <p>Zeitstempel: <?php echo $report['timestamp']; ?></p>
            </div>
        </div>
    </div>
</body>
</html>