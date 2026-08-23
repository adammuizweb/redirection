<?php
declare(strict_types=1);

function jyr_path_is_file_like(string $path): bool
{
    if (function_exists('url_path_is_file_like')) return url_path_is_file_like($path);
    return preg_match('#(?:^|/)[^/]+\.(?:[a-z0-9]{1,10}|webmanifest)$#i', $path) === 1;
}

function jyr_normalize_source_path(string $input, bool $allowRoot = false): ?string
{
    $input = trim($input);
    if ($input === '' || strlen($input) > 2048 || !str_starts_with($input, '/') || str_starts_with($input, '//')) return null;
    if (str_contains($input, '?') || str_contains($input, '#') || str_contains($input, '\\')) return null;
    if (preg_match('/[\x00-\x1F\x7F]/', $input) === 1 || preg_match('/%(?![0-9a-f]{2})/i', $input) === 1) return null;
    if (preg_match('/%(?:2f|5c|00|0a|0d)/i', $input) === 1) return null;

    $decoded = rawurldecode($input);
    if (!mb_check_encoding($decoded, 'UTF-8') || strlen($decoded) > 512) return null;
    if (preg_match('/[\x00-\x1F\x7F]/', $decoded) === 1 || str_contains($decoded, '\\') || str_contains($decoded, '?') || str_contains($decoded, '#')) return null;

    if ($decoded === '/') return $allowRoot ? '/' : null;
    if (str_contains(trim($decoded, '/'), '//')) return null;

    $segments = explode('/', trim($decoded, '/'));
    if ($segments === [] || in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)) return null;

    $path = '/' . implode('/', $segments);
    if (!jyr_path_is_file_like($path)) $path .= '/';
    return strlen($path) <= 512 ? $path : null;
}

function jyr_encode_path(string $decodedPath): string
{
    if ($decodedPath === '/') return '/';
    $trailing = str_ends_with($decodedPath, '/');
    $segments = array_map('rawurlencode', explode('/', trim($decodedPath, '/')));
    return '/' . implode('/', $segments) . ($trailing ? '/' : '');
}

function jyr_normalize_target_url(string $input): ?string
{
    $input = trim($input);
    if ($input === '' || strlen($input) > 2048 || !str_starts_with($input, '/') || str_starts_with($input, '//')) return null;
    if (str_contains($input, '\\') || preg_match('/[\x00-\x20\x7F]/', $input) === 1) return null;

    $parts = parse_url($input);
    if (!is_array($parts) || isset($parts['scheme'], $parts['host'])) return null;
    $rawPath = (string)($parts['path'] ?? '/');
    $path = jyr_normalize_source_path($rawPath, true);
    if ($path === null) return null;

    $target = jyr_encode_path($path);
    if (isset($parts['query']) && $parts['query'] !== '') $target .= '?' . $parts['query'];
    if (isset($parts['fragment']) && $parts['fragment'] !== '') $target .= '#' . $parts['fragment'];
    return strlen($target) <= 2048 ? $target : null;
}

function jyr_target_path(string $target): ?string
{
    $parts = parse_url($target);
    if (!is_array($parts)) return null;
    return jyr_normalize_source_path((string)($parts['path'] ?? '/'), true);
}

