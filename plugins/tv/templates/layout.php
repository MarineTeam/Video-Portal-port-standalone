<?php
/**
 * The television's own page.
 *
 * It covers the app chrome entirely rather than restructuring the site
 * layout: a remote cannot use a sidebar, and on a television it would eat a
 * fifth of the screen. Everything here is sized for somebody across a room,
 * with margins that survive a set's overscan.
 *
 * @var \App\Core\View $v
 * @var array $shell
 * @var string $content
 */
$b = $shell['branding'];
$noindex = true;
?>
<!doctype html>
<html lang="<?= e($shell['locale']) ?>" class="<?= e($shell['themeClasses']) ?>">
<head>
<?= $v->partial('partials/head', get_defined_vars()) ?>
</head>
<body class="tv">
<main class="tv-safe" id="main">
  <?= $v->raw($content) ?>
</main>
</body>
</html>
