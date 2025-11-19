<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';
require_admin();

$page = max(1, (int)($_GET['page'] ?? 1));
[$offset, $limit] = paginate($page, 50);

$rows = q("
SELECT a.id, a.created_at, a.action, a.entity_type, a.entity_id, a.details,
       u.email AS actor_email
FROM audit_log a
LEFT JOIN users u ON u.id = a.actor_user_id
ORDER BY a.id DESC
LIMIT ? OFFSET ?", [$limit, $offset])->fetchAll();
$total = (int)q("SELECT COUNT(1) FROM audit_log")->fetchColumn();
$pages = max(1, (int)ceil($total / 50));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Audit · <?= e(APP_NAME) ?></title>
  <link rel="stylesheet" href="<?= e(BASE_URL) ?>/style.css">
</head>
<body>
<div class="container">
  <div class="topbar">
    <div class="brand"><span>Audit Log</span></div>
    <div class="toolbar">
      <a class="button secondary" href="<?= e(BASE_URL) ?>/admin/">Back</a>
    </div>
  </div>

  <div class="card">
    <h3>Events (<?= $total ?>)</h3>
    <table>
      <thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Entity</th><th>Details</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= e($r['created_at']) ?></td>
          <td><?= e($r['actor_email'] ?: '—') ?></td>
          <td><?= e($r['action']) ?></td>
          <td><?= e(($r['entity_type'] ?: '—') . ($r['entity_id'] ? ' #' . $r['entity_id'] : '')) ?></td>
          <td><code><?= e($r['details'] ?: '') ?></code></td>
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
