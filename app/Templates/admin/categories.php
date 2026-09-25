<?php
/**
 * @var \App\Core\View $v
 * @var list<array<string, mixed>> $categories rows with depth and series_count
 */
?>
<h1>Categories</h1>
<p class="muted">The shape of the library. Categories hold series and other categories, as deep as you like; the arrows set the order they list in.</p>
<form data-api="/api/admin/categories" data-method="POST" class="row card">
  <label>New category<input name="name" required maxlength="255"></label>
  <label>Inside
    <select name="parentId" data-null>
      <option value="">— the top level —</option>
      <?php foreach ($categories as $c): ?>
        <option value="<?= e($c['id']) ?>"><?= e(str_repeat('— ', (int) $c['depth']) . $c['name']) ?></option>
      <?php endforeach ?>
    </select>
  </label>
  <label class="check"><input type="checkbox" name="published" checked> Published</label>
  <button class="button primary" type="submit">Add</button>
  <p class="error" data-error hidden></p>
</form>
<?php if ($categories === []): ?>
  <p class="muted">No categories yet. Series can also sit at the top level, uncategorised.</p>
<?php else: ?>
<table class="table">
  <thead><tr><th>Category</th><th>Series</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($categories as $c): ?>
    <tr>
      <td style="padding-left: <?= e((string) (0.75 + 1.25 * (int) $c['depth'])) ?>rem">
        <a href="<?= e(url('/admin/categories/' . $c['id'])) ?>"><?= e($c['name']) ?></a>
        <?php if (!$c['published']): ?><span class="badge muted">Draft</span><?php endif ?>
        <?php if ($c['member_only']): ?><span class="badge">Members</span><?php endif ?>
        <?php if ($c['hidden']): ?><span class="badge muted">Hidden</span><?php endif ?>
        <?php if ($c['hymnal_style']): ?><span class="badge muted">Hymnals</span><?php endif ?>
      </td>
      <td><?= e((string) $c['series_count']) ?></td>
      <td class="actions">
        <button type="button" class="button small" aria-label="Move <?= e($c['name']) ?> up" data-api="/api/admin/categories/<?= e($c['id']) ?>" data-method="PATCH" data-body='{"move":"up"}'>↑</button>
        <button type="button" class="button small" aria-label="Move <?= e($c['name']) ?> down" data-api="/api/admin/categories/<?= e($c['id']) ?>" data-method="PATCH" data-body='{"move":"down"}'>↓</button>
        <a class="button small" href="<?= e(url('/admin/categories/' . $c['id'])) ?>">Edit</a>
        <button type="button" class="button small danger" data-api="/api/admin/categories/<?= e($c['id']) ?>" data-method="DELETE" data-confirm="Move “<?= e($c['name']) ?>” to the trash? What’s inside stays where it is, reachable by its own address.">Delete</button>
      </td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
<?php endif ?>
