<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';
require_admin();
verify_csrf();

$email = trim($_POST['email'] ?? '');
$team_slug = trim($_POST['team_slug'] ?? '');
$amount = (int)($_POST['amount'] ?? 0);
$reason = trim($_POST['reason'] ?? '');

if ($email === '' && $team_slug === '') {
    http_response_code(400);
    echo "Email or team required"; exit;
}
if ($amount === 0) { http_response_code(400); echo "Points cannot be 0"; exit; }

$user_id = null; $team_id = null;

if ($email !== '') {
    $u = q("SELECT id FROM users WHERE email=? AND is_active=1", [$email])->fetch();
    if (!$u) { http_response_code(404); echo "User not found or inactive"; exit; }
    $user_id = (int)$u['id'];
}
if ($team_slug !== '') {
    $t = q("SELECT id FROM teams WHERE slug=?", [$team_slug])->fetch();
    if (!$t) { http_response_code(404); echo "Team not found"; exit; }
    $team_id = (int)$t['id'];
}

q("INSERT INTO points (user_id, team_id, amount, reason, source, created_by) VALUES (?,?,?,?,?,?)",
  [$user_id, $team_id, $amount, $reason ?: null, 'manual', current_user()['id']]);

q("INSERT INTO audit_log (actor_user_id, action, entity_type, details) VALUES (?,?,?,?)",
  [current_user()['id'], 'award', 'points', json_encode(['email'=>$email,'team_slug'=>$team_slug,'amount'=>$amount,'reason'=>$reason])]);

redirect(BASE_URL . '/admin/points.php');
