<?php
/**
 * @var \App\Core\View $v
 * @var string $videoId
 * @var bool $cast whether this video can be sent to a television from here
 */
?>
<button type="button" class="button small" data-download-video="<?= e($videoId) ?>" data-saved-label="<?= e(t('downloads.saved')) ?>">⬇ <?= e(t('downloads.button')) ?></button>
<p class="small error" data-download-status hidden></p>
<?php if ($cast): ?>
  <button type="button" class="button small" data-cast-video="<?= e($videoId) ?>" hidden>▶ <?= e(t('cast.button')) ?></button>
  <p class="small error" data-cast-status hidden></p>
  <script type="module" src="<?= e(asset('js/cast.js')) ?>"></script>
<?php endif ?>
<script type="module" src="<?= e(asset('js/downloads.js')) ?>"></script>
