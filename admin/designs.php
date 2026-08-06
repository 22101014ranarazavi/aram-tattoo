<?php
/** Portfolio manager — add, edit, reorder, publish and delete designs. */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_admin();

const DESIGN_STYLES = ['Fine Line', 'Blackwork', 'Realism', 'Cover-up', 'Other'];
const MAX_IMAGE_BYTES = 8 * 1024 * 1024;
const MAX_IMAGE_EDGE  = 1600;

/**
 * Validate and re-encode an uploaded image, returning its relative path.
 * Re-encoding is what makes this safe: only real pixels survive, so a file
 * with PHP smuggled inside it cannot reach the disk intact.
 */
function store_design_image(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [null, 'That upload did not complete.'];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return [null, 'Invalid upload.'];
    }
    if ($file['size'] > MAX_IMAGE_BYTES) {
        return [null, 'Image is too large (8 MB max).'];
    }

    $info = @getimagesize($file['tmp_name']);
    if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        return [null, 'Please upload a JPG, PNG or WEBP.'];
    }
    if (!function_exists('imagecreatefromstring')) {
        return [null, 'The server has no image support (GD is missing).'];
    }

    $img = @imagecreatefromstring((string) file_get_contents($file['tmp_name']));
    if ($img === false) {
        return [null, 'That image could not be read.'];
    }

    $w = imagesx($img);
    $h = imagesy($img);
    if ($w > MAX_IMAGE_EDGE || $h > MAX_IMAGE_EDGE) {
        $s  = MAX_IMAGE_EDGE / max($w, $h);
        $nw = max(1, (int) round($w * $s));
        $nh = max(1, (int) round($h * $s));
        $dst = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);
        $img = $dst;
        $w = $nw;
        $h = $nh;
    }

    $flat = imagecreatetruecolor($w, $h);
    imagefilledrectangle($flat, 0, 0, $w, $h, imagecolorallocate($flat, 255, 255, 255));
    imagecopy($flat, $img, 0, 0, 0, 0, $w, $h);

    $rel = 'uploads/designs/' . bin2hex(random_bytes(16)) . '.jpg';
    $abs = app_root() . '/' . $rel;
    if (!is_dir(dirname($abs))) {
        @mkdir(dirname($abs), 0755, true);
    }

    $ok = imagejpeg($flat, $abs, 88);
    imagedestroy($img);
    imagedestroy($flat);

    if (!$ok) {
        return [null, 'Could not save the image.'];
    }
    @chmod($abs, 0644);

    return [$rel, null];
}

$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $id     = (int) ($_POST['id'] ?? 0);

    if ($action === 'delete' && $id > 0) {
        $d = db_one('SELECT image_path FROM designs WHERE id = ?', [$id]);
        if ($d) {
            @unlink(app_root() . '/' . $d['image_path']);
            db_run('DELETE FROM designs WHERE id = ?', [$id]);
            flash('Design deleted.');
        }
        redirect('designs.php');
    }

    if ($action === 'toggle' && $id > 0) {
        db_run('UPDATE designs SET is_published = 1 - is_published WHERE id = ?', [$id]);
        redirect('designs.php');
    }

    if ($action === 'save') {
        $titleIn = clean($_POST['title'] ?? '', 160);
        $style   = clean($_POST['style'] ?? 'Other', 40);
        $desc    = clean($_POST['description'] ?? '', 1200);
        $price   = $_POST['price_from'] ?? '';
        $price   = is_numeric($price) ? round((float) $price, 2) : null;
        $sort    = (int) ($_POST['sort_order'] ?? 0);
        $pub     = isset($_POST['is_published']) ? 1 : 0;

        if (!in_array($style, DESIGN_STYLES, true)) {
            $style = 'Other';
        }
        if (mb_strlen($titleIn) < 2) {
            $error = 'Give the piece a title.';
        }

        [$imagePath, $imgErr] = $error ? [null, null] : store_design_image($_FILES['image'] ?? []);
        if ($imgErr) {
            $error = $imgErr;
        }

        if (!$error) {
            // slug must stay unique; add a suffix if the title repeats
            $base = slugify($titleIn);
            $slug = $base;
            $n = 2;
            while (db_one('SELECT id FROM designs WHERE slug = ? AND id <> ?', [$slug, $id])) {
                $slug = $base . '-' . $n++;
            }

            if ($id > 0) {
                $existing = db_one('SELECT image_path FROM designs WHERE id = ?', [$id]);
                if ($existing === null) {
                    flash('That design no longer exists.', 'err');
                    redirect('designs.php');
                }
                if ($imagePath !== null) {
                    @unlink(app_root() . '/' . $existing['image_path']);
                }
                db_run(
                    'UPDATE designs
                        SET title=?, slug=?, style=?, description=?, price_from=?,
                            is_published=?, sort_order=?'
                    . ($imagePath !== null ? ', image_path=?' : '') .
                    ' WHERE id=?',
                    $imagePath !== null
                        ? [$titleIn, $slug, $style, $desc, $price, $pub, $sort, $imagePath, $id]
                        : [$titleIn, $slug, $style, $desc, $price, $pub, $sort, $id]
                );
                flash('Design updated.');
            } else {
                if ($imagePath === null) {
                    $error = 'Choose an image for the new design.';
                } else {
                    db_run(
                        'INSERT INTO designs
                           (title, slug, style, description, image_path, price_from, is_published, sort_order)
                         VALUES (?,?,?,?,?,?,?,?)',
                        [$titleIn, $slug, $style, $desc, $imagePath, $price, $pub, $sort]
                    );
                    flash('Design added.');
                }
            }

            if (!$error) {
                redirect('designs.php');
            }
        }
    }
}

