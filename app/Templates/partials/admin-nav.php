<?php
/**
 * @var \App\Core\View $v
 * @var array $shell
 */
use App\Modules\Site\Shell;
?>
<nav class="admin-nav" aria-label="Admin">
<?php foreach ($shell['adminNav'] as $group): ?>
  <h2><?= e($group['label']) ?></h2>
  <?php foreach ($group['links'] as $link): ?>
    <?php $active = Shell::isActivePath($link['href'], $shell['path'], $link['href'] === '/admin'); ?>
    <a href="<?= e(url($link['href'])) ?>" class="<?= $active ? 'active' : '' ?>"<?= $active ? ' aria-current="page"' : '' ?>><?= e($link['label']) ?></a>
  <?php endforeach ?>
<?php endforeach ?>
</nav>
