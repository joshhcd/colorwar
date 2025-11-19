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
        $email = trim($_POST['email'] ?? '');
        $team_slug = trim($_POST['team_slug'] ?? '');
        $external = trim($_POST['external_user_id'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Valid email required";
        if (!$errors) {
            $team_id = null;
            if ($team_slug !== '') {
                $team = q("SELECT id FROM teams WHERE slug=?", [$team_slug])->fetch();
                if (!$team) {
                    // auto-create
                    q("INSERT INTO teams (name, slug) VALUES (?,?)", [$team_slug, $team_slug]);
                    $team_id = (int)db()->lastInsertId();
                } else { $team_id = (int)$team['id']; }
            }
            try {
                q("INSERT INTO users (name, email, team_id, external_user_id) VALUES (?,?,?,?)",
                  [$name ?: null, $email, $team_id, $external ?: null]);
                q("INSERT INTO audit_log (actor_user_id, action, entity_type, details) VALUES (?,?,?,?)",
                  [current_user()['id'], 'create', 'user', json_encode(['email'=>$email])]);
                redirect(BASE_URL . '/admin/users.php');
            } catch (Throwable $e) {
                $errors[] = "Create failed: " . $e->getMessage();
            }
        }
    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $team_slug = trim($_POST['team_slug'] ?? '');
        $active = isset($_POST['is_active']) ? 1 : 0;
        $external = trim($_POST['external_user_id'] ?? '');
        if ($id <= 0 || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid input";
        if (!$errors) {
            $team_id = null;
            if ($team_slug !== '') {
                $team = q("SELECT id FROM teams WHERE slug=?", [$team_slug])->fetch();
                if (!$team) {
                    q("INSERT INTO teams (name, slug) VALUES (?,?)", [$team_slug, $team_slug]);
                    $team_id = (int)db()->lastInsertId();
                } else { $team_id = (int)$team['id']; }
            }
            try {
                q("UPDATE users SET name=?, email=?, team_id=?, is_active=?, external_user_id=? WHERE id=?",
                  [$name ?: null, $email, $team_id, $active, $external ?: null, $id]);
                q("INSERT INTO audit_log (actor_user_id, action, entity_type, entity_id, details) VALUES (?,?,?,?,?)",
                  [current_user()['id'], 'update', 'user', $id, json_encode(['email'=>$email])]);
                redirect(BASE_URL . '/admin/users.php');
            } catch (Throwable $e) {
                $errors[] = "Update failed: " . $e->getMessage();
            }
        }
    } elseif ($action === 'csv') {
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = "Upload failed";
        } else {
            $tmp = $_FILES['file']['tmp_name'];
            $rowsAdded = 0; $rowsUpd = 0;
            if (($h = fopen($tmp, 'r')) !== false) {
                $header = fgetcsv($h);
                if (!$header) { $errors[] = "Empty CSV"; }
                else {
                    $cols = array_map('strtolower', $header);
                    $idxEmail = array_search('email', $cols);
                    $idxName = array_search('name', $cols);
                    $idxTeam = array_search('team_slug', $cols);
                    $idxExt  = array_search('external_user_id', $cols);
                    if ($idxEmail === false) $errors[] = "CSV missing 'email' column";
                    while (!$errors && ($r = fgetcsv($h)) !== false) {
                        $email = trim($r[$idxEmail] ?? '');
                        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
                        $name = trim($r[$idxName] ?? '');
                        $team_slug = trim($r[$idxTeam] ?? '');
                        $ext = trim($r[$idxExt] ?? '');
                        $team_id = null;
                        if ($team_slug !== '') {
                            $t = q("SELECT id FROM teams WHERE slug=?", [$team_slug])->fetch();
                            if (!$t) {
                                q("INSERT INTO teams (name, slug) VALUES (?,?)", [$team_slug, $team_slug]);
                                $team_id = (int)db()->lastInsertId();
                            } else { $team_id = (int)$t['id']; }
                        }
                        $existing = q("SELECT id FROM users WHERE email=?", [$email])->fetch();
                        if ($existing) {
                            q("UPDATE users SET name=?, team_id=?, external_user_id=? WHERE id=?",
                              [$name ?: null, $team_id, $ext ?: null, (int)$existing['id']]);
                            $rowsUpd++;
                        } else {
                            q("INSERT INTO users (name, email, team_id, external_user_id) VALUES (?,?,?,?)",
                              [$name ?: null, $email, $team_id, $ext ?: null]);
                            $rowsAdded++;
                        }
                    }
                    if (!$errors) $info = "Imported: {$rowsAdded} created, {$rowsUpd} updated.";
                    fclose($h);
                }
            } else $errors[] = "Cannot read CSV";
        }
    }
}

