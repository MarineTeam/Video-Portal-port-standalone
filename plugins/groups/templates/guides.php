<?php
/**
 * /guides: what a group can work through.
 *
 * @var \App\Core\View $v
 * @var list<array<string, mixed>> $guides
 */
?>
<h1><?= e(t('guides.title')) ?></h1>
<?php if ($guides === []): ?>
  <p class="muted"><?= e(t('guides.none')) ?></p>
<?php else: ?>
  <ul class="plain">
    <?php foreach ($guides as $guide): ?>
      <li class="card">
        <h2><a href="<?= e(url('/guides/' . $guide['slug'])) ?>"><?= e((string) $guide['title']) ?></a>
          <?php if (!$guide['published']): ?><span class="badge muted">draft</span><?php endif ?>
        </h2>
        <p class="small muted"><?= e((string) $guide['summary']) ?><?php if ($guide['description'] !== null && $guide['description'] !== ''): ?> · <?= e((string) $guide['description']) ?><?php endif ?></p>
      </li>
    <?php endforeach ?>
  </ul>
<?php endif ?>
