<?php
/**
 * @var \App\Core\View $v
 * @var string $title
 * @var list<array<string, mixed>> $series
 * @var list<array<string, mixed>> $videos
 */
?>
<section class="related" aria-label="<?= e($title) ?>">
  <h2><?= e($title) ?></h2>
  <?php if ($series !== []): ?>
    <div class="row-scroll"><?= $v->partial('partials/library-series-tiles', ['series' => $series]) ?></div>
  <?php endif ?>
  <?php if ($videos !== []): ?>
    <?= $v->partial('partials/library-videos', ['videos' => $videos]) ?>
  <?php endif ?>
</section>
