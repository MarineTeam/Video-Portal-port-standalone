<?php
/**
 * One event in the admin area: its fields, and who is coming.
 *
 * @var \App\Core\View $v
 * @var array<string, mixed> $event
 * @var list<array<string, mixed>> $registrations
 * @var int $taken places taken, guests counted
 */
?>
<p><a href="<?= e(url('/admin/events')) ?>">← Events</a></p>
<h1><?= e((string) $event['title']) ?></h1>
<p class="muted"><?= e((string) $event['when']) ?><?php if ($event['location'] !== null && $event['location'] !== ''): ?> · <?= e((string) $event['location']) ?><?php endif ?></p>
<form class="stack card narrow" data-api="/api/admin/events/<?= e((string) $event['id']) ?>" data-method="PATCH">
  <?= $v->partial('events/admin-fields', ['row' => $event]) ?>
  <div class="row">
    <button class="button primary" type="submit">Save</button>
    <a class="button small" href="<?= e(url('/events/' . $event['slug'])) ?>">See the page</a>
    <a class="button small" href="<?= e(url('/api/admin/events/' . $event['id'] . '/registrations?format=csv')) ?>">CSV for the door</a>
  </div>
  <p class="error" data-error hidden></p>
</form>
<?php if ($event['registration']): ?>
  <h2>Who is coming</h2>
  <p class="muted"><?= e((string) $taken) ?> place<?= e($taken === 1 ? '' : 's') ?> taken<?php if ($event['capacity'] !== null): ?> of <?= e((string) $event['capacity']) ?><?php endif ?>. A guest counts as a place.</p>
  <?php if ($registrations === []): ?>
    <p class="notice">Nobody yet.</p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>With</th><th>Status</th><th>Member</th><th>Note</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($registrations as $r): ?>
          <tr>
            <td><?= e((string) $r['name']) ?></td>
            <td><?= e((string) $r['email']) ?></td>
            <td><?= e((string) ($r['phone'] ?? '')) ?></td>
            <td><?= e((string) $r['guests']) ?></td>
            <td><?= e(strtolower((string) $r['status'])) ?><?php if ($r['promotedAt'] !== null): ?> <span class="badge muted">moved up</span><?php endif ?></td>
            <td><?= e($r['member'] ? 'yes' : 'no') ?></td>
            <td class="small"><?= e((string) ($r['note'] ?? '')) ?></td>
            <td><?php if ($r['status'] !== 'CANCELLED'): ?><button type="button" class="button small danger" data-api="/api/admin/events/<?= e((string) $event['id']) ?>/registrations/<?= e((string) $r['id']) ?>" data-method="DELETE" data-confirm="Take this person off the list?">Remove</button><?php endif ?></td>
          </tr>
        <?php endforeach ?>
      </tbody>
    </table>
  <?php endif ?>
<?php endif ?>
