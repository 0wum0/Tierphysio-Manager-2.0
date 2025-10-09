<?php
/**
 * Tierphysio Manager 2.0
 * Simple Template Rendering with Twig (Standalone)
 */

// Try to include Twig via vendor autoload if available
$vendor_autoload = __DIR__ . '/../vendor/autoload.php';
$twig_available = false;

if (file_exists($vendor_autoload)) {
    require_once $vendor_autoload;
    if (class_exists('\\Twig\\Loader\\FilesystemLoader')) {
        $twig_available = true;
    }
}

if ($twig_available) {
    use Twig\Loader\FilesystemLoader;
    use Twig\Environment;
}

/**
 * Render a Twig template
 * @param string $path Template path relative to templates directory
 * @param array $data Data to pass to template
 * @return void
 */
function render_template($path, $data = []) {
    global $twig_available;
    
    // Fallback für fehlende Twig-Installation
    if (!$twig_available) {
        render_template_fallback($path, $data);
        return;
    }
    
    try {
        // Setup Twig loader with multiple search paths
        $loader = new FilesystemLoader([
            __DIR__ . '/../templates',
            __DIR__ . '/../templates/layouts',
            __DIR__ . '/../templates/partials'
        ]);
        
        // Setup Twig environment
        $twig = new Environment($loader, [
            'cache' => false, // Disable cache for development
            'debug' => true,
            'auto_reload' => true
        ]);
        
        // Add debug extension
        $twig->addExtension(new \Twig\Extension\DebugExtension());
        
        // User role helper
        if (!function_exists('is_admin')) {
            function is_admin() {
                if (!isset($_SESSION)) {
                    session_start();
                }
                return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
            }
        }
        
        // Register the helper with Twig
        $twig->addFunction(new \Twig\TwigFunction('is_admin', function () {
            return is_admin();
        }));
        
        // Translation helper function
        if (!function_exists('__')) {
            function __($text) {
                // Future localization logic can go here (e.g. from lang files)
                // For now, just return the same text
                return $text;
            }
        }
        
        // Register translation function with Twig
        $twig->addFunction(new \Twig\TwigFunction('__', function ($text) {
            return __($text);
        }));
        
        // Add custom functions
        $twig->addFunction(new \Twig\TwigFunction('csrf_token', function() {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            return $_SESSION['csrf_token'] ?? '';
        }));
        
        $twig->addFunction(new \Twig\TwigFunction('asset', function($path) {
            return '/' . ltrim($path, '/');
        }));
        
        $twig->addFunction(new \Twig\TwigFunction('url', function($path) {
            return '/' . ltrim($path, '/');
        }));
        
        $twig->addFunction(new \Twig\TwigFunction('route', function($name, $params = []) {
            // Simple route function for compatibility
            $routes = [
                'dashboard' => '/public/index.php',
                'owners' => '/public/owners.php',
                'patients' => '/public/patients.php',
                'appointments' => '/public/appointments.php',
                'invoices' => '/public/invoices.php',
                'settings' => '/public/settings.php',
                'login' => '/public/login.php',
                'logout' => '/public/logout.php'
            ];
            $url = $routes[$name] ?? '/public/index.php';
            if (!empty($params)) {
                $url .= '?' . http_build_query($params);
            }
            return $url;
        }));
        
        // Add global variables
        $twig->addGlobal('base_url', '');
        $twig->addGlobal('current_year', date('Y'));
        $twig->addGlobal('app', [
            'name' => 'Tierphysio Manager',
            'version' => '2.0.0',
            'locale' => 'de_DE',
            'description' => 'Moderne Praxisverwaltung für Tierphysiotherapie'
        ]);
        
        // Add flash messages if available
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (isset($_SESSION['flash_success'])) {
                $data['flash_success'] = $_SESSION['flash_success'];
                unset($_SESSION['flash_success']);
            }
            if (isset($_SESSION['flash_error'])) {
                $data['flash_error'] = $_SESSION['flash_error'];
                unset($_SESSION['flash_error']);
            }
            if (isset($_SESSION['flash_warning'])) {
                $data['flash_warning'] = $_SESSION['flash_warning'];
                unset($_SESSION['flash_warning']);
            }
            if (isset($_SESSION['flash_info'])) {
                $data['flash_info'] = $_SESSION['flash_info'];
                unset($_SESSION['flash_info']);
            }
        }
        
        // Render template
        echo $twig->render($path, $data);
        
    } catch (Exception $e) {
        // In development, show error
        if (defined('APP_DEBUG') && APP_DEBUG) {
            echo '<pre style="color: red; background: #fff; padding: 20px; border: 2px solid red;">';
            echo 'Template Error: ' . htmlspecialchars($e->getMessage());
            echo "\n\nStack Trace:\n";
            echo htmlspecialchars($e->getTraceAsString());
            echo '</pre>';
        } else {
            // In production, show generic error
            echo '<div style="padding: 20px; text-align: center;">';
            echo '<h1>Ein Fehler ist aufgetreten</h1>';
            echo '<p>Bitte versuchen Sie es später erneut oder kontaktieren Sie den Administrator.</p>';
            echo '</div>';
        }
        exit;
    }
}

