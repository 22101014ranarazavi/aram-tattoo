<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';   // e() is used by the templates below

/**
 * Sends mail either through PHP's mail() or a real SMTP server.
 *
 * mail() works out of the box on most shared hosting but often lands in
 * spam. Set MAIL_TRANSPORT=smtp with your provider's details (Gmail,
 * Brevo, Mailgun, your host's SMTP) for delivery that actually arrives.
 *
 * Never throws — a mail problem must not lose a client's booking. Failures
 * are written to the PHP error log and reported via the return value.
 */
function send_mail(
    string $to,
    string $subject,
    string $html,
    string $text = '',
    ?string $replyTo = null
): bool {
    $fromMail = (string) app_config('MAIL_FROM', 'no-reply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $fromName = (string) app_config('MAIL_FROM_NAME', "Aram's Tattoo");

    if ($text === '') {
        $text = trim(html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $html) ?? '')));
    }

    $boundary = 'b' . bin2hex(random_bytes(12));

    $headers = [
        'MIME-Version'              => '1.0',
        'Content-Type'              => "multipart/alternative; boundary=\"{$boundary}\"",
        'From'                      => mime_name($fromName) . " <{$fromMail}>",
        'Date'                      => date(DATE_RFC2822),
        'Message-ID'                => '<' . bin2hex(random_bytes(12)) . '@' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '>',
        'X-Mailer'                  => 'arams-tattoo',
        'Auto-Submitted'            => 'auto-generated',
    ];
    if ($replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $headers['Reply-To'] = $replyTo;
    }

    $body = "--{$boundary}\r\n"
          . "Content-Type: text/plain; charset=UTF-8\r\n"
          . "Content-Transfer-Encoding: base64\r\n\r\n"
          . chunk_split(base64_encode($text)) . "\r\n"
          . "--{$boundary}\r\n"
          . "Content-Type: text/html; charset=UTF-8\r\n"
          . "Content-Transfer-Encoding: base64\r\n\r\n"
          . chunk_split(base64_encode($html)) . "\r\n"
          . "--{$boundary}--\r\n";

    $encodedSubject = mime_name($subject);

    if (strtolower((string) app_config('MAIL_TRANSPORT', 'mail')) === 'smtp') {
        return smtp_send($fromMail, $to, $encodedSubject, $headers, $body);
    }

    $lines = [];
    foreach ($headers as $k => $v) {
        $lines[] = "{$k}: {$v}";
    }

    $sent = @mail($to, $encodedSubject, $body, implode("\r\n", $lines));
    if (!$sent) {
        error_log("send_mail: mail() failed for {$to}");
    }
    return $sent;
}

/** RFC 2047 encode anything non-ASCII so accents survive. */
function mime_name(string $s): string
{
    return preg_match('/[\x80-\xFF]/', $s)
        ? '=?UTF-8?B?' . base64_encode($s) . '?='
        : $s;
}

/**
 * Minimal SMTP client: STARTTLS or implicit TLS, AUTH LOGIN.
 * Enough for Gmail, Brevo, Mailgun, Postmark and most host SMTP.
 */
function smtp_send(string $from, string $to, string $subject, array $headers, string $body): bool
{
    $host   = (string) app_config('SMTP_HOST', '');
    $port   = (int) app_config('SMTP_PORT', 587);
    $user   = (string) app_config('SMTP_USER', '');
    $pass   = (string) app_config('SMTP_PASS', '');
    $secure = strtolower((string) app_config('SMTP_SECURE', 'tls'));   // tls | ssl | none

    if ($host === '') {
        error_log('smtp_send: SMTP_HOST is not set');
        return false;
    }

    $target = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);

    $fp = @stream_socket_client($target, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        error_log("smtp_send: cannot reach {$target} ({$errno} {$errstr})");
        return false;
    }
    stream_set_timeout($fp, 15);

    $read = function () use ($fp): string {
        $out = '';
        while (($line = fgets($fp, 515)) !== false) {
            $out .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        return $out;
    };
    $cmd = function (string $line, string $expect) use ($fp, $read): bool {
        fwrite($fp, $line . "\r\n");
        $res = $read();
        if (!str_starts_with($res, $expect)) {
            error_log('smtp_send: expected ' . $expect . ', got ' . trim($res));
            return false;
        }
        return true;
    };

    $ok = true;
    $read();                                             // greeting
    $ehlo = 'EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

    $ok = $ok && $cmd($ehlo, '250');

    if ($ok && $secure === 'tls') {
        $ok = $ok && $cmd('STARTTLS', '220');
        $ok = $ok && stream_socket_enable_crypto(
            $fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
        );
        $ok = $ok && $cmd($ehlo, '250');                 // must re-greet after TLS
    }

    if ($ok && $user !== '') {
        $ok = $ok && $cmd('AUTH LOGIN', '334');
        $ok = $ok && $cmd(base64_encode($user), '334');
        $ok = $ok && $cmd(base64_encode($pass), '235');
    }

    $ok = $ok && $cmd("MAIL FROM:<{$from}>", '250');
    $ok = $ok && $cmd("RCPT TO:<{$to}>", '250');
    $ok = $ok && $cmd('DATA', '354');

    if ($ok) {
        $lines = ["To: {$to}", "Subject: {$subject}"];
        foreach ($headers as $k => $v) {
            $lines[] = "{$k}: {$v}";
        }
        // A lone "." would end the message early, so escape it.
        $payload = implode("\r\n", $lines) . "\r\n\r\n"
                 . preg_replace('/^\./m', '..', $body);

        fwrite($fp, $payload . "\r\n.\r\n");
        $ok = str_starts_with($read(), '250');
    }

    @fwrite($fp, "QUIT\r\n");
    @fclose($fp);

    return $ok;
}


