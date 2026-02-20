<?php
/**
 * Tierphysio Manager 2.0
 * Appointments API Endpoint - Unified JSON Response Format
 *
 * Robust-Fix:
 * - Schema-Discovery für tp_appointments (existierende Spalten + ENUM-Werte)
 * - INSERT/UPDATE nur mit existierenden Spalten (keine Unknown column Fehler)
 * - ENUM Validierung für status/type (keine "Incorrect value for enum" Fehler)
 * - FK-Fallback therapist_id + patient_id Existence Checks
 * - LIST/GET: NULL-Status => pending (für Wartend)
 * - Optional: Debug-Fehlerausgabe via ?debug=1 oder APP_DEBUG env
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

const TP_HOURLY_RATE_EUR = 75.0;

// Get action from request
$action = $_GET['action'] ?? 'list';

/**
 * Debug mode (ONLY use locally!)
 * - set env APP_DEBUG=1 OR call endpoint with ?debug=1
 */
function is_debug(): bool
{
    if (isset($_GET['debug']) && (string)$_GET['debug'] === '1') return true;
    $env = getenv('APP_DEBUG');
    return $env !== false && in_array(strtolower((string)$env), ['1','true','yes','on'], true);
}

/**
 * Reads JSON body (safe)
 */
function read_json_body_safe(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Unified input reader (POST form OR JSON)
 */
function read_input(): array
{
    $input = $_POST;
    if (empty($input)) {
        $input = read_json_body_safe();
    }
    return is_array($input) ? $input : [];
}

/**
 * Normalize status safely.
 * Default used for CREATE/UPDATE: scheduled
 * Default used for LIST/GET (NULL): pending
 */
function normalize_status($status, string $default = 'scheduled'): string
{
    $s = strtolower(trim((string)$status));
    if ($s === '' || $s === 'null' || $s === 'undefined') return $default;

    $allowed = ['scheduled', 'confirmed', 'pending', 'cancelled', 'completed'];
    return in_array($s, $allowed, true) ? $s : $default;
}

/**
 * Normalize type safely.
 * Default: followup (compat)
 */
function normalize_type($type, string $default = 'followup'): string
{
    $t = strtolower(trim((string)$type));
    if ($t === '' || $t === 'null' || $t === 'undefined') return $default;

    $allowed = ['treatment', 'initial', 'followup', 'emergency', 'massage'];
    return in_array($t, $allowed, true) ? $t : $default;
}

/**
 * Normalize time string to HH:MM:SS when possible
 */
function normalize_time_hms(string $t): string
{
    $t = trim($t);
    if ($t === '') return '';
    if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $t)) return $t;
    if (preg_match('/^\d{2}:\d{2}$/', $t)) return $t . ':00';
    return $t;
}

/**
 * Compute end time
 */
function compute_end_time(string $appointment_date, string $start_time_hms, int $duration_minutes): string
{
    $start = new DateTime($appointment_date . ' ' . $start_time_hms);
    $end = clone $start;
    $end->add(new DateInterval("PT{$duration_minutes}M"));
    return $end->format('H:i:s');
}

/**
 * Schema discovery: columns + nullable + enum values
 */
function get_table_schema(PDO $pdo, string $table): array
{
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];

    $sql = "SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_TYPE
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :tbl";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':tbl' => $table]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $columns = [];
    $nullable = [];
    $enums = [];

    foreach ($rows as $r) {
        $name = (string)$r['COLUMN_NAME'];
        $columns[$name] = true;
        $nullable[$name] = ((string)$r['IS_NULLABLE'] === 'YES');

        $ctype = (string)($r['COLUMN_TYPE'] ?? '');
        if (stripos($ctype, 'enum(') === 0) {
            // parse enum('a','b',...)
            $inside = substr($ctype, 5, -1); // remove enum( and )
            $vals = [];
            // split by comma but handle quotes
            preg_match_all("/'((?:\\\\'|[^'])*)'/", $inside, $m);
            if (!empty($m[1])) {
                foreach ($m[1] as $v) {
                    $vals[] = str_replace("\\'", "'", $v);
                }
            }
            $enums[$name] = $vals;
        }
    }

    $cache[$table] = [
        'columns' => $columns,
        'nullable' => $nullable,
        'enums' => $enums
    ];
    return $cache[$table];
}

