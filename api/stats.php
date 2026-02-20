<?php
/**
 * Tierphysio Manager 2.0
 * API Endpoint: /api/stats.php
 *
 * FIX:
 * - Nutzt DEIN DB-Schema: tp_invoices.total (NICHT total_amount)
 * - Liefert IMMER JSON (auch bei Fatal Errors)
 * - Einheitliches Response-Format via api_success/api_error aus _bootstrap.php
 *
 * Actions:
 * - overview (default): KPIs für Dashboard/Rechnungen
 *   Parameter optional:
 *   - period=month|quarter|year|all
 *   - from=YYYY-MM-DD, to=YYYY-MM-DD (überschreibt period)
 *   - date_from/date_to (Alias)
 *
 * Response:
 * {"ok":true,"status":"success","data":{"items":[{...}] ,"count":1}}
 */

declare(strict_types=1);

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

ob_start();

register_shutdown_function(function () {
    $err = error_get_last();
    if (!$err) {
        if (ob_get_length() !== false) {
            ob_end_flush();
        }
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    if (!in_array($err['type'], $fatalTypes, true)) {
        if (ob_get_length() !== false) {
            ob_end_flush();
        }
        return;
    }

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    error_log("Stats API FATAL: {$err['message']} in {$err['file']}:{$err['line']}");

    echo json_encode([
        'ok' => false,
        'status' => 'error',
        'message' => 'Serverfehler aufgetreten',
    ], JSON_UNESCAPED_UNICODE);

    exit;
});

require_once __DIR__ . '/_bootstrap.php';

// ------------------------------------------------------------
// Helpers
// ------------------------------------------------------------

function safe_trim($v): string
{
    return trim((string)($v ?? ''));
}

function to_float($v): float
{
    if ($v === null) return 0.0;
    if (is_float($v)) return $v;
    if (is_int($v)) return (float)$v;
    $s = str_replace(',', '.', (string)$v);
    return is_numeric($s) ? (float)$s : 0.0;
}

function period_to_range(string $period): array
{
    $period = strtolower(trim($period));
    $today = new DateTimeImmutable('today');

    if ($period === 'all') {
        return ['', ''];
    }
    if ($period === 'year') {
        $from = $today->setDate((int)$today->format('Y'), 1, 1);
        $to = $today->setDate((int)$today->format('Y'), 12, 31);
        return [$from->format('Y-m-d'), $to->format('Y-m-d')];
    }
    if ($period === 'quarter') {
        $m = (int)$today->format('n');
        $q = (int)floor(($m - 1) / 3);
        $startMonth = $q * 3 + 1;
        $from = $today->setDate((int)$today->format('Y'), $startMonth, 1);
        $to = $from->modify('+3 months')->modify('-1 day');
        return [$from->format('Y-m-d'), $to->format('Y-m-d')];
    }

    // default month
    $from = $today->setDate((int)$today->format('Y'), (int)$today->format('n'), 1);
    $to = $from->modify('+1 month')->modify('-1 day');
    return [$from->format('Y-m-d'), $to->format('Y-m-d')];
}

function compute_overview(PDO $pdo, string $dateFrom, string $dateTo): array
{
    $today = (new DateTimeImmutable('today'))->format('Y-m-d');

    // Wir rechnen konsequent auf tp_invoices.total (dein Schema)
    // "Offen": status in sent, partially_paid, draft (anpassbar)
    // "Überfällig": due_date < today und status in sent, partially_paid
    // "Bezahlt (Monat)": status='paid' UND invoice_date im aktuellen Monat (oder optional payment_date)
    // "Monatsumsatz": alle Rechnungen im aktuellen Monat (egal Status) anhand invoice_date

    $ym = (new DateTimeImmutable('today'))->format('Y-m'); // e.g. 2025-12

    $params = [];
    $whereRange = '';

    if ($dateFrom !== '' && $dateTo !== '') {
        $whereRange = " AND invoice_date BETWEEN :from AND :to";
        $params[':from'] = $dateFrom;
        $params[':to'] = $dateTo;
    }

    // 1) Aggregat für geladenen Zeitraum (für deine Overview-Kacheln kann das auch period-basiert sein)
    $sql = "
        SELECT
            COALESCE(SUM(CASE WHEN status IN ('sent','draft','partially_paid') THEN total ELSE 0 END), 0) AS open_amount,
            COALESCE(SUM(CASE WHEN status IN ('sent','partially_paid') AND due_date < :today THEN total ELSE 0 END), 0) AS overdue_amount,
            COALESCE(SUM(CASE WHEN status = 'paid' THEN total ELSE 0 END), 0) AS paid_amount_in_range,

            SUM(CASE WHEN status IN ('sent','draft','partially_paid') THEN 1 ELSE 0 END) AS open_count,
            SUM(CASE WHEN status IN ('sent','partially_paid') AND due_date < :today THEN 1 ELSE 0 END) AS overdue_count,
            SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) AS paid_count_in_range
        FROM tp_invoices
        WHERE 1=1
        {$whereRange}
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':today', $today);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->execute();
    $agg = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    // 2) Monatsumsatz (immer aktueller Monat, unabhängig vom gewählten Zeitraum, wie dein JS es auch macht)
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(total), 0) AS month_revenue_total
        FROM tp_invoices
        WHERE invoice_date LIKE :ym
    ");
    $stmt->bindValue(':ym', $ym . '%');
    $stmt->execute();
    $monthRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['month_revenue_total' => 0];

    // 3) Bezahlt (Monat) – aktueller Monat nach invoice_date
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(total), 0) AS paid_month_amount,
            COUNT(*) AS paid_month_count
        FROM tp_invoices
        WHERE status = 'paid'
          AND invoice_date LIKE :ym
    ");
    $stmt->bindValue(':ym', $ym . '%');
    $stmt->execute();
    $paidMonth = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['paid_month_amount' => 0, 'paid_month_count' => 0];

    return [
        'monthRevenueTotal' => to_float($monthRow['month_revenue_total'] ?? 0),
        'openAmount' => to_float($agg['open_amount'] ?? 0),
        'overdueAmount' => to_float($agg['overdue_amount'] ?? 0),
        'paidMonthAmount' => to_float($paidMonth['paid_month_amount'] ?? 0),

        'openCount' => (int)($agg['open_count'] ?? 0),
        'overdueCount' => (int)($agg['overdue_count'] ?? 0),
        'paidMonthCount' => (int)($paidMonth['paid_month_count'] ?? 0),

        // Debug / Kontext
        'range' => [
            'from' => $dateFrom,
            'to' => $dateTo,
        ],
        'today' => $today,
        'currentMonth' => $ym,
    ];
}

