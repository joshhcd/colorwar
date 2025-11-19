<?php
require_once 'db.php';
require_once __DIR__ . '/../../lib/bootstrap.php';

if (empty($_SESSION['saml_authenticated'])) {
  header('Location: ' . ('/../saml/login.php'));
  exit;
}
// Simple landing: user picks a puzzle; name/email are pulled from SAML when available
$puzzles = $pdo->query("SELECT id, title, width, height, created_at FROM puzzles ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$samlName = $_SESSION['saml_email'] ?? '';
$samlEmail = $_SESSION['saml_email'] ?? '';
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>WordSearch — Play</title>
  <link rel="stylesheet" href="assets/style.css"/>
</head>
<body>
<header class="topbar">
  <h1>WordSearch</h1>
</header>

<main class="container">
  <section class="card">
    <h2>Welcome Warrior <?= $samlName ? '' . htmlspecialchars($samlName) : '' ?></h2>
    <p>Choose a puzzle to begin.</p>
    <form method="post" action="play.php" class="grid2">
      <input type="hidden" name="name" value="<?= htmlspecialchars($samlName) ?>">
      <input type="hidden" name="email" value="<?= htmlspecialchars($samlEmail) ?>">
      <label>Puzzle<br>
        <select name="puzzle_id" required>
          <option value="">Choose a puzzle...</option>
          <?php foreach ($puzzles as $p): ?>
            <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['title']) ?> (<?= (int)$p['width'] ?>×<?= (int)$p['height'] ?>)</option>
          <?php endforeach; ?>
        </select>
      </label>
      <div class="row">
        <button class="btn" type="submit">Play</button>
      </div>
    </form>
  </section>

  <section class="card">
    <h2>Recent Puzzles</h2>
    <?php if (!$puzzles): ?>
      <p>No puzzles yet. Ask an admin to create one.</p>
    <?php else: ?>
    <ul class="list">
      <?php foreach ($puzzles as $p): ?>
        <li><strong><?= htmlspecialchars($p['title']) ?></strong> (<?= (int)$p['width'] ?>×<?= (int)$p['height'] ?>)</li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
