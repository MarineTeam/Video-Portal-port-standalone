<?php
/**
 * "Restricted viewing" on a series or a video.
 *
 * @var \App\Core\View $v
 * @var string $path series|videos
 * @var string $id
 * @var string $noun
 * @var array{users: list<array<string, mixed>>, groups: list<array<string, mixed>>} $viewers
 * @var list<array{id: string, name: string}> $groups
 */
$restricted = $viewers['users'] !== [] || $viewers['groups'] !== [];
?>
<h2>Restricted viewing</h2>
<div class="card stack narrow">
  <p class="small muted"><?= e($restricted
      ? 'Only the people and roles below (and administrators) can view this ' . $noun . '. “Members only” no longer applies to it.'
      : 'Nobody is named, so “Members only” decides. Naming anybody here makes the ' . $noun . ' theirs alone.') ?></p>
  <?php if ($viewers['groups'] !== []): ?>
    <ul class="plain">
      <?php foreach ($viewers['groups'] as $g): ?>
        <li><?= e($g['name']) ?> <button type="button" class="link" data-api="/api/admin/<?= e($path) ?>/viewer-groups/<?= e($g['id']) ?>" data-method="DELETE">Remove</button></li>
      <?php endforeach ?>
    </ul>
  <?php endif ?>
  <?php if ($viewers['users'] !== []): ?>
    <ul class="plain">
      <?php foreach ($viewers['users'] as $u): ?>
        <li><?= e($u['name'] ?: $u['email']) ?> <span class="small muted"><?= e($u['email']) ?></span> <button type="button" class="link" data-api="/api/admin/<?= e($path) ?>/viewers/<?= e($u['id']) ?>" data-method="DELETE">Remove</button></li>
      <?php endforeach ?>
    </ul>
  <?php endif ?>
  <?php if ($groups !== []): ?>
  <form class="row" data-api="/api/admin/<?= e($path) ?>/<?= e($id) ?>/viewer-groups" data-method="POST">
    <select name="groupId" aria-label="Role"><?php foreach ($groups as $g): ?><option value="<?= e($g['id']) ?>"><?= e($g['name']) ?></option><?php endforeach ?></select>
    <button class="button small" type="submit">Add role</button>
    <p class="error" data-error hidden></p>
  </form>
  <?php endif ?>
  <form class="row" data-api="/api/admin/<?= e($path) ?>/<?= e($id) ?>/viewers" data-method="POST">
    <input type="email" name="email" placeholder="member@example.org" aria-label="Member’s email" required>
    <button class="button small" type="submit">Add person</button>
    <p class="error" data-error hidden></p>
  </form>
</div>
