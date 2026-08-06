<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

const LOGIN_MAX_ATTEMPTS = 8;    // per IP
const LOGIN_WINDOW_MIN   = 15;   // minutes

/** The logged-in admin row, or null. */
function current_admin(): ?array
{
    start_session();
    $id = $_SESSION['admin_id'] ?? null;
    if (!$id) {
        return null;
    }

    static $admin = null;
    if ($admin === null) {
        $admin = db_one('SELECT id, email, name FROM admins WHERE id = ?', [(int) $id]);
        if ($admin === null) {          // account deleted while logged in
            admin_logout();
        }
    }
    return $admin;
}

/** Put at the top of every admin page. */
function require_admin(): array
{
    $admin = current_admin();
    if ($admin === null) {
        redirect('login.php');
    }
    return $admin;
}

function login_throttled(): bool
{
    $ip = client_ip_binary();
    if ($ip === null) {
        return false;
    }

    $row = db_one(
        'SELECT COUNT(*) AS n FROM login_attempts
          WHERE ip = ? AND succeeded = 0
            AND attempted_at > (NOW() - INTERVAL ? MINUTE)',
        [$ip, LOGIN_WINDOW_MIN]
    );

    return (int) ($row['n'] ?? 0) >= LOGIN_MAX_ATTEMPTS;
}

function record_login_attempt(string $email, bool $ok): void
{
    db_run(
        'INSERT INTO login_attempts (ip, email, succeeded) VALUES (?, ?, ?)',
        [client_ip_binary(), mb_substr($email, 0, 255), $ok ? 1 : 0]
    );
}

/**
 * Verify credentials and start the session.
 * Returns an error message, or null on success.
 */
function admin_login(string $email, string $password): ?string
{
    if (login_throttled()) {
        return 'Too many attempts. Wait ' . LOGIN_WINDOW_MIN . ' minutes and try again.';
    }

    $admin = db_one('SELECT * FROM admins WHERE email = ?', [$email]);

    // Always run a hash comparison, even when the account doesn't exist, so
    // the response takes the same time either way and can't be used to work
    // out which addresses are real.
    $hash = $admin['password_hash']
        ?? '$2y$12$usesomesillystringfore7hnbRJHxXVLeakoG8K30M1p1DR.HuUk2';

    if (!password_verify($password, $hash) || $admin === null) {
        record_login_attempt($email, false);
        return 'Those details do not match an account.';
    }

    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        db_run('UPDATE admins SET password_hash = ? WHERE id = ?',
            [password_hash($password, PASSWORD_DEFAULT), $admin['id']]);
    }

    start_session();
    session_regenerate_id(true);          // stops session fixation
    $_SESSION['admin_id'] = (int) $admin['id'];

    db_run('UPDATE admins SET last_login_at = NOW() WHERE id = ?', [$admin['id']]);
    record_login_attempt($email, true);

    return null;
}

function admin_logout(): void
{
    start_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], (bool) $p['secure'], (bool) $p['httponly']);
    }
    session_destroy();
}
