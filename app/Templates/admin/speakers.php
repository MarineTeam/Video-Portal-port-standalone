<?php
/**
 * @var \App\Core\View $v
 * @var list<array<string, mixed>> $speakers
 */
?>
<h1>Speakers</h1>
<p class="muted">The people a video can name as its speaker. Each has a page listing their videos.</p>
<form data-api="/api/admin/speakers" data-method="POST" class="row card">
  <label>Name<input name="name" required maxlength="255"></label>
  <button class="button primary" type="submit">Add</button>
  <p class="error" data-error hidden></p>
</form>
<?php foreach ($speakers as $s): ?>
  <details class="card">
    <summary><strong><?= e($s['name']) ?></strong> <span class="small muted"><?= e((string) $s['videoCount']) ?> videos</span></summary>
    <form class="stack narrow" data-api="/api/admin/speakers/<?= e($s['id']) ?>" data-method="PATCH">
      <label>Name<input name="name" value="<?= e($s['name']) ?>" required maxlength="255"></label>
      <label>Address<input name="slug" value="<?= e($s['slug']) ?>" pattern="[a-z0-9]+(-[a-z0-9]+)*" maxlength="80"></label>
      <label>About<textarea name="bio" rows="4" data-null><?= e((string) ($s['bio'] ?? '')) ?></textarea></label>
      <?= $v->partial('partials/admin-image-field', ['name' => 'photoUrl', 'label' => 'Photo', 'value' => $s['photoUrl'] ?? null, 'kind' => 'speakers']) ?>
      <div class="row">
        <button class="button primary" type="submit">Save</button>
        <button type="button" class="button danger" data-api="/api/admin/speakers/<?= e($s['id']) ?>" data-method="DELETE" data-confirm="Delete <?= e($s['name']) ?>? Their videos stay, without a speaker.">Delete</button>
      </div>
      <p class="error" data-error hidden></p>
    </form>
  </details>
<?php endforeach ?>
