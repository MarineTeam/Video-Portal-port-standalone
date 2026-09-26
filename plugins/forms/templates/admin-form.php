<?php
/**
 * One form: its settings, its questions, and what people have said.
 *
 * @var \App\Core\View $v
 * @var array<string, mixed> $form with its fields
 * @var list<string> $types
 * @var list<string> $choiceTypes
 * @var list<array{id: string, label: string, retired: bool}> $columns
 * @var list<array<string, mixed>> $submissions
 */
$api = '/api/admin/forms/' . $form['id'];
?>
<p><a href="<?= e(url('/admin/forms')) ?>">← Forms</a></p>
<h1><?= e((string) $form['title']) ?></h1>
<p class="muted"><a href="<?= e(url('/forms/' . $form['slug'])) ?>">/forms/<?= e((string) $form['slug']) ?></a></p>

<form class="stack card narrow" data-api="<?= e($api) ?>" data-method="PATCH">
  <label>Title<input name="title" required maxlength="255" value="<?= e((string) $form['title']) ?>"></label>
  <label>Address<input name="slug" maxlength="191" value="<?= e((string) $form['slug']) ?>"></label>
  <label>About it<textarea name="description" rows="2" maxlength="20000" data-null><?= e((string) ($form['description'] ?? '')) ?></textarea></label>
  <label>Shown after sending<textarea name="confirmation" rows="2" maxlength="2000" data-null><?= e((string) ($form['confirmation'] ?? '')) ?></textarea></label>
  <label>Tell these addresses (comma separated)<input name="notifyEmails" maxlength="2000" data-null value="<?= e((string) ($form['notifyEmails'] ?? '')) ?>"></label>
  <div class="row">
    <label class="check"><input type="checkbox" name="published"<?= $form['published'] ? ' checked' : '' ?>> Published</label>
    <label class="check"><input type="checkbox" name="memberOnly"<?= $form['memberOnly'] ? ' checked' : '' ?>> Members only</label>
    <label class="check"><input type="checkbox" name="multiple"<?= $form['multiple'] ? ' checked' : '' ?>> May be sent more than once</label>
  </div>
  <div class="row">
    <button class="button primary" type="submit">Save</button>
    <button type="button" class="button danger" data-api="<?= e($api) ?>" data-method="DELETE" data-redirect="/admin/forms" data-confirm="Delete this form and every response to it?">Delete the form</button>
  </div>
  <p class="error" data-error hidden></p>
</form>

<h2>Questions</h2>
<p class="muted">Renaming a question doesn't rewrite history: an answer belongs to the question, not to the words it was asked in. Stopping one keeps its answers — it is retired, and its column stays in the export after the live ones.</p>
<?php foreach ($form['fields'] as $field): ?>
  <form class="stack card narrow" data-api="<?= e($api . '/fields/' . $field['id']) ?>" data-method="PATCH">
    <div class="row">
      <label>Question<input name="label" required maxlength="500" value="<?= e((string) $field['label']) ?>"></label>
      <label>Kind<select name="type"><?php foreach ($types as $type): ?><option value="<?= e($type) ?>"<?= $field['type'] === $type ? ' selected' : '' ?>><?= e(strtolower($type)) ?></option><?php endforeach ?></select></label>
      <label>Order<input name="position" type="number" min="0" max="10000" data-type="int" value="<?= e((string) $field['position']) ?>"></label>
      <label class="check"><input type="checkbox" name="required"<?= $field['required'] ? ' checked' : '' ?>> Needed</label>
      <?php if ($field['deletedAt'] !== null): ?><span class="badge muted">retired</span><?php endif ?>
    </div>
    <label>A line under it<input name="help" maxlength="1000" data-null value="<?= e((string) ($field['help'] ?? '')) ?>"></label>
    <label>Choices, one per line (for a drop-down, choose-one or choose-any)<textarea name="options" rows="3" maxlength="5000" data-null><?= e((string) ($field['options'] ?? '')) ?></textarea></label>
    <div class="row">
      <button class="button small primary" type="submit">Save</button>
      <?php if ($field['deletedAt'] === null): ?>
        <button type="button" class="button small danger" data-api="<?= e($api . '/fields/' . $field['id']) ?>" data-method="DELETE" data-confirm="Stop asking this? Answers already given are kept.">Stop asking it</button>
      <?php endif ?>
    </div>
    <p class="error" data-error hidden></p>
  </form>
<?php endforeach ?>
<form class="stack card narrow" data-api="<?= e($api . '/fields') ?>" data-method="POST">
  <div class="row">
    <label>New question<input name="label" required maxlength="500"></label>
    <label>Kind<select name="type"><?php foreach ($types as $type): ?><option value="<?= e($type) ?>"><?= e(strtolower($type)) ?></option><?php endforeach ?></select></label>
    <label class="check"><input type="checkbox" name="required"> Needed</label>
  </div>
  <label>Choices, one per line<textarea name="options" rows="3" maxlength="5000" data-null></textarea></label>
  <div><button class="button primary" type="submit">Add the question</button></div>
  <p class="error" data-error hidden></p>
</form>

<h2>Responses</h2>
<p><a class="button small" href="<?= e(url($api . '/submissions?format=csv')) ?>">Download as CSV</a></p>
<?php if ($submissions === []): ?>
  <p class="notice">Nobody has sent this in yet.</p>
<?php else: ?>
  <table class="table">
    <thead>
      <tr><th>Sent</th><?php foreach ($columns as $column): ?><th><?= e($column['label']) ?><?php if ($column['retired']): ?> <span class="badge muted">retired</span><?php endif ?></th><?php endforeach ?><th>Dealt with</th><th></th></tr>
    </thead>
    <tbody>
      <?php foreach ($submissions as $row): ?>
        <tr>
          <td class="small"><time datetime="<?= e((string) $row['createdAt']) ?>" data-local-date><?= e(substr((string) $row['createdAt'], 0, 10)) ?></time><?php if ($row['member']): ?> <span class="badge muted">member</span><?php endif ?></td>
          <?php foreach ($row['cells'] as $cell): ?><td class="small"><?= e(str_replace("\n", ', ', (string) $cell['value'])) ?></td><?php endforeach ?>
          <td class="small"><?= e((string) ($row['handledBy'] ?? '')) ?></td>
          <td>
            <button type="button" class="button small" data-api="<?= e($api . '/submissions/' . $row['id']) ?>" data-method="PATCH" data-body="<?= e(\App\Core\View::json(['handled' => !$row['handled']])) ?>"><?= e($row['handled'] ? 'Not dealt with' : 'Dealt with') ?></button>
            <button type="button" class="button small danger" data-api="<?= e($api . '/submissions/' . $row['id']) ?>" data-method="DELETE" data-confirm="Delete this response?">Delete</button>
          </td>
        </tr>
      <?php endforeach ?>
    </tbody>
  </table>
<?php endif ?>
