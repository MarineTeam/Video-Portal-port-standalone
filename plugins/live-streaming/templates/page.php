<?php
/**
 * /live: what is on now, or a countdown to the next one.
 *
 * @var \App\Core\View $v
 * @var array<string, mixed>|null $stream
 * @var bool $started whether its player is worth showing yet
 * @var ?string $startsAt the time being counted down to
 * @var list<array{title: string, startsAt: ?string}> $upcoming
 * @var array<string, mixed>|null $chat
 * @var string $script
 */
$when = static fn (?string $iso): string => $iso === null ? '' : gmdate('D j M H:i', (int) strtotime($iso)) . ' UTC';
?>
<h1><?= e(t('live.title')) ?></h1>
<?php if ($stream === null): ?>
  <p class="muted"><?= e(t('live.nothingScheduled')) ?></p>
<?php else: ?>
  <h2><?= e((string) $stream['title']) ?></h2>
  <?php if ($started): ?>
    <div class="player">
      <iframe src="<?= e((string) $stream['embedUrl']) ?>" title="<?= e((string) $stream['title']) ?>" allow="autoplay; fullscreen; picture-in-picture; encrypted-media" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>
    </div>
  <?php else: ?>
    <?php if ($stream['coverImageUrl'] !== null): ?>
      <img class="thumb-preview" src="<?= e((string) $stream['coverImageUrl']) ?>" alt="">
    <?php endif ?>
    <p class="notice" role="status"><?= e(t('live.startsAt')) ?>
      <time datetime="<?= e((string) $startsAt) ?>" data-live-countdown><?= e($when($startsAt)) ?></time>
    </p>
  <?php endif ?>
  <?php if ($stream['description'] !== null && $stream['description'] !== ''): ?>
    <p><?= e((string) $stream['description']) ?></p>
  <?php endif ?>
<?php endif ?>
<?php if ($chat !== null && $chat['state'] !== \MarineTeam\Plugins\Live\Chat::OFF): ?>
  <?= $v->partial('live-streaming/chat', ['chat' => $chat]) ?>
<?php endif ?>
<?php if ($upcoming !== []): ?>
  <h2><?= e(t('live.upcoming')) ?></h2>
  <ul class="plain">
    <?php foreach ($upcoming as $next): ?>
      <li class="card row">
        <strong><?= e($next['title']) ?></strong>
        <time datetime="<?= e((string) $next['startsAt']) ?>" data-local-time><?= e($when($next['startsAt'])) ?></time>
      </li>
    <?php endforeach ?>
  </ul>
<?php endif ?>
<script type="module" src="<?= e($script) ?>"></script>
