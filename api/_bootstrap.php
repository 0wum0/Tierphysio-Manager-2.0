<?php
/**
 * Tierphysio Manager 2.0
 * API Bootstrap - Centralized helpers and configuration
 *
 * Ziele:
 * - gleiche Session wie Frontend (tierphysio_session)
 * - API liefert immer JSON (keine Redirects)
 * - $db ist am Ende IMMER eine PDO Instanz (egal ob Projekt $pdo/$db/$conn nutzt oder Database Singleton)
 */

declare(strict_types=1);

// JSON header immediately
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

// Production error handling
error_reporting(E_ALL);
ini_set('display_errors', '0');

// Clear any existing output buffer to avoid broken JSON
while (ob_get_level() > 0) { @ob_end_clean(); }
ob_start();

/**
 * Robust autoload resolver (Root/Public Unterschiede)
 */
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
    echo json_encode(['status' => 'error', 'message' => 'Autoload nicht gefunden (vendor/autoload.php).'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once $autoloadPath;

/**
 * version.php optional robust laden (wenn vorhanden)
 */
$versionCandidates = [
    __DIR__ . '/../includes/version.php',
    __DIR__ . '/includes/version.php',
    __DIR__ . '/../../includes/version.php',
];
foreach ($versionCandidates as $p) {
    if (is_file($p)) { require_once $p; break; }
}

/**
 * SESSION FIX:
 * Login hängt bei dir an "tierphysio_session". API muss dieselbe Session nutzen.
 */
if (session_status() === PHP_SESSION_NONE) {
    @session_name('tierphysio_session');

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    @session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',          // wichtig für /public UND /api
        'domain' => '',         // current host
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    @session_start();
}

/**
 * Small JSON helpers for bootstrap failures
 */
function _bootstrap_json_fail(int $code, string $message): void {
    while (ob_get_level() > 0) { @ob_end_clean(); }
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Load DB bootstrap file (dein Projekt nutzt includes/db.php)
 */
$dbCandidates = [
    __DIR__ . '/../includes/db.php',
    __DIR__ . '/includes/db.php',
    __DIR__ . '/../../includes/db.php',
];

$dbPath = null;
foreach ($dbCandidates as $p) {
    if (is_file($p)) { $dbPath = $p; break; }
}

if (!$dbPath) {
    _bootstrap_json_fail(500, 'DB Datei nicht gefunden (includes/db.php).');
}

require_once $dbPath;

/**
 * DB RESOLVER:
 * Manche Projekte setzen $pdo, andere $db, $conn, $connection usw.
 * Außerdem existiert bei dir sehr wahrscheinlich TierphysioManager\Database (Singleton) -> PDO via getConnection()
 *
 * Am Ende muss $db eine PDO Instanz sein.
 */
$pdoCandidate = null;

// 1) klassische Variablen
if (isset($pdo) && ($pdo instanceof PDO)) {
    $pdoCandidate = $pdo;
} elseif (isset($db) && ($db instanceof PDO)) {
    $pdoCandidate = $db;
} elseif (isset($conn) && ($conn instanceof PDO)) {
    $pdoCandidate = $conn;
} elseif (isset($connection) && ($connection instanceof PDO)) {
    $pdoCandidate = $connection;
} elseif (isset($dbh) && ($dbh instanceof PDO)) {
    $pdoCandidate = $dbh;
} elseif (isset($PDO) && ($PDO instanceof PDO)) {
    $pdoCandidate = $PDO;
}

// 2) Falls db.php ein Objekt liefert, das ->getConnection() hat
if (!$pdoCandidate) {
    $maybeObjects = [];
    if (isset($database)) $maybeObjects[] = $database;
    if (isset($db)) $maybeObjects[] = $db;

    foreach ($maybeObjects as $obj) {
        if (is_object($obj) && method_exists($obj, 'getConnection')) {
            try {
                $tmp = $obj->getConnection();
                if ($tmp instanceof PDO) {
                    $pdoCandidate = $tmp;
                    break;
                }
            } catch (Throwable $e) {
                // ignore
            }
        }
    }
}

// 3) TierphysioManager\Database Singleton (aus deinem Code sehr wahrscheinlich vorhanden)
if (!$pdoCandidate && class_exists(\TierphysioManager\Database::class)) {
    try {
        $dbSingleton = \TierphysioManager\Database::getInstance();

        if (is_object($dbSingleton)) {
            if (method_exists($dbSingleton, 'getConnection')) {
                $tmp = $dbSingleton->getConnection();
                if ($tmp instanceof PDO) {
                    $pdoCandidate = $tmp;
                }
            } elseif ($dbSingleton instanceof PDO) {
                $pdoCandidate = $dbSingleton;
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
}

if (!$pdoCandidate || !($pdoCandidate instanceof PDO)) {
    _bootstrap_json_fail(500, 'DB (PDO) nicht initialisiert: includes/db.php liefert keine PDO Instanz ($pdo/$db/$conn) und Database::getConnection() ist nicht verfügbar.');
}

// Ab hier: einheitlich $db als PDO
$db = $pdoCandidate;

/**
 * Initialize Auth
 */
use TierphysioManager\Auth;

try {
    $auth = Auth::getInstance();
} catch (Throwable $e) {
    _bootstrap_json_fail(500, 'Auth init fehlgeschlagen: ' . $e->getMessage());
}

/**
 * API Success Response
 */
function api_success(array $payload = []): void {
    while (ob_get_level() > 0) { @ob_end_clean(); }

    $items = $payload['items'] ?? ($payload['data'] ?? []);
    $count = isset($payload['count']) ? (int)$payload['count'] : (is_array($items) ? count($items) : 0);

    if (!is_array($items)) {
        $items = [$items];
    }

    $response = [
        'ok' => true,
        'status' => 'success',
        'data' => [
            'items' => $items,
            'count' => $count
        ]
    ];

    foreach ($payload as $key => $value) {
        if (!in_array($key, ['items', 'data', 'count'], true)) {
            $response[$key] = $value;
        }
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * API Error Response
 */
function api_error(string $message, int $code = 400): void {
    while (ob_get_level() > 0) { @ob_end_clean(); }

    // HTTP 200 für Frontend-Kompatibilität (wie vorher)
    http_response_code(200);

    echo json_encode([
        'ok' => false,
        'status' => 'error',
        'message' => $message
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}