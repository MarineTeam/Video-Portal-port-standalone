<?php
/**
 * @var \App\Core\View $v
 * @var bool $on
 * @var array<string, string> $body
 */
?>
<button type="button" class="button small" data-api="/api/favorites" data-body="<?= e(\App\Core\View::json($body)) ?>" data-toggle="favorited"
  data-label-on="★ <?= e(t('favorites.saved')) ?>" data-label-off="☆ <?= e(t('favorites.save')) ?>" aria-pressed="<?= e($on ? 'true' : 'false') ?>"><?= e($on ? '★ ' . t('favorites.saved') : '☆ ' . t('favorites.save')) ?></button>
