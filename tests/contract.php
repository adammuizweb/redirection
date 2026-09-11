<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$GLOBALS['_jyr_hooks'] = [];
$GLOBALS['_jyr_routes'] = [];
$GLOBALS['_jyr_locale'] = 'en';

define('BACKEND_PATH', '/tmp');
function __(string $source): string { return $source; }
function get_locale(): string { return (string)$GLOBALS['_jyr_locale']; }
function add_action(string $name, callable $callback, int $priority = 10): void { $GLOBALS['_jyr_hooks'][$name][] = $callback; }
function add_filter(string $name, callable $callback, int $priority = 10): void { $GLOBALS['_jyr_hooks'][$name][] = $callback; }
function register_frontend_route(string $path, callable|string $handler, array $options = []): bool {
    $GLOBALS['_jyr_routes'][] = ['path' => trim($path, '/'), 'handler' => $handler] + $options;
    return true;
}
function get_frontend_route_definitions(): array { return $GLOBALS['_jyr_routes']; }
function plugin_frontend_route_normalize_path(string $path): ?string {
    $path = trim($path, '/');
    return preg_match('/\A[A-Za-z0-9._~-]+(?:\/[A-Za-z0-9._~-]+)*\z/D', $path) === 1 ? $path : null;
}
function url_append_query_string(string $target, string $query): string {
    return $query === '' ? $target : $target . (str_contains($target, '?') ? '&' : '?') . $query;
}

require_once $root . '/plugin.php';

$failures = [];
$check = static function (bool $passed, string $message) use (&$failures): void {
    echo ($passed ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$passed) $failures[] = $message;
};

$manifest = json_decode((string)file_get_contents($root . '/plugin.json'), true, 512, JSON_THROW_ON_ERROR);
$manifestObject = json_decode((string)file_get_contents($root . '/plugin.json'), false, 512, JSON_THROW_ON_ERROR);
$check(($manifest['name'] ?? null) === 'redirection', 'plugin slug is stable');
$check(($manifest['version'] ?? null) === JYR_VERSION, 'manifest and runtime versions match');
$check(($manifest['requires']['jyavani'] ?? null) === '>=2.3.91', 'minimum Core version provides exact method-aware frontend routes');
$check(in_array('pdo_mysql', $manifest['requires']['extensions'] ?? [], true), 'MySQL PDO driver requirement is explicit');
$check(($manifestObject->requires->plugins ?? null) instanceof stdClass, 'plugin dependencies use an object map');
$permission = $manifest['permissions'][0] ?? [];
$check(($permission['key'] ?? '') === 'plugin.redirection.rules.manage'
    && ($permission['default_roles'] ?? null) === ['admin']
    && ($permission['delegable'] ?? null) === false, 'redirect management is admin-only and nondelegable');
$check(($manifest['admin']['pages'][0]['permission'] ?? '') === $permission['key'], 'dashboard route uses the declared permission');
$check(($manifest['admin']['pages'][1]['hidden'] ?? false) === true, 'mutation route is hidden from navigation');
$check(isset($GLOBALS['_jyr_hooks']['unresolved_content_redirect_url']), 'unresolved redirect filter is registered');
$check(isset($GLOBALS['_jyr_hooks']['init']), 'bounded 404 capture is registered on init');
$check(isset($GLOBALS['_jyr_hooks']['router_path'])
    && ($GLOBALS['_jyr_routes'][0]['path'] ?? '') === 'jyr-redirect-dispatch'
    && ($GLOBALS['_jyr_routes'][0]['methods'] ?? []) === ['GET', 'HEAD'], 'one static GET/HEAD dispatcher route handles content overrides');
$check(isset($GLOBALS['_jyr_hooks']['plugin_uninstall']), 'data cleanup participates in the Core uninstall contract');

