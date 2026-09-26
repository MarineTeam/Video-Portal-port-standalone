<?php
/**
 * One group in the admin area: its fields, and everybody against it.
 *
 * @var \App\Core\View $v
 * @var array<string, mixed> $group with its members
 * @var list<string> $statuses
 */
$api = '/api/admin/groups/' . $group['id'];
?>
<p><a href="<?= e(url('/admin/groups')) ?>">← Small groups</a></p>
<h1><?= e((string) $group['name']) ?></h1>
<p class="muted"><a href="<?= e(url('/groups/' . $group['slug'])) ?>">/groups/<?= e((string) $group['slug']) ?></a> · <?= e((string) $group['memberCount']) ?> in it</p>
<form class="stack card narrow" data-api="<?= e($api) ?>" data-method="PATCH">
  <label>Name<input name="name" required maxlength="255" value="<?= e((string) $group['name']) ?>"></label>
  <label>About it<textarea name="description" rows="2" maxlength="20000" data-null><?= e((string) ($group['description'] ?? '')) ?></textarea></label>
  <div class="row">
    <label>When it meets<input name="meetsWhen" maxlength="255" data-null value="<?= e((string) ($group['meetsWhen'] ?? '')) ?>"></label>
    <label>Roughly where (public)<input name="area" maxlength="255" data-null value="<?= e((string) ($group['area'] ?? '')) ?>"></label>
  </div>
  <label>The address (people in the group only)<input name="address" maxlength="1000" data-null value="<?= e((string) ($group['address'] ?? '')) ?>"></label>
  <div class="row">
    <label>Room for<input name="capacity" type="number" min="0" max="1000" data-type="int" data-null value="<?= e((string) ($group['capacity'] ?? '')) ?>"></label>
    <label class="check"><input type="checkbox" name="published"<?= $group['published'] ? ' checked' : '' ?>> Published</label>
    <label class="check"><input type="checkbox" name="openToJoin"<?= $group['openToJoin'] ? ' checked' : '' ?>> Taking new people</label>
    <label class="check"><input type="checkbox" name="waitlist"<?= $group['waitlist'] ? ' checked' : '' ?>> Waiting list</label>
  </div>
  <div class="row">
    <button class="button primary" type="submit">Save</button>
    <button type="button" class="button danger" data-api="<?= e($api) ?>" data-method="DELETE" data-redirect="/admin/groups" data-confirm="Delete this group, its conversation and its rolls?">Delete the group</button>
  </div>
  <p class="error" data-error hidden></p>
</form>

<h2>Who is in it</h2>
<p class="muted">Putting somebody in a group works from here, and leaves a row saying so — which is how a site manager joins a conversation they need to read.</p>
<table class="table">
  <thead><tr><th>Name</th><th>Email</th><th>Standing</th><th>Role</th><th>What they said</th><th></th></tr></thead>
  <tbody>
    <?php foreach ($group['members'] as $member): ?>
      <tr>
        <td><?= e((string) $member['name']) ?></td>
        <td class="small"><?= e((string) $member['email']) ?></td>
        <td><?= e(strtolower((string) $member['status'])) ?></td>
        <td><?= e(strtolower((string) $member['role'])) ?></td>
        <td class="small"><?= e((string) ($member['note'] ?? '')) ?></td>
        <td><button type="button" class="button small danger" data-api="<?= e($api . '/members/' . $member['id']) ?>" data-method="DELETE" data-confirm="Take this person off the group?">Remove</button></td>
      </tr>
    <?php endforeach ?>
  </tbody>
</table>
<form class="row card" data-api="<?= e($api . '/members') ?>" data-method="POST">
  <label>Add by email<input name="email" type="email" required></label>
  <label>Role<select name="role"><option value="MEMBER">member</option><option value="LEADER">leader</option></select></label>
  <label>Standing<select name="status"><?php foreach ($statuses as $status): ?><option value="<?= e($status) ?>"<?= $status === 'ACTIVE' ? ' selected' : '' ?>><?= e(strtolower($status)) ?></option><?php endforeach ?></select></label>
  <button class="button primary" type="submit">Add</button>
  <p class="error" data-error hidden></p>
</form>
