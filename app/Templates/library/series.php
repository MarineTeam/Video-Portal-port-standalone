<?php
/**
 * @var \App\Core\View $v
 * @var array<string, mixed> $series
 * @var list<array<string, mixed>> $trail
 * @var bool $preview
 * @var list<array<string, mixed>> $videos
 * @var array<string, int> $locked
 * @var list<array<string, mixed>> $files
 * @var list<string> $tags
 * @var bool $signedIn
 * @var ?array<string, mixed> $share
 * @var array{actions: list<string>, below: list<string>} $panels from plugins (page.series.panels)
 */
?>
<div hidden data-view-event="<?= e(\App\Core\View::json(['seriesId' => $series['id']])) ?>"></div>
<?= $v->partial('partials/library-crumbs', ['trail' => $trail]) ?>
<?php if ($preview): ?><p class="notice warn"><?= e(t('library.preview')) ?></p><?php endif ?>
<header class="series-head">
  <?php if (!empty($series['cover_image_url'])): ?><img class="series-cover" src="<?= e((string) $series['cover_image_url']) ?>" alt=""><?php endif ?>
  <div>
    <h1><?= e($series['title']) ?></h1>
    <?php if (!empty($series['description'])): ?><div class="prose"><?= $v->raw(nl2br(e((string) $series['description']))) ?></div><?php endif ?>
    <?php if ($tags !== []): ?>
      <p class="chips" aria-label="<?= e(t('library.tags')) ?>">
        <?php foreach ($tags as $tag): ?><a class="chip" href="<?= e(url('/tags/' . rawurlencode(mb_strtolower($tag)))) ?>"><?= e($tag) ?></a><?php endforeach ?>
      </p>
    <?php endif ?>
    <?php if ($panels['actions'] !== []): ?><div class="video-actions"><?php foreach ($panels['actions'] as $html): ?><?= $v->raw($html) ?><?php endforeach ?></div><?php endif ?>
  </div>
</header>
<?php if ($videos === [] && $files === []): ?>
  <p class="muted"><?= e(t('library.empty')) ?></p>
<?php endif ?>
<?php if ($videos !== []): ?>
  <?= $v->partial('partials/library-videos', ['videos' => $videos, 'locked' => $locked]) ?>
<?php endif ?>
<?php if ($share !== null): ?><?= $v->partial('partials/share-panel', ['share' => $share]) ?><?php endif ?>
<?php if ($files !== []): ?>
  <h2><?= e(t('library.downloads')) ?></h2>
  <?= $v->partial('partials/library-files', ['files' => $files]) ?>
<?php endif ?>
<?php foreach ($panels['below'] as $html): ?><?= $v->raw($html) ?><?php endforeach ?>
