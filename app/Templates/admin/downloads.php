<?php
/**
 * @var \App\Core\View $v
 * @var array{platform: string, audience: string, maxDeviceGb: int, groupIds: list<string>, userIds: list<string>, users: list<array{id: string, email: string}>} $policy
 * @var list<array{id: string, name: string}> $groups
 * @var bool $enabled
 */
?>
<h1>Downloads</h1>
<?php if (!$enabled): ?>
  <p class="notice warn">The Downloads plugin is off, so nobody sees a download button. Turn it on under <a href="<?= e(url('/admin/plugins')) ?>">Plugins</a>.</p>
<?php endif ?>
<p class="small muted">Whether a particular video can be downloaded is set on the video, its series or its category (Inherit, Allow, Block; the most specific wins). This page decides who, and where.</p>
<form class="card stack narrow" data-api="/api/admin/downloads" data-method="PATCH" data-done="Saved." data-no-reload>
  <fieldset class="stack">
    <legend>Who</legend>
    <label class="check"><input type="radio" name="audience" value="ALL_MEMBERS"<?= $policy['audience'] === 'ALL_MEMBERS' ? ' checked' : '' ?>> Any member</label>
    <label class="check"><input type="radio" name="audience" value="SPECIFIC"<?= $policy['audience'] === 'SPECIFIC' ? ' checked' : '' ?>> Only these roles and people (administrators always can)</label>
    <?php foreach ($groups as $g): ?>
      <label class="check"><input type="checkbox" name="groupIds" value="<?= e($g['id']) ?>" data-type="multi"<?= in_array($g['id'], $policy['groupIds'], true) ? ' checked' : '' ?>> <?= e($g['name']) ?></label>
    <?php endforeach ?>
    <label>People (email addresses, one per line)<textarea name="userEmails" rows="3" data-type="list"><?= e(implode("\n", array_column($policy['users'], 'email'))) ?></textarea></label>
  </fieldset>
  <fieldset class="stack">
    <legend>Where</legend>
    <label class="check"><input type="radio" name="platform" value="BOTH"<?= $policy['platform'] === 'BOTH' ? ' checked' : '' ?>> The browser and the installed app</label>
    <label class="check"><input type="radio" name="platform" value="PWA"<?= $policy['platform'] === 'PWA' ? ' checked' : '' ?>> The installed app only</label>
    <label class="check"><input type="radio" name="platform" value="WEB"<?= $policy['platform'] === 'WEB' ? ' checked' : '' ?>> The browser only</label>
  </fieldset>
  <label>Suggested space per device (GB)<input type="number" name="maxDeviceGb" min="1" max="512" value="<?= e((string) $policy['maxDeviceGb']) ?>" data-type="int"></label>
  <div><button class="button primary" type="submit">Save</button></div>
  <p class="small" data-done-message hidden></p>
  <p class="error" data-error hidden></p>
</form>
