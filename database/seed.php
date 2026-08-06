<?php
/**
 * Creates the first admin account. Run once from the command line:
 *
 *     php database/seed.php
 *
 * Reads SEED_ADMIN_* from includes/config.local.php. Safe to run again —
 * it updates the password rather than creating a duplicate.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this from the command line, not the browser.\n");
}

require_once __DIR__ . '/../includes/db.php';

$name  = (string) app_config('SEED_ADMIN_NAME', 'Admin');
$email = (string) app_config('SEED_ADMIN_EMAIL', '');
$pass  = (string) app_config('SEED_ADMIN_PASSWORD', '');

if ($email === '' || $pass === '') {
    exit("Set SEED_ADMIN_EMAIL and SEED_ADMIN_PASSWORD in includes/config.local.php first.\n");
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    exit("SEED_ADMIN_EMAIL is not a valid address.\n");
}
if (strlen($pass) < 10) {
    exit("Use a password of at least 10 characters.\n");
}
if ($pass === 'change-this-now') {
    exit("Change SEED_ADMIN_PASSWORD from the example value first.\n");
}

$hash = password_hash($pass, PASSWORD_DEFAULT);

db_run(
    'INSERT INTO admins (email, password_hash, name)
     VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), name = VALUES(name)',
    [$email, $hash, $name]
);

echo "Admin ready: {$email}\n";
echo "Log in at /admin/login.php\n";
echo "Now delete or empty SEED_ADMIN_PASSWORD in config.local.php.\n";
