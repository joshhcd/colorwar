<?php
declare(strict_types=1);

/** HTML escape */
function e(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Redirect and exit */
function redirect(string $path): void {
    if (!str_starts_with($path, 'http')) {
        $base = defined('BASE_URL') ? BASE_URL : '';
        if ($base && !str_starts_with($path, $base)) {
            $path = rtrim($base, '/') . '/' . ltrim($path, '/');
        }
    }
    header('Location: ' . $path);
    exit;
}

/** Get POST param trimmed */
function post(string $key, ?string $default = null): ?string {
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

/** Random string */
function random_token(int $length = 32): string {
    return bin2hex(random_bytes($length));
}

/** Slugify a name to a URL-safe, lowercase slug */
function slugify(string $text): string {
    $text = strtolower($text);
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    $text = trim($text, '-');
    $text = preg_replace('~[^-\w]+~', '', $text);
    $text = preg_replace('~-+~', '-', $text);
    return $text ?: 'n-a';
}

/** Simple pagination helpers */
function paginate(int $page, int $perPage = 25): array {
    $page = max(1, $page);
    $offset = ($page - 1) * $perPage;
    return [$offset, $perPage];
}

/** Format integer points with sign */
function fmt_points(int $n): string {
    return ($n >= 0 ? '+' : '') . (string)$n;
}

/** Ensure directory exists */
function ensure_dir(string $path): void {
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
    }
}
