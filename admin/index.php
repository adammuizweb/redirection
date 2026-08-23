<?php
declare(strict_types=1);

if (!defined('DASHBOARD_CONTEXT')) exit;

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) {
    adiwira_render_404();
    return;
}
[$uid] = adiwira_require_permission($pdo, 'plugin.redirection.rules.manage', false);
if (!jyr_ensure_schema($pdo)) {
    echo '<div class="jyr-empty"><h2>' . jyr_h(jyr_t('Storage unavailable')) . '</h2><p>' . jyr_h(jyr_t('Redirection could not initialize its database tables.')) . '</p></div>';
    return;
}

$baseUrl = rtrim((string)ADMIN_BASE_PATH, '/') . '/?page=admin/tools/redirection';
$actionUrl = rtrim((string)ADMIN_BASE_PATH, '/') . '/?page=admin/tools/redirection/action';
$csrf = function_exists('csrf_token') ? csrf_token() : '';
$tab = (string)($_GET['tab'] ?? 'rules');
if (!in_array($tab, ['rules', '404s'], true)) $tab = 'rules';
$query = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 160);
$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 25;

$pageUrl = static function (array $overrides = []) use ($baseUrl): string {
    $params = array_merge($_GET, $overrides);
    unset($params['page']);
    foreach ($params as $key => $value) {
        if ($value === '' || $value === null) unset($params[$key]);
    }
    $queryString = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    return $baseUrl . ($queryString !== '' ? '&' . $queryString : '');
};

$rules = [];
$logs = [];
$total = 0;
$totalPages = 1;
$editRule = null;

