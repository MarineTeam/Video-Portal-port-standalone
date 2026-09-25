<?php
/**
 * A list of videos, each with its thumbnail, length and the reader's progress.
 *
 * @var \App\Core\View $v
 * @var list<array<string, mixed>> $videos from Browse::decorate
 * @var array<string, int> $locked optional: ids the reader can't open yet
 * @var ?string $current optional: the id being watched
 */
$locked ??= [];
$current ??= null;
?>
<ol class="video-list">
<?php foreach ($videos as $item): ?>
  <?php $isLocked = isset($locked[(string) $item['id']]); ?>
  <li class="video-item<?= (string) $item['id'] === $current ? ' current' : '' ?><?= $isLocked ? ' locked' : '' ?>">
    <a href="<?= e(url('/videos/' . $item['slug'])) ?>"<?= $v->raw((string) $item['id'] === $current ? ' aria-current="page"' : '') ?>>
      <span class="video-thumb">
        <?php if (!empty($item['thumbnail'])): ?><img src="<?= e((string) $item['thumbnail']) ?>" alt="" loading="lazy"><?php endif ?>
        <?php if ($item['duration_seconds'] !== null): ?><span class="video-length"><?= e(\App\Support\Timestamp::format((int) $item['duration_seconds'])) ?></span><?php endif ?>
        <?php if (!$item['watched'] && ($item['progress_seconds'] ?? 0) > 0 && $item['duration_seconds']): ?>
          <span class="video-progress" style="--p: <?= e((string) min(100, round(100 * (int) $item['progress_seconds'] / max(1, (int) $item['duration_seconds'])))) ?>%"></span>
        <?php endif ?>
      </span>
      <span class="video-text">
        <span class="video-title"><?= e($item['title']) ?></span>
        <?php if (!empty($item['series_title'])): ?><span class="small muted"><?= e((string) $item['series_title']) ?></span><?php endif ?>
        <?php if ($item['watched']): ?><span class="badge"><?= e(t('library.watched')) ?></span><?php endif ?>
        <?php if ($isLocked): ?><span class="badge muted" aria-label="<?= e(t('library.locked')) ?>">🔒</span><?php endif ?>
      </span>
    </a>
  </li>
<?php endforeach ?>
</ol>
