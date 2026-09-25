<?php
/**
 * @var \App\Core\View $v
 * @var list<array{title: string, timestamp_seconds: int|string}> $chapters in time order
 * @var string $page the video's address
 */
use App\Support\Timestamp;

?>
<section aria-labelledby="chapters-h">
  <h2 id="chapters-h"><?= e(t('chapters.title')) ?></h2>
  <ol class="chapter-list">
    <?php foreach ($chapters as $ch): ?>
      <?php $at = (int) $ch['timestamp_seconds']; $link = $page . ($at > 0 ? '?t=' . $at : ''); ?>
      <li>
        <a href="<?= e($link) ?>" data-seek="<?= e((string) $at) ?>"><span class="chapter-time"><?= e(Timestamp::format($at)) ?></span> <?= e($ch['title']) ?></a>
        <button type="button" class="button small" data-copy-link="<?= e($link) ?>" data-copied-label="<?= e(t('chapters.copied')) ?>" aria-label="<?= e(t('chapters.copyLink', ['title' => $ch['title']])) ?>">🔗</button>
      </li>
    <?php endforeach ?>
  </ol>
</section>
