<?php
/**
 * @var \App\Core\View $v
 * @var array{items: list<array<string, mixed>>, total: int, page: int, pageSize: int} $list
 * @var array<string, string> $query
 */
$filters = array_filter(array_intersect_key($query, array_flip(['q', 'from', 'to'])));
?>
<h1>Audit log</h1>
<p class="muted">Every change an administrator or editor made, kept even when the person or the thing they changed is gone.</p>
<form method="get" class="row">
  <input type="search" name="q" value="<?= e($query['q'] ?? '') ?>" placeholder="Who, what or where" aria-label="Search">
  <input type="date" name="from" value="<?= e($query['from'] ?? '') ?>" aria-label="From">
  <input type="date" name="to" value="<?= e($query['to'] ?? '') ?>" aria-label="To">
  <button class="button" type="submit">Filter</button>
  <a class="button" href="<?= e(url('/api/admin/audit/export', ['format' => 'csv'] + $filters)) ?>">Export CSV</a>
  <a class="button" href="<?= e(url('/api/admin/audit/export', ['format' => 'json'] + $filters)) ?>">Export JSON</a>
</form>
<table class="table">
  <thead><tr><th>When</th><th>Who</th><th>What</th><th>Detail</th></tr></thead>
  <tbody>
  <?php foreach ($list['items'] as $a): ?>
    <tr>
      <td class="small"><?= e(str_replace(['T', '.000Z'], [' ', ''], (string) $a['createdAt'])) ?></td>
      <td><?= e($a['actorEmail']) ?></td>
      <td><code><?= e($a['action']) ?></code><div class="small muted"><?= e($a['entityType']) ?> <?= e($a['entityId'] ?? '') ?></div></td>
      <td class="small"><?= e(mb_substr((string) ($a['detail'] ?? ''), 0, 300)) ?></td>
    </tr>
  <?php endforeach ?>
  <?php if ($list['items'] === []): ?><tr><td colspan="4" class="muted">Nothing recorded.</td></tr><?php endif ?>
  </tbody>
</table>
<?= $v->partial('partials/pager', ['list' => $list, 'query' => $filters, 'path' => '/admin/audit']) ?>
