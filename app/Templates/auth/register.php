<?php
/** @var \App\Core\View $v @var ?string $error @var string $email @var string $name */
?>
<h1><?= e(t('auth.register')) ?></h1>
<?php if ($error !== null): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif ?>
<form method="post" action="<?= e(url('/auth/register')) ?>" class="stack">
  <?= $v->raw(csrf_field()) ?>
  <label class="hp" aria-hidden="true">Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
  <label>Name<input type="text" name="name" autocomplete="name" value="<?= e($name) ?>" maxlength="120"></label>
  <label><?= e(t('auth.email')) ?><input type="email" name="email" autocomplete="email" required value="<?= e($email) ?>"></label>
  <label><?= e(t('auth.password')) ?><input type="password" name="password" autocomplete="new-password" minlength="12" required></label>
  <button type="submit" class="button primary"><?= e(t('auth.register')) ?></button>
</form>
