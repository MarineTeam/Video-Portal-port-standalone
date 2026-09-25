<?php
/**
 * @var \App\Core\View $v
 * @var list<array{type: string, id: string, title: string, deletedAt: ?string}> $items
 */
$labels = ['category' => 'Category', 'series' => 'Series', 'video' => 'Video', 'file' => 'File'];
?>
<h1>Trash</h1>
<p class="muted">Deleted categories, series, videos and files. Restoring brings one back exactly as it was. Deleting for good can’t be undone — and for a video or file it is also the moment its stored copy is removed.</p>
<?php if ($items === []): ?>
  <p class="muted">The trash is empty.</p>
<?php else: ?>
<table class="table">
  <thead><tr><th>What</th><th>Kind</th><th>Deleted</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($items as $item): ?>
    <tr>
      <td><?= e($item['title']) ?></td>
      <td><?= e($labels[$item['type']] ?? $item['type']) ?></td>
      <td class="small"><time datetime="<?= e((string) $item['deletedAt']) ?>"><?= e(substr((string) $item['deletedAt'], 0, 10)) ?></time></td>
      <td class="actions">
        <button type="button" class="button small" data-api="/api/admin/trash/<?= e($item['type']) ?>/<?= e($item['id']) ?>" data-method="POST">Restore</button>
        <button type="button" class="button small danger" data-api="/api/admin/trash/<?= e($item['type']) ?>/<?= e($item['id']) ?>" data-method="DELETE" data-confirm="Delete “<?= e($item['title']) ?>” for good? This can’t be undone.">Delete for good</button>
      </td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
<?php endif ?>
