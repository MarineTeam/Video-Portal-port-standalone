<?php
/**
 * @var \App\Core\View $v
 * @var list<array<string, mixed>> $series presented, with categoryName, videoCount, fileCount
 * @var int $total
 * @var int $page
 * @var int $perPage
 * @var string $q
 * @var string $categoryId
 * @var list<array<string, mixed>> $categories
 * @var bool $canPublish
 */
$query = array_filter(['q' => $q, 'categoryId' => $categoryId], fn ($x) => $x !== '');
?>
<h1>Series</h1>
<form data-api="/api/admin/series" data-method="POST" data-redirect="/admin/series/{id}" class="row card">
  <label>New series<input name="title" required maxlength="255"></label>
  <label>In
    <select name="categoryId" data-null>
      <option value="">— no category —</option>
      <?php foreach ($categories as $c): ?>
        <option value="<?= e($c['id']) ?>"><?= e(str_repeat('— ', (int) $c['depth']) . $c['name']) ?></option>
      <?php endforeach ?>
    </select>
  </label>
  <button class="button primary" type="submit">Create</button>
  <p class="error" data-error hidden></p>
</form>
<form method="get" class="row">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Filter by title" aria-label="Filter by title">
  <select name="categoryId" aria-label="Category">
    <option value="">Every category</option>
    <option value="none"<?= $categoryId === 'none' ? ' selected' : '' ?>>No category</option>
    <?php foreach ($categories as $c): ?>
      <option value="<?= e($c['id']) ?>"<?= $categoryId === $c['id'] ? ' selected' : '' ?>><?= e(str_repeat('— ', (int) $c['depth']) . $c['name']) ?></option>
    <?php endforeach ?>
  </select>
  <button class="button" type="submit">Filter</button>
</form>
<?php if ($series === []): ?>
  <p class="muted">No series<?= e($q !== '' ? ' match “' . $q . '”' : ' yet') ?>.</p>
<?php else: ?>
<div class="bulk" data-bulk="/api/admin/series/{id}">
  <div class="row small">
    <span data-bulk-count>0 selected</span>
    <?php if ($canPublish): ?>
      <button type="button" class="button small" data-bulk-action='{"published":true}'>Publish</button>
      <button type="button" class="button small" data-bulk-action='{"published":false}'>Unpublish</button>
    <?php endif ?>
    <select data-bulk-move aria-label="Move selected to">
      <option value="">Move to…</option>
      <option value="none">— no category —</option>
      <?php foreach ($categories as $c): ?>
        <option value="<?= e($c['id']) ?>"><?= e(str_repeat('— ', (int) $c['depth']) . $c['name']) ?></option>
      <?php endforeach ?>
    </select>
    <button type="button" class="button small danger" data-bulk-action="delete" data-confirm="Move the selected series to the trash?">Delete</button>
  </div>
<table class="table">
  <thead><tr><th><input type="checkbox" data-bulk-all aria-label="Select all"></th><th>Series</th><th>Category</th><th>Videos</th><th>Files</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($series as $s): ?>
    <tr>
      <td><input type="checkbox" data-bulk-id="<?= e($s['id']) ?>" aria-label="Select <?= e($s['title']) ?>"></td>
      <td>
        <a href="<?= e(url('/admin/series/' . $s['id'])) ?>"><?= e($s['title']) ?></a>
        <?php if (!$s['published']): ?><span class="badge muted">Draft</span><?php elseif ($s['publishAt'] !== null && $s['publishAt'] > gmdate('Y-m-d\TH:i:s')): ?><span class="badge muted">Scheduled</span><?php endif ?>
        <?php if ($s['memberOnly']): ?><span class="badge">Members</span><?php endif ?>
        <?php if ($s['hidden']): ?><span class="badge muted">Hidden</span><?php endif ?>
        <?php if ($s['featured']): ?><span class="badge">Featured</span><?php endif ?>
        <?php if ($s['pinned']): ?><span class="badge">Pinned</span><?php endif ?>
      </td>
      <td class="small"><?= e($s['categoryName'] ?? '—') ?></td>
      <td><?= e((string) $s['videoCount']) ?></td>
      <td><?= e((string) $s['fileCount']) ?></td>
      <td class="actions">
        <button type="button" class="button small" aria-label="Move <?= e($s['title']) ?> up" data-api="/api/admin/series/<?= e($s['id']) ?>" data-method="PATCH" data-body='{"move":"up"}'>↑</button>
        <button type="button" class="button small" aria-label="Move <?= e($s['title']) ?> down" data-api="/api/admin/series/<?= e($s['id']) ?>" data-method="PATCH" data-body='{"move":"down"}'>↓</button>
        <a class="button small" href="<?= e(url('/admin/series/' . $s['id'])) ?>">Edit</a>
      </td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
</div>
<?= $v->partial('partials/pager', ['list' => ['total' => $total, 'page' => $page, 'pageSize' => $perPage], 'query' => $query, 'path' => '/admin/series']) ?>
<?php endif ?>
