<?php
require_once 'db.php';
require_once __DIR__ . '/../../lib/bootstrap.php';

if (empty($_SESSION['saml_authenticated'])) {
  header('Location: ' . ('/../saml/login.php'));
  exit;
}

try {
    $cols = [];
    foreach ($pdo->query("PRAGMA table_info(results)")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $cols[] = $c['name'];
    }
    if (!in_array('grid', $cols))        { $pdo->exec("ALTER TABLE results ADD COLUMN grid TEXT"); }
    if (!in_array('answer_mask', $cols)) { $pdo->exec("ALTER TABLE results ADD COLUMN answer_mask TEXT"); }
    if (!in_array('seed', $cols))        { $pdo->exec("ALTER TABLE results ADD COLUMN seed TEXT"); }
} catch (Throwable $e) {
    // safe to ignore on hosts that block ALTER; worst case: the UPDATE later will error and you’ll see it in logs
}

/**
 * Create a result row if needed, then generate + attach a per-user grid
 */
function ensure_user_result_with_grid(PDO $pdo, int $puzzleId, string $name, string $email): int {
    // Create result row
    $stmt = $pdo->prepare("INSERT INTO results (puzzle_id, user_name, user_email, started_at) VALUES (:pid,:n,:e,datetime('now'))");
    $stmt->execute([':pid'=>$puzzleId, ':n'=>$name, ':e'=>$email]);
    $resultId = (int)$pdo->lastInsertId();

    // Load puzzle to build the personalized grid
    $pst = $pdo->prepare("SELECT * FROM puzzles WHERE id=?");
    $pst->execute([$puzzleId]);
    $puzzle = $pst->fetch(PDO::FETCH_ASSOC);
    if (!$puzzle) {
        throw new RuntimeException("Puzzle not found");
    }
    $desc = (string)($p['description'] ?? '');


    $gridTemplate = json_decode($puzzle['grid'], true);
    $words = json_decode($puzzle['words'], true) ?: [];
    $width = (int)$puzzle['width'];
    $height = (int)$puzzle['height'];
    $allowDiag = ((int)$puzzle['allow_diagonal'] === 1);
    $seedKey = $puzzleId . '|' . mb_strtolower($email);

    [$userGrid, $answerMask, $seed] = ws_generate_personal_grid($gridTemplate, $words, $width, $height, $allowDiag, $seedKey);

    // Save personalized grid on result
    $up = $pdo->prepare("UPDATE results SET grid=:g, answer_mask=:m, seed=:s WHERE id=:id");
    $up->execute([
        ':g' => json_encode($userGrid, JSON_UNESCAPED_UNICODE),
        ':m' => json_encode($answerMask, JSON_UNESCAPED_UNICODE),
        ':s' => $seed,
        ':id'=> $resultId
    ]);

    return $resultId;
}

