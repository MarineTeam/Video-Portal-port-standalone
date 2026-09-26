<?php
/**
 * One guide. A member's shape has no leaderNotes on it at all, so this
 * page cannot print an answer it was never given.
 *
 * @var \App\Core\View $v
 * @var array<string, mixed> $guide
 */
$labels = ['QUESTION' => t('guides.question'), 'SCRIPTURE' => t('guides.scripture'), 'NOTE' => t('guides.note')];
?>
<p><a href="<?= e(url('/guides')) ?>">← <?= e(t('guides.title')) ?></a></p>
<h1><?= e((string) $guide['title']) ?></h1>
<?php if ($guide['description'] !== null && $guide['description'] !== ''): ?><p class="muted"><?= e((string) $guide['description']) ?></p><?php endif ?>
<ol class="plain">
  <?php foreach ($guide['items'] as $item): ?>
    <li class="card">
      <p class="small muted"><?= e($labels[$item['kind']] ?? $item['kind']) ?><?php if ($item['reference'] !== null && $item['reference'] !== ''): ?> · <?= e((string) $item['reference']) ?><?php endif ?></p>
      <p><?= e((string) $item['body']) ?></p>
    </li>
  <?php endforeach ?>
</ol>
<?php if (isset($guide['leaderNotes'])): ?>
  <section class="card">
    <h2><?= e(t('guides.leaderNotes')) ?></h2>
    <?php foreach ($guide['leaderNotes'] as $note): ?><p><?= e((string) $note['body']) ?></p><?php endforeach ?>
  </section>
<?php endif ?>
