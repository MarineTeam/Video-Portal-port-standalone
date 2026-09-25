<?php
/**
 * @var \App\Core\View $v
 * @var array<string, string> $body the item: {seriesId} or {videoId}
 * @var list<array<string, mixed>> $comments top level, each with replies
 * @var bool $signedIn
 * @var string $script
 */
?>
<section class="comments" aria-labelledby="comments-h" data-comments="<?= e(\App\Core\View::json($body)) ?>"
  data-labels="<?= e(\App\Core\View::json(['delete' => t('comments.delete'), 'report' => t('comments.report'), 'reported' => t('comments.reported'), 'reply' => t('comments.reply'), 'confirmDelete' => t('comments.confirmDelete'), 'you' => t('comments.you')])) ?>">
  <h2 id="comments-h"><?= e(t('comments.title')) ?></h2>
  <?php if ($signedIn): ?>
    <form class="stack" data-comment-form>
      <textarea name="body" rows="2" required maxlength="2000" placeholder="<?= e(t('comments.placeholder')) ?>" aria-label="<?= e(t('comments.placeholder')) ?>"></textarea>
      <div><button class="button small primary" type="submit"><?= e(t('comments.post')) ?></button></div>
      <p class="error small" data-error hidden></p>
    </form>
  <?php else: ?>
    <p class="small muted"><a href="<?= e(url('/auth/login')) ?>"><?= e(t('comments.signIn')) ?></a></p>
  <?php endif ?>
  <ol class="plain comment-list" data-comment-list>
    <?php foreach ($comments as $c): ?>
      <li data-comment="<?= e($c['id']) ?>">
        <?= $v->partial('comments/comment', ['c' => $c, 'reply' => false, 'signedIn' => $signedIn]) ?>
        <ol class="plain comment-replies" data-replies>
          <?php foreach ($c['replies'] as $r): ?><li data-comment="<?= e($r['id']) ?>"><?= $v->partial('comments/comment', ['c' => $r, 'reply' => true, 'signedIn' => $signedIn]) ?></li><?php endforeach ?>
        </ol>
      </li>
    <?php endforeach ?>
  </ol>
  <?php if ($comments === []): ?><p class="small muted" data-no-comments><?= e(t('comments.none')) ?></p><?php endif ?>
</section>
<?php if ($signedIn): ?><script type="module" src="<?= e($script) ?>"></script><?php endif ?>
