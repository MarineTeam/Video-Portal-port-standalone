<?php
/**
 * The profile's own section links, above every profile page.
 *
 * @var \App\Core\View $v
 * @var array $shell
 * @var list<array{href: string, label: string, badge?: int}> $sections
 */
use App\Modules\Site\Shell;
?>
<nav class="subnav" aria-label="<?= e(t('profile.title')) ?>">
  <?php foreach ($sections as $s): $on = Shell::isActivePath($s['href'], $shell['path'], $s['href'] === '/profile'); ?>
    <a href="<?= e(url($s['href'])) ?>" class="<?= $on ? 'active' : '' ?>"<?= $v->raw($on ? ' aria-current="page"' : '') ?>>
      <?= e($s['label']) ?><?php if (($s['badge'] ?? 0) > 0): ?> <span class="badge" data-inbox-badge><?= e((string) $s['badge']) ?></span><?php endif ?>
    </a>
  <?php endforeach ?>
</nav>
