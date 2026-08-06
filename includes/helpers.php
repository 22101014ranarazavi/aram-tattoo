<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

/* ── output escaping ─────────────────────────────────────── */

/** Escape for HTML. Use this on EVERY value printed into a page. */
function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}


/* ── sessions ────────────────────────────────────────────── */

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,                       // JavaScript can't read it
        'samesite' => 'Lax',                      // blocks cross-site sends
        'secure'   => !empty($_SERVER['HTTPS']),  // HTTPS-only in production
    ]);
    session_name('aramsid');
    session_start();
}


/* ── CSRF ────────────────────────────────────────────────── */

/** Token proving a form was served by us, not a hostile site. */
function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_valid(?string $token): bool
{
    start_session();
    return !empty($_SESSION['csrf'])
        && is_string($token)
        && hash_equals($_SESSION['csrf'], $token);
}

/** Stop the request unless a valid CSRF token was posted. */
function csrf_check(): void
{
    if (!csrf_valid($_POST['_csrf'] ?? null)) {
        http_response_code(419);
        exit('Your session expired. Go back, reload the page and try again.');
    }
}


/* ── JSON API replies ────────────────────────────────────── */

function json_out(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_ok(array $payload = []): never
{
    json_out(['ok' => true] + $payload);
}

/** @param array<string,string> $fields field name => message */
function json_fail(string $message, int $status = 422, array $fields = []): never
{
    json_out(['ok' => false, 'error' => $message, 'fields' => $fields], $status);
}

/** Read a JSON body, falling back to a normal form post. */
function request_input(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if ($raw !== '' && str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'json')) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return $_POST;
}

function require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        json_fail('Method not allowed.', 405);
    }
}


/* ── input cleaning ──────────────────────────────────────── */

/** Trim, strip control characters, and cap the length. */
function clean(mixed $v, int $max = 255): string
{
    $s = is_scalar($v) ? (string) $v : '';
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
    $s = trim($s);
    return mb_substr($s, 0, $max);
}

function valid_email(string $email): bool
{
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL) && mb_strlen($email) <= 255;
}

/** Accepts YYYY-MM-DD and rejects impossible dates like 2026-02-31. */
function valid_date(string $date): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date;
}


/* ── client identity ─────────────────────────────────────── */

/** Packed binary IP for the VARBINARY(16) columns, or null. */
function client_ip_binary(): ?string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $packed = $ip !== '' ? @inet_pton($ip) : false;
    return $packed === false ? null : $packed;
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
}


/* ── rate limiting ───────────────────────────────────────── */

/**
 * True when this IP has made more than $max rows in $table in the last
 * $minutes. Cheap, needs no extra storage, and good enough to stop a bot
 * hammering the booking form.
 */
function too_many_recent(string $table, int $max, int $minutes): bool
{
    $allowed = ['bookings', 'subscribers', 'previews'];
    if (!in_array($table, $allowed, true)) {   // never interpolate raw input
        throw new InvalidArgumentException('Unknown table');
    }

    $ip = client_ip_binary();
    if ($ip === null) {
        return false;
    }

    $row = db_one(
        "SELECT COUNT(*) AS n FROM {$table}
          WHERE ip = ? AND created_at > (NOW() - INTERVAL ? MINUTE)",
        [$ip, $minutes]
    );

    return (int) ($row['n'] ?? 0) >= $max;
}


/* ── misc ────────────────────────────────────────────────── */

/** Short human-friendly booking reference, e.g. AT-7K3F9Q. */
function booking_reference(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';  // no I/O/0/1 to avoid confusion
    $out = '';
    for ($i = 0; $i < 6; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return 'AT-' . $out;
}

function slugify(string $text): string
{
    $text = mb_strtolower(trim($text));
    $text = preg_replace('/[^\p{L}\p{N}]+/u', '-', $text) ?? '';
    return trim($text, '-') ?: 'design';
}

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

/** One-shot message shown after a redirect. */
function flash(?string $msg = null, string $type = 'ok'): ?array
{
    start_session();
    if ($msg !== null) {
        $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}
