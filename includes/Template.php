<?php
namespace TierphysioManager;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\Extension\DebugExtension;
use Twig\TwigFunction;

/**
 * Template Engine Manager
 * Handles Twig template rendering
 */
class Template {
    private static $instance = null;
    private $twig;
    private $auth;
    private $db;
    
    /**
     * Private constructor - Singleton pattern
     */
    private function __construct() {
        $this->auth = Auth::getInstance();
        $this->db = Database::getInstance();
        
        // Initialize Twig
        $loader = new FilesystemLoader(__DIR__ . '/../templates');
        $this->twig = new Environment($loader, [
            'cache' => false, // Disable cache for development
            'debug' => defined('APP_DEBUG') ? APP_DEBUG : false,
            'auto_reload' => true
        ]);
        
        // Add debug extension
        if ($this->twig->isDebug()) {
            $this->twig->addExtension(new DebugExtension());
        }
        
        // Add custom functions
        $this->addCustomFunctions();
        
        // Add global variables
        $this->addGlobalVariables();
    }
    
    /**
     * Get Template instance
     * @return Template
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Add custom Twig functions
     */
    private function addCustomFunctions() {
        // URL function
        $this->twig->addFunction(new TwigFunction('url', function($path = '') {
            $baseUrl = defined('APP_URL') ? APP_URL : '';
            return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
        }));
        
        // Asset function
        $this->twig->addFunction(new TwigFunction('asset', function($path) {
            $baseUrl = defined('APP_URL') ? APP_URL : '';
            return rtrim($baseUrl, '/') . '/public/' . ltrim($path, '/');
        }));
        
        // CSRF token function
        $this->twig->addFunction(new TwigFunction('csrf_token', function() {
            return $this->auth->generateCSRFToken();
        }));
        
        // CSRF field function
        $this->twig->addFunction(new TwigFunction('csrf_field', function() {
            $token = $this->auth->generateCSRFToken();
            $name = defined('CSRF_TOKEN_NAME') ? CSRF_TOKEN_NAME : '_csrf_token';
            return '<input type="hidden" name="' . $name . '" value="' . $token . '">';
        }));
        
        // Has permission function
        $this->twig->addFunction(new TwigFunction('has_permission', function($permission) {
            return $this->auth->hasPermission($permission);
        }));
        
        // Is admin function
        $this->twig->addFunction(new TwigFunction('is_admin', function() {
            return $this->auth->isAdmin();
        }));
        
        // Date format function
        $this->twig->addFunction(new TwigFunction('date_format', function($date, $format = null) {
            if (!$date) return '';
            if (!$format) {
                $format = defined('DATE_FORMAT') ? DATE_FORMAT : 'd.m.Y';
            }
            return date($format, strtotime($date));
        }));
        
        // Time format function
        $this->twig->addFunction(new TwigFunction('time_format', function($time, $format = null) {
            if (!$time) return '';
            if (!$format) {
                $format = defined('TIME_FORMAT') ? TIME_FORMAT : 'H:i';
            }
            return date($format, strtotime($time));
        }));
        
        // Currency format function
        $this->twig->addFunction(new TwigFunction('currency', function($amount) {
            $symbol = defined('CURRENCY_SYMBOL') ? CURRENCY_SYMBOL : '€';
            return number_format($amount, 2, ',', '.') . ' ' . $symbol;
        }));
        
        // Translate function
        $this->twig->addFunction(new TwigFunction('__', function($key, $params = []) {
            // Simple translation function - can be extended with i18n library
            $translations = $this->getTranslations();
            $text = $translations[$key] ?? $key;
            
            foreach ($params as $param => $value) {
                $text = str_replace(':' . $param, $value, $text);
            }
            
            return $text;
        }));
        
        // Get setting function
        $this->twig->addFunction(new TwigFunction('setting', function($key, $default = null) {
            return $this->getSetting($key, $default);
        }));
    }
    
    /**
     * Add global variables to Twig
     */
    private function addGlobalVariables() {
        // App info
        $this->twig->addGlobal('app', [
            'name' => defined('APP_NAME') ? APP_NAME : 'Tierphysio Manager',
            'version' => defined('APP_VERSION') ? APP_VERSION : '2.0.0',
            'year' => date('Y'),
            'debug' => defined('APP_DEBUG') ? APP_DEBUG : false,
            'url' => defined('APP_URL') ? APP_URL : '',
            'locale' => defined('APP_LOCALE') ? APP_LOCALE : 'de_DE',
            'timezone' => defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Europe/Berlin'
        ]);
        
        // Current user
        $this->twig->addGlobal('user', $this->auth->getUser());
        
        // Current page info
        $this->twig->addGlobal('current_page', [
            'url' => $_SERVER['REQUEST_URI'] ?? '',
            'path' => parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH),
            'query' => $_GET
        ]);
        
