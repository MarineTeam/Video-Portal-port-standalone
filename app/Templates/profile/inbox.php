<?php
/**
 * @var \App\Core\View $v
 * @var array $shell
 * @var list<array{href: string, label: string, badge?: int}> $sections
 * @var list<array{id: string, title: string, body: string, url: ?string, readAt: ?string, createdAt: string}> $notifications
 * @var bool $hasMore
 * @var int $unread
 * @var string $pushPanel
 */
?>
<h1><?= e(t('profile.inbox')) ?></h1>
<?= $v->partial('partials/profile-nav', ['shell' => $shell, 'sections' => $sections]) ?>
<?= $v->raw($pushPanel) ?>
<?php if ($notifications === []): ?>
  <p class="muted"><?= e(t('inbox.empty')) ?></p>
<?php else: ?>
  <div class="row">
    <?php if ($unread > 0): ?>
      <button class="button small" type="button" data-api="/api/inbox" data-method="PATCH" data-body='{"all":true}'><?= e(t('inbox.markAllRead')) ?></button>
    <?php endif ?>
    <button class="button small danger" type="button" data-api="/api/inbox" data-method="DELETE" data-body='{"all":true}' data-confirm="<?= e(t('inbox.clearConfirm')) ?>"><?= e(t('inbox.clearAll')) ?></button>
  </div>
  <ul class="inbox">
    <?php foreach ($notifications as $n): ?>
      <li class="<?= $n['readAt'] === null ? 'unread' : '' ?>">
        <div class="inbox-text">
          <strong><?= e($n['title']) ?></strong><?php if ($n['readAt'] === null): ?> <span class="badge"><?= e(t('inbox.new')) ?></span><?php endif ?>
          <p><?= e($n['body']) ?></p>
          <time class="small muted" datetime="<?= e($n['createdAt']) ?>"><?= e(substr($n['createdAt'], 0, 10)) ?></time>
        </div>
        <div class="inbox-actions">
          <?php if ($n['url'] !== null): ?>
            <a class="button small" href="<?= e(str_starts_with($n['url'], '/') ? url($n['url']) : $n['url']) ?>" data-inbox-open="<?= e($n['id']) ?>"><?= e(t('inbox.open')) ?></a>
          <?php endif ?>
          <?php if ($n['readAt'] === null): ?>
            <button class="button small" type="button" data-api="/api/inbox" data-method="PATCH" data-body="<?= e(json_encode(['ids' => [$n['id']]])) ?>"><?= e(t('inbox.markRead')) ?></button>
          <?php endif ?>
          <button class="button small danger" type="button" data-api="/api/inbox" data-method="DELETE" data-body="<?= e(json_encode(['ids' => [$n['id']]])) ?>"><?= e(t('common.delete')) ?></button>
        </div>
      </li>
    <?php endforeach ?>
  </ul>
  <?php if ($hasMore): $last = $notifications[count($notifications) - 1]; ?>
    <p><a class="button" href="<?= e(url('/profile/inbox', ['before' => $last['id']])) ?>"><?= e(t('inbox.older')) ?></a></p>
  <?php endif ?>
<?php endif ?>
<script type="module" src="<?= e(asset('js/profile.js')) ?>"></script>
