<?php
/** Bookings list — the admin home page. */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_admin();

/* ── status change from the list ──────────────────────────── */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();

    $id     = (int) ($_POST['id'] ?? 0);
    $status = (string) ($_POST['status'] ?? '');

    if ($id > 0 && in_array($status, ['new', 'confirmed', 'declined', 'done'], true)) {
        db_run('UPDATE bookings SET status = ? WHERE id = ?', [$status, $id]);
        flash('Booking updated.');
    }
    redirect('index.php' . (isset($_POST['back']) ? '?' . (string) $_POST['back'] : ''));
}

/* ── filters ──────────────────────────────────────────────── */
$status = clean($_GET['status'] ?? '', 20);
$q      = clean($_GET['q'] ?? '', 80);
$page   = max(1, (int) ($_GET['page'] ?? 1));
$per    = 20;

$where  = [];
$params = [];

if (in_array($status, ['new', 'confirmed', 'declined', 'done'], true)) {
    $where[]  = 'b.status = ?';
    $params[] = $status;
}
if ($q !== '') {
    $where[]  = '(b.name LIKE ? OR b.email LIKE ? OR b.reference LIKE ?)';
    $like     = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int) (db_one("SELECT COUNT(*) AS n FROM bookings b {$whereSql}", $params)['n'] ?? 0);
$pages = max(1, (int) ceil($total / $per));
$page  = min($page, $pages);
$offset = ($page - 1) * $per;

$rows = db_all(
    "SELECT b.*, p.image_path AS preview
       FROM bookings b
       LEFT JOIN previews p ON p.booking_id = b.id
       {$whereSql}
   ORDER BY b.created_at DESC
      LIMIT {$per} OFFSET {$offset}",
    $params
);

$title    = 'Bookings';
$nav_page = 'bookings';
require __DIR__ . '/partials/header.php';

$qs = fn(array $extra = []) => '?' . http_build_query(array_filter(
    ['status' => $status, 'q' => $q] + $extra,
    fn($v) => $v !== '' && $v !== null
));
?>

<div class="head">
  <h1>Bookings <span class="muted"><?= $total ?></span></h1>

  <form class="filters" method="get">
    <input type="search" name="q" placeholder="Name, email or reference"
           value="<?= e($q) ?>">
    <select name="status" onchange="this.form.submit()">
      <option value="">All statuses</option>
      <?php foreach (['new' => 'New', 'confirmed' => 'Confirmed',
                      'declined' => 'Declined', 'done' => 'Done'] as $k => $label): ?>
        <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $label ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit">Search</button>
  </form>
</div>

<?php if (!$rows): ?>
  <p class="empty">No bookings<?= $status || $q ? ' match that filter' : ' yet' ?>.</p>
<?php else: ?>

<table class="tbl">
  <thead>
    <tr>
      <th>Reference</th><th>Client</th><th>Wants</th>
      <th>Date</th><th>Status</th><th>Received</th><th></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td>
        <a class="ref" href="booking.php?id=<?= (int) $r['id'] ?>"><?= e($r['reference']) ?></a>
        <?php if ($r['preview']): ?><span class="tag tag--cam">📷</span><?php endif; ?>
      </td>
      <td>
        <strong><?= e($r['name']) ?></strong><br>
        <a class="sub" href="mailto:<?= e($r['email']) ?>"><?= e($r['email']) ?></a>
      </td>
      <td>
        <span class="tag"><?= e($r['style']) ?></span>
        <?php if ($r['placement']): ?>
          <div class="sub"><?= e($r['placement']) ?></div>
        <?php endif; ?>
      </td>
      <td><?= $r['preferred_date'] ? e(date('j M Y', strtotime($r['preferred_date']))) : '—' ?></td>
      <td>
        <form method="post" class="statusform">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
          <input type="hidden" name="back" value="<?= e(http_build_query(['status' => $status, 'q' => $q, 'page' => $page])) ?>">
          <select name="status" class="st st--<?= e($r['status']) ?>" onchange="this.form.submit()">
            <?php foreach (['new' => 'New', 'confirmed' => 'Confirmed',
                            'declined' => 'Declined', 'done' => 'Done'] as $k => $label): ?>
              <option value="<?= $k ?>" <?= $r['status'] === $k ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      </td>
      <td class="sub"><?= e(date('j M, H:i', strtotime($r['created_at']))) ?></td>
      <td><a class="btn-sm" href="booking.php?id=<?= (int) $r['id'] ?>">Open</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<?php if ($pages > 1): ?>
  <nav class="pager">
    <?php for ($i = 1; $i <= $pages; $i++): ?>
      <a href="<?= e($qs(['page' => $i])) ?>" class="<?= $i === $page ? 'is-on' : '' ?>"><?= $i ?></a>
    <?php endfor; ?>
  </nav>
<?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
