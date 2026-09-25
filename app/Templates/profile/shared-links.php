<?php
/**
 * @var \App\Core\View $v
 * @var array $shell
 * @var list<array{href: string, label: string, badge?: int}> $sections
 * @var list<array<string, mixed>> $links
 */
?>
<h1><?= e(t('share.mine')) ?></h1>
<?= $v->partial('partials/profile-nav', ['shell' => $shell, 'sections' => $sections]) ?>
<?php if ($links === []): ?>
  <p class="muted"><?= e(t('share.none')) ?></p>
<?php else: ?>
  <?= $v->partial('partials/share-link-rows', ['links' => $links, 'api' => '/api/share-links', 'showOwner' => false, 'showContent' => true]) ?>
<?php endif ?>
