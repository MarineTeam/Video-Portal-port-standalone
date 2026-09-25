<?php
/**
 * @var \App\Core\View $v
 * @var list<array<string, mixed>> $groups
 * @var array<string, array{label: string, hint: string, siteWideOnly: bool}> $capabilities
 * @var list<array<string, mixed>> $assignments
 * @var list<array{id: string, name: string}> $categories
 * @var list<array{id: string, title: string}> $series
 */
?>
<h1>Permissions</h1>
<p class="muted">A group is a bundle of capabilities; assigning it to a member grants them site-wide, or only inside one category (and everything under it) or one series. Administrators always have every capability, and only another administrator can make one — no group can.</p>

<?php foreach ($groups as $g): ?>
<form class="card stack" data-api="/api/admin/permission-groups/<?= e($g['id']) ?>" data-method="PATCH">
  <div class="row">
    <label>Name<input name="name" value="<?= e($g['name']) ?>" required></label>
    <label>Description<input name="description" value="<?= e($g['description'] ?? '') ?>" data-null></label>
  </div>
  <fieldset class="caps">
    <legend>Capabilities</legend>
    <?php foreach ($capabilities as $key => $cap): ?>
      <label class="check" title="<?= e($cap['hint']) ?>"><input type="checkbox" name="capabilities" value="<?= e($key) ?>" data-type="multi" <?= in_array($key, $g['capabilities'], true) ? 'checked' : '' ?>> <?= e($cap['label']) ?><?php if ($cap['siteWideOnly']): ?> <span class="small muted">(site-wide)</span><?php endif ?></label>
    <?php endforeach ?>
  </fieldset>
  <div class="row">
    <button class="button primary" type="submit">Save</button>
    <button type="button" class="button danger" data-api="/api/admin/permission-groups/<?= e($g['id']) ?>" data-method="DELETE" data-confirm="Delete the group “<?= e($g['name']) ?>”? Everyone assigned it loses its capabilities.">Delete</button>
    <span class="small muted"><?= e($g['assignmentCount']) ?> assignment(s)</span>
  </div>
  <p class="error" data-error hidden></p>
</form>
<?php endforeach ?>

<form class="card stack" data-api="/api/admin/permission-groups" data-method="POST">
  <h2>New group</h2>
  <div class="row">
    <label>Name<input name="name" required placeholder="Moderators"></label>
    <label>Description<input name="description" data-null></label>
  </div>
  <fieldset class="caps">
    <legend>Capabilities</legend>
    <?php foreach ($capabilities as $key => $cap): ?>
      <label class="check" title="<?= e($cap['hint']) ?>"><input type="checkbox" name="capabilities" value="<?= e($key) ?>" data-type="multi"> <?= e($cap['label']) ?></label>
    <?php endforeach ?>
  </fieldset>
  <button class="button" type="submit">Create group</button>
  <p class="error" data-error hidden></p>
</form>

<h2>Assignments</h2>
<form class="card row" data-api="/api/admin/group-assignments" data-method="POST">
  <label>Member’s email<input type="email" name="email" required></label>
  <label>Group<select name="groupId" required><?php foreach ($groups as $g): ?><option value="<?= e($g['id']) ?>"><?= e($g['name']) ?></option><?php endforeach ?></select></label>
  <label>Only in category<select name="categoryId" data-null><option value="">Site-wide</option><?php foreach ($categories as $c): ?><option value="<?= e($c['id']) ?>"><?= e($c['name']) ?></option><?php endforeach ?></select></label>
  <label>Or only in series<select name="seriesId" data-null><option value="">—</option><?php foreach ($series as $s): ?><option value="<?= e($s['id']) ?>"><?= e($s['title']) ?></option><?php endforeach ?></select></label>
  <button class="button" type="submit">Assign</button>
  <p class="error" data-error hidden></p>
</form>
<table class="table">
  <thead><tr><th>Member</th><th>Group</th><th>Where</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($assignments as $a): ?>
    <tr>
      <td><?= e($a['userEmail']) ?></td>
      <td><?= e($a['groupName']) ?></td>
      <td><?= e($a['categoryName'] ? 'Category: ' . $a['categoryName'] : ($a['seriesTitle'] ? 'Series: ' . $a['seriesTitle'] : 'Site-wide')) ?></td>
      <td><button type="button" class="button small danger" data-api="/api/admin/group-assignments/<?= e($a['id']) ?>" data-method="DELETE">Remove</button></td>
    </tr>
  <?php endforeach ?>
  <?php if ($assignments === []): ?><tr><td colspan="4" class="muted">No assignments yet.</td></tr><?php endif ?>
  </tbody>
</table>
