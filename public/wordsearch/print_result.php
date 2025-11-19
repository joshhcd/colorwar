<?php
session_start();
require_once __DIR__ . '/db.php';
// You can use require_admin(); or require_saml(); your choice:
if (!is_adminword()) {
    header('Location: admin.php');
    exit;
}

$rid = (int)($_GET['result_id'] ?? 0);
$rst = $pdo->prepare("SELECT r.*, p.title FROM results r JOIN puzzles p ON p.id=r.puzzle_id WHERE r.id=?");
$rst->execute([$rid]);
$row = $rst->fetch(PDO::FETCH_ASSOC);
if (!$row) die('Result not found');

$grid = json_decode($row['grid'] ?? '[]', true);
$mask = json_decode($row['answer_mask'] ?? '[]', true);
if (!is_array($grid) || !$grid) die('No personalized grid saved for this result');

$h = count($grid);
$w = count($grid[0] ?? []);
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Print — <?= htmlspecialchars($row['title']) ?> — <?= htmlspecialchars($row['user_name']) ?></title>
<style>
  body { background:#fff; color:#000; font-family: system-ui, Arial, sans-serif; }
  h2 { margin: 0 0 8px 0; }
  .grid { border-collapse: collapse; }
  .grid td { border:1px solid #000; width:24px; height:24px; text-align:center; font-family:monospace; }
  .answer { background:#ddd; }
  @media print { .no-print{display:none} }
</style>
</head>
<body>
<h2><?= htmlspecialchars($row['title']) ?> — <?= htmlspecialchars($row['user_name']) ?> (<?= htmlspecialchars($row['user_email']) ?>)</h2>
<table class="grid">
<?php for ($y=0;$y<$h;$y++): ?><tr>
  <?php for ($x=0;$x<$w;$x++): $ans = (!empty($mask[$y][$x])) ? 'answer' : ''; ?>
    <td class="<?= $ans ?>"><?= htmlspecialchars($grid[$y][$x]) ?></td>
  <?php endfor; ?>
</tr><?php endfor; ?>
</table>
<p class="no-print"><button onclick="print()">Print</button></p>
</body>
</html>
