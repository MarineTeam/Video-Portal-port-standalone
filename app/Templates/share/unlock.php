<?php
/** @var string $token */
?>
<form class="card stack narrow" data-api="/api/share-links/unlock" data-method="POST" data-follow>
  <h1><?= e(t('share.unlockTitle')) ?></h1>
  <p><?= e(t('share.unlockBody')) ?></p>
  <input type="hidden" name="token" value="<?= e($token) ?>">
  <label><?= e(t('share.password')) ?><input type="password" name="password" required autofocus autocomplete="off"></label>
  <div><button class="button primary" type="submit"><?= e(t('share.unlock')) ?></button></div>
  <p class="error" data-error hidden></p>
</form>
