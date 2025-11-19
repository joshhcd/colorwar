<?php
session_start();
require_once __DIR__ . '/db.php';
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }

// ---------------- Admin auth (unchanged) ----------------
if (isset($_POST['action']) && $_POST['action'] === 'login') {
    $user = $_POST['username'] ?? '';
    $pass = $_POST['password'] ?? '';
    $row = $pdo->query("SELECT admin_user, admin_pass_hash FROM settings WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    if ($row && $user === $row['admin_user'] && password_verify($pass, $row['admin_pass_hash'])) {
        $_SESSION['adminword'] = true;
        header("Location: admin.php");
        exit;
    } else {
        $error = "Invalid credentials";
    }
}
if (isset($_GET['logout'])) {
    $_SESSION['adminword'] = false;
    session_destroy();
    header("Location: admin.php");
    exit;
}

// ---------------- Export Logic ----------------

if (isset($_GET['export_csv']) && is_adminword()) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="wordsearch_results.csv"');

    $out = fopen('php://output', 'w');

    // Fixed: write headers once (in desired order)
    fputcsv($out, [
        'Result ID','Puzzle ID','Puzzle Title','User Name','User Email',
        'Started','Finished','Duration (sec)','Found Words'
    ]);

    $sql = "SELECT r.id            AS result_id,
                   r.puzzle_id     AS puzzle_id,
                   p.title         AS puzzle_title,
                   r.user_name     AS user_name,
                   r.user_email    AS user_email,
                   r.started_at    AS started_at,
                   r.finished_at   AS finished_at,
                   r.duration_sec  AS duration_sec,
                   r.found_words   AS found_words
            FROM results r
            JOIN puzzles p ON p.id = r.puzzle_id
            ORDER BY r.started_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Ensure consistent column order to match header
        fputcsv($out, [
            $row['result_id'],
            $row['puzzle_id'],
            $row['puzzle_title'],
            $row['user_name'],
            $row['user_email'],
            $row['started_at'],
            $row['finished_at'],
            $row['duration_sec'],
            $row['found_words'],
        ]);
    }
    fclose($out);
    exit;
}

