<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';
require_admin();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $color = trim($_POST['color'] ?? '');
        if ($name === '') $errors[] = "Name required";
        if (!$errors) {
            $slug = slugify($name);
            try {
                q("INSERT INTO teams (name, slug, color) VALUES (?,?,?)", [$name, $slug, $color ?: null]);
                q("INSERT INTO audit_log (actor_user_id, action, entity_type, details) VALUES (?,?,?,?)",
                  [current_user()['id'], 'create', 'team', json_encode(['name'=>$name,'slug'=>$slug])]);
                redirect(BASE_URL . '/admin/teams.php');
            } catch (Throwable $e) {
                $errors[] = "Create failed: " . $e->getMessage();
            }
        }
    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $color = trim($_POST['color'] ?? '');
        if ($id <= 0 || $name === '') $errors[] = "Invalid input";
        if (!$errors) {
            $slug = slugify($name);
            try {
                q("UPDATE teams SET name=?, slug=?, color=? WHERE id=?", [$name, $slug, $color ?: null, $id]);
                q("INSERT INTO audit_log (actor_user_id, action, entity_type, entity_id, details) VALUES (?,?,?,?,?)",
                  [current_user()['id'], 'update', 'team', $id, json_encode(['name'=>$name,'slug'=>$slug,'color'=>$color])]);
                redirect(BASE_URL . '/admin/teams.php');
            } catch (Throwable $e) {
                $errors[] = "Update failed: " . $e->getMessage();
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) $errors[] = "Invalid ID";
        if (!$errors) {
            try {
                q("DELETE FROM teams WHERE id=?", [$id]);
                q("INSERT INTO audit_log (actor_user_id, action, entity_type, entity_id) VALUES (?,?,?,?)",
                  [current_user()['id'], 'delete', 'team', $id]);
                redirect(BASE_URL . '/admin/teams.php');
            } catch (Throwable $e) {
                $errors[] = "Delete failed: " . $e->getMessage();
            }
        }
    }
}

$teams = q("SELECT * FROM teams ORDER BY name ASC")->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Teams · <?= e(APP_NAME) ?></title>
  <link rel="stylesheet" href="<?= e(BASE_URL) ?>/style.css">
</head>
<body>
<div class="container">
  <div class="topbar">
    <div class="brand"><span>Teams</span></div>
    <div class="toolbar">
      <a class="button secondary" href="<?= e(BASE_URL) ?>/admin/">Back</a>
    </div>
  </div>
  <?php if ($errors): ?>
    <div class="warn"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <div class="grid">
    <div class="card">
      <h3>Create Team</h3>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <label>Name<br><input name="name" required style="width:100%;padding:10px;border-radius:8px;border:1px solid #243049;background:#0f1320;color:#fff"></label><br><br>
        <label>Color (hex)<br><input name="color" placeholder="#e80a4d" style="width:100%;padding:10px;border-radius:8px;border:1px solid #243049;background:#0f1320;color:#fff"></label><br><br>
        <button class="button">Add</button>
      </form>
    </div>
    <div class="card">
      <h3>Existing Teams</h3>
      <table>
        <thead><tr><th>Name</th><th>Slug</th><th>Color</th><th>Actions</th></tr></thead>
        <tbody>
          <?php foreach ($teams as $t): ?>
            <tr>
              <td><?= e($t['name']) ?></td>
              <td><?= e($t['slug']) ?></td>
              <td><span class="team-badge" style="background:<?= e($t['color'] ?: '#38405f') ?>22;border-color:<?= e($t['color'] ?: '#38405f') ?>;color:#fff"><?= e($t['color'] ?: '—') ?></span></td>
              <td>
                <details>
                  <summary class="button secondary">Edit/Delete</summary>
                  <div style="margin-top:8px">
                    <form method="post" style="margin-bottom:8px">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="update">
                      <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                      <input name="name" value="<?= e($t['name']) ?>" style="padding:6px;border-radius:6px;border:1px solid #243049;background:#0f1320;color:#fff">
                      <input name="color" value="<?= e($t['color'] ?: '') ?>" style="padding:6px;border-radius:6px;border:1px solid #243049;background:#0f1320;color:#fff">
                      <button class="button">Save</button>
                    </form>
                    <form method="post" onsubmit="return confirm('Delete this team? Users will remain but without a team.')">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                      <button class="button" style="background:#9b1c31">Delete</button>
                    </form>
                  </div>
                </details>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
</body>
</html>
