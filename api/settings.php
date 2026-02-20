<?php
/**
 * /api/settings.php
 * Settings API (JSON) - categories + list + save + add + delete
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

$autoloadCandidates = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
];

$autoloadPath = null;
foreach ($autoloadCandidates as $p) {
    if (is_file($p)) { $autoloadPath = $p; break; }
}

if (!$autoloadPath) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Autoload nicht gefunden']);
    exit;
}

require_once $autoloadPath;

use TierphysioManager\Auth;
use TierphysioManager\Database;

try {
    $auth = Auth::getInstance();
    $dbWrap = Database::getInstance();

    // Login + Permission
    $auth->requireLogin();
    $auth->requirePermission('manage_settings');

    $pdo = method_exists($dbWrap, 'getConnection') ? $dbWrap->getConnection() : null;
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('PDO Verbindung fehlt.');
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = $_GET['action'] ?? ($_POST['action'] ?? 'categories');
    $showSystem = (int)($_GET['show_system'] ?? 0);

    $jsonBody = null;
    if ($method === 'POST' || $method === 'PUT' || $method === 'DELETE') {
        $raw = file_get_contents('php://input');
        $jsonBody = $raw ? json_decode($raw, true) : null;
    }

    // helper: allow only short types (wegen deinem type-truncation Problem)
    $normalizeType = function($t): string {
        $t = strtolower(trim((string)$t));
        // nur kurze Werte erlauben
        $allowed = ['string','text','int','bool','json','email','url','pass'];
        if (!in_array($t, $allowed, true)) return 'string';
        return $t;
    };

    $ok = function(array $data = [], string $message = 'OK') {
        echo json_encode(['status' => 'success', 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE);
        exit;
    };

    $fail = function(string $message, int $code = 400) {
        http_response_code($code);
        echo json_encode(['status' => 'error', 'message' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    };

    // ===== GET =====
    if ($method === 'GET') {
        if ($action === 'categories') {
            if ($showSystem === 1) {
                $stmt = $pdo->query("
                    SELECT category, COUNT(*) AS count
                    FROM tp_settings
                    GROUP BY category
                    ORDER BY category
                ");
            } else {
                $stmt = $pdo->query("
                    SELECT category, COUNT(*) AS count
                    FROM tp_settings
                    WHERE COALESCE(is_system,0) = 0
                    GROUP BY category
                    ORDER BY category
                ");
            }

            $cats = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $ok(['categories' => $cats], 'Kategorien geladen');
        }

        if ($action === 'list') {
            $category = $_GET['category'] ?? 'general';

            if ($showSystem === 1) {
                $stmt = $pdo->prepare("
                    SELECT id, category, `key`, value, type, description, COALESCE(is_system,0) AS is_system
                    FROM tp_settings
                    WHERE category = :c
                    ORDER BY `key`
                ");
            } else {
                $stmt = $pdo->prepare("
                    SELECT id, category, `key`, value, type, description, COALESCE(is_system,0) AS is_system
                    FROM tp_settings
                    WHERE category = :c AND COALESCE(is_system,0) = 0
                    ORDER BY `key`
                ");
            }

            $stmt->execute([':c' => $category]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $ok(['settings' => $rows], 'Settings geladen');
        }

        $fail('Unbekannte GET action', 400);
    }

    // ===== POST =====
    if ($method === 'POST') {
        // SAVE
        if ($action === 'save') {
            $payload = $jsonBody ?? [];
            $category = (string)($payload['category'] ?? '');
            $items = $payload['settings'] ?? [];

            if ($category === '') $fail('Kategorie fehlt', 400);
            if (!is_array($items)) $fail('settings muss Array sein', 400);

            $pdo->beginTransaction();
            $updated = 0;

            foreach ($items as $it) {
                $key = (string)($it['key'] ?? '');
                if ($key === '') continue;

                $value = $it['value'] ?? '';
                if (is_array($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                } else {
                    $value = (string)$value;
                }

                $type = $normalizeType($it['type'] ?? 'string');
                $desc = isset($it['description']) ? (string)$it['description'] : null;

                // exists?
                $stmt = $pdo->prepare("SELECT id, COALESCE(is_system,0) AS is_system FROM tp_settings WHERE category = :c AND `key` = :k LIMIT 1");
                $stmt->execute([':c' => $category, ':k' => $key]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($row) {
                    // system only editable when show_system=1
                    if ((int)$row['is_system'] === 1 && $showSystem !== 1) continue;

                    $u = $pdo->prepare("
                        UPDATE tp_settings
                        SET value = :v,
                            type = :t,
                            description = :d,
                            updated_by = :uid,
                            updated_at = NOW()
                        WHERE id = :id
                    ");
                    $u->execute([
                        ':v' => $value,
                        ':t' => $type,
                        ':d' => $desc,
                        ':uid' => method_exists($auth, 'getUserId') ? $auth->getUserId() : null,
                        ':id' => (int)$row['id'],
                    ]);
                } else {
                    $i = $pdo->prepare("
                        INSERT INTO tp_settings (category, `key`, value, type, description, is_system, updated_by, created_at, updated_at)
                        VALUES (:c, :k, :v, :t, :d, 0, :uid, NOW(), NOW())
                    ");
                    $i->execute([
                        ':c' => $category,
                        ':k' => $key,
                        ':v' => $value,
                        ':t' => $type,
                        ':d' => $desc,
                        ':uid' => method_exists($auth, 'getUserId') ? $auth->getUserId() : null,
                    ]);
                }

                $updated++;
            }

            $pdo->commit();
            $ok(['updated' => $updated], $updated . ' Einstellungen gespeichert');
        }

        // ADD single
        if ($action === 'add') {
            $payload = $jsonBody ?? [];
            $category = trim((string)($payload['category'] ?? ''));
            $key = trim((string)($payload['key'] ?? ''));
            $value = $payload['value'] ?? '';
            $type = $normalizeType($payload['type'] ?? 'string');
            $desc = isset($payload['description']) ? (string)$payload['description'] : null;

            if ($category === '' || $key === '') $fail('category/key fehlt', 400);

            if (is_array($value)) $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            else $value = (string)$value;

            $stmt = $pdo->prepare("SELECT id FROM tp_settings WHERE category = :c AND `key` = :k LIMIT 1");
            $stmt->execute([':c' => $category, ':k' => $key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                $u = $pdo->prepare("
                    UPDATE tp_settings
                    SET value = :v, type = :t, description = :d, updated_by = :uid, updated_at = NOW()
                    WHERE id = :id
                ");
                $u->execute([
                    ':v' => $value,
                    ':t' => $type,
                    ':d' => $desc,
                    ':uid' => method_exists($auth, 'getUserId') ? $auth->getUserId() : null,
                    ':id' => (int)$row['id'],
                ]);
            } else {
                $i = $pdo->prepare("
                    INSERT INTO tp_settings (category, `key`, value, type, description, is_system, updated_by, created_at, updated_at)
                    VALUES (:c, :k, :v, :t, :d, 0, :uid, NOW(), NOW())
                ");
                $i->execute([
                    ':c' => $category,
                    ':k' => $key,
                    ':v' => $value,
                    ':t' => $type,
                    ':d' => $desc,
                    ':uid' => method_exists($auth, 'getUserId') ? $auth->getUserId() : null,
                ]);
            }

            $ok([], 'Einstellung gespeichert');
        }

        // DELETE
        if ($action === 'delete') {
            $payload = $jsonBody ?? [];
            $category = trim((string)($payload['category'] ?? ''));
            $key = trim((string)($payload['key'] ?? ''));

            if ($category === '' || $key === '') $fail('category/key fehlt', 400);

            $stmt = $pdo->prepare("SELECT id, COALESCE(is_system,0) AS is_system FROM tp_settings WHERE category = :c AND `key` = :k LIMIT 1");
            $stmt->execute([':c' => $category, ':k' => $key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) $fail('Nicht gefunden', 404);
            if ((int)$row['is_system'] === 1) $fail('System Setting kann nicht gelöscht werden', 403);

            $d = $pdo->prepare("DELETE FROM tp_settings WHERE id = :id");
            $d->execute([':id' => (int)$row['id']]);

            $ok([], 'Einstellung gelöscht');
        }

        $fail('Unbekannte POST action', 400);
    }

    $fail('Methode nicht erlaubt', 405);

} catch (Throwable $e) {
    error_log('Settings API Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Serverfehler: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}