/**
 * Pick a valid enum value if column is enum.
 * If not enum or list empty: return as-is.
 */
function enforce_enum(PDO $pdo, string $table, string $column, string $value, string $fallback): string
{
    $schema = get_table_schema($pdo, $table);
    $enums = $schema['enums'][$column] ?? null;
    if (!$enums || !is_array($enums) || count($enums) === 0) {
        return $value;
    }
    if (in_array($value, $enums, true)) return $value;
    if (in_array($fallback, $enums, true)) return $fallback;
    return $enums[0]; // last resort: first enum option
}

/**
 * Verify existence in a table
 */
function record_exists(PDO $pdo, string $table, int $id): bool
{
    if ($id <= 0) return false;
    $stmt = $pdo->prepare("SELECT 1 FROM `$table` WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Get fallback therapist id:
 * - prefer provided if exists
 * - else try session user id
 * - else first user in tp_users
 */
function resolve_therapist_id(PDO $pdo, array $input, bool $allowNull): ?int
{
    $provided = (int)($input['therapist_id'] ?? 0);
    if ($provided > 0 && record_exists($pdo, 'tp_users', $provided)) {
        return $provided;
    }

    $sessionId = 0;
    if (isset($_SESSION)) {
        $sessionId = (int)($_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? 0);
    }
    if ($sessionId > 0 && record_exists($pdo, 'tp_users', $sessionId)) {
        return $sessionId;
    }

    $stmt = $pdo->query("SELECT id FROM tp_users ORDER BY id ASC LIMIT 1");
    $first = (int)($stmt->fetchColumn() ?: 0);
    if ($first > 0) return $first;

    return $allowNull ? null : 1;
}

/**
 * Apply runtime normalization on appointment row:
 * - status: NULL => pending
 * - duration: default 30
 * - hourly_rate + price_eur (75€/h)
 */
function normalize_row(array $row): array
{
    $status = $row['status'] ?? null;
    $status = normalize_status($status, 'pending'); // LIST/GET: NULL -> pending
    $row['status'] = $status;

    $duration = isset($row['duration']) ? (int)$row['duration'] : 30;
    if ($duration <= 0) $duration = 30;
    $row['duration'] = $duration;

    $row['hourly_rate'] = TP_HOURLY_RATE_EUR;
    $row['price_eur'] = round(($duration / 60) * TP_HOURLY_RATE_EUR, 2);

    return $row;
}

// Special case: integrity check
if ($action === 'integrity' || isset($_GET['integrity_check'])) {
    try {
        $pdo = get_pdo();

        // Best effort: tp_notes
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS tp_notes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                patient_id INT NOT NULL,
                user_id INT DEFAULT 1,
                note_type VARCHAR(50) DEFAULT 'general',
                content TEXT NOT NULL,
                is_important TINYINT(1) DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_patient (patient_id),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Exception $e) {
            // ignore
        }

        $tables = [
            'tp_users','tp_owners','tp_patients',
            'tp_appointments','tp_treatments',
            'tp_invoices','tp_notes'
        ];

        $stats = [];
        foreach ($tables as $tbl) {
            try {
                $stmt = $pdo->query("SELECT COUNT(*) AS cnt FROM `$tbl`");
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $stats[$tbl] = intval($row['cnt'] ?? 0);
            } catch (Exception $e) {
                $stats[$tbl] = 0;
            }
        }

        api_success([
            'items' => [[
                'check' => 'db',
                'ok' => true,
                'checked_tables' => count($tables),
                'table_stats' => $stats
            ]]
        ]);
    } catch (Throwable $e) {
        api_error('Integritätsprüfung fehlgeschlagen: ' . $e->getMessage(), 500);
    }
    exit;
}

try {
    $pdo = get_pdo();

    // Discover schema once (for create/update)
    $schema = get_table_schema($pdo, 'tp_appointments');
    $cols = $schema['columns'];
    $nullable = $schema['nullable'];

    switch ($action) {

        case 'list': {
            $date_from    = (string)($_GET['date_from'] ?? date('Y-m-d'));
            $date_to      = (string)($_GET['date_to'] ?? date('Y-m-d', strtotime('+30 days')));
            $patient_id   = (int)($_GET['patient_id'] ?? 0);
            $therapist_id = (int)($_GET['therapist_id'] ?? 0);
            $status       = (string)($_GET['status'] ?? '');
            $limit        = (int)($_GET['limit'] ?? 100);
            $offset       = (int)($_GET['offset'] ?? 0);

            if ($limit < 1) $limit = 1;
            if ($limit > 500) $limit = 500;
            if ($offset < 0) $offset = 0;

            $statusFilter = '';
            if ($status !== '') {
                $statusFilter = normalize_status($status, 'scheduled');
            }

            $sql = "SELECT a.*,
                        p.name as patient_name,
                        p.species as patient_species,
                        o.first_name as owner_first_name,
                        o.last_name as owner_last_name,
                        CONCAT_WS(' ', o.first_name, o.last_name) AS owner_full_name,
                        o.phone as owner_phone,
                        u.first_name as therapist_first_name,
                        u.last_name as therapist_last_name
                    FROM tp_appointments a
                    LEFT JOIN tp_patients p ON a.patient_id = p.id
                    LEFT JOIN tp_owners o ON p.owner_id = o.id
                    LEFT JOIN tp_users u ON a.therapist_id = u.id
                    WHERE a.appointment_date BETWEEN :date_from AND :date_to";

            $params = [
                ':date_from' => $date_from,
                ':date_to'   => $date_to
            ];

            if ($patient_id > 0) {
                $sql .= " AND a.patient_id = :patient_id";
                $params[':patient_id'] = $patient_id;
            }

            if ($therapist_id > 0) {
                $sql .= " AND a.therapist_id = :therapist_id";
                $params[':therapist_id'] = $therapist_id;
            }

            if ($statusFilter !== '') {
                $sql .= " AND COALESCE(a.status, 'pending') = :status";
                $params[':status'] = $statusFilter;
            }

            $sql .= " ORDER BY a.appointment_date, a.start_time LIMIT :limit OFFSET :offset";

            $stmt = $pdo->prepare($sql);

            foreach ($params as $key => $value) {
                if (is_int($value)) $stmt->bindValue($key, $value, PDO::PARAM_INT);
                else $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

            $stmt->execute();

            $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Status NULL => pending + price
            $appointments = array_map(function ($row) {
                // if DB returned NULL status, normalize_row will set pending
                return normalize_row($row);
            }, $appointments);

            api_success(['items' => $appointments, 'count' => count($appointments)]);
            break;
        }

        case 'get': {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) api_error('Appointment ID fehlt', 400);

            $stmt = $pdo->prepare("
                SELECT a.*,
                    p.name as patient_name,
                    p.species as patient_species,
                    o.first_name as owner_first_name,
                    o.last_name as owner_last_name,
                    CONCAT_WS(' ', o.first_name, o.last_name) AS owner_full_name,
                    o.phone as owner_phone,
                    o.email as owner_email,
                    u.first_name as therapist_first_name,
                    u.last_name as therapist_last_name
                FROM tp_appointments a
                LEFT JOIN tp_patients p ON a.patient_id = p.id
                LEFT JOIN tp_owners o ON p.owner_id = o.id
                LEFT JOIN tp_users u ON a.therapist_id = u.id
                WHERE a.id = ?
            ");
            $stmt->execute([$id]);
            $appointment = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$appointment) api_error('Appointment nicht gefunden', 404);

            $appointment = normalize_row($appointment);
            api_success(['items' => [$appointment]]);
            break;
        }

        case 'create': {
            $input = read_input();

            $patient_id        = (int)($input['patient_id'] ?? 0);
            $appointment_date  = (string)($input['appointment_date'] ?? '');
            $start_time        = (string)($input['start_time'] ?? '');
            $duration          = (int)($input['duration'] ?? 30);

            if ($patient_id <= 0 || $appointment_date === '' || $start_time === '') {
                api_error('Pflichtfelder fehlen', 400);
            }
            if (!record_exists($pdo, 'tp_patients', $patient_id)) {
                api_error('Patient existiert nicht (FK)', 400);
            }

            if ($duration < 5) $duration = 5;
            if ($duration > 480) $duration = 480;

            $start_time_hms = normalize_time_hms($start_time);
            if ($start_time_hms === '') api_error('Startzeit ungültig', 400);

            $end_time = compute_end_time($appointment_date, $start_time_hms, $duration);

            // status/type normalize + enforce enum if column is enum
            $status = array_key_exists('status', $input) ? normalize_status($input['status'], 'scheduled') : 'scheduled';
            $type   = array_key_exists('type', $input) ? normalize_type($input['type'], 'followup') : 'followup';

            if (isset($cols['status'])) $status = enforce_enum($pdo, 'tp_appointments', 'status', $status, 'scheduled');
            if (isset($cols['type']))   $type   = enforce_enum($pdo, 'tp_appointments', 'type',   $type,   'followup');

            // therapist_id: resolve to existing
            $allowTherapistNull = isset($nullable['therapist_id']) ? (bool)$nullable['therapist_id'] : false;
            $therapist_id = resolve_therapist_id($pdo, $input, $allowTherapistNull);
            if ($therapist_id !== null && !record_exists($pdo, 'tp_users', (int)$therapist_id)) {
                if ($allowTherapistNull) $therapist_id = null;
                else api_error('Therapeut existiert nicht (FK)', 400);
            }

            // Build dynamic INSERT
            $fields = [];
            $placeholders = [];
            $values = [];

            // required
            if (isset($cols['patient_id'])) {
                $fields[] = 'patient_id';
                $placeholders[] = '?';
                $values[] = $patient_id;
            }
            if (isset($cols['therapist_id'])) {
                $fields[] = 'therapist_id';
                $placeholders[] = '?';
                $values[] = $therapist_id;
            }
            if (isset($cols['appointment_date'])) {
                $fields[] = 'appointment_date';
                $placeholders[] = '?';
                $values[] = $appointment_date;
            }
            if (isset($cols['start_time'])) {
                $fields[] = 'start_time';
                $placeholders[] = '?';
                $values[] = $start_time_hms;
            }

            // optional
            if (isset($cols['end_time'])) {
                $fields[] = 'end_time';
                $placeholders[] = '?';
                $values[] = $end_time;
            }
            if (isset($cols['duration'])) {
                $fields[] = 'duration';
                $placeholders[] = '?';
                $values[] = $duration;
            }
            if (isset($cols['type'])) {
                $fields[] = 'type';
                $placeholders[] = '?';
                $values[] = $type;
            }
            if (isset($cols['status'])) {
                $fields[] = 'status';
                $placeholders[] = '?';
                $values[] = $status;
            }
            if (isset($cols['treatment_focus'])) {
                $fields[] = 'treatment_focus';
                $placeholders[] = '?';
                $values[] = ($input['treatment_focus'] ?? null);
            }
            if (isset($cols['notes'])) {
                $fields[] = 'notes';
                $placeholders[] = '?';
                $values[] = ($input['notes'] ?? null);
            }

            // timestamps (optional)
            if (isset($cols['created_at'])) {
                $fields[] = 'created_at';
                $placeholders[] = 'NOW()';
            }

            if (count($fields) < 4) {
                api_error('Schema von tp_appointments unerwartet (Pflichtspalten fehlen)', 500);
            }

            $sql = "INSERT INTO tp_appointments (" . implode(', ', $fields) . ")
                    VALUES (" . implode(', ', $placeholders) . ")";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($values);

            $id = (int)$pdo->lastInsertId();

            // return created
            $stmt = $pdo->prepare("SELECT a.* FROM tp_appointments a WHERE a.id = ?");
            $stmt->execute([$id]);
            $appointment = $stmt->fetch(PDO::FETCH_ASSOC);

            $appointment = $appointment ? normalize_row($appointment) : normalize_row(['id' => $id, 'duration' => $duration, 'status' => $status]);
            api_success(['items' => [$appointment]]);
            break;
        }

        case 'update': {
            $input = read_input();

            $id = (int)($input['id'] ?? 0);
            if ($id <= 0) api_error('Appointment ID fehlt', 400);

            $stmt = $pdo->prepare("SELECT id FROM tp_appointments WHERE id = ?");
            $stmt->execute([$id]);
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) api_error('Appointment nicht gefunden', 404);

            $appointment_date = (string)($input['appointment_date'] ?? '');
            $start_time       = (string)($input['start_time'] ?? '');
            $duration         = (int)($input['duration'] ?? 30);

            if ($appointment_date === '' || $start_time === '') api_error('Pflichtfelder fehlen', 400);

            if ($duration < 5) $duration = 5;
            if ($duration > 480) $duration = 480;

            $start_time_hms = normalize_time_hms($start_time);
            if ($start_time_hms === '') api_error('Startzeit ungültig', 400);

            $end_time = compute_end_time($appointment_date, $start_time_hms, $duration);

            $status = array_key_exists('status', $input) ? normalize_status($input['status'], 'scheduled') : 'scheduled';
            $type   = array_key_exists('type', $input) ? normalize_type($input['type'], 'followup') : 'followup';

            if (isset($cols['status'])) $status = enforce_enum($pdo, 'tp_appointments', 'status', $status, 'scheduled');
            if (isset($cols['type']))   $type   = enforce_enum($pdo, 'tp_appointments', 'type',   $type,   'followup');

            $allowTherapistNull = isset($nullable['therapist_id']) ? (bool)$nullable['therapist_id'] : false;
            $therapist_id = resolve_therapist_id($pdo, $input, $allowTherapistNull);
            if ($therapist_id !== null && !record_exists($pdo, 'tp_users', (int)$therapist_id)) {
                if ($allowTherapistNull) $therapist_id = null;
                else api_error('Therapeut existiert nicht (FK)', 400);
            }

            $sets = [];
            $values = [];

            if (isset($cols['therapist_id'])) { $sets[] = "therapist_id = ?"; $values[] = $therapist_id; }
            if (isset($cols['appointment_date'])) { $sets[] = "appointment_date = ?"; $values[] = $appointment_date; }
            if (isset($cols['start_time'])) { $sets[] = "start_time = ?"; $values[] = $start_time_hms; }
            if (isset($cols['end_time'])) { $sets[] = "end_time = ?"; $values[] = $end_time; }
            if (isset($cols['duration'])) { $sets[] = "duration = ?"; $values[] = $duration; }
            if (isset($cols['type'])) { $sets[] = "type = ?"; $values[] = $type; }
            if (isset($cols['status'])) { $sets[] = "status = ?"; $values[] = $status; }
            if (isset($cols['treatment_focus'])) { $sets[] = "treatment_focus = ?"; $values[] = ($input['treatment_focus'] ?? null); }
            if (isset($cols['notes'])) { $sets[] = "notes = ?"; $values[] = ($input['notes'] ?? null); }

            if (isset($cols['updated_at'])) {
                $sets[] = "updated_at = NOW()";
            }

            if (count($sets) === 0) api_error('Keine aktualisierbaren Felder gefunden', 500);

            $values[] = $id;

            $sql = "UPDATE tp_appointments SET " . implode(", ", $sets) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($values);

            api_success(['items' => []]);
            break;
        }

        case 'delete': {
            $input = read_input();

            $id = (int)($input['id'] ?? ($_GET['id'] ?? 0));
            if ($id <= 0) api_error('Appointment ID fehlt', 400);

            $stmt = $pdo->prepare("DELETE FROM tp_appointments WHERE id = ?");
            $stmt->execute([$id]);

            api_success(['items' => []]);
            break;
        }

        default:
            api_error("Unbekannte Aktion: " . $action, 400);
    }

} catch (PDOException $e) {
    error_log("Appointments API PDO Error (" . $action . "): " . $e->getMessage());

    // Optional debug output (nur lokal!)
    if (is_debug()) {
        api_error('Datenbankfehler: ' . $e->getMessage(), 500);
    } else {
        api_error('Datenbankfehler aufgetreten', 500);
    }
} catch (Throwable $e) {
    error_log("Appointments API Error (" . $action . "): " . $e->getMessage());
    if (is_debug()) {
        api_error('Serverfehler: ' . $e->getMessage(), 500);
    } else {
        api_error('Serverfehler aufgetreten', 500);
    }
}

exit;