/* ── templates ───────────────────────────────────────────── */

function mail_layout(string $heading, string $inner): string
{
    $year = date('Y');
    return <<<HTML
<!DOCTYPE html><html><body style="margin:0;padding:24px;background:#04091c;
 font-family:Helvetica,Arial,sans-serif;color:#eaf5fd">
 <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
  style="max-width:560px;margin:0 auto;background:#0a1c47;border-radius:14px;
   border:1px solid rgba(165,216,243,.2)">
  <tr><td style="padding:28px 30px 8px">
    <div style="font:italic 22px Georgia,serif;color:#fff">Aram's</div>
    <div style="font-size:10px;letter-spacing:.42em;color:#7cc4ee;margin-top:4px">TATTOO</div>
  </td></tr>
  <tr><td style="padding:14px 30px 0">
    <h1 style="margin:0 0 14px;font-size:20px;color:#c4e6f8;font-weight:600">{$heading}</h1>
  </td></tr>
  <tr><td style="padding:0 30px 26px;font-size:14px;line-height:1.65;color:#cfe0f2">
    {$inner}
  </td></tr>
  <tr><td style="padding:16px 30px 26px;border-top:1px solid rgba(165,216,243,.15);
   font-size:11px;color:#9fb6d6">
    © {$year} Aram's Tattoo
  </td></tr>
 </table></body></html>
HTML;
}

/** Sent to the studio when a booking arrives. */
function mail_booking_to_studio(array $b, ?string $previewUrl): string
{
    $rows = '';
    foreach ([
        'Reference' => $b['reference'],
        'Name'      => $b['name'],
        'Email'     => $b['email'],
        'Phone'     => $b['phone'] ?: '—',
        'Date'      => $b['preferred_date'] ?: '—',
        'Style'     => $b['style'],
        'Placement' => ($b['placement'] ?? '') ?: '—',
    ] as $k => $v) {
        $rows .= '<tr><td style="padding:5px 0;color:#9fb6d6;width:110px">' . e($k)
               . '</td><td style="padding:5px 0;color:#fff">' . e((string) $v) . '</td></tr>';
    }

    $inner = '<table role="presentation" style="width:100%;font-size:14px">' . $rows . '</table>'
        . '<p style="margin:18px 0 6px;color:#9fb6d6">Their idea</p>'
        . '<div style="background:#071231;border-radius:10px;padding:14px;white-space:pre-wrap">'
        . e($b['idea']) . '</div>';

    if ($previewUrl !== null) {
        $inner .= '<p style="margin:18px 0 8px;color:#9fb6d6">They attached a camera preview</p>'
            . '<a href="' . e($previewUrl) . '"><img src="' . e($previewUrl)
            . '" alt="Preview" style="max-width:100%;border-radius:10px"></a>';
    }

    return mail_layout('New booking request', $inner);
}

/** Sent to the client as confirmation. */
function mail_booking_to_client(array $b): string
{
    $inner = '<p>Hi ' . e($b['name']) . ',</p>'
        . '<p>Thanks — your request is in. I read every one myself and will reply '
        . 'within 24 hours to sort out the details.</p>'
        . '<p style="margin:18px 0"><span style="color:#9fb6d6">Your reference</span><br>'
        . '<strong style="font-size:19px;color:#c4e6f8;letter-spacing:1px">'
        . e($b['reference']) . '</strong></p>'
        . '<p style="margin:16px 0 6px;color:#9fb6d6">What you sent</p>'
        . '<div style="background:#071231;border-radius:10px;padding:14px;white-space:pre-wrap">'
        . e($b['idea']) . '</div>'
        . '<p style="margin-top:20px">Nothing is booked until I confirm — no payment is '
        . 'needed yet.</p>';

    return mail_layout('Your request is in', $inner);
}
