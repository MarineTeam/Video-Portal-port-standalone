<?php
/**
 * @var \App\Core\View $v
 * @var array<string, mixed> $next
 * @var string $href
 * @var int $duration the current video's when its player reports no position, else 0
 * @var string $script
 */
?>
<section class="card up-next" data-up-next="<?= e($href) ?>" data-duration="<?= e((string) $duration) ?>" aria-labelledby="up-next-h">
  <div class="row">
    <h2 id="up-next-h" class="small"><?= e(t('upNext.title')) ?></h2>
    <label class="check small"><input type="checkbox" data-up-next-autoplay> <?= e(t('upNext.autoplay')) ?></label>
  </div>
  <?= $v->partial('partials/library-videos', ['videos' => [$next]]) ?>
  <p class="small" data-up-next-countdown hidden>
    <span data-up-next-text data-template="<?= e(t('upNext.countdown', ['seconds' => '{seconds}'])) ?>"></span>
    <button type="button" class="button small" data-up-next-cancel><?= e(t('upNext.cancel')) ?></button>
  </p>
</section>
<script type="module" src="<?= e($script) ?>"></script>
