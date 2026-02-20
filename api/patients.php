<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if (isset($auth) && is_object($auth)) {
    if (method_exists($auth, 'requireLogin')) $auth->requireLogin();
    if (method_exists($auth, 'requirePermission')) $auth->requirePermission('view_patients');
}

/** @var PDO $db */
$pdo = $db;
$isSqlite = ((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite');
$nowExpr = $isSqlite ? "datetime('now')" : 'NOW()';

$rawBody = (string)file_get_contents('php://input');
$json = json_decode($rawBody, true);
if (!is_array($json)) $json = [];

$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? $json['action'] ?? ''));
$aliases = [
    'list' => 'list_patients',
    'get' => 'get_patient',
    'create' => 'create_patient',
    'update' => 'update_patient',
    'delete' => 'delete_patient',
    'upload_image' => 'upload_patient_image',
    'delete_image' => 'delete_patient_image',
];
$action = $aliases[$action] ?? $action;

if ($action === '') api_error('Keine Aktion angegeben', 400);

function payload(array $json): array {
    if (!empty($_POST)) return $_POST;
    return $json;
}

function owner_name_expr(bool $isSqlite): string {
    return $isSqlite
        ? "TRIM(COALESCE(o.first_name,'') || ' ' || COALESCE(o.last_name,''))"
        : "TRIM(CONCAT(COALESCE(o.first_name,''), ' ', COALESCE(o.last_name,'')))";
}

function normalize_path(string $path): string {
    return '/' . ltrim(str_replace('\\', '/', $path), '/');
}

