<?php
/**
 * @var \App\Core\View $v
 * @var array<string, mixed> $category
 * @var list<array<string, mixed>> $trail
 * @var bool $preview
 * @var list<array<string, mixed>> $children
 * @var list<array<string, mixed>> $series
 * @var list<array<string, mixed>> $videos
 * @var list<array<string, mixed>> $files
 * @var array{actions: list<string>, top: list<string>, below: list<string>} $panels from plugins (page.category.panels)
 */
?>
<?= $v->partial('partials/library-crumbs', ['trail' => $trail]) ?>
<?php if ($preview): ?><p class="notice warn"><?= e(t('library.preview')) ?></p><?php endif ?>
<h1><?= e($category['name']) ?></h1>
<?php if (!empty($category['description'])): ?><div class="prose"><?= $v->raw(nl2br(e((string) $category['description']))) ?></div><?php endif ?>
<?php if ($panels['actions'] !== []): ?><div class="video-actions"><?php foreach ($panels['actions'] as $html): ?><?= $v->raw($html) ?><?php endforeach ?></div><?php endif ?>
<?php foreach ($panels['top'] as $html): ?><?= $v->raw($html) ?><?php endforeach ?>
<?php if ($children === [] && $series === [] && $videos === [] && $files === []): ?>
  <p class="muted"><?= e(t('library.empty')) ?></p>
<?php endif ?>
<?php if ($children !== [] || $series !== []): ?>
  <?= $v->partial('partials/library-series-tiles', ['categories' => $children, 'series' => $series]) ?>
<?php endif ?>
<?php if ($videos !== []): ?>
  <h2><?= e(t('library.moreVideos')) ?></h2>
  <?= $v->partial('partials/library-videos', ['videos' => $videos]) ?>
<?php endif ?>
<?php if ($files !== []): ?>
  <h2><?= e(t('library.downloads')) ?></h2>
  <?= $v->partial('partials/library-files', ['files' => $files]) ?>
<?php endif ?>
<?php foreach ($panels['below'] as $html): ?><?= $v->raw($html) ?><?php endforeach ?>
