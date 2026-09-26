<?php
/**
 * @var \App\Core\View $v
 * @var array $shell
 * @var list<array{href: string, label: string, badge?: int}> $sections
 * @var array<string, mixed> $fields the account settings this site offers (by the plugins that are on)
 * @var string $email
 * @var bool $hasPassword
 * @var int $otherSessions
 * @var ?string $calendarUrl the member's own diary feed, once they ask for one
 * @var string $extra what plugins add
 */
$has = static fn (string $key): bool => array_key_exists($key, $fields);
?>
<h1><?= e(t('profile.settings')) ?></h1>
<?= $v->partial('partials/profile-nav', ['shell' => $shell, 'sections' => $sections]) ?>

<section class="card stack narrow" data-device-settings>
  <h2><?= e(t('settings.device')) ?></h2>
  <p class="small muted"><?= e(t('settings.deviceHint')) ?></p>
  <fieldset class="row">
    <legend><?= e(t('common.theme')) ?></legend>
    <label class="check"><input type="radio" name="theme" value="system"> <?= e(t('settings.themeSystem')) ?></label>
    <label class="check"><input type="radio" name="theme" value="light"> <?= e(t('settings.themeLight')) ?></label>
    <label class="check"><input type="radio" name="theme" value="dark"> <?= e(t('settings.themeDark')) ?></label>
  </fieldset>
  <label><?= e(t('common.language')) ?>
    <select name="language">
      <?php foreach (\App\Modules\I18n\I18n::LOCALES as $code => $label): ?>
        <option value="<?= e($code) ?>"<?= $shell['locale'] === $code ? ' selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach ?>
    </select>
  </label>
  <label class="check"><input type="checkbox" name="autoplay"> <?= e(t('settings.autoplay')) ?></label>
  <label><?= e(t('settings.speed')) ?>
    <select name="playbackSpeed" data-type="number">
      <?php foreach ([0.5, 0.75, 1, 1.25, 1.5, 1.75, 2] as $speed): ?>
        <option value="<?= e((string) $speed) ?>"><?= e($speed . '×') ?></option>
      <?php endforeach ?>
    </select>
  </label>
  <h3><?= e(t('settings.reading')) ?></h3>
  <label class="check"><input type="checkbox" name="keepScreenOn"> <?= e(t('settings.keepScreenOn')) ?></label>
  <label class="check"><input type="checkbox" name="swipePages"> <?= e(t('settings.swipePages')) ?></label>
  <label><?= e(t('settings.readingSize')) ?>
    <input type="range" name="readingTextScale" min="0.75" max="2" step="0.05" data-type="number">
  </label>
  <p class="small muted" data-device-saved hidden><?= e(t('settings.saved')) ?></p>
</section>

<section class="card stack narrow" data-tab-editor data-options="<?= e(\App\Core\View::json(array_map(fn ($t) => ['href' => $t['href'], 'label' => $t['label']], $shell['tabOptions']))) ?>" data-suggested="<?= e(\App\Core\View::json(array_map(fn ($t) => $t['href'], $shell['tabs']))) ?>">
  <h2><?= e(t('settings.bottomBar')) ?></h2>
  <p class="small muted"><?= e(t('settings.bottomBarHint')) ?></p>
  <ol class="tab-editor" data-tab-list></ol>
  <div class="row">
    <select data-tab-add aria-label="<?= e(t('settings.bottomBar')) ?>"></select>
    <button class="button small" type="button" data-tab-reset><?= e(t('settings.bottomBarReset')) ?></button>
  </div>
</section>

