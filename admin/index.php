<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';
require_admin();

$u = current_user();
$org = q("SELECT org_name FROM settings LIMIT 1")->fetchColumn();
$teamCount = (int)q("SELECT COUNT(1) FROM teams")->fetchColumn();
$userCount = (int)q("SELECT COUNT(1) FROM users")->fetchColumn();
$ptsTotal = (int)q("SELECT COALESCE(SUM(amount),0) FROM points")->fetchColumn();
$intCount = (int)q("SELECT COUNT(1) FROM integrations")->fetchColumn();

// Recent point events
$recent = q("
SELECT p.created_at, p.amount, p.reason, p.source,
       u.name AS user_name, t.name AS team_name, t.color AS team_color
FROM points p
LEFT JOIN users u ON u.id = p.user_id
LEFT JOIN teams t ON t.id = p.team_id
ORDER BY p.id DESC
LIMIT 15
")->fetchAll();


?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Admin · <?= e(APP_NAME) ?></title>
  <link rel="stylesheet" href="<?= e(BASE_URL) ?>/style.css">
</head>
<body>
<div class="container">
  <div class="topbar">
    <div class="brand"><span>Admin</span> Dashboard</div>
    <div class="toolbar">
      <a class="button secondary" href="<?= e(BASE_URL) ?>/admin/teams.php">Teams</a>
      <a class="button secondary" href="<?= e(BASE_URL) ?>/admin/users.php">Users</a>
      <a class="button secondary" href="<?= e(BASE_URL) ?>/admin/points.php">Points</a>
      <a class="button secondary" href="<?= e(BASE_URL) ?>/admin/integrations.php">Integrations</a>
      <a class="button secondary" href="<?= e(BASE_URL) ?>/admin/audit.php">Audit</a>
      <a class="button" href="<?= e(BASE_URL) ?>/admin/logout.php">Logout</a>
    </div>
  </div>

  <div class="grid">
    <div class="card">
      <h3>Organization</h3>
      <div><?= e($org ?: '—') ?></div>
      <div style="color:#9fb0ca;font-size:12px">Signed in as <?= e($u['email']) ?> (<?= e($u['role']) ?>)</div>
    </div>
    <div class="card">
      <h3>Teams</h3>
      <div style="font-size:26px;font-weight:800"><?= $teamCount ?></div>
    </div>
    <div class="card">
      <h3>Users</h3>
      <div style="font-size:26px;font-weight:800"><?= $userCount ?></div>
    </div>
    <div class="card">
      <h3>Total Points</h3>
      <div style="font-size:26px;font-weight:800"><?= $ptsTotal ?></div>
    </div>
    <div class="card">
      <h3>Integrations</h3>
      <div style="font-size:26px;font-weight:800"><?= $intCount ?></div>
    </div>
  </div>

  <div class="card" style="margin-top:20px">
    <h3>Quick Award</h3>
    <form method="post" action="<?= e(BASE_URL) ?>/api/award.php">
      <?= csrf_field() ?>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px">
        <div><label>User Email (optional)<br><input name="email" style="width:100%;padding:10px;border-radius:8px;border:1px solid #243049;background:#0f1320;color:#fff"></label></div>
        <div><label>Team Slug (optional)<br><input name="team_slug" style="width:100%;padding:10px;border-radius:8px;border:1px solid #243049;background:#0f1320;color:#fff"></label></div>
        <div><label>Points (±int)<br><input name="amount" type="number" required value="1" style="width:100%;padding:10px;border-radius:8px;border:1px solid #243049;background:#0f1320;color:#fff"></label></div>
        <div><label>Reason<br><input name="reason" style="width:100%;padding:10px;border-radius:8px;border:1px solid #243049;background:#0f1320;color:#fff"></label></div>
      </div>
      <div style="margin-top:10px">
        <button class="button">Apply</button>
        <span style="color:#8aa1c2;margin-left:10px">Either user email or team slug is required.</span>
      </div>
    </form>
  </div>
  
      <h2 style="margin-top:30px">Recent Activity</h2>
    <div class="card">
      <?php if (!$recent): ?>
        <div class="notice">No point changes yet.</div>
      <?php else: ?>
        <table>
          <thead><tr><th>When</th><th>Delta</th><th>Who/Team</th><th>Reason</th><th>Source</th></tr></thead>
          <tbody>
          <?php foreach ($recent as $r): ?>
            <tr>
              <td><?= e($r['created_at']) ?></td>
              <td><?= e(fmt_points((int)$r['amount'])) ?></td>
              <td>
                <?php if ($r['user_name']): ?>
                  <?= e($r['user_name']) ?>
                <?php elseif ($r['team_name']): ?>
                  <span class="team-badge" style="background:<?= e($r['team_color'] ?: '#38405f') ?>22;border-color:<?= e($r['team_color'] ?: '#38405f') ?>;color:#fff">
                    <?= e($r['team_name']) ?>
                  </span>
                <?php else: ?>
                  —
                <?php endif; ?>
              </td>
              <td><?= e($r['reason'] ?: '') ?></td>
              <td><?= e($r['source'] ?: '') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
    
</div>
</body>
</html>
