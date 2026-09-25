<?php
/**
 * @var \App\Core\View $v
 * @var list<array<string, mixed>> $playlists newest change first
 */
?>
<h1><?= e(t('playlists.title')) ?></h1>
<form class="row card" data-api="/api/playlists" data-method="POST">
  <label><?= e(t('playlists.newTitle')) ?><input name="title" required maxlength="255"></label>
  <button class="button primary" type="submit"><?= e(t('playlists.create')) ?></button>
  <p class="error" data-error hidden></p>
</form>
<?php if ($playlists === []): ?>
  <p class="muted"><?= e(t('playlists.empty')) ?></p>
<?php else: ?>
  <ul class="plain">
    <?php foreach ($playlists as $p): ?>
      <li class="card row">
        <a href="<?= e(url('/playlists/' . $p['id'])) ?>"><strong><?= e($p['title']) ?></strong></a>
        <span class="small muted"><?= e(t((int) $p['itemCount'] === 1 ? 'playlists.oneVideo' : 'playlists.videos', ['count' => (string) $p['itemCount']])) ?></span>
        <?php if ($p['public']): ?><span class="badge muted"><?= e(t('playlists.shared')) ?></span><?php endif ?>
      </li>
    <?php endforeach ?>
  </ul>
<?php endif ?>
