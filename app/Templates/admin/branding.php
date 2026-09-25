<?php
/**
 * @var \App\Core\View $v
 * @var array{name: string, shortName: string, brand: string, brandDeep: string, brandLight: string, logoUrl: ?string} $branding
 * @var bool $gd
 */
?>
<h1>Branding</h1>
<p class="muted">The name in the header and under the app icon, and three colours every other colour is worked out from — light and dark alike.</p>
<form class="card stack narrow" data-api="/api/admin/branding" data-method="PUT">
  <label>Name<input name="name" value="<?= e($branding['name']) ?>" maxlength="60" required></label>
  <label>Short name (under an app icon)<input name="shortName" value="<?= e($branding['shortName']) ?>" maxlength="30" required></label>
  <div class="row">
    <label>Brand<input type="color" name="brand" value="<?= e($branding['brand']) ?>"></label>
    <label>Deep<input type="color" name="brandDeep" value="<?= e($branding['brandDeep']) ?>"></label>
    <label>Light<input type="color" name="brandLight" value="<?= e($branding['brandLight']) ?>"></label>
  </div>
  <label>Logo address<input name="logoUrl" value="<?= e($branding['logoUrl'] ?? '') ?>" data-null placeholder="https://… or upload one below"></label>
  <div class="row">
    <button class="button primary" type="submit">Save</button>
    <button type="button" class="button" data-api="/api/admin/branding" data-method="DELETE" data-confirm="Go back to the default name and colours?">Reset to defaults</button>
  </div>
  <p class="error" data-error hidden></p>
</form>
<h2>Upload a logo</h2>
<?php if ($branding['logoUrl'] !== null): ?><p><img src="<?= e($branding['logoUrl']) ?>" alt="Current logo" style="max-height:64px"></p><?php endif ?>
<form class="card stack narrow" data-chunked-upload="image" data-api="/api/admin/branding/logo" data-method="POST">
  <input type="file" accept="image/png,image/jpeg,image/webp,image/gif" data-upload-file required>
  <input type="hidden" name="upload" data-upload-id>
  <progress data-upload-progress value="0" max="100" hidden></progress>
  <p class="small muted">PNG, JPEG, WebP or GIF. <?= $gd ? 'It is redrawn at up to 1024 pixels before it is stored.' : 'This host has no GD extension, so the file is checked but stored as uploaded.' ?> SVG isn’t accepted: it can carry script, and the logo is on every page.</p>
  <button class="button" type="submit">Upload</button>
  <p class="error" data-error hidden></p>
</form>
