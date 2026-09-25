<?php
/**
 * @var \App\Core\View $v
 * @var list<array<string, mixed>> $categories
 * @var list<array<string, mixed>> $series
 * @var list<array<string, mixed>> $videos
 */
?>
<h1><?= e(t('watchLater.title')) ?></h1>
<?php if ($categories === [] && $series === [] && $videos === []): ?>
  <p class="muted"><?= e(t('watchLater.empty')) ?></p>
<?php endif ?>
<?php if ($videos !== []): ?>
  <section aria-labelledby="wl-videos-h">
    <h2 id="wl-videos-h"><?= e(t('watchLater.videos')) ?></h2>
    <?= $v->partial('partials/library-videos', ['videos' => $videos]) ?>
  </section>
<?php endif ?>
<?php if ($categories !== [] || $series !== []): ?>
  <section aria-labelledby="wl-series-h">
    <h2 id="wl-series-h"><?= e(t('watchLater.series')) ?></h2>
    <?= $v->partial('partials/library-series-tiles', ['categories' => $categories, 'series' => $series]) ?>
  </section>
<?php endif ?>
