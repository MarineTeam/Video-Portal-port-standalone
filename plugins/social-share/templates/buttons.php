<?php
/**
 * @var \App\Core\View $v
 * @var string $link
 * @var string $title
 * @var bool $shareAt
 * @var string $x
 * @var string $facebook
 * @var string $script
 */
?>
<span class="share-buttons">
  <button type="button" class="button small" data-copy-link="<?= e($link) ?>" data-copied-label="<?= e(t('socialShare.copied')) ?>"><?= e(t('socialShare.copy')) ?></button>
  <button type="button" class="button small" data-native-share="<?= e($link) ?>" data-share-title="<?= e($title) ?>" hidden><?= e(t('socialShare.share')) ?></button>
  <a class="button small" href="<?= e($x) ?>" target="_blank" rel="noopener noreferrer"><?= e(t('socialShare.x')) ?></a>
  <a class="button small" href="<?= e($facebook) ?>" target="_blank" rel="noopener noreferrer"><?= e(t('socialShare.facebook')) ?></a>
</span>
<?php if ($shareAt): ?>
  <form class="share-at small" data-share-at="<?= e($link) ?>">
    <label for="share-t"><?= e(t('socialShare.at')) ?></label>
    <input id="share-t" name="t" size="6" placeholder="0:00" pattern="[0-9:hms]+" data-share-time>
    <button type="submit" class="button small"><?= e(t('socialShare.copy')) ?></button>
    <span class="small" data-share-done hidden><?= e(t('socialShare.copied')) ?></span>
  </form>
<?php endif ?>
<script type="module" src="<?= e($script) ?>"></script>
