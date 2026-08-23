<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $passed, string $message) use (&$failures): void {
    echo ($passed ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$passed) $failures[] = $message;
};

$action = (string)file_get_contents($root . '/admin/action.php');
$index = (string)file_get_contents($root . '/admin/index.php');
$runtime = (string)file_get_contents($root . '/includes/runtime.php');
$schema = (string)file_get_contents($root . '/includes/schema.php');

$check(str_contains($action, "defined('DASHBOARD_CONTEXT')"), 'mutation endpoint requires dashboard context');
$check(str_contains($action, "adiwira_require_permission(\$pdo, 'plugin.redirection.rules.manage', false)"), 'mutation endpoint repeats the permission guard');
$check(str_contains($action, "REQUEST_METHOD") && str_contains($action, "!== 'POST'"), 'mutation endpoint rejects non-POST requests');
$check(str_contains($action, 'csrf_check'), 'mutation endpoint validates CSRF');
$check(str_contains($action, 'beginTransaction') && str_contains($action, 'rollBack'), 'rule mutations use transactional rollback');
$check(str_contains($action, 'jyr_rules_have_loop'), 'save and enable operations validate redirect loops');
$check(str_contains($action, 'jyr_source_conflict'), 'new sources cannot shadow active site routes');
$check(str_contains($action, "!authorization_audit") || str_contains($action, "|| !authorization_audit"), 'authorization audit failure blocks sensitive mutations');
$check(str_contains($index, 'jyr_h('), 'dashboard output uses the plugin escaping boundary');
$check(str_contains($runtime, "['GET', 'HEAD']"), 'runtime redirects only GET and HEAD');
$check(str_contains($runtime, 'jyr_source_conflict'), 'runtime yields to routes created after a rule');
$check(str_contains($runtime, 'http_response_code() !== 404'), '404 capture records only final 404 responses');
$check(str_contains($schema, 'jyr_redirects') && str_contains($schema, 'jyr_404_paths'), 'schema owns only prefixed plugin tables');
$check(str_contains($schema, 'BINARY(32)'), 'indexed path identity uses fixed hashes compatible with MySQL 5.7 limits');
$check(!str_contains($schema, 'ALTER TABLE'), 'plugin installation does not alter Core tables');

$allSource = '';
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->isFile()) $allSource .= (string)file_get_contents($file->getPathname());
}
$forbiddenBrand = chr(119) . chr(111) . chr(114) . chr(100) . chr(112) . chr(114) . chr(101) . chr(115) . chr(115);
$check(stripos($allSource, $forbiddenBrand) === false, 'plugin identity contains no unrelated CMS branding');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " security check(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
