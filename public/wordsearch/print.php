<?php
require_once __DIR__ . '/db.php';
$id = (int)($_GET['id'] ?? 0);
$stm = $pdo->prepare("SELECT * FROM puzzles WHERE id=?");
$stm->execute([$id]);
$p = $stm->fetch(PDO::FETCH_ASSOC);
if (!$p) die("Puzzle not found");

$grid = json_decode($p['grid'], true);
$words = json_decode($p['words'], true);
$allowDiagonal = (int)$p['allow_diagonal'] === 1;
$showAnswers = (int)$p['show_answers'] === 1;

// Compute answer mask by searching for each word in the grid (forwards/backwards)
$h = count($grid);
$w = count($grid[0] ?? []);
$mask = array_fill(0, $h, array_fill(0, $w, 0));

$dirs = [[1,0],[0,1],[-1,0],[0,-1]]; // orthogonal
if ($allowDiagonal) {
    $dirs = array_merge($dirs, [[1,1],[1,-1],[-1,1],[-1,-1]]);
}

function find_and_mark(&$grid, $w, $h, $word, $dirs, &$mask) {
    $len = mb_strlen($word);
    for ($y=0; $y<$h; $y++) {
        for ($x=0; $x<$w; $x++) {
            foreach ($dirs as $d) {
                $dx = $d[0]; $dy = $d[1];
                $endx = $x + $dx*($len-1);
                $endy = $y + $dy*($len-1);
                if ($endx<0 || $endx>=$w || $endy<0 || $endy>=$h) continue;

                // forward
                $ok = true;
                for ($i=0; $i<$len; $i++) {
                    $cx = $x + $dx*$i;
                    $cy = $y + $dy*$i;
                    $ch = $grid[$cy][$cx];
                    if ($ch !== mb_substr($word, $i, 1)) { $ok=false; break; }
                }
                if ($ok) {
                    for ($i=0; $i<$len; $i++) {
                        $cx = $x + $dx*$i; $cy = $y + $dy*$i;
                        $mask[$cy][$cx] = 1;
                    }
                    return true;
                }

                // reverse
                $ok = true;
                for ($i=0; $i<$len; $i++) {
                    $cx = $x + $dx*$i;
                    $cy = $y + $dy*$i;
                    $ch = $grid[$cy][$cx];
                    if ($ch !== mb_substr($word, $len-1-$i, 1)) { $ok=false; break; }
                }
                if ($ok) {
                    for ($i=0; $i<$len; $i++) {
                        $cx = $x + $dx*$i; $cy = $y + $dy*$i;
                        $mask[$cy][$cx] = 1;
                    }
                    return true;
                }
            }
        }
    }
    return false;
}

if ($showAnswers) {
    foreach ($words as $wd) {
        find_and_mark($grid, $w, $h, $wd, $dirs, $mask);
    }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8"/>
  <title>Print — <?= htmlspecialchars($p['title']) ?></title>
  <link rel="stylesheet" href="assets/style.css"/>
  <style>
    body { background: white; color: black; }
    .sheet { page-break-after: always; margin: 1rem auto; width: fit-content; }
    .grid { border-collapse: collapse; }
    .grid td { border: 1px solid #000; width: 24px; height: 24px; text-align: center; font-family: monospace; font-size: 14px; }
    .grid td.answer { background: #ddd; }
    .wordlist { columns: 3; margin-top: .5rem; }
    @media print {.no-print{display:none}}
  </style>
</head>
<body>
<div class="sheet">
  <h2><?= htmlspecialchars($p['title']) ?></h2>
  <table class="grid">
    <?php for ($yy=0; $yy<$h; $yy++): ?>
      <tr>
        <?php for ($xx=0; $xx<$w; $xx++): ?>
          <?php $ans = ($showAnswers && $mask[$yy][$xx] === 1) ? 'answer' : ''; ?>
          <td class="<?= $ans ?>"><?= htmlspecialchars($grid[$yy][$xx]) ?></td>
        <?php endfor; ?>
      </tr>
    <?php endfor; ?>
  </table>
  <?php if ((int)$p['show_wordlist'] === 1): ?>
    <div class="wordlist">
      <?php foreach ($words as $wrd): ?>
        <div><?= htmlspecialchars($wrd) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <p class="no-print"><button onclick="window.print()">Print</button></p>
</div>
</body>
</html>
