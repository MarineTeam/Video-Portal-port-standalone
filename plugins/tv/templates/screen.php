<?php
/**
 * The library at arm's length: rows of tiles, walked with four arrows.
 *
 * @var \App\Core\View $v
 * @var list<array{title: string, videos: list<array<string, mixed>>}> $rows
 * @var array<string, mixed>|null $device
 * @var ?string $who
 * @var string $linkUrl
 * @var string $script
 */
?>
<header class="tv-head">
  <h1 class="tv-title"><?= e(t('tv.title')) ?></h1>
  <?php if ($who !== null): ?>
    <p class="tv-who"><?= e(t('tv.signedInAs', ['name' => $who])) ?></p>
  <?php endif ?>
</header>

<?php if ($device === null): ?>
  <section class="tv-pair" data-tv-pair data-labels="<?= e(\App\Core\View::json([
      'waiting' => t('tv.waitingHint'),
      'expired' => t('tv.codeExpired'),
      'denied' => t('tv.denied'),
  ])) ?>">
    <h2><?= e(t('tv.waitingTitle')) ?></h2>
    <p class="tv-lead"><?= e(t('tv.waitingBody', ['url' => preg_replace('#^https?://#', '', $linkUrl) ?? $linkUrl])) ?></p>
    <p class="tv-code" data-tv-code aria-live="polite">······</p>
    <p class="tv-hint" data-tv-status><?= e(t('tv.waitingHint')) ?></p>
  </section>
<?php endif ?>

<div class="tv-rows" data-tv-grid>
  <?php foreach ($rows as $row): ?>
    <section class="tv-row" data-tv-row>
      <h2 class="tv-row-title"><?= e($row['title']) ?></h2>
      <ul class="tv-tiles">
        <?php foreach ($row['videos'] as $video): ?>
          <li>
            <a class="tv-tile" href="<?= e(url('/tv/' . $video['slug'])) ?>" data-tv-tile>
              <?php if ($video['thumbnail'] !== ''): ?>
                <img src="<?= e($video['thumbnail']) ?>" alt="" loading="lazy" width="320" height="180">
              <?php else: ?>
                <span class="tv-tile-blank" aria-hidden="true"></span>
              <?php endif ?>
              <span class="tv-tile-title"><?= e($video['title']) ?></span>
              <?php if ($video['memberOnly']): ?><span class="badge"><?= e(t('common.membersOnly')) ?></span><?php endif ?>
            </a>
          </li>
        <?php endforeach ?>
      </ul>
    </section>
  <?php endforeach ?>
</div>
<?php if ($rows === []): ?><p class="tv-lead"><?= e(t('tv.nothingHere')) ?></p><?php endif ?>
<script type="module" src="<?= e($script) ?>"></script>
