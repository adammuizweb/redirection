<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $passed, string $message) use (&$failures): void {
    echo ($passed ? 'PASS' : 'FAIL') . ' ' . $message . PHP_EOL;
    if (!$passed) $failures[] = $message;
};

$output = sys_get_temp_dir() . '/redirection-package-test-' . bin2hex(random_bytes(6)) . '.zip';
$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/build-package.php') . ' ' . escapeshellarg($output);
$lines = [];
$status = 1;
exec($command . ' 2>&1', $lines, $status);
$check($status === 0 && is_file($output), 'package builder creates a candidate outside the repository');

$payload = $status === 0 ? json_decode(implode("\n", $lines), true) : null;
$check(is_array($payload) && ($payload['version'] ?? '') === '1.0.0' && ($payload['entries'] ?? 0) === 15, 'builder reports the expected version and entry count');
$check(is_array($payload) && hash_equals((string)($payload['sha256'] ?? ''), (string)hash_file('sha256', $output)), 'reported package checksum matches the final bytes');

$zip = new ZipArchive();
$opened = is_file($output) && $zip->open($output) === true;
$check($opened, 'candidate package reopens successfully');
if ($opened) {
    $entries = [];
    for ($index = 0; $index < $zip->numFiles; $index++) $entries[] = (string)$zip->getNameIndex($index);
    $check(in_array('plugin.json', $entries, true) && in_array('plugin.php', $entries, true), 'manifest and entrypoint are at the ZIP root');
    $check(!in_array('AGENTS.md', $entries, true) && !in_array('tests/contract.php', $entries, true), 'development-only files are excluded');
    $embedded = json_decode((string)$zip->getFromName('plugin.json'), true, 512, JSON_THROW_ON_ERROR);
    $check(($embedded['name'] ?? '') === 'redirection' && ($embedded['version'] ?? '') === '1.0.0', 'embedded manifest identity is correct');
    $zip->close();
}
if (is_file($output)) unlink($output);

$existing = sys_get_temp_dir() . '/redirection-existing-' . bin2hex(random_bytes(6)) . '.zip';
file_put_contents($existing, 'do-not-replace');
$existingLines = [];
$existingStatus = 0;
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/build-package.php') . ' ' . escapeshellarg($existing) . ' 2>&1', $existingLines, $existingStatus);
$check($existingStatus !== 0 && file_get_contents($existing) === 'do-not-replace', 'builder refuses to overwrite an existing output');
unlink($existing);

$inside = $root . '/tools/unsafe-output.zip';
$insideLines = [];
$insideStatus = 0;
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/build-package.php') . ' ' . escapeshellarg($inside) . ' 2>&1', $insideLines, $insideStatus);
$check($insideStatus !== 0 && !file_exists($inside), 'builder refuses output inside the source repository');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " package check(s) failed.\n");
    exit(1);
}
echo "RESULT: ALL PASS\n";
