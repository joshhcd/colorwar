<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = ['']; // add your domain here
if (in_array($origin, $allowed, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: X-TV-Token');
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
/**
 * TV-token auth (no SAML). Accepts:
 * - HTTP header: X-TV-Token
 * - Query param: ?token=...
 * - Cookie: tv_token (set after first successful visit with ?token=)
 *
 * Configure allowed tokens via:
 *   - env TV_TOKENS="hex1,hex2"
 *   - define('TV_TOKENS', 'hex1, hex2');  or define('TV_TOKEN', 'hex1');
 */
define('TV_TOKEN', '<HEXTOKEN>');

function tv_allowed_tokens(): array {
    $raw = getenv('TV_TOKENS');
    if (!$raw && defined('TV_TOKENS')) $raw = TV_TOKENS;
    if (!$raw && defined('TV_TOKEN'))  $raw = TV_TOKEN;
    $list = array_filter(array_map('trim', preg_split('/[,\s]+/', (string)$raw)));
    return array_values(array_unique($list));
}

$allowed = tv_allowed_tokens();
if (empty($allowed)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "TV dashboard token is not configured. Set TV_TOKENS env var or TV_TOKEN/TV_TOKENS in lib/config.php.";
    exit;
}

$provided = $_SERVER['HTTP_X_TV_TOKEN'] ?? ($_GET['token'] ?? ($_COOKIE['tv_token'] ?? ''));

if (!in_array($provided, $allowed, true)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden. Provide a valid token via ?token=... or X-TV-Token header.";
    exit;
}

// If arriving with ?token=... set a short-lived, HttpOnly cookie and redirect to remove token from URL
if (isset($_GET['token'])) {
    $cookiePath = (string)(BASE_URL ?: '/');
    if ($cookiePath === '' || $cookiePath[0] !== '/') $cookiePath = '/';
    setcookie('tv_token', $provided, [
        'expires'  => time() + 60*60*24*7, // 7 days
        'path'     => $cookiePath,
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $cleanUrl = strtok($_SERVER['REQUEST_URI'], '?');
    header('Location: ' . $cleanUrl);
    exit;
}

// --- Fetch team totals only (no individual leaderboard) ---
$teams = q("
SELECT t.id, t.name, t.slug, t.color,
  COALESCE((SELECT SUM(p.amount) FROM points p WHERE p.team_id = t.id),0) +
  COALESCE((SELECT SUM(p2.amount) FROM points p2
           JOIN users u ON u.id = p2.user_id
           WHERE u.team_id = t.id),0) AS total_points,
  (SELECT COUNT(1) FROM users u WHERE u.team_id = t.id AND u.is_active = 1) AS members
FROM teams t
ORDER BY total_points DESC, t.name ASC
")->fetchAll();

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= e(APP_NAME) ?> · TV Dashboard</title>
  <link rel="stylesheet" href="<?= e(BASE_URL) ?>/style.css">
  <style>
    /* TV-friendly sizing */
    body { background:#0b1020; }
    .topbar { border-bottom: 0; }
    .brand { font-size: 26px; }
    h2 { font-size: 28px; margin: 10px 0 14px; }
    .grid {
      display:grid;
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
      gap:14px;
    }
    .team-card {
      display:flex; flex-direction:column; align-items:flex-start; justify-content:center;
      min-height: 160px;
    }
    .team-card h3 { font-size: 28px; margin: 0 0 8px; }
    .team-card .meta { opacity:.7; margin-bottom:8px }
    .team-score {
      font-weight: 800;
      font-size: 54px;
      line-height: 1;
      margin-top: 8px;
      letter-spacing: 1px;
    }
    .ticker {
      position:fixed; left:0; right:0; bottom:0;
      background:#0a0f1c; border-top:1px solid #1f2a44; padding:8px 14px;
      color:#8aa1c2; font-size:14px;
      display:flex; justify-content:space-between; align-items:center;
    }
    .muted { opacity: .75; }
  </style>
  <script>
    // Auto-refresh every 30s for TV loop
    setInterval(() => { location.reload(); }, 30000);
  </script>
</head>
<body>
<div class="container" style="max-width:1280px">
  <div class="topbar">
      <img src="<INSERTLOGO>" style="display:center;width:250px">
    <div class="brand">
        <span><?= e(APP_NAME) ?></span>
    </div>
    <div class="toolbar">
      <!-- intentionally no auth buttons; token-only access -->
    </div>
  </div>

  <div class="board">
    <div class="grid">
      <?php foreach ($teams as $t): ?>
        <?php $badge = $t['color'] ?: '#38405f'; ?>
        <div class="card team-card">
          <h3><?= e($t['name']) ?></h3>
          <div class="meta"><?= (int)$t['members'] ?> members</div>
          <div class="team-badge" style="background:<?= e($badge) ?>22;border-color:<?= e($badge) ?>;color:#fff">
            <?= e($t['slug']) ?>
          </div>
          <div class="team-score"><?= (int)$t['total_points'] ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="ticker">
  <div class="muted">Auto-refreshing…</div>
</div>
</body>
</html>
