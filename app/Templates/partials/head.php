<?php
/**
 * The <head> every layout shares.
 *
 * @var \App\Core\View $v
 * @var array $shell
 * @var string|null $title
 */
$b = $shell['branding'];
$pageTitle = isset($title) && $title !== '' && $title !== $b['name'] ? $title . ' — ' . $b['name'] : $b['name'];
?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($pageTitle) ?></title>
<meta name="csrf-token" content="<?= e($shell['csrf']) ?>">
<meta name="base-path" content="<?= e(url('/')) ?>">
<meta name="theme-color" content="<?= e($b['brandDeep']) ?>">
<?php if (!empty($noindex)): ?><meta name="robots" content="noindex"><?php endif ?>
<?php if (!empty($description)): ?><meta name="description" content="<?= e($description) ?>"><?php endif ?>
<?php foreach (($meta ?? []) as $property => $content): ?>
<meta property="<?= e($property) ?>" content="<?= e($content) ?>">
<?php endforeach ?>
<link rel="manifest" href="<?= e(url('/api/manifest')) ?>">
<link rel="icon" href="<?= e(url('/icon.svg')) ?>">
<link rel="apple-touch-icon" href="<?= e(url('/icon-192.png')) ?>">
<script nonce="<?= e($shell['nonce']) ?>"><?= $v->raw(\App\Modules\Site\Shell::THEME_INIT_SCRIPT) ?></script>
<style nonce="<?= e($shell['nonce']) ?>"><?= $v->raw($shell['brandingCss']) ?></style>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<?php foreach ($shell['theme']['css'] as $href): ?>
<link rel="stylesheet" href="<?= e($href) ?>">
<?php endforeach ?>
<?php foreach (($jsonLd ?? []) as $block): ?>
<script type="application/ld+json" nonce="<?= e($shell['nonce']) ?>"><?= $v->raw(\App\Core\View::json($block)) ?></script>
<?php endforeach ?>
<?= $v->raw($shell['head']) ?>