function jyr_source_conflict(PDO $pdo, string $source): ?array
{
    $path = trim($source, '/');
    try {
        if (function_exists('content_route_is_valid_path') && content_route_is_valid_path($path)
            && function_exists('content_route_path_conflict')) {
            return content_route_path_conflict($pdo, $path);
        }

        $first = strtolower((string)(explode('/', $path, 2)[0] ?? ''));
        if (in_array($first, ['author', 'private', 'plugins', 'posts', 'static'], true)
            || preg_match('/^\d{4}$/', $first) === 1
            || strtolower($path) === 'sw.js' || str_starts_with(strtolower($path), 'sitemap')) {
            return ['type' => 'core_route'];
        }
        foreach ([
            ['admin_path', 'dashboard', true],
            ['login_path', 'login', false],
            ['register_path', 'register', false],
            ['posts_list_path', 'artikel', true],
            ['pages_list_path', 'halaman', true],
            ['category_path', 'category', true],
        ] as [$key, $default, $prefix]) {
            $value = function_exists('settings_get') ? trim((string)(settings_get($pdo, $key, $default) ?? $default), '/') : $default;
            if ($value !== '' && ($path === $value || ($prefix && str_starts_with($path, $value . '/')))) return ['type' => 'setting_route'];
        }
        if (function_exists('get_frontend_routes')) {
            foreach (array_keys((array)get_frontend_routes()) as $prefix) {
                $prefix = trim((string)$prefix, '/');
                if ($prefix !== '' && ($path === $prefix || str_starts_with($path, $prefix . '/'))) return ['type' => 'plugin_route'];
            }
        }
        $publicRoot = defined('PUBLIC_PATH') ? (string)PUBLIC_PATH : '';
        if ($publicRoot !== '') {
            $candidate = rtrim($publicRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
            $firstCandidate = rtrim($publicRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $first;
            if (file_exists($candidate) || is_link($candidate) || is_dir($firstCandidate) || is_link($firstCandidate)) return ['type' => 'physical_path'];
        }
        $stmt = $pdo->prepare("SELECT id FROM posts WHERE slug = ? AND type IN ('article','page','theme') AND is_deleted = 0 LIMIT 1");
        $stmt->execute([$path]);
        return $stmt->fetchColumn() ? ['type' => 'post_slug'] : null;
    } catch (Throwable $e) {
        error_log('[redirection] route conflict check failed: ' . $e->getMessage());
        return ['type' => 'unknown'];
    }
}

function jyr_request_source_path(): ?string
{
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
    $rawWithoutQuery = explode('?', $uri, 2)[0];
    if (str_starts_with($rawWithoutQuery, '//')) return null;
    $rawPath = parse_url($uri, PHP_URL_PATH);
    if (!is_string($rawPath) || preg_match('/%(?![0-9a-f]{2})/i', $rawPath) === 1
        || preg_match('/%(?:2f|5c|00|0a|0d)/i', $rawPath) === 1) return null;
    return jyr_normalize_source_path($rawPath);
}

function jyr_request_uri_path_is_safe(): bool
{
    if (!isset($_SERVER['REQUEST_URI'])) return true;
    return jyr_request_source_path() !== null;
}

function jyr_404_path_is_excluded(PDO $pdo, string $path): bool
{
    $trimmed = trim($path, '/');
    $first = strtolower((string)(explode('/', $trimmed, 2)[0] ?? ''));
    if (in_array($first, ['static', 'private', 'plugins', 'api', 'pondasi'], true)) return true;
    if (in_array(strtolower($trimmed), ['sw.js', 'manifest.webmanifest', 'favicon.ico', 'robots.txt'], true)) return true;
    if (str_starts_with(strtolower($trimmed), 'sitemap')) return true;
    if (function_exists('get_frontend_routes')) {
        foreach (array_keys((array)get_frontend_routes()) as $prefix) {
            $prefix = trim((string)$prefix, '/');
            if ($prefix !== '' && ($trimmed === $prefix || str_starts_with($trimmed, $prefix . '/'))) return true;
        }
    }

    foreach (['get_admin_path', 'get_login_path', 'get_register_path'] as $resolver) {
        if (!function_exists($resolver)) continue;
        try {
            $reserved = trim((string)$resolver($pdo), '/');
            if ($reserved !== '' && ($trimmed === $reserved || str_starts_with($trimmed, $reserved . '/'))) return true;
        } catch (Throwable $e) {
            error_log('[redirection] sensitive route exclusion failed: ' . $e->getMessage());
            return true;
        }
    }
    return false;
}
