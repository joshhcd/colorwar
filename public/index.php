<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';

if (empty($_SESSION['saml_authenticated'])) {
  header('Location: ' . (BASE_URL . '/saml/login.php'));
  exit;
}

// Fetch teams with totals (user points + team direct points)
$teams = q("
SELECT t.id, t.name, t.slug, t.color,
  COALESCE((SELECT SUM(p.amount) FROM points p WHERE p.team_id = t.id),0) +
  COALESCE((SELECT SUM(p2.amount) FROM points p2
           JOIN users u ON u.id = p2.user_id
           WHERE u.team_id = t.id),0) AS total_points,
  (SELECT COUNT(1) FROM users u WHERE u.team_id = t.id AND u.is_active = 1) AS members
FROM teams t
ORDER BY total_points DESC, t.name ASC
")->fetchAll();

// Top users
$users = q("
SELECT u.id, u.name, u.email, t.name AS team, t.color,
       COALESCE(SUM(p.amount),0) AS pts
FROM users u
LEFT JOIN points p ON p.user_id = u.id
LEFT JOIN teams t ON u.team_id = t.id
WHERE u.is_active = 1
GROUP BY u.id
ORDER BY pts DESC, u.name ASC

")->fetchAll();


?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= e(APP_NAME) ?></title>
  <link rel="stylesheet" href="<?= e(BASE_URL) ?>/style.css">
</head>
<body>
<div class="container">
  <div class="topbar">
      <img src="<insertlogo>" style="width:250px">
    <div class="brand"><span>Color</span> War Dashboard</div>
    <div class="toolbar">
     <!--  <a class="button secondary" href="<?= e(BASE_URL) ?>/saml/logout.php">SSO Logout</a> -->
    </div>
  </div>

  <div class="board">
    <h2>Teams</h2>
    <div class="grid">
      <?php foreach ($teams as $t): ?>
        <?php $badge = $t['color'] ?: '#38405f'; ?>
        <div class="card team-card">
          <h3><?= e($t['name']) ?></h3>
          <div class="meta"><?= (int)$t['members'] ?> members</div>
          <div class="team-badge" style="background:<?= e($badge) ?>22;border-color:<?= e($badge) ?>;color:#fff">
            <?= e($t['slug']) ?>
          </div>
          <div class="team-score"><?= (int)$t['total_points'] ?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <h2 style="margin-top:30px">Top Users</h2>
    <div class="card">
      <table>
        <thead>
          <tr><th>User</th><th>Team</th><th>Points</th></tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u): ?>
          <tr>
            <td><?= e($u['name'] ?: $u['email']) ?></td>
            <td>
              <span class="team-badge" style="background:<?= e($u['color'] ?: '#38405f') ?>22;border-color:<?= e($u['color'] ?: '#38405f') ?>;color:#fff">
                <?= e($u['team'] ?: '—') ?>
              </span>
            </td>
            <td><strong><?= (int)$u['pts'] ?></strong></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>


  </div>

  <footer>© <?= date('Y') ?> · <?= e(APP_NAME) ?></footer>
</div>
</body>
</html>
