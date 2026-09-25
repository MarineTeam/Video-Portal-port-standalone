<?php
/**
 * @var \App\Core\View $v
 * @var string $videoId
 * @var string $script
 */
?>
<details class="playlist-add" data-playlist-add="<?= e($videoId) ?>">
  <summary class="button small">＋ <?= e(t('playlists.addTo')) ?></summary>
  <div class="card stack">
    <div data-playlist-choices data-empty="<?= e(t('playlists.noneYet')) ?>"><p class="small muted"><?= e(t('playlists.loading')) ?></p></div>
    <form class="row" data-playlist-new>
      <input name="title" required maxlength="255" placeholder="<?= e(t('playlists.newTitle')) ?>" aria-label="<?= e(t('playlists.newTitle')) ?>">
      <button class="button small" type="submit"><?= e(t('playlists.create')) ?></button>
    </form>
    <p class="error small" data-error hidden></p>
  </div>
</details>
<script type="module" src="<?= e($script) ?>"></script>
