<?php
/**
 * The publishing fields every library item shares: published, the publish
 * window, members-only, hidden, and the three-way download setting.
 *
 * @var \App\Core\View $v
 * @var array<string, mixed> $item presented (camelCase) fields
 * @var bool $canPublish
 * @var bool $downloads whether the download setting applies here
 */
$dl = $item['downloadEnabled'] ?? null;
?>
<fieldset class="stack" <?= $v->raw($canPublish ? '' : 'disabled title="Publishing needs the publish-content permission"') ?>>
  <legend>Publishing</legend>
  <label class="check"><input type="checkbox" name="published"<?= !empty($item['published']) ? ' checked' : '' ?>> Published</label>
  <div class="row">
    <label>Publish at (optional)<input type="datetime-local" name="publishAt" data-type="datetime" data-null data-iso="<?= e((string) ($item['publishAt'] ?? '')) ?>"></label>
    <label>Take down at (optional)<input type="datetime-local" name="unpublishAt" data-type="datetime" data-null data-iso="<?= e((string) ($item['unpublishAt'] ?? '')) ?>"></label>
  </div>
  <p class="small muted">A publish time keeps it hidden until then even when ticked published; a take-down time removes it on its own.</p>
</fieldset>
<fieldset class="stack">
  <legend>Who sees it</legend>
  <label class="check"><input type="checkbox" name="memberOnly"<?= !empty($item['memberOnly']) ? ' checked' : '' ?>> Members only — visitors see it isn’t there; a direct link asks them to sign in</label>
  <label class="check"><input type="checkbox" name="hidden"<?= !empty($item['hidden']) ? ' checked' : '' ?>> Hidden — nobody but the people managing it can find or open it</label>
  <?php if (!empty($downloads)): ?>
  <label>Downloads
    <select name="downloadEnabled" data-type="bool" data-null>
      <option value=""<?= $dl === null ? ' selected' : '' ?>>Inherit</option>
      <option value="true"<?= $dl === true ? ' selected' : '' ?>>Allow</option>
      <option value="false"<?= $dl === false ? ' selected' : '' ?>>Block</option>
    </select>
  </label>
  <?php endif ?>
</fieldset>
