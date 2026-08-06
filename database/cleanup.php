<?php
/**
 * Housekeeping. Run occasionally, or nightly from cron:
 *
 *     0 3 * * *  php /path/to/aram-tattoo/database/cleanup.php
 *
 * Deletes camera previews nobody attached to a booking (someone tried the
 * tool and left) and old login-attempt rows. Previews that belong to a
 * booking are never touched.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Command line only.\n");
}

require_once __DIR__ . '/../includes/db.php';

const KEEP_UNCLAIMED_DAYS = 2;
const KEEP_ATTEMPT_DAYS   = 30;

$stale = db_all(
    'SELECT id, image_path FROM previews
      WHERE booking_id IS NULL
        AND created_at < (NOW() - INTERVAL ? DAY)',
    [KEEP_UNCLAIMED_DAYS]
);

$removed = 0;
foreach ($stale as $p) {
    $file = dirname(__DIR__) . '/' . $p['image_path'];
    if (is_file($file)) {
        @unlink($file);
    }
    db_run('DELETE FROM previews WHERE id = ?', [$p['id']]);
    $removed++;
}

$attempts = db_run(
    'DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL ? DAY)',
    [KEEP_ATTEMPT_DAYS]
)->rowCount();

echo "Removed {$removed} unclaimed preview(s) and {$attempts} old login attempt(s).\n";
