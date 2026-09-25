<?php
/**
 * @var \App\Core\View $v
 * @var array<string, mixed> $speaker
 * @var list<array<string, mixed>> $videos
 */
?>
<p class="crumbs small"><a href="<?= e(url('/speakers')) ?>"><?= e(t('library.speakers')) ?></a></p>
<header class="series-head">
  <?php if (!empty($speaker['photo_url'])): ?><img class="series-cover avatar-cover" src="<?= e((string) $speaker['photo_url']) ?>" alt=""><?php endif ?>
  <div>
    <h1><?= e($speaker['name']) ?></h1>
    <?php if (!empty($speaker['bio'])): ?><div class="prose"><?= $v->raw(nl2br(e((string) $speaker['bio']))) ?></div><?php endif ?>
  </div>
</header>
<?php if ($videos === []): ?>
  <p class="muted"><?= e(t('library.empty')) ?></p>
<?php else: ?>
  <?= $v->partial('partials/library-videos', ['videos' => $videos]) ?>
<?php endif ?>
