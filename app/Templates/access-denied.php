<?php
/** @var bool $guestOpen */
?>
<h1><?= e(t('accessDenied.title')) ?></h1>
<p><?= e(t('accessDenied.body')) ?></p>
<?php if ($guestOpen): ?>
  <p><a href="<?= e(url('/auth/guest')) ?>"><?= e(t('accessDenied.guest')) ?></a></p>
<?php endif ?>
