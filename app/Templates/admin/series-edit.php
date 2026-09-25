<?php
/**
 * @var \App\Core\View $v
 * @var array<string, mixed> $series presented
 * @var list<array<string, mixed>> $categories
 * @var ?array{data: array<string, mixed>, updatedAt: ?string} $draft
 * @var bool $canPublish
 * @var array{users: list<array<string, mixed>>, groups: list<array<string, mixed>>} $viewers
 * @var list<array{id: string, name: string}> $groups
 */
$restricted = $viewers['users'] !== [] || $viewers['groups'] !== [];
?>
<p><a href="<?= e(url('/admin/series')) ?>">← Series</a></p>
<h1><?= e($series['title']) ?></h1>
<?php if ($draft !== null): ?>
  <div class="notice warn" data-draft="<?= e(\App\Core\View::json($draft['data'])) ?>">
    A draft was saved <?= e((string) $draft['updatedAt']) ?> and isn’t live.
    <button type="button" class="link" data-draft-load>Load into form</button>
    <button type="button" class="link" data-api="/api/admin/series/<?= e($series['id']) ?>/draft" data-method="DELETE" data-confirm="Discard the draft?">Discard</button>
  </div>
<?php endif ?>
<form class="card stack narrow" id="series-form" data-api="/api/admin/series/<?= e($series['id']) ?>" data-method="PATCH" data-done="Published." data-no-reload>
  <label>Title<input name="title" value="<?= e($series['title']) ?>" required maxlength="255"></label>
  <label>Address<input name="slug" value="<?= e($series['slug']) ?>" pattern="[a-z0-9]+(-[a-z0-9]+)*" maxlength="80"></label>
  <p class="small muted"><?= e(url('/series/' . $series['slug'])) ?> — changing it keeps the old address working.</p>
  <label>Category
    <select name="categoryId" data-null>
      <option value="">— no category —</option>
      <?php foreach ($categories as $c): ?>
        <option value="<?= e($c['id']) ?>"<?= $series['categoryId'] === $c['id'] ? ' selected' : '' ?>><?= e(str_repeat('— ', (int) $c['depth']) . $c['name']) ?></option>
      <?php endforeach ?>
    </select>
  </label>
  <label>Description<textarea name="description" rows="6" data-null><?= e((string) ($series['description'] ?? '')) ?></textarea></label>
  <?= $v->partial('partials/admin-image-field', ['name' => 'coverImageUrl', 'label' => 'Cover image', 'value' => $series['coverImageUrl'] ?? null, 'kind' => 'covers']) ?>
  <label>Tags (comma separated)<input name="tags" data-type="list" value="<?= e(implode(', ', (array) ($series['tags'] ?? []))) ?>"></label>
  <div class="row">
    <label>Language (e.g. en, es)<input name="language" value="<?= e((string) ($series['language'] ?? '')) ?>" maxlength="35" data-null></label>
    <label>Badge on a hymnal cover<input name="abbreviation" value="<?= e((string) ($series['abbreviation'] ?? '')) ?>" maxlength="32" data-null></label>
  </div>
  <?= $v->partial('partials/admin-publishing', ['item' => $series, 'canPublish' => $canPublish, 'downloads' => true]) ?>
  <fieldset class="stack" <?= $v->raw($canPublish ? '' : 'disabled') ?>>
    <legend>Presentation</legend>
    <label class="check"><input type="checkbox" name="featured"<?= $series['featured'] ? ' checked' : '' ?>> Featured on the home page</label>
    <label class="check"><input type="checkbox" name="pinned"<?= $series['pinned'] ? ' checked' : '' ?>> Pinned first in its category</label>
  </fieldset>
  <label class="check"><input type="checkbox" name="requireSequential"<?= $series['requireSequential'] ? ' checked' : '' ?>> Videos must be watched in order</label>
  <label class="check"><input type="checkbox" name="hymnPerFile"<?= $series['hymnPerFile'] ? ' checked' : '' ?>> One book, one file per hymn</label>
  <div class="row">
    <button class="button primary" type="submit">Publish now</button>
    <button class="button" type="button" data-draft-save="/api/admin/series/<?= e($series['id']) ?>/draft">Save as draft</button>
  </div>
  <p class="small" data-done-message hidden></p>
  <p class="error" data-error hidden></p>
</form>

<h2>Restricted viewing</h2>
<div class="card stack narrow">
  <p class="small muted"><?= e($restricted
      ? 'Only the people and roles below (and administrators) can view this series. “Members only” no longer applies to it.'
      : 'Nobody is named, so “Members only” decides. Naming anybody here makes the series theirs alone.') ?></p>
  <?php if ($viewers['groups'] !== []): ?>
    <ul class="plain">
      <?php foreach ($viewers['groups'] as $g): ?>
        <li><?= e($g['name']) ?> <button type="button" class="link" data-api="/api/admin/series/viewer-groups/<?= e($g['id']) ?>" data-method="DELETE">Remove</button></li>
      <?php endforeach ?>
    </ul>
  <?php endif ?>
  <?php if ($viewers['users'] !== []): ?>
    <ul class="plain">
      <?php foreach ($viewers['users'] as $u): ?>
        <li><?= e($u['name'] ?: $u['email']) ?> <span class="small muted"><?= e($u['email']) ?></span> <button type="button" class="link" data-api="/api/admin/series/viewers/<?= e($u['id']) ?>" data-method="DELETE">Remove</button></li>
      <?php endforeach ?>
    </ul>
  <?php endif ?>
  <?php if ($groups !== []): ?>
  <form class="row" data-api="/api/admin/series/<?= e($series['id']) ?>/viewer-groups" data-method="POST">
    <select name="groupId" aria-label="Role"><?php foreach ($groups as $g): ?><option value="<?= e($g['id']) ?>"><?= e($g['name']) ?></option><?php endforeach ?></select>
    <button class="button small" type="submit">Add role</button>
    <p class="error" data-error hidden></p>
  </form>
  <?php endif ?>
  <form class="row" data-api="/api/admin/series/<?= e($series['id']) ?>/viewers" data-method="POST">
    <input type="email" name="email" placeholder="member@example.org" aria-label="Member’s email" required>
    <button class="button small" type="submit">Add person</button>
    <p class="error" data-error hidden></p>
  </form>
</div>
<p><button type="button" class="button danger" data-api="/api/admin/series/<?= e($series['id']) ?>" data-method="DELETE" data-redirect="/admin/series" data-confirm="Move this series to the trash? Its videos and files stay, reachable by their own addresses.">Delete series</button></p>