$page = max(1, (int)($_GET['page'] ?? 1));
[$offset, $limit] = paginate($page, 150);
$users = q("
SELECT u.id, u.name, u.email, u.is_active, u.external_user_id,
       t.slug AS team_slug, t.color
FROM users u
LEFT JOIN teams t ON t.id = u.team_id
ORDER BY u.email ASC
LIMIT ? OFFSET ?", [$limit, $offset])->fetchAll();

$total = (int)q("SELECT COUNT(1) FROM users")->fetchColumn();
$pages = max(1, (int)ceil($total / 25));
$teamsAll = q("SELECT slug FROM teams ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
$teamList = implode(', ', array_map('e', $teamsAll));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Users · <?= e(APP_NAME) ?></title>
  <link rel="stylesheet" href="<?= e(BASE_URL) ?>/style.css">
</head>
<body>
<div class="container">
  <div class="topbar">
    <div class="brand"><span>Users</span></div>
    <div class="toolbar">
      <a class="button secondary" href="<?= e(BASE_URL) ?>/admin/">Back</a>
    </div>
  </div>

  <?php if ($info): ?><div class="notice"><?= e($info) ?></div><?php endif; ?>
  <?php if ($errors): ?>
    <div class="warn"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <div class="grid">
    <div class="card">
      <h3>Create User</h3>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px">
          <label>Name<br><input name="name" style="width:100%;padding:8px;border-radius:8px;border:1px solid #243049;background:#0f1320;color:#fff"></label>
          <label>Email<br><input name="email" type="email" required style="width:100%;padding:8px;border-radius:8px;border:1px solid #243049;background:#0f1320;color:#fff"></label>
          <label>Team Slug<br><input name="team_slug" placeholder="e.g. red-team" style="width:100%;padding:8px;border-radius:8px;border:1px solid #243049;background:#0f1320;color:#fff"></label>
          <label>External User ID<br><input name="external_user_id" style="width:100%;padding:8px;border-radius:8px;border:1px solid #243049;background:#0f1320;color:#fff"></label>
        </div>
        <div style="margin-top:10px"><button class="button">Add</button></div>
      </form>
    </div>

    <div class="card">
      <h3>Bulk Import (CSV)</h3>
      <p>Columns: <code>email,name,team_slug,external_user_id</code>. Unknown teams are auto-created.</p>
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="csv">
        <input type="file" name="file" accept=".csv" required>
        <button class="button">Upload</button>
      </form>
    </div>
  </div>

  <div class="card" style="margin-top:20px">
    <h3>Users (<?= $total ?>)</h3>
    <table>
      <thead><tr><th>Email</th><th>Name</th><th>Team</th><th>Active</th><th>External</th><th>Save</th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <td><input name="email" value="<?= e($u['email']) ?>" style="width:100%;padding:6px;border-radius:6px;border:1px solid #243049;background:#0f1320;color:#fff"></td>
            <td><input name="name" value="<?= e($u['name'] ?: '') ?>" style="width:100%;padding:6px;border-radius:6px;border:1px solid #243049;background:#0f1320;color:#fff"></td>
            <td><input name="team_slug" value="<?= e($u['team_slug'] ?: '') ?>" placeholder="one of: <?= $teamList ?>" style="width:100%;padding:6px;border-radius:6px;border:1px solid #243049;background:#0f1320;color:#fff"></td>
            <td><input type="checkbox" name="is_active" <?= $u['is_active'] ? 'checked' : '' ?>></td>
            <td><input name="external_user_id" value="<?= e($u['external_user_id'] ?: '') ?>" style="width:100%;padding:6px;border-radius:6px;border:1px solid #243049;background:#0f1320;color:#fff"></td>
            <td><button class="button">Save</button></td>
          </form>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div style="margin-top:10px">
      Page <?= $page ?> / <?= $pages ?> &nbsp;
      <?php if ($page > 1): ?><a href="?page=<?= $page-1 ?>">Prev</a><?php endif; ?>
      <?php if ($page < $pages): ?> | <a href="?page=<?= $page+1 ?>">Next</a><?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
