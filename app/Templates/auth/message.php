<?php
/** @var string $title @var string $body */
?>
<h1><?= e($title) ?></h1>
<p><?= e($body) ?></p>
<p><a href="<?= e(url('/auth/login')) ?>"><?= e(t('auth.signInTitle')) ?></a></p>
