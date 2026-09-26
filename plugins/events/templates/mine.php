<?php
/**
 * /profile/events: what this member has signed up for.
 *
 * @var \App\Core\View $v
 * @var array $shell
 * @var list<array{href: string, label: string}> $sections
 * @var list<array{title: string, href: string, slug: string, when: string, waiting: bool, guests: int, past: bool}> $rows
 */
?>
<h1><?= e(t('events.mine')) ?></h1>
<?= $v->partial('partials/profile-nav', ['shell' => $shell, 'sections' => $sections]) ?>
<?php if ($rows === []): ?>
  <p class="muted"><?= e(t('events.mineEmpty')) ?></p>
<?php else: ?>
  <ul class="plain">
    <?php foreach ($rows as $row): ?>
      <li class="card">
        <a href="<?= e(url($row['href'])) ?>"><strong><?= e($row['title']) ?></strong></a>
        <?php if ($row['waiting']): ?><span class="badge muted"><?= e(t('events.waiting')) ?></span><?php endif ?>
        <?php if ($row['past']): ?><span class="badge muted"><?= e(t('events.past')) ?></span><?php endif ?>
        <p class="small muted"><?= e($row['when']) ?><?php if ($row['guests'] > 0): ?> · <?= e(t('events.withGuests', ['count' => (string) $row['guests']])) ?><?php endif ?></p>
        <?php if (!$row['past']): ?>
          <button class="button small danger" type="button" data-api="/api/events/<?= e($row['slug']) ?>/register" data-method="DELETE" data-confirm="<?= e(t('events.cancelConfirm')) ?>"><?= e(t('events.cancel')) ?></button>
        <?php endif ?>
      </li>
    <?php endforeach ?>
  </ul>
<?php endif ?>