$check(jyr_normalize_source_path('/old-path') === '/old-path/', 'directory-like source receives a canonical trailing slash');
$check(jyr_normalize_source_path('old-path') === '/old-path/', 'source accepts omitted leading and trailing slashes');
$check(jyr_normalize_source_path('old-path/') === '/old-path/', 'source accepts an omitted leading slash');
$check(jyr_normalize_source_path('/old-file.html') === '/old-file.html', 'file-like source remains slashless');
$check(jyr_normalize_source_path('old-file.html/') === '/old-file.html', 'file-like source tolerates omitted leading and extra trailing slash');
$check(jyr_normalize_source_path('/Docs/Old/') === '/Docs/Old/', 'source matching preserves case');
$check(jyr_normalize_source_path('/old%20path/') === '/old path/', 'safe encoded path text is decoded for matching');
$check(jyr_normalize_source_path('/') === null, 'homepage cannot be registered as an unresolved source');
$check(jyr_normalize_source_path('//outside.test/path') === null, 'protocol-relative source is rejected');
$check(jyr_normalize_source_path('/a/../b/') === null, 'dot-segment source is rejected');
$check(jyr_normalize_source_path('/a%2Fb/') === null, 'encoded slash source is rejected');
$check(jyr_normalize_source_path('/a//b/') === null, 'empty source segments are rejected');
$check(jyr_normalize_source_path("/bad\r\npath/") === null, 'header control bytes are rejected');
$check(jyr_normalize_source_path('/old/?key=value') === null, 'query strings cannot participate in source matching');

$check(jyr_normalize_target_url('/new-path/') === '/new-path/', 'internal target is accepted');
$check(jyr_normalize_target_url('new-path') === '/new-path/', 'internal target accepts omitted leading and trailing slashes');
$check(jyr_normalize_target_url('new-path/') === '/new-path/', 'internal target accepts an omitted leading slash');
$check(jyr_normalize_target_url('folder/video.mp4') === '/folder/video.mp4', 'nested internal file target remains slashless');
$check(jyr_normalize_target_url('/new%20path/?fixed=1#details') === '/new%20path/?fixed=1#details', 'encoded target query and fragment are preserved');
$check(jyr_normalize_target_url('google.com/video.mp4') === null, 'external shorthand still requires explicit opt-in');
$check(jyr_normalize_target_url('google.com/video.mp4', true) === 'https://google.com/video.mp4', 'external domain shorthand gains a safe HTTPS scheme');
$check(jyr_normalize_target_url('google.com/video.mp4?fixed=1#details', true) === 'https://google.com/video.mp4?fixed=1#details', 'external shorthand preserves a fixed query and fragment');
$check(jyr_normalize_target_url('127.1/video.mp4', true) === null, 'invalid numeric external shorthand does not fall back internally');
$check(jyr_normalize_target_url('foo..example/video.mp4', true) === null, 'malformed external shorthand does not fall back internally');
$check(jyr_normalize_target_url('/folder.name/video.mp4', true) === '/folder.name/video.mp4', 'a leading slash disambiguates a dotted internal path');
$check(jyr_normalize_target_url('https://example.test/new/') === null, 'external target requires explicit opt-in');
$check(jyr_normalize_target_url('https://Example.test/new/?fixed=1#details', true) === 'https://example.test/new/?fixed=1#details', 'HTTPS external target is accepted with explicit opt-in');
$check(jyr_normalize_target_url('http://example.test/new/', true) === null, 'insecure external target is rejected');
$check(jyr_normalize_target_url('https://user:pass@example.test/new/', true) === null, 'external target credentials are rejected');
$check(jyr_normalize_target_url('https://127.0.0.1/new/', true) === null, 'external IP target is rejected');
$check(jyr_normalize_target_url('https://127.1/new/', true) === null, 'abbreviated IPv4 target is rejected');
$check(jyr_normalize_target_url('https://0177.0.0.1/new/', true) === null, 'octal-looking IPv4 target is rejected');
$check(jyr_normalize_target_url('//example.test/new/') === null, 'protocol-relative target is blocked');
$check(jyr_normalize_target_url('/new path/') === null, 'unsafe target whitespace is rejected');
$check(jyr_normalize_target_url("https://example.test/new/%0d%0aLocation:evil", true) === null, 'encoded header controls are rejected');
$check(jyr_target_path('https://example.test/new/') === null, 'external targets do not enter the internal redirect graph');
unset($_SERVER['HTTP_HOST'], $_SERVER['SERVER_NAME']);
$check(jyr_external_target_policy_error('https://example.test/new/', true, true) === 'same_host', 'external redirects fail closed when the request host is unavailable');
$_SERVER['HTTP_HOST'] = 'site.test';
$check(jyr_external_target_policy_error('https://site.test/new/', true, true) === 'same_host', 'same-host absolute target is rejected before graph bypass');
$check(jyr_external_target_policy_error('https://example.test/new/', false, true) === 'external_consent', 'external target requires destination consent');
$check(jyr_external_target_policy_error('https://example.test/new/', true, false) === 'query_consent', 'external target requires query-forwarding consent');
$check(jyr_external_target_policy_error('https://example.test/new/', true, true) === null, 'external target policy accepts complete consent');

