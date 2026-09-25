<?php
/**
 * @var \App\Core\View $v
 * @var list<array{name: string, note?: string, email?: string, phone?: string}> $members
 * @var string $q
 * @var string $standing where the viewer stands, in plain words
 */
?>
<h1><?= e(t('directory.title')) ?></h1>
<p class="muted"><?= e($standing) ?> <a href="<?= e(url('/profile/settings')) ?>"><?= e(t('directory.change')) ?></a></p>
<form method="get" action="<?= e(url('/directory')) ?>" class="row" role="search">
  <input aria-label="<?= e(t('directory.search')) ?>" type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('directory.search')) ?>" maxlength="100">
  <button class="button" type="submit"><?= e(t('directory.search')) ?></button>
</form>
<?php if ($members === []): ?>
  <p class="muted"><?= e($q === '' ? t('directory.empty') : t('directory.noMatch')) ?></p>
<?php else: ?>
  <ul class="plain directory">
    <?php foreach ($members as $m): ?>
      <li class="card">
        <strong><?= e($m['name']) ?></strong>
        <?php if (isset($m['note'])): ?><div class="small"><?= e($m['note']) ?></div><?php endif ?>
        <?php if (isset($m['email'])): ?><div class="small"><a href="mailto:<?= e($m['email']) ?>"><?= e($m['email']) ?></a></div><?php endif ?>
        <?php if (isset($m['phone'])): ?><div class="small"><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $m['phone'])) ?>"><?= e($m['phone']) ?></a></div><?php endif ?>
      </li>
    <?php endforeach ?>
  </ul>
<?php endif ?>
