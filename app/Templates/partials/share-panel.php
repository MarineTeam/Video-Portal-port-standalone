<?php
/**
 * "Share a link" on a series or video page.
 *
 * @var \App\Core\View $v
 * @var array{type: string, id: string, restricted: bool, mayOverride: bool, links: list<array<string, mixed>>} $share
 */
$key = $share['type'] === 'series' ? 'seriesId' : 'videoId';
?>
<details class="card share-panel">
  <summary><?= e(t('share.panelTitle')) ?></summary>
  <form class="stack" data-api="/api/share-links" data-method="POST">
    <input type="hidden" name="<?= e($key) ?>" value="<?= e($share['id']) ?>">
    <fieldset class="row">
      <label class="check"><input type="radio" name="visibility" value="PUBLIC" checked> <?= e(t('share.public')) ?></label>
      <label class="check"><input type="radio" name="visibility" value="PRIVATE"> <?= e(t('share.private')) ?></label>
    </fieldset>
    <label><?= e(t('share.recipients')) ?><textarea name="recipients" rows="2" data-null placeholder="ruth@example.org, boaz@example.org"></textarea></label>
    <?php if ($share['restricted']): ?>
      <?php if ($share['mayOverride']): ?>
        <label class="check"><input type="checkbox" name="grantsAccess"> <?= e(t('share.letIn')) ?></label>
      <?php else: ?>
        <p class="small muted"><?= e(t('share.onlyThoseWithAccess')) ?></p>
      <?php endif ?>
    <?php endif ?>
    <div class="row">
      <label><?= e(t('share.password')) ?><input type="password" name="password" minlength="6" maxlength="200" autocomplete="new-password" data-null></label>
      <label><?= e(t('share.expires')) ?>
        <select name="expiresInDays" data-type="int" data-null>
          <option value=""><?= e(t('share.never')) ?></option>
          <?php foreach ([1, 7, 30, 90, 365] as $d): ?><option value="<?= e((string) $d) ?>"><?= e(t('share.days', ['count' => (string) $d])) ?></option><?php endforeach ?>
        </select>
      </label>
    </div>
    <label><?= e(t('share.note')) ?><input name="note" maxlength="500" data-null></label>
    <div><button class="button primary small" type="submit"><?= e(t('share.create')) ?></button></div>
    <p class="error" data-error hidden></p>
  </form>
  <?php if ($share['links'] !== []): ?>
    <?= $v->partial('partials/share-link-rows', ['links' => $share['links'], 'api' => '/api/share-links', 'showOwner' => false, 'showContent' => false]) ?>
  <?php endif ?>
</details>
