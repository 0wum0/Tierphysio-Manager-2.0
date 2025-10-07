<?php
/**
 * Tierphysio Manager 2.0
 * JSON Integrity Check Tool
 * 
 * Prüft alle API-Endpunkte auf korrekte JSON-Ausgabe
 */

// Set JSON header for this tool
header('Content-Type: text/html; charset=utf-8');

// Start session for authentication check
session_start();

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

// Check if user is admin
if (!is_logged_in() || !is_admin()) {
    die('Zugriff verweigert. Admin-Rechte erforderlich.');
}

// Define all API endpoints to test
$endpoints = [
    'patients' => '/public/api/patients.php',
    'owners' => '/public/api/owners.php',
    'appointments' => '/public/api/appointments.php',
    'treatments' => '/public/api/treatments.php',
    'invoices' => '/public/api/invoices.php',
    'notes' => '/public/api/notes.php'
];

// Function to test an endpoint
function testEndpoint($name, $path, $sessionCookie) {
    $baseUrl = 'http://' . $_SERVER['HTTP_HOST'];
    $fullUrl = $baseUrl . $path . '?action=list';
    
    $result = [
        'name' => $name,
        'url' => $fullUrl,
        'status' => 'unknown',
        'http_code' => 0,
        'content_type' => '',
        'is_json' => false,
        'json_valid' => false,
        'response_preview' => '',
        'error' => ''
    ];
    
    // Initialize cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $fullUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_COOKIE, $sessionCookie);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    
    // Execute request
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    
    if (curl_errno($ch)) {
        $result['error'] = 'cURL Error: ' . curl_error($ch);
        curl_close($ch);
        return $result;
    }
    
    curl_close($ch);
    
    // Parse response
    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    
    // Extract content-type
    if (preg_match('/Content-Type:\s*([^\r\n]+)/i', $headers, $matches)) {
        $result['content_type'] = trim($matches[1]);
    }
    
    $result['http_code'] = $httpCode;
    $result['response_preview'] = substr($body, 0, 500);
    
    // Check if content-type is JSON
    $result['is_json'] = stripos($result['content_type'], 'application/json') !== false;
    
    // Validate JSON
    if (!empty($body)) {
        $jsonData = json_decode($body);
        $result['json_valid'] = (json_last_error() === JSON_ERROR_NONE);
        
        if (!$result['json_valid']) {
            $result['error'] = 'JSON Parse Error: ' . json_last_error_msg();
        }
    }
    
    // Determine overall status
    if ($result['json_valid'] && $result['is_json']) {
        $result['status'] = 'success';
    } elseif ($httpCode == 401) {
        $result['status'] = 'auth_error';
    } else {
        $result['status'] = 'error';
    }
    
    return $result;
}

// Get current session cookie
$sessionCookie = session_name() . '=' . session_id();

// Test all endpoints
$results = [];
foreach ($endpoints as $name => $path) {
    $results[] = testEndpoint($name, $path, $sessionCookie);
}

// Calculate statistics
$totalEndpoints = count($results);
$successCount = 0;
$errorCount = 0;
$authErrorCount = 0;

foreach ($results as $result) {
    if ($result['status'] === 'success') {
        $successCount++;
    } elseif ($result['status'] === 'auth_error') {
        $authErrorCount++;
    } else {
        $errorCount++;
    }
}

