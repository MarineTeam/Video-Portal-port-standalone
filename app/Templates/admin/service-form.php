<?php
/**
 * A provider's settings, generated from its configSchema(). Secrets are
 * write-only: the form says "set" and never echoes them.
 *
 * @var \App\Core\View $v
 * @var string $slot
 * @var string $provider
 * @var class-string<\App\Services\ServiceProvider> $class
 * @var array<string, mixed> $values
 * @var ?array $result
 * @var ?string $token
 * @var bool $https
 * @var bool $isActive
 */
?>
<p><a href="<?= e(url('/admin/providers')) ?>">← Services</a></p>
<h1><?= e($class::label()) ?></h1>
<?php if ($class::limits() !== ''): ?><p class="muted"><?= e($class::limits()) ?></p><?php endif ?>
<?php if ($class::requiresHttps() && !$https): ?>
  <p class="notice error">This provider can only be switched on once the site is reached over HTTPS.</p>
<?php endif ?>

<?php if ($result !== null): ?>
  <div class="notice <?= $result['ok'] ? 'ok' : 'error' ?>" role="status">
    <p><?= e($result['message']) ?></p>
    <?php if ($result['steps'] !== []): ?><ul class="small"><?php foreach ($result['steps'] as $step): ?><li><?= e($step) ?></li><?php endforeach ?></ul><?php endif ?>
  </div>
  <?php if ($result['ok'] && $token !== null && $slot === 'auth' && $provider !== 'local'): ?>
    <form method="post" action="<?= e(url("/admin/providers/auth/$provider/trial")) ?>" class="stack narrow">
      <?= $v->raw(csrf_field()) ?>
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <p class="small">Before anybody else signs in this way, you do: sign in through <?= e($class::label()) ?> as yourself (<?= e((string) ($shell['user']['email'] ?? '')) ?>). If it lets you in under the current rules, it becomes how people sign in; if not, nothing changes. Local sign-in stays open for administrators either way.</p>
      <button type="submit" class="button primary">Sign in with <?= e($class::label()) ?> to switch</button>
    </form>
  <?php elseif ($result['ok'] && $token !== null): ?>
    <form method="post" action="<?= e(url("/admin/providers/$slot/$provider/switch")) ?>" class="stack narrow">
      <?= $v->raw(csrf_field()) ?>
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <?php if (!empty($result['needsCode'])): ?>
        <label>The six-digit code from the test message<input name="code" inputmode="numeric" pattern="[0-9 ]*" autocomplete="one-time-code" required></label>
      <?php endif ?>
      <?php if (!$isActive): ?>
        <label class="check"><input type="checkbox" name="activate" value="1" checked> Make this the active <?= e($slot) ?> provider</label>
      <?php else: ?>
        <input type="hidden" name="activate" value="1">
      <?php endif ?>
      <button type="submit" class="button primary"><?= $isActive ? 'Save these settings' : 'Switch' ?></button>
    </form>
  <?php endif ?>
<?php endif ?>

<form method="post" action="<?= e(url("/admin/providers/$slot/$provider/test")) ?>" class="stack narrow" autocomplete="off">
  <?= $v->raw(csrf_field()) ?>
  <?= $v->partial('partials/config-fields', ['fields' => $class::configSchema(), 'values' => $values]) ?>
  <?php if ($slot === 'sms'): ?>
    <?php /* Asked for by the test, never stored: a provider accepting a
             message says nothing about a carrier delivering it, so the test
             ends with a text arriving on somebody's own phone. */ ?>
    <label>Your own mobile number, to text<input name="test_to" inputmode="tel" autocomplete="off" placeholder="+44 7700 900123"></label>
  <?php endif ?>
  <button type="submit" class="button">Test</button>
</form>
