<?php
/**
 * Creates the tables from schema.sql, then the admin account.
 *
 * For container hosts where you cannot easily open phpMyAdmin:
 *
 *     railway run php database/migrate.php
 *
 * Safe to run repeatedly — every statement is CREATE TABLE IF NOT EXISTS,
 * and the admin insert updates rather than duplicating.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Command line only.\n");
}

require_once __DIR__ . '/../includes/db.php';

$sql = file_get_contents(__DIR__ . '/schema.sql');
if ($sql === false) {
    exit("Could not read schema.sql\n");
}

// strip comments, then split on the statement terminator
$sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? '';
$statements = array_filter(array_map('trim', explode(';', $sql)));

$pdo = db();
$made = 0;

foreach ($statements as $stmt) {
    if ($stmt === '') {
        continue;
    }
    try {
        $pdo->exec($stmt);
        $made++;
    } catch (PDOException $e) {
        fwrite(STDERR, "Failed: " . substr($stmt, 0, 60) . "…\n  " . $e->getMessage() . "\n");
    }
}

echo "Ran {$made} statement(s).\n";

foreach (db_all('SHOW TABLES') as $row) {
    echo '  - ' . implode('', array_values($row)) . "\n";
}

// create the admin if the seed values are present
$email = (string) app_config('SEED_ADMIN_EMAIL', '');
$pass  = (string) app_config('SEED_ADMIN_PASSWORD', '');

if ($email !== '' && strlen($pass) >= 10 && $pass !== 'change-this-now') {
    db_run(
        'INSERT INTO admins (email, password_hash, name)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)',
        [$email, password_hash($pass, PASSWORD_DEFAULT),
         (string) app_config('SEED_ADMIN_NAME', 'Admin')]
    );
    echo "Admin ready: {$email}\n";
    echo "Now clear SEED_ADMIN_PASSWORD from the environment.\n";
} else {
    echo "\nNo admin created — set SEED_ADMIN_EMAIL and a SEED_ADMIN_PASSWORD\n";
    echo "of at least 10 characters, then run this again.\n";
}
