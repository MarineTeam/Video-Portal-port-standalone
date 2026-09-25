<?php
/**
 * @var \App\Core\View $v
 * @var array<string, mixed> $video decorated (thumbnail, progress_seconds, watched)
 * @var ?array<string, mixed> $series
 * @var list<array<string, mixed>> $trail
 * @var bool $preview
 * @var bool $premiere
 * @var bool $locked
 * @var ?array<string, mixed> $waitingFor the video to finish first
 * @var ?array<string, mixed> $player
 * @var ?array<string, mixed> $previous
 * @var ?array<string, mixed> $next
 * @var ?array<string, mixed> $speaker
 * @var list<string> $scripture
 * @var bool $signedIn
 * @var ?array<string, mixed> $share
 * @var bool $download
 * @var list<array{title: string, timestamp_seconds: int|string}> $chapters shown when the Chapters plugin is on
 * @var ?string $transcript shown when the Transcripts plugin is on
 * @var array{actions: list<string>, below: list<string>} $panels from plugins (page.video.panels)
 */
use App\Support\Timestamp;

$book = fn (string $ref) => \App\Modules\Library\Videos::scriptureBook($ref);
?>
<div hidden data-view-event="<?= e(\App\Core\View::json(['videoId' => $video['id']])) ?>"></div>
<?= $v->partial('partials/library-crumbs', ['trail' => $trail, 'series' => $series]) ?>
<?php if ($preview): ?><p class="notice warn"><?= e(t('library.preview')) ?></p><?php endif ?>

<?php if ($locked): ?>
  <div class="player-placeholder card">
    <p><?= e(t('library.locked')) ?></p>
    <?php if ($waitingFor !== null): ?><p><a class="button primary" href="<?= e(url('/videos/' . $waitingFor['slug'])) ?>"><?= e(t('library.lockedNext', ['title' => $waitingFor['title']])) ?></a></p><?php endif ?>
  </div>
<?php elseif ($premiere): ?>
  <div class="player-placeholder card">
    <p><?= e(t('library.premiere', ['when' => ''])) ?><time datetime="<?= e((string) \App\Core\Json::instant((string) $video['publish_at'])) ?>" data-local-time><?= e((string) $video['publish_at']) ?> UTC</time></p>
  </div>
<?php elseif ($video['status'] !== 'READY'): ?>
  <div class="player-placeholder card"><p><?= e(t('library.processing')) ?></p></div>
<?php elseif ($player === null): ?>
  <div class="player-placeholder card"><p><?= e(t('library.unavailable')) ?></p></div>
<?php else: ?>
  <div class="player" id="player" data-player="<?= e(\App\Core\View::json($player)) ?>" data-title="<?= e($video['title']) ?>"<?= $signedIn ? ' data-progress' : '' ?>></div>
  <?php if ($player['start'] > 0): ?><p class="small muted"><?= e(t('library.resume', ['time' => Timestamp::format((int) $player['start'])])) ?></p><?php endif ?>
<?php endif ?>

<h1><?= e($video['title']) ?></h1>
<p class="small muted">
  <?php if ($speaker !== null): ?><a href="<?= e(url('/speakers/' . $speaker['slug'])) ?>"><?= e($speaker['name']) ?></a> · <?php endif ?>
  <?php if ($series !== null): ?><?= e(t('library.inSeries', ['title' => ''])) ?><a href="<?= e(url('/series/' . $series['slug'])) ?>"><?= e($series['title']) ?></a> · <?php endif ?>
  <time datetime="<?= e((string) \App\Core\Json::instant((string) ($video['publish_at'] ?? $video['created_at']))) ?>" data-local-date><?= e(substr((string) ($video['publish_at'] ?? $video['created_at']), 0, 10)) ?></time>
</p>

