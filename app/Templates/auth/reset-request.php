<?php
/** @var \App\Core\View $v @var bool $configured */
?>
<h1><?= e(t('auth.resetTitle')) ?></h1>
<?php if (!$configured): ?>
  <p><?= e(t('auth.resetUnavailable')) ?></p>
<?php else: ?>
<form method="post" action="<?= e(url('/auth/reset')) ?>" class="stack">
  <?= $v->raw(csrf_field()) ?>
  <label><?= e(t('auth.email')) ?><input type="email" name="email" autocomplete="email" required></label>
  <button type="submit" class="button primary"><?= e(t('auth.continue')) ?></button>
</form>
<?php endif ?>
