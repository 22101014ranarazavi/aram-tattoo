<?php
/**
 * GET /api/designs.php — published portfolio pieces, newest ordering first.
 *
 * Optional query:
 *   ?style=Blackwork   filter by style
 *   ?limit=12          1–48, default 24
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    json_fail('Method not allowed.', 405);
}

$limit = (int) ($_GET['limit'] ?? 24);
$limit = max(1, min(48, $limit));

$style = clean($_GET['style'] ?? '', 40);

$sql = 'SELECT title, slug, style, description, image_path, price_from
          FROM designs
         WHERE is_published = 1';
$params = [];

if ($style !== '') {
    $sql .= ' AND style = ?';
    $params[] = $style;
}

// LIMIT can't be a bound parameter in MySQL prepared statements, so it is
// cast to int and clamped above — never taken raw from the query string.
$sql .= ' ORDER BY sort_order ASC, created_at DESC LIMIT ' . $limit;

try {
    $rows = db_all($sql, $params);
} catch (Throwable $e) {
    error_log('designs.php: ' . $e->getMessage());
    json_fail('Could not load designs.', 500);
}

$base = app_url();
foreach ($rows as &$r) {
    $r['image_url'] = $base . '/' . ltrim($r['image_path'], '/');
    $r['price_from'] = $r['price_from'] !== null ? (float) $r['price_from'] : null;
    unset($r['image_path']);
}
unset($r);

header('Cache-Control: public, max-age=120');
json_ok(['designs' => $rows]);
