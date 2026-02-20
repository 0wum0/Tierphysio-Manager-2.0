<?php
declare(strict_types=1);

namespace TierphysioManager;

/**
 * Template service expected by public controllers.
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
        if (!array_key_exists('user', $data)) {
            try {
                $auth = Auth::getInstance();
                if (method_exists($auth, 'getUser')) {
                    $user = $auth->getUser();
                    if (is_array($user) || is_object($user)) {
                        $data['user'] = $user;
                    }
                }
            } catch (\Throwable $e) {
                // continue rendering without explicit user
            }
        }

        require_once __DIR__ . '/template.php';
        render_template($template, $data);
    }
}
