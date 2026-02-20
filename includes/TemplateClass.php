<?php
declare(strict_types=1);

namespace TierphysioManager;

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
        if (function_exists('render_template')) {
            render_template($template, $data);
            return;
        }

        // Fallback: load standalone template helper.
        require_once __DIR__ . '/template.php';
        render_template($template, $data);
    }
}
