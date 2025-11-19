<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';

header('Content-Type: application/json');

$token = $_GET['token'] ?? '';
if ($token === '') { http_response_code(401); echo json_encode(['error'=>'missing token']); exit; }

$int = q("SELECT * FROM integrations WHERE token=? AND is_enabled=1", [$token])->fetch();
if (!$int) { http_response_code(403); echo json_encode(['error'=>'invalid token']); exit; }

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) { http_response_code(400); echo json_encode(['error'=>'invalid json']); exit; }

$event = trim((string)($data['event'] ?? ''));
if ($int['match_event'] && $event !== $int['match_event']) {
    http_response_code(400); echo json_encode(['error'=>'event does not match integration']); exit;
}

$points = isset($data['points']) ? (int)$data['points'] : (int)$int['default_points'];
$reason = isset($data['reason']) ? trim((string)$data['reason']) : ('integration:' . ($event ?: 'event'));
$meta = $data['meta'] ?? null;
$email = isset($data['email']) ? trim((string)$data['email']) : '';
$external = isset($data['external_user_id']) ? trim((string)$data['external_user_id']) : '';
$team_slug = isset($data['team_slug']) ? trim((string)$data['team_slug']) : '';

$user_id = null; $team_id = null;
if ($int['target'] === 'user') {
    if ($email !== '') {
        $u = q("SELECT id FROM users WHERE email=? AND is_active=1", [$email])->fetch();
        if ($u) $user_id = (int)$u['id'];
    }
    if (!$user_id && $external !== '') {
        $u = q("SELECT id FROM users WHERE external_user_id=? AND is_active=1", [$external])->fetch();
        if ($u) $user_id = (int)$u['id'];
    }
    if (!$user_id && $team_slug !== '') {
        // fallback to team-level if no user found
        $t = q("SELECT id FROM teams WHERE slug=?", [$team_slug])->fetch();
        if ($t) $team_id = (int)$t['id'];
    }
} else {
    // target=team (enforced to exist on integration)
    $team_id = $int['team_id'] ? (int)$int['team_id'] : null;
    if (!$team_id && $team_slug !== '') {
        $t = q("SELECT id FROM teams WHERE slug=?", [$team_slug])->fetch();
        if ($t) $team_id = (int)$t['id'];
    }
}

if (!$user_id && !$team_id) {
    http_response_code(404);
    echo json_encode(['error'=>'no user/team matched']);
    exit;
}

q("INSERT INTO points (user_id, team_id, amount, reason, source) VALUES (?,?,?,?,?)",
  [$user_id, $team_id, $points, $reason, 'webhook']);

q("INSERT INTO audit_log (actor_user_id, action, entity_type, details) VALUES (?,?,?,?)",
  [null, 'webhook', 'points', json_encode(['integration_id'=>(int)$int['id'], 'event'=>$event, 'email'=>$email, 'external'=>$external, 'team_slug'=>$team_slug, 'points'=>$points, 'meta'=>$meta])]);

echo json_encode(['ok'=>true, 'applied_points'=>$points, 'user_id'=>$user_id, 'team_id'=>$team_id]);
