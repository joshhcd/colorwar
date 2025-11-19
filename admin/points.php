<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';
require_admin();

$errors = [];
$info = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? 'award';

    if ($action === 'delete') {
        // --- Delete a single points row ---
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            $errors[] = "Invalid point id";
        } else {
            $row = q("SELECT * FROM points WHERE id=?", [$id])->fetch();
            if (!$row) {
                $errors[] = "Points entry not found";
            } else {
                q("DELETE FROM points WHERE id=?", [$id]);
                q("INSERT INTO audit_log (actor_user_id, action, entity_type, entity_id, details)
                   VALUES (?,?,?,?,?)",
                  [current_user()['id'], 'delete', 'points', $id, json_encode($row)]
                );
                $info = "Deleted points entry #{$id}";
            }
        }

    } elseif ($action === 'bulk_delete') {
        // --- Bulk delete selected rows ---
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids) || count($ids) === 0) {
            $errors[] = "No rows selected";
        } else {
            // Normalize to unique positive ints
            $norm = [];
            foreach ($ids as $v) {
                $v = (int)$v;
                if ($v > 0) $norm[$v] = true;
            }
            $ids = array_keys($norm);

            if (count($ids) === 0) {
                $errors[] = "No valid ids to delete";
            } else {
                // Fetch rows for auditing, then delete within a transaction
                $place = implode(',', array_fill(0, count($ids), '?'));
                $rows = q("SELECT * FROM points WHERE id IN ($place)", $ids)->fetchAll();

                if (!$rows) {
                    $errors[] = "No matching rows found";
                } else {
                    $pdo = db();
                    $pdo->beginTransaction();
                    try {
                        foreach ($rows as $r) {
                            q("DELETE FROM points WHERE id=?", [(int)$r['id']]);
                            q("INSERT INTO audit_log (actor_user_id, action, entity_type, entity_id, details)
                               VALUES (?,?,?,?,?)",
                              [current_user()['id'], 'delete', 'points', (int)$r['id'], json_encode($r)]
                            );
                        }
                        $pdo->commit();
                        $info = "Deleted " . count($rows) . " point " . (count($rows) === 1 ? "entry" : "entries");
                    } catch (Throwable $e) {
                        $pdo->rollBack();
                        $errors[] = "Bulk delete failed: " . $e->getMessage();
                    }
                }
            }
        }

} elseif ($action === 'bulk_import') {
    // --- Bulk import points from CSV (with optional fixed amount) ---
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = "Upload failed";
    } else {
        $defaultReason = trim($_POST['bulk_reason'] ?? '');

        // Same amount for all rows (optional)
        $fixedAmountRaw = trim($_POST['bulk_amount'] ?? '');
        $useFixedAmount = ($fixedAmountRaw !== '');
        $fixedAmount = null;

        if ($useFixedAmount) {
            if (!preg_match('/^-?\d+$/', $fixedAmountRaw)) {
                $errors[] = "Same amount for all rows must be an integer";
            } else {
                $fixedAmount = (int)$fixedAmountRaw;
                if ($fixedAmount === 0) {
                    $errors[] = "Same amount for all rows cannot be 0";
                }
            }
        }

        $tmp = $_FILES['file']['tmp_name'];
        $added = 0; $skipped = 0; $rownum = 1; $skippedNotes = [];

        if (!$errors && ($h = fopen($tmp, 'r')) !== false) {
            $header = fgetcsv($h);
            $rownum++;

            if (!$header) {
                $errors[] = "Empty CSV";
                fclose($h);
            } else {
                // Map header names (case-insensitive)
                $cols = array_map('strtolower', $header);
                $idx = [
                    'email' => array_search('email', $cols),
                    'external_user_id' => array_search('external_user_id', $cols),
                    'team_slug' => array_search('team_slug', $cols),
                    'amount' => array_search('amount', $cols),
                    'reason' => array_search('reason', $cols),
                ];

                // If no fixed amount, require 'amount' column
                if (!$useFixedAmount && $idx['amount'] === false) {
                    $errors[] = "CSV missing required 'amount' column (or set 'Same amount for all rows').";
                }

                if (!$errors) {
                    while (($r = fgetcsv($h)) !== false) {
                        $email = $idx['email'] !== false ? trim((string)($r[$idx['email']] ?? '')) : '';
                        $ext   = $idx['external_user_id'] !== false ? trim((string)($r[$idx['external_user_id']] ?? '')) : '';
                        $team  = $idx['team_slug'] !== false ? trim((string)($r[$idx['team_slug']] ?? '')) : '';
                        $rowReason = $idx['reason'] !== false ? trim((string)($r[$idx['reason']] ?? '')) : '';

                        // Determine amount
                        if ($useFixedAmount) {
                            $amount = $fixedAmount;
                        } else {
                            $amountRaw = $idx['amount'] !== false ? trim((string)($r[$idx['amount']] ?? '')) : '';
                            if ($amountRaw === '' || !preg_match('/^-?\d+$/', $amountRaw)) {
                                $skipped++; $skippedNotes[] = "Row {$rownum}: invalid amount";
                                $rownum++; continue;
                            }
                            $amount = (int)$amountRaw;
                            if ($amount === 0) { $skipped++; $skippedNotes[] = "Row {$rownum}: zero amount"; $rownum++; continue; }
                        }

                        $user_id = null; $team_id = null;

                        // Try user by email first
                        if ($email !== '') {
                            $u = q("SELECT id FROM users WHERE email=? AND is_active=1", [$email])->fetch();
                            if ($u) { $user_id = (int)$u['id']; }
                        }
                        // Then by external_user_id
                        if (!$user_id && $ext !== '') {
                            $u = q("SELECT id FROM users WHERE external_user_id=? AND is_active=1", [$ext])->fetch();
                            if ($u) { $user_id = (int)$u['id']; }
                        }
                        // Fallback to team by slug (auto-create if missing)
                        if (!$user_id && $team !== '') {
                            $t = q("SELECT id FROM teams WHERE slug=?", [$team])->fetch();
                            if (!$t) {
                                q("INSERT INTO teams (name, slug) VALUES (?,?)", [$team, $team]);
                                $team_id = (int)db()->lastInsertId();
                            } else { $team_id = (int)$t['id']; }
                        }

                        if (!$user_id && !$team_id) {
                            $skipped++; $skippedNotes[] = "Row {$rownum}: no user/team matched";
                            $rownum++; continue;
                        }

                        $finalReason = $rowReason !== '' ? $rowReason : ($defaultReason !== '' ? $defaultReason : 'bulk import');

                        try {
                            q("INSERT INTO points (user_id, team_id, amount, reason, source, created_by)
                               VALUES (?,?,?,?,?,?)",
                              [$user_id, $team_id, $amount, $finalReason, 'bulk-import', current_user()['id']]
                            );
                            $added++;
                        } catch (Throwable $e) {
                            $skipped++; $skippedNotes[] = "Row {$rownum}: DB error";
                        }
                        $rownum++;
                    }
                }

                fclose($h);

                if (!$errors) {
                    $info = "Bulk import complete: {$added} added, {$skipped} skipped.";
                    // Audit log entry
                    q("INSERT INTO audit_log (actor_user_id, action, entity_type, details)
                       VALUES (?,?,?,?)",
                      [current_user()['id'], 'bulk_import', 'points', json_encode([
                          'added'=>$added, 'skipped'=>$skipped, 'default_reason'=>$defaultReason, 'fixed_amount'=>$useFixedAmount ? $fixedAmount : null
                      ])]
                    );
                    if ($skipped && $skippedNotes) {
                        $errors[] = "Skipped details: " . implode('; ', array_slice($skippedNotes, 0, 10)) . (count($skippedNotes) > 10 ? ' ...' : '');
                    }
                }
            }
        } else if (!$errors) {
            $errors[] = "Cannot read CSV";
        }
    } // <-- closes the upload branch else { ... }
} // <-- closes elseif ($action === 'bulk_import')


     else { // action === 'award'
        // --- Award / Remove points (negative value removes) ---
        $email     = trim($_POST['email'] ?? '');
        $team_slug = trim($_POST['team_slug'] ?? '');
        $amount    = (int)($_POST['amount'] ?? 0);
        $reason    = trim($_POST['reason'] ?? '');

        if ($email === '' && $team_slug === '') $errors[] = "Email or Team is required";
        if ($amount === 0) $errors[] = "Points cannot be 0";

        if (!$errors) {
            $targetUser = null; $targetTeam = null;
            if ($email !== '') {
                $targetUser = q("SELECT id FROM users WHERE email=? AND is_active=1", [$email])->fetch();
                if (!$targetUser) $errors[] = "User not found or inactive";
            }
            if (!$errors && $team_slug !== '') {
                $targetTeam = q("SELECT id FROM teams WHERE slug=?", [$team_slug])->fetch();
                if (!$targetTeam) $errors[] = "Team not found";
            }

            if (!$errors) {
                q("INSERT INTO points (user_id, team_id, amount, reason, source, created_by)
                   VALUES (?,?,?,?,?,?)",
                  [$targetUser['id'] ?? null, $targetTeam['id'] ?? null, $amount, $reason ?: null, 'manual', current_user()['id']]
                );
                q("INSERT INTO audit_log (actor_user_id, action, entity_type, details)
                   VALUES (?,?,?,?)",
                  [current_user()['id'], 'award', 'points', json_encode([
                      'email'=>$email,
                      'team_slug'=>$team_slug,
                      'amount'=>$amount,
                      'reason'=>$reason
                  ])]
                );
                $info = "Applied " . ($amount > 0 ? '+' : '') . $amount . " points";
            }
        }
    }
}

