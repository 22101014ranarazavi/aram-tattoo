<?php
/**
 * Router for PHP's built-in server:  php -S 127.0.0.1:8791 router.php
 *
 * The built-in server ignores .htaccess, so the rules that protect
 * /includes, /database and /uploads on Apache do nothing locally. This
 * reproduces them, which matters the moment you expose the dev server
 * through a tunnel.
 *
 * Not used in production — real Apache reads the .htaccess files instead.
 */
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Source and schema are never web content.
if (preg_match('#^/(includes|database)(/|$)#i', $path)) {
    http_response_code(403);
    exit('Forbidden');
}

// Uploads are data, never code.
if (preg_match('#^/uploads/.*\.(php|phtml|php\d|phar|cgi|pl|py|sh|htaccess)$#i', $path)) {
    http_response_code(403);
    exit('Forbidden');
}

// Anything else: let the built-in server serve it normally.
return false;
