<?php
/**
 * The televisions signed in to this account. A sign-in does not run out on
 * its own, which is exactly why a set somebody no longer has should be
 * signed out here.
 *
 * @var \App\Core\View $v
 * @var list<array<string, mixed>> $devices
 */
?>
<h1><?= e(t('tv.myDevices')) ?></h1>
<p class="small muted"><?= e(t('tv.myDevicesHint')) ?></p>
<?php if ($devices === []): ?>
  <p class="muted"><?= e(t('tv.noDevices')) ?></p>
<?php else: ?>
  <ul class="plain">
    <?php foreach ($devices as $device): ?>
      <li class="row" data-device="<?= e((string) $device['id']) ?>">
        <strong><?= e((string) $device['name']) ?></strong>
        <?php if ($device['linkedAt'] !== null): ?>
          <span class="small muted"><time datetime="<?= e((string) $device['linkedAt']) ?>" data-local-time><?= e((string) $device['linkedAt']) ?></time></span>
        <?php endif ?>
        <?php if ($device['lastSeenAt'] !== null): ?>
          <span class="small muted"><?= e(t('tv.lastSeen', ['when' => ''])) ?> <time datetime="<?= e((string) $device['lastSeenAt']) ?>" data-local-time><?= e((string) $device['lastSeenAt']) ?></time></span>
        <?php endif ?>
        <button class="button small danger" type="button" data-api="/api/profile/devices/<?= e((string) $device['id']) ?>" data-method="DELETE" data-confirm="<?= e(t('tv.signOutConfirm')) ?>"><?= e(t('tv.signOut')) ?></button>
      </li>
    <?php endforeach ?>
  </ul>
<?php endif ?>
