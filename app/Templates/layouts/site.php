<?php
/**
 * The site shell: header, sidebar, content, and the bottom tab bar the
 * installed app navigates by.
 *
 * @var \App\Core\View $v
 * @var array $shell
 * @var string $content
 */
use App\Modules\Site\Icons;
use App\Modules\Site\Shell;

$b = $shell['branding'];
$user = $shell['user'];
$tabs = $shell['tabs'];
?>
<!doctype html>
<html lang="<?= e($shell['locale']) ?>" class="<?= e($shell['themeClasses']) ?>">
<head>
<?= $v->partial('partials/head', get_defined_vars()) ?>
</head>
<body class="site" data-base="<?= e(url('/')) ?>">
<a class="skip" href="#main"><?= e(t('nav.skip')) ?></a>
<?= $v->partial('partials/notices', ['shell' => $shell]) ?>
<header class="topbar">
  <a class="brand" href="<?= e(url('/')) ?>">
    <?php if ($b['logoUrl'] !== null): ?><img src="<?= e($b['logoUrl']) ?>" alt="" class="brand-logo"><?php endif ?>
    <span><?= e($b['name']) ?></span>
  </a>
  <form class="topbar-search" action="<?= e(url('/search')) ?>" method="get" role="search">
    <input type="search" name="q" placeholder="<?= e(t('nav.search')) ?>" aria-label="<?= e(t('nav.search')) ?>" maxlength="100">
  </form>
  <nav class="topbar-actions" aria-label="<?= e(t('nav.menu')) ?>">
    <?php if ($user !== null && $user['isStaff']): ?>
      <a href="<?= e(url('/admin')) ?>"><?= e(t('nav.admin')) ?></a>
    <?php endif ?>
    <?php if ($user !== null): ?>
      <a href="<?= e(url('/profile')) ?>" class="avatar" title="<?= e($user['name']) ?>"><?= e(mb_strtoupper(mb_substr($user['name'], 0, 1))) ?></a>
    <?php else: ?>
      <a class="button small" href="<?= e(url('/auth/login', ['returnTo' => url($shell['path'])])) ?>"><?= e(t('nav.signIn')) ?></a>
    <?php endif ?>
  </nav>
</header>
<div class="frame">
  <nav class="sidebar" aria-label="<?= e(t('nav.primary')) ?>">
    <?php foreach ($shell['nav'] as $item): ?>
      <a href="<?= e(url($item['href'])) ?>" class="<?= Shell::isActivePath($item['href'], $shell['path']) ? 'active' : '' ?>"<?= $v->raw(Shell::isActivePath($item['href'], $shell['path']) ? ' aria-current="page"' : '') ?>>
        <?= $v->raw(Icons::svg($item['icon'] ?? 'folder')) ?><span><?= e($item['label']) ?></span>
      </a>
    <?php endforeach ?>
    <?php if ($user !== null): ?>
      <form method="post" action="<?= e(url('/auth/logout')) ?>" class="sidebar-signout">
        <?= $v->raw(csrf_field()) ?>
        <button type="submit" class="link"><?= e(t('nav.signOut')) ?></button>
      </form>
    <?php endif ?>
  </nav>
  <main id="main" class="content">
    <?= $v->raw($shell['pageTop'] ?? '') ?>
    <?= $v->raw($content) ?>
  </main>
</div>
<nav class="tabbar<?= count($tabs) > 5 ? ' scrolls' : '' ?>" aria-label="<?= e(t('nav.primary')) ?>" data-tabbar data-tabs="<?= e(\App\Core\View::json(array_map(fn ($t) => ['href' => $t['href'], 'label' => $t['label'], 'icon' => $t['icon'] ?? 'folder'], $tabs))) ?>">
  <?php foreach ($tabs as $tab): ?>
    <?= $v->partial('partials/tab', ['tab' => $tab, 'path' => $shell['path']]) ?>
  <?php endforeach ?>
</nav>
<?php /* Every destination this viewer may choose for the bar, for a per-device choice to draw from. */ ?>
<template data-tab-options>
  <?php foreach ($shell['tabOptions'] as $tab): ?>
    <?= $v->partial('partials/tab', ['tab' => $tab, 'path' => $shell['path']]) ?>
  <?php endforeach ?>
</template>
<script type="module" src="<?= e(asset('js/app.js')) ?>"></script>
<?php foreach ($shell['theme']['js'] as $src): ?>
<script type="module" src="<?= e($src) ?>"></script>
<?php endforeach ?>
<?= $v->raw($shell['bodyEnd']) ?>
</body>
</html>