$editing = null;
if (($eid = (int) ($_GET['edit'] ?? 0)) > 0) {
    $editing = db_one('SELECT * FROM designs WHERE id = ?', [$eid]);
}

$designs = db_all('SELECT * FROM designs ORDER BY sort_order ASC, created_at DESC');

$title    = 'Designs';
$nav_page = 'designs';
require __DIR__ . '/partials/header.php';
?>

<div class="head">
  <h1>Designs <span class="muted"><?= count($designs) ?></span></h1>
</div>

<?php if ($error): ?><div class="flash flash--err"><?= e($error) ?></div><?php endif; ?>

<div class="cols cols--wide">

  <section class="card">
    <h2><?= $editing ? 'Edit design' : 'Add a design' ?></h2>

    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">

      <label for="title">Title</label>
      <input type="text" id="title" name="title" required maxlength="160"
             value="<?= e($editing['title'] ?? '') ?>">

      <label for="style">Style</label>
      <select name="style" id="style">
        <?php foreach (DESIGN_STYLES as $s): ?>
          <option value="<?= e($s) ?>" <?= ($editing['style'] ?? '') === $s ? 'selected' : '' ?>><?= e($s) ?></option>
        <?php endforeach; ?>
      </select>

      <label for="description">Description</label>
      <textarea name="description" id="description" rows="3"
                maxlength="1200"><?= e($editing['description'] ?? '') ?></textarea>

      <div class="row2">
        <div>
          <label for="price_from">Price from</label>
          <input type="number" id="price_from" name="price_from" step="0.01" min="0"
                 value="<?= e($editing['price_from'] ?? '') ?>">
        </div>
        <div>
          <label for="sort_order">Order</label>
          <input type="number" id="sort_order" name="sort_order"
                 value="<?= e((string) ($editing['sort_order'] ?? 0)) ?>">
        </div>
      </div>

      <label for="image">Photo <?= $editing ? '<span class="hint">— leave empty to keep the current one</span>' : '' ?></label>
      <input type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp"
             <?= $editing ? '' : 'required' ?>>

      <?php if ($editing): ?>
        <img class="thumbnail" src="../<?= e($editing['image_path']) ?>" alt="">
      <?php endif; ?>

      <label class="check">
        <input type="checkbox" name="is_published" value="1"
               <?= (!$editing || $editing['is_published']) ? 'checked' : '' ?>>
        Show on the website
      </label>

      <button type="submit"><?= $editing ? 'Save changes' : 'Add design' ?></button>
      <?php if ($editing): ?>
        <a class="btn-wide" href="designs.php">Cancel</a>
      <?php endif; ?>
    </form>
  </section>

  <section class="card">
    <h2>Portfolio</h2>

    <?php if (!$designs): ?>
      <p class="empty">Nothing yet. Add your first piece on the left — it will
         appear on the website straight away.</p>
    <?php else: ?>
      <div class="gallery">
        <?php foreach ($designs as $d): ?>
          <figure class="gitem <?= $d['is_published'] ? '' : 'is-hidden' ?>">
            <img src="../<?= e($d['image_path']) ?>" alt="<?= e($d['title']) ?>" loading="lazy">
            <figcaption>
              <strong><?= e($d['title']) ?></strong>
              <span class="sub"><?= e($d['style']) ?><?= $d['price_from'] !== null
                ? ' · from ' . e(number_format((float) $d['price_from'], 0)) : '' ?></span>
            </figcaption>
            <div class="gactions">
              <a class="btn-sm" href="designs.php?edit=<?= (int) $d['id'] ?>">Edit</a>

              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                <button class="btn-sm" type="submit"><?= $d['is_published'] ? 'Hide' : 'Show' ?></button>
              </form>

              <form method="post" onsubmit="return confirm('Delete <?= e(addslashes($d['title'])) ?>?')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                <button class="btn-sm danger" type="submit">Delete</button>
              </form>
            </div>
          </figure>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
