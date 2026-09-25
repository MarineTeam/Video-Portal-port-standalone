<?php
/**
 * @var \App\Core\View $v
 * @var array $shell
 * @var string $content
 */
$b = $shell['branding'];
$noindex = true;
?>
<!doctype html>
<html lang="<?= e($shell['locale']) ?>">
<head>
<?= $v->partial('partials/head', get_defined_vars()) ?>
</head>
<body class="auth">
<main class="auth-card" id="main">
  <a class="brand" href="<?= e(url('/')) ?>">
    <?php if ($b['logoUrl'] !== null): ?><img src="<?= e($b['logoUrl']) ?>" alt="" class="brand-logo"><?php endif ?>
    <span><?= e($b['name']) ?></span>
  </a>
  <?= $v->raw($content) ?>
</main>
<script type="module" src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
