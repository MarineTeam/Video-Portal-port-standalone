<?php
/**
 * An image address field with an upload beside it: the upload is stored
 * here and its address put in the field.
 *
 * @var \App\Core\View $v
 * @var string $name the field's name
 * @var string $label
 * @var ?string $value
 * @var string $kind covers | speakers | thumbnails
 */
$fieldId = 'img-' . $name . '-' . substr(md5($label), 0, 6);
?>
<div class="stack image-field">
  <label><?= e($label) ?><input id="<?= e($fieldId) ?>" name="<?= e($name) ?>" value="<?= e((string) ($value ?? '')) ?>" placeholder="https://… or upload one" data-null></label>
  <div class="row">
    <?php if (!empty($value)): ?><img src="<?= e((string) $value) ?>" alt="" class="thumb" data-image-preview="<?= e($fieldId) ?>"><?php else: ?><img alt="" class="thumb" hidden data-image-preview="<?= e($fieldId) ?>"><?php endif ?>
    <label class="button small">Upload an image<input type="file" accept="image/png,image/jpeg,image/webp,image/gif" hidden data-image-upload="<?= e($fieldId) ?>" data-kind="<?= e($kind) ?>"></label>
  </div>
</div>
