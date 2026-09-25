<?php
/** @var string $reason revoked, expired, wrong_recipient or invalid */
?>
<div class="card stack narrow">
  <h1><?= e(t('share.unavailableTitle')) ?></h1>
  <p><?= e(t('share.unavailable.' . $reason)) ?></p>
  <p><a href="<?= e(url('/')) ?>"><?= e(t('nav.home')) ?></a></p>
</div>
