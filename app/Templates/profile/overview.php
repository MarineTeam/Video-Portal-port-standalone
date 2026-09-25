<?php
/**
 * @var \App\Core\View $v
 * @var array $shell
 * @var list<array{href: string, label: string, badge?: int}> $sections
 * @var int $unread
 * @var list<array{title: string, body?: string, href?: string, count?: int}> $cards
 * @var string $name
 */
?>
<h1><?= e(t('profile.hello', ['name' => $name])) ?></h1>
<?= $v->partial('partials/profile-nav', ['shell' => $shell, 'sections' => $sections]) ?>
<div class="card-grid">
  <a class="card link-card" href="<?= e(url('/profile/inbox')) ?>">
    <h2><?= e(t('profile.inbox')) ?></h2>
    <p><?= e($unread > 0 ? t('profile.unread', ['count' => $unread]) : t('profile.allRead')) ?></p>
  </a>
  <?php foreach ($cards as $card): ?>
    <?php if (isset($card['href'])): ?>
      <a class="card link-card" href="<?= e(url($card['href'])) ?>">
    <?php else: ?>
      <div class="card">
    <?php endif ?>
      <h2><?= e($card['title']) ?><?php if (isset($card['count'])): ?> <span class="badge"><?= e((string) $card['count']) ?></span><?php endif ?></h2>
      <?php if (isset($card['body'])): ?><p><?= e($card['body']) ?></p><?php endif ?>
    <?php if (isset($card['href'])): ?></a><?php else: ?></div><?php endif ?>
  <?php endforeach ?>
</div>
