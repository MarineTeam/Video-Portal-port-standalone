<?php
/** @var \App\Core\View $v @var ?string $error */
?>
<h1>Recover an administrator account</h1>
<p>This page is open because <code>storage/enable-local-login</code> exists. Open <code>storage/recovery.key</code> in your file manager and paste its contents below.</p>
<?php if ($error !== null): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif ?>
<form method="post" action="<?= e(url('/auth/recover')) ?>" class="stack" autocomplete="off">
  <?= $v->raw(csrf_field()) ?>
  <label>Recovery code<input name="code" required></label>
  <label>Administrator’s email<input type="email" name="email" required></label>
  <label>New password<input type="password" name="password" autocomplete="new-password" minlength="12" required></label>
  <button type="submit" class="button primary">Set password</button>
</form>
