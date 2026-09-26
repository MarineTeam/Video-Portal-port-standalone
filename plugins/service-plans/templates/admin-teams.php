<?php
/**
 * @var \App\Core\View $v
 * @var list<array<string, mixed>> $teams
 */
?>
<h1>Teams</h1>
<p class="muted">The pick-list a rota is built from. What somebody usually does is offered as the default when they are scheduled; the job itself is free text on the ask, because every church names those differently.</p>
<form class="row card" data-api="/api/admin/teams" data-method="POST">
  <label>Name<input name="name" required maxlength="255" placeholder="Tech team"></label>
  <label>Order<input name="position" type="number" min="0" max="1000" value="0" data-type="int"></label>
  <button class="button primary" type="submit">Add a team</button>
  <p class="error" data-error hidden></p>
</form>
<?php foreach ($teams as $team): ?>
  <details class="card">
    <summary><?= e((string) $team['name']) ?> <span class="small muted"><?= e((string) count($team['members'])) ?> people</span></summary>
    <form class="row" data-api="/api/admin/teams/<?= e((string) $team['id']) ?>" data-method="PATCH">
      <label>Name<input name="name" required maxlength="255" value="<?= e((string) $team['name']) ?>"></label>
      <label>Order<input name="position" type="number" min="0" max="1000" data-type="int" value="<?= e((string) $team['position']) ?>"></label>
      <button class="button small primary" type="submit">Save</button>
      <button type="button" class="button small danger" data-api="/api/admin/teams/<?= e((string) $team['id']) ?>" data-method="DELETE" data-confirm="Delete this team and every ask on it?">Delete</button>
      <p class="error" data-error hidden></p>
    </form>
    <ul class="plain">
      <?php foreach ($team['members'] as $member): ?>
        <li class="row">
          <span><?= e((string) $member['name']) ?> <span class="small muted"><?= e((string) $member['email']) ?><?php if ($member['position'] !== null && $member['position'] !== ''): ?> · <?= e((string) $member['position']) ?><?php endif ?></span></span>
          <button type="button" class="button small danger" data-api="/api/admin/teams/<?= e((string) $team['id']) ?>" data-method="PATCH" data-body="<?= e(\App\Core\View::json(['name' => $team['name'], 'removeMemberId' => $member['id']])) ?>">Remove</button>
        </li>
      <?php endforeach ?>
    </ul>
    <form class="row" data-api="/api/admin/teams/<?= e((string) $team['id']) ?>" data-method="PATCH">
      <input type="hidden" name="name" value="<?= e((string) $team['name']) ?>">
      <label>Add by email<input name="addEmail" type="email" required></label>
      <label>What they usually do<input name="addPosition" maxlength="255" data-null></label>
      <button class="button small primary" type="submit">Add</button>
      <p class="error" data-error hidden></p>
    </form>
  </details>
<?php endforeach ?>
