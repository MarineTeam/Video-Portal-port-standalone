<?php
/**
 * @var \App\Core\View $v
 * @var array<string, mixed> $category presented
 * @var list<array<string, mixed>> $parents categories it may move under
 */
?>
<p><a href="<?= e(url('/admin/categories')) ?>">← Categories</a></p>
<h1><?= e($category['name']) ?></h1>
<form class="card stack narrow" data-api="/api/admin/categories/<?= e($category['id']) ?>" data-method="PATCH" data-done="Saved." data-no-reload>
  <label>Name<input name="name" value="<?= e($category['name']) ?>" required maxlength="255"></label>
  <label>Address<input name="slug" value="<?= e($category['slug']) ?>" pattern="[a-z0-9]+(-[a-z0-9]+)*" maxlength="80"></label>
  <p class="small muted"><?= e(url('/categories/' . $category['slug'])) ?></p>
  <label>Inside
    <select name="parentId" data-null>
      <option value="">— the top level —</option>
      <?php foreach ($parents as $c): ?>
        <option value="<?= e($c['id']) ?>"<?= $category['parentId'] === $c['id'] ? ' selected' : '' ?>><?= e(str_repeat('— ', (int) $c['depth']) . $c['name']) ?></option>
      <?php endforeach ?>
    </select>
  </label>
  <label>Description<textarea name="description" rows="4" data-null><?= e((string) ($category['description'] ?? '')) ?></textarea></label>
  <?= $v->partial('partials/admin-image-field', ['name' => 'coverImageUrl', 'label' => 'Cover image', 'value' => $category['coverImageUrl'] ?? null, 'kind' => 'covers']) ?>
  <label>Tags (comma separated)<input name="tags" data-type="list" value="<?= e(implode(', ', (array) ($category['tags'] ?? []))) ?>"></label>
  <?= $v->partial('partials/admin-publishing', ['item' => $category, 'canPublish' => true, 'downloads' => true]) ?>
  <fieldset class="stack">
    <legend>Presentation</legend>
    <label class="check"><input type="checkbox" name="featured"<?= $category['featured'] ? ' checked' : '' ?>> Featured</label>
    <label class="check"><input type="checkbox" name="pinned"<?= $category['pinned'] ? ' checked' : '' ?>> Pinned first in its list</label>
    <label class="check"><input type="checkbox" name="requireSequential"<?= $category['requireSequential'] ? ' checked' : '' ?>> Its own videos must be watched in order</label>
    <label class="check"><input type="checkbox" name="hymnalStyle"<?= $category['hymnalStyle'] ? ' checked' : '' ?>> Show its books as a shelf of hymnal covers</label>
  </fieldset>
  <div class="row"><button class="button primary" type="submit">Save</button></div>
  <p class="small" data-done-message hidden></p>
  <p class="error" data-error hidden></p>
</form>
