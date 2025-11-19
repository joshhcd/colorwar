<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';
require_admin();

$errors = [];
$info = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $event = trim($_POST['match_event'] ?? '');
        $points = (int)($_POST['default_points'] ?? 1);
        $target = $_POST['target'] === 'team' ? 'team' : 'user';
        $team_slug = trim($_POST['team_slug'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        if ($name === '' || $event === '') $errors[] = "Name and event are required";

        $team_id = null;
        if ($target === 'team') {
            if ($team_slug === '') $errors[] = "Team slug required for team target";
            else {
                $t = q("SELECT id FROM teams WHERE slug=?", [$team_slug])->fetch();
                if (!$t) $errors[] = "Team not found";
                else $team_id = (int)$t['id'];
            }
        }

        if (!$errors) {
            $token = bin2hex(random_bytes(24));
            q("INSERT INTO integrations (name, token, is_enabled, match_event, default_points, target, team_id, description) VALUES (?,?,?,?,?,?,?,?)",
              [$name, $token, 1, $event, $points, $target, $team_id, $desc ?: null]);
            q("INSERT INTO audit_log (actor_user_id, action, entity_type, details) VALUES (?,?,?,?)",
              [current_user()['id'], 'create', 'integration', json_encode(['name'=>$name,'event'=>$event])]);
            redirect(BASE_URL . '/admin/integrations.php');
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $enabled = (int)($_POST['is_enabled'] ?? 0) ? 1 : 0;
        q("UPDATE integrations SET is_enabled=? WHERE id=?", [$enabled, $id]);
        redirect(BASE_URL . '/admin/integrations.php');
    } elseif ($action === 'regen') {
        $id = (int)($_POST['id'] ?? 0);
        $token = bin2hex(random_bytes(24));
        q("UPDATE integrations SET token=? WHERE id=?", [$token, $id]);
        redirect(BASE_URL . '/admin/integrations.php');
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        q("DELETE FROM integrations WHERE id=?", [$id]);
        redirect(BASE_URL . '/admin/integrations.php');
    }
}

$ints = q("
SELECT i.*, t.slug AS team_slug
FROM integrations i
LEFT JOIN teams t ON t.id = i.team_id
ORDER BY i.id DESC
")->fetchAll();

$base = rtrim(BASE_URL, '/');
$webhookBase = $base . '/api/webhook.php?token=';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Integrations · <?= e(APP_NAME) ?></title>
  <link rel="stylesheet" href="<?= e(BASE_URL) ?>/style.css">
</head>
<body>
<div class="container">
  <div class="topbar">
    <div class="brand"><span>Integrations</span></div>
    <div class="toolbar">
      <a class="button secondary" href="<?= e(BASE_URL) ?>/admin/">Back</a>
    </div>
  </div>

  <?php if ($errors): ?><div class="warn"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

  <div class="card">
    <h3>Create Webhook Rule</h3>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px">
        <div><label>Name<br><input name="name" required style="width:100%;padding:10px;border-radius:8px;border:1px solid #243049;background:#0f1320;color:#fff"></label></div>
        <div><label>Event Match<br><input name="match_event" placeholder="e.g. phishing_report" required style="width:100%;padding:10px;border-radius:8px;border:1px solid #243049;background:#0f1320;color:#fff"></label></div>
        <div><label>Default Points (±int)<br><input name="default_points" type="number" value="1" style="width:100%;padding:10px;border-radius:8px;border:1px solid #243049;background:#0f1320;color:#fff"></label></div>
        <div><label>Target<br>
          <select name="target" style="width:100%;padding:10px;border-radius:8px;border:1px solid #243049;background:#0f1320;color:#fff">
            <option value="user">User</option>
            <option value="team">Team</option>
          </select></label></div>
        <div><label>Team Slug (target=team)<br><input name="team_slug" placeholder="e.g. red-team" style="width:100%;padding:10px;border-radius:8px;border:1px solid #243049;background:#0f1320;color:#fff"></label></div>
      </div>
      <div style="margin-top:10px"><label>Description<br><input name="description" style="width:100%;padding:10px;border-radius:8px;border:1px solid #243049;background:#0f1320;color:#fff"></label></div>
      <div style="margin-top:10px"><button class="button">Create</button></div>
    </form>
  </div>

  <div class="card" style="margin-top:20px">
    <h3>Existing Integrations</h3>
    <table>
      <thead><tr><th>Name</th><th>Event</th><th>Default Points</th><th>Target</th><th>Team</th><th>Enabled</th><th>Token</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($ints as $i): ?>
        <tr>
          <td><?= e($i['name']) ?></td>
          <td><code><?= e($i['match_event'] ?: '—') ?></code></td>
          <td><?= (int)$i['default_points'] ?></td>
          <td><?= e($i['target']) ?></td>
          <td><?= e($i['team_slug'] ?: '—') ?></td>
          <td><?= $i['is_enabled'] ? 'Yes' : 'No' ?></td>
          <td style="max-width:280px;word-break:break-all">
            <code><?= e($i['token']) ?></code><br>
            <small>POST JSON to: <code><?= e($webhookBase . $i['token']) ?></code></small>
          </td>
          <td>
            <form method="post" style="display:inline-block">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
              <input type="hidden" name="is_enabled" value="<?= $i['is_enabled'] ? '0' : '1' ?>">
              <button class="button secondary"><?= $i['is_enabled'] ? 'Disable' : 'Enable' ?></button>
            </form>
            <form method="post" style="display:inline-block;margin-left:6px">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="regen">
              <input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
              <button class="button secondary">New Token</button>
            </form>
            <form method="post" style="display:inline-block;margin-left:6px" onsubmit="return confirm('Delete integration?')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
              <button class="button" style="background:#9b1c31">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <div class="notice" style="margin-top:12px">
      Example cURL (user points):
      <pre style="white-space:pre-wrap">
curl -X POST "<?= e($webhookBase) ?>YOUR_TOKEN" \
 -H "Content-Type: application/json" \
 -d '{"event":"phishing_report","email":"user@example.com","points":1,"reason":"Reported phishing","meta":{"source":"KnowBe4"}}'
      </pre>
    </div>
  </div>
</div>
</body>
</html>
