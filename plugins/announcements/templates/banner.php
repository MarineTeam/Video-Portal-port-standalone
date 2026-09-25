<?php
/**
 * @var \App\Core\View $v
 * @var array{id: string, message: string} $banner
 * @var string $script
 */
?>
<div class="notice announcement" role="status" data-announcement="<?= e($banner['id']) ?>">
  <p><?= e($banner['message']) ?></p>
  <button type="button" class="link" data-announcement-dismiss aria-label="<?= e(t('announcements.dismiss')) ?>">✕</button>
</div>
<script type="module" src="<?= e($script) ?>"></script>
