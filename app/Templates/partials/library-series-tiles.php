<?php
/**
 * Series as tiles: thumbnail, title, what's inside.
 *
 * @var list<array<string, mixed>> $series from Browse (video_count, file_count, thumbnail)
 * @var list<array<string, mixed>> $categories optional, shown first
 */
$categories ??= [];
$count = function (int $n, string $one, string $many): string {
    return $n === 1 ? t($one) : t($many, ['count' => (string) $n]);
};
?>
<ul class="tiles">
<?php foreach ($categories as $c): ?>
  <li><a class="tile" href="<?= e(url('/categories/' . $c['slug'])) ?>">
    <?php if (!empty($c['cover_image_url'])): ?><img class="tile-thumb" src="<?= e((string) $c['cover_image_url']) ?>" alt="" loading="lazy"><?php else: ?><span class="tile-thumb tile-thumb-empty" aria-hidden="true"></span><?php endif ?>
    <span class="tile-text"><span class="tile-title"><?= e($c['name']) ?></span></span>
  </a></li>
<?php endforeach ?>
<?php foreach ($series as $s): ?>
  <li><a class="tile" href="<?= e(url('/series/' . $s['slug'])) ?>">
    <?php if (!empty($s['thumbnail'])): ?><img class="tile-thumb" src="<?= e((string) $s['thumbnail']) ?>" alt="" loading="lazy"><?php else: ?><span class="tile-thumb tile-thumb-empty" aria-hidden="true"></span><?php endif ?>
    <span class="tile-text">
      <span class="tile-title"><?= e($s['title']) ?></span>
      <span class="small muted"><?= e(implode(' · ', array_filter([
          (int) $s['video_count'] > 0 ? $count((int) $s['video_count'], 'library.video', 'library.videos') : '',
          (int) $s['file_count'] > 0 ? $count((int) $s['file_count'], 'library.file', 'library.files') : '',
      ]))) ?></span>
    </span>
  </a></li>
<?php endforeach ?>
</ul>
