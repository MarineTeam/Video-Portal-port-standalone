<?php
/**
 * One chat message.
 *
 * @var \App\Core\View $v
 * @var array<string, mixed> $m
 * @var bool $canModerate
 */
?>
<li data-message="<?= e((string) $m['id']) ?>">
  <strong><?= e((string) $m['author']) ?></strong>
  <span class="live-chat-body"><?= e((string) $m['body']) ?></span>
  <?php if ($m['canDelete']): ?><button type="button" class="link small" data-message-delete><?= e(t('live.delete')) ?></button><?php endif ?>
  <?php if ($canModerate && !$m['mine']): ?><button type="button" class="link small" data-message-mute><?= e(t('live.mute')) ?></button><?php endif ?>
</li>
