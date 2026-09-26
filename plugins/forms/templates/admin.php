<?php
/**
 * @var \App\Core\View $v
 * @var list<array<string, mixed>> $rows
 */
?>
<h1>Forms</h1>
<p class="muted">Connect cards and sign-up forms. The questions are rows you add here, not code somebody deploys: they change every term, and "add a box for dietary requirements" shouldn't need a release. An account is not needed to fill one in — that is the whole point of a connect card.</p>
<form class="stack card narrow" data-api="/api/admin/forms" data-method="POST" data-redirect="/admin/forms/{id}">
  <label>Title<input name="title" required maxlength="255"></label>
  <label>About it<textarea name="description" rows="2" maxlength="20000" data-null></textarea></label>
  <div><button class="button primary" type="submit">Add a form</button></div>
  <p class="error" data-error hidden></p>
</form>
<?php if ($rows === []): ?>
  <p class="notice">No forms yet.</p>
<?php else: ?>
  <table class="table">
    <thead><tr><th>Form</th><th>Address</th><th>Responses</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td><a href="<?= e(url('/admin/forms/' . $row['id'])) ?>"><strong><?= e((string) $row['title']) ?></strong></a>
            <span class="badge<?= $row['published'] ? '' : ' muted' ?>"><?= e($row['published'] ? 'published' : 'draft') ?></span>
            <?php if ($row['memberOnly']): ?><span class="badge muted">members only</span><?php endif ?>
          </td>
          <td class="small"><a href="<?= e(url('/forms/' . $row['slug'])) ?>">/forms/<?= e((string) $row['slug']) ?></a></td>
          <td><?= e((string) ($row['submissions'] ?? 0)) ?><?php if ((int) ($row['waiting'] ?? 0) > 0): ?> <span class="badge"><?= e((string) $row['waiting']) ?> to deal with</span><?php endif ?></td>
          <td><a class="button small" href="<?= e(url('/admin/forms/' . $row['id'])) ?>">Open</a></td>
        </tr>
      <?php endforeach ?>
    </tbody>
  </table>
<?php endif ?>
