<?php
/**
 * The chat beside a stream: what is there when the page is drawn, and the
 * attributes its module polls with.
 *
 * @var \App\Core\View $v
 * @var array<string, mixed> $chat
 */
?>
<section class="live-chat card" aria-labelledby="live-chat-h"
  data-live-chat="<?= e((string) $chat['streamId']) ?>"
  data-state="<?= e((string) $chat['state']) ?>"
  data-slow-mode="<?= e((string) $chat['slowMode']) ?>"
  data-can-moderate="<?= e($chat['canModerate'] ? '1' : '0') ?>"
  data-labels="<?= e(\App\Core\View::json([
      'delete' => t('live.delete'),
      'mute' => t('live.mute'),
      'confirmMute' => t('live.confirmMute'),
      'closed' => t('live.chatClosed'),
  ])) ?>">
  <h2 id="live-chat-h"><?= e(t('live.chat')) ?></h2>
  <ol class="plain live-chat-list" data-live-messages aria-live="polite">
    <?php foreach ($chat['messages'] as $m): ?>
      <?= $v->partial('live-streaming/message', ['m' => $m, 'canModerate' => (bool) $chat['canModerate']]) ?>
    <?php endforeach ?>
  </ol>
  <?php if ($chat['state'] === \MarineTeam\Plugins\Live\Chat::OPEN && $chat['signedIn'] && !$chat['muted']): ?>
    <form class="row" data-live-form>
      <input name="body" type="text" required maxlength="500" autocomplete="off" placeholder="<?= e(t('live.say')) ?>" aria-label="<?= e(t('live.say')) ?>">
      <button class="button small primary" type="submit"><?= e(t('live.send')) ?></button>
    </form>
    <?php if ($chat['slowMode'] > 0): ?><p class="small muted"><?= e(t('live.slowModeOn', ['seconds' => (string) $chat['slowMode']])) ?></p><?php endif ?>
    <p class="error small" data-error hidden></p>
  <?php elseif ($chat['muted']): ?>
    <p class="small muted"><?= e(t('live.muted')) ?></p>
  <?php elseif (!$chat['signedIn']): ?>
    <p class="small muted"><a href="<?= e(url('/auth/login')) ?>"><?= e(t('live.signIn')) ?></a></p>
  <?php else: ?>
    <p class="small muted" data-live-note><?= e(t($chat['state'] === \MarineTeam\Plugins\Live\Chat::EARLY ? 'live.chatOpensSoon' : 'live.chatClosed')) ?></p>
  <?php endif ?>
</section>
