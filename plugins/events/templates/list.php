<?php
/**
 * /events: what's on.
 *
 * @var \App\Core\View $v
 * @var list<array<string, mixed>> $events
 * @var string $feed
 */
?>
<h1><?= e(t('events.title')) ?></h1>
<p class="muted"><?= e(t('events.intro')) ?> <a href="<?= e($feed) ?>"><?= e(t('events.subscribe')) ?></a></p>
<?php if ($events === []): ?>
  <p class="muted"><?= e(t('events.none')) ?></p>
<?php else: ?>
  <ul class="plain">
    <?php foreach ($events as $event): ?>
      <li class="card">
        <h2><a href="<?= e(url('/events/' . $event['slug'])) ?>"><?= e((string) $event['title']) ?></a>
          <?php if ($event['memberOnly']): ?><span class="badge muted"><?= e(t('events.membersOnly')) ?></span><?php endif ?>
        </h2>
        <p class="small muted"><?= e((string) $event['when']) ?><?php if ($event['location'] !== null && $event['location'] !== ''): ?> · <?= e((string) $event['location']) ?><?php endif ?></p>
        <?php if ($event['state'] !== 'NONE'): ?>
          <p class="small"><?= e(t('events.state.' . strtolower((string) $event['state']))) ?><?php if ($event['placesMessage'] !== ''): ?> · <?= e((string) $event['placesMessage']) ?><?php endif ?></p>
        <?php endif ?>
      </li>
    <?php endforeach ?>
  </ul>
<?php endif ?>
