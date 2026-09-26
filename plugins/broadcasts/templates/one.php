<?php
/**
 * One broadcast, and how far it got. One bad address does not stop the rest,
 * so the failures are listed afterwards with the reason the provider gave.
 *
 * @var \App\Core\View $v
 * @var array<string, mixed> $broadcast
 * @var string $script
 */
$progress = $broadcast['progress'];
?>
<p><a href="<?= e(url('/admin/broadcasts')) ?>">← <?= e(t('broadcasts.title')) ?></a></p>
<h1><?= e((string) $broadcast['subject']) ?></h1>
<p class="small muted">
  <?= e((string) $broadcast['audienceLabel']) ?> ·
  <?= e(t('broadcasts.status.' . $broadcast['status'])) ?> ·
  <?= e((string) $broadcast['createdBy']) ?>
</p>

<section class="card" data-broadcast-one="<?= e((string) $broadcast['id']) ?>"
         data-labels="<?= e(\App\Core\View::json(['sending' => t('broadcasts.sending', ['done' => '{done}', 'total' => '{total}'])])) ?>">
  <p>
    <?= e(t('broadcasts.sent', ['count' => (string) $progress['sent']])) ?>
    <?php if ($progress['failed'] > 0): ?> · <?= e(t('broadcasts.failures')) ?>: <?= e((string) $progress['failed']) ?><?php endif ?>
    <?php if ($progress['skipped'] > 0): ?> · <?= e(t('broadcasts.stopped')) ?><?php endif ?>
    <?php if ($broadcast['delivered'] > 0): ?> · <?= e((string) $broadcast['delivered']) ?> <?= e(t('broadcasts.delivered')) ?><?php endif ?>
  </p>
  <?php if (!$progress['finished']): ?>
    <p><progress value="<?= e((string) $progress['done']) ?>" max="<?= e((string) $progress['total']) ?>"></progress></p>
    <div class="row">
      <button class="button primary" type="button" data-broadcast-resume><?= e(t('broadcasts.resume')) ?></button>
      <button class="button danger" type="button" data-api="/api/admin/broadcasts/<?= e((string) $broadcast['id']) ?>/cancel" data-method="POST" data-confirm="<?= e(t('broadcasts.stop')) ?>"><?= e(t('broadcasts.stop')) ?></button>
    </div>
  <?php endif ?>
  <p class="error" data-error hidden></p>
</section>

<section class="card">
  <h2><?= e(t('broadcasts.body')) ?></h2>
  <p><?= e((string) $broadcast['body']) ?></p>
</section>

<?php if ($broadcast['failures'] !== []): ?>
  <section class="card">
    <h2><?= e(t('broadcasts.failures')) ?></h2>
    <ul class="plain small">
      <?php foreach ($broadcast['failures'] as $failure): ?>
        <li>
          <strong><?= e((string) ($failure['name'] ?? $failure['address'])) ?></strong>
          <span class="muted"><?= e((string) $failure['channel']) ?></span>
          <?php if ($failure['error'] !== null): ?><span><?= e((string) $failure['error']) ?></span><?php endif ?>
        </li>
      <?php endforeach ?>
    </ul>
  </section>
<?php endif ?>
<script type="module" src="<?= e($script) ?>"></script>
