<?php
/**
 * Settings fields generated from a schema (a provider's configSchema(), an
 * integration's fields). Secrets are write-only: "set", never echoed.
 *
 * @var \App\Core\View $v
 * @var list<array<string, mixed>> $fields
 * @var array<string, mixed> $values
 */
?>
  <?php foreach ($fields as $field): ?>
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
    <?php elseif (!empty($field['secret']) && $type === 'textarea'): ?>
      <label><?= e($field['label']) ?> <?= $v->raw(!empty($value) ? '<span class="badge">set</span>' : '') ?>
        <textarea name="<?= e($key) ?>" rows="4" autocomplete="off" spellcheck="false" placeholder="<?= !empty($value) ? 'Leave blank to keep the saved value' : '' ?>"></textarea>
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
