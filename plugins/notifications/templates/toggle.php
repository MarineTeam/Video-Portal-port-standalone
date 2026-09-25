<?php
/**
 * @var \App\Core\View $v
 * @var ?string $publicKey null when Web Push isn't set up
 * @var bool $isAdmin
 * @var string $script
 */
?>
<?php if ($publicKey !== null): ?>
  <div class="card row" data-push-toggle="<?= e($publicKey) ?>" hidden>
    <p class="small" data-push-state></p>
    <button type="button" class="button small" data-push-on><?= e(t('notifications.turnOn')) ?></button>
    <button type="button" class="button small" data-push-off hidden><?= e(t('notifications.turnOff')) ?></button>
    <span data-push-texts hidden data-on="<?= e(t('notifications.onHere')) ?>" data-off="<?= e(t('notifications.offHere')) ?>" data-blocked="<?= e(t('notifications.blocked')) ?>" data-unsupported="<?= e(t('notifications.unsupported')) ?>"></span>
  </div>
  <script type="module" src="<?= e($script) ?>"></script>
<?php elseif ($isAdmin): ?>
  <p class="notice small"><?= e(t('notifications.notSetUp')) ?> <a href="<?= e(url('/admin/integrations/webpush')) ?>"><?= e(t('notifications.setUp')) ?></a></p>
<?php endif ?>
