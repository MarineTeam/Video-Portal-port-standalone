<?php
/**
 * @var \App\Core\View $v
 * @var array{likes: int, dislikes: int, mine: ?string} $summary
 * @var bool $signedIn
 * @var array<string, string> $body
 * @var string $script
 */
?>
<span class="share-buttons" data-reactions="<?= e(\App\Core\View::json($body)) ?>">
  <?php if ($signedIn): ?>
    <button type="button" class="button small" data-reaction="LIKE" aria-pressed="<?= e($summary['mine'] === 'LIKE' ? 'true' : 'false') ?>" aria-label="<?= e(t('reactions.like')) ?>">👍 <span data-count><?= e((string) $summary['likes']) ?></span></button>
    <button type="button" class="button small" data-reaction="DISLIKE" aria-pressed="<?= e($summary['mine'] === 'DISLIKE' ? 'true' : 'false') ?>" aria-label="<?= e(t('reactions.dislike')) ?>">👎 <span data-count><?= e((string) $summary['dislikes']) ?></span></button>
  <?php else: ?>
    <span class="small muted" aria-label="<?= e(t('reactions.counts', ['likes' => (string) $summary['likes'], 'dislikes' => (string) $summary['dislikes']])) ?>">👍 <?= e((string) $summary['likes']) ?> · 👎 <?= e((string) $summary['dislikes']) ?></span>
  <?php endif ?>
</span>
<?php if ($signedIn): ?><script type="module" src="<?= e($script) ?>"></script><?php endif ?>
