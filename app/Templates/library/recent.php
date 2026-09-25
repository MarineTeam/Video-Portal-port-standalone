<?php
/**
 * @var \App\Core\View $v
 * @var list<array<string, mixed>> $series
 * @var list<array<string, mixed>> $videos
 */
?>
<h1><?= e(t('library.recentlyAdded')) ?></h1>
<?php if ($series === [] && $videos === []): ?><p class="muted"><?= e(t('library.empty')) ?></p><?php endif ?>
<?php if ($series !== []): ?>
  <h2><?= e(t('library.series')) ?></h2>
  <?= $v->partial('partials/library-series-tiles', ['series' => $series]) ?>
<?php endif ?>
<?php if ($videos !== []): ?>
  <h2><?= e(t('library.moreVideos')) ?></h2>
  <?= $v->partial('partials/library-videos', ['videos' => $videos]) ?>
<?php endif ?>
