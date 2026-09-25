<?php
/**
 * @var \App\Core\View $v
 * @var array $branding
 * @var list<array{name: string, slug: string}> $categories
 */
?>
<h1><?= e(t('home.welcome', ['name' => $branding['name']])) ?></h1>
<?php if ($categories === []): ?>
  <p class="muted"><?= e(t('home.empty')) ?></p>
<?php else: ?>
  <ul class="tiles">
  <?php foreach ($categories as $c): ?>
    <li><a class="tile" href="<?= e(url('/categories/' . $c['slug'])) ?>"><span class="tile-title"><?= e($c['name']) ?></span></a></li>
  <?php endforeach ?>
  </ul>
<?php endif ?>
