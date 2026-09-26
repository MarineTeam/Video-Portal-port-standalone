<?php
/**
 * One form, filled in.
 *
 * @var \App\Core\View $v
 * @var array<string, mixed> $form
 * @var list<array<string, mixed>> $fields the live questions, in order
 * @var bool $alreadySent
 * @var string $script
 */
?>
<p><a href="<?= e(url('/forms')) ?>">← <?= e(t('forms.title')) ?></a></p>
<h1><?= e((string) $form['title']) ?></h1>
<?php if ($form['description'] !== null && $form['description'] !== ''): ?>
  <div class="stack"><?php foreach (preg_split('/\n{2,}/', (string) $form['description']) ?: [] as $para): ?><p><?= e(trim((string) $para)) ?></p><?php endforeach ?></div>
<?php endif ?>
<?php if ($alreadySent): ?>
  <p class="notice ok" role="status"><?= e(t('forms.alreadySent')) ?></p>
<?php elseif ($fields === []): ?>
  <p class="muted"><?= e(t('forms.noQuestions')) ?></p>
<?php else: ?>
  <form class="stack card narrow" data-form="<?= e((string) $form['slug']) ?>">
    <?php foreach ($fields as $field): ?>
      <?php $id = 'f-' . $field['id']; $req = $field['required'] ? ' required' : ''; // an attribute, escaped like any other value ?>
      <?php if ($field['type'] === 'CHECKBOX'): ?>
        <label class="check"><input type="checkbox" id="<?= e($id) ?>" data-field="<?= e((string) $field['id']) ?>" data-kind="CHECKBOX"<?= e($req) ?>> <?= e((string) $field['label']) ?></label>
      <?php elseif ($field['type'] === 'CHECKBOXES'): ?>
        <fieldset>
          <legend><?= e((string) $field['label']) ?><?= e($field['required'] ? ' *' : '') ?></legend>
          <?php foreach ($field['options'] as $option): ?>
            <label class="check"><input type="checkbox" value="<?= e($option) ?>" data-field="<?= e((string) $field['id']) ?>" data-kind="CHECKBOXES"> <?= e($option) ?></label>
          <?php endforeach ?>
        </fieldset>
      <?php elseif ($field['type'] === 'RADIO'): ?>
        <fieldset>
          <legend><?= e((string) $field['label']) ?><?= e($field['required'] ? ' *' : '') ?></legend>
          <?php foreach ($field['options'] as $option): ?>
            <label class="check"><input type="radio" name="<?= e($id) ?>" value="<?= e($option) ?>" data-field="<?= e((string) $field['id']) ?>" data-kind="RADIO"<?= e($req) ?>> <?= e($option) ?></label>
          <?php endforeach ?>
        </fieldset>
      <?php elseif ($field['type'] === 'SELECT'): ?>
        <label for="<?= e($id) ?>"><?= e((string) $field['label']) ?><?= e($field['required'] ? ' *' : '') ?>
          <select id="<?= e($id) ?>" data-field="<?= e((string) $field['id']) ?>" data-kind="SELECT"<?= e($req) ?>>
            <option value=""><?= e(t('forms.choose')) ?></option>
            <?php foreach ($field['options'] as $option): ?><option value="<?= e($option) ?>"><?= e($option) ?></option><?php endforeach ?>
          </select>
        </label>
      <?php elseif ($field['type'] === 'TEXTAREA'): ?>
        <label for="<?= e($id) ?>"><?= e((string) $field['label']) ?><?= e($field['required'] ? ' *' : '') ?>
          <textarea id="<?= e($id) ?>" rows="4" maxlength="5000" data-field="<?= e((string) $field['id']) ?>" data-kind="TEXTAREA"<?= e($req) ?>></textarea>
        </label>
      <?php else: ?>
        <?php $type = ['EMAIL' => 'email', 'PHONE' => 'tel', 'NUMBER' => 'number', 'DATE' => 'date'][$field['type']] ?? 'text'; ?>
        <label for="<?= e($id) ?>"><?= e((string) $field['label']) ?><?= e($field['required'] ? ' *' : '') ?>
          <input id="<?= e($id) ?>" type="<?= e($type) ?>" maxlength="5000" data-field="<?= e((string) $field['id']) ?>" data-kind="<?= e((string) $field['type']) ?>"<?= e($req) ?>>
        </label>
      <?php endif ?>
      <?php if ($field['help'] !== null && $field['help'] !== ''): ?><p class="small muted"><?= e((string) $field['help']) ?></p><?php endif ?>
    <?php endforeach ?>
    <label class="hp" aria-hidden="true">Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
    <div><button class="button primary" type="submit"><?= e(t('forms.send')) ?></button></div>
    <p class="error" data-error hidden></p>
  </form>
  <p class="notice ok" data-form-done hidden role="status"></p>
<?php endif ?>
<script type="module" src="<?= e($script) ?>"></script>
