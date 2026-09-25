<?php
/**
 * Notices only an administrator sees: plugins switched off automatically, a
 * theme that fell back to the default, local sign-in forced on by the
 * break-glass file.
 *
 * @var \App\Core\View $v
 * @var array $shell
 */
?>
<?php if ($shell['breakGlass']): ?>
<div class="notice warn" role="status">Local sign-in for administrators is forced on by <code>storage/enable-local-login</code>. Delete that file once you are back in.</div>
<?php endif ?>
<?php foreach ($shell['notices'] as $n): ?>
<div class="notice error" role="status">
  The plugin <strong><?= e($n['name']) ?></strong> was switched off automatically (<?= e($n['deactivated_reason']) ?>): <?= e(mb_substr((string) $n['deactivated_error'], 0, 300)) ?>
  <form method="post" action="<?= e(url('/admin/plugins/' . $n['slug'] . '/dismiss')) ?>" class="inline">
    <?= $v->raw(csrf_field()) ?><input type="hidden" name="returnTo" value="<?= e(url($shell['path'])) ?>">
    <button type="submit" class="link">Dismiss</button>
  </form>
</div>
<?php endforeach ?>
<?php if (is_array($shell['themeNotice'])): ?>
<div class="notice error" role="status">
  The theme <strong><?= e($shell['themeNotice']['slug'] ?? '') ?></strong> failed to load and the default theme was restored: <?= e(mb_substr((string) ($shell['themeNotice']['error'] ?? ''), 0, 300)) ?>
  <form method="post" action="<?= e(url('/admin/appearance/dismiss')) ?>" class="inline">
    <?= $v->raw(csrf_field()) ?><input type="hidden" name="returnTo" value="<?= e(url($shell['path'])) ?>">
    <button type="submit" class="link">Dismiss</button>
  </form>
</div>
<?php endif ?>
