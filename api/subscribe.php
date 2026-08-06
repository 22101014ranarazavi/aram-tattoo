<?php
/**
 * POST /api/subscribe.php — newsletter / aftercare list.
 * Re-subscribing an address that previously opted out simply reactivates it.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';

require_post();

$in = request_input();

if (clean($in['website'] ?? '') !== '') {      // honeypot
    json_ok(['message' => "You're on the list — welcome aboard."]);
}

$email = clean($in['email'] ?? '', 255);

if (!valid_email($email)) {
    json_fail('That email address does not look right.', 422, ['email' => 'Check this address.']);
}

if (too_many_recent('subscribers', 10, 60)) {
    json_fail('Too many sign-ups from here. Try again later.', 429);
}

try {
    // Insert, or revive a previously unsubscribed row. The unique index on
    // email makes this safe against duplicates and races.
    db_run(
        'INSERT INTO subscribers (email, token, ip)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE unsubscribed_at = NULL',
        [$email, bin2hex(random_bytes(16)), client_ip_binary()]
    );
} catch (Throwable $e) {
    error_log('subscribe.php: ' . $e->getMessage());
    json_fail('Could not sign you up just now. Please try again.', 500);
}

json_ok(['message' => "You're on the list — welcome aboard."]);
