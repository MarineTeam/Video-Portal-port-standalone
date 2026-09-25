<?php
/**
 * @var \App\Core\View $v
 * @var ?string $error
 * @var string $email
 * @var string $returnTo
 * @var bool $magicLink
 * @var bool $selfRegistration
 */
?>
<h1><?= e(t('auth.signInTitle')) ?></h1>
<?php if ($error !== null): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif ?>
<?php if ($magicLink): ?>
<form method="post" action="<?= e(url('/auth/magic')) ?>" class="stack">
  <?= $v->raw(csrf_field()) ?>
  <label><?= e(t('auth.email')) ?><input type="email" name="email" autocomplete="email" required value="<?= e($email) ?>"></label>
  <button type="submit" class="button primary"><?= e(t('auth.emailMeALink')) ?></button>
</form>
<p class="divider"><span>or</span></p>
<?php endif ?>
<form method="post" action="<?= e(url('/auth/login')) ?>" class="stack">
  <?= $v->raw(csrf_field()) ?>
  <input type="hidden" name="returnTo" value="<?= e($returnTo) ?>">
  <label><?= e(t('auth.email')) ?><input type="email" name="email" autocomplete="username" required value="<?= e($email) ?>"></label>
  <label><?= e(t('auth.password')) ?><input type="password" name="password" autocomplete="current-password" required></label>
  <button type="submit" class="button<?= $magicLink ? '' : ' primary' ?>"><?= e(t('auth.signInButton')) ?></button>
</form>
<p class="small"><a href="<?= e(url('/auth/reset')) ?>"><?= e(t('auth.forgot')) ?></a>
<?php if ($selfRegistration): ?> · <a href="<?= e(url('/auth/register')) ?>"><?= e(t('auth.register')) ?></a><?php endif ?></p>
