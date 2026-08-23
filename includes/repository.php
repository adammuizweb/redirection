<?php
declare(strict_types=1);

function jyr_find_active_rule(PDO $pdo, string $source): ?array
{
    $stmt = $pdo->prepare('SELECT id, source_path, target_url, enabled FROM `' . JYR_RULES_TABLE . '` WHERE source_hash = :hash AND source_path = :source AND enabled = 1 LIMIT 1');
    $stmt->bindValue(':hash', hash('sha256', $source, true), PDO::PARAM_LOB);
    $stmt->bindValue(':source', $source);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function jyr_increment_hit(PDO $pdo, int $id): void
{
    $stmt = $pdo->prepare('UPDATE `' . JYR_RULES_TABLE . '` SET hit_count = hit_count + 1, last_hit_at = NOW() WHERE id = :id AND enabled = 1');
    $stmt->execute([':id' => $id]);
}

function jyr_all_rules(PDO $pdo, bool $lock = false): array
{
    $sql = 'SELECT id, source_path, target_url, enabled FROM `' . JYR_RULES_TABLE . '` ORDER BY id';
    if ($lock && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') $sql .= ' FOR UPDATE';
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function jyr_rules_have_loop(PDO $pdo, ?array $candidate = null, array $enableIds = []): bool
{
    $rows = jyr_all_rules($pdo);
    $candidateId = (int)($candidate['id'] ?? 0);
    $replaced = false;
    foreach ($rows as &$row) {
        $id = (int)$row['id'];
        if (in_array($id, $enableIds, true)) $row['enabled'] = 1;
        if ($candidate !== null && $candidateId > 0 && $id === $candidateId) {
            $row = $candidate;
            $replaced = true;
        }
    }
    unset($row);
    if ($candidate !== null && !$replaced) $rows[] = $candidate;

    $graph = [];
    foreach ($rows as $row) {
        if ((int)($row['enabled'] ?? 0) !== 1) continue;
        $source = (string)($row['source_path'] ?? '');
        $targetPath = jyr_target_path((string)($row['target_url'] ?? ''));
        if ($source !== '' && $targetPath !== null) $graph[$source] = $targetPath;
    }

    foreach (array_keys($graph) as $source) {
        $visited = [];
        $node = $source;
        for ($hop = 0; $hop < 16; $hop++) {
            if (isset($visited[$node])) return true;
            $visited[$node] = true;
            $next = $graph[$node] ?? null;
            if (!is_string($next) || !isset($graph[$next])) {
                $node = '';
                break;
            }
            $node = $next;
        }
        if ($node !== '' && isset($graph[$node])) return true;
    }
    return false;
}

function jyr_redirect_chain_is_safe(PDO $pdo, string $source, string $target): bool
{
    $visited = [$source => true];
    $next = jyr_target_path($target);
    for ($hop = 0; $hop < 16 && $next !== null; $hop++) {
        if (isset($visited[$next])) return false;
        $visited[$next] = true;
        $rule = jyr_find_active_rule($pdo, $next);
        if (!$rule) return true;
        $next = jyr_target_path((string)$rule['target_url']);
    }
    return $next === null;
}

function jyr_record_404(PDO $pdo, string $path): void
{
    $hash = hash('sha256', $path, true);
    $update = $pdo->prepare('UPDATE `' . JYR_LOGS_TABLE . '` SET hit_count = hit_count + 1, last_seen_at = NOW() WHERE path_hash = :hash AND path = :path');
    $update->bindValue(':hash', $hash, PDO::PARAM_LOB);
    $update->bindValue(':path', $path);
    $update->execute();
    if ($update->rowCount() > 0) return;

    $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    $locked = false;
    try {
        if ($driver === 'mysql') {
            $lock = $pdo->query("SELECT GET_LOCK('jyr_404_insert', 2)")->fetchColumn();
            if ((int)$lock !== 1) return;
            $locked = true;
        }

        $update->execute();
        if ($update->rowCount() > 0) return;
        $count = (int)$pdo->query('SELECT COUNT(*) FROM `' . JYR_LOGS_TABLE . '`')->fetchColumn();
        if ($count >= JYR_MAX_404_PATHS) return;

        $insert = $pdo->prepare(
            'INSERT INTO `' . JYR_LOGS_TABLE . '` (path, path_hash, hit_count, first_seen_at, last_seen_at)
             VALUES (:path, :hash, 1, NOW(), NOW())
             ON DUPLICATE KEY UPDATE hit_count = hit_count + 1, last_seen_at = NOW()'
        );
        $insert->bindValue(':path', $path);
        $insert->bindValue(':hash', $hash, PDO::PARAM_LOB);
        $insert->execute();
    } finally {
        if ($locked) {
            try {
                $pdo->query("SELECT RELEASE_LOCK('jyr_404_insert')")->fetchColumn();
            } catch (Throwable $ignored) {
            }
        }
    }
}

function jyr_normalize_ids(mixed $input): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)$input), static fn(int $id): bool => $id > 0)));
    return array_slice($ids, 0, 500);
}

function jyr_placeholders(array $values): string
{
    return implode(',', array_fill(0, count($values), '?'));
}
