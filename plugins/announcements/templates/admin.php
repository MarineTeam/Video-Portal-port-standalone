<?php
/**
 * @var \App\Core\View $v
 * @var list<array<string, mixed>> $rows newest first
 */
$audiences = ['ALL' => 'Everyone', 'GUESTS' => 'Guests (not signed in)', 'MEMBERS' => 'Members (signed in)'];
?>
<h1>Announcements</h1>
<p class="muted">One banner across the top of every page: the newest active announcement for the reader, inside its window if it has one. Readers can dismiss it for the rest of their visit.</p>
<form class="stack card narrow" data-api="/api/admin/announcements" data-method="POST">
  <label>Message<textarea name="message" rows="2" required maxlength="1000"></textarea></label>
  <label>Shown to<select name="audience"><?php foreach ($audiences as $value => $label): ?><option value="<?= e($value) ?>"><?= e($label) ?></option><?php endforeach ?></select></label>
  <div class="row">
    <label>From (optional)<input type="datetime-local" name="publishAt" data-type="datetime" data-null></label>
    <label>Until (optional)<input type="datetime-local" name="expiresAt" data-type="datetime" data-null></label>
  </div>
  <label class="check"><input type="checkbox" name="active" checked> Active</label>
  <div><button class="button primary" type="submit">Add announcement</button></div>
  <p class="error" data-error hidden></p>
</form>
<?php foreach ($rows as $row): ?>
  <details class="card">
    <summary><?= e(mb_strimwidth((string) $row['message'], 0, 80, '…')) ?> <span class="badge<?= $row['active'] ? '' : ' muted' ?>"><?= e($row['active'] ? 'active' : 'off') ?></span> <span class="small muted"><?= e($audiences[$row['audience']] ?? $row['audience']) ?></span></summary>
    <form class="stack narrow" data-api="/api/admin/announcements/<?= e($row['id']) ?>" data-method="PATCH">
      <label>Message<textarea name="message" rows="2" required maxlength="1000"><?= e((string) $row['message']) ?></textarea></label>
      <label>Shown to<select name="audience"><?php foreach ($audiences as $value => $label): ?><option value="<?= e($value) ?>"<?= $row['audience'] === $value ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach ?></select></label>
      <div class="row">
        <label>From<input type="datetime-local" name="publishAt" data-type="datetime" data-null data-iso="<?= e((string) ($row['publishAt'] ?? '')) ?>"></label>
        <label>Until<input type="datetime-local" name="expiresAt" data-type="datetime" data-null data-iso="<?= e((string) ($row['expiresAt'] ?? '')) ?>"></label>
      </div>
      <label class="check"><input type="checkbox" name="active"<?= $row['active'] ? ' checked' : '' ?>> Active</label>
      <div class="row">
        <button class="button primary" type="submit">Save</button>
        <button type="button" class="button danger" data-api="/api/admin/announcements/<?= e($row['id']) ?>" data-method="DELETE" data-confirm="Delete this announcement?">Delete</button>
      </div>
      <p class="error" data-error hidden></p>
    </form>
  </details>
<?php endforeach ?>
