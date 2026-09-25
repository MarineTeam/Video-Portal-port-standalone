<?php
/**
 * @var \App\Core\View $v
 * @var string $active
 * @var list<array{slug: string, name: string, version: string, author: string, parent: ?string, screenshot: ?string, active: bool, deletable: bool, hasFunctions: bool}> $themes
 * @var list<array{key: string, type: string, label: string, default: mixed, options: list<array{value: string, label: string}>}> $customizer
 * @var array<string, mixed> $values
 * @var bool $zip
 * @var bool $gd
 */
?>
<h1>Appearance</h1>
<p class="muted">A theme changes how the site looks without changing what it does. The branding (name, colours, logo) carries over to every theme; a theme’s own settings are below.</p>

<h2>Themes</h2>
<div class="theme-grid">
<?php foreach ($themes as $t): ?>
  <div class="card theme-card<?= $t['active'] ? ' active' : '' ?>">
    <?php if ($t['screenshot'] !== null): ?>
      <img src="<?= e($t['screenshot']) ?>" alt="" loading="lazy">
    <?php else: ?>
      <div class="theme-shot" aria-hidden="true"><?= e(mb_substr($t['name'], 0, 1)) ?></div>
    <?php endif ?>
    <h3><?= e($t['name']) ?> <?php if ($t['active']): ?><span class="badge">Active</span><?php endif ?></h3>
    <p class="small muted">
      Version <?= e($t['version']) ?><?php if ($t['author'] !== ''): ?> · <?= e($t['author']) ?><?php endif ?>
      <?php if ($t['parent'] !== null): ?><br>Builds on <code><?= e($t['parent']) ?></code><?php endif ?>
      <?php if ($t['hasFunctions']): ?><br>Runs code (functions.php)<?php endif ?>
    </p>
    <div class="row">
      <?php if (!$t['active']): ?>
        <button class="button small primary" data-api="/api/admin/appearance/theme" data-method="POST" data-body="<?= e(json_encode(['slug' => $t['slug']])) ?>">Activate</button>
      <?php endif ?>
      <?php if ($t['deletable']): ?>
        <button class="button small danger" data-api="/api/admin/appearance/themes/<?= e($t['slug']) ?>" data-method="DELETE" data-confirm="Delete the theme “<?= e($t['name']) ?>” and its settings?">Delete</button>
      <?php endif ?>
    </div>
    <p class="error" data-error hidden></p>
  </div>
<?php endforeach ?>
</div>

<h2>Theme settings</h2>
<?php if ($customizer === []): ?>
  <p class="muted">The active theme has no settings of its own.</p>
<?php else: ?>
<form class="card stack narrow" data-api="/api/admin/appearance/customizer" data-method="PUT">
  <?php foreach ($customizer as $s): $value = $values[$s['key']] ?? null; ?>
    <?php if ($s['type'] === 'toggle'): ?>
      <label class="check"><input type="checkbox" name="<?= e($s['key']) ?>" <?= $value === true ? 'checked' : '' ?>> <?= e($s['label']) ?></label>
    <?php elseif ($s['type'] === 'colour'): ?>
      <label><?= e($s['label']) ?><input type="color" name="<?= e($s['key']) ?>" value="<?= e(is_string($value) ? $value : '#000000') ?>"></label>
    <?php elseif ($s['type'] === 'select'): ?>
      <label><?= e($s['label']) ?>
        <select name="<?= e($s['key']) ?>">
          <?php foreach ($s['options'] as $o): ?>
            <option value="<?= e($o['value']) ?>" <?= $value === $o['value'] ? 'selected' : '' ?>><?= e($o['label']) ?></option>
          <?php endforeach ?>
        </select>
      </label>
    <?php elseif ($s['type'] === 'image'): ?>
      <label><?= e($s['label']) ?> (address)<input name="<?= e($s['key']) ?>" value="<?= e(is_string($value) ? $value : '') ?>" placeholder="https://… or upload below" data-null></label>
    <?php else: ?>
      <label><?= e($s['label']) ?><input name="<?= e($s['key']) ?>" value="<?= e(is_string($value) ? $value : '') ?>" maxlength="200" data-null></label>
    <?php endif ?>
  <?php endforeach ?>
  <div class="row">
    <button class="button primary" type="submit">Save</button>
    <button type="button" class="button" data-api="/api/admin/appearance/customizer" data-method="DELETE" data-confirm="Put this theme’s settings back to their defaults?">Reset</button>
  </div>
  <p class="error" data-error hidden></p>
</form>
<?php foreach ($customizer as $s): if ($s['type'] !== 'image') { continue; } ?>
<form class="card stack narrow" data-chunked-upload="image" data-api="/api/admin/appearance/customizer/image" data-method="POST">
  <h3>Upload: <?= e($s['label']) ?></h3>
  <input type="hidden" name="key" value="<?= e($s['key']) ?>">
  <input type="file" accept="image/png,image/jpeg,image/webp,image/gif" data-upload-file required>
  <input type="hidden" name="upload" data-upload-id>
  <progress data-upload-progress value="0" max="100" hidden></progress>
  <?php if (!$gd): ?><p class="small muted">This host has no GD extension, so the image is checked but stored as uploaded.</p><?php endif ?>
  <button class="button" type="submit">Upload</button>
  <p class="error" data-error hidden></p>
</form>
<?php endforeach ?>
<?php endif ?>

<h2>Install a theme</h2>
<?php if ($zip): ?>
<form class="card stack narrow" data-chunked-upload="theme" data-api="/api/admin/appearance/install" data-method="POST">
  <p class="small muted">A <code>.zip</code> holding one folder named after the theme’s slug, with a <code>theme.json</code> inside. Uploading a theme that is already installed replaces it.</p>
  <input type="file" accept=".zip" data-upload-file required>
  <input type="hidden" name="upload" data-upload-id>
  <progress data-upload-progress value="0" max="100" hidden></progress>
  <button class="button" type="submit">Upload and install</button>
  <p class="error" data-error hidden></p>
</form>
<?php else: ?>
  <p>This host has no zip extension, so themes can’t be uploaded here. Unzip the theme on your computer, upload its folder into <code>themes/</code> with FTP or your host’s file manager, then refresh this page.</p>
<?php endif ?>
