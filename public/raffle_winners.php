<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';

if (empty($_SESSION['saml_authenticated'])) {
  header('Location: ' . (BASE_URL . '/saml/login.php'));
  exit;
}

$raffles = [
  [
    'title' => 'Headphone Raffle Winner 🎧',
    'url'   => '',
  ],
  [
    'title' => 'Gift Card Raffle #1 💳',
    'url'   => '',
  ],
  [
    'title' => 'Gift Card Raffle #2 💳',
    'url'   => '',
  ],
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Colorwar Raffle Winners  Cybersecurity Awareness Month</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="style.css">
</head>
<body>
<header>
  <h1>🎉 Colorwar Raffle Winners </h1>
  <p class="muted">Thank you for participating in Cybersecurity Awareness Month!</p>
</header>

<main class="grid" style="grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));">
  <?php foreach ($raffles as $r): ?>
    <section class="card" style="text-align:center;">
      <h2><?= htmlspecialchars($r['title']) ?></h2>
      <div style="
        position:relative;
        padding-bottom:56.25%;
        height:0;
        overflow:hidden;
        border-radius:12px;
        box-shadow:0 4px 12px rgba(0,0,0,0.3);
      ">
        <iframe 
          src="<?= htmlspecialchars($r['url']) ?>" 
          frameborder="0" 
          allowfullscreen 
          title="Winner!"
          style="position:absolute;top:0;left:0;width:100%;height:100%;border:0;">
        </iframe>
      </div>
    </section>
  <?php endforeach; ?>
</main>

<footer>
  <a href="index.php">← Back to Dashboard</a>
</footer>
</body>
</html>