// ------------------------------------------------------------
// Routing
// ------------------------------------------------------------

$action = $_GET['action'] ?? 'overview';

try {
    $pdo = get_pdo();

    // Parameter normalisieren
    $period = safe_trim($_GET['period'] ?? 'month');

    $dateFrom = safe_trim($_GET['from'] ?? '');
    $dateTo = safe_trim($_GET['to'] ?? '');

    // Aliases
    if ($dateFrom === '') $dateFrom = safe_trim($_GET['date_from'] ?? '');
    if ($dateTo === '') $dateTo = safe_trim($_GET['date_to'] ?? '');

    // Wenn nix gesetzt: period range
    if ($dateFrom === '' && $dateTo === '') {
        [$df, $dt] = period_to_range($period ?: 'month');
        $dateFrom = $df;
        $dateTo = $dt;
    }

    switch ($action) {
        case 'overview':
        default: {
            $row = compute_overview($pdo, $dateFrom, $dateTo);

            api_success([
                'items' => [$row],
                'count' => 1,
            ]);
            break;
        }
    }

} catch (PDOException $e) {
    error_log("Stats API PDO Error (" . (string)$action . "): " . $e->getMessage());
    api_error('Datenbankfehler aufgetreten');
} catch (Throwable $e) {
    error_log("Stats API Error (" . (string)$action . "): " . $e->getMessage());
    api_error('Serverfehler aufgetreten');
}

exit;