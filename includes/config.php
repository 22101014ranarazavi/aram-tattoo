<?php
/**
 * Reads settings from includes/config.local.php, falling back to real
 * environment variables so the site also works on hosts that set secrets
 * that way. config.local.php is git-ignored and never leaves your server.
 */
declare(strict_types=1);

function app_config(string $key, $default = null)
{
    static $local = null;

    if ($local === null) {
        $path = __DIR__ . '/config.local.php';
        $local = file_exists($path) ? require $path : [];
    }

    if (array_key_exists($key, $local) && $local[$key] !== '') {
        return $local[$key];
    }

    $env = getenv($key);
    if ($env !== false && $env !== '') {
        return $env;
    }

    return $default;
}

/** True while developing locally — shows real errors instead of a generic page. */
function app_debug(): bool
{
    return filter_var(app_config('APP_DEBUG', false), FILTER_VALIDATE_BOOL);
}

/** Absolute site URL with no trailing slash, e.g. https://aramstattoo.com */
function app_url(): string
{
    $configured = app_config('APP_URL');
    if ($configured) {
        return rtrim((string) $configured, '/');
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return ($https ? 'https://' : 'http://') . $host;
}

/** Filesystem path to the site root (the folder holding index.html). */
function app_root(): string
{
    return dirname(__DIR__);
}

error_reporting(E_ALL);
ini_set('display_errors', app_debug() ? '1' : '0');
date_default_timezone_set((string) app_config('APP_TIMEZONE', 'UTC'));
