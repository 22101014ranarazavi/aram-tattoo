<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

if (current_admin() !== null) {
    redirect('index.php');
}

$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();

    $email = clean($_POST['email'] ?? '', 255);
    $pass  = (string) ($_POST['password'] ?? '');

    $error = admin_login($email, $pass);

    if ($error === null) {
        redirect('index.php');
    }
}

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Log in — Aram's Tattoo Admin</title>
<link rel="stylesheet" href="../assets/css/admin.css?v=1">
</head>
<body class="login-body">

<form class="login" method="post" autocomplete="on">
  <div class="login__mark">A</div>
  <h1>Aram's <b>Admin</b></h1>
  <p class="login__sub">Sign in to manage bookings and designs.</p>

  <?php if ($error): ?>
    <div class="flash flash--err"><?= e($error) ?></div>
  <?php endif; ?>

  <?= csrf_field() ?>

  <label for="email">Email</label>
  <input type="email" id="email" name="email" required autofocus
         autocomplete="username"
         value="<?= e($_POST['email'] ?? '') ?>">

  <label for="password">Password</label>
  <input type="password" id="password" name="password" required
         autocomplete="current-password">

  <button type="submit">Log in</button>
</form>

</body>
</html>
