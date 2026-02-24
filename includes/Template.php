<?php
declare(strict_types=1);

namespace TierphysioManager;

/**
 * Application Template service used by public/*.php pages.
 *
 * Uses the existing standalone Twig renderer in includes/template.php,
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
        require_once __DIR__ . '/template.php';
        render_template($template, $data);
    }
}
