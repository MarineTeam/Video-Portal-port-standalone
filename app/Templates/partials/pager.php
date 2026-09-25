<?php
/**
 * Previous / next links for a paginated list.
 *
 * @var array{total: int, page: int, pageSize: int} $list
 * @var array<string, string> $query
 * @var string $path
 */
$pages = max(1, (int) ceil($list['total'] / max(1, $list['pageSize'])));
?>
<?php if ($pages > 1): ?>
<nav class="pager" aria-label="Pages">
  <?php if ($list['page'] > 1): ?><a href="<?= e(url($path, ['page' => $list['page'] - 1] + $query)) ?>">← Newer</a><?php endif ?>
  <span class="muted">Page <?= e($list['page']) ?> of <?= e($pages) ?> · <?= e(number_format($list['total'])) ?> in all</span>
  <?php if ($list['page'] < $pages): ?><a href="<?= e(url($path, ['page' => $list['page'] + 1] + $query)) ?>">Older →</a><?php endif ?>
</nav>
<?php endif ?>
