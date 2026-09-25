<?php
/**
 * @var \App\Core\View $v
 * @var array $branding
 * @var ?array<string, mixed> $hero the featured series, or the newest
 * @var ?array{title: string, videos: list<array<string, mixed>>} $continue the row above the browse list
 * @var list<array<string, mixed>> $categories top level
 * @var list<array<string, mixed>> $series in no category
 * @var list<array<string, mixed>> $videos standing alone, in no category
 * @var list<array{type: string, title: string, href: ?string, series: list<array<string, mixed>>}> $rows configured at /admin/home-rows
 */
$empty = $categories === [] && $series === [] && $videos === [];
?>
<?php if ($hero !== null): ?>
  <a class="hero" href="<?= e(url('/series/' . $hero['slug'])) ?>">
    <?php if (!empty($hero['thumbnail'])): ?><img src="<?= e((string) $hero['thumbnail']) ?>" alt=""><?php endif ?>
    <span class="hero-text">
      <span class="small"><?= e(t($hero['featured'] ? 'library.featured' : 'library.recentlyAdded')) ?></span>
      <span class="hero-title"><?= e($hero['title']) ?></span>
      <span class="button primary small"><?= e(t('library.watchNow')) ?></span>
    </span>
  </a>
<?php else: ?>
  <h1><?= e(t('home.welcome', ['name' => $branding['name']])) ?></h1>
<?php endif ?>

<?php if ($continue !== null): ?>
  <section aria-labelledby="continue-h">
    <h2 id="continue-h"><?= e($continue['title']) ?></h2>
    <div class="row-scroll"><?= $v->partial('partials/library-videos', ['videos' => $continue['videos']]) ?></div>
  </section>
<?php endif ?>

<section aria-labelledby="browse-h">
  <h2 id="browse-h"><?= e(t('library.browse')) ?></h2>
  <?php if ($empty): ?>
    <p class="muted"><?= e(t('home.empty')) ?></p>
  <?php else: ?>
    <?= $v->partial('partials/library-series-tiles', ['categories' => $categories, 'series' => $series]) ?>
    <?php if ($videos !== []): ?><?= $v->partial('partials/library-videos', ['videos' => $videos]) ?><?php endif ?>
  <?php endif ?>
</section>

<?php foreach ($rows as $i => $row): ?>
  <section aria-labelledby="row-h-<?= e((string) $i) ?>" data-home-row="<?= e(strtolower($row['type'])) ?>">
    <h2 id="row-h-<?= e((string) $i) ?>"><?php if ($row['href'] !== null): ?><a href="<?= e(url($row['href'])) ?>"><?= e($row['title']) ?></a><?php else: ?><?= e($row['title']) ?><?php endif ?></h2>
    <div class="row-scroll"><?= $v->partial('partials/library-series-tiles', ['series' => $row['series']]) ?></div>
  </section>
<?php endforeach ?>
