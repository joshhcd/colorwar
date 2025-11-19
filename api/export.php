<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';
require_admin();

$type = $_GET['type'] ?? 'points';
$fname = 'export-' . $type . '-' . date('Ymd-His') . '.csv';

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $fname . '"');

$out = fopen('php://output', 'w');

if ($type === 'points') {
    fputcsv($out, ['created_at','amount','reason','source','user_email','user_name','team_slug','team_name']);
    $rows = q("
      SELECT p.created_at, p.amount, p.reason, p.source, u.email, u.name, t.slug, t.name AS team
      FROM points p
      LEFT JOIN users u ON u.id = p.user_id
      LEFT JOIN teams t ON t.id = p.team_id
      ORDER BY p.id DESC
    ")->fetchAll();
    foreach ($rows as $r) {
        fputcsv($out, [$r['created_at'],$r['amount'],$r['reason'],$r['source'],$r['email'],$r['name'],$r['slug'],$r['team']]);
    }
} elseif ($type === 'users') {
    fputcsv($out, ['email','name','team_slug','active','external_user_id']);
    $rows = q("
      SELECT u.email, u.name, t.slug AS team_slug, u.is_active, u.external_user_id
      FROM users u
      LEFT JOIN teams t ON t.id = u.team_id
      ORDER BY u.email
    ")->fetchAll();
    foreach ($rows as $r) {
        fputcsv($out, [$r['email'],$r['name'],$r['team_slug'],$r['is_active'],$r['external_user_id']]);
    }
} else {
    fputcsv($out, ['id','unknown_type']);
}

fclose($out);