<section class="card stack narrow">
  <h2><?= e(t('settings.account')) ?></h2>
  <p class="small muted"><?= e(t('settings.accountHint')) ?></p>
  <?php if ($fields === [] && trim($extra) === ''): ?>
    <p class="muted"><?= e(t('settings.noAccountSettings')) ?></p>
  <?php endif ?>
  <?php if ($fields !== []): ?>
  <form class="stack" data-api="/api/profile" data-method="PATCH">
    <?php if ($has('displayName')): ?>
      <label><?= e(t('settings.displayName')) ?><input name="displayName" value="<?= e((string) ($fields['displayName'] ?? '')) ?>" maxlength="80" data-null></label>
      <p class="small muted"><?= e(t('settings.displayNameHint')) ?></p>
    <?php endif ?>
    <?php if ($has('notificationFrequency')): ?>
      <label><?= e(t('settings.notifyFrequency')) ?>
        <select name="notificationFrequency">
          <option value="INSTANT"<?= $fields['notificationFrequency'] === 'INSTANT' ? ' selected' : '' ?>><?= e(t('settings.notifyInstant')) ?></option>
          <option value="DAILY"<?= $fields['notificationFrequency'] === 'DAILY' ? ' selected' : '' ?>><?= e(t('settings.notifyDaily')) ?></option>
        </select>
      </label>
    <?php endif ?>
    <?php foreach (['emailNotifications', 'broadcastEmails'] as $flag): if ($has($flag)): ?>
      <label class="check"><input type="checkbox" name="<?= e($flag) ?>"<?= $fields[$flag] ? ' checked' : '' ?>> <?= e(t('settings.' . $flag)) ?></label>
    <?php endif; endforeach ?>
    <?php if ($has('phone')): ?>
      <label><?= e(t('settings.phone')) ?><input type="tel" name="phone" value="<?= e((string) ($fields['phone'] ?? '')) ?>" maxlength="32" autocomplete="tel" data-null></label>
    <?php endif ?>
    <?php foreach (['smsOptIn', 'directoryListed', 'directoryShowEmail', 'directoryShowPhone'] as $flag): if ($has($flag)): ?>
      <label class="check"><input type="checkbox" name="<?= e($flag) ?>"<?= $fields[$flag] ? ' checked' : '' ?>> <?= e(t('settings.' . $flag)) ?></label>
    <?php endif; endforeach ?>
    <?php if ($has('directoryNote')): ?>
      <label><?= e(t('settings.directoryNote')) ?><input name="directoryNote" value="<?= e((string) ($fields['directoryNote'] ?? '')) ?>" maxlength="500" data-null></label>
    <?php endif ?>
    <div><button class="button primary" type="submit"><?= e(t('common.save')) ?></button></div>
    <p class="error" data-error hidden></p>
  </form>
  <?php endif ?>
  <?= $v->raw($extra) ?>
</section>

<section class="card stack narrow">
  <h2><?= e(t('settings.signIn')) ?></h2>
  <form class="stack" data-api="/api/profile/password" data-method="POST" data-no-reload data-done="<?= e(t('settings.passwordChanged')) ?>">
    <?php if ($hasPassword): ?>
      <label><?= e(t('settings.currentPassword')) ?><input type="password" name="current" autocomplete="current-password" required></label>
    <?php endif ?>
    <label><?= e(t('settings.newPassword')) ?><input type="password" name="password" autocomplete="new-password" minlength="12" required></label>
    <div><button class="button" type="submit"><?= e(t($hasPassword ? 'settings.changePassword' : 'settings.setPassword')) ?></button></div>
    <p class="small" data-done-message hidden></p>
    <p class="error" data-error hidden></p>
  </form>
  <?php if ($otherSessions > 0): ?>
    <p class="small"><?= e(t('settings.otherSessions', ['count' => $otherSessions])) ?></p>
    <div><button class="button small" type="button" data-api="/api/profile/sessions" data-method="DELETE"><?= e(t('settings.signOutElsewhere')) ?></button></div>
  <?php endif ?>
</section>

<section class="card stack narrow">
  <h2><?= e(t('settings.calendar')) ?></h2>
  <p class="small muted"><?= e(t('settings.calendarHint')) ?></p>
  <?php if ($calendarUrl !== null): ?>
    <p><input type="text" readonly value="<?= e($calendarUrl) ?>" aria-label="<?= e(t('settings.calendar')) ?>" data-copy-link="<?= e($calendarUrl) ?>"></p>
    <div class="row">
      <button class="button small" type="button" data-api="/api/profile/calendar" data-method="POST" data-confirm="<?= e(t('settings.calendarReplaceConfirm')) ?>"><?= e(t('settings.calendarReplace')) ?></button>
      <button class="button small danger" type="button" data-api="/api/profile/calendar" data-method="DELETE" data-confirm="<?= e(t('settings.calendarStopConfirm')) ?>"><?= e(t('settings.calendarStop')) ?></button>
    </div>
  <?php else: ?>
    <div><button class="button" type="button" data-api="/api/profile/calendar" data-method="POST"><?= e(t('settings.calendarMake')) ?></button></div>
  <?php endif ?>
</section>

<section class="card stack narrow">
  <h2><?= e(t('settings.yourData')) ?></h2>
  <p><?= e(t('settings.exportHint')) ?></p>
  <div><a class="button" href="<?= e(url('/api/profile/export')) ?>" download><?= e(t('settings.export')) ?></a></div>
  <hr>
  <p><?= e(t('settings.deleteHint')) ?></p>
  <form class="stack" data-api="/api/profile" data-method="DELETE" data-redirect="/">
    <label><?= e(t('settings.deleteConfirmLabel', ['email' => $email])) ?><input type="email" name="confirm" autocomplete="off" required></label>
    <div><button class="button danger" type="submit"><?= e(t('settings.delete')) ?></button></div>
    <p class="error" data-error hidden></p>
  </form>
</section>
<script type="module" src="<?= e(asset('js/profile.js')) ?>"></script>
