<?php
declare(strict_types=1);

if (!defined('DASHBOARD_CONTEXT')) exit;

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) {
    adiwira_render_404();
    return;
}
[$uid] = adiwira_require_permission($pdo, 'plugin.redirection.rules.manage', false);

$baseUrl = rtrim((string)ADMIN_BASE_PATH, '/') . '/?page=admin/tools/redirection';
if (!function_exists('adiwira_redirect_with_flash') && defined('DASH_PATH')) {
    require_once rtrim((string)DASH_PATH, DIRECTORY_SEPARATOR) . '/admin/_notify.php';
}
$redirect = static function (string $type, string $message, string $suffix = '') use ($baseUrl): void {
    $location = $baseUrl . $suffix;
    if (function_exists('adiwira_redirect_with_flash')) adiwira_redirect_with_flash($location, $type, $message);
    header('Location: ' . $location, true, 303);
    exit;
};
$audit = static function (string $event, ?string $resourceId, array $metadata = []) use ($pdo, $uid): void {
    if (!function_exists('authorization_audit')
        || !authorization_audit($pdo, $event, $uid, null, 'redirection', $resourceId, $metadata)) {
        throw new RuntimeException('Unable to write the authorization audit event.');
    }
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    adiwira_render_404();
    return;
}
if (!function_exists('csrf_check') || !csrf_check((string)($_POST['csrf_token'] ?? ''))) {
    $redirect('error', jyr_t('The security token is invalid. Please try again.'));
}
if (!jyr_ensure_schema($pdo)) {
    $redirect('error', jyr_t('Redirection storage is not available.'));
}

$action = (string)($_POST['action'] ?? '');

