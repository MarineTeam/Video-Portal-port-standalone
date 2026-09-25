<?php
/** @var string $returnTo */
?>
<div class="card stack narrow">
  <h1><?= e(t('library.signInTitle')) ?></h1>
  <p><?= e(t('library.signInBody')) ?></p>
  <p><a class="button primary" href="<?= e(url('/auth/login', ['returnTo' => url($returnTo)])) ?>"><?= e(t('nav.signIn')) ?></a></p>
</div>
