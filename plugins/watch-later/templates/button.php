<?php
/**
 * @var \App\Core\View $v
 * @var bool $on
 * @var array<string, string> $body
 */
?>
<button type="button" class="button small" data-api="/api/watch-later" data-body="<?= e(\App\Core\View::json($body)) ?>" data-toggle="saved"
  data-label-on="✓ <?= e(t('watchLater.queued')) ?>" data-label-off="+ <?= e(t('watchLater.add')) ?>" aria-pressed="<?= e($on ? 'true' : 'false') ?>"><?= e($on ? '✓ ' . t('watchLater.queued') : '+ ' . t('watchLater.add')) ?></button>
