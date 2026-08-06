<?php
/**
 * POST /api/upload-preview.php — stores a Virtual Camera snapshot.
 *
 * Called before the booking exists, so it returns a one-time token the
 * booking form sends back to link the two together.
 *
 * Accepts either
 *   multipart/form-data  with file field "image"
 *   application/json     with { image: "data:image/png;base64,…" }
 * plus an optional size_cm.
 *
 * Replies { ok:true, token:"…", url:"…" }
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';

require_post();

const MAX_UPLOAD_BYTES = 6 * 1024 * 1024;   // 6 MB
const MAX_EDGE         = 2000;              // px, longest side after resize

/* ── read the image bytes ─────────────────────────────────── */
$bytes = null;

if (!empty($_FILES['image']['tmp_name']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
    if (($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        json_fail('That upload did not complete. Try again.', 422);
    }
    if (($_FILES['image']['size'] ?? 0) > MAX_UPLOAD_BYTES) {
        json_fail('That image is too large (6 MB max).', 413);
    }
    $bytes = file_get_contents($_FILES['image']['tmp_name']) ?: null;

} else {
    $in  = request_input();
    $raw = (string) ($in['image'] ?? '');

    if (preg_match('#^data:image/(png|jpeg|jpg|webp);base64,#i', $raw, $m)) {
        $b64 = substr($raw, strpos($raw, ',') + 1);
        if (strlen($b64) > MAX_UPLOAD_BYTES * 1.4) {      // base64 is ~33% bigger
            json_fail('That image is too large (6 MB max).', 413);
        }
        $bytes = base64_decode($b64, true) ?: null;
    }
}

if ($bytes === null || $bytes === '') {
    json_fail('No image received.', 422);
}
if (strlen($bytes) > MAX_UPLOAD_BYTES) {
    json_fail('That image is too large (6 MB max).', 413);
}

/* ── prove it really is an image ──────────────────────────── */
$info = @getimagesizefromstring($bytes);
if ($info === false) {
    json_fail('That file is not a readable image.', 422);
}

$allowed = [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP];
if (!in_array($info[2], $allowed, true)) {
    json_fail('Please use a PNG, JPG or WEBP.', 422);
}

// A collapsed canvas produces a sliver of pixels that is useless to the
// studio. Reject it here too, so a broken client can never fill the
// database with unusable previews.
if ($info[0] < 120 || $info[1] < 120) {
    json_fail('That preview came through too small to use. Try the shutter again.', 422);
}

if (too_many_recent('previews', 25, 60)) {
    json_fail('Too many uploads from here. Try again later.', 429);
}

/* ── re-encode ────────────────────────────────────────────────
   Never write the uploaded bytes to disk as-is. Decoding to a raw
   bitmap and re-encoding discards EXIF, trailing data and anything
   smuggled inside the file — only real pixels survive.            */
if (!function_exists('imagecreatefromstring')) {
    error_log('upload-preview.php: GD extension is not installed');
    json_fail('The server cannot process images right now.', 500);
}

$img = @imagecreatefromstring($bytes);
if ($img === false) {
    json_fail('That image could not be read.', 422);
}

$w = imagesx($img);
$h = imagesy($img);

if ($w > MAX_EDGE || $h > MAX_EDGE) {
    $scale = MAX_EDGE / max($w, $h);
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));
    $resized = imagecreatetruecolor($nw, $nh);
    imagealphablending($resized, false);
    imagesavealpha($resized, true);
    imagecopyresampled($resized, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($img);
    $img = $resized;
    $w = $nw;
    $h = $nh;
}

$name = bin2hex(random_bytes(16)) . '.jpg';
$rel  = 'uploads/previews/' . $name;
$abs  = app_root() . '/' . $rel;

if (!is_dir(dirname($abs)) && !@mkdir(dirname($abs), 0755, true)) {
    error_log('upload-preview.php: cannot create ' . dirname($abs));
    json_fail('The server could not store the image.', 500);
}

// Flatten onto white — a camera shot has no transparency worth keeping,
// and JPEG keeps these small.
$flat = imagecreatetruecolor($w, $h);
imagefilledrectangle($flat, 0, 0, $w, $h, imagecolorallocate($flat, 255, 255, 255));
imagecopy($flat, $img, 0, 0, 0, 0, $w, $h);
$saved = imagejpeg($flat, $abs, 86);

imagedestroy($img);
imagedestroy($flat);

if (!$saved) {
    error_log('upload-preview.php: imagejpeg failed for ' . $abs);
    json_fail('The server could not store the image.', 500);
}
@chmod($abs, 0644);

/* ── record it ────────────────────────────────────────────── */
$sizeCm = $_POST['size_cm'] ?? (request_input()['size_cm'] ?? null);
$sizeCm = is_numeric($sizeCm) ? round((float) $sizeCm, 2) : null;
if ($sizeCm !== null && ($sizeCm <= 0 || $sizeCm > 200)) {
    $sizeCm = null;
}

$token = bin2hex(random_bytes(16));

try {
    db_run(
        'INSERT INTO previews (token, image_path, size_cm, ip) VALUES (?, ?, ?, ?)',
        [$token, $rel, $sizeCm, client_ip_binary()]
    );
} catch (Throwable $e) {
    @unlink($abs);
    error_log('upload-preview.php: ' . $e->getMessage());
    json_fail('Could not save the preview.', 500);
}

json_ok([
    'token' => $token,
    'url'   => app_url() . '/' . $rel,
]);
