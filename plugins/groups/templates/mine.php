<?php
/**
 * /profile/groups.
 *
 * @var \App\Core\View $v
 * @var array $shell
 * @var list<array{href: string, label: string}> $sections
 * @var list<array{name: string, slug: string, status: string, muted: bool}> $rows
 */
?>
<h1><?= e(t('groups.mine')) ?></h1>
<?= $v->partial('partials/profile-nav', ['shell' => $shell, 'sections' => $sections]) ?>
<?php if ($rows === []): ?>
  <p class="muted"><?= e(t('groups.mineEmpty')) ?></p>
<?php else: ?>
  <ul class="plain">
    <?php foreach ($rows as $row): ?>
      <li class="card row">
        <a href="<?= e(url('/groups/' . $row['slug'])) ?>"><strong><?= e($row['name']) ?></strong></a>
        <span class="badge<?= $row['status'] === 'ACTIVE' ? '' : ' muted' ?>"><?= e(t('groups.join.' . strtolower($row['status'] === 'ACTIVE' ? 'in' : ($row['status'] === 'REQUESTED' ? 'asked' : ($row['status'] === 'WAITLIST' ? 'waiting' : 'declined'))))) ?></span>
        <?php if ($row['muted']): ?><span class="badge muted"><?= e(t('groups.mute')) ?></span><?php endif ?>
      </li>
    <?php endforeach ?>
  </ul>
<?php endif ?>
