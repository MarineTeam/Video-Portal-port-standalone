<?php
/**
 * @var \App\Core\View $v
 * @var array $shell
 * @var array<string, string> $fields
 */
?>
<form method="post" action="<?= e(url('/auth/callback')) ?>" id="resubmit" class="stack">
  <?php foreach ($fields as $name => $value): ?><input type="hidden" name="<?= e((string) $name) ?>" value="<?= e($value) ?>"><?php endforeach ?>
  <input type="hidden" name="_again" value="1">
  <p><?= e(t('auth.finishing')) ?></p>
  <noscript><button type="submit" class="button primary"><?= e(t('auth.continue')) ?></button></noscript>
</form>
<script nonce="<?= e($shell['nonce']) ?>">document.getElementById('resubmit').submit();</script>
