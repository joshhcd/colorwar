<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';

$err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = post('email', '');
    $password = (string)($_POST['password'] ?? '');
    if (login($email, $password)) {
        redirect(BASE_URL . '/admin/index.php');
    } else {
        $err = 'Invalid credentials';
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Admin Login · <?= e(APP_NAME) ?></title>
  <link rel="stylesheet" href="<?= e(BASE_URL) ?>/style.css">
</head>
<body>
<div class="container">
  <div class="topbar">
    <div class="brand"><span>Admin</span> Console</div>
  </div>
  <div class="card" style="max-width:520px;margin:0 auto">
    <h2>Sign in</h2>
    <?php if ($flash): ?><div class="notice"><?= e($flash) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="warn"><?= e($err) ?></div><?php endif; ?>
    <form method="post">
      <label>Email<br><input name="email" type="email" required style="width:100%;padding:10px;border-radius:8px;border:1px solid #243049;background:#0f1320;color:#fff"></label><br><br>
      <label>Password<br><input name="password" type="password" required style="width:100%;padding:10px;border-radius:8px;border:1px solid #243049;background:#0f1320;color:#fff"></label><br><br>
      <button class="button">Login</button>
      <a class="button secondary" href="<?= e(BASE_URL) ?>/">Back</a>
    </form>
  </div>
</div>
</body>
</html>
