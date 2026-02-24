<?php
declare(strict_types=1);

namespace TierphysioManager;

/**
 * Application Template service used by public/*.php pages.
 *
 * Uses the existing standalone Twig renderer in includes/template_standalone.php,
 * which already requires ../vendor/autoload.php.
 */
class Template {
    private static ?self $instance = null;

    private function __construct() {}

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function display(string $template, array $data = []): void {
        if (!function_exists('render_template')) {
            require_once __DIR__ . '/template_standalone.php';
        }
        render_template($template, $data);
    }

    /**
     * Set a flash message (static alias for set_flash() in template_standalone.php)
     */
    public static function setFlash(string $type, string $message): void {
        if (!function_exists('set_flash')) {
            require_once __DIR__ . '/template_standalone.php';
        }
        set_flash($type, $message);
    }
}
