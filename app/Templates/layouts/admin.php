<?php
/**
 * The admin shell: grouped sidebar of the sections this person can use.
 *
 * @var \App\Core\View $v
 * @var array $shell
 * @var string $content
 */
use App\Modules\Admin\AdminNav;

$b = $shell['branding'];
$noindex = true;
$current = AdminNav::currentLabel($shell['path']);
?>
<!doctype html>
<html lang="en" class="<?= e($shell['themeClasses']) ?>">
<head>
<?= $v->partial('partials/head', get_defined_vars()) ?>
</head>
<body class="admin">
<a class="skip" href="#main">Skip to content</a>
<?= $v->partial('partials/notices', ['shell' => $shell]) ?>
<header class="topbar">
  <a class="brand" href="<?= e(url('/')) ?>"><span><?= e($b['name']) ?></span></a>
  <details class="admin-switcher">
    <summary><?= e($current) ?></summary>
    <?= $v->partial('partials/admin-nav', ['shell' => $shell]) ?>
  </details>
  <nav class="topbar-actions">
    <a href="<?= e(url('/')) ?>">View site</a>
    <form method="post" action="<?= e(url('/auth/logout')) ?>" class="inline">
      <?= $v->raw(csrf_field()) ?><button class="link" type="submit">Sign out</button>
    </form>
  </nav>
</header>
<div class="frame">
  <aside class="sidebar admin-sidebar"><?= $v->partial('partials/admin-nav', ['shell' => $shell]) ?></aside>
  <main id="main" class="content">
    <?= $v->raw($content) ?>
  </main>
</div>
<script type="module" src="<?= e(asset('js/app.js')) ?>"></script>
<script type="module" src="<?= e(asset('js/admin.js')) ?>"></script>
<?= $v->raw($shell['bodyEnd']) ?>
</body>
</html>
