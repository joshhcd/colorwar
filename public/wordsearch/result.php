<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../../lib/bootstrap.php';

if (empty($_SESSION['saml_authenticated'])) {
  header('Location: ' . ('/../saml/login.php'));
  exit;
}


header('Content-Type: application/json');
$data = json_body();
$id = (int)($data['id'] ?? 0);
if (!$id) { echo json_encode(['ok'=>false]); exit; }

$found = (int)($data['found'] ?? 0);
$sec = (int)($data['seconds'] ?? 0);
$selections = json_encode($data['selections'] ?? [], JSON_UNESCAPED_UNICODE);

if (!empty($data['done'])) {
    $stmt = $pdo->prepare("UPDATE results SET found_words=:f, duration_sec=:s, selections=:sel, finished_at=datetime('now') WHERE id=:id");
} else {
    $stmt = $pdo->prepare("UPDATE results SET found_words=:f, duration_sec=:s, selections=:sel WHERE id=:id");
}
$stmt->execute([':f'=>$found, ':s'=>$sec, ':sel'=>$selections, ':id'=>$id]);
echo json_encode(['ok'=>true]);
