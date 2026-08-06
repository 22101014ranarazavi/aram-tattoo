<?php
/** Newsletter list, with a CSV export for your mail tool. */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_admin();

/* ── CSV export ───────────────────────────────────────────── */
if (isset($_GET['export'])) {
    $rows = db_all('SELECT email, created_at FROM subscribers
                     WHERE unsubscribed_at IS NULL ORDER BY created_at DESC');

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="subscribers-' . date('Y-m-d') . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['email', 'subscribed_at']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['email'], $r['created_at']]);
    }
    fclose($out);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $id = (int) ($_POST['id'] ?? 0);

    if ($id > 0 && isset($_POST['remove'])) {
        db_run('DELETE FROM subscribers WHERE id = ?', [$id]);
        flash('Removed.');
    }
    redirect('subscribers.php');
}

$rows   = db_all('SELECT * FROM subscribers ORDER BY created_at DESC LIMIT 500');
$active = count(array_filter($rows, fn($r) => $r['unsubscribed_at'] === null));

$title    = 'Subscribers';
$nav_page = 'subscribers';
require __DIR__ . '/partials/header.php';
?>

<div class="head">
  <h1>Subscribers <span class="muted"><?= $active ?> active</span></h1>
  <?php if ($rows): ?>
    <a class="btn-sm" href="subscribers.php?export=1">Export CSV</a>
  <?php endif; ?>
</div>

<?php if (!$rows): ?>
  <p class="empty">Nobody has signed up yet.</p>
<?php else: ?>
  <table class="tbl">
    <thead><tr><th>Email</th><th>Joined</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><a href="mailto:<?= e($r['email']) ?>"><?= e($r['email']) ?></a></td>
        <td class="sub"><?= e(date('j M Y', strtotime($r['created_at']))) ?></td>
        <td>
          <?php if ($r['unsubscribed_at']): ?>
            <span class="tag">Unsubscribed</span>
          <?php else: ?>
            <span class="tag tag--ok">Active</span>
          <?php endif; ?>
        </td>
        <td>
          <form method="post" onsubmit="return confirm('Remove this address?')">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <button class="btn-sm danger" type="submit" name="remove" value="1">Remove</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
