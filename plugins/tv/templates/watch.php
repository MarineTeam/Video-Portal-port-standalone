<?php
/**
 * One video, full-screen, with the remote's Back button going back.
 *
 * @var \App\Core\View $v
 * @var array<string, mixed> $video
 * @var array<string, mixed>|null $player
 * @var ?string $refused
 * @var string $linkUrl
 * @var string $script
 */
?>
<div class="tv-watch" data-tv-watch>
  <h1 class="tv-title"><?= e((string) $video['title']) ?></h1>
  <?php if ($refused !== null): ?>
    <p class="tv-lead"><?= e($refused) ?></p>
    <p class="tv-hint"><?= e(t('tv.waitingBody', ['url' => preg_replace('#^https?://#', '', $linkUrl) ?? $linkUrl])) ?></p>
  <?php elseif ($player === null): ?>
    <p class="tv-lead"><?= e(t('tv.nothingHere')) ?></p>
  <?php else: ?>
    <div class="tv-player" id="player" data-player="<?= e(\App\Core\View::json($player)) ?>" data-title="<?= e((string) $video['title']) ?>" data-progress></div>
    <script type="module" src="<?= e(asset('js/player.js')) ?>"></script>
  <?php endif ?>
  <p><a class="tv-back" href="<?= e(url('/tv')) ?>">← <?= e(t('common.back')) ?></a></p>
</div>
<script type="module" src="<?= e($script) ?>"></script>