$page = max(1, (int)($_GET['page'] ?? 1));
[$offset, $limit] = paginate($page, 50);
$rows = q("
SELECT p.id, p.created_at, p.amount, p.reason, p.source,
       u.email, u.name, t.name AS team, t.slug AS team_slug, t.color
FROM points p
LEFT JOIN users u ON u.id = p.user_id
LEFT JOIN teams t ON t.id = p.team_id
ORDER BY p.id DESC
LIMIT ? OFFSET ?", [$limit, $offset])->fetchAll();
$total = (int)q("SELECT COUNT(1) FROM points")->fetchColumn();
$pages = max(1, (int)ceil($total / 50));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Points · <?= e(APP_NAME) ?></title>
  <link rel="stylesheet" href="<?= e(BASE_URL) ?>/style.css">
</head>
<body>
<div class="container">
  <div class="topbar">
    <div class="brand"><span>Points</span></div>
    <div class="toolbar">
      <a class="button secondary" href="<?= e(BASE_URL) ?>/admin/">Back</a>
      <a class="button secondary" href="<?= e(BASE_URL) ?>/api/export.php?type=points">Export CSV</a>
    </div>
  </div>

  <?php if ($info): ?><div class="notice"><?= e($info) ?></div><?php endif; ?>
  <?php if ($errors): ?>
    <div class="warn"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <div class="card">
    <h3>Award / Remove Points</h3>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="award">
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px">
        <div><label>User Email (optional)<br><input name="email" style="width:100%;padding:10px;border-radius:8px;border:1px solid #243049;background:#0f1320;color:#fff"></label></div>
        <div><label>Team Slug (optional)<br><input name="team_slug" style="width:100%;padding:10px;border-radius:8px;border:1px solid #243049;background:#0f1320;color:#fff"></label></div>
        <div><label>Points (±int)<br><input name="amount" type="number" required value="1" style="width:100%;padding:10px;border-radius:8px;border:1px solid #243049;background:#0f1320;color:#fff"></label></div>
        <div><label>Reason<br><input name="reason" style="width:100%;padding:10px;border-radius:8px;border:1px solid #243049;background:#0f1320;color:#fff"></label></div>
      </div>
      <div style="margin-top:10px"><button class="button">Apply</button></div>
    </form>
  </div>

  <div class="card" style="margin-top:20px">
    <h3>Bulk Import Points</h3>
    <p>Headers accepted (case-insensitive): <code>email</code>, <code>external_user_id</code>, <code>team_slug</code>, <code>amount</code>, <code>reason</code>.<br>
       Matching order: email → external_user_id → team_slug (unknown teams auto-created). Negative amounts remove points.</p>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="bulk_import">
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px">
        <div><label>Default Reason (used if row has no reason)<br>
          <input name="bulk_reason" placeholder="e.g. Hackathon participation" style="width:100%;padding:10px;border-radius:8px;border:1px solid #243049;background:#0f1320;color:#fff">
        </label></div>
        <div><label>Same amount for all rows (optional, ±int)<br>
          <input name="bulk_amount" type="number" placeholder="e.g. 5 or -2" style="width:100%;padding:10px;border-radius:8px;border:1px solid #243049;background:#0f1320;color:#fff">
        </label>
        <div style="font-size:12px;color:#8aa1c2;margin-top:4px">If set, this overrides any <code>amount</code> values in the CSV and the <code>amount</code> column becomes optional.</div>
        </div>
        <div><label>CSV File<br>
          <input type="file" name="file" accept=".csv" required style="width:100%;padding:8px;border-radius:8px;border:1px solid #243049;background:#0f1320;color:#fff">
        </label></div>
      </div>
      <div style="margin-top:10px"><button class="button">Import</button></div>
      <div style="margin-top:8px;color:#8aa1c2;font-size:12px">
        Examples:<br>
        <code>email,reason</code> + “Same amount” = applies the same amount to each user row.<br>
        <code>team_slug</code> only + “Same amount” = grants that amount to each team row.<br>
        Or leave “Same amount” empty and include <code>amount</code> per row.
      </div>
    </form>
  </div>

  <div class="card" style="margin-top:20px">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
      <h3 style="margin:0">Recent Point Changes (<?= $total ?>)</h3>
      <!-- Bulk delete form controls -->
      <form method="post" id="bulkForm" style="display:flex;gap:8px;align-items:center">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="bulk_delete">
        <button class="button" id="bulkDeleteBtn" disabled>Delete Selected</button>
      </form>
    </div>

    <table>
      <thead>
        <tr>
          <th style="width:36px"><input type="checkbox" id="chkAll" title="Select all"></th>
          <th>When</th>
          <th>Delta</th>
          <th>User</th>
          <th>Team</th>
          <th>Reason</th>
          <th>Source</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td>
            <input type="checkbox" class="rowchk" name="ids[]" value="<?= (int)$r['id'] ?>" form="bulkForm" title="Select row #<?= (int)$r['id'] ?>">
          </td>
          <td><?= e($r['created_at']) ?></td>
          <td><?= e(fmt_points((int)$r['amount'])) ?></td>
          <td><?= e($r['name'] ?: $r['email'] ?: '—') ?></td>
          <td>
            <?php if ($r['team']): ?>
              <span class="team-badge" style="background:<?= e($r['color'] ?: '#38405f') ?>22;border-color:<?= e($r['color'] ?: '#38405f') ?>;color:#fff">
                <?= e($r['team']) ?>
              </span>
            <?php else: ?>
              —
            <?php endif; ?>
          </td>
          <td><?= e($r['reason'] ?: '') ?></td>
          <td><?= e($r['source'] ?: '') ?></td>
          <td>
            <form method="post" onsubmit="return confirm('Delete this points entry permanently?');" style="display:inline-block">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="button" style="background:#9b1c31">Delete</button>
            </form>
          </td>
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

<script>
document.addEventListener('DOMContentLoaded', function() {
  const chkAll = document.getElementById('chkAll');
  const checks = Array.from(document.querySelectorAll('.rowchk'));
  const btn = document.getElementById('bulkDeleteBtn');
  const bulkForm = document.getElementById('bulkForm');

  function sync() {
    const selected = checks.filter(c => c.checked).length;
    btn.disabled = selected === 0;
    btn.textContent = selected ? `Delete Selected (${selected})` : 'Delete Selected';
    if (selected !== checks.length) { chkAll.checked = false; }
    if (selected === checks.length && checks.length > 0) { chkAll.checked = true; }
  }

  chkAll && chkAll.addEventListener('change', function() {
    checks.forEach(c => c.checked = chkAll.checked);
    sync();
  });

  checks.forEach(c => c.addEventListener('change', sync));

  bulkForm && bulkForm.addEventListener('submit', function(e) {
    if (!confirm('Permanently delete selected points entries?')) {
      e.preventDefault();
    }
  });

  sync();
});
</script>
</body>
</html>
