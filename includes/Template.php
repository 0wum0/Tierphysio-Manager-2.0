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
        // Ensure templates that depend on a global user (e.g. base layout)
        // still receive user context even if only user_id is stored in session.
        if (!array_key_exists('user', $data)) {
            try {
                $auth = Auth::getInstance();
                if (method_exists($auth, 'getUser')) {
                    $u = $auth->getUser();
                    if (is_array($u) || is_object($u)) {
                        $data['user'] = $u;
                    }
                }
            } catch (\Throwable $e) {
                // keep rendering without forcing user context
            }
        }

        require_once __DIR__ . '/template.php';
        render_template($template, $data);
    }
}