// ---------------- Create/Delete puzzles (unchanged) ----------------
if (is_adminword() && ($_POST['action'] ?? '') === 'create') {
    $title = trim($_POST['title'] ?? '');
    $width = max(5, (int)($_POST['width'] ?? 12));
    $height = max(5, (int)($_POST['height'] ?? 12));
    $allowDiagonal = isset($_POST['allow_diagonal']) ? 1 : 0;
    $showAnswers = isset($_POST['show_answers']) ? 1 : 0;
    $showWordlist = isset($_POST['show_wordlist']) ? 1 : 0;
$desc = trim($_POST['description'] ?? '');
    // Sanitize words
    $words = sanitize_words($_POST['words'] ?? '');

    if (!$title || empty($words)) {
        $error = "Title and at least one word are required.";
    } else {
        // Build grid
        function place_words($width, $height, $words, $allowDiagonal) {
            $grid = array_fill(0, $height, array_fill(0, $width, ''));
            $dirs = [[1,0],[0,1],[-1,0],[0,-1]];
            if ($allowDiagonal) {
                $dirs = array_merge($dirs, [[1,1],[1,-1],[-1,1],[-1,-1]]);
            }
            usort($words, fn($a,$b)=>mb_strlen($b)-mb_strlen($a));
            foreach ($words as $w) {
                $placed=false;
                for ($attempt=0;$attempt<400 && !$placed;$attempt++) {
                    [$dx,$dy] = $dirs[array_rand($dirs)];
                    $x = rand(0,$width-1); $y = rand(0,$height-1);
                    $len = mb_strlen($w);
                    $endx = $x + $dx*($len-1);
                    $endy = $y + $dy*($len-1);
                    if ($endx<0||$endx>=$width||$endy<0||$endy>=$height) continue;
                    $ok=true;
                    for ($i=0;$i<$len;$i++){
                        $cx=$x+$dx*$i; $cy=$y+$dy*$i; $ch=$grid[$cy][$cx];
                        if ($ch!=='' && $ch!==mb_substr($w,$i,1)){ $ok=false; break; }
                    }
                    if(!$ok) continue;
                    for ($i=0;$i<$len;$i++){
                        $cx=$x+$dx*$i; $cy=$y+$dy*$i;
                        $grid[$cy][$cx]=mb_substr($w,$i,1);
                    }
                    $placed=true;
                }
                if (!$placed) return [false,"Failed to place word: $w. Try a larger grid."];
            }
            $alphabet = range('A','Z');
            for ($yy=0;$yy<$height;$yy++){
                for ($xx=0;$xx<$width;$xx++){
                    if ($grid[$yy][$xx]==='') $grid[$yy][$xx]=$alphabet[array_rand($alphabet)];
                }
            }
            return [$grid,null];
        }

        [$grid,$err] = place_words($width,$height,$words,$allowDiagonal);
        if (!$grid) { $error = $err ?: "Could not generate grid."; }
        else {
$stmt = $pdo->prepare("
  INSERT INTO puzzles
    (title,width,height,grid,words,allow_diagonal,show_answers,show_wordlist,description,created_at)
  VALUES
    (:title,:w,:h,:grid,:words,:diag,:ans,:list,:desc,datetime('now'))
");
$stmt->execute([
  ':title'=>$title,
  ':w'=>$width,
  ':h'=>$height,
  ':grid'=>json_encode($grid, JSON_UNESCAPED_UNICODE),
  ':words'=>json_encode($words, JSON_UNESCAPED_UNICODE),
  ':diag'=>$allowDiagonal,
  ':ans'=>$showAnswers,
  ':list'=>$showWordlist,
  ':desc'=>$desc,    // <— new bind
]);
            $success = "Puzzle created!";
        }
    }
}
if (is_adminword() && isset($_POST['action']) && $_POST['action']==='delete') {
    $id = (int)($_POST['id'] ?? 0);
    $pdo->prepare("DELETE FROM puzzles WHERE id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM results WHERE puzzle_id=?")->execute([$id]);
    $success = "Puzzle #$id deleted.";
}

// Delete a single result
if (is_adminword() && ($_POST['action'] ?? '') === 'delete_result') {
    $rid = (int)($_POST['id'] ?? 0);
    if ($rid > 0) {
        $stmt = $pdo->prepare("DELETE FROM results WHERE id = ?");
        $stmt->execute([$rid]);
        $success = "Result #{$rid} deleted.";
    } else {
        $error = "Invalid result id.";
    }
}

// ---------------- Load data for UI ----------------
$puzzles = $pdo->query("SELECT * FROM puzzles ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

// Ensure results table has columns we rely on
try {
    $cols = [];
    foreach ($pdo->query("PRAGMA table_info(results)")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $cols[] = $c['name'];
    }
    if (!in_array('grid', $cols))        $pdo->exec("ALTER TABLE results ADD COLUMN grid TEXT");
    if (!in_array('answer_mask', $cols)) $pdo->exec("ALTER TABLE results ADD COLUMN answer_mask TEXT");
    if (!in_array('seed', $cols))        $pdo->exec("ALTER TABLE results ADD COLUMN seed TEXT");
} catch (Throwable $e) { /* ignore */ }

// Filters
$filterPuzzle = isset($_GET['puzzle_id']) ? (int)$_GET['puzzle_id'] : 0;
$q = trim($_GET['q'] ?? '');

// Build WHERE for results
$where = [];
$params = [];
if ($filterPuzzle > 0) { $where[] = "r.puzzle_id = :pid"; $params[':pid'] = $filterPuzzle; }
if ($q !== '') {
    $where[] = "(r.user_email LIKE :q OR r.user_name LIKE :q)";
    $params[':q'] = "%{$q}%";
}
$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

// Stats
$statsSql = "
SELECT
  COUNT(*) AS plays,
  SUM(CASE WHEN finished_at IS NOT NULL THEN 1 ELSE 0 END) AS finished,
  AVG(NULLIF(duration_sec,0)) AS avg_seconds,
  AVG(found_words) AS avg_found
FROM results r
{$whereSql}";
$st = $pdo->prepare($statsSql);
$st->execute($params);
$stats = $st->fetch(PDO::FETCH_ASSOC) ?: ['plays'=>0,'finished'=>0,'avg_seconds'=>null,'avg_found'=>null];

// Results list (latest 100)
$listSql = "
SELECT r.*, p.title, p.width, p.height, json_array_length(p.words) AS total_words
FROM results r
JOIN puzzles p ON p.id = r.puzzle_id
{$whereSql}
ORDER BY r.started_at DESC
LIMIT 100";
$ls = $pdo->prepare($listSql);
$ls->execute($params);
$results = $ls->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>WordSearch — Admin</title>
  <link rel="stylesheet" href="assets/style.css"/>
  <style>
    .statgrid { display:grid; grid-template-columns: repeat(4, 1fr); gap:12px; }
    .stat { background:#0f1520; border:1px solid #22344b; border-radius:10px; padding:12px; text-align:center; }
    .muted { color:#8aa0b6; font-size:12px; }
    .table { display:grid; grid-template-columns: 40px 1fr 1fr 120px 120px 80px 110px 110px; border:1px solid #22344b; border-radius:12px; }
    .table .t-head,.table .t-row{display:contents}
    .table > div > div{padding:10px;border-bottom:1px solid #1e2b3d; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;}
    .t-head > div { background:#101621; font-weight:700; }
    .filters { display:flex; gap:8px; align-items:end; flex-wrap:wrap; }
    .filters label { min-width:220px; }
    .btn.secondary { background:#263445; color:#d6e3f3; }
    .nowrap { white-space:nowrap; }
  </style>
</head>
<body>
<header class="topbar">
  <h1>Admin</h1>
  <nav>
    <a href="index.php">Play</a>
    <?php if (is_adminword()): ?><a href="admin.php?logout=1">Logout</a><?php endif; ?>
  </nav>
</header>

<main class="container">
<?php if (!is_adminword()): ?>
  <!-- Login -->
  <section class="card max400">
    <h2>Admin Sign In</h2>
    <?php if (!empty($error)): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
    <form method="post">
      <input type="hidden" name="action" value="login"/>
      <label>Username<br><input name="username" required></label>
      <label>Password<br><input type="password" name="password" required></label>
      <button class="btn" type="submit">Sign in</button>
    </form>
  </section>

<?php else: ?>
  <?php if (!empty($error)): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <?php if (!empty($success)): ?><div class="success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

  <!-- Create puzzle -->
  <section class="card">
    <h2>Create New Puzzle</h2>
    <form method="post" class="grid2">
      <input type="hidden" name="action" value="create"/>
      <label>Title<br><input name="title" placeholder="Week 2 — Phishing Awareness" required></label>
      <label class="span2">Description (shown on the play page)<br>
         <textarea name="description" rows="3" placeholder="Short intro, rules, or storyline..."></textarea>
       </label>

      <label>Grid Size<br>
        <input type="number" name="width" min="5" max="32" value="14" style="width:6rem"> ×
        <input type="number" name="height" min="5" max="32" value="14" style="width:6rem">
      </label>
      <label class="span2">Words (one per line or comma-separated)<br>
        <textarea name="words" rows="5" placeholder="PASSWORD, MFA, REPORT, HYPERLINK, MALWARE" required></textarea>
      </label>
      <label><input type="checkbox" name="allow_diagonal" checked> Allow diagonal</label>
      <label><input type="checkbox" name="show_answers"> Include answer key on print</label>
      <label><input type="checkbox" name="show_wordlist" checked> Include word list on print</label>
      <div class="row span2">
        <button class="btn" type="submit">Generate & Save</button>
      </div>
    </form>
  </section>

  <!-- Manage puzzles -->
  <section class="card">
    <h2>Manage Puzzles</h2>
    <?php if (!$puzzles): ?>
      <p>No puzzles yet.</p>
    <?php else: ?>
      <div class="table">
        <div class="t-head">
          <div>ID</div><div>Title</div><div>Size</div><div>Created</div><div>Actions</div><div></div><div></div><div></div>
        </div>
        <?php foreach ($puzzles as $p): ?>
        <div class="t-row">
          <div>#<?= (int)$p['id'] ?></div>
          <div><?= htmlspecialchars($p['title']) ?></div>
          <div><?= (int)$p['width'] ?>×<?= (int)$p['height'] ?></div>
          <div><?= htmlspecialchars($p['created_at']) ?></div>
          <div class="row">
            <a class="btn small" href="print.php?id=<?= (int)$p['id'] ?>" target="_blank">Print (blank)</a>
            <a class="btn small" href="play.php?puzzle_id=<?= (int)$p['id'] ?>">Preview</a>
          </div>
          <div></div>
          <div>
            <form method="post" onsubmit="return confirm('Delete puzzle #<?= (int)$p['id'] ?>?');">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
              <button class="btn danger small" type="submit">Delete</button>
            </form>
          </div>
          <div></div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <!-- Results -->
  <section class="card">
    <h2>Results</h2>
    <div style="margin-top:10px; margin-bottom:10px; text-align:right;">
      <a href="admin.php?export_csv=1" class="btn secondary">⬇️ Export All Results (CSV)</a>
    </div>
    <form method="get" class="filters">
      <label>Puzzle<br>
        <select name="puzzle_id">
          <option value="0">All puzzles</option>
          <?php foreach ($puzzles as $pz): ?>
            <option value="<?= (int)$pz['id'] ?>" <?= $filterPuzzle===(int)$pz['id']?'selected':'' ?>>
              #<?= (int)$pz['id'] ?>  <?= htmlspecialchars($pz['title']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Search (name/email)<br>
        <input name="q" value="<?= htmlspecialchars($q) ?>" placeholder="alice@company.com">
      </label>
      <div>
        <button class="btn" type="submit">Filter</button>
        <a class="btn secondary" href="admin.php">Clear</a>
      </div>
    </form>

    <div class="statgrid" style="margin-top:10px;">
      <div class="stat">
        <div style="font-size:22px; font-weight:700;"><?= (int)($stats['plays'] ?? 0) ?></div>
        <div class="muted">Plays</div>
      </div>
      <div class="stat">
        <?php
          $plays = max(1,(int)($stats['plays'] ?? 0));
          $finished = (int)($stats['finished'] ?? 0);
          $rate = $plays ? round(($finished/$plays)*100) : 0;
        ?>
        <div style="font-size:22px; font-weight:700;"><?= $rate ?>%</div>
        <div class="muted">Completion</div>
      </div>
      <div class="stat">
        <?php $avgSec = $stats['avg_seconds'] !== null ? (int)$stats['avg_seconds'] : 0;
              $mm = str_pad((string)floor($avgSec/60),2,'0',STR_PAD_LEFT);
              $ss = str_pad((string)($avgSec%60),2,'0',STR_PAD_LEFT);
        ?>
        <div style="font-size:22px; font-weight:700;"><?= $mm ?>:<?= $ss ?></div>
        <div class="muted">Avg time</div>
      </div>
      <div class="stat">
        <div style="font-size:22px; font-weight:700;"><?= $stats['avg_found'] !== null ? round($stats['avg_found'],1) : 0 ?></div>
        <div class="muted">Avg words found</div>
      </div>
    </div>

    <?php if (!$results): ?>
      <p style="margin-top:12px;">No results yet.</p>
    <?php else: ?>
      <div class="table" style="margin-top:12px;">
        <div class="t-head">
          <div>#</div><div>User</div><div>Email</div>
          <div>Started</div><div>Finished</div><div>Time</div>
          <div>Found</div><div>Actions</div>
        </div>
        <?php foreach ($results as $r):
          $sec = (int)($r['duration_sec'] ?? 0);
          $mm  = str_pad((string)floor($sec/60),2,'0',STR_PAD_LEFT);
          $ss  = str_pad((string)($sec%60),2,'0',STR_PAD_LEFT);
          $found = (int)($r['found_words'] ?? 0);
          $total = (int)($r['total_words'] ?? 0);
        ?>
        <div class="t-row">
          <div><?= (int)$r['id'] ?></div>
          <div class="nowrap"><?= htmlspecialchars($r['user_name']) ?></div>
          <div class="nowrap"><?= htmlspecialchars($r['user_email']) ?></div>
          <div class="nowrap"><?= htmlspecialchars($r['started_at']) ?></div>
          <div class="nowrap"><?= htmlspecialchars($r['finished_at'] ?? '') ?></div>
          <div class="nowrap"><?= $mm ?>:<?= $ss ?></div>
          <div class="nowrap"><?= $found ?>/<?= $total ?></div>
          <div class="row">
              <a class="btn small" href="print_result.php?result_id=<?= (int)$r['id'] ?>" target="_blank">Print</a>
              <form method="post" style="display:inline" onsubmit="return confirm('Delete result #<?= (int)$r['id'] ?> for <?= htmlspecialchars($r['user_email']) ?>?');">
                <input type="hidden" name="action" value="delete_result">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn danger small" type="submit">Delete</button>
              </form>
            </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

<?php endif; ?>
</main>
</body>
</html>
