<?php
/**
 * One request, as this reader is given it.
 *
 * @var \App\Core\View $v
 * @var array<string, mixed> $r
 * @var bool $moderator
 */
?>
<li class="card" data-prayer="<?= e((string) $r['id']) ?>">
  <?php if ($r['status'] !== 'APPROVED'): ?><span class="badge muted"><?= e(t('prayer.status.' . strtolower((string) $r['status']))) ?></span><?php endif ?>
  <p><?= e((string) $r['body']) ?></p>
  <p class="small muted">
    <?= e($r['author'] !== null ? (string) $r['author'] : t('prayer.anonymous')) ?>
    · <time datetime="<?= e((string) $r['createdAt']) ?>" data-local-date><?= e(substr((string) $r['createdAt'], 0, 10)) ?></time>
  </p>
  <?php if ($r['answeredNote'] !== null && $r['answeredNote'] !== ''): ?>
    <p class="notice ok"><strong><?= e(t('prayer.answered')) ?></strong> <?= e((string) $r['answeredNote']) ?></p>
  <?php endif ?>
  <p class="small">
    <?php if ($r['canPray']): ?>
      <button type="button" class="button small" data-prayer-pray aria-pressed="<?= e($r['prayed'] ? 'true' : 'false') ?>"<?= $r['prayed'] ? ' disabled' : '' ?>><?= e($r['prayed'] ? t('prayer.prayed') : t('prayer.pray')) ?></button>
    <?php endif ?>
    <span data-prayer-count><?= e(t('prayer.count', ['count' => (string) $r['prayers']])) ?></span>
    <?php if ($r['canDelete']): ?><button type="button" class="link" data-prayer-delete><?= e(t('prayer.delete')) ?></button><?php endif ?>
  </p>
</li>
