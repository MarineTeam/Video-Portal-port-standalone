<?php
/**
 * One comment's own row (its replies are the panel's).
 *
 * @var \App\Core\View $v
 * @var array<string, mixed> $c
 * @var bool $reply
 * @var bool $signedIn
 */
?>
<div class="comment">
  <p class="small"><strong><?= e($c['author']) ?></strong> · <time datetime="<?= e($c['createdAt']) ?>" data-local-date><?= e(substr((string) $c['createdAt'], 0, 10)) ?></time></p>
  <p class="comment-body"><?= $v->raw(nl2br(e((string) $c['body']))) ?></p>
  <p class="small comment-actions">
    <?php if ($signedIn && !$reply): ?><button type="button" class="link" data-comment-reply><?= e(t('comments.reply')) ?></button><?php endif ?>
    <?php if ($c['canDelete']): ?><button type="button" class="link" data-comment-delete><?= e(t('comments.delete')) ?></button><?php endif ?>
    <?php if ($c['canReport']): ?><button type="button" class="link" data-comment-report><?= e(t('comments.report')) ?></button><?php endif ?>
  </p>
</div>
