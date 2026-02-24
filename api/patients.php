<?php
// Datei: patients.php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php'; // ggf. anpassen, falls dein Pfad anders ist

// ======================================================
// Helpers
// ======================================================

function api_success($data = [], int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'status' => 'success',
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function api_error(string $message, int $code = 400, $extra = null): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    $payload = [
        'ok' => false,
        'status' => 'error',
        'message' => $message
    ];
    if ($extra !== null) $payload['extra'] = $extra;
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Liest JSON-Body (mit Cache, damit Mehrfachaufrufe nicht "leer" sind)
 */
function read_json_body(): array {
    static $cache = null;

    if (is_array($cache)) return $cache;

    $raw = file_get_contents('php://input');
    if (!$raw) {
        $cache = [];
        return $cache;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $cache = [];
        return $cache;
    }

    $cache = $data;
    return $cache;
}

function safe_entity_type(string $type): string {
    $type = trim(strtolower($type));
    if ($type === 'record') return 'record';
    if ($type === 'note') return 'note';
    if ($type === 'treatment') return 'treatment';
    // Default
    return 'note';
}

function is_json_request(): bool {
    $ct = (string)($_SERVER['CONTENT_TYPE'] ?? '');
    return (stripos($ct, 'application/json') !== false);
}

function ensure_auth(): void {
    // Hier ggf. deine Auth-Logik/Session-Checks
    if (!isset($_SESSION['user_id'])) {
        api_error('Nicht eingeloggt', 401);
    }
}

// ======================================================
// Init
// ======================================================

ensure_auth();

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = (string)($_GET['action'] ?? ($_POST['action'] ?? ''));

// JSON-POST: action kann auch im Body stehen
if ($action === '' && is_json_request()) {
    $data = read_json_body();
    $action = (string)($data['action'] ?? '');
}

$action = trim($action);
if ($action === '') {
    api_error('Keine Aktion angegeben', 400);
}

// DB
/** @var PDO $pdo */
$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) {
    api_error('DB nicht verfügbar', 500);
}

// SQLite Flag (falls du das nutzt)
$isSqlite = (bool)($GLOBALS['isSqlite'] ?? false);

// ======================================================
// Router
// ======================================================

