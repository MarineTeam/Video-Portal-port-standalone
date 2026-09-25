<?php
/**
 * @var \App\Core\View $v
 * @var array{items: list<array<string, mixed>>, total: int, page: int, pageSize: int} $list
 * @var array<string, string> $query
 */
$reasons = [
    'NOT_ORG_MEMBER' => 'Not in the organisation',
    'EMAIL_NOT_AUTHORIZED' => 'Address not on the list',
    'NOT_ORG_MEMBER_AND_EMAIL_NOT_AUTHORIZED' => 'Neither',
    'AUTH0_CALLBACK_ERROR' => 'Refused by the sign-in provider',
];
?>
<h1>Access attempts</h1>
<p class="muted">Refused sign-ins, sign-ups and sessions that stopped passing. No password, token or code is ever stored here. Kept for 90 days.</p>
<form method="get" class="row">
  <input type="search" name="q" value="<?= e($query['q'] ?? '') ?>" placeholder="Email" aria-label="Email">
  <select name="reason" aria-label="Reason"><option value="">Any reason</option><?php foreach ($reasons as $k => $label): ?><option value="<?= e($k) ?>" <?= ($query['reason'] ?? '') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach ?></select>
  <input type="date" name="from" value="<?= e($query['from'] ?? '') ?>" aria-label="From">
  <input type="date" name="to" value="<?= e($query['to'] ?? '') ?>" aria-label="To">
  <label class="check"><input type="checkbox" name="unreviewed" value="1" <?= ($query['unreviewed'] ?? '') === '1' ? 'checked' : '' ?>> Not reviewed</label>
  <button class="button" type="submit">Filter</button>
</form>
<p>
  <button type="button" class="button small" data-api="/api/admin/access-attempts" data-method="POST" data-body='<?= e(\App\Core\View::json(['action' => 'review', 'ids' => array_column(array_filter($list['items'], fn ($r) => $r['reviewedAt'] === null), 'id')])) ?>'>Mark this page reviewed</button>
  <button type="button" class="button small" data-api="/api/admin/access-attempts" data-method="POST" data-body='{"action":"prune"}' data-confirm="Delete attempts older than 90 days now?">Prune old attempts</button>
</p>
<table class="table">
  <thead><tr><th>When</th><th>Who</th><th>What</th><th>Why</th></tr></thead>
  <tbody>
  <?php foreach ($list['items'] as $a): ?>
    <tr class="<?= $a['reviewedAt'] ? 'muted' : '' ?>">
      <td class="small"><?= e(str_replace(['T', '.000Z'], [' ', ''], (string) $a['createdAt'])) ?></td>
      <td><?= e($a['email'] ?? '(unknown)') ?><div class="small muted"><?= e($a['provider'] ?? '') ?></div></td>
      <td><?= e(ucfirst(strtolower((string) $a['attemptType']))) ?></td>
      <td><?= e($reasons[$a['reason']] ?? $a['reason']) ?><?php if ($a['detail']): ?><div class="small muted"><?= e($a['detail']) ?></div><?php endif ?></td>
    </tr>
  <?php endforeach ?>
  <?php if ($list['items'] === []): ?><tr><td colspan="4" class="muted">No refused attempts.</td></tr><?php endif ?>
  </tbody>
</table>
<?= $v->partial('partials/pager', ['list' => $list, 'query' => array_filter(array_intersect_key($query, array_flip(['q', 'reason', 'from', 'to', 'unreviewed']))), 'path' => '/admin/access-attempts']) ?>
