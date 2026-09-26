<?php
/**
 * /services.
 *
 * @var \App\Core\View $v
 * @var list<array{id: string, title: string, date: ?string, published: bool, items: int}> $plans
 */
?>
<h1><?= e(t('services.title')) ?></h1>
<?php if ($plans === []): ?>
  <p class="muted"><?= e(t('services.none')) ?></p>
<?php else: ?>
  <ul class="plain">
    <?php foreach ($plans as $plan): ?>
      <li class="card">
        <h2><a href="<?= e(url('/services/' . $plan['id'])) ?>"><?= e($plan['title']) ?></a>
          <?php if (!$plan['published']): ?><span class="badge muted">draft</span><?php endif ?>
        </h2>
        <p class="small muted"><?php if ($plan['date'] !== null): ?><time datetime="<?= e($plan['date']) ?>" data-local-date><?= e($plan['date']) ?></time> · <?php endif ?><?= e(t('services.itemCount', ['count' => (string) $plan['items']])) ?></p>
      </li>
    <?php endforeach ?>
  </ul>
<?php endif ?>