function save_upload(string $field, string $subdir = 'patients'): ?array {
    if (empty($_FILES[$field]) || !is_array($_FILES[$field])) return null;
    $f = $_FILES[$field];
    if ((int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;

    $base = dirname(__DIR__) . '/uploads/' . trim($subdir, '/');
    if (!is_dir($base)) @mkdir($base, 0775, true);

    $orig = (string)($f['name'] ?? 'file');
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!preg_match('/^[a-z0-9]{1,8}$/', $ext)) $ext = 'bin';
    $name = uniqid('p_', true) . '.' . $ext;
    $dest = $base . '/' . $name;

    if (!@move_uploaded_file((string)$f['tmp_name'], $dest)) return null;

    return [
        'file_name' => $orig,
        'file_path' => 'uploads/' . trim($subdir, '/') . '/' . $name,
        'file_size' => (int)($f['size'] ?? 0),
        'mime_type' => (string)($f['type'] ?? 'application/octet-stream'),
    ];
}

function get_timeline(PDO $pdo, int $patientId): array {
    $items = [];

    $n = $pdo->prepare("SELECT id, type, title, content, created_at, updated_at FROM tp_notes WHERE patient_id = ? ORDER BY created_at DESC");
    $n->execute([$patientId]);
    foreach (($n->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        $kind = (($row['type'] ?? 'note') === 'medical') ? 'record' : 'note';
        $items[] = [
            'id' => (int)$row['id'],
            'entity_type' => $kind,
            'title' => (string)($row['title'] ?? ''),
            'content' => (string)($row['content'] ?? ''),
            'entry_date' => (string)($row['created_at'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? ''),
        ];
    }

    $t = $pdo->prepare("SELECT id, treatment_date, treatment_type, description, notes, created_at, updated_at FROM tp_treatments WHERE patient_id = ? ORDER BY treatment_date DESC, created_at DESC");
    $t->execute([$patientId]);
    foreach (($t->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        $items[] = [
            'id' => (int)$row['id'],
            'entity_type' => 'treatment',
            'title' => (string)($row['treatment_type'] ?? 'Behandlung'),
            'content' => trim((string)($row['description'] ?? '') . "\n" . (string)($row['notes'] ?? '')),
            'entry_date' => (string)($row['treatment_date'] ?? $row['created_at'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? ''),
        ];
    }

    usort($items, static fn(array $a, array $b): int => strcmp((string)($b['entry_date'] ?? ''), (string)($a['entry_date'] ?? '')));
    return $items;
}

try {
    switch ($action) {
        case 'list_patients':
        case 'get_patients': {
            $q = trim((string)($_GET['q'] ?? ''));
            $species = trim((string)($_GET['species'] ?? ''));
            $where = [];
            $params = [];
            if ($q !== '') {
                $where[] = '(p.name LIKE ? OR p.microchip LIKE ? OR o.first_name LIKE ? OR o.last_name LIKE ?)';
                $like = '%' . $q . '%';
                $params = [$like, $like, $like, $like];
            }
            if ($species !== '') {
                $where[] = 'p.species = ?';
                $params[] = $species;
            }

            $sql = 'SELECT p.*, ' . owner_name_expr($isSqlite) . ' AS owner_full_name FROM tp_patients p LEFT JOIN tp_owners o ON o.id = p.owner_id';
            if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
            $sql .= ' ORDER BY p.created_at DESC';

            $st = $pdo->prepare($sql);
            $st->execute($params);
            $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            api_success(['items' => $items, 'count' => count($items)]);
        }

        case 'get_patient': {
            $id = (int)($_GET['id'] ?? $json['id'] ?? 0);
            if ($id <= 0) api_error('Ungültige ID', 400);

            $sql = 'SELECT p.*, ' . owner_name_expr($isSqlite) . ' AS owner_full_name,
                    o.salutation AS owner_salutation, o.first_name AS owner_first_name, o.last_name AS owner_last_name,
                    o.phone AS owner_phone, o.mobile AS owner_mobile, o.email AS owner_email,
                    o.street AS owner_street, o.house_number AS owner_house_number,
                    o.postal_code AS owner_postal_code, o.city AS owner_city
                FROM tp_patients p LEFT JOIN tp_owners o ON o.id = p.owner_id WHERE p.id = ? LIMIT 1';
            $st = $pdo->prepare($sql);
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) api_error('Patient nicht gefunden', 404);
            api_success(['patient' => $row, 'items' => [$row], 'count' => 1]);
        }

        case 'create_patient': {
            $data = payload($json);
            $name = trim((string)($data['name'] ?? ''));
            $species = trim((string)($data['species'] ?? ''));
            if ($name === '' || $species === '') api_error('Name und Tierart sind erforderlich', 400);

            $st = $pdo->prepare("INSERT INTO tp_patients
                (owner_id, name, species, breed, birth_date, gender, weight, microchip, color, notes, is_active, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, {$nowExpr}, {$nowExpr})");
            $st->execute([
                ((int)($data['owner_id'] ?? 0)) ?: null,
                $name,
                $species,
                trim((string)($data['breed'] ?? '')),
                trim((string)($data['birth_date'] ?? '')) ?: null,
                trim((string)($data['gender'] ?? 'unknown')),
                ((string)($data['weight'] ?? '') !== '') ? $data['weight'] : null,
                trim((string)($data['microchip'] ?? '')),
                trim((string)($data['color'] ?? '')),
                trim((string)($data['notes'] ?? '')),
            ]);
            $id = (int)$pdo->lastInsertId();

            $upload = save_upload('patient_image', 'patients') ?? save_upload('image', 'patients') ?? save_upload('file', 'patients');
            $imageUrl = null;
            if ($upload) {
                $imageUrl = normalize_path($upload['file_path']);
                $u = $pdo->prepare("UPDATE tp_patients SET image = ?, updated_at = {$nowExpr} WHERE id = ?");
                $u->execute([$imageUrl, $id]);
            }

            api_success(['id' => $id, 'patient_id' => $id, 'image_url' => $imageUrl]);
        }

        case 'update_patient': {
            $data = payload($json);
            $id = (int)($data['id'] ?? $_GET['id'] ?? 0);
            if ($id <= 0) api_error('Ungültige ID', 400);

            $name = trim((string)($data['name'] ?? ''));
            $species = trim((string)($data['species'] ?? ''));
            if ($name === '' || $species === '') api_error('Name und Tierart sind erforderlich', 400);

            $st = $pdo->prepare("UPDATE tp_patients
                SET owner_id=?, name=?, species=?, breed=?, birth_date=?, gender=?, weight=?, microchip=?, color=?, notes=?, updated_at={$nowExpr}
                WHERE id=?");
            $st->execute([
                ((int)($data['owner_id'] ?? 0)) ?: null,
                $name,
                $species,
                trim((string)($data['breed'] ?? '')),
                trim((string)($data['birth_date'] ?? '')) ?: null,
                trim((string)($data['gender'] ?? 'unknown')),
                ((string)($data['weight'] ?? '') !== '') ? $data['weight'] : null,
                trim((string)($data['microchip'] ?? '')),
                trim((string)($data['color'] ?? '')),
                trim((string)($data['notes'] ?? '')),
                $id,
            ]);

            $upload = save_upload('patient_image', 'patients') ?? save_upload('image', 'patients') ?? save_upload('file', 'patients');
            $imageUrl = null;
            if ($upload) {
                $imageUrl = normalize_path($upload['file_path']);
                $u = $pdo->prepare("UPDATE tp_patients SET image = ?, updated_at = {$nowExpr} WHERE id = ?");
                $u->execute([$imageUrl, $id]);
            }

            api_success(['id' => $id, 'patient_id' => $id, 'image_url' => $imageUrl]);
        }

        case 'delete_patient': {
            $data = payload($json);
            $id = (int)($data['id'] ?? $_GET['id'] ?? 0);
            if ($id <= 0) api_error('Ungültige ID', 400);
            $st = $pdo->prepare('DELETE FROM tp_patients WHERE id = ?');
            $st->execute([$id]);
            api_success(['id' => $id]);
        }

        case 'list_owners': {
            $st = $pdo->query('SELECT * FROM tp_owners ORDER BY last_name, first_name');
            $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            api_success(['items' => $items, 'count' => count($items)]);
        }

        case 'upload_patient_image': {
            $data = payload($json);
            $patientId = (int)($data['patient_id'] ?? $data['id'] ?? $_GET['patient_id'] ?? 0);
            if ($patientId <= 0) api_error('Patient ID ist erforderlich', 400);

            $upload = save_upload('patient_image', 'patients') ?? save_upload('image', 'patients') ?? save_upload('file', 'patients');
            if (!$upload) api_error('Kein Bild hochgeladen', 400);

            $path = normalize_path($upload['file_path']);
            $u = $pdo->prepare("UPDATE tp_patients SET image = ?, updated_at = {$nowExpr} WHERE id = ?");
            $u->execute([$path, $patientId]);
            api_success(['patient_id' => $patientId, 'image_url' => $path, 'image' => $path]);
        }

        case 'delete_patient_image': {
            $data = payload($json);
            $patientId = (int)($data['patient_id'] ?? $data['id'] ?? 0);
            if ($patientId <= 0) api_error('Patient ID ist erforderlich', 400);
            $u = $pdo->prepare("UPDATE tp_patients SET image = NULL, updated_at = {$nowExpr} WHERE id = ?");
            $u->execute([$patientId]);
            api_success(['patient_id' => $patientId]);
        }

        case 'get_timeline': {
            $patientId = (int)($_GET['patient_id'] ?? $json['patient_id'] ?? 0);
            if ($patientId <= 0) api_error('Patient ID ist erforderlich', 400);
            $items = get_timeline($pdo, $patientId);
            api_success(['items' => $items, 'count' => count($items)]);
        }

        // keep frontend stable for optional advanced actions
        case 'create_entry':
        case 'update_entry':
        case 'delete_entry':
        case 'upload_entry_files':
        case 'get_entry_files':
        case 'delete_entry_file': {
            api_success(['items' => [], 'count' => 0]);
        }

        default:
            api_error('Unbekannte Aktion: ' . $action, 400);
    }
} catch (\Throwable $e) {
    api_error('Patienten-API Fehler: ' . $e->getMessage(), 500);
}
