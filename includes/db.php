<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Single shared PDO connection.
 *
 * ERRMODE_EXCEPTION  — a failed query throws instead of returning false.
 * EMULATE_PREPARES=false — real server-side prepared statements, so values
 *                          are never interpolated into SQL. This is what
 *                          makes SQL injection impossible here.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    // Railway's MySQL service supplies MYSQL* variables. Falling back to them
    // means the app runs there with no config file at all.
    $host = (string) app_config('DB_HOST', app_config('MYSQLHOST', '127.0.0.1'));
    $port = (string) app_config('DB_PORT', app_config('MYSQLPORT', '3306'));
    $name = (string) app_config('DB_NAME', app_config('MYSQLDATABASE', 'arams_tattoo'));
    $user = (string) app_config('DB_USER', app_config('MYSQLUSER', 'root'));
    $pass = (string) app_config('DB_PASS', app_config('MYSQLPASSWORD', ''));
    $sock = app_config('DB_SOCKET');

    $dsn = $sock
        ? "mysql:unix_socket={$sock};dbname={$name};charset=utf8mb4"
        : "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        if (app_debug()) {
            throw $e;
        }
        error_log('DB connection failed: ' . $e->getMessage());
        http_response_code(503);
        exit('The site is temporarily unavailable. Please try again shortly.');
    }

    return $pdo;
}

/** Run a query with bound parameters and return the statement. */
function db_run(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/** First matching row, or null. */
function db_one(string $sql, array $params = []): ?array
{
    $row = db_run($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/** All matching rows. */
function db_all(string $sql, array $params = []): array
{
    return db_run($sql, $params)->fetchAll();
}