$successRate = $totalEndpoints > 0 ? round(($successCount / $totalEndpoints) * 100, 2) : 0;
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JSON Integrity Check - Tierphysio Manager</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 2rem;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 1rem;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
        }
        
        .header h1 {
            font-size: 1.875rem;
            margin-bottom: 0.5rem;
        }
        
        .header p {
            opacity: 0.9;
        }
        
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            padding: 2rem;
            background: #f8f9fa;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 0.5rem;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 0.25rem;
        }
        
        .stat-label {
            color: #6b7280;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .stat-success .stat-value {
            color: #10b981;
        }
        
        .stat-error .stat-value {
            color: #ef4444;
        }
        
        .stat-warning .stat-value {
            color: #f59e0b;
        }
        
        .stat-info .stat-value {
            color: #3b82f6;
        }
        
        .results {
            padding: 2rem;
        }
        
        .results h2 {
            font-size: 1.5rem;
            margin-bottom: 1rem;
            color: #1f2937;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th {
            background: #f3f4f6;
            padding: 0.75rem;
            text-align: left;
            font-weight: 600;
            color: #374151;
            border-bottom: 2px solid #e5e7eb;
        }
        
        td {
            padding: 0.75rem;
            border-bottom: 1px solid #e5e7eb;
        }
        
        tr:hover {
            background: #f9fafb;
        }
        
        .badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .badge-success {
            background: #d1fae5;
            color: #065f46;
        }
        
        .badge-error {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .badge-warning {
            background: #fed7aa;
            color: #92400e;
        }
        
        .icon {
            display: inline-block;
            width: 1.25rem;
            height: 1.25rem;
            vertical-align: middle;
        }
        
        .icon-check {
            color: #10b981;
        }
        
        .icon-x {
            color: #ef4444;
        }
        
        .icon-warning {
            color: #f59e0b;
        }
        
        .response-preview {
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-family: monospace;
            font-size: 0.75rem;
            color: #6b7280;
        }
        
        .error-message {
            color: #ef4444;
            font-size: 0.875rem;
        }
        
        .action-buttons {
            padding: 2rem;
            background: #f8f9fa;
            border-top: 1px solid #e2e8f0;
            text-align: center;
        }
        
        .btn {
            display: inline-block;
            padding: 0.75rem 1.5rem;
            border-radius: 0.5rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s;
            margin: 0 0.5rem;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }
        
        .btn-secondary {
            background: white;
            color: #4b5563;
            border: 2px solid #e5e7eb;
        }
        
        .btn-secondary:hover {
            background: #f9fafb;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔍 JSON Integrity Check</h1>
            <p>Überprüfung aller API-Endpunkte auf korrekte JSON-Ausgabe</p>
        </div>
        
        <div class="stats">
            <div class="stat-card stat-info">
                <div class="stat-value"><?php echo $totalEndpoints; ?></div>
                <div class="stat-label">Endpunkte gesamt</div>
            </div>
            <div class="stat-card stat-success">
                <div class="stat-value"><?php echo $successCount; ?></div>
                <div class="stat-label">Erfolgreich</div>
            </div>
            <div class="stat-card stat-error">
                <div class="stat-value"><?php echo $errorCount; ?></div>
                <div class="stat-label">Fehler</div>
            </div>
            <div class="stat-card <?php echo $successRate >= 80 ? 'stat-success' : ($successRate >= 50 ? 'stat-warning' : 'stat-error'); ?>">
                <div class="stat-value"><?php echo $successRate; ?>%</div>
                <div class="stat-label">Erfolgsrate</div>
            </div>
        </div>
        
        <div class="results">
            <h2>Detaillierte Ergebnisse</h2>
            <table>
                <thead>
                    <tr>
                        <th>Endpunkt</th>
                        <th>HTTP Status</th>
                        <th>Content-Type</th>
                        <th>JSON Valid</th>
                        <th>Status</th>
                        <th>Response / Fehler</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $result): ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($result['name']); ?></strong>
                        </td>
                        <td>
                            <span class="badge <?php echo $result['http_code'] == 200 ? 'badge-success' : 'badge-error'; ?>">
                                <?php echo $result['http_code']; ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($result['is_json']): ?>
                                <span class="icon icon-check">✓</span>
                            <?php else: ?>
                                <span class="icon icon-x">✗</span>
                            <?php endif; ?>
                            <small><?php echo htmlspecialchars($result['content_type']); ?></small>
                        </td>
                        <td>
                            <?php if ($result['json_valid']): ?>
                                <span class="icon icon-check">✓</span> Valid
                            <?php else: ?>
                                <span class="icon icon-x">✗</span> Invalid
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($result['status'] === 'success'): ?>
                                <span class="badge badge-success">✓ OK</span>
                            <?php elseif ($result['status'] === 'auth_error'): ?>
                                <span class="badge badge-warning">Auth</span>
                            <?php else: ?>
                                <span class="badge badge-error">Fehler</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($result['error']): ?>
                                <span class="error-message"><?php echo htmlspecialchars($result['error']); ?></span>
                            <?php else: ?>
                                <div class="response-preview" title="<?php echo htmlspecialchars($result['response_preview']); ?>">
                                    <?php echo htmlspecialchars($result['response_preview']); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div class="action-buttons">
            <a href="javascript:location.reload()" class="btn btn-primary">🔄 Erneut prüfen</a>
            <a href="/public/dashboard.php" class="btn btn-secondary">← Zurück zum Dashboard</a>
        </div>
    </div>
</body>
</html>