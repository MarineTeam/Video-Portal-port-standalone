<?php
/**
 * One destination in the bottom bar.
 *
 * @var \App\Core\View $v
 * @var array{href: string, label: string, icon?: string, badge?: int} $tab
 * @var string $path
 */
use App\Modules\Site\Icons;
use App\Modules\Site\Shell;

$on = Shell::isActivePath($tab['href'], $path);
?>
<a href="<?= e(url($tab['href'])) ?>" data-href="<?= e($tab['href']) ?>" data-icon="<?= e($tab['icon'] ?? 'folder') ?>" class="<?= $on ? 'active' : '' ?>"<?= $v->raw($on ? ' aria-current="page"' : '') ?>>
  <?= $v->raw(Icons::svg($tab['icon'] ?? 'folder')) ?><span><?= e($tab['label']) ?></span><?php if (($tab['badge'] ?? 0) > 0): ?><b class="tab-badge" aria-label="<?= e((string) $tab['badge']) ?>"><?= e($tab['badge'] > 99 ? '99+' : (string) $tab['badge']) ?></b><?php endif ?>
</a>
