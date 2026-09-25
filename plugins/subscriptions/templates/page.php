<?php
/**
 * @var \App\Core\View $v
 * @var list<array{kind: string, id: string, title: string, href: string, muted: bool}> $follows
 * @var bool $pushReady
 */
?>
<h1><?= e(t('subscriptions.title')) ?></h1>
<p class="muted"><?= e(t('subscriptions.intro')) ?><?php if ($pushReady): ?> <a href="<?= e(url('/profile/inbox')) ?>"><?= e(t('subscriptions.turnOnPush')) ?></a><?php endif ?></p>
<?php if ($follows === []): ?>
  <p class="muted"><?= e(t('subscriptions.empty')) ?></p>
<?php else: ?>
  <ul class="plain">
    <?php foreach ($follows as $f): ?>
      <?php $body = [$f['kind'] . 'Id' => $f['id']]; ?>
      <li class="card row">
        <a href="<?= e(url($f['href'])) ?>"><strong><?= e($f['title']) ?></strong></a>
        <span class="badge muted"><?= e(t($f['kind'] === 'series' ? 'subscriptions.series' : 'subscriptions.category')) ?></span>
        <button type="button" class="button small" data-api="/api/subscriptions" data-method="PATCH" data-body="<?= e(\App\Core\View::json($body + ['muted' => !$f['muted']])) ?>"><?= e($f['muted'] ? t('subscriptions.unmute') : t('subscriptions.mute')) ?></button>
        <button type="button" class="button small" data-api="/api/subscriptions" data-body="<?= e(\App\Core\View::json($body)) ?>"><?= e(t('subscriptions.unfollow')) ?></button>
      </li>
    <?php endforeach ?>
  </ul>
<?php endif ?>
