<?php
/** One booking: full detail, status, private notes, camera preview. */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_admin();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    redirect('index.php');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();

    if (isset($_POST['delete'])) {
        // previews cascade via the foreign key; clean the files up too
        foreach (db_all('SELECT image_path FROM previews WHERE booking_id = ?', [$id]) as $p) {
            @unlink(app_root() . '/' . $p['image_path']);
        }
        db_run('DELETE FROM bookings WHERE id = ?', [$id]);
        flash('Booking deleted.');
        redirect('index.php');
    }

    $status = (string) ($_POST['status'] ?? 'new');
    if (!in_array($status, ['new', 'confirmed', 'declined', 'done'], true)) {
        $status = 'new';
    }

    db_run('UPDATE bookings SET status = ?, admin_notes = ? WHERE id = ?', [
        $status,
        clean($_POST['admin_notes'] ?? '', 4000),
        $id,
    ]);

    flash('Saved.');
    redirect('booking.php?id=' . $id);
}

$b = db_one('SELECT * FROM bookings WHERE id = ?', [$id]);
if ($b === null) {
    flash('That booking no longer exists.', 'err');
    redirect('index.php');
}

$previews = db_all('SELECT * FROM previews WHERE booking_id = ? ORDER BY created_at', [$id]);

$title    = $b['reference'];
$nav_page = 'bookings';
require __DIR__ . '/partials/header.php';
?>

<p class="crumb"><a href="index.php">← All bookings</a></p>

<div class="head">
  <h1><?= e($b['name']) ?> <span class="muted"><?= e($b['reference']) ?></span></h1>
  <span class="st st--<?= e($b['status']) ?> st--badge"><?= e(ucfirst($b['status'])) ?></span>
</div>

<div class="cols">
  <section class="card">
    <h2>The request</h2>

    <dl class="dl">
      <dt>Email</dt><dd><a href="mailto:<?= e($b['email']) ?>"><?= e($b['email']) ?></a></dd>
      <dt>Phone</dt><dd><?= $b['phone'] ? e($b['phone']) : '—' ?></dd>
      <dt>Preferred date</dt>
      <dd><?= $b['preferred_date'] ? e(date('l j F Y', strtotime($b['preferred_date']))) : '—' ?></dd>
      <dt>Style</dt><dd><?= e($b['style']) ?></dd>
      <dt>Placement</dt>
      <dd><?= $b['placement'] ? e($b['placement']) : '<span class="sub">not specified</span>' ?></dd>
      <dt>Received</dt><dd><?= e(date('j M Y, H:i', strtotime($b['created_at']))) ?></dd>
    </dl>

    <h3>Their idea</h3>
    <div class="quote"><?= nl2br(e($b['idea'])) ?></div>

    <?php if ($previews): ?>
      <h3>Camera preview</h3>
      <div class="shots">
        <?php foreach ($previews as $p): ?>
          <figure>
            <a href="../<?= e($p['image_path']) ?>" target="_blank" rel="noopener">
              <img src="../<?= e($p['image_path']) ?>" alt="Preview the client made">
            </a>
            <?php if ($p['size_cm'] !== null): ?>
              <figcaption>They sized it at <strong><?= e(rtrim(rtrim((string) $p['size_cm'], '0'), '.')) ?> cm</strong> wide</figcaption>
            <?php endif; ?>
          </figure>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <aside class="card">
    <h2>Manage</h2>

    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">

      <label for="status">Status</label>
      <select name="status" id="status">
        <?php foreach (['new' => 'New', 'confirmed' => 'Confirmed',
                        'declined' => 'Declined', 'done' => 'Done'] as $k => $label): ?>
          <option value="<?= $k ?>" <?= $b['status'] === $k ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
      </select>

      <label for="admin_notes">Private notes</label>
      <textarea name="admin_notes" id="admin_notes" rows="7"
        placeholder="Quoted 3 hours, deposit taken…"><?= e($b['admin_notes'] ?? '') ?></textarea>
      <p class="hint">Only you see these — never sent to the client.</p>

      <button type="submit">Save</button>
    </form>

    <hr>

    <a class="btn-wide" href="mailto:<?= e($b['email']) ?>?subject=<?= rawurlencode("Your tattoo booking {$b['reference']}") ?>">
      Reply by email
    </a>

    <form method="post" onsubmit="return confirm('Delete this booking permanently? This cannot be undone.')">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
      <button type="submit" name="delete" value="1" class="danger">Delete booking</button>
    </form>
  </aside>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