        // Flash messages from session
        $flash = [];
        if (isset($_SESSION['flash'])) {
            $flash = $_SESSION['flash'];
            unset($_SESSION['flash']);
        }
        $this->twig->addGlobal('flash', $flash);
    }
    
    /**
     * Render template
     * @param string $template
     * @param array $data
     * @return string
     */
    public function render($template, $data = []) {
        try {
            // Add .twig extension if not present
            if (!str_ends_with($template, '.twig')) {
                $template .= '.twig';
            }
            
            return $this->twig->render($template, $data);
        } catch (\Exception $e) {
            error_log('Template Error: ' . $e->getMessage());
            
            if (defined('APP_DEBUG') && APP_DEBUG) {
                throw $e;
            } else {
                return 'Ein Fehler ist aufgetreten. Bitte kontaktieren Sie den Administrator.';
            }
        }
    }
    
    /**
     * Display template
     * @param string $template
     * @param array $data
     */
    public function display($template, $data = []) {
        echo $this->render($template, $data);
    }
    
    /**
     * Set flash message
     * @param string $type
     * @param string $message
     */
    public static function setFlash($type, $message) {
        if (!isset($_SESSION['flash'])) {
            $_SESSION['flash'] = [];
        }
        $_SESSION['flash'][$type] = $message;
    }
    
    /**
     * Get translations
     * @return array
     */
    private function getTranslations() {
        // Basic German translations
        return [
            'dashboard' => 'Dashboard',
            'patients' => 'Patienten',
            'owners' => 'Besitzer',
            'appointments' => 'Termine',
            'treatments' => 'Behandlungen',
            'invoices' => 'Rechnungen',
            'notes' => 'Notizen',
            'settings' => 'Einstellungen',
            'profile' => 'Profil',
            'logout' => 'Abmelden',
            'login' => 'Anmelden',
            'save' => 'Speichern',
            'cancel' => 'Abbrechen',
            'delete' => 'Löschen',
            'edit' => 'Bearbeiten',
            'add' => 'Hinzufügen',
            'search' => 'Suchen',
            'filter' => 'Filtern',
            'export' => 'Exportieren',
            'import' => 'Importieren',
            'print' => 'Drucken',
            'back' => 'Zurück',
            'next' => 'Weiter',
            'previous' => 'Zurück',
            'yes' => 'Ja',
            'no' => 'Nein',
            'confirm' => 'Bestätigen',
            'loading' => 'Laden...',
            'error' => 'Fehler',
            'success' => 'Erfolgreich',
            'warning' => 'Warnung',
            'info' => 'Info',
            'welcome' => 'Willkommen',
            'goodbye' => 'Auf Wiedersehen',
            'good_morning' => 'Guten Morgen',
            'good_afternoon' => 'Guten Tag',
            'good_evening' => 'Guten Abend',
            'today' => 'Heute',
            'yesterday' => 'Gestern',
            'tomorrow' => 'Morgen',
            'week' => 'Woche',
            'month' => 'Monat',
            'year' => 'Jahr',
            'all' => 'Alle',
            'none' => 'Keine',
            'active' => 'Aktiv',
            'inactive' => 'Inaktiv',
            'open' => 'Offen',
            'closed' => 'Geschlossen',
            'pending' => 'Ausstehend',
            'completed' => 'Abgeschlossen',
            'cancelled' => 'Abgebrochen',
            'paid' => 'Bezahlt',
            'unpaid' => 'Unbezahlt',
            'overdue' => 'Überfällig'
        ];
    }
    
    /**
     * Get setting from database
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    private function getSetting($key, $default = null) {
        try {
            // Split key into category and key
            $parts = explode('.', $key, 2);
            if (count($parts) === 2) {
                $result = $this->db->selectOne('tp_settings', [
                    'category' => $parts[0],
                    'key' => $parts[1]
                ]);
            } else {
                $result = $this->db->selectOne('tp_settings', ['key' => $key]);
            }
            
            if ($result) {
                $value = $result['value'];
                
                // Convert based on type
                switch ($result['type']) {
                    case 'boolean':
                        return (bool) $value;
                    case 'number':
                        return is_numeric($value) ? (float) $value : $default;
                    case 'json':
                    case 'array':
                        $decoded = json_decode($value, true);
                        return $decoded !== null ? $decoded : $default;
                    default:
                        return $value;
                }
            }
        } catch (\Exception $e) {
            error_log('Failed to get setting: ' . $e->getMessage());
        }
        
        return $default;
    }
}