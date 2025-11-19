<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';

ensure_dir(dirname(DB_PATH));
$exists = file_exists(DB_PATH);

if ($exists) {
    // If DB exists and users table exists with at least one admin, redirect to admin
    try {
        $count = q("SELECT COUNT(1) AS c FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn();
        if ($count) {
            $admins = q("SELECT COUNT(1) FROM users WHERE role IN ('admin','manager')")->fetchColumn();
            if ((int)$admins > 0) {
                redirect(BASE_URL . '/admin/login.php');
            }
        }
    } catch (Throwable $e) {
        // continue to allow re-init if broken
    }
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $org = trim($_POST['org'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $pass = (string)($_POST['password'] ?? '');

    if ($org === '') $errors[] = "Organization is required";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Valid email required";
    if (strlen($pass) < 8) $errors[] = "Password must be at least 8 characters";

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            // Schema
            $schema = <<<SQL
CREATE TABLE IF NOT EXISTS settings (
  id INTEGER PRIMARY KEY,
  org_name TEXT NOT NULL,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS teams (
  id INTEGER PRIMARY KEY,
  name TEXT NOT NULL,
  slug TEXT UNIQUE NOT NULL,
  color TEXT,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY,
  name TEXT,
  email TEXT UNIQUE NOT NULL,
  password_hash TEXT,
  role TEXT DEFAULT 'user',
  team_id INTEGER,
  is_active INTEGER DEFAULT 1,
  external_user_id TEXT,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS points (
  id INTEGER PRIMARY KEY,
  user_id INTEGER,
  team_id INTEGER,
  amount INTEGER NOT NULL,
  reason TEXT,
  source TEXT,
  created_by INTEGER,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS integrations (
  id INTEGER PRIMARY KEY,
  name TEXT NOT NULL,
  token TEXT UNIQUE NOT NULL,
  is_enabled INTEGER DEFAULT 1,
  match_event TEXT,
  default_points INTEGER DEFAULT 1,
  target TEXT DEFAULT 'user', -- 'user' or 'team'
  team_id INTEGER,
  description TEXT,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS audit_log (
  id INTEGER PRIMARY KEY,
  actor_user_id INTEGER,
  action TEXT NOT NULL,
  entity_type TEXT,
  entity_id INTEGER,
  details TEXT,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
);
SQL;
            $pdo->exec($schema);

            q("INSERT INTO settings (org_name) VALUES (?)", [$org]);

            // Seed three teams with brand colors
            $teams = [
                ['Red Team', 'red-team', '#e80a4d'],
                ['Blue Team', 'blue-team', '#38405f'],
                ['Gold Team', 'gold-team', '#feda15'],
            ];
            foreach ($teams as $t) {
                q("INSERT INTO teams (name, slug, color) VALUES (?,?,?)", $t);
            }

            // Create admin user
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            q("INSERT INTO users (name, email, password_hash, role, is_active) VALUES (?,?,?,?,1)", [
                $name ?: 'Admin', $email, $hash, 'admin'
            ]);
            $adminId = (int)db()->lastInsertId();

            // Default integration example
            $tok = bin2hex(random_bytes(24));
            q("INSERT INTO integrations (name, token, is_enabled, match_event, default_points, target, description) VALUES (?,?,?,?,?,?,?)",
              ['Example Webhook', $tok, 1, 'phishing_report', 1, 'user', 'Example rule for reported-phish events']);

            // Log setup
            q("INSERT INTO audit_log (actor_user_id, action, entity_type, details) VALUES (?,?,?,?)",
              [$adminId, 'setup', 'settings', json_encode(['org'=>$org])]);

            $pdo->commit();
            $_SESSION['flash'] = "Setup complete. You can now log in.";
            redirect(BASE_URL . '/admin/login.php');
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = "Setup failed: " . $e->getMessage();
        }
    }
}

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Setup · <?= e(APP_NAME) ?></title>
  <link rel="stylesheet" href="<?= e(BASE_URL) ?>/style.css">
</head>
<body>
<div class="container">
  <div class="topbar">
    <div class="brand"><span>Color</span> War Setup</div>
  </div>

  <div class="card">
    <h2>Initialize</h2>
    <p>Welcome! Let's set up your organization and the first admin account.</p>
    <?php if ($errors): ?>
      <div class="warn">
        <ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>
    <form method="post">
      <label>Organization<br><input name="org" required style="width:100%;padding:10px;border-radius:8px;border:1px solid #243049;background:#0f1320;color:#fff"></label><br><br>
      <label>Admin Name<br><input name="name" style="width:100%;padding:10px;border-radius:8px;border:1px solid #243049;background:#0f1320;color:#fff"></label><br><br>
      <label>Admin Email<br><input name="email" type="email" required style="width:100%;padding:10px;border-radius:8px;border:1px solid #243049;background:#0f1320;color:#fff"></label><br><br>
      <label>Admin Password<br><input name="password" type="password" required minlength="8" style="width:100%;padding:10px;border-radius:8px;border:1px solid #243049;background:#0f1320;color:#fff"></label><br><br>
      <button class="button">Create</button>
    </form>
  </div>
</div>
</body>
</html>
