<?php
/**
 * @var \App\Core\View $v
 * @var list<array<string, mixed>> $rows
 */
?>
<h1>Small groups</h1>
<p class="muted">The home groups and studies that meet during the week. A district is on the page for anybody; the address is given only to people actually in the group. Leaders answer their own requests on the group's page — no admin access needed, and no capability to grant.</p>
<form class="stack card narrow" data-api="/api/admin/groups" data-method="POST">
  <label>Name<input name="name" required maxlength="255"></label>
  <label>About it<textarea name="description" rows="2" maxlength="20000" data-null></textarea></label>
  <div class="row">
    <label>When it meets<input name="meetsWhen" maxlength="255" data-null placeholder="Tuesdays, 7.30pm"></label>
    <label>Roughly where (public)<input name="area" maxlength="255" data-null placeholder="North side, near the station"></label>
  </div>
  <label>The address (people in the group only)<input name="address" maxlength="1000" data-null></label>
  <div class="row">
    <label>Leader's email<input name="leaderEmail" type="email" data-null></label>
    <label>Room for<input name="capacity" type="number" min="0" max="1000" data-type="int" data-null></label>
    <label class="check"><input type="checkbox" name="published"> Published</label>
    <label class="check"><input type="checkbox" name="openToJoin" checked> Taking new people</label>
    <label class="check"><input type="checkbox" name="waitlist" checked> Waiting list when full</label>
  </div>
  <div><button class="button primary" type="submit">Add the group</button></div>
  <p class="error" data-error hidden></p>
</form>
<?php foreach ($rows as $row): ?>
  <details class="card">
    <summary>
      <?= e((string) $row['name']) ?>
      <span class="badge<?= $row['published'] ? '' : ' muted' ?>"><?= e($row['published'] ? 'published' : 'draft') ?></span>
      <span class="small muted"><?= e((string) $row['memberCount']) ?> in it<?php if ((int) $row['waiting'] > 0): ?>, <?= e((string) $row['waiting']) ?> waiting<?php endif ?></span>
      <?php if ($row['leaders'] === []): ?><span class="badge error">no leader</span><?php else: ?><span class="small muted"><?= e(implode(', ', $row['leaders'])) ?></span><?php endif ?>
    </summary>
    <form class="stack narrow" data-api="/api/admin/groups/<?= e((string) $row['id']) ?>" data-method="PATCH">
      <label>Name<input name="name" required maxlength="255" value="<?= e((string) $row['name']) ?>"></label>
      <label>About it<textarea name="description" rows="2" maxlength="20000" data-null><?= e((string) ($row['description'] ?? '')) ?></textarea></label>
      <div class="row">
        <label>When it meets<input name="meetsWhen" maxlength="255" data-null value="<?= e((string) ($row['meetsWhen'] ?? '')) ?>"></label>
        <label>Roughly where<input name="area" maxlength="255" data-null value="<?= e((string) ($row['area'] ?? '')) ?>"></label>
      </div>
      <label>The address<input name="address" maxlength="1000" data-null value="<?= e((string) ($row['address'] ?? '')) ?>"></label>
      <div class="row">
        <label>Add a leader by email<input name="leaderEmail" type="email" data-null></label>
        <label>Room for<input name="capacity" type="number" min="0" max="1000" data-type="int" data-null value="<?= e((string) ($row['capacity'] ?? '')) ?>"></label>
        <label class="check"><input type="checkbox" name="published"<?= $row['published'] ? ' checked' : '' ?>> Published</label>
        <label class="check"><input type="checkbox" name="openToJoin"<?= $row['openToJoin'] ? ' checked' : '' ?>> Taking new people</label>
        <label class="check"><input type="checkbox" name="waitlist"<?= $row['waitlist'] ? ' checked' : '' ?>> Waiting list</label>
      </div>
      <div class="row">
        <button class="button primary" type="submit">Save</button>
        <a class="button small" href="<?= e(url('/admin/groups/' . $row['id'])) ?>">Who is in it</a>
        <a class="button small" href="<?= e(url('/groups/' . $row['slug'])) ?>">The group's page</a>
        <button type="button" class="button danger" data-api="/api/admin/groups/<?= e((string) $row['id']) ?>" data-method="DELETE" data-confirm="Delete this group, its conversation and its rolls?">Delete</button>
      </div>
      <p class="error" data-error hidden></p>
    </form>
  </details>
<?php endforeach ?>
