<?php
/**
 * POST /api/book.php — receives the booking form.
 *
 * Accepts JSON or a normal form post. Replies with JSON:
 *   { ok:true, reference:"AT-7K3F9Q" }
 *   { ok:false, error:"…", fields:{ email:"…" } }
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/mailer.php';

require_post();

$in = request_input();

/* Honeypot: a field hidden from people but filled in by naive bots.
   Pretend it worked so the bot doesn't learn anything. */
if (clean($in['website'] ?? '') !== '') {
    json_ok(['reference' => booking_reference()]);
}

/* ── validate ─────────────────────────────────────────────── */
$name  = clean($in['name']  ?? '', 120);
$email = clean($in['email'] ?? '', 255);
$phone = clean($in['phone'] ?? '', 40);
$date  = clean($in['date']  ?? '', 10);
$style = clean($in['style'] ?? 'Not sure yet', 40);
$place = clean($in['placement'] ?? '', 60);
$idea  = clean($in['idea']  ?? '', 4000);
$token = clean($in['preview_token'] ?? '', 32);

$errors = [];

if (mb_strlen($name) < 2)      $errors['name']  = 'Please tell me your name.';
if (!valid_email($email))      $errors['email'] = 'That email address does not look right.';
if (mb_strlen($idea) < 10)     $errors['idea']  = 'Tell me a little more about the idea.';

if ($date !== '') {
    if (!valid_date($date)) {
        $errors['date'] = 'That date is not valid.';
    } elseif ($date < date('Y-m-d')) {
        $errors['date'] = 'Please pick a date in the future.';
    }
}

$allowedStyles = ['Fine Line', 'Blackwork', 'Realism', 'Cover-up', 'Not sure yet'];
if (!in_array($style, $allowedStyles, true)) {
    $style = 'Not sure yet';
}

if ($errors) {
    json_fail('Please check the highlighted fields.', 422, $errors);
}

/* ── rate limit ───────────────────────────────────────────── */
if (too_many_recent('bookings', 5, 60)) {
    json_fail('That is a lot of requests from one place. Try again in an hour, or email me directly.', 429);
}

/* ── store ────────────────────────────────────────────────── */
$pdo = db();
$pdo->beginTransaction();

try {
    // reference is unique; on the astronomically unlikely clash, try again
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $reference = booking_reference();
        try {
            db_run(
                'INSERT INTO bookings
                   (reference, name, email, phone, preferred_date, style, placement, idea, ip, user_agent)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $reference, $name, $email,
                    $phone !== '' ? $phone : null,
                    $date  !== '' ? $date  : null,
                    $style,
                    $place !== '' ? $place : null,
                    $idea,
                    client_ip_binary(),
                    mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                ]
            );
            break;
        } catch (PDOException $e) {
            if ($e->errorInfo[1] !== 1062 || $attempt === 4) {   // 1062 = duplicate key
                throw $e;
            }
        }
    }

    $bookingId = (int) $pdo->lastInsertId();

    // Attach the camera preview, if they took one. Only an unclaimed token counts.
    $preview = null;
    if ($token !== '' && ctype_xdigit($token)) {
        $preview = db_one(
            'SELECT id, image_path FROM previews WHERE token = ? AND booking_id IS NULL',
            [$token]
        );
        if ($preview) {
            db_run('UPDATE previews SET booking_id = ? WHERE id = ?', [$bookingId, $preview['id']]);
        }
    }

    $pdo->commit();

} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('book.php: ' . $e->getMessage());
    json_fail('Something went wrong saving your request. Please try again, or email me directly.', 500);
}

/* ── notify ───────────────────────────────────────────────── */
// Mail must never sink a booking that is already safely stored.
try {
    $booking = compact('reference', 'name', 'email', 'phone', 'style', 'idea')
        + ['preferred_date' => $date, 'placement' => $place];

    $previewUrl = $preview ? app_url() . '/' . ltrim($preview['image_path'], '/') : null;

    $studio = (string) app_config('STUDIO_EMAIL', app_config('MAIL_FROM', ''));
    if ($studio !== '') {
        send_mail(
            $studio,
            "New booking {$reference} — {$name}",
            mail_booking_to_studio($booking, $previewUrl),
            '',
            $email                       // replying goes straight to the client
        );
    }

    send_mail($email, "We got your request ({$reference}) — Aram's Tattoo",
        mail_booking_to_client($booking));

} catch (Throwable $e) {
    error_log('book.php mail: ' . $e->getMessage());
}

json_ok([
    'reference' => $reference,
    'message'   => "Thanks {$name} — request sent. Your reference is {$reference}.",
]);
