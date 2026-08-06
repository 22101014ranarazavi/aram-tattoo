<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';

$admin = require_admin();
$nav_page = $nav_page ?? '';   // not $page — list screens use that for pagination
$title    = $title ?? 'Admin';

$counts = db_one(
    "SELECT
        SUM(status = 'new')       AS new_count,
        SUM(status = 'confirmed') AS confirmed_count,
        COUNT(*)                  AS total
     FROM bookings"
) ?? [];

$newCount = (int) ($counts['new_count'] ?? 0);
$f = flash();

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> — Aram's Tattoo</title>
<link rel="stylesheet" href="../assets/css/admin.css?v=1">
</head>
<body>

<header class="abar">
  <a class="abar__brand" href="index.php">
    <span class="abar__mark">A</span>
    <span>Aram's <b>Admin</b></span>
  </a>

  <nav class="abar__nav">
    <a href="index.php"       class="<?= $nav_page === 'bookings'    ? 'is-on' : '' ?>">
      Bookings<?= $newCount ? ' <span class="pill">' . $newCount . '</span>' : '' ?>
    </a>
    <a href="designs.php"     class="<?= $nav_page === 'designs'     ? 'is-on' : '' ?>">Designs</a>
    <a href="subscribers.php" class="<?= $nav_page === 'subscribers' ? 'is-on' : '' ?>">Subscribers</a>
  </nav>

  <div class="abar__right">
    <a href="../index.html" target="_blank" rel="noopener" class="abar__site">View site ↗</a>
    <span class="abar__who"><?= e($admin['name']) ?></span>
    <a href="logout.php" class="abar__out">Log out</a>
  </div>
</header>

<main class="awrap">
<?php if ($f): ?>
  <div class="flash flash--<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
<?php endif; ?>