switch ($action) {

    // ======================================================
    // PATIENTS: LIST
    // ======================================================
    case 'list_patients': {
        try {
            $q = (string)($_GET['q'] ?? '');
            $species = (string)($_GET['species'] ?? '');
            $q = trim($q);
            $species = trim($species);

            $where = [];
            $params = [];

            if ($q !== '') {
                $where[] = "(p.name LIKE ? OR o.first_name LIKE ? OR o.last_name LIKE ? OR p.microchip LIKE ?)";
                $like = '%' . $q . '%';
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            }

            if ($species !== '') {
                $where[] = "p.species = ?";
                $params[] = $species;
            }

            $sql = "
                SELECT
                    p.*,
                    CONCAT(COALESCE(o.first_name,''),' ',COALESCE(o.last_name,'')) AS owner_full_name
                FROM tp_patients p
                LEFT JOIN tp_owners o ON o.id = p.owner_id
            ";

            if ($where) {
                $sql .= " WHERE " . implode(" AND ", $where);
            }

            $sql .= " ORDER BY p.created_at DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            api_success([
                'items' => $items,
                'count' => count($items),
            ]);
        } catch (Exception $e) {
            api_error('Fehler beim Laden der Patienten', 500);
        }
    } break;

    // ======================================================
    // PATIENTS: GET SINGLE
    // ======================================================
    case 'get_patient': {
        try {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) api_error('Ungültige ID', 400);

            $stmt = $pdo->prepare("
                SELECT
                    p.*,
                    o.salutation AS owner_salutation,
                    o.first_name AS owner_first_name,
                    o.last_name AS owner_last_name,
                    CONCAT(COALESCE(o.first_name,''),' ',COALESCE(o.last_name,'')) AS owner_full_name,
                    o.phone AS owner_phone,
                    o.mobile AS owner_mobile,
                    o.email AS owner_email,
                    o.street AS owner_street,
                    o.house_number AS owner_house_number,
                    o.postal_code AS owner_postal_code,
                    o.city AS owner_city
                FROM tp_patients p
                LEFT JOIN tp_owners o ON o.id = p.owner_id
                WHERE p.id = ?
                LIMIT 1
            ");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) api_error('Patient nicht gefunden', 404);

            api_success(['patient' => $row]);
        } catch (Exception $e) {
            api_error('Fehler beim Laden des Patienten', 500);
        }
    } break;

    // ======================================================
    // PATIENTS: CREATE (inkl. optionalem Profilbild-Upload)
    // ======================================================
    case 'create_patient': {
        try {
            $isJson = is_json_request();
            $data = $isJson ? read_json_body() : $_POST;

            $name = trim((string)($data['name'] ?? ''));
            $species = trim((string)($data['species'] ?? ''));
            $breed = trim((string)($data['breed'] ?? ''));
            $birth_date = trim((string)($data['birth_date'] ?? ''));
            $gender = trim((string)($data['gender'] ?? 'unknown'));
            $weight = (string)($data['weight'] ?? '');
            $microchip = trim((string)($data['microchip'] ?? ''));
            $color = trim((string)($data['color'] ?? ''));
            $notes = trim((string)($data['notes'] ?? ''));
            $health_status = trim((string)($data['health_status'] ?? 'ok'));

            $owner_id = (int)($data['owner_id'] ?? 0);

            if ($name === '' || $species === '') api_error('Name und Tierart sind erforderlich', 400);

            // Optional: Besitzer im Body als newOwner anlegen
            if ($owner_id <= 0 && isset($data['owner']) && is_array($data['owner'])) {
                $o = $data['owner'];

                $salutation = trim((string)($o['salutation'] ?? ''));
                $first_name = trim((string)($o['first_name'] ?? ''));
                $last_name = trim((string)($o['last_name'] ?? ''));
                $phone = trim((string)($o['phone'] ?? ''));
                $mobile = trim((string)($o['mobile'] ?? ''));
                $email = trim((string)($o['email'] ?? ''));
                $street = trim((string)($o['street'] ?? ''));
                $house_number = trim((string)($o['house_number'] ?? ''));
                $postal_code = trim((string)($o['postal_code'] ?? ''));
                $city = trim((string)($o['city'] ?? ''));

                if ($first_name === '' || $last_name === '') api_error('Besitzer Vor- und Nachname sind erforderlich', 400);

                $stmt = $pdo->prepare("
                    INSERT INTO tp_owners
                    (salutation, first_name, last_name, phone, mobile, email, street, house_number, postal_code, city, created_at)
                    VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $salutation, $first_name, $last_name, $phone, $mobile, $email,
                    $street, $house_number, $postal_code, $city
                ]);
                $owner_id = (int)$pdo->lastInsertId();
            }

            $stmt = $pdo->prepare("
                INSERT INTO tp_patients
                (owner_id, name, species, breed, birth_date, gender, weight, microchip, color, notes, health_status, is_active, created_at, updated_at)
                VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
            ");
            $stmt->execute([
                $owner_id > 0 ? $owner_id : null,
                $name, $species, $breed,
                $birth_date !== '' ? $birth_date : null,
                $gender,
                ($weight !== '' ? $weight : null),
                $microchip,
                $color,
                $notes,
                ($health_status !== '' ? $health_status : 'ok'),
            ]);

            $patient_id = (int)$pdo->lastInsertId();

            // Optional: Profilbild direkt mit hochladen (patient_image)
            $imageResult = handle_patient_image_upload($pdo, $patient_id, $isSqlite);

            api_success([
                'id' => $patient_id,
                'image' => $imageResult['image'] ?? null,
                'image_url' => $imageResult['image_url'] ?? null,
            ], 201);

        } catch (Exception $e) {
            api_error('Fehler beim Anlegen des Patienten', 500);
        }
    } break;

    // ======================================================
    // PATIENTS: UPDATE (inkl. optionalem Profilbild-Upload)
    // ======================================================
    case 'update_patient': {
        try {
            $isJson = is_json_request();
            $data = $isJson ? read_json_body() : $_POST;

            $id = (int)($data['id'] ?? ($_GET['id'] ?? 0));
            if ($id <= 0) api_error('Ungültige ID', 400);

            $name = trim((string)($data['name'] ?? ''));
            $species = trim((string)($data['species'] ?? ''));
            $breed = trim((string)($data['breed'] ?? ''));
            $birth_date = trim((string)($data['birth_date'] ?? ''));
            $gender = trim((string)($data['gender'] ?? 'unknown'));
            $weight = (string)($data['weight'] ?? '');
            $microchip = trim((string)($data['microchip'] ?? ''));
            $color = trim((string)($data['color'] ?? ''));
            $notes = trim((string)($data['notes'] ?? ''));
            $health_status = trim((string)($data['health_status'] ?? 'ok'));
            $owner_id = (int)($data['owner_id'] ?? 0);

            if ($name === '' || $species === '') api_error('Name und Tierart sind erforderlich', 400);

            $stmt = $pdo->prepare("
                UPDATE tp_patients
                SET owner_id = ?, name = ?, species = ?, breed = ?, birth_date = ?, gender = ?, weight = ?,
                    microchip = ?, color = ?, notes = ?, health_status = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $owner_id > 0 ? $owner_id : null,
                $name,
                $species,
                $breed,
                ($birth_date !== '' ? $birth_date : null),
                $gender,
                ($weight !== '' ? $weight : null),
                $microchip,
                $color,
                $notes,
                ($health_status !== '' ? $health_status : 'ok'),
                $id
            ]);

            // Optionales Profilbild (patient_image)
            $imageResult = handle_patient_image_upload($pdo, $id, $isSqlite);

            api_success([
                'id' => $id,
                'image' => $imageResult['image'] ?? null,
                'image_url' => $imageResult['image_url'] ?? null,
            ]);
        } catch (Exception $e) {
            api_error('Fehler beim Speichern', 500);
        }
    } break;

    // ======================================================
    // PATIENTS: DELETE
    // ======================================================
    case 'delete_patient': {
        try {
            $isJson = is_json_request();
            $data = $isJson ? read_json_body() : $_POST;
            $id = (int)($data['id'] ?? ($_GET['id'] ?? 0));
            if ($id <= 0) api_error('Ungültige ID', 400);

            $stmt = $pdo->prepare("DELETE FROM tp_patients WHERE id = ?");
            $stmt->execute([$id]);

            api_success(['id' => $id]);
        } catch (Exception $e) {
            api_error('Fehler beim Löschen', 500);
        }
    } break;

    // ======================================================
    // OWNERS: LIST
    // ======================================================
    case 'list_owners': {
        try {
            $stmt = $pdo->query("SELECT * FROM tp_owners ORDER BY last_name ASC, first_name ASC");
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            api_success(['items' => $items, 'count' => count($items)]);
        } catch (Exception $e) {
            api_error('Fehler beim Laden der Besitzer', 500);
        }
    } break;

    // ======================================================
    // TIMELINE: GET
    // ======================================================
    case 'get_timeline': {
        try {
            $isJson = is_json_request();
            $data = $isJson ? read_json_body() : $_GET;

            $patient_id = (int)($data['patient_id'] ?? 0);
            if ($patient_id <= 0) api_error('Patient ID ist erforderlich', 400);

            // ... (Timeline-Logik bleibt wie bei dir)
            // (Hier kommt im Teil 2+ der restliche Code)

            // Platzhalter (wird im nächsten Teil fortgesetzt)
            // api_success([...]);

        } catch (Exception $e) {
            api_error('Fehler beim Laden der Timeline', 500);
        }
    } break;

    // ======================================================
    // (REST KOMMT IN TEIL 2)
    // ======================================================

    default:
        api_error('Unbekannte Aktion: ' . $action, 400);
}