<div class="video-actions">
  <?php if ($signedIn && !$locked): ?>
    <button type="button" class="button small" data-api="/api/watch-progress/mark-watched" data-method="POST"
      data-body="<?= e(\App\Core\View::json(['videoId' => $video['id'], 'completed' => !$video['watched']])) ?>"><?= e(t($video['watched'] ? 'library.markUnwatched' : 'library.markWatched')) ?></button>
  <?php endif ?>
  <?php if ($download): ?>
    <button type="button" class="button small" data-download-video="<?= e($video['id']) ?>" data-saved-label="<?= e(t('downloads.saved')) ?>">⬇ <?= e(t('downloads.button')) ?></button>
  <?php endif ?>
  <?php if ($player !== null): ?>
    <form class="share-at small" data-share-at="<?= e(\App\Core\Url::absolute('/videos/' . $video['slug'])) ?>">
      <label for="share-t"><?= e(t('library.shareAt')) ?></label>
      <input id="share-t" name="t" size="6" placeholder="0:00" pattern="[0-9:hms]+" data-share-time>
      <button type="submit" class="button small"><?= e(t('library.copyLink')) ?></button>
      <span class="small" data-share-done hidden><?= e(t('library.copied')) ?></span>
    </form>
  <?php endif ?>
  <?php foreach ($panels['actions'] as $html): ?><?= $v->raw($html) ?><?php endforeach ?>
</div>

<?php if ($scripture !== []): ?>
  <p class="chips" aria-label="<?= e(t('library.scripture')) ?>">
    <?php foreach ($scripture as $ref): ?>
      <?php $b = $book($ref); ?>
      <?php if ($b !== null): ?><a class="chip" href="<?= e(url('/scripture/' . rawurlencode($b))) ?>"><?= e($ref) ?></a><?php else: ?><span class="chip"><?= e($ref) ?></span><?php endif ?>
    <?php endforeach ?>
  </p>
<?php endif ?>

<?php if ($chapters !== []): ?>
  <section aria-labelledby="chapters-h">
    <h2 id="chapters-h"><?= e(t('library.chapters')) ?></h2>
    <ol class="chapter-list">
      <?php foreach ($chapters as $ch): ?>
        <?php $at = (int) $ch['timestamp_seconds']; $link = \App\Core\Url::absolute('/videos/' . $video['slug']) . ($at > 0 ? '?t=' . $at : ''); ?>
        <li>
          <a href="<?= e($link) ?>" data-seek="<?= e((string) $at) ?>"><span class="chapter-time"><?= e(Timestamp::format($at)) ?></span> <?= e($ch['title']) ?></a>
          <button type="button" class="button small" data-copy-link="<?= e($link) ?>" data-copied-label="<?= e(t('library.copied')) ?>" aria-label="<?= e(t('library.copyChapterLink', ['title' => $ch['title']])) ?>">🔗</button>
        </li>
      <?php endforeach ?>
    </ol>
  </section>
<?php endif ?>

<?php if (!empty($video['description'])): ?><div class="prose"><?= $v->raw(nl2br(e((string) $video['description']))) ?></div><?php endif ?>
<?php if (!empty($video['note_outline'])): ?>
  <details class="card"><summary><?= e(t('library.notes')) ?></summary><div class="prose"><?= $v->raw(nl2br(e((string) $video['note_outline']))) ?></div></details>
<?php endif ?>

<?php if ($transcript !== null): ?>
  <details class="card"><summary><?= e(t('library.transcript')) ?></summary><div class="prose"><?= $v->raw(nl2br(e($transcript))) ?></div></details>
<?php endif ?>
<?php foreach ($panels['below'] as $html): ?><?= $v->raw($html) ?><?php endforeach ?>
<?php if ($share !== null): ?><?= $v->partial('partials/share-panel', ['share' => $share]) ?><?php endif ?>
<?php if ($previous !== null || $next !== null): ?>
  <nav class="row prev-next" aria-label="<?= e($series['title'] ?? '') ?>">
    <?php if ($previous !== null): ?><a class="button" rel="prev" href="<?= e(url('/videos/' . $previous['slug'])) ?>">← <?= e(t('library.previous')) ?>: <?= e($previous['title']) ?></a><?php endif ?>
    <?php if ($next !== null): ?><a class="button" rel="next" href="<?= e(url('/videos/' . $next['slug'])) ?>"><?= e(t('library.next')) ?>: <?= e($next['title']) ?> →</a><?php endif ?>
  </nav>
<?php endif ?>
<p class="small error" data-download-status hidden></p>
<script type="module" src="<?= e(asset('js/player.js')) ?>"></script>
<?php if ($download): ?><script type="module" src="<?= e(asset('js/downloads.js')) ?>"></script><?php endif ?>
