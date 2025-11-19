<?php
// db.php - SQLite connection and bootstrap (creates tables if missing)
declare(strict_types=1);

$dbPath = '../../data/app.db';
$needInit = !file_exists($dbPath);

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die('SQLite connection error: ' . htmlspecialchars($e->getMessage()));
}

if ($needInit) {
    $pdo->exec("PRAGMA journal_mode=WAL;");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            id INTEGER PRIMARY KEY CHECK (id=1),
            admin_user TEXT NOT NULL,
            admin_pass_hash TEXT NOT NULL
        );
    ");
    $stmt = $pdo->prepare("INSERT INTO settings (id, admin_user, admin_pass_hash) VALUES (1, :u, :h)");
    $stmt->execute([':u'=>'joshh@culinarydepot.com', ':h'=>password_hash('rlaJrAB8bX^p0kwP&NK$ei', PASSWORD_DEFAULT)]);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            created_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS puzzles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            width INTEGER NOT NULL,
            height INTEGER NOT NULL,
            grid TEXT NOT NULL,      -- JSON of rows
            words TEXT NOT NULL,     -- JSON array
            allow_diagonal INTEGER NOT NULL DEFAULT 1,
            show_answers INTEGER NOT NULL DEFAULT 0,
            show_wordlist INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL,
            description TEXT DEFAULT ''
        );
        CREATE TABLE IF NOT EXISTS results (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            puzzle_id INTEGER NOT NULL,
            user_name TEXT NOT NULL,
            user_email TEXT NOT NULL,
            started_at TEXT NOT NULL,
            finished_at TEXT,
            duration_sec INTEGER,
            found_words INTEGER DEFAULT 0,
            selections TEXT,         -- JSON of found paths
            FOREIGN KEY(puzzle_id) REFERENCES puzzles(id)
        );
    ");
}
try {
    $cols = [];
    foreach ($pdo->query("PRAGMA table_info(results)")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $cols[] = $c['name'];
    }
    if (!in_array('grid', $cols))         { $pdo->exec("ALTER TABLE results ADD COLUMN grid TEXT"); }
    if (!in_array('answer_mask', $cols))  { $pdo->exec("ALTER TABLE results ADD COLUMN answer_mask TEXT"); }
    if (!in_array('seed', $cols))         { $pdo->exec("ALTER TABLE results ADD COLUMN seed TEXT"); }
    
        $cols = array_column($pdo->query("PRAGMA table_info(puzzles)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('description', $cols, true)) {
        $pdo->exec("ALTER TABLE puzzles ADD COLUMN description TEXT DEFAULT ''");
    }
    
} catch (Throwable $e) {
    // ignore if already added / legacy sqlite
}

function is_adminword(): bool {
    return isset($_SESSION['adminword']) && $_SESSION['adminword'] === true;
}

function require_adminword() {
    if (!is_adminword()) {
        http_response_code(401);
        echo json_encode(['ok'=>false, 'error'=>'Unauthorized']);
        exit;
    }
}

function json_body() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function sanitize_words($text): array {
    $words = array_filter(array_map('trim', preg_split('/\r?\n|,/', (string)$text)));
    $words = array_values(array_unique(array_map(function($w){ return mb_strtoupper(preg_replace('/[^a-zA-Z]/', '', $w)); }, $words)));
    return $words;
}

function ws_generate_personal_grid(array $gridTemplate, array $words, int $width, int $height, bool $allowDiagonal, string $seedKey): array {
    // Deterministic RNG based on seedKey
    $seed = hexdec(substr(hash('sha256', $seedKey), 0, 12)) % PHP_INT_MAX;
    mt_srand($seed, MT_RAND_MT19937);

    // Recreate placement (longest words first)
    $dirs = [[1,0],[0,1],[-1,0],[0,-1]];
    if ($allowDiagonal) { $dirs = array_merge($dirs, [[1,1],[1,-1],[-1,1],[-1,-1]]); }
    usort($words, fn($a,$b) => mb_strlen($b)-mb_strlen($a));

    $grid = array_fill(0, $height, array_fill(0, $width, ''));
    $mask = array_fill(0, $height, array_fill(0, $width, 0));

    foreach ($words as $w) {
        $placed = false;
        for ($attempt=0; $attempt<600 && !$placed; $attempt++) {
            [$dx,$dy] = $dirs[array_rand($dirs)];
            $x = mt_rand(0,$width-1);
            $y = mt_rand(0,$height-1);
            $len = mb_strlen($w);
            $endx = $x + $dx*($len-1);
            $endy = $y + $dy*($len-1);
            if ($endx<0 || $endx>=$width || $endy<0 || $endy>=$height) continue;

            $ok = true;
            for ($i=0;$i<$len;$i++){
                $cx=$x+$dx*$i;$cy=$y+$dy*$i;
                $ch = $grid[$cy][$cx];
                $wch = mb_substr($w,$i,1);
                if ($ch!=='' && $ch!==$wch) { $ok=false; break; }
            }
            if (!$ok) continue;

            for ($i=0;$i<$len;$i++){
                $cx=$x+$dx*$i;$cy=$y+$dy*$i;
                $wch = mb_substr($w,$i,1);
                $grid[$cy][$cx] = $wch;
                $mask[$cy][$cx] = 1;
            }
            $placed = true;
        }
        if (!$placed) {
            // fallback: leave as is; caller can detect sparse placement if desired
        }
    }
    // Fill blanks
    $alphabet = range('A','Z');
    for ($yy=0;$yy<$height;$yy++){
        for ($xx=0;$xx<$width;$xx++){
            if ($grid[$yy][$xx]==='') $grid[$yy][$xx] = $alphabet[array_rand($alphabet)];
        }
    }
    // Reseed global RNG to avoid affecting others
    mt_srand();

    return [$grid, $mask, (string)$seed];
}


?>