/* -------------------- Start/Resume logic -------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $puzzle_id = (int)($_POST['puzzle_id'] ?? 0);
    if (!$name || !$email || !$puzzle_id) { header("Location: index.php"); exit; }

    $_SESSION['player'] = ['name'=>$name,'email'=>$email];

    // Ensure user exists
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO users (name,email,created_at) VALUES (:n,:e,datetime('now'))");
    $stmt->execute([':n'=>$name,':e'=>$email]);

    // Create result row + personalized grid
    $_SESSION['result_id'] = ensure_user_result_with_grid($pdo, $puzzle_id, $name, $email);

    header("Location: play.php?puzzle_id=".$puzzle_id);
    exit;
}

$player = $_SESSION['player'] ?? null;
$puzzle_id = (int)($_GET['puzzle_id'] ?? 0);

// Auto-init from SAML if not started via POST
if (!$player && !empty($_SESSION['saml_authenticated'])) {
    $samlName = trim($_SESSION['saml_name'] ?? '');
    $samlEmail = trim($_SESSION['saml_email'] ?? '');
    if ($samlName && $samlEmail && $puzzle_id) {
        $_SESSION['player'] = ['name'=>$samlName, 'email'=>$samlEmail];

        // Ensure user exists
        $stmt = $pdo->prepare("INSERT OR IGNORE INTO users (name,email,created_at) VALUES (:n,:e,datetime('now'))");
        $stmt->execute([':n'=>$samlName, ':e'=>$samlEmail]);

        // Create result row + personalized grid
        $_SESSION['result_id'] = ensure_user_result_with_grid($pdo, $puzzle_id, $samlName, $samlEmail);

        $player = $_SESSION['player'];
    }
}

if (!$puzzle_id) { header("Location: index.php"); exit; }

// Load master puzzle (for metadata + word list)
$stmt = $pdo->prepare("SELECT * FROM puzzles WHERE id=?");
$stmt->execute([$puzzle_id]);
$p = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$p) die("Puzzle not found");
$desc = (string)($p['description'] ?? '');


// Load this session's personalized grid from the result row
$grid = null;
$words = json_decode($p['words'], true);

$rid = isset($_SESSION['result_id']) ? (int)$_SESSION['result_id'] : 0;
if ($rid > 0) {
    $rst = $pdo->prepare("SELECT grid FROM results WHERE id=?");
    $rst->execute([$rid]);
    $resRow = $rst->fetch(PDO::FETCH_ASSOC);
    if ($resRow && !empty($resRow['grid'])) {
        $grid = json_decode($resRow['grid'], true);
    }
}
// Fallback: use the admin’s template grid if (for any reason) no personalized grid exists
if (!$grid) {
    $grid = json_decode($p['grid'], true);
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Play — <?= htmlspecialchars($p['title']) ?></title>
  <link rel="stylesheet" href="assets/style.css"/>
</head>
<body>
<header class="topbar">
  <h1><?= htmlspecialchars($p['title']) ?></h1>
  <nav>
    <a href="index.php">Home</a>
  </nav>
</header>

<main class="container">
  <?php if (!$player): ?>
    <section class="card">
      <h2>Missing player info</h2>
      <p>Your SSO is active, but we don't have your name/email. Please start from the home page.</p>
      <a class="btn" href="index.php">Go back</a>
    </section>
  <?php else: ?>
  <section class="card">
      
<section class="card info" style="margin-bottom:1rem;">
  <h2>About this puzzle</h2>
  <p><?= nl2br(htmlspecialchars($desc)) ?></p>
</section>


    <section class="card info" style="margin-bottom:1rem;">
  <h2>How to Play</h2>
  <p>
    Find all the hidden words listed below in the letter grid. Words may appear
    <strong>horizontally, vertically, or diagonally</strong> — and can be written
    <strong>forward or backward</strong>. Click and drag your mouse (or tap and
    drag on mobile) to highlight a word. Once found, the word will be crossed off
    your list automatically.
  </p>
  <p>
    Try to find them all as fast as you can! Your time and score will be recorded
    when you finish.
  </p>
</section>

    <div class="row between">
      <div>Player: <strong><?= htmlspecialchars($player['name']) ?></strong> — <?= htmlspecialchars($player['email']) ?></div>
      <div>Found: <span id="foundCount">0</span> / <?= count($words) ?> | Time: <span id="timer">00:00</span></div>
    </div>
    <div id="grid" class="ws-grid" data-width="<?= (int)$p['width'] ?>" data-height="<?= (int)$p['height'] ?>"></div>
    <div class="words">
      <?php foreach ($words as $w): ?>
        <span class="word" data-word="<?= htmlspecialchars($w) ?>"><?= htmlspecialchars($w) ?></span>
      <?php endforeach; ?>
    </div>
    <div class="row">
      <button id="btnFinish" class="btn">Finish</button>
    </div>
  </section>
  <script>
    const GRID = <?= json_encode($grid ?? []) ?>;
    const WORDS = <?= json_encode($words ?? []) ?>;
    const RESULT_ID = <?= isset($_SESSION['result_id']) ? (int)$_SESSION['result_id'] : 'null' ?>;
  </script>
  <script src="assets/play.js"></script>
  <?php endif; ?>
</main>
</body>
</html>