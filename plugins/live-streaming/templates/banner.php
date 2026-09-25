<?php
/**
 * "Live now", across the top of every page but /live itself.
 *
 * @var \App\Core\View $v
 * @var string $title
 */
?>
<p class="notice live-banner" role="status">
  <span class="badge live-dot"><?= e(t('live.now')) ?></span>
  <a href="<?= e(url('/live')) ?>"><?= e($title) ?></a>
</p>
