<?php
/**
 * @var \App\Core\View $v
 * @var array{items: list<array<string, mixed>>, total: int, page: int, pageSize: int} $list
 * @var string $q
 * @var bool $isAdmin
 * @var array{categoryEditors: list<array<string, mixed>>, seriesEditors: list<array<string, mixed>>} $editors
 * @var list<array{id: string, name: string}> $categories
 * @var list<array{id: string, title: string}> $series
 */
?>
<h1>Members &amp; roles</h1>
<p class="muted">Accounts, roles, and editor grants. Access itself is decided by <a href="<?= e(url('/admin/authorized-emails')) ?>">Who can sign in</a>; Grant and Revoke here write to that list.</p>

<form data-api="/api/admin/users" data-method="POST" class="row card">
  <label>Pre-authorise an address<input type="email" name="email" required></label>
  <label>Name<input name="name" data-null></label>
  <button class="button primary" type="submit">Add</button>
  <p class="error" data-error hidden></p>
</form>

<form method="get" class="row"><input type="search" name="q" value="<?= e($q) ?>" placeholder="Search members" aria-label="Search members"><button class="button" type="submit">Search</button></form>
<table class="table">
  <thead><tr><th>Member</th><th>Role</th><th>Access</th><th>Signs in with</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($list['items'] as $u): ?>
    <tr>
      <td><?= e($u['displayName'] ?: ($u['name'] ?: $u['email'])) ?><div class="small muted"><?= e($u['email']) ?></div></td>
      <td><?= e($u['role'] === 'ADMIN' ? 'Administrator' : 'Member') ?></td>
      <td><?= e($u['authorized'] ? 'Allowed' : ($u['lastLoginAt'] ? 'Refused' : 'Pending')) ?></td>
      <td class="small"><?= e(implode(', ', $u['providers']) ?: '—') ?></td>
      <td class="actions">
        <?php if ($u['authorized']): ?>
          <button type="button" class="button small" data-api="/api/admin/users/<?= e($u['id']) ?>" data-method="PATCH" data-body='{"authorized":false}' data-confirm="Revoke access for <?= e($u['email']) ?>? They are signed out on their next click.">Revoke</button>
        <?php else: ?>
          <button type="button" class="button small" data-api="/api/admin/users/<?= e($u['id']) ?>" data-method="PATCH" data-body='{"authorized":true}'>Grant</button>
        <?php endif ?>
        <?php if ($isAdmin): ?>
          <button type="button" class="button small" data-api="/api/admin/users/<?= e($u['id']) ?>" data-method="PATCH" data-body='<?= e(\App\Core\View::json(['role' => $u['role'] === 'ADMIN' ? 'MEMBER' : 'ADMIN'])) ?>' data-confirm="<?= e($u['role'] === 'ADMIN' ? 'Remove administrator rights from ' : 'Make an administrator: ') . e($u['email']) ?>?"><?= $u['role'] === 'ADMIN' ? 'Make member' : 'Make admin' ?></button>
        <?php endif ?>
        <button type="button" class="button small danger" data-api="/api/admin/users/<?= e($u['id']) ?>" data-method="DELETE" data-confirm="Delete <?= e($u['email']) ?>’s account and everything they created? This can’t be undone.">Delete</button>
      </td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
<?= $v->partial('partials/pager', ['list' => $list, 'query' => $q === '' ? [] : ['q' => $q], 'path' => '/admin/users']) ?>

<h2>Content editors</h2>
<p class="small muted">An editor can manage the series, videos and files inside one category (and everything under it) or one series, and nothing else.</p>
<div class="row">
  <form data-api="/api/admin/editors/category" data-method="POST" class="card stack">
    <label>Member’s email<input type="email" name="email" required></label>
    <label>Category<select name="categoryId" required><?php foreach ($categories as $c): ?><option value="<?= e($c['id']) ?>"><?= e($c['name']) ?></option><?php endforeach ?></select></label>
    <button class="button" type="submit">Grant category</button>
    <p class="error" data-error hidden></p>
  </form>
  <form data-api="/api/admin/editors/series" data-method="POST" class="card stack">
    <label>Member’s email<input type="email" name="email" required></label>
    <label>Series<select name="seriesId" required><?php foreach ($series as $s): ?><option value="<?= e($s['id']) ?>"><?= e($s['title']) ?></option><?php endforeach ?></select></label>
    <button class="button" type="submit">Grant series</button>
    <p class="error" data-error hidden></p>
  </form>
</div>
<table class="table">
  <tbody>
  <?php foreach ($editors['categoryEditors'] as $e): ?>
    <tr><td><?= e($e['userEmail']) ?></td><td>Category: <?= e($e['categoryName']) ?></td><td><button type="button" class="button small danger" data-api="/api/admin/editors/category/<?= e($e['id']) ?>" data-method="DELETE">Remove</button></td></tr>
  <?php endforeach ?>
  <?php foreach ($editors['seriesEditors'] as $e): ?>
    <tr><td><?= e($e['userEmail']) ?></td><td>Series: <?= e($e['seriesTitle']) ?></td><td><button type="button" class="button small danger" data-api="/api/admin/editors/series/<?= e($e['id']) ?>" data-method="DELETE">Remove</button></td></tr>
  <?php endforeach ?>
  <?php if ($editors['categoryEditors'] === [] && $editors['seriesEditors'] === []): ?><tr><td class="muted">No editor grants.</td></tr><?php endif ?>
  </tbody>
</table>
