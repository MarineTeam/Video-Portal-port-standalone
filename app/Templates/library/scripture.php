<?php
/**
 * @var list<array{book: string, count: int}> $books
 */
?>
<h1><?= e(t('library.scripture')) ?></h1>
<?php if ($books === []): ?>
  <p class="muted"><?= e(t('library.empty')) ?></p>
<?php else: ?>
  <ul class="chips plain">
    <?php foreach ($books as $b): ?>
      <li><a class="chip" href="<?= e(url('/scripture/' . rawurlencode($b['book']))) ?>"><?= e($b['book']) ?> <span class="muted">(<?= e((string) $b['count']) ?>)</span></a></li>
    <?php endforeach ?>
  </ul>
<?php endif ?>
