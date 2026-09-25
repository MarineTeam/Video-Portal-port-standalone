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
  <?php if ($result['ok'] && $token !== null): ?>
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
  <?php foreach ($class::configSchema() as $field): ?>
    <?php $key = $field['key']; $value = $values[$key] ?? ($field['default'] ?? ''); $type = $field['type'] ?? 'text'; ?>
    <?php if ($type === 'toggle'): ?>
      <label class="check"><input type="checkbox" name="<?= e($key) ?>" value="1" <?= $value ? 'checked' : '' ?>> <?= e($field['label']) ?></label>
    <?php elseif ($type === 'select'): ?>
      <label><?= e($field['label']) ?>
        <select name="<?= e($key) ?>">
          <?php foreach (($field['options'] ?? []) as $optValue => $optLabel): ?>
            <option value="<?= e($optValue) ?>" <?= (string) $value === (string) $optValue ? 'selected' : '' ?>><?= e($optLabel) ?></option>
          <?php endforeach ?>
        </select>
      </label>
    <?php elseif (!empty($field['secret'])): ?>
      <label><?= e($field['label']) ?> <?= $v->raw(!empty($value) ? '<span class="badge">set</span>' : '') ?>
        <input type="password" name="<?= e($key) ?>" value="" autocomplete="new-password" placeholder="<?= !empty($value) ? 'Leave blank to keep the saved value' : '' ?>">
      </label>
    <?php elseif ($type === 'textarea'): ?>
      <label><?= e($field['label']) ?><textarea name="<?= e($key) ?>" rows="4"><?= e($value) ?></textarea></label>
    <?php else: ?>
      <label><?= e($field['label']) ?><input type="<?= $type === 'number' ? 'number' : 'text' ?>" name="<?= e($key) ?>" value="<?= e(is_scalar($value) ? $value : '') ?>" <?= !empty($field['required']) ? 'required' : '' ?>></label>
    <?php endif ?>
    <?php if (!empty($field['help'])): ?><p class="small muted"><?= e($field['help']) ?></p><?php endif ?>
  <?php endforeach ?>
  <button type="submit" class="button">Test</button>
</form>
