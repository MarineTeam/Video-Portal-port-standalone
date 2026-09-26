<?php
/**
 * @var \App\Core\View $v
 * @var list<array<string, mixed>> $rows
 * @var list<string> $kinds
 */
?>
<h1>Discussion guides</h1>
<p class="muted">The questions a group works through, written once and used by every group. A leader note is never part of what a member is given — the shape a member's page gets has no field to print one from.</p>
<form class="stack card narrow" data-api="/api/admin/guides" data-method="POST">
  <label>Title<input name="title" required maxlength="255"></label>
  <label>About it<textarea name="description" rows="2" maxlength="20000" data-null></textarea></label>
  <label class="check"><input type="checkbox" name="published"> Published</label>
  <div><button class="button primary" type="submit">Add a guide</button></div>
  <p class="error" data-error hidden></p>
</form>
<?php foreach ($rows as $row): ?>
  <details class="card">
    <summary><?= e((string) $row['title']) ?> <span class="badge<?= $row['published'] ? '' : ' muted' ?>"><?= e($row['published'] ? 'published' : 'draft') ?></span> <span class="small muted"><?= e((string) $row['summary']) ?></span></summary>
    <form class="stack narrow" data-api="/api/admin/guides/<?= e((string) $row['id']) ?>" data-method="PATCH">
      <label>Title<input name="title" required maxlength="255" value="<?= e((string) $row['title']) ?>"></label>
      <label>About it<textarea name="description" rows="2" maxlength="20000" data-null><?= e((string) ($row['description'] ?? '')) ?></textarea></label>
      <label class="check"><input type="checkbox" name="published"<?= $row['published'] ? ' checked' : '' ?>> Published</label>
      <div class="row">
        <button class="button primary" type="submit">Save</button>
        <button type="button" class="button danger" data-api="/api/admin/guides/<?= e((string) $row['id']) ?>" data-method="DELETE" data-confirm="Delete this guide?">Delete</button>
      </div>
      <p class="error" data-error hidden></p>
    </form>
    <?php foreach ($row['items'] as $item): ?>
      <form class="row" data-api="/api/admin/guides/<?= e((string) $row['id']) ?>/items/<?= e((string) $item['id']) ?>" data-method="PATCH">
        <label>Kind<select name="kind"><?php foreach ($kinds as $kind): ?><option value="<?= e($kind) ?>"<?= $item['kind'] === $kind ? ' selected' : '' ?>><?= e(strtolower(str_replace('_', ' ', $kind))) ?></option><?php endforeach ?></select></label>
        <label>Text<input name="body" required maxlength="5000" value="<?= e((string) $item['body']) ?>"></label>
        <label>Reference<input name="reference" maxlength="255" data-null value="<?= e((string) ($item['reference'] ?? '')) ?>"></label>
        <label>Order<input name="position" type="number" min="0" max="10000" data-type="int" value="<?= e((string) $item['position']) ?>"></label>
        <button class="button small primary" type="submit">Save</button>
        <button type="button" class="button small danger" data-api="/api/admin/guides/<?= e((string) $row['id']) ?>/items/<?= e((string) $item['id']) ?>" data-method="DELETE" data-confirm="Delete this item?">×</button>
      </form>
    <?php endforeach ?>
    <form class="row" data-api="/api/admin/guides/<?= e((string) $row['id']) ?>/items" data-method="POST">
      <label>Kind<select name="kind"><?php foreach ($kinds as $kind): ?><option value="<?= e($kind) ?>"><?= e(strtolower(str_replace('_', ' ', $kind))) ?></option><?php endforeach ?></select></label>
      <label>Text<input name="body" required maxlength="5000"></label>
      <label>Reference<input name="reference" maxlength="255" data-null></label>
      <button class="button small primary" type="submit">Add</button>
    </form>
  </details>
<?php endforeach ?>
