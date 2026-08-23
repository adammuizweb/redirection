<?php
declare(strict_types=1);

function jyr_unresolved_redirect(string $existing, string $path, PDO $pdo): string
{
    if ($existing !== '' || !jyr_schema_is_ready()) return $existing;
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'HEAD'], true)) return '';
    if (!jyr_request_uri_path_is_safe()) return '';

    try {
        $source = jyr_normalize_source_path('/' . trim($path, '/'));
        if ($source === null) return '';
        $rule = jyr_find_active_rule($pdo, $source);
        if (!$rule || jyr_source_conflict($pdo, $source) !== null) return '';

        $target = jyr_normalize_target_url((string)$rule['target_url']);
        if ($target === null || jyr_target_path($target) === $source || !jyr_redirect_chain_is_safe($pdo, $source, $target)) return '';

        try {
            jyr_increment_hit($pdo, (int)$rule['id']);
        } catch (Throwable $counterError) {
            error_log('[redirection] hit counter error: ' . $counterError->getMessage());
        }
        return $target;
    } catch (Throwable $e) {
        error_log('[redirection] runtime error: ' . $e->getMessage());
        return '';
    }
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
