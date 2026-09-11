<?php
declare(strict_types=1);

function jyr_validate_redirect_target(PDO $pdo, string $source, array $rule): string
{
    $target = jyr_normalize_target_url((string)$rule['target_url'], true);
    if ($target === null || jyr_external_target_policy_error($target, true, true) !== null
        || jyr_target_path($target) === $source || !jyr_redirect_chain_is_safe($pdo, $source, $target)) return '';
    return $target;
}

function jyr_resolve_redirect_target(PDO $pdo, string $source): string
{
    $rule = jyr_find_active_rule($pdo, $source);
    if (!$rule) return '';
    $target = jyr_validate_redirect_target($pdo, $source, $rule);
    if ($target === '') return '';

    try {
        jyr_increment_hit($pdo, (int)$rule['id']);
    } catch (Throwable $counterError) {
        error_log('[redirection] hit counter error: ' . $counterError->getMessage());
    }
    return $target;
}

function jyr_unresolved_redirect(string $existing, string $path, PDO $pdo): string
{
    if ($existing !== '' || !jyr_schema_is_ready()) return $existing;
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'HEAD'], true)) return '';
    if (!jyr_request_uri_path_is_safe()) return '';

    try {
        $source = jyr_normalize_source_path('/' . trim($path, '/'));
        if ($source === null) return '';
        if (jyr_source_conflict($pdo, $source) !== null) return '';
        return jyr_resolve_redirect_target($pdo, $source);
    } catch (Throwable $e) {
        error_log('[redirection] runtime error: ' . $e->getMessage());
        return '';
    }
}

function jyr_content_override_path(string $path): string
{
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'HEAD'], true) || !jyr_schema_is_ready()) return $path;
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) return $path;

    $source = jyr_normalize_source_path('/' . trim($path, '/'));
    if ($source === null) return $path;
    $locale = (string)($GLOBALS['ct_request_locale'] ?? '');
    $conflict = jyr_source_conflict($pdo, $source, $locale);
    if (!jyr_content_route_can_be_overridden($conflict) || jyr_plugin_route_conflict($source) !== null) return $path;
    $rule = jyr_find_active_rule($pdo, $source);
    if (!$rule || jyr_validate_redirect_target($pdo, $source, $rule) === '') return $path;

    $GLOBALS['jyr_content_override_source'] = $source;
    return 'jyr-redirect-dispatch';
}

function jyr_content_override_dispatch(PDO $pdo): void
{
    $source = (string)($GLOBALS['jyr_content_override_source'] ?? '');
    $target = $source !== '' ? jyr_resolve_redirect_target($pdo, $source) : '';
    if ($target === '' || !function_exists('url_append_query_string')) {
        http_response_code(404);
        return;
    }
    $target = url_append_query_string($target, (string)($_SERVER['QUERY_STRING'] ?? ''));
    if ($target === '') {
        http_response_code(404);
        return;
    }
    header('Location: ' . $target, true, 301);
}

function jyr_register_404_capture(): void
{
    if (!jyr_schema_is_ready() || PHP_SAPI === 'cli') return;
    register_shutdown_function('jyr_capture_404');
}

function jyr_capture_404(): void
{
    if (http_response_code() !== 404 || !jyr_schema_is_ready()) return;
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'HEAD'], true)) return;

    foreach (headers_list() as $header) {
        if (stripos($header, 'Content-Type:') !== 0) continue;
        if (stripos($header, 'text/html') === false) return;
    }

    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) return;
    $path = jyr_request_source_path();
    if ($path === null || jyr_404_path_is_excluded($pdo, $path)) return;

    try {
        jyr_record_404($pdo, $path);
    } catch (Throwable $e) {
        error_log('[redirection] 404 capture error: ' . $e->getMessage());
    }
}
