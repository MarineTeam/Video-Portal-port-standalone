<?php
/**
 * @var \App\Core\View $v
 * @var list<array<string, mixed>> $series
 * @var list<array<string, mixed>> $videos
 */
?>
<h1><?= e(t('favorites.title')) ?></h1>
<?php if ($series === [] && $videos === []): ?>
  <p class="muted"><?= e(t('favorites.empty')) ?></p>
<?php endif ?>
<?php if ($series !== []): ?>
  <section aria-labelledby="fav-series-h">
    <h2 id="fav-series-h"><?= e(t('favorites.series')) ?></h2>
    <?= $v->partial('partials/library-series-tiles', ['series' => $series]) ?>
  </section>
<?php endif ?>
<?php if ($videos !== []): ?>
  <section aria-labelledby="fav-videos-h">
    <h2 id="fav-videos-h"><?= e(t('favorites.videos')) ?></h2>
    <?= $v->partial('partials/library-videos', ['videos' => $videos]) ?>
  </section>
<?php endif ?>