try {
    if ($action === 'save') {
        $id = max(0, (int)($_POST['id'] ?? 0));
        $source = jyr_normalize_source_path((string)($_POST['source_path'] ?? ''));
        $rawTarget = trim((string)($_POST['target_url'] ?? ''));
        $allowExternal = !empty($_POST['allow_external']);
        $confirmExternalQuery = !empty($_POST['confirm_external_query']);
        $target = jyr_normalize_target_url($rawTarget, true);
        $enabled = !empty($_POST['enabled']) ? 1 : 0;
        if ($source === null) throw new DomainException(jyr_t('Enter a valid source path.'));
        if ($target === null) throw new DomainException(jyr_t('Enter a valid internal path or HTTPS external domain.'));
        $externalPolicyError = jyr_external_target_policy_error($target, $allowExternal, $confirmExternalQuery);
        if ($externalPolicyError === 'same_host') throw new DomainException(jyr_t('Use a root-relative path for destinations on this site.'));
        if ($externalPolicyError === 'external_consent') throw new DomainException(jyr_t('Confirm that this redirect may leave the site.'));
        if ($externalPolicyError === 'query_consent') throw new DomainException(jyr_t('Confirm that incoming query strings may be forwarded to the external domain.'));
        if (jyr_target_path($target) === $source) throw new DomainException(jyr_t('Source and target cannot resolve to the same path.'));
        if ($enabled === 1 && jyr_source_conflict($pdo, $source) !== null) throw new DomainException(jyr_t('The source path is already owned by an active site route.'));

        $pdo->beginTransaction();
        jyr_all_rules($pdo, true);
        $before = null;
        if ($id > 0) {
            $exists = $pdo->prepare('SELECT id, source_path, target_url, enabled FROM `' . JYR_RULES_TABLE . '` WHERE id = ? LIMIT 1');
            $exists->execute([$id]);
            $before = $exists->fetch(PDO::FETCH_ASSOC);
            if (!is_array($before)) throw new DomainException(jyr_t('Redirect rule not found.'));
        }
        $candidate = ['id' => $id, 'source_path' => $source, 'target_url' => $target, 'enabled' => $enabled];
        if ($enabled === 1 && jyr_rules_have_loop($pdo, $candidate)) throw new DomainException(jyr_t('This rule would create a redirect loop or an excessive redirect chain.'));
        $sourceHash = hash('sha256', $source, true);

        if ($id > 0) {
            $stmt = $pdo->prepare(
                'UPDATE `' . JYR_RULES_TABLE . '`
                 SET source_path = ?, source_hash = ?, target_url = ?, enabled = ?, updated_by = ?
                 WHERE id = ?'
            );
            $stmt->bindValue(1, $source);
            $stmt->bindValue(2, $sourceHash, PDO::PARAM_LOB);
            $stmt->bindValue(3, $target);
            $stmt->bindValue(4, $enabled, PDO::PARAM_INT);
            $stmt->bindValue(5, $uid, PDO::PARAM_INT);
            $stmt->bindValue(6, $id, PDO::PARAM_INT);
            $stmt->execute();
            $event = 'jyr.redirect.updated';
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO `' . JYR_RULES_TABLE . '`
                    (source_path, source_hash, target_url, enabled, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->bindValue(1, $source);
            $stmt->bindValue(2, $sourceHash, PDO::PARAM_LOB);
            $stmt->bindValue(3, $target);
            $stmt->bindValue(4, $enabled, PDO::PARAM_INT);
            $stmt->bindValue(5, $uid, PDO::PARAM_INT);
            $stmt->bindValue(6, $uid, PDO::PARAM_INT);
            $stmt->execute();
            $id = (int)$pdo->lastInsertId();
            $event = 'jyr.redirect.created';
        }
        $audit($event, (string)$id, [
            'before' => $before,
            'after' => ['source_path' => $source, 'target_url' => $target, 'enabled' => $enabled],
        ]);
        $pdo->commit();
        $redirect('success', jyr_t('Redirect rule saved.'));
    }

    if (in_array($action, ['enable', 'disable', 'delete', 'reset_hits'], true)) {
        $ids = jyr_normalize_ids($_POST['selected_ids'] ?? []);
        if ($ids === []) throw new DomainException(jyr_t('Select at least one redirect rule.'));
        $placeholders = jyr_placeholders($ids);

        $pdo->beginTransaction();
        $lockedRules = jyr_all_rules($pdo, true);
        if ($action === 'enable' && jyr_rules_have_loop($pdo, null, $ids)) {
            throw new DomainException(jyr_t('The selected rules would create a redirect loop or an excessive redirect chain.'));
        }
        if ($action === 'enable') {
            foreach ($lockedRules as $rule) {
                if (in_array((int)$rule['id'], $ids, true) && jyr_source_conflict($pdo, (string)$rule['source_path']) !== null) {
                    throw new DomainException(jyr_t('One or more source paths are owned by active site routes.'));
                }
            }
        }
        $affected = 0;
        if ($action === 'enable' || $action === 'disable') {
            $value = $action === 'enable' ? 1 : 0;
            $params = array_merge([$value, $uid], $ids);
            $stmt = $pdo->prepare('UPDATE `' . JYR_RULES_TABLE . '` SET enabled = ?, updated_by = ? WHERE id IN (' . $placeholders . ')');
            $stmt->execute($params);
            $affected = $stmt->rowCount();
        } elseif ($action === 'reset_hits') {
            $stmt = $pdo->prepare('UPDATE `' . JYR_RULES_TABLE . '` SET hit_count = 0, last_hit_at = NULL WHERE id IN (' . $placeholders . ')');
            $stmt->execute($ids);
            $affected = $stmt->rowCount();
        } else {
            $stmt = $pdo->prepare('DELETE FROM `' . JYR_RULES_TABLE . '` WHERE id IN (' . $placeholders . ')');
            $stmt->execute($ids);
            $affected = $stmt->rowCount();
        }
        $audit('jyr.redirect.bulk_' . $action, null, ['requested' => count($ids), 'affected' => $affected]);
        $pdo->commit();
        $redirect('success', jyr_t('Redirect rules updated.'));
    }

    if ($action === 'delete_logs') {
        $ids = jyr_normalize_ids($_POST['selected_ids'] ?? []);
        if ($ids === []) throw new DomainException(jyr_t('Select at least one 404 path.'));
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('DELETE FROM `' . JYR_LOGS_TABLE . '` WHERE id IN (' . jyr_placeholders($ids) . ')');
        $stmt->execute($ids);
        $audit('jyr.logs.deleted', null, ['requested' => count($ids), 'affected' => $stmt->rowCount()]);
        $pdo->commit();
        $redirect('success', jyr_t('404 paths deleted.'), '&tab=404s');
    }

    if ($action === 'clear_logs') {
        $pdo->beginTransaction();
        $count = (int)$pdo->query('SELECT COUNT(*) FROM `' . JYR_LOGS_TABLE . '`')->fetchColumn();
        $pdo->exec('DELETE FROM `' . JYR_LOGS_TABLE . '`');
        $audit('jyr.logs.cleared', null, ['count' => $count]);
        $pdo->commit();
        $redirect('success', jyr_t('404 insights cleared.'), '&tab=404s');
    }

    if ($action === 'purge') {
        if ((string)($_POST['confirmation'] ?? '') !== 'PURGE') throw new DomainException(jyr_t('Type PURGE to confirm data removal.'));
        $audit('jyr.data.purge_requested', null);
        if (!function_exists('plugin_disable') || !plugin_disable('redirection')) throw new RuntimeException('Unable to deactivate Redirection.');
        try {
            jyr_drop_schema($pdo);
        } catch (Throwable $purgeError) {
            if (function_exists('plugin_enable')) plugin_enable('redirection');
            throw $purgeError;
        }
        header('Location: ' . rtrim((string)ADMIN_BASE_PATH, '/') . '/?page=admin/plugins/index', true, 303);
        exit;
    }

    throw new DomainException(jyr_t('Unknown action.'));
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $message = $e instanceof DomainException ? $e->getMessage() : jyr_t('The operation could not be completed.');
    if (!$e instanceof DomainException) error_log('[redirection] admin action error: ' . $e->getMessage());
    $redirect('error', $message, $action === 'delete_logs' || $action === 'clear_logs' ? '&tab=404s' : '');
}
