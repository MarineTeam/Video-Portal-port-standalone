<?php
/**
 * @var \App\Core\View $v
 * @var array<string, mixed> $playlist
 * @var list<array<string, mixed>> $videos in order, only those this reader may open
 * @var bool $owner
 * @var string $link
 */
$api = '/api/playlists/' . $playlist['id'];
?>
<h1><?= e($playlist['title']) ?></h1>
<?php if ($owner): ?>
  <div class="video-actions">
    <details>
      <summary class="button small"><?= e(t('playlists.rename')) ?></summary>
      <form class="row" data-api="<?= e($api) ?>" data-method="PATCH">
        <input name="title" value="<?= e($playlist['title']) ?>" required maxlength="255" aria-label="<?= e(t('playlists.newTitle')) ?>">
        <button class="button small primary" type="submit"><?= e(t('playlists.save')) ?></button>
      </form>
    </details>
    <button type="button" class="button small" data-api="<?= e($api) ?>" data-method="PATCH" data-body="<?= e(\App\Core\View::json(['public' => !$playlist['public']])) ?>"><?= e($playlist['public'] ? t('playlists.makePrivate') : t('playlists.makeShareable')) ?></button>
    <?php if ($playlist['public']): ?><button type="button" class="button small" data-copy-link="<?= e($link) ?>" data-copied-label="<?= e(t('playlists.copied')) ?>"><?= e(t('playlists.copyLink')) ?></button><?php endif ?>
    <button type="button" class="button small danger" data-api="<?= e($api) ?>" data-method="DELETE" data-redirect="/playlists" data-confirm="<?= e(t('playlists.confirmDelete')) ?>"><?= e(t('playlists.delete')) ?></button>
  </div>
  <p class="small muted"><?= e($playlist['public'] ? t('playlists.sharedNote') : t('playlists.privateNote')) ?></p>
<?php endif ?>
<?php if ($videos === []): ?>
  <p class="muted"><?= e(t('playlists.emptyList')) ?></p>
<?php else: ?>
  <ol class="plain">
    <?php foreach ($videos as $video): ?>
      <li class="row">
        <div style="flex: 1"><?= $v->partial('partials/library-videos', ['videos' => [$video]]) ?></div>
        <?php if ($owner): ?>
          <button type="button" class="button small" aria-label="<?= e(t('playlists.up')) ?>" data-api="<?= e($api) ?>/items" data-method="PATCH" data-body="<?= e(\App\Core\View::json(['videoId' => $video['id'], 'move' => 'up'])) ?>">↑</button>
          <button type="button" class="button small" aria-label="<?= e(t('playlists.down')) ?>" data-api="<?= e($api) ?>/items" data-method="PATCH" data-body="<?= e(\App\Core\View::json(['videoId' => $video['id'], 'move' => 'down'])) ?>">↓</button>
          <button type="button" class="button small" data-api="<?= e($api) ?>/items?videoId=<?= e(rawurlencode((string) $video['id'])) ?>" data-method="DELETE"><?= e(t('playlists.remove')) ?></button>
        <?php endif ?>
      </li>
    <?php endforeach ?>
  </ol>
<?php endif ?>
