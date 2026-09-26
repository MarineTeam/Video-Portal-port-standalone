<?php
/**
 * /groups: the list somebody is choosing between.
 *
 * @var \App\Core\View $v
 * @var list<array<string, mixed>> $groups
 */
?>
<h1><?= e(t('groups.title')) ?></h1>
<p class="muted"><?= e(t('groups.intro')) ?></p>
<?php if ($groups === []): ?>
  <p class="muted"><?= e(t('groups.none')) ?></p>
<?php else: ?>
  <ul class="plain">
    <?php foreach ($groups as $group): ?>
      <li class="card">
        <h2><a href="<?= e(url('/groups/' . $group['slug'])) ?>"><?= e((string) $group['name']) ?></a></h2>
        <p class="small muted">
          <?php if ($group['meetsWhen'] !== null && $group['meetsWhen'] !== ''): ?><?= e((string) $group['meetsWhen']) ?><?php endif ?>
          <?php if ($group['area'] !== null && $group['area'] !== ''): ?> · <?= e((string) $group['area']) ?><?php endif ?>
          · <?= e(t('groups.members', ['count' => (string) $group['memberCount']])) ?>
        </p>
        <p class="small"><?= e(t('groups.join.' . strtolower((string) $group['joinState']))) ?></p>
        <?php if ($group['needsLeader']): ?><p class="small muted"><?= e(t('groups.needsLeader')) ?></p><?php endif ?>
      </li>
    <?php endforeach ?>
  </ul>
<?php endif ?>
