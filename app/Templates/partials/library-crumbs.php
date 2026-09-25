<?php
/**
 * @var list<array<string, mixed>> $trail categories, top first
 * @var ?array<string, mixed> $series optional
 */
$series ??= null;
if ($trail === [] && $series === null) {
    return;
}
?>
<nav class="crumbs small" aria-label="Breadcrumb">
  <a href="<?= e(url('/')) ?>"><?= e(t('nav.home')) ?></a>
  <?php foreach ($trail as $c): ?> › <a href="<?= e(url('/categories/' . $c['slug'])) ?>"><?= e($c['name']) ?></a><?php endforeach ?>
  <?php if ($series !== null): ?> › <a href="<?= e(url('/series/' . $series['slug'])) ?>"><?= e($series['title']) ?></a><?php endif ?>
</nav>
