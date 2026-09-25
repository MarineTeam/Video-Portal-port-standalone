<?php
/** @var \App\Core\View $v @var string $token @var string $email */
?>
<h1><?= e(t('auth.signInTitle')) ?></h1>
<form method="post" action="<?= e(url('/auth/magic/' . $token)) ?>" class="stack">
  <?= $v->raw(csrf_field()) ?>
  <p><?= e(t('auth.magicConfirm', ['email' => $email])) ?></p>
  <button type="submit" class="button primary"><?= e(t('auth.continue')) ?></button>
</form>
