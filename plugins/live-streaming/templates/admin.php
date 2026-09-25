<?php
/**
 * @var \App\Core\View $v
 * @var list<array<string, mixed>> $rows newest first
 */
$when = static fn (string $iso): string => gmdate('Y-m-d H:i', (int) strtotime($iso)) . ' UTC';
?>
<h1>Live streams</h1>
<p class="muted">A live event points at a stream already hosted somewhere else — YouTube, Boxcast, Resi — so what is kept here is when it starts, where its player is, and whether there is a chat beside it. Publishing one tells members, the way publishing a video does. <a href="<?= e(url('/live')) ?>">/live</a> shows whatever is on now and counts down to the next one otherwise.</p>
<form class="stack card narrow" data-api="/api/admin/live" data-method="POST">
  <label>Title<input name="title" required maxlength="255"></label>
  <label>Embed address<input name="embedUrl" type="url" required placeholder="https://www.youtube.com/embed/…"></label>
  <label>Description (optional)<textarea name="description" rows="2" maxlength="5000"></textarea></label>
  <label>Cover image (optional)<input name="coverImageUrl" type="url" data-null></label>
  <div class="row">
    <label>Starts<input type="datetime-local" name="startAt" data-type="datetime" required></label>
    <label>Ends (optional)<input type="datetime-local" name="endAt" data-type="datetime" data-null></label>
  </div>
  <div class="row">
    <label class="check"><input type="checkbox" name="published"> Published</label>
    <label class="check"><input type="checkbox" name="chatEnabled"> Chat</label>
    <label>Slow mode (seconds)<input type="number" name="chatSlowMode" min="0" max="3600" value="0"></label>
  </div>
  <div><button class="button primary" type="submit">Add stream</button></div>
  <p class="error" data-error hidden></p>
</form>
<?php foreach ($rows as $row): ?>
  <details class="card">
    <summary>
      <?= e((string) $row['title']) ?>
      <span class="badge<?= $row['published'] ? '' : ' muted' ?>"><?= e($row['published'] ? 'published' : 'draft') ?></span>
      <span class="small muted"><time datetime="<?= e((string) $row['startAt']) ?>" data-local-time><?= e($when((string) $row['startAt'])) ?></time></span>
      <?php if ($row['chatEnabled']): ?><span class="badge muted">chat</span><?php endif ?>
    </summary>
    <form class="stack narrow" data-api="/api/admin/live/<?= e((string) $row['id']) ?>" data-method="PATCH">
      <label>Title<input name="title" required maxlength="255" value="<?= e((string) $row['title']) ?>"></label>
      <label>Embed address<input name="embedUrl" type="url" required value="<?= e((string) $row['embedUrl']) ?>"></label>
      <label>Description<textarea name="description" rows="2" maxlength="5000"><?= e((string) ($row['description'] ?? '')) ?></textarea></label>
      <label>Cover image<input name="coverImageUrl" type="url" data-null value="<?= e((string) ($row['coverImageUrl'] ?? '')) ?>"></label>
      <div class="row">
        <label>Starts<input type="datetime-local" name="startAt" data-type="datetime" required data-iso="<?= e((string) $row['startAt']) ?>"></label>
        <label>Ends<input type="datetime-local" name="endAt" data-type="datetime" data-null data-iso="<?= e((string) ($row['endAt'] ?? '')) ?>"></label>
      </div>
      <div class="row">
        <label class="check"><input type="checkbox" name="published"<?= $row['published'] ? ' checked' : '' ?>> Published</label>
        <label class="check"><input type="checkbox" name="chatEnabled"<?= $row['chatEnabled'] ? ' checked' : '' ?>> Chat</label>
        <label>Slow mode (seconds)<input type="number" name="chatSlowMode" min="0" max="3600" value="<?= e((string) $row['chatSlowMode']) ?>"></label>
      </div>
      <div class="row">
        <button class="button primary" type="submit">Save</button>
        <button type="button" class="button danger" data-api="/api/admin/live/<?= e((string) $row['id']) ?>" data-method="DELETE" data-confirm="Delete this stream and its chat?">Delete</button>
      </div>
      <p class="error" data-error hidden></p>
    </form>
  </details>
<?php endforeach ?>
