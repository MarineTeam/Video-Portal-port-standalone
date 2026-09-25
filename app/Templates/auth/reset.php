<?php
/** @var \App\Core\View $v @var string $token @var ?string $error */
?>
<h1><?= e(t('auth.resetTitle')) ?></h1>
<?php if ($error !== null): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif ?>
<form method="post" action="<?= e(url('/auth/reset/' . $token)) ?>" class="stack">
  <?= $v->raw(csrf_field()) ?>
  <label><?= e(t('auth.newPassword')) ?><input type="password" name="password" autocomplete="new-password" minlength="12" required></label>
  <button type="submit" class="button primary"><?= e(t('auth.setPassword')) ?></button>
</form>
