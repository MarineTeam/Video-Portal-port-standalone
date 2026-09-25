<?php
/**
 * @var \App\Core\View $v
 * @var list<array<string, mixed>> $rows reported or hidden, most reported first
 */
?>
<h1>Comments</h1>
<p class="muted">Comments members have reported, and those already hidden, in the part of the library you moderate. Hide takes a comment out of view and keeps it; Show lets it back and clears its reports; Delete is permanent.</p>
<?php if ($rows === []): ?>
  <p class="notice ok">Nothing reported.</p>
<?php else: ?>
  <table class="table">
    <thead><tr><th>Comment</th><th>On</th><th>Reports</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $c): ?>
      <tr>
        <td><strong><?= e($c['author']) ?></strong><?php if ($c['hidden']): ?> <span class="badge muted">hidden</span><?php endif ?><div class="small"><?= e(mb_strimwidth((string) $c['body'], 0, 300, '…')) ?></div></td>
        <td><a href="<?= e(url($c['on']['href'])) ?>"><?= e((string) $c['on']['title']) ?></a></td>
        <td><?= e((string) $c['reports']) ?></td>
        <td class="actions">
          <button type="button" class="button small" data-api="/api/admin/comments/<?= e($c['id']) ?>" data-method="PATCH" data-body="<?= e(\App\Core\View::json(['hidden' => !$c['hidden']])) ?>"><?= e($c['hidden'] ? 'Show' : 'Hide') ?></button>
          <button type="button" class="button small danger" data-api="/api/comments/<?= e($c['id']) ?>" data-method="DELETE" data-confirm="Delete this comment for good?">Delete</button>
        </td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
<?php endif ?>
