<?php
/**
 * @var \App\Core\View $v
 * @var array{items: list<array<string, mixed>>, total: int, page: int, pageSize: int} $list
 * @var string $q
 * @var string $mode
 * @var bool $guest
 * @var ?string $primary
 */
$modes = [
    'ORGANIZATION' => 'Only organisation membership is checked — this list is not enforced.',
    'ALLOWLIST' => 'Only this list is checked — organisation membership is not required.',
    'EITHER' => 'Either an organisation membership or an active address on this list is enough on its own.',
];
?>
<h1>Who can sign in</h1>
<p class="muted">An address here doesn’t create an account: it says “if this person signs in, let them in”. Removing or suspending one takes effect on that person’s next click. The Grant and Revoke buttons on <a href="<?= e(url('/admin/users')) ?>">Members &amp; roles</a> write to this same list.</p>
<?php if (isset($modes[$mode])): ?><p class="notice warn">Authorization mode is <strong><?= e($mode) ?></strong>: <?= e($modes[$mode]) ?></p><?php endif ?>

<section class="card">
  <h2>Guest sign-in link</h2>
  <p class="small muted">Lets one invited guest (an address marked Guest below) sign in without organisation membership, through <code><?= e(url('/auth/guest')) ?></code>. Closed, the link doesn’t exist. Only meaningful with a sign-in provider that asks for an organisation.</p>
  <button type="button" class="button small" data-api="/api/admin/guest-login" data-method="PATCH" data-body='<?= e(\App\Core\View::json(['enabled' => !$guest])) ?>'><?= $guest ? 'Close the guest link' : 'Open the guest link' ?></button>
  <span class="badge <?= $guest ? '' : 'muted' ?>"><?= $guest ? 'open' : 'closed' ?></span>
</section>

<form data-api="/api/admin/authorized-emails" data-method="POST" class="row card">
  <label>Email address<input type="email" name="email" required></label>
  <label>Note<input name="note" data-null placeholder="2026 elders"></label>
  <label class="check"><input type="checkbox" name="organizationExempt"> Guest (no organisation needed)</label>
  <button class="button primary" type="submit">Add</button>
  <p class="error" data-error hidden></p>
</form>

<form method="get" class="row"><input type="search" name="q" value="<?= e($q) ?>" placeholder="Search addresses" aria-label="Search addresses"><button class="button" type="submit">Search</button></form>
<table class="table">
  <thead><tr><th>Address</th><th>Status</th><th>Added</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($list['items'] as $row): ?>
    <tr>
      <td><?= e($row['email']) ?><?php if ($row['organizationExempt']): ?> <span class="badge">Guest</span><?php endif ?><?php if ($row['note']): ?><div class="small muted"><?= e($row['note']) ?></div><?php endif ?></td>
      <td><?= e($row['status'] === 'ACTIVE' ? 'Active' : 'Suspended') ?></td>
      <td class="small"><?= e(substr((string) $row['createdAt'], 0, 10)) ?><?php if ($row['addedByEmail']): ?><div class="muted">by <?= e($row['addedByEmail']) ?></div><?php endif ?></td>
      <td class="actions">
        <button type="button" class="button small" data-api="/api/admin/authorized-emails/<?= e($row['id']) ?>" data-method="PATCH" data-body='<?= e(\App\Core\View::json(['status' => $row['status'] === 'ACTIVE' ? 'SUSPENDED' : 'ACTIVE'])) ?>'><?= $row['status'] === 'ACTIVE' ? 'Suspend' : 'Reinstate' ?></button>
        <button type="button" class="button small" data-api="/api/admin/authorized-emails/<?= e($row['id']) ?>" data-method="PATCH" data-body='<?= e(\App\Core\View::json(['organizationExempt' => !$row['organizationExempt']])) ?>'><?= $row['organizationExempt'] ? 'Require organisation' : 'Make guest' ?></button>
        <button type="button" class="button small danger" data-api="/api/admin/authorized-emails/<?= e($row['id']) ?>" data-method="DELETE" data-confirm="Remove <?= e($row['email']) ?> from the list?">Remove</button>
      </td>
    </tr>
  <?php endforeach ?>
  <?php if ($list['items'] === []): ?><tr><td colspan="4" class="muted">No addresses match.</td></tr><?php endif ?>
  </tbody>
</table>
<?= $v->partial('partials/pager', ['list' => $list, 'query' => $q === '' ? [] : ['q' => $q], 'path' => '/admin/authorized-emails']) ?>
