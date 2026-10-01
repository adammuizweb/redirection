<?php
declare(strict_types=1);

if (!defined('BACKEND_PATH')) return;

const JYR_VERSION = '1.3.1';
const JYR_SCHEMA_VERSION = '1';
const JYR_RULES_TABLE = 'jyr_redirects';
const JYR_LOGS_TABLE = 'jyr_404_paths';
const JYR_MAX_404_PATHS = 10000;
const JYR_ADMIN_PAGE_SIZES = [10, 20, 50, 100, 200];

function jyr_admin_page_size(mixed $value): int
{
    if (is_int($value)) {
        $size = $value;
    } elseif (is_string($value) && preg_match('/\A\d+\z/D', $value) === 1) {
        $size = (int)$value;
    } else {
        return 20;
    }
    return in_array($size, JYR_ADMIN_PAGE_SIZES, true) ? $size : 20;
}

function jyr_t(string $source, mixed ...$args): string
{
    $translated = function_exists('__') ? __($source) : $source;
    if ($translated === $source) {
        $locale = function_exists('get_locale') ? strtolower((string)get_locale()) : 'en';
        $locale = explode('-', str_replace('_', '-', $locale), 2)[0];
        if (in_array($locale, ['id', 'de'], true)) {
            static $catalogs = [];
            if (!isset($catalogs[$locale])) {
                $file = __DIR__ . '/languages/' . $locale . '.php';
                $catalog = is_file($file) ? require $file : [];
                $catalogs[$locale] = is_array($catalog) ? $catalog : [];
            }
            $translated = (string)($catalogs[$locale][$source] ?? $source);
        }
    }
    return $args === [] ? $translated : sprintf($translated, ...$args);
}

function jyr_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

require_once __DIR__ . '/includes/schema.php';
require_once __DIR__ . '/includes/normalize.php';
require_once __DIR__ . '/includes/repository.php';
require_once __DIR__ . '/includes/runtime.php';

if (function_exists('register_frontend_route')) {
    register_frontend_route('jyr-redirect-dispatch', 'jyr_content_override_dispatch', ['match' => 'exact', 'methods' => ['GET', 'HEAD']]);
}

function jyr_bootstrap(): void
{
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) return;
    jyr_ensure_schema($pdo);
}

function jyr_admin_assets(): void
{
    $page = trim((string)($_GET['page'] ?? ''), '/');
    if ($page !== 'admin/tools/redirection') return;
    echo '<link rel="stylesheet" href="/static/plugins/redirection/admin.css?v=' . rawurlencode(JYR_VERSION) . '">';
    echo '<script defer src="/static/plugins/redirection/admin.js?v=' . rawurlencode(JYR_VERSION) . '"></script>';
}

function jyr_uninstall(string $name): void
{
    if ($name !== 'redirection') return;
    $pdo = $GLOBALS['pdo'] ?? null;
    if ($pdo instanceof PDO) jyr_drop_schema($pdo);
}

add_action('plugins_loaded', 'jyr_bootstrap');
add_action('init', 'jyr_register_404_capture');
add_action('admin_head', 'jyr_admin_assets');
add_action('plugin_uninstall', 'jyr_uninstall');
add_filter('router_path', 'jyr_content_override_path', PHP_INT_MAX);
add_filter('unresolved_content_redirect_url', 'jyr_unresolved_redirect', PHP_INT_MAX);
