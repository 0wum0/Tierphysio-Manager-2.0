<?php
/**
 * /api/emails.php
 * Tierphysio Manager - Emails API
 * - health (checks config/extensions)
 * - folders (IMAP folders)
 * - list (IMAP inbox list)
 * - read (IMAP read message by UID)
 * - send (PHPMailer SMTP or PHP mail)
 * - notify (UNSEEN count + preview items for bell)
 * - backward compat: inbox/message/test_imap/test_smtp
 */

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');

function json_out(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Robust auth checks (ohne Redirects)
 */
try {
    if (!isset($auth)) {
        json_out(500, ['status' => 'error', 'message' => 'Auth nicht initialisiert (_bootstrap.php)']);
    }

    $user = null;
    if (method_exists($auth, 'getUser')) {
        $user = $auth->getUser();
    }

    if (!$user || !is_array($user) || empty($user['id'])) {
        json_out(401, ['status' => 'error', 'message' => 'Nicht eingeloggt']);
    }

    // Optional permission check
    if (method_exists($auth, 'hasPermission')) {
        if (!$auth->hasPermission('view_emails')) {
            json_out(403, ['status' => 'error', 'message' => 'Keine Berechtigung (view_emails)']);
        }
    }

    if (!isset($db) || !($db instanceof PDO)) {
        json_out(500, ['status' => 'error', 'message' => 'DB (PDO) nicht initialisiert (_bootstrap.php)']);
    }
} catch (Throwable $e) {
    json_out(500, ['status' => 'error', 'message' => 'Auth/Bootstrap Fehler: ' . $e->getMessage()]);
}

/**
 * Settings helper (mit Fallback-Keys!)
 */
function get_setting(PDO $db, string $category, string $key, $default = null): ?string {
    $stmt = $db->prepare("SELECT `value` FROM tp_settings WHERE category = :c AND `key` = :k LIMIT 1");
    $stmt->execute([':c' => $category, ':k' => $key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return $default === null ? null : (string)$default;
    return $row['value'] === null ? ($default === null ? null : (string)$default) : (string)$row['value'];
}

function get_setting_any(PDO $db, string $category, array $keys, $default = null): ?string {
    foreach ($keys as $k) {
        $v = get_setting($db, $category, (string)$k, null);
        if ($v !== null && $v !== '') return (string)$v;
    }
    return $default === null ? null : (string)$default;
}

function bool_setting_any(PDO $db, string $category, array $keys, bool $default = false): bool {
    $v = get_setting_any($db, $category, $keys, $default ? '1' : '0');
    $v = strtolower(trim((string)$v));
    return ($v === '1' || $v === 'true' || $v === 'on' || $v === 'yes');
}

function int_setting_any(PDO $db, string $category, array $keys, int $default = 0): int {
    $v = get_setting_any($db, $category, $keys, (string)$default);
    return (int)$v;
}

/**
 * IMAP mailbox string builder
 * Unterstützt sowohl:
 *  - imap.host / imap.port / imap.encryption / imap.username / imap.password / imap.mailbox
 *  - imap.imap_host / imap.imap_port / ... (deine alte API Keys)
 */
function imap_config(PDO $db, ?string $folderOverride = null): array {
    $host = trim((string)get_setting_any($db, 'imap', ['host', 'imap_host'], ''));
    $port = int_setting_any($db, 'imap', ['port', 'imap_port'], 993);
    $enc  = strtolower(trim((string)get_setting_any($db, 'imap', ['encryption', 'imap_encryption'], 'ssl')));
    $folder = trim((string)($folderOverride ?: get_setting_any($db, 'imap', ['mailbox', 'imap_folder', 'folder'], 'INBOX')));
    $validateCert = bool_setting_any($db, 'imap', ['validate_cert', 'imap_validate_cert'], true);

    $user = (string)get_setting_any($db, 'imap', ['username', 'imap_username'], '');
    $pass = (string)get_setting_any($db, 'imap', ['password', 'imap_password'], '');

    if ($host === '') throw new RuntimeException('IMAP Host fehlt (Settings: imap.host)');
    if ($user === '') throw new RuntimeException('IMAP Username fehlt (Settings: imap.username)');

    $flags = [];
    if ($enc === 'ssl') $flags[] = 'ssl';
    if ($enc === 'tls') $flags[] = 'tls';
    if (!$validateCert) $flags[] = 'novalidate-cert';

    $flagStr = '';
    if (!empty($flags)) $flagStr = '/' . implode('/', $flags);

    $mailbox = '{' . $host . ':' . $port . $flagStr . '}' . $folder;
    return [$mailbox, $user, $pass, $host, $port, $enc, $folder];
}

/**
 * MIME decode helpers
 */
function decode_part(string $text, int $encoding): string {
    return match ($encoding) {
        3 => (base64_decode($text) ?: $text),
        4 => quoted_printable_decode($text),
        default => $text,
    };
}

function extract_body($imap, int $msgno): array {
    $structure = @imap_fetchstructure($imap, $msgno);
    $out = ['text' => '', 'html' => ''];

    if (!$structure) {
        $raw = @imap_body($imap, $msgno);
        $out['text'] = is_string($raw) ? $raw : '';
        return $out;
    }

    // Single part
    if (empty($structure->parts)) {
        $body = @imap_body($imap, $msgno);
        $body = is_string($body) ? decode_part($body, (int)($structure->encoding ?? 0)) : '';
        $sub = strtolower((string)($structure->subtype ?? 'plain'));
        if ($sub === 'html') $out['html'] = $body;
        else $out['text'] = $body;
        return $out;
    }

    // Multipart: walk parts for text/plain + text/html
    foreach ($structure->parts as $idx => $part) {
        $partNo = (string)($idx + 1);
        $type = (int)($part->type ?? -1); // 0 = text
        $sub = strtolower((string)($part->subtype ?? ''));

        if ($type === 0 && ($sub === 'plain' || $sub === 'html')) {
            $b = @imap_fetchbody($imap, $msgno, $partNo);
            if (!is_string($b)) continue;
            $b = decode_part($b, (int)($part->encoding ?? 0));
            $b = imap_utf8($b);

            if ($sub === 'html' && $out['html'] === '') $out['html'] = $b;
            if ($sub === 'plain' && $out['text'] === '') $out['text'] = $b;
        }
    }

    // Fallback
    if ($out['text'] === '' && $out['html'] === '') {
        $raw = @imap_body($imap, $msgno);
        $out['text'] = is_string($raw) ? $raw : '';
    }

    return $out;
}

/**
 * Peek preview helper (OHNE als gelesen zu markieren)
 */
function extract_preview_peek($imap, int $msgno, int $maxLen = 140): string {
    $peekFlag = defined('FT_PEEK') ? FT_PEEK : 0;

    $structure = @imap_fetchstructure($imap, $msgno);
    $text = '';

    if ($structure && !empty($structure->parts) && is_array($structure->parts)) {
        // prefer text/plain
        foreach ($structure->parts as $idx => $part) {
            $partNo = (string)($idx + 1);
            $type = (int)($part->type ?? -1);
            $sub = strtolower((string)($part->subtype ?? ''));

            if ($type === 0 && $sub === 'plain') {
                $b = @imap_fetchbody($imap, $msgno, $partNo, $peekFlag);
                if (is_string($b) && $b !== '') {
                    $b = decode_part($b, (int)($part->encoding ?? 0));
                    $text = (string)$b;
                    break;
                }
            }
        }

        // fallback: try html part
        if ($text === '') {
            foreach ($structure->parts as $idx => $part) {
                $partNo = (string)($idx + 1);
                $type = (int)($part->type ?? -1);
                $sub = strtolower((string)($part->subtype ?? ''));

                if ($type === 0 && $sub === 'html') {
                    $b = @imap_fetchbody($imap, $msgno, $partNo, $peekFlag);
                    if (is_string($b) && $b !== '') {
                        $b = decode_part($b, (int)($part->encoding ?? 0));
                        $text = strip_tags((string)$b);
                        break;
                    }
                }
            }
        }
    }

    if ($text === '') {
        $raw = @imap_body($imap, $msgno, $peekFlag);
        $text = is_string($raw) ? $raw : '';
        $text = strip_tags($text);
    }

    $text = (string)imap_utf8($text);
    $text = preg_replace("/\r\n|\r|\n/u", "\n", $text);
    $text = trim($text);

    // first line only
    $firstLine = $text;
    $pos = mb_strpos($text, "\n");
    if ($pos !== false) {
        $firstLine = trim(mb_substr($text, 0, $pos));
    }

    if (mb_strlen($firstLine) > $maxLen) {
        $firstLine = mb_substr($firstLine, 0, $maxLen - 1) . '…';
    }

    return $firstLine;
}

/**
 * SMTP config helper (Fallback-Keys)
 */
function smtp_config(PDO $db): array {
    // neue Keys (seed): smtp.host, smtp.port, smtp.encryption, smtp.username, smtp.password, smtp.auth
    // alte Keys (deine API): smtp.smtp_host, ...
    $host = trim((string)get_setting_any($db, 'smtp', ['host','smtp_host'], ''));
    $port = int_setting_any($db, 'smtp', ['port','smtp_port'], 587);
    $enc  = strtolower(trim((string)get_setting_any($db, 'smtp', ['encryption','smtp_encryption'], 'tls')));
    $authEnabled = bool_setting_any($db, 'smtp', ['auth','smtp_auth'], true);
    $userSmtp = (string)get_setting_any($db, 'smtp', ['username','smtp_username'], '');
    $passSmtp = (string)get_setting_any($db, 'smtp', ['password','smtp_password'], '');
    $allowSelf = bool_setting_any($db, 'smtp', ['allow_self_signed','smtp_allow_self_signed'], false);

    // from / reply (mail category preferred, fallback smtp legacy)
    $fromEmail = (string)get_setting_any($db, 'mail', ['from_email'], get_setting_any($db, 'smtp', ['from_email','smtp_from_email'], 'no-reply@example.com'));
    $fromName  = (string)get_setting_any($db, 'mail', ['from_name'],  get_setting_any($db, 'smtp', ['from_name','smtp_from_name'], 'Tierphysio Manager'));
    $replyTo   = (string)get_setting_any($db, 'mail', ['reply_to'],   get_setting_any($db, 'smtp', ['reply_to','smtp_reply_to'], ''));

    // driver: smtp / phpmail
    $driver = strtolower(trim((string)get_setting_any($db, 'mail', ['driver'], 'smtp')));

    return [$driver, $host, $port, $enc, $authEnabled, $userSmtp, $passSmtp, $allowSelf, $fromEmail, $fromName, $replyTo];
}

/**
 * Actions
 */
$action = $_GET['action'] ?? '';

try {
    switch ($action) {

        // --- NEW actions expected by your UI ---
        case 'health': {
            $imapConfigured = false;
            $smtpConfigured = false;

            $imapExt = function_exists('imap_open');
            $phpmailer = class_exists(\PHPMailer\PHPMailer\PHPMailer::class);

            try {
                $host = trim((string)get_setting_any($db, 'imap', ['host','imap_host'], ''));
                $userImap = trim((string)get_setting_any($db, 'imap', ['username','imap_username'], ''));
                $imapConfigured = ($host !== '' && $userImap !== '');
            } catch (Throwable $e) {}

            try {
                $host = trim((string)get_setting_any($db, 'smtp', ['host','smtp_host'], ''));
                $smtpConfigured = ($host !== '');
            } catch (Throwable $e) {}

            json_out(200, [
                'status' => 'success',
                'data' => [
                    'imap' => [
                        'enabled' => true,
                        'configured' => $imapConfigured,
                        'ext_imap' => $imapExt,
                    ],
                    'smtp' => [
                        'enabled' => true,
                        'configured' => $smtpConfigured,
                        'phpmailer' => $phpmailer,
                    ],
                ]
            ]);
        }

        /**
         * NOTIFY: für die Glocke
         * - liefert UNSEEN count + preview items (uid, subject, from, date, preview)
         * - markiert NICHT als gelesen (FT_PEEK)
         */
        case 'notify': {
            if (!function_exists('imap_open')) {
                json_out(500, ['status' => 'error', 'message' => 'PHP IMAP Extension fehlt (php-imap aktivieren).']);
            }

            $limit = isset($_GET['limit']) ? max(1, min(20, (int)$_GET['limit'])) : 5;
            $folder = 'INBOX';

            [$mailbox, $userImap, $passImap] = imap_config($db, $folder);

            $imap = @imap_open($mailbox, $userImap, $passImap, 0, 1);
            if (!$imap) {
                $err = imap_last_error() ?: 'Unbekannter IMAP Fehler';
                json_out(400, ['status' => 'error', 'message' => 'IMAP Verbindung fehlgeschlagen: ' . $err]);
            }

            // UNSEEN msg numbers
            $unseenMsgNos = @imap_search($imap, 'UNSEEN');
            $unseenMsgNos = is_array($unseenMsgNos) ? $unseenMsgNos : [];

            $unseenCount = count($unseenMsgNos);

            // newest first
            rsort($unseenMsgNos);

            $items = [];
            $take = array_slice($unseenMsgNos, 0, $limit);

            foreach ($take as $msgno) {
                $msgno = (int)$msgno;
                $h = @imap_headerinfo($imap, $msgno);
                if (!$h) continue;

                $uid = @imap_uid($imap, $msgno);
                if (!$uid) $uid = $msgno;

                $subject = isset($h->subject) ? (string)imap_utf8((string)$h->subject) : '(ohne Betreff)';
                $from = '';
                if (!empty($h->from) && is_array($h->from)) {
                    $f = $h->from[0];
                    $name = trim((string)($f->personal ?? ''));
                    $mailAddr = trim((string)($f->mailbox ?? '')) . '@' . trim((string)($f->host ?? ''));
                    $from = $name !== '' ? ($name . ' <' . $mailAddr . '>') : $mailAddr;
                }

                $date = isset($h->date) ? (string)$h->date : '';

                $preview = '';
                try {
                    $preview = extract_preview_peek($imap, $msgno, 140);
                } catch (Throwable $e) {
                    $preview = '';
                }

                $items[] = [
                    'uid' => (int)$uid,
                    'msgno' => $msgno,
                    'subject' => $subject,
                    'from' => $from,
                    'date' => $date,
                    'preview' => $preview,
                ];
            }

            imap_close($imap);

            json_out(200, [
                'status' => 'success',
                'data' => [
                    'unseen' => $unseenCount,
                    'items' => $items,
                ]
            ]);
        }

        case 'folders': {
            if (!function_exists('imap_open')) {
                json_out(500, ['status' => 'error', 'message' => 'PHP IMAP Extension fehlt (php-imap aktivieren).']);
            }

            // Folder listing: connect to INBOX base, list mailboxes
            [$mailbox, $userImap, $passImap, $host, $port, $enc] = imap_config($db, 'INBOX');

            $imap = @imap_open($mailbox, $userImap, $passImap, 0, 1);
            if (!$imap) {
                $err = imap_last_error() ?: 'Unbekannter IMAP Fehler';
                json_out(400, ['status' => 'error', 'message' => 'IMAP Verbindung fehlgeschlagen: ' . $err]);
            }

            $flags = [];
            if ($enc === 'ssl') $flags[] = 'ssl';
            if ($enc === 'tls') $flags[] = 'tls';
            $validateCert = bool_setting_any($db, 'imap', ['validate_cert', 'imap_validate_cert'], true);
            if (!$validateCert) $flags[] = 'novalidate-cert';
            $flagStr = !empty($flags) ? '/' . implode('/', $flags) : '';

            $base = '{' . $host . ':' . $port . $flagStr . '}';

            $list = @imap_list($imap, $base, '*');
            $folders = [];

            if (is_array($list)) {
                foreach ($list as $f) {
                    $name = (string)$f;
                    // strip base
                    if (str_starts_with($name, $base)) {
                        $name = substr($name, strlen($base));
                    }
                    $name = trim($name);
                    if ($name !== '') $folders[] = $name;
                }
            }

            imap_close($imap);

            if (empty($folders)) {
                $folders = ['INBOX'];
            }

            json_out(200, ['status' => 'success', 'data' => array_values(array_unique($folders))]);
        }

        case 'list': {
            if (!function_exists('imap_open')) {
                json_out(500, ['status' => 'error', 'message' => 'PHP IMAP Extension fehlt (php-imap aktivieren).']);
            }

            $folder = trim((string)($_GET['folder'] ?? 'INBOX'));
            $pageSize = isset($_GET['pageSize']) ? max(1, min(200, (int)$_GET['pageSize'])) : 40;

            [$mailbox, $userImap, $passImap] = imap_config($db, $folder);

            $imap = @imap_open($mailbox, $userImap, $passImap, 0, 1);
            if (!$imap) {
                $err = imap_last_error() ?: 'Unbekannter IMAP Fehler';
                json_out(400, ['status' => 'error', 'message' => 'IMAP Verbindung fehlgeschlagen: ' . $err]);
            }

            $numMsg = (int)imap_num_msg($imap);
            $start = max(1, $numMsg - $pageSize + 1);

            $items = [];
            for ($i = $numMsg; $i >= $start; $i--) {
                $h = @imap_headerinfo($imap, $i);
                if (!$h) continue;

                $uid = @imap_uid($imap, $i);
                if (!$uid) $uid = $i;

                $subject = isset($h->subject) ? (string)imap_utf8((string)$h->subject) : '(ohne Betreff)';
                $from = '';
                if (!empty($h->from) && is_array($h->from)) {
                    $f = $h->from[0];
                    $name = trim((string)($f->personal ?? ''));
                    $mail = trim((string)($f->mailbox ?? '')) . '@' . trim((string)($f->host ?? ''));
                    $from = $name !== '' ? ($name . ' <' . $mail . '>') : $mail;
                }

                $date = isset($h->date) ? (string)$h->date : '';

                $seen = false;
                if (isset($h->Unseen)) $seen = ((int)$h->Unseen === 0);

                $items[] = [
                    'uid' => (int)$uid,
                    'msgno' => $i,
                    'subject' => $subject,
                    'from' => $from,
                    'date' => $date,
                    'seen' => $seen,
                ];
            }

            imap_close($imap);

            json_out(200, [
                'status' => 'success',
                'data' => [
                    'items' => $items,
                    'total' => $numMsg,
                ]
            ]);
        }

        case 'read': {
            if (!function_exists('imap_open')) {
                json_out(500, ['status' => 'error', 'message' => 'PHP IMAP Extension fehlt (php-imap aktivieren).']);
            }

            $folder = trim((string)($_GET['folder'] ?? 'INBOX'));
            $uid = isset($_GET['uid']) ? (int)$_GET['uid'] : 0;
            if ($uid <= 0) json_out(400, ['status' => 'error', 'message' => 'uid fehlt']);

            [$mailbox, $userImap, $passImap] = imap_config($db, $folder);

            $imap = @imap_open($mailbox, $userImap, $passImap, 0, 1);
            if (!$imap) {
                $err = imap_last_error() ?: 'Unbekannter IMAP Fehler';
                json_out(400, ['status' => 'error', 'message' => 'IMAP Verbindung fehlgeschlagen: ' . $err]);
            }

            $msgno = @imap_msgno($imap, $uid);
            if (!$msgno || $msgno <= 0) {
                imap_close($imap);
                json_out(404, ['status' => 'error', 'message' => 'Mail nicht gefunden (UID)']);
            }

            @imap_setflag_full($imap, (string)$msgno, "\\Seen");

            $h = @imap_headerinfo($imap, (int)$msgno);

            $subject = $h && isset($h->subject) ? (string)imap_utf8((string)$h->subject) : '(ohne Betreff)';
            $from = '';
            if ($h && !empty($h->from) && is_array($h->from)) {
                $f = $h->from[0];
                $name = trim((string)($f->personal ?? ''));
                $mail = trim((string)($f->mailbox ?? '')) . '@' . trim((string)($f->host ?? ''));
                $from = $name !== '' ? ($name . ' <' . $mail . '>') : $mail;
            }

            $to = '';
            if ($h && !empty($h->to) && is_array($h->to)) {
                $t = $h->to[0];
                $mail = trim((string)($t->mailbox ?? '')) . '@' . trim((string)($t->host ?? ''));
                $to = $mail;
            }

            $date = $h && isset($h->date) ? (string)$h->date : '';
            $body = extract_body($imap, (int)$msgno);

            imap_close($imap);

            json_out(200, [
                'status' => 'success',
                'data' => [
                    'uid' => $uid,
                    'msgno' => (int)$msgno,
                    'subject' => $subject,
                    'from' => $from,
                    'to' => $to,
                    'date' => $date,
                    'body' => [
                        'text' => (string)($body['text'] ?? ''),
                        'html' => (string)($body['html'] ?? ''),
                    ]
                ]
            ]);
        }

        case 'send': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                json_out(405, ['status' => 'error', 'message' => 'POST erforderlich']);
            }

            $raw = file_get_contents('php://input');
            $in = json_decode($raw ?: '', true);
            if (!is_array($in)) {
                json_out(400, ['status' => 'error', 'message' => 'Ungültiges JSON']);
            }

            $to = trim((string)($in['to'] ?? ''));
            $subject = trim((string)($in['subject'] ?? ''));
            $bodyText = (string)($in['body_text'] ?? ($in['body'] ?? ''));

            if ($to === '' || $subject === '' || trim($bodyText) === '') {
                json_out(400, ['status' => 'error', 'message' => 'An/Betreff/Text erforderlich']);
            }

            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                json_out(400, ['status' => 'error', 'message' => 'Empfänger Email ungültig']);
            }

            if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
                json_out(500, ['status' => 'error', 'message' => 'PHPMailer nicht gefunden (Composer).']);
            }

            [$driver, $host, $port, $enc, $authEnabled, $userSmtp, $passSmtp, $allowSelf, $fromEmail, $fromName, $replyTo] = smtp_config($db);

            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->CharSet = 'UTF-8';

            // Driver: smtp oder phpmail
            if ($driver === 'phpmail' || $host === '') {
                $mail->isMail();
            } else {
                $mail->isSMTP();
                $mail->Host = $host;
                $mail->Port = $port;
                $mail->SMTPAuth = $authEnabled;

                if ($authEnabled) {
                    $mail->Username = $userSmtp;
                    $mail->Password = $passSmtp;
                }

                if ($enc === 'tls') $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                elseif ($enc === 'ssl') $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                else $mail->SMTPSecure = false;

                if ($allowSelf) {
                    $mail->SMTPOptions = [
                        'ssl' => [
                            'verify_peer' => false,
                            'verify_peer_name' => false,
                            'allow_self_signed' => true,
                        ]
                    ];
                }
            }

            $mail->setFrom($fromEmail, $fromName);
            if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
                $mail->addReplyTo($replyTo);
            }

            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->isHTML(false);
            $mail->Body = $bodyText;

            $mail->send();

            json_out(200, ['status' => 'success', 'message' => 'Email gesendet']);
        }

        // --- Backward compat (dein alter UI-Code) ---
        case 'test_imap': {
            $_GET['action'] = 'health';
            // simple proxy:
            if (!function_exists('imap_open')) {
                json_out(500, ['status' => 'error', 'message' => 'PHP IMAP Extension fehlt (php-imap aktivieren).']);
            }
            [$mailbox, $userImap, $passImap] = imap_config($db, null);
            $imap = @imap_open($mailbox, $userImap, $passImap, 0, 1);
            if (!$imap) {
                $err = imap_last_error() ?: 'Unbekannter IMAP Fehler';
                json_out(400, ['status' => 'error', 'message' => 'IMAP Verbindung fehlgeschlagen: ' . $err]);
            }
            imap_close($imap);
            json_out(200, ['status' => 'success', 'message' => 'IMAP OK']);
        }

        case 'test_smtp': {
            if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
                json_out(500, ['status' => 'error', 'message' => 'PHPMailer nicht gefunden (Composer).']);
            }
            [, $host, $port, $enc, $authEnabled, $userSmtp, $passSmtp, $allowSelf] = smtp_config($db);

            if ($host === '') json_out(400, ['status' => 'error', 'message' => 'SMTP Host fehlt (smtp.host)']);

            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $host;
            $mail->Port = $port;
            $mail->SMTPAuth = $authEnabled;
            if ($authEnabled) {
                $mail->Username = $userSmtp;
                $mail->Password = $passSmtp;
            }

            if ($enc === 'tls') $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            elseif ($enc === 'ssl') $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            else $mail->SMTPSecure = false;

            if ($allowSelf) {
                $mail->SMTPOptions = [
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true,
                    ]
                ];
            }

            $ok = $mail->smtpConnect();
            $mail->smtpClose();

            if (!$ok) json_out(400, ['status' => 'error', 'message' => 'SMTP Verbindung fehlgeschlagen (smtpConnect=false)']);
            json_out(200, ['status' => 'success', 'message' => 'SMTP OK']);
        }

        // legacy mapping
        case 'inbox': {
            // map to list
            $_GET['action'] = 'list';
            // fallthrough intentionally
            $action = 'list';
            // handle by re-calling same switch is messy => quick direct:
            if (!function_exists('imap_open')) {
                json_out(500, ['status' => 'error', 'message' => 'PHP IMAP Extension fehlt (php-imap aktivieren).']);
            }
            $limit = isset($_GET['limit']) ? max(1, min(200, (int)$_GET['limit'])) : 40;
            $folder = trim((string)($_GET['folder'] ?? 'INBOX'));

            [$mailbox, $userImap, $passImap] = imap_config($db, $folder);

            $imap = @imap_open($mailbox, $userImap, $passImap, 0, 1);
            if (!$imap) {
                $err = imap_last_error() ?: 'Unbekannter IMAP Fehler';
                json_out(400, ['status' => 'error', 'message' => 'IMAP Verbindung fehlgeschlagen: ' . $err]);
            }

            $numMsg = (int)imap_num_msg($imap);
            $start = max(1, $numMsg - $limit + 1);

            $items = [];
            for ($i = $numMsg; $i >= $start; $i--) {
                $h = @imap_headerinfo($imap, $i);
                if (!$h) continue;

                $subject = isset($h->subject) ? (string)imap_utf8((string)$h->subject) : '(ohne Betreff)';
                $from = '';
                if (!empty($h->from) && is_array($h->from)) {
                    $f = $h->from[0];
                    $name = trim((string)($f->personal ?? ''));
                    $mailAddr = trim((string)($f->mailbox ?? '')) . '@' . trim((string)($f->host ?? ''));
                    $from = $name !== '' ? ($name . ' <' . $mailAddr . '>') : $mailAddr;
                }

                $date = isset($h->date) ? (string)$h->date : '';
                $seen = false;
                if (isset($h->Unseen)) $seen = ((int)$h->Unseen === 0);

                $items[] = [
                    'msgno' => $i,
                    'subject' => $subject,
                    'from' => $from,
                    'date' => $date,
                    'seen' => $seen,
                ];
            }

            imap_close($imap);

            json_out(200, [
                'status' => 'success',
                'data' => [
                    'items' => $items,
                    'count' => count($items),
                    'total' => $numMsg,
                ]
            ]);
        }

        case 'message': {
            // map old msgno to read by msgno (fallback)
            if (!function_exists('imap_open')) {
                json_out(500, ['status' => 'error', 'message' => 'PHP IMAP Extension fehlt (php-imap aktivieren).']);
            }

            $msgno = isset($_GET['msgno']) ? (int)$_GET['msgno'] : 0;
            if ($msgno <= 0) json_out(400, ['status' => 'error', 'message' => 'msgno fehlt']);

            $folder = trim((string)($_GET['folder'] ?? 'INBOX'));

            [$mailbox, $userImap, $passImap] = imap_config($db, $folder);

            $imap = @imap_open($mailbox, $userImap, $passImap, 0, 1);
            if (!$imap) {
                $err = imap_last_error() ?: 'Unbekannter IMAP Fehler';
                json_out(400, ['status' => 'error', 'message' => 'IMAP Verbindung fehlgeschlagen: ' . $err]);
            }

            @imap_setflag_full($imap, (string)$msgno, "\\Seen");
            $h = @imap_headerinfo($imap, $msgno);

            $subject = $h && isset($h->subject) ? (string)imap_utf8((string)$h->subject) : '(ohne Betreff)';
            $from = '';
            if ($h && !empty($h->from) && is_array($h->from)) {
                $f = $h->from[0];
                $name = trim((string)($f->personal ?? ''));
                $mailAddr = trim((string)($f->mailbox ?? '')) . '@' . trim((string)($f->host ?? ''));
                $from = $name !== '' ? ($name . ' <' . $mailAddr . '>') : $mailAddr;
            }
            $date = $h && isset($h->date) ? (string)$h->date : '';
            $body = extract_body($imap, $msgno);

            imap_close($imap);

            json_out(200, [
                'status' => 'success',
                'data' => [
                    'msgno' => $msgno,
                    'subject' => $subject,
                    'from' => $from,
                    'date' => $date,
                    'body' => ($body['text'] !== '' ? $body['text'] : $body['html']),
                ]
            ]);
        }

        default:
            json_out(400, ['status' => 'error', 'message' => 'Unbekannte action']);
    }

} catch (Throwable $e) {
    json_out(500, ['status' => 'error', 'message' => 'Serverfehler: ' . $e->getMessage()]);
}