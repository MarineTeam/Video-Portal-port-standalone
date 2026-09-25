<?php
/**
 * @var \App\Core\View $v
 * @var string $videoId
 */
?>
<button type="button" class="button small" data-download-video="<?= e($videoId) ?>" data-saved-label="<?= e(t('downloads.saved')) ?>">⬇ <?= e(t('downloads.button')) ?></button>
<p class="small error" data-download-status hidden></p>
<script type="module" src="<?= e(asset('js/downloads.js')) ?>"></script>
