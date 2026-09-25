<?php
/**
 * @var \App\Core\View $v
 * @var list<array<string, mixed>> $videos most recently played first
 */
?>
<h1><?= e(t('history.title')) ?></h1>
<?php if ($videos === []): ?>
  <p class="muted"><?= e(t('history.empty')) ?></p>
<?php else: ?>
  <?= $v->partial('partials/library-videos', ['videos' => $videos]) ?>
<?php endif ?>
