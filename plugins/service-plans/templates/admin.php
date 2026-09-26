<?php
/**
 * @var \App\Core\View $v
 * @var list<array<string, mixed>> $rows
 */
?>
<h1>Service plans</h1>
<p class="muted">The running order for a service, and the rota beside it. A plan can be drafted before the date is settled; drafts stay off the members' list until somebody is happy with the order. <a href="<?= e(url('/admin/services/report')) ?>">What we sang</a> counts every song for a licence return.</p>
<form class="stack card narrow" data-api="/api/admin/services" data-method="POST" data-redirect="/admin/services/{id}">
  <label>Title<input name="title" required maxlength="255" placeholder="Sunday morning"></label>
  <label>The day<input type="date" name="serviceDate" data-null></label>
  <div><button class="button primary" type="submit">Start a plan</button></div>
  <p class="error" data-error hidden></p>
</form>
<?php if ($rows === []): ?>
  <p class="notice">No plans yet.</p>
<?php else: ?>
  <table class="table">
    <thead><tr><th>Service</th><th>Day</th><th>Order</th><th>On the rota</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td><a href="<?= e(url('/admin/services/' . $row['id'])) ?>"><strong><?= e((string) $row['title']) ?></strong></a> <span class="badge<?= $row['published'] ? '' : ' muted' ?>"><?= e($row['published'] ? 'published' : 'draft') ?></span></td>
          <td class="small"><?= e($row['serviceDate'] === null ? '—' : substr((string) $row['serviceDate'], 0, 10)) ?></td>
          <td><?= e((string) $row['items']) ?></td>
          <td><?= e((string) $row['onTheRota']) ?></td>
          <td><a class="button small" href="<?= e(url('/admin/services/' . $row['id'])) ?>">Open</a></td>
        </tr>
      <?php endforeach ?>
    </tbody>
  </table>
<?php endif ?>
