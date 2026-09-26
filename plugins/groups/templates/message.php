<?php
/**
 * One message in the group's conversation.
 *
 * @var \App\Core\View $v
 * @var array<string, mixed> $message
 */
?>
<li data-message="<?= e((string) $message['id']) ?>">
  <p class="small"><strong><?= e((string) $message['author']) ?></strong> · <time datetime="<?= e((string) $message['createdAt']) ?>" data-local-date><?= e(substr((string) $message['createdAt'], 0, 10)) ?></time>
    <?php if ($message['canRemove']): ?><button type="button" class="link small" data-message-remove><?= e(t('groups.remove')) ?></button><?php endif ?>
  </p>
  <p class="group-message"><?= e((string) $message['body']) ?></p>
</li>
