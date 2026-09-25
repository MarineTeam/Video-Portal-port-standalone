<?php
/**
 * @var list<array<string, mixed>> $speakers each with video_count
 */
?>
<h1><?= e(t('library.speakers')) ?></h1>
<?php if ($speakers === []): ?>
  <p class="muted"><?= e(t('library.empty')) ?></p>
<?php else: ?>
<ul class="tiles">
  <?php foreach ($speakers as $s): ?>
    <li><a class="tile" href="<?= e(url('/speakers/' . $s['slug'])) ?>">
      <?php if (!empty($s['photo_url'])): ?><img class="tile-thumb avatar-thumb" src="<?= e((string) $s['photo_url']) ?>" alt="" loading="lazy"><?php else: ?><span class="tile-thumb avatar-thumb tile-thumb-empty" aria-hidden="true"></span><?php endif ?>
      <span class="tile-text"><span class="tile-title"><?= e($s['name']) ?></span>
      <?php if ($s['video_count'] > 0): ?><span class="small muted"><?= e($s['video_count'] === 1 ? t('library.video') : t('library.videos', ['count' => (string) $s['video_count']])) ?></span><?php endif ?></span>
    </a></li>
  <?php endforeach ?>
</ul>
<?php endif ?>
