<?php
/**
 * @var \App\Core\View $v
 * @var list<array<string, mixed>> $links
 * @var string $state
 */
?>
<h1>Share links</h1>
<form method="get" class="row">
  <select name="state" aria-label="Which links">
    <option value="">All links</option>
    <option value="active"<?= $state === 'active' ? ' selected' : '' ?>>Active</option>
    <option value="revoked"<?= $state === 'revoked' ? ' selected' : '' ?>>Revoked</option>
  </select>
  <button class="button" type="submit">Filter</button>
</form>
<details class="card">
  <summary>Make a link for any series or video</summary>
  <form class="stack" data-api="/api/admin/share-links" data-method="POST">
    <div class="row">
      <label>Series id<input name="seriesId" data-null maxlength="32"></label>
      <label>or video id<input name="videoId" data-null maxlength="32"></label>
    </div>
    <label class="check"><input type="checkbox" name="grantsAccess"> Let people in who couldn’t otherwise see it</label>
    <label>Expires after (days, blank for never)<input name="expiresInDays" type="number" min="1" max="365" data-type="int" data-null></label>
    <label>Note<input name="note" maxlength="500" data-null></label>
    <div><button class="button primary small" type="submit">Create link</button></div>
    <p class="error" data-error hidden></p>
  </form>
</details>
<?php if ($links === []): ?>
  <p class="muted">No links.</p>
<?php else: ?>
  <?= $v->partial('partials/share-link-rows', ['links' => $links, 'api' => '/api/admin/share-links', 'showOwner' => true, 'showContent' => true]) ?>
<?php endif ?>