if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->sqliteCreateFunction('NOW', static fn(): string => gmdate('Y-m-d H:i:s'));
    $pdo->exec('CREATE TABLE jyr_redirects (id INTEGER PRIMARY KEY AUTOINCREMENT, source_path TEXT UNIQUE, source_hash BLOB, target_url TEXT, enabled INTEGER, hit_count INTEGER DEFAULT 0, last_hit_at TEXT, created_by INTEGER, updated_by INTEGER, created_at TEXT, updated_at TEXT)');
    $pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT, type TEXT, is_deleted INTEGER)');
    $insert = $pdo->prepare('INSERT INTO jyr_redirects (source_path,source_hash,target_url,enabled) VALUES (?,?,?,1)');
    $insertRule = static function (string $source, string $target) use ($insert): void {
        $insert->bindValue(1, $source);
        $insert->bindValue(2, hash('sha256', $source, true), PDO::PARAM_LOB);
        $insert->bindValue(3, $target);
        $insert->execute();
    };
    foreach ([['/a/', '/b/'], ['/b/', '/c/']] as [$source, $target]) $insertRule($source, $target);
    $check(!jyr_rules_have_loop($pdo), 'acyclic rule graph is accepted');
    $check(jyr_rules_have_loop($pdo, ['id' => 3, 'source_path' => '/c/', 'target_url' => '/a/', 'enabled' => 1]), 'multi-rule redirect loop is detected');
    $check(!jyr_redirect_chain_is_safe($pdo, '/c/', '/a/'), 'runtime defense rejects a corrupted redirect cycle');

    $GLOBALS['jyr_schema_ready'] = true;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/a/?campaign=test';
    $check(jyr_unresolved_redirect('/existing/', 'a', $pdo) === '/existing/', 'an earlier redirect filter is never overridden');
    $check(jyr_unresolved_redirect('', 'a', $pdo) === '/b/', 'exact unresolved source returns its internal target');
    $_SERVER['REQUEST_URI'] = '/a%2Fb/';
    $check(jyr_unresolved_redirect('', 'a/b', $pdo) === '', 'encoded path separators fail closed before Core-decoded fallback');
    $insertRule('/rewrite/', '/target/');
    $_SERVER['REQUEST_URI'] = '/de/rewrite/';
    $check(jyr_unresolved_redirect('', 'rewrite', $pdo) === '/target/', 'runtime matching honors the semantic path supplied by Core');
    $insertRule('/outside/', 'https://example.test/destination/?fixed=1#details');
    $_SERVER['HTTP_HOST'] = 'opencode.test';
    $_SERVER['REQUEST_URI'] = '/outside/?campaign=test';
    $check(jyr_unresolved_redirect('', 'outside', $pdo) === 'https://example.test/destination/?fixed=1#details', 'runtime returns a validated external target for Core query forwarding');
    $insertRule('/same-host/', 'https://opencode.test/same-host/');
    $_SERVER['REQUEST_URI'] = '/same-host/';
    $check(jyr_unresolved_redirect('', 'same-host', $pdo) === '', 'runtime rejects a stored same-host absolute redirect');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $check(jyr_unresolved_redirect('', 'a', $pdo) === '', 'non-idempotent requests are not redirected');

    $pdo->exec('DELETE FROM jyr_redirects');
    for ($index = 1; $index <= 17; $index++) {
        $source = '/chain-' . $index . '/';
        $target = '/chain-' . ($index + 1) . '/';
        $insertRule($source, $target);
    }
    $check(jyr_rules_have_loop($pdo), 'redirect chains beyond the runtime hop limit are rejected at save time');
    $pdo->exec('DELETE FROM jyr_redirects');
    for ($index = 17; $index >= 1; $index--) {
        $source = '/reverse-chain-' . $index . '/';
        $target = '/reverse-chain-' . ($index + 1) . '/';
        $insertRule($source, $target);
    }
    $check(jyr_rules_have_loop($pdo), 'chain bounds do not depend on database row order');
    $check(jyr_source_conflict($pdo, '/posts/feed.xml') !== null, 'legacy posts collection remains reserved for generic source paths');
    $check(jyr_source_conflict($pdo, '/2026/report.pdf') !== null, 'year archive prefixes remain reserved for generic source paths');
    $check(jyr_redirect_source_blocker($pdo, '/robots.txt') !== null, 'crawler policy remains a protected Core route');
    $pdo->exec("INSERT INTO posts (slug,type,is_deleted) VALUES ('faq','page',0)");
    $insertRule('/faq/', 'https://example.test/faq');
    $check(jyr_content_route_can_be_overridden(jyr_source_conflict($pdo, '/faq/')), 'an exact content path is an allowed redirect override');
    $check(jyr_redirect_source_blocker($pdo, '/faq/') === null, 'content ownership does not block an explicit redirect rule');
    $GLOBALS['pdo'] = $pdo;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $check(jyr_content_override_path('faq') === 'jyr-redirect-dispatch', 'an exact content rule is routed through the static redirect dispatcher');
    $GLOBALS['_jyr_routes'][] = ['path' => 'account', 'handler' => 'other_plugin', 'match' => 'prefix', 'methods' => ['GET']];
    $check(jyr_redirect_source_blocker($pdo, '/account/profile/') !== null, 'another plugin frontend route remains protected');
    $GLOBALS['_jyr_routes'][] = ['path' => 'mutations', 'handler' => 'other_plugin_post', 'match' => 'prefix', 'methods' => ['POST']];
    $check(jyr_plugin_route_conflict('/mutations/item/') !== null, 'POST-only plugin routes remain protected from an exact redirect route');
} else {
    $check(true, 'SQLite behavior checks skipped because pdo_sqlite is unavailable');
}

