<?php
/**
 * Simple Template System for Tierphysio Manager 2.0
 * Falls back to PHP templates when Twig is not available
 */

class SimpleTemplate {
    private static $instance = null;
    private $templateDir;
    private $data = [];
    
    private function __construct() {
        $this->templateDir = __DIR__ . '/../templates/';
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function render($template, $data = []) {
        $this->data = array_merge($this->data, $data);
        
        // Add global data
        $this->data['user'] = $_SESSION['user'] ?? null;
        $this->data['csrf_token'] = csrf_token();
        $this->data['flash'] = $this->getFlash();
        
        // Convert template path
        $templateFile = $this->templateDir . str_replace('.twig', '.php', $template);
        
        if (!file_exists($templateFile)) {
            // Try as .twig file  
            $templateFile = $this->templateDir . $template;
        }
        
        if (!file_exists($templateFile)) {
            die("Template not found: $template");
        }
        
        // Extract data to variables
        extract($this->data);
        
        // Start output buffering
        ob_start();
        
        // Include template
        include $templateFile;
        
        // Return output
        return ob_get_clean();
    }
    
    public function display($template, $data = []) {
        echo $this->render($template, $data);
    }
    
    public static function setFlash($type, $message) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['flash'][$type][] = $message;
    }
    
    private function getFlash() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $flash = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $flash;
    }
}