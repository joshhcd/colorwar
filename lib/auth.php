<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// Set session cookie params
session_name(SESSION_NAME);
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

function current_user(): ?array {
    if (!empty($_SESSION['user_id'])) {
        $stmt = q("SELECT id, name, email, role FROM users WHERE id = ? AND is_active = 1", [$_SESSION['user_id']]);
        $user = $stmt->fetch();
        if ($user) { return $user; }
    }
    return null;
}

function is_admin(): bool {
    $u = current_user();
    return $u && in_array($u['role'], ['admin', 'manager'], true);
}

function require_admin(): void {
    if (!is_admin()) {
        header('Location: ' . (BASE_URL . '/admin/login.php'));
        exit;
    }
}

function login(string $email, string $password): bool {
    $stmt = q("SELECT id, email, password_hash, role FROM users WHERE email = ? AND is_active = 1", [$email]);
    $u = $stmt->fetch();
    if ($u && isset($u['password_hash']) && password_verify($password, $u['password_hash'])) {
        $_SESSION['user_id'] = (int)$u['id'];
        session_regenerate_id(true);
        return true;
    }
    return false;
}

function logout(): void {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}