/**
 * Set a flash message
 * @param string $type Type of message (success, error, warning, info)
 * @param string $message Message text
 * @return void
 */
function set_flash($type, $message) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['flash_' . $type] = $message;
}

/**
 * Get a flash message
 * @param string $type Type of message
 * @return string|null
 */
function get_flash($type) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (isset($_SESSION['flash_' . $type])) {
        $message = $_SESSION['flash_' . $type];
        unset($_SESSION['flash_' . $type]);
        return $message;
    }
    return null;
}

/**
 * Fallback template rendering without Twig
 * @param string $path Template path
 * @param array $data Data for template
 * @return void
 */
function render_template_fallback($path, $data = []) {
    // Extract data to variables
    extract($data);
    
    // Start output buffering
    ob_start();
    
    // Build full template path
    $template_file = __DIR__ . '/../templates/' . $path;
    
    // Check if it's a Twig file and needs conversion
    if (str_ends_with($template_file, '.twig')) {
        // For now, show a simple HTML message
        ?>
        <!DOCTYPE html>
        <html lang="de">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Tierphysio Manager 2.0 - Setup Required</title>
            <style>
                body {
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    margin: 0;
                    padding: 20px;
                }
                .container {
                    background: white;
                    border-radius: 12px;
                    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                    padding: 40px;
                    max-width: 600px;
                    width: 100%;
                }
                h1 {
                    color: #333;
                    margin-bottom: 10px;
                }
                .subtitle {
                    color: #666;
                    margin-bottom: 30px;
                }
                .status {
                    background: #f8f9fa;
                    border-radius: 8px;
                    padding: 20px;
                    margin: 20px 0;
                }
                .status-item {
                    display: flex;
                    align-items: center;
                    margin: 10px 0;
                }
                .status-icon {
                    width: 24px;
                    height: 24px;
                    margin-right: 10px;
                }
                .success { color: #28a745; }
                .warning { color: #ffc107; }
                .error { color: #dc3545; }
                .info-box {
                    background: #e3f2fd;
                    border-left: 4px solid #2196f3;
                    padding: 15px;
                    margin: 20px 0;
                    border-radius: 4px;
                }
                .code {
                    background: #f5f5f5;
                    padding: 10px;
                    border-radius: 4px;
                    font-family: 'Courier New', monospace;
                    margin: 10px 0;
                }
                .btn {
                    display: inline-block;
                    padding: 12px 24px;
                    background: #667eea;
                    color: white;
                    text-decoration: none;
                    border-radius: 6px;
                    margin-top: 20px;
                    transition: background 0.3s;
                }
                .btn:hover {
                    background: #5a67d8;
                }
            </style>
        </head>
        <body>
            <div class="container">
                <h1>🐾 Tierphysio Manager 2.0</h1>
                <div class="subtitle">Setup erforderlich</div>
                
                <div class="status">
                    <div class="status-item">
                        <span class="status-icon success">✓</span>
                        <span>Bootstrap.php wurde erfolgreich erstellt</span>
                    </div>
                    <div class="status-item">
                        <span class="status-icon success">✓</span>
                        <span>Konfiguration geladen</span>
                    </div>
                    <div class="status-item">
                        <span class="status-icon warning">⚠</span>
                        <span>Twig Template Engine nicht installiert</span>
                    </div>
                </div>
                
                <div class="info-box">
                    <strong>Installation erforderlich:</strong>
                    <p>Die Twig Template Engine muss installiert werden. Führen Sie folgende Befehle aus:</p>
                    <div class="code">
                        curl -sS https://getcomposer.org/installer | php<br>
                        php composer.phar install
                    </div>
                </div>
                
                <div style="margin-top: 30px;">
                    <p><strong>Aktueller Status:</strong></p>
                    <ul>
                        <li>PHP-Umgebung: <?php echo phpversion(); ?></li>
                        <li>Template-Pfad: <?php echo htmlspecialchars($path); ?></li>
                        <li>Arbeitsverzeichnis: <?php echo getcwd(); ?></li>
                    </ul>
                </div>
                
                <?php if (isset($data['error'])): ?>
                <div class="status-item">
                    <span class="status-icon error">✗</span>
                    <span><?php echo htmlspecialchars($data['error']); ?></span>
                </div>
                <?php endif; ?>
                
                <a href="/admin/" class="btn">Zum Admin-Panel</a>
            </div>
        </body>
        </html>
        <?php
    } else if (file_exists($template_file)) {
        // Include PHP template
        include $template_file;
    } else {
        echo "Template nicht gefunden: " . htmlspecialchars($path);
    }
    
    // Get contents and clean buffer
    $output = ob_get_clean();
    echo $output;
}