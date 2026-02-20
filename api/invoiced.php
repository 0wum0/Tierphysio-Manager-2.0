<?php
/**
 * Tierphysio Manager 2.0
 * Invoiced API Endpoint - Unified JSON Response Format
 *
 * Unterstützt DB-Schemata:
 * - tp_invoices: total/subtotal ODER total_amount/net_amount (wird automatisch erkannt)
 * - tp_invoice_items: price/total ODER unit_price/total_price (wird automatisch erkannt)
 *
 * Actions:
 * - list, get, create, update, delete, statistics, patients, pdf
 *
 * Rückgabe ist immer JSON (außer action=pdf -> PDF oder HTML-Fallback).
 */

declare(strict_types=1);

// Always JSON (even if something goes wrong before api_* helpers)
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

ob_start();

// --- Fatal/Shutdown handler: returns JSON instead of empty/HTML
register_shutdown_function(function () {
    $err = error_get_last();
    if (!$err) {
        if (ob_get_length() !== false) {
            @ob_end_flush();
        }
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    if (!in_array((int)$err['type'], $fatalTypes, true)) {
        if (ob_get_length() !== false) {
            @ob_end_flush();
        }
        return;
    }

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    header('Content-Type: application/json; charset=utf-8');

    error_log("Invoiced API FATAL: {$err['message']} in {$err['file']}:{$err['line']}");

    echo json_encode([
        'ok' => false,
        'status' => 'error',
        'message' => 'Serverfehler aufgetreten'
    ], JSON_UNESCAPED_UNICODE);

    exit;
});

require_once __DIR__ . '/_bootstrap.php';

// Default JSON header (außer PDF-Action)
function set_json_headers(): void
{
    header('Content-Type: application/json; charset=utf-8');
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

function normalize_invoice_status($status, string $default = 'draft'): string
{
    $s = strtolower(trim((string)$status));
    if ($s === '' || $s === 'null' || $s === 'undefined') {
        return $default;
    }
    $allowed = ['draft', 'sent', 'paid', 'overdue', 'cancelled', 'pending', 'partially_paid', 'open'];
    return in_array($s, $allowed, true) ? $s : $default;
}

function to_float($v): float
{
    if ($v === null) return 0.0;
    if (is_float($v)) return $v;
    if (is_int($v)) return (float)$v;
    $s = str_replace(',', '.', (string)$v);
    return is_numeric($s) ? (float)$s : 0.0;
}

function to_int($v): int
{
    return (int)($v ?? 0);
}

function safe_str($v): string
{
    return trim((string)($v ?? ''));
}

/**
 * Detect schema columns for tp_invoices and tp_invoice_items
 */
function get_schema(PDO $pdo): array
{
    static $cache = null;
    if (is_array($cache)) return $cache;

    $invCols = [];
    try {
        $r = $pdo->query("SHOW COLUMNS FROM tp_invoices");
        $invCols = $r ? $r->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        $invCols = [];
    }

    $itemCols = [];
    try {
        $r2 = $pdo->query("SHOW COLUMNS FROM tp_invoice_items");
        $itemCols = $r2 ? $r2->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        $itemCols = [];
    }

    $invNames = [];
    foreach ($invCols as $c) $invNames[] = (string)($c['Field'] ?? '');
    $itemNames = [];
    foreach ($itemCols as $c) $itemNames[] = (string)($c['Field'] ?? '');

    $has = function(array $list, string $name): bool {
        return in_array($name, $list, true);
    };

    // Invoices mapping
    $netCol   = $has($invNames, 'net_amount') ? 'net_amount' : ($has($invNames, 'subtotal') ? 'subtotal' : null);
    $taxCol   = $has($invNames, 'tax_amount') ? 'tax_amount' : null;
    $totalCol = $has($invNames, 'total_amount') ? 'total_amount' : ($has($invNames, 'total') ? 'total' : null);

    $cache = [
        'invoices' => [
            'net'   => $netCol,
            'tax'   => $taxCol,
            'total' => $totalCol,
            'has_discount_amount'  => $has($invNames, 'discount_amount'),
            'has_discount_percent' => $has($invNames, 'discount_percent'),
            'has_tax_rate'         => $has($invNames, 'tax_rate'),
            'has_owner_id'         => $has($invNames, 'owner_id'),
            'has_patient_id'       => $has($invNames, 'patient_id'),
            'has_payment_date'     => $has($invNames, 'payment_date'),
            'has_payment_method'   => $has($invNames, 'payment_method'),
            'has_notes'            => $has($invNames, 'notes'),
            'has_updated_at'       => $has($invNames, 'updated_at'),
            'has_created_at'       => $has($invNames, 'created_at'),
            'has_invoice_number'   => $has($invNames, 'invoice_number'),
            'has_due_date'         => $has($invNames, 'due_date'),
            'has_invoice_date'     => $has($invNames, 'invoice_date'),
            'has_status'           => $has($invNames, 'status'),
        ],
        'items' => [
            'price' => $has($itemNames, 'unit_price') ? 'unit_price' : ($has($itemNames, 'price') ? 'price' : null),
            'total' => $has($itemNames, 'total_price') ? 'total_price' : ($has($itemNames, 'total') ? 'total' : null),
            'has_tax_rate'         => $has($itemNames, 'tax_rate'),
            'has_discount_percent' => $has($itemNames, 'discount_percent'),
            'has_unit'             => $has($itemNames, 'unit'),
            'has_treatment_id'     => $has($itemNames, 'treatment_id'),
            'has_position'         => $has($itemNames, 'position'),
            'has_created_at'       => $has($itemNames, 'created_at'),
        ]
    ];

    return $cache;
}

/**
 * Generate invoice number like "2025-0001"
 */
function generate_invoice_number(PDO $pdo): string
{
    $year = (int)date('Y');

    $stmt = $pdo->prepare("
        SELECT invoice_number
        FROM tp_invoices
        WHERE invoice_number LIKE :prefix
        ORDER BY invoice_number DESC
        LIMIT 1
    ");
    $stmt->bindValue(':prefix', $year . '-%');
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $next = 1;
    if ($row && !empty($row['invoice_number'])) {
        $parts = explode('-', (string)$row['invoice_number']);
        $lastSeq = (int)($parts[1] ?? 0);
        if ($lastSeq > 0) $next = $lastSeq + 1;
    }

    return sprintf('%d-%04d', $year, $next);
}

/**
 * Join owners via COALESCE(i.owner_id, p.owner_id)
 */
function invoice_owner_join_sql(): string
{
    return "LEFT JOIN tp_owners o ON o.id = COALESCE(i.owner_id, p.owner_id)";
}

/**
 * Normalize invoice row for frontend:
 * - ensures net_amount/tax_amount/total_amount exist
 */
function normalize_invoice_row(array $row, array $schema): array
{
    $inv = $row;

    $netKey   = $schema['invoices']['net'];
    $taxKey   = $schema['invoices']['tax'];
    $totalKey = $schema['invoices']['total'];

    $netVal   = $netKey ? to_float($row[$netKey] ?? 0) : 0.0;
    $taxVal   = $taxKey ? to_float($row[$taxKey] ?? 0) : 0.0;
    $totalVal = $totalKey ? to_float($row[$totalKey] ?? 0) : ($netVal + $taxVal);

    $inv['net_amount']   = $netVal;
    $inv['tax_amount']   = $taxVal;
    $inv['total_amount'] = $totalVal;

    return $inv;
}

/**
 * Normalize item row for frontend:
 * - ensures unit_price/total_price fields exist (even if DB uses price/total)
 */
function normalize_item_row(array $row, array $schema): array
{
    $it = $row;

    $priceKey = $schema['items']['price'];
    $totalKey = $schema['items']['total'];

    $unitPrice = $priceKey ? to_float($row[$priceKey] ?? 0) : 0.0;
    $total     = $totalKey ? to_float($row[$totalKey] ?? 0) : $unitPrice * to_float($row['quantity'] ?? 0);

    $it['unit_price']  = $unitPrice;
    $it['total_price'] = $total;

    $it['quantity'] = to_float($row['quantity'] ?? 0);
    if (!empty($row['tax_rate'])) $it['tax_rate'] = to_float($row['tax_rate']);
    if (!empty($row['discount_percent'])) $it['discount_percent'] = to_float($row['discount_percent']);

    return $it;
}

/**
 * Very small HTML escape helper
 */
function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// -----------------------------
// Action routing
// -----------------------------

$action = $_GET['action'] ?? 'list';

try {
    $pdo = get_pdo();
    $schema = get_schema($pdo);

    // PDF action must not set JSON header
    if ($action !== 'pdf') {
        set_json_headers();
    }

    switch ($action) {

        /**
         * ✅ PATIENT LIST (for modal dropdown)
         * GET /api/invoiced.php?action=patients
         */
        case 'patients': {
            $limit  = (int)($_GET['limit'] ?? 500);
            if ($limit < 1) $limit = 1;
            if ($limit > 2000) $limit = 2000;

            $sql = "
                SELECT
                    p.id,
                    p.name AS patient_name,
                    p.species,
                    p.patient_number,
                    o.id AS owner_id,
                    o.customer_number,
                    o.first_name AS owner_first_name,
                    o.last_name AS owner_last_name,
                    CONCAT_WS(' ', o.first_name, o.last_name) AS owner_full_name
                FROM tp_patients p
                LEFT JOIN tp_owners o ON p.owner_id = o.id
                ORDER BY o.last_name, o.first_name, p.name
                LIMIT :limit
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();

            $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            api_success([
                'items' => $items,
                'count' => count($items)
            ]);
            break;
        }

        /**
         * LIST invoices
         * Unterstützt Parameter:
         * - date_from/date_to (alt)
         * - from/to (neu, dein JS)
         */
        case 'list': {
            $date_from  = (string)($_GET['date_from'] ?? ($_GET['from'] ?? date('Y-m-d', strtotime('-30 days'))));
            $date_to    = (string)($_GET['date_to'] ?? ($_GET['to'] ?? date('Y-m-d')));
            $status     = (string)($_GET['status'] ?? '');
            $patient_id = (int)($_GET['patient_id'] ?? 0);
            $limit      = (int)($_GET['limit'] ?? 200);
            $offset     = (int)($_GET['offset'] ?? 0);

            if ($limit < 1) $limit = 1;
            if ($limit > 500) $limit = 500;
            if ($offset < 0) $offset = 0;

            $sql = "
                SELECT
                    i.*,
                    p.name AS patient_name,
                    p.patient_number,
                    p.species,
                    CONCAT_WS(' ', o.first_name, o.last_name) AS owner_full_name,
                    o.customer_number,
                    o.email AS owner_email,
                    o.phone AS owner_phone
                FROM tp_invoices i
                LEFT JOIN tp_patients p ON i.patient_id = p.id
                " . invoice_owner_join_sql() . "
                WHERE i.invoice_date BETWEEN :date_from AND :date_to
            ";

            $params = [
                ':date_from' => $date_from,
                ':date_to'   => $date_to
            ];

            if ($status !== '') {
                $sql .= " AND i.status = :status";
                $params[':status'] = normalize_invoice_status($status, 'draft');
            }

            if ($patient_id > 0) {
                $sql .= " AND i.patient_id = :patient_id";
                $params[':patient_id'] = $patient_id;
            }

            $sql .= " ORDER BY i.invoice_date DESC, i.id DESC LIMIT :limit OFFSET :offset";

            $stmt = $pdo->prepare($sql);
            foreach ($params as $k => $v) {
                if (is_int($v)) $stmt->bindValue($k, $v, PDO::PARAM_INT);
                else $stmt->bindValue($k, $v);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

            $stmt->execute();

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $items = [];
            foreach ($rows as $r) {
                $items[] = normalize_invoice_row($r, $schema);
            }

            api_success([
                'items' => $items,
                'count' => count($items)
            ]);
            break;
        }

        /**
         * GET invoice by id (incl. items)
         */
        case 'get': {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) {
                api_error('Rechnungs-ID fehlt', 400);
            }

            $stmt = $pdo->prepare("
                SELECT
                    i.*,
                    p.name AS patient_name,
                    p.patient_number,
                    p.species,
                    o.salutation,
                    o.first_name AS owner_first_name,
                    o.last_name AS owner_last_name,
                    o.company AS owner_company,
                    o.street AS owner_street,
                    o.house_number AS owner_house_number,
                    o.postal_code AS owner_postal_code,
                    o.city AS owner_city,
                    o.email AS owner_email,
                    o.phone AS owner_phone,
                    CONCAT_WS(' ', o.first_name, o.last_name) AS owner_full_name
                FROM tp_invoices i
                LEFT JOIN tp_patients p ON i.patient_id = p.id
                " . invoice_owner_join_sql() . "
                WHERE i.id = ?
                LIMIT 1
            ");
            $stmt->execute([$id]);
            $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$invoice) {
                api_error('Rechnung nicht gefunden', 404);
            }

            // items
            $stmt = $pdo->prepare("
                SELECT *
                FROM tp_invoice_items
                WHERE invoice_id = ?
                ORDER BY position, id
            ");
            $stmt->execute([$id]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $items = [];
            foreach ($rows as $it) {
                $items[] = normalize_item_row($it, $schema);
            }

            $invoice = normalize_invoice_row($invoice, $schema);
            $invoice['items'] = $items;

            api_success(['items' => [$invoice]]);
            break;
        }

        /**
         * CREATE invoice + items (transaction)
         * POST JSON:
         * {
         *   patient_id, invoice_date, due_date, status, payment_method, payment_date, notes,
         *   items: [{description, quantity, unit_price, tax_rate}, ...]
         * }
         */
        case 'create': {
            $input = $_POST;
            if (empty($input)) {
                $input = read_json_body_safe();
            }

            $patient_id   = (int)($input['patient_id'] ?? 0);
            $invoice_date = (string)($input['invoice_date'] ?? date('Y-m-d'));
            $due_date     = (string)($input['due_date'] ?? date('Y-m-d', strtotime('+14 days')));
            $status       = normalize_invoice_status($input['status'] ?? 'draft', 'draft');
            $notes        = $input['notes'] ?? null;

            $items = $input['items'] ?? [];
            if (!is_array($items)) $items = [];

            if ($patient_id <= 0) {
                api_error('Patient ID fehlt', 400);
            }
            if (count($items) < 1) {
                api_error('Mindestens eine Position erforderlich', 400);
            }

            // Ensure patient exists + get owner_id
            $stmt = $pdo->prepare("SELECT id, owner_id FROM tp_patients WHERE id = ?");
            $stmt->execute([$patient_id]);
            $pRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$pRow) {
                api_error('Patient nicht gefunden', 404);
            }
            $owner_id = (int)($pRow['owner_id'] ?? 0);

            if (($schema['invoices']['has_owner_id'] ?? false) && $owner_id <= 0) {
                api_error('Patient hat keinen Besitzer (owner_id).', 400);
            }

            $pdo->beginTransaction();

            try {
                $invoice_number = ($schema['invoices']['has_invoice_number'] ?? false)
                    ? generate_invoice_number($pdo)
                    : '';

                // Totals
                $subtotal = 0.0;   // aka net
                $tax_amount = 0.0;
                $discount_percent = to_float($input['discount_percent'] ?? 0);
                $discount_amount  = to_float($input['discount_amount'] ?? 0);

                // Validate items + compute
                $cleanItems = [];
                foreach ($items as $idx => $it) {
                    if (!is_array($it)) continue;

                    $desc = trim((string)($it['description'] ?? 'Position'));
                    if ($desc === '') $desc = 'Position';

                    $qty  = max(0.01, to_float($it['quantity'] ?? 1));
                    $unit = max(0.0, to_float($it['unit_price'] ?? ($it['price'] ?? 0)));
                    $rate = max(0.0, to_float($it['tax_rate'] ?? 0));

                    $lineNet = $qty * $unit;
                    $lineTax = $lineNet * ($rate / 100.0);

                    $subtotal += $lineNet;
                    $tax_amount += $lineTax;

                    $cleanItems[] = [
                        'description' => $desc,
                        'quantity' => $qty,
                        'unit_price' => $unit,
                        'tax_rate' => $rate,
                        'unit' => safe_str($it['unit'] ?? 'Stück'),
                        'discount_percent' => to_float($it['discount_percent'] ?? 0),
                        'treatment_id' => isset($it['treatment_id']) ? to_int($it['treatment_id']) : null,
                    ];
                }

                if (count($cleanItems) < 1) {
                    api_error('Keine gültigen Positionen übergeben', 400);
                }

                if (($schema['invoices']['has_discount_amount'] ?? false) && $discount_amount <= 0 && $discount_percent > 0) {
                    $discount_amount = ($subtotal + $tax_amount) * ($discount_percent / 100.0);
                }

                $total = ($subtotal + $tax_amount) - $discount_amount;
                if ($total < 0) $total = 0.0;

                // Build dynamic INSERT for tp_invoices
                $cols = [];
                $vals = [];
                $bind = [];

                if ($schema['invoices']['has_invoice_number']) {
                    $cols[] = 'invoice_number';
                    $vals[] = '?';
                    $bind[] = $invoice_number;
                }

                if ($schema['invoices']['has_owner_id']) {
                    $cols[] = 'owner_id';
                    $vals[] = '?';
                    $bind[] = $owner_id;
                }

                if ($schema['invoices']['has_patient_id']) {
                    $cols[] = 'patient_id';
                    $vals[] = '?';
                    $bind[] = $patient_id;
                }

                if ($schema['invoices']['has_invoice_date']) {
                    $cols[] = 'invoice_date';
                    $vals[] = '?';
                    $bind[] = $invoice_date;
                }

                if ($schema['invoices']['has_due_date']) {
                    $cols[] = 'due_date';
                    $vals[] = '?';
                    $bind[] = $due_date;
                }

                // net/subtotal
                if ($schema['invoices']['net']) {
                    $cols[] = $schema['invoices']['net'];
                    $vals[] = '?';
                    $bind[] = $subtotal;
                }

                // tax_amount
                if ($schema['invoices']['tax']) {
                    $cols[] = $schema['invoices']['tax'];
                    $vals[] = '?';
                    $bind[] = $tax_amount;
                }

                // tax_rate (optional) -> if exists, store 0 (because we may have mixed item rates)
                if ($schema['invoices']['has_tax_rate']) {
                    $cols[] = 'tax_rate';
                    $vals[] = '?';
                    $bind[] = to_float($input['tax_rate'] ?? 0);
                }

                if ($schema['invoices']['has_discount_percent']) {
                    $cols[] = 'discount_percent';
                    $vals[] = '?';
                    $bind[] = $discount_percent;
                }

                if ($schema['invoices']['has_discount_amount']) {
                    $cols[] = 'discount_amount';
                    $vals[] = '?';
                    $bind[] = $discount_amount;
                }

                // total/total_amount
                if ($schema['invoices']['total']) {
                    $cols[] = $schema['invoices']['total'];
                    $vals[] = '?';
                    $bind[] = $total;
                }

                if ($schema['invoices']['has_status']) {
                    $cols[] = 'status';
                    $vals[] = '?';
                    $bind[] = $status;
                }

                if ($schema['invoices']['has_payment_method']) {
                    $cols[] = 'payment_method';
                    $vals[] = '?';
                    $bind[] = $input['payment_method'] ?? null;
                }

                if ($schema['invoices']['has_payment_date']) {
                    $cols[] = 'payment_date';
                    $vals[] = '?';
                    $bind[] = $input['payment_date'] ?? null;
                }

                if ($schema['invoices']['has_notes']) {
                    $cols[] = 'notes';
                    $vals[] = '?';
                    $bind[] = $notes;
                }

                // created_at exists? If yes, set NOW() else ignore (DB default might exist)
                if ($schema['invoices']['has_created_at']) {
                    $cols[] = 'created_at';
                    $vals[] = 'NOW()';
                }

                $sqlIns = "INSERT INTO tp_invoices (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";
                $stmtIns = $pdo->prepare($sqlIns);
                $stmtIns->execute($bind);

                $invoice_id = (int)$pdo->lastInsertId();

                // Insert items (dynamic)
                $itemCols = ['invoice_id', 'description', 'quantity'];
                $itemVals = ['?', '?', '?'];

                // position
                if ($schema['items']['has_position']) {
                    $itemCols[] = 'position';
                    $itemVals[] = '?';
                }

                // unit
                if ($schema['items']['has_unit']) {
                    $itemCols[] = 'unit';
                    $itemVals[] = '?';
                }

                // treatment_id
                if ($schema['items']['has_treatment_id']) {
                    $itemCols[] = 'treatment_id';
                    $itemVals[] = '?';
                }

                // price / unit_price
                if ($schema['items']['price']) {
                    $itemCols[] = $schema['items']['price'];
                    $itemVals[] = '?';
                }

                // tax_rate
                if ($schema['items']['has_tax_rate']) {
                    $itemCols[] = 'tax_rate';
                    $itemVals[] = '?';
                }

                // discount_percent
                if ($schema['items']['has_discount_percent']) {
                    $itemCols[] = 'discount_percent';
                    $itemVals[] = '?';
                }

                // total / total_price
                if ($schema['items']['total']) {
                    $itemCols[] = $schema['items']['total'];
                    $itemVals[] = '?';
                }

                // created_at
                if ($schema['items']['has_created_at']) {
                    $itemCols[] = 'created_at';
                    $itemVals[] = 'NOW()';
                }

                $sqlItem = "INSERT INTO tp_invoice_items (" . implode(',', $itemCols) . ") VALUES (" . implode(',', $itemVals) . ")";
                $stmtItem = $pdo->prepare($sqlItem);

                $pos = 1;
                foreach ($cleanItems as $it) {
                    $lineNet = $it['quantity'] * $it['unit_price'];
                    $lineTax = $lineNet * ($it['tax_rate'] / 100.0);

                    $lineDiscount = 0.0;
                    if (($schema['items']['has_discount_percent'] ?? false) && $it['discount_percent'] > 0) {
                        $lineDiscount = ($lineNet + $lineTax) * ($it['discount_percent'] / 100.0);
                    }

                    $lineTotal = ($lineNet + $lineTax) - $lineDiscount;
                    if ($lineTotal < 0) $lineTotal = 0.0;

                    $bindItem = [$invoice_id, $it['description'], $it['quantity']];

                    if ($schema['items']['has_position']) {
                        $bindItem[] = $pos++;
                    }

                    if ($schema['items']['has_unit']) {
                        $bindItem[] = $it['unit'] ?: 'Stück';
                    }

                    if ($schema['items']['has_treatment_id']) {
                        $bindItem[] = $it['treatment_id'];
                    }

                    if ($schema['items']['price']) {
                        $bindItem[] = $it['unit_price'];
                    }

                    if ($schema['items']['has_tax_rate']) {
                        $bindItem[] = $it['tax_rate'];
                    }

                    if ($schema['items']['has_discount_percent']) {
                        $bindItem[] = $it['discount_percent'];
                    }

                    if ($schema['items']['total']) {
                        $bindItem[] = $lineTotal;
                    }

                    $stmtItem->execute($bindItem);
                }

                $pdo->commit();

                // Return created invoice (with joins)
                $stmt = $pdo->prepare("
                    SELECT
                        i.*,
                        p.name AS patient_name,
                        CONCAT_WS(' ', o.first_name, o.last_name) AS owner_full_name
                    FROM tp_invoices i
                    LEFT JOIN tp_patients p ON i.patient_id = p.id
                    " . invoice_owner_join_sql() . "
                    WHERE i.id = ?
                    LIMIT 1
                ");
                $stmt->execute([$invoice_id]);
                $inv = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($inv) {
                    $inv = normalize_invoice_row($inv, $schema);
                }

                api_success(['items' => [$inv ?: ['id' => $invoice_id]]]);

            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            break;
        }

        /**
         * UPDATE invoice meta + optional items
         * POST JSON:
         * {
         *   id, invoice_date, due_date, status, payment_method, payment_date, notes,
         *   items?: [{description, quantity, unit_price, tax_rate, unit, discount_percent}, ...]
         * }
         */
        case 'update': {
            $input = $_POST;
            if (empty($input)) {
                $input = read_json_body_safe();
            }

            $id = (int)($input['id'] ?? 0);
            if ($id <= 0) {
                api_error('Rechnungs-ID fehlt', 400);
            }

            // exists?
            $stmt = $pdo->prepare("SELECT id, patient_id FROM tp_invoices WHERE id = ?");
            $stmt->execute([$id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                api_error('Rechnung nicht gefunden', 404);
            }

            $invoice_date = (string)($input['invoice_date'] ?? date('Y-m-d'));
            $due_date     = (string)($input['due_date'] ?? date('Y-m-d', strtotime('+14 days')));
            $status       = normalize_invoice_status($input['status'] ?? 'draft', 'draft');
            $payment_method = $input['payment_method'] ?? null;
            $payment_date   = $input['payment_date'] ?? null;
            $notes          = $input['notes'] ?? null;

            // If status is paid and payment_date empty -> set today
            if ($status === 'paid' && (!$payment_date || trim((string)$payment_date) === '')) {
                $payment_date = date('Y-m-d');
            }

            $pdo->beginTransaction();
            try {
                // Update meta columns dynamically
                $sets = [];
                $bind = [];

                if ($schema['invoices']['has_invoice_date']) {
                    $sets[] = "invoice_date = ?";
                    $bind[] = $invoice_date;
                }
                if ($schema['invoices']['has_due_date']) {
                    $sets[] = "due_date = ?";
                    $bind[] = $due_date;
                }
                if ($schema['invoices']['has_status']) {
                    $sets[] = "status = ?";
                    $bind[] = $status;
                }
                if ($schema['invoices']['has_payment_method']) {
                    $sets[] = "payment_method = ?";
                    $bind[] = $payment_method;
                }
                if ($schema['invoices']['has_payment_date']) {
                    $sets[] = "payment_date = ?";
                    $bind[] = $payment_date;
                }
                if ($schema['invoices']['has_notes']) {
                    $sets[] = "notes = ?";
                    $bind[] = $notes;
                }
                if ($schema['invoices']['has_updated_at']) {
                    $sets[] = "updated_at = NOW()";
                }

                if (!empty($sets)) {
                    $sqlUp = "UPDATE tp_invoices SET " . implode(", ", $sets) . " WHERE id = ?";
                    $bind[] = $id;

                    $stmtUp = $pdo->prepare($sqlUp);
                    $stmtUp->execute($bind);
                }

                // Optional: update items + recompute totals if items provided
                $items = $input['items'] ?? null;
                if (is_array($items)) {
                    // delete existing items
                    $stmtDel = $pdo->prepare("DELETE FROM tp_invoice_items WHERE invoice_id = ?");
                    $stmtDel->execute([$id]);

                    $subtotal = 0.0;
                    $tax_amount = 0.0;

                    $cleanItems = [];
                    foreach ($items as $it) {
                        if (!is_array($it)) continue;

                        $desc = trim((string)($it['description'] ?? 'Position'));
                        if ($desc === '') $desc = 'Position';

                        $qty  = max(0.01, to_float($it['quantity'] ?? 1));
                        $unitPrice = max(0.0, to_float($it['unit_price'] ?? ($it['price'] ?? 0)));
                        $rate = max(0.0, to_float($it['tax_rate'] ?? 0));

                        $lineNet = $qty * $unitPrice;
                        $lineTax = $lineNet * ($rate / 100.0);

                        $subtotal += $lineNet;
                        $tax_amount += $lineTax;

                        $cleanItems[] = [
                            'description' => $desc,
                            'quantity' => $qty,
                            'unit_price' => $unitPrice,
                            'tax_rate' => $rate,
                            'unit' => safe_str($it['unit'] ?? 'Stück'),
                            'discount_percent' => to_float($it['discount_percent'] ?? 0),
                            'treatment_id' => isset($it['treatment_id']) ? to_int($it['treatment_id']) : null,
                        ];
                    }

                    // insert items
                    $itemCols = ['invoice_id', 'description', 'quantity'];
                    $itemVals = ['?', '?', '?'];

                    if ($schema['items']['has_position']) { $itemCols[] = 'position'; $itemVals[] = '?'; }
                    if ($schema['items']['has_unit']) { $itemCols[] = 'unit'; $itemVals[] = '?'; }
                    if ($schema['items']['has_treatment_id']) { $itemCols[] = 'treatment_id'; $itemVals[] = '?'; }
                    if ($schema['items']['price']) { $itemCols[] = $schema['items']['price']; $itemVals[] = '?'; }
                    if ($schema['items']['has_tax_rate']) { $itemCols[] = 'tax_rate'; $itemVals[] = '?'; }
                    if ($schema['items']['has_discount_percent']) { $itemCols[] = 'discount_percent'; $itemVals[] = '?'; }
                    if ($schema['items']['total']) { $itemCols[] = $schema['items']['total']; $itemVals[] = '?'; }
                    if ($schema['items']['has_created_at']) { $itemCols[] = 'created_at'; $itemVals[] = 'NOW()'; }

                    $sqlItem = "INSERT INTO tp_invoice_items (" . implode(',', $itemCols) . ") VALUES (" . implode(',', $itemVals) . ")";
                    $stmtItem = $pdo->prepare($sqlItem);

                    $pos = 1;
                    foreach ($cleanItems as $it) {
                        $lineNet = $it['quantity'] * $it['unit_price'];
                        $lineTax = $lineNet * ($it['tax_rate'] / 100.0);

                        $lineDiscount = 0.0;
                        if (($schema['items']['has_discount_percent'] ?? false) && $it['discount_percent'] > 0) {
                            $lineDiscount = ($lineNet + $lineTax) * ($it['discount_percent'] / 100.0);
                        }

                        $lineTotal = ($lineNet + $lineTax) - $lineDiscount;
                        if ($lineTotal < 0) $lineTotal = 0.0;

                        $bindItem = [$id, $it['description'], $it['quantity']];

                        if ($schema['items']['has_position']) $bindItem[] = $pos++;
                        if ($schema['items']['has_unit']) $bindItem[] = $it['unit'] ?: 'Stück';
                        if ($schema['items']['has_treatment_id']) $bindItem[] = $it['treatment_id'];
                        if ($schema['items']['price']) $bindItem[] = $it['unit_price'];
                        if ($schema['items']['has_tax_rate']) $bindItem[] = $it['tax_rate'];
                        if ($schema['items']['has_discount_percent']) $bindItem[] = $it['discount_percent'];
                        if ($schema['items']['total']) $bindItem[] = $lineTotal;

                        $stmtItem->execute($bindItem);
                    }

                    // update totals
                    $discount_percent = to_float($input['discount_percent'] ?? 0);
                    $discount_amount  = to_float($input['discount_amount'] ?? 0);
                    if (($schema['invoices']['has_discount_amount'] ?? false) && $discount_amount <= 0 && $discount_percent > 0) {
                        $discount_amount = ($subtotal + $tax_amount) * ($discount_percent / 100.0);
                    }
                    $total = ($subtotal + $tax_amount) - $discount_amount;
                    if ($total < 0) $total = 0.0;

                    $sets2 = [];
                    $bind2 = [];

                    if ($schema['invoices']['net']) { $sets2[] = $schema['invoices']['net'] . " = ?"; $bind2[] = $subtotal; }
                    if ($schema['invoices']['tax']) { $sets2[] = $schema['invoices']['tax'] . " = ?"; $bind2[] = $tax_amount; }
                    if ($schema['invoices']['has_discount_percent']) { $sets2[] = "discount_percent = ?"; $bind2[] = $discount_percent; }
                    if ($schema['invoices']['has_discount_amount']) { $sets2[] = "discount_amount = ?"; $bind2[] = $discount_amount; }
                    if ($schema['invoices']['total']) { $sets2[] = $schema['invoices']['total'] . " = ?"; $bind2[] = $total; }

                    if (!empty($sets2)) {
                        $sqlUp2 = "UPDATE tp_invoices SET " . implode(", ", $sets2) . " WHERE id = ?";
                        $bind2[] = $id;
                        $stmtUp2 = $pdo->prepare($sqlUp2);
                        $stmtUp2->execute($bind2);
                    }
                }

                $pdo->commit();

                // Return updated invoice
                $stmtOut = $pdo->prepare("
                    SELECT
                        i.*,
                        p.name AS patient_name,
                        CONCAT_WS(' ', o.first_name, o.last_name) AS owner_full_name
                    FROM tp_invoices i
                    LEFT JOIN tp_patients p ON i.patient_id = p.id
                    " . invoice_owner_join_sql() . "
                    WHERE i.id = ?
                    LIMIT 1
                ");
                $stmtOut->execute([$id]);
                $inv = $stmtOut->fetch(PDO::FETCH_ASSOC);
                if ($inv) $inv = normalize_invoice_row($inv, $schema);

                api_success(['items' => [$inv ?: ['id' => $id]]]);

            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            break;
        }

        /**
         * DELETE invoice + items
         */
        case 'delete': {
            $input = $_POST;
            if (empty($input)) {
                $input = read_json_body_safe();
            }

            $id = (int)($input['id'] ?? ($_GET['id'] ?? 0));
            if ($id <= 0) {
                api_error('Rechnungs-ID fehlt', 400);
            }

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("DELETE FROM tp_invoice_items WHERE invoice_id = ?");
                $stmt->execute([$id]);

                $stmt = $pdo->prepare("DELETE FROM tp_invoices WHERE id = ?");
                $stmt->execute([$id]);

                $pdo->commit();
                api_success(['items' => []]);

            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            break;
        }

        /**
         * STATISTICS (optional, server-side)
         * GET /api/invoiced.php?action=statistics&year=2025&month=12
         * -> nutzt korrekt "total" ODER "total_amount" (auto)
         */
        case 'statistics': {
            $year = (int)($_GET['year'] ?? date('Y'));
            $month = (int)($_GET['month'] ?? 0);

            $totalCol = $schema['invoices']['total'] ?: 'total';
            $statusColExists = $schema['invoices']['has_status'] ?? true;

            $sql = "
                SELECT
                    COUNT(*) AS total_invoices,
                    COALESCE(SUM($totalCol), 0) AS total_revenue,
                    COALESCE(SUM(CASE WHEN status = 'paid' THEN $totalCol ELSE 0 END), 0) AS paid_revenue,
                    COALESCE(SUM(CASE WHEN status IN ('sent','pending','partially_paid','open') THEN $totalCol ELSE 0 END), 0) AS open_revenue,
                    COALESCE(SUM(CASE WHEN status = 'overdue' THEN $totalCol ELSE 0 END), 0) AS overdue_revenue
                FROM tp_invoices
                WHERE YEAR(invoice_date) = :year
            ";

            $params = [':year' => $year];

            if ($month > 0) {
                $sql .= " AND MONTH(invoice_date) = :month";
                $params[':month'] = $month;
            }

            $stmt = $pdo->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, (int)$v, PDO::PARAM_INT);
            }
            $stmt->execute();

            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
                'total_invoices' => 0,
                'total_revenue' => 0,
                'paid_revenue' => 0,
                'open_revenue' => 0,
                'overdue_revenue' => 0
            ];

            $row['total_invoices'] = (int)($row['total_invoices'] ?? 0);
            $row['total_revenue']  = to_float($row['total_revenue'] ?? 0);
            $row['paid_revenue']   = to_float($row['paid_revenue'] ?? 0);
            $row['open_revenue']   = to_float($row['open_revenue'] ?? 0);
            $row['overdue_revenue']= to_float($row['overdue_revenue'] ?? 0);

            api_success(['items' => [$row]]);
            break;
        }

        /**
         * PDF download
         * GET /api/invoiced.php?action=pdf&id=123
         *
         * - versucht mPDF (wenn vorhanden)
         * - fallback: HTML print page
         */
        case 'pdf': {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) {
                // PDF-Action: plain text
                header('Content-Type: text/plain; charset=utf-8');
                echo "Rechnungs-ID fehlt";
                exit;
            }

            // invoice + items laden
            $stmt = $pdo->prepare("
                SELECT
                    i.*,
                    p.name AS patient_name,
                    p.species,
                    p.patient_number,
                    o.salutation,
                    o.first_name AS owner_first_name,
                    o.last_name AS owner_last_name,
                    o.company AS owner_company,
                    o.street AS owner_street,
                    o.house_number AS owner_house_number,
                    o.postal_code AS owner_postal_code,
                    o.city AS owner_city,
                    o.email AS owner_email,
                    o.phone AS owner_phone,
                    CONCAT_WS(' ', o.first_name, o.last_name) AS owner_full_name
                FROM tp_invoices i
                LEFT JOIN tp_patients p ON i.patient_id = p.id
                " . invoice_owner_join_sql() . "
                WHERE i.id = ?
                LIMIT 1
            ");
            $stmt->execute([$id]);
            $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$invoice) {
                header('Content-Type: text/plain; charset=utf-8');
                echo "Rechnung nicht gefunden";
                exit;
            }

            $stmt = $pdo->prepare("SELECT * FROM tp_invoice_items WHERE invoice_id = ? ORDER BY position, id");
            $stmt->execute([$id]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $items = [];
            foreach ($rows as $it) {
                $items[] = normalize_item_row($it, $schema);
            }

            $invoice = normalize_invoice_row($invoice, $schema);

            $invNo = (string)($invoice['invoice_number'] ?? ('R-' . $invoice['id']));
            $filename = 'Rechnung_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $invNo) . '.pdf';

            // HTML for PDF or print
            $html = '<!doctype html><html lang="de"><head><meta charset="utf-8">';
            $html .= '<meta name="viewport" content="width=device-width,initial-scale=1">';
            $html .= '<title>' . h($filename) . '</title>';
            $html .= '<style>
                body{font-family: Arial, sans-serif; font-size: 12px; color:#111; margin: 24px;}
                .row{display:flex; gap:24px; justify-content:space-between;}
                .box{border:1px solid #ddd; padding:12px; border-radius:10px;}
                h1{font-size:18px; margin:0 0 6px;}
                .muted{color:#666;}
                table{width:100%; border-collapse:collapse; margin-top:14px;}
                th,td{border-bottom:1px solid #eee; padding:8px 6px; text-align:left;}
                th{background:#f7f7f7;}
                .right{text-align:right;}
                .totals{margin-top:14px; width: 320px; margin-left:auto;}
                .totals td{border:none; padding:4px 6px;}
                .totals tr:last-child td{font-weight:bold; font-size:13px;}
                @media print{ .noprint{display:none;} body{margin:0;} }
            </style></head><body>';

            $html .= '<div class="noprint" style="margin-bottom:12px;"><button onclick="window.print()">Drucken</button></div>';

            $html .= '<div class="row">';
            $html .= '<div style="flex:1" class="box">';
            $html .= '<h1>Rechnung ' . h($invNo) . '</h1>';
            $html .= '<div class="muted">Datum: ' . h((string)($invoice['invoice_date'] ?? '')) . ' · Fällig: ' . h((string)($invoice['due_date'] ?? '')) . '</div>';
            $html .= '<div class="muted">Status: ' . h((string)($invoice['status'] ?? '')) . '</div>';
            $html .= '</div>';

            $html .= '<div style="flex:1" class="box">';
            $ownerName = trim((string)($invoice['owner_full_name'] ?? ''));
            $html .= '<div style="font-weight:bold;">Kunde</div>';
            $html .= '<div>' . h($ownerName ?: '—') . '</div>';
            $addr = trim((string)($invoice['owner_street'] ?? '')) . ' ' . trim((string)($invoice['owner_house_number'] ?? ''));
            $zipCity = trim((string)($invoice['owner_postal_code'] ?? '')) . ' ' . trim((string)($invoice['owner_city'] ?? ''));
            if (trim($addr) !== '') $html .= '<div>' . h(trim($addr)) . '</div>';
            if (trim($zipCity) !== '') $html .= '<div>' . h(trim($zipCity)) . '</div>';
            if (!empty($invoice['owner_email'])) $html .= '<div class="muted">' . h((string)$invoice['owner_email']) . '</div>';
            if (!empty($invoice['owner_phone'])) $html .= '<div class="muted">' . h((string)$invoice['owner_phone']) . '</div>';
            $html .= '</div>';
            $html .= '</div>';

            $html .= '<div class="box" style="margin-top:14px;">';
            $html .= '<div style="font-weight:bold;margin-bottom:6px;">Patient</div>';
            $html .= '<div>' . h((string)($invoice['patient_name'] ?? '—')) . '</div>';
            if (!empty($invoice['patient_number'])) $html .= '<div class="muted">Nr.: ' . h((string)$invoice['patient_number']) . '</div>';
            $html .= '</div>';

            $html .= '<table><thead><tr>';
            $html .= '<th style="width:52%">Beschreibung</th>';
            $html .= '<th class="right" style="width:12%">Menge</th>';
            $html .= '<th class="right" style="width:18%">Einzel</th>';
            $html .= '<th class="right" style="width:18%">Gesamt</th>';
            $html .= '</tr></thead><tbody>';

            foreach ($items as $it) {
                $desc = (string)($it['description'] ?? '');
                $qty  = to_float($it['quantity'] ?? 0);
                $up   = to_float($it['unit_price'] ?? 0);
                $tot  = to_float($it['total_price'] ?? 0);
                $html .= '<tr>';
                $html .= '<td>' . h($desc) . '</td>';
                $html .= '<td class="right">' . h(number_format($qty, 2, ',', '.')) . '</td>';
                $html .= '<td class="right">' . h(number_format($up, 2, ',', '.')) . ' €</td>';
                $html .= '<td class="right">' . h(number_format($tot, 2, ',', '.')) . ' €</td>';
                $html .= '</tr>';
            }

            $html .= '</tbody></table>';

            $html .= '<table class="totals">';
            $html .= '<tr><td class="right">Zwischensumme:</td><td class="right">' . h(number_format($invoice['net_amount'], 2, ',', '.')) . ' €</td></tr>';
            $html .= '<tr><td class="right">MwSt:</td><td class="right">' . h(number_format($invoice['tax_amount'], 2, ',', '.')) . ' €</td></tr>';
            $html .= '<tr><td class="right">Gesamt:</td><td class="right">' . h(number_format($invoice['total_amount'], 2, ',', '.')) . ' €</td></tr>';
            $html .= '</table>';

            if (!empty($invoice['notes'])) {
                $html .= '<div class="box" style="margin-top:14px;"><div style="font-weight:bold;margin-bottom:6px;">Notizen</div>';
                $html .= '<div>' . nl2br(h((string)$invoice['notes'])) . '</div></div>';
            }

            $html .= '</body></html>';

            // Try mPDF if available
            $mpdfOk = false;
            try {
                $vendor = dirname(__DIR__) . '/vendor/autoload.php';
                if (is_file($vendor)) {
                    require_once $vendor;
                }
                if (class_exists('\Mpdf\Mpdf')) {
                    $mpdf = new \Mpdf\Mpdf([
                        'mode' => 'utf-8',
                        'format' => 'A4',
                        'margin_left' => 12,
                        'margin_right' => 12,
                        'margin_top' => 12,
                        'margin_bottom' => 12,
                    ]);
                    $mpdf->WriteHTML($html);
                    header('Content-Type: application/pdf');
                    header('Content-Disposition: inline; filename="' . $filename . '"');
                    $mpdf->Output($filename, \Mpdf\Output\Destination::INLINE);
                    $mpdfOk = true;
                }
            } catch (Throwable $e) {
                $mpdfOk = false;
            }

            if (!$mpdfOk) {
                // Fallback HTML
                header('Content-Type: text/html; charset=utf-8');
                echo $html;
            }
            exit;
        }

        default:
            api_error("Unbekannte Aktion: " . $action, 400);
    }

} catch (PDOException $e) {
    error_log("Invoiced API PDO Error (" . $action . "): " . $e->getMessage());
    set_json_headers();
    api_error('Datenbankfehler aufgetreten');
} catch (Throwable $e) {
    error_log("Invoiced API Error (" . $action . "): " . $e->getMessage());
    set_json_headers();
    api_error('Serverfehler aufgetreten');
}

exit;