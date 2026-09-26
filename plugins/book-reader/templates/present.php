<?php
/**
 * The words, big. The copyright line stays up with them, because that is
 * what a projector is required to show.
 *
 * @var \App\Core\View $v
 * @var string $heading
 * @var list<string> $verses
 * @var ?string $copyright
 * @var ?string $ccli
 * @var array{id: string, title: string}|null $plan
 * @var string $script
 * @var string $opened
 * @var string $openedScript
 */
?>
<section class="present" data-present>
  <p class="small muted">
    <?= e($heading) ?>
    <?php if ($plan !== null): ?> · <a href="<?= e(url('/services/' . $plan['id'])) ?>"><?= e($plan['title']) ?></a><?php endif ?>
  </p>
  <div data-verses>
    <?php foreach ($verses as $i => $verse): ?>
      <p class="present-verse"<?= e($i === 0 ? '' : ' hidden') ?>><?= e($verse) ?></p>
    <?php endforeach ?>
  </div>
  <p class="row">
    <button type="button" class="button" data-present-prev>←</button>
    <span data-present-count><?= e('1 / ' . count($verses)) ?></span>
    <button type="button" class="button" data-present-next>→</button>
  </p>
  <?php if ($copyright !== null || $ccli !== null): ?>
    <p class="small muted present-credit"><?= e((string) $copyright) ?><?php if ($ccli !== null): ?> · CCLI <?= e($ccli) ?><?php endif ?></p>
  <?php endif ?>
</section>
<div hidden data-opened="<?= e($opened) ?>"></div>
<script type="module" src="<?= e($script) ?>"></script>
<script type="module" src="<?= e($openedScript) ?>"></script>
