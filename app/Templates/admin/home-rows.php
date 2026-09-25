<?php
/**
 * @var \App\Core\View $v
 * @var list<array<string, mixed>> $rows
 * @var list<array{id: string, name: string}> $categories
 * @var array<string, bool> $plugins whether the plugin a built-in row needs is on
 */
$needs = ['RECOMMENDATIONS' => 'Recommendations', 'TRENDING' => 'View counts'];
?>
<h1>Homepage rows</h1>
<p class="muted">The rows on the homepage, top to bottom. Continue watching always sits just above the browse list; the others follow it in this order. Leave a title empty to use the usual one.</p>
<table class="table">
  <thead><tr><th>Row</th><th>Title</th><th>Shown</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $row): ?>
    <tr>
      <td>
        <strong><?= e($row['defaultTitle']) ?></strong>
        <span class="badge muted"><?= e($row['builtIn'] ? 'Built in' : ($row['type'] === 'CATEGORY' ? 'Category' : 'Tag')) ?></span>
        <?php if (isset($needs[$row['type']]) && !$plugins[$row['type']]): ?>
          <p class="small muted">Needs the <a href="<?= e(url('/admin/plugins')) ?>"><?= e($needs[$row['type']]) ?></a> plugin, which is off.</p>
        <?php endif ?>
      </td>
      <td colspan="2">
        <form class="row" data-api="/api/admin/home-rows/<?= e($row['id']) ?>" data-method="PATCH">
          <input aria-label="Title" name="title" value="<?= e((string) ($row['title'] ?? '')) ?>" placeholder="<?= e($row['defaultTitle']) ?>" maxlength="255" data-null>
          <label class="check"><input type="checkbox" name="enabled"<?= $row['enabled'] ? ' checked' : '' ?>> Shown</label>
          <button class="button small primary" type="submit">Save</button>
          <p class="error" data-error hidden></p>
        </form>
      </td>
      <td class="actions">
        <button type="button" class="button small" aria-label="Move up" data-api="/api/admin/home-rows/<?= e($row['id']) ?>" data-method="PATCH" data-body='{"move":"up"}'>↑</button>
        <button type="button" class="button small" aria-label="Move down" data-api="/api/admin/home-rows/<?= e($row['id']) ?>" data-method="PATCH" data-body='{"move":"down"}'>↓</button>
        <?php if (!$row['builtIn']): ?>
          <button type="button" class="button small danger" data-api="/api/admin/home-rows/<?= e($row['id']) ?>" data-method="DELETE" data-confirm="Remove this row from the homepage?">Remove</button>
        <?php endif ?>
      </td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>

<h2>Add a row</h2>
<div class="card-grid">
  <form class="stack card" data-api="/api/admin/home-rows" data-method="POST">
    <input type="hidden" name="type" value="CATEGORY">
    <label>Category
      <select name="categoryId" required>
        <option value="">Choose…</option>
        <?php foreach ($categories as $c): ?><option value="<?= e($c['id']) ?>"><?= e($c['name']) ?></option><?php endforeach ?>
      </select>
    </label>
    <label>Title (optional)<input name="title" maxlength="255" data-null></label>
    <button class="button primary" type="submit">Add category row</button>
    <p class="error" data-error hidden></p>
  </form>
  <form class="stack card" data-api="/api/admin/home-rows" data-method="POST">
    <input type="hidden" name="type" value="TAG">
    <label>Tag<input name="tag" required maxlength="191" placeholder="advent"></label>
    <label>Title (optional)<input name="title" maxlength="255" data-null></label>
    <button class="button primary" type="submit">Add tag row</button>
    <p class="error" data-error hidden></p>
  </form>
</div>
