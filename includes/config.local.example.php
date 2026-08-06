<?php
/**
 * Copy this file to config.local.php and fill it in.
 *
 *     cp includes/config.local.example.php includes/config.local.php
 *
 * config.local.php holds your real passwords — never commit it and never
 * let it be served. The .htaccess in this folder blocks web access to it.
 */

return [
    /* ── database ───────────────────────────────────────── */
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '3306',
    'DB_NAME' => 'arams_tattoo',
    'DB_USER' => 'root',
    'DB_PASS' => '',
    // 'DB_SOCKET' => '/tmp/mysql.sock',   // only if your host uses a socket

    /* ── site ───────────────────────────────────────────── */
    'APP_URL'      => 'http://localhost:8790',   // no trailing slash
    'APP_TIMEZONE' => 'Europe/Istanbul',
    'APP_DEBUG'    => true,                      // MUST be false on the live site

    /* ── where booking notifications go ─────────────────── */
    'STUDIO_EMAIL' => 'hello@aramstattoo.com',

    /* ── sending mail ───────────────────────────────────── */
    // 'mail' uses PHP's built-in mail() — works on most shared hosting but
    // often lands in spam. Switch to 'smtp' and fill in the block below for
    // delivery that actually arrives.
    'MAIL_TRANSPORT' => 'mail',
    'MAIL_FROM'      => 'no-reply@aramstattoo.com',
    'MAIL_FROM_NAME' => "Aram's Tattoo",

    // 'MAIL_TRANSPORT' => 'smtp',
    // 'SMTP_HOST'   => 'smtp-relay.brevo.com',
    // 'SMTP_PORT'   => 587,
    // 'SMTP_SECURE' => 'tls',        // tls (587) | ssl (465) | none
    // 'SMTP_USER'   => '',
    // 'SMTP_PASS'   => '',

    /* ── first admin account, used once by database/seed.php ── */
    'SEED_ADMIN_NAME'     => 'Aram',
    'SEED_ADMIN_EMAIL'    => 'aram@aramstattoo.com',
    'SEED_ADMIN_PASSWORD' => 'change-this-now',
];