$GLOBALS['_jyr_locale'] = 'id';
$check(jyr_t('Redirects') === 'Pengalihan', 'Indonesian catalog is available');
$GLOBALS['_jyr_locale'] = 'de';
$check(jyr_t('Redirects') === 'Weiterleitungen', 'German catalog is available');
$translationSources = [];
foreach (['plugin.php', 'admin/action.php', 'admin/index.php'] as $relative) {
    $source = (string)file_get_contents($root . '/' . $relative);
    preg_match_all("/jyr_t\\('((?:[^'\\\\]|\\\\.)*)'/", $source, $matches);
    foreach ($matches[1] ?? [] as $message) $translationSources[stripcslashes($message)] = true;
}
foreach (['id', 'de'] as $locale) {
    $catalog = require $root . '/languages/' . $locale . '.php';
    $missing = array_diff(array_keys($translationSources), array_keys($catalog));
    $check($missing === [], $locale . ' catalog covers every plugin dashboard string');
}

foreach ($manifest['static']['copy'] ?? [] as $entry) {
    $destination = (string)($entry['to'] ?? '');
    $source = (string)($entry['from'] ?? '');
    $check(str_starts_with($destination, 'static/plugins/redirection/'), 'static destination stays in the plugin namespace');
    $check(is_file($root . '/' . $source), 'declared static source exists');
}

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " contract check(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
