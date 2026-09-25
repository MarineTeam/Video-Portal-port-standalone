<?php
/**
 * A list of videos, each with its thumbnail, length and the reader's progress.
 *
 * @var list<array<string, mixed>> $videos from Browse::decorate
 * @var array<string, int> $locked optional: ids the reader can't open yet
 * @var ?string $current optional: the id being watched
 */
$locked ??= [];
$current ??= null;
?>
<ol class="video-list">
<?php foreach ($videos as $v): ?>
  <?php $isLocked = isset($locked[(string) $v['id']]); ?>
  <li class="video-item<?= (string) $v['id'] === $current ? ' current' : '' ?><?= $isLocked ? ' locked' : '' ?>">
    <a href="<?= e(url('/videos/' . $v['slug'])) ?>"<?= (string) $v['id'] === $current ? ' aria-current="page"' : '' ?>>
      <span class="video-thumb">
        <?php if (!empty($v['thumbnail'])): ?><img src="<?= e((string) $v['thumbnail']) ?>" alt="" loading="lazy"><?php endif ?>
        <?php if ($v['duration_seconds'] !== null): ?><span class="video-length"><?= e(\App\Support\Timestamp::format((int) $v['duration_seconds'])) ?></span><?php endif ?>
        <?php if (!$v['watched'] && ($v['progress_seconds'] ?? 0) > 0 && $v['duration_seconds']): ?>
          <span class="video-progress" style="--p: <?= e((string) min(100, round(100 * (int) $v['progress_seconds'] / max(1, (int) $v['duration_seconds'])))) ?>%"></span>
        <?php endif ?>
      </span>
      <span class="video-text">
        <span class="video-title"><?= e($v['title']) ?></span>
        <?php if (!empty($v['series_title'])): ?><span class="small muted"><?= e((string) $v['series_title']) ?></span><?php endif ?>
        <?php if ($v['watched']): ?><span class="badge"><?= e(t('library.watched')) ?></span><?php endif ?>
        <?php if ($isLocked): ?><span class="badge muted" aria-label="<?= e(t('library.locked')) ?>">🔒</span><?php endif ?>
      </span>
    </a>
  </li>
<?php endforeach ?>
</ol>
