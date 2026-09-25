<?php
/**
 * @var \App\Core\View $v
 * @var bool $on
 * @var array<string, string> $body
 */
?>
<button type="button" class="button small" data-api="/api/subscriptions" data-body="<?= e(\App\Core\View::json($body)) ?>" data-toggle="following"
  data-label-on="🔔 <?= e(t('subscriptions.following')) ?>" data-label-off="🔔 <?= e(t('subscriptions.follow')) ?>" aria-pressed="<?= e($on ? 'true' : 'false') ?>"><?= e('🔔 ' . ($on ? t('subscriptions.following') : t('subscriptions.follow'))) ?></button>