if ($tab === 'rules') {
    $status = (string)($_GET['status'] ?? 'all');
    if (!in_array($status, ['all', 'enabled', 'disabled'], true)) $status = 'all';
    $where = ['1=1'];
    $params = [];
    if ($status !== 'all') {
        $where[] = 'enabled = :enabled';
        $params[':enabled'] = $status === 'enabled' ? 1 : 0;
    }
    if ($query !== '') {
        $where[] = "(source_path LIKE :query ESCAPE '!' OR target_url LIKE :query ESCAPE '!')";
        $params[':query'] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query) . '%';
    }
    $whereSql = implode(' AND ', $where);
    $count = $pdo->prepare('SELECT COUNT(*) FROM `' . JYR_RULES_TABLE . '` WHERE ' . $whereSql);
    $count->execute($params);
    $total = (int)$count->fetchColumn();
    $totalPages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;
    $stmt = $pdo->prepare('SELECT * FROM `' . JYR_RULES_TABLE . '` WHERE ' . $whereSql . ' ORDER BY updated_at DESC, id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);
    $stmt->execute($params);
    $rules = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $editId = max(0, (int)($_GET['edit'] ?? 0));
    if ($editId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM `' . JYR_RULES_TABLE . '` WHERE id = ? LIMIT 1');
        $stmt->execute([$editId]);
        $editRule = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
} else {
    $params = [];
    $whereSql = '1=1';
    if ($query !== '') {
        $whereSql = "path LIKE :query ESCAPE '!'";
        $params[':query'] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query) . '%';
    }
    $count = $pdo->prepare('SELECT COUNT(*) FROM `' . JYR_LOGS_TABLE . '` WHERE ' . $whereSql);
    $count->execute($params);
    $total = (int)$count->fetchColumn();
    $totalPages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;
    $stmt = $pdo->prepare('SELECT * FROM `' . JYR_LOGS_TABLE . '` WHERE ' . $whereSql . ' ORDER BY last_seen_at DESC, id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$from = $total === 0 ? 0 : (($page - 1) * $perPage) + 1;
$to = min($total, $page * $perPage);
?>
<div class="jyr-wrap">
  <header class="jyr-hero">
    <div>
      <span class="jyr-kicker"><?= jyr_h(jyr_t('ROUTE CONTROL')) ?></span>
      <h1><?= jyr_h(jyr_t('Redirection')) ?></h1>
      <p><?= jyr_h(jyr_t('Guide unresolved paths to their intended destination and learn which public paths return 404.')) ?></p>
    </div>
    <div class="jyr-policy">
      <strong>301</strong>
      <span><?= jyr_h(jyr_t('Permanent redirects')) ?></span>
      <small><?= jyr_h(jyr_t('Internal redirects preserve query strings. External redirects require explicit forwarding consent.')) ?></small>
    </div>
  </header>

  <nav class="jyr-tabs" aria-label="<?= jyr_h(jyr_t('Redirection sections')) ?>">
    <a class="<?= $tab === 'rules' ? 'is-active' : '' ?>" <?= $tab === 'rules' ? 'aria-current="page"' : '' ?> href="<?= jyr_h($baseUrl) ?>"><?= jyr_h(jyr_t('Redirects')) ?></a>
    <a class="<?= $tab === '404s' ? 'is-active' : '' ?>" <?= $tab === '404s' ? 'aria-current="page"' : '' ?> href="<?= jyr_h($baseUrl . '&tab=404s') ?>"><?= jyr_h(jyr_t('404 Insights')) ?></a>
  </nav>

  <?php if ($tab === 'rules'): ?>
  <section class="jyr-grid">
    <article class="jyr-panel jyr-form-panel">
      <div class="jyr-panel-head">
        <div><span>01</span><h2><?= jyr_h($editRule ? jyr_t('Edit redirect') : jyr_t('Add redirect')) ?></h2></div>
        <?php if ($editRule): ?><a href="<?= jyr_h($baseUrl) ?>"><?= jyr_h(jyr_t('Cancel edit')) ?></a><?php endif; ?>
      </div>
      <form method="post" action="<?= jyr_h($actionUrl) ?>" class="jyr-form">
        <input type="hidden" name="csrf_token" value="<?= jyr_h($csrf) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)($editRule['id'] ?? 0) ?>">
        <label>
          <span><?= jyr_h(jyr_t('Source path')) ?></span>
          <input type="text" name="source_path" maxlength="512" required placeholder="old-path" value="<?= jyr_h($editRule['source_path'] ?? ($_GET['source'] ?? '')) ?>">
          <small><?= jyr_h(jyr_t('Leading and trailing slashes are optional. Query strings are not part of matching.')) ?></small>
        </label>
        <label>
          <span><?= jyr_h(jyr_t('Target URL')) ?></span>
          <input type="text" name="target_url" maxlength="2048" required placeholder="<?= jyr_h(jyr_t('Example: new-path or example.com/video.mp4')) ?>" value="<?= jyr_h($editRule['target_url'] ?? '') ?>">
          <small><?= jyr_h(jyr_t('Slashes are optional for internal paths. A dotted first segment is treated as a domain; prefix it with / when it is internal.')) ?></small>
        </label>
        <label class="jyr-check"><input type="checkbox" name="allow_external" value="1" autocomplete="off"><span><?= jyr_h(jyr_t('Allow this redirect to leave the site')) ?></span></label>
        <label class="jyr-check"><input type="checkbox" name="confirm_external_query" value="1" autocomplete="off"><span><?= jyr_h(jyr_t('I understand incoming query strings are forwarded to the external domain')) ?></span></label>
        <label class="jyr-check"><input type="checkbox" name="enabled" value="1" <?= !$editRule || !empty($editRule['enabled']) ? 'checked' : '' ?>><span><?= jyr_h(jyr_t('Enable this redirect')) ?></span></label>
        <button class="jyr-primary" type="submit"><?= jyr_h($editRule ? jyr_t('Update redirect') : jyr_t('Create redirect')) ?></button>
      </form>
    </article>

    <article class="jyr-panel jyr-list-panel">
      <div class="jyr-panel-head"><div><span>02</span><h2><?= jyr_h(jyr_t('Redirect rules')) ?></h2></div><b><?= number_format($total) ?></b></div>
      <form method="get" class="jyr-toolbar">
        <input type="hidden" name="page" value="admin/tools/redirection">
        <input type="search" name="q" value="<?= jyr_h($query) ?>" placeholder="<?= jyr_h(jyr_t('Search source or target')) ?>">
        <select name="status">
          <?php foreach (['all' => jyr_t('All states'), 'enabled' => jyr_t('Enabled'), 'disabled' => jyr_t('Disabled')] as $value => $label): ?>
          <option value="<?= jyr_h($value) ?>" <?= (($_GET['status'] ?? 'all') === $value) ? 'selected' : '' ?>><?= jyr_h($label) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit"><?= jyr_h(jyr_t('Filter')) ?></button>
      </form>

      <form method="post" action="<?= jyr_h($actionUrl) ?>" onsubmit="return this.elements.action.value !== 'delete' || confirm(<?= jyr_h(json_encode(jyr_t('Delete the selected redirect rules?'))) ?>)">
        <input type="hidden" name="csrf_token" value="<?= jyr_h($csrf) ?>">
        <div class="jyr-bulk">
          <select name="action" required>
            <option value=""><?= jyr_h(jyr_t('Bulk action')) ?></option>
            <option value="enable"><?= jyr_h(jyr_t('Enable')) ?></option>
            <option value="disable"><?= jyr_h(jyr_t('Disable')) ?></option>
            <option value="reset_hits"><?= jyr_h(jyr_t('Reset hits')) ?></option>
            <option value="delete"><?= jyr_h(jyr_t('Delete')) ?></option>
          </select>
          <button type="submit"><?= jyr_h(jyr_t('Apply')) ?></button>
        </div>
        <div class="jyr-table-wrap" tabindex="0" role="region" aria-label="<?= jyr_h(jyr_t('Redirect rules')) ?>">
          <table class="jyr-table">
            <thead><tr><th scope="col"><span class="sr-only"><?= jyr_h(jyr_t('Select')) ?></span></th><th scope="col"><?= jyr_h(jyr_t('Route')) ?></th><th scope="col"><?= jyr_h(jyr_t('State')) ?></th><th scope="col"><?= jyr_h(jyr_t('Hits')) ?></th><th scope="col"><?= jyr_h(jyr_t('Last hit')) ?></th><th scope="col"><?= jyr_h(jyr_t('Action')) ?></th></tr></thead>
            <tbody>
            <?php if ($rules === []): ?>
              <tr><td colspan="6" class="jyr-empty-row"><?= jyr_h(jyr_t('No redirect rules found.')) ?></td></tr>
            <?php else: foreach ($rules as $rule): ?>
              <tr>
                <td><input type="checkbox" name="selected_ids[]" value="<?= (int)$rule['id'] ?>" aria-label="<?= jyr_h(jyr_t('Select redirect')) ?>"></td>
                <td><code><?= jyr_h($rule['source_path']) ?></code><span class="jyr-arrow">&rarr;</span><code><?= jyr_h($rule['target_url']) ?></code></td>
                <td><span class="jyr-badge <?= !empty($rule['enabled']) ? 'is-on' : 'is-off' ?>"><?= jyr_h(!empty($rule['enabled']) ? jyr_t('Enabled') : jyr_t('Disabled')) ?></span></td>
                <td><?= number_format((int)$rule['hit_count']) ?></td>
                <td><?= jyr_h($rule['last_hit_at'] ?: jyr_t('Never')) ?></td>
                <td><a href="<?= jyr_h($pageUrl(['edit' => (int)$rule['id']])) ?>" aria-label="<?= jyr_h(jyr_t('Edit redirect %s', (string)$rule['source_path'])) ?>"><?= jyr_h(jyr_t('Edit')) ?></a></td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </form>
    </article>
  </section>
  <section class="jyr-danger-zone">
    <div><h2><?= jyr_h(jyr_t('Data removal')) ?></h2><p><?= jyr_h(jyr_t('Permanently remove all redirect rules and 404 insights, then deactivate the plugin.')) ?></p></div>
    <form method="post" action="<?= jyr_h($actionUrl) ?>" onsubmit="return confirm(<?= jyr_h(json_encode(jyr_t('Purge all Redirection data and deactivate the plugin?'))) ?>)">
      <input type="hidden" name="csrf_token" value="<?= jyr_h($csrf) ?>"><input type="hidden" name="action" value="purge">
      <label><span><?= jyr_h(jyr_t('Type PURGE to confirm')) ?></span><input type="text" name="confirmation" autocomplete="off" required pattern="PURGE"></label>
      <button class="jyr-danger" type="submit"><?= jyr_h(jyr_t('Purge data and deactivate')) ?></button>
    </form>
  </section>
  <?php else: ?>
  <section class="jyr-panel jyr-logs-panel">
    <div class="jyr-panel-head"><div><span>03</span><h2><?= jyr_h(jyr_t('404 Insights')) ?></h2></div><b><?= number_format($total) ?></b></div>
    <p class="jyr-privacy"><?= jyr_h(jyr_t('Only aggregate paths and timestamps are stored. Query strings, IP addresses, referrers, user agents, cookies, and request bodies are never recorded.')) ?></p>
    <div class="jyr-log-tools">
      <form method="get" class="jyr-toolbar">
        <input type="hidden" name="page" value="admin/tools/redirection"><input type="hidden" name="tab" value="404s">
        <input type="search" name="q" value="<?= jyr_h($query) ?>" placeholder="<?= jyr_h(jyr_t('Search 404 paths')) ?>">
        <button type="submit"><?= jyr_h(jyr_t('Search')) ?></button>
      </form>
      <form method="post" action="<?= jyr_h($actionUrl) ?>" onsubmit="return confirm(<?= jyr_h(json_encode(jyr_t('Clear all 404 insights?'))) ?>)">
        <input type="hidden" name="csrf_token" value="<?= jyr_h($csrf) ?>"><input type="hidden" name="action" value="clear_logs">
        <button class="jyr-danger" type="submit"><?= jyr_h(jyr_t('Clear all')) ?></button>
      </form>
    </div>
    <form method="post" action="<?= jyr_h($actionUrl) ?>" onsubmit="return confirm(<?= jyr_h(json_encode(jyr_t('Delete the selected 404 paths?'))) ?>)">
      <input type="hidden" name="csrf_token" value="<?= jyr_h($csrf) ?>"><input type="hidden" name="action" value="delete_logs">
      <div class="jyr-bulk"><button type="submit"><?= jyr_h(jyr_t('Delete selected')) ?></button></div>
      <div class="jyr-table-wrap" tabindex="0" role="region" aria-label="<?= jyr_h(jyr_t('404 Insights')) ?>"><table class="jyr-table"><thead><tr><th scope="col"><span class="sr-only"><?= jyr_h(jyr_t('Select')) ?></span></th><th scope="col"><?= jyr_h(jyr_t('Path')) ?></th><th scope="col"><?= jyr_h(jyr_t('Hits')) ?></th><th scope="col"><?= jyr_h(jyr_t('First seen')) ?></th><th scope="col"><?= jyr_h(jyr_t('Last seen')) ?></th><th scope="col"><?= jyr_h(jyr_t('Action')) ?></th></tr></thead><tbody>
      <?php if ($logs === []): ?><tr><td colspan="6" class="jyr-empty-row"><?= jyr_h(jyr_t('No public 404 paths recorded.')) ?></td></tr>
      <?php else: foreach ($logs as $log): ?><tr>
        <td><input type="checkbox" name="selected_ids[]" value="<?= (int)$log['id'] ?>" aria-label="<?= jyr_h(jyr_t('Select 404 path')) ?>"></td>
        <td><code><?= jyr_h($log['path']) ?></code></td><td><?= number_format((int)$log['hit_count']) ?></td><td><?= jyr_h($log['first_seen_at']) ?></td><td><?= jyr_h($log['last_seen_at']) ?></td>
        <td><a href="<?= jyr_h($baseUrl . '&source=' . rawurlencode((string)$log['path'])) ?>" aria-label="<?= jyr_h(jyr_t('Create redirect for %s', (string)$log['path'])) ?>"><?= jyr_h(jyr_t('Create redirect')) ?></a></td>
      </tr><?php endforeach; endif; ?>
      </tbody></table></div>
    </form>
  </section>
  <?php endif; ?>

  <?php if ($totalPages > 1): ?><nav class="jyr-pagination" aria-label="<?= jyr_h(jyr_t('Pagination')) ?>">
    <span><?= jyr_h(jyr_t('Showing %d-%d of %d', $from, $to, $total)) ?></span><div>
    <?php for ($number = max(1, $page - 2); $number <= min($totalPages, $page + 2); $number++): ?>
      <a class="<?= $number === $page ? 'is-current' : '' ?>" <?= $number === $page ? 'aria-current="page"' : '' ?> href="<?= jyr_h($pageUrl(['p' => $number, 'edit' => null])) ?>"><?= $number ?></a>
    <?php endfor; ?></div>
  </nav><?php endif; ?>
</div>
