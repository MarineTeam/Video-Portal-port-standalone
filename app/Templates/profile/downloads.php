<?php
/**
 * @var \App\Core\View $v
 * @var array $shell
 * @var list<array{href: string, label: string, badge?: int}> $sections
 * @var bool $enabled
 * @var bool $audienceOk
 * @var string $platform
 * @var int $maxDeviceGb
 */
?>
<h1><?= e(t('downloads.title')) ?></h1>
<?= $v->partial('partials/profile-nav', ['shell' => $shell, 'sections' => $sections]) ?>
<?php if (!$enabled): ?>
  <p class="notice"><?= e(t('downloads.off')) ?></p>
<?php elseif (!$audienceOk): ?>
  <p class="notice"><?= e(t('downloads.notForYou')) ?></p>
<?php else: ?>
  <p class="small muted"><?= e(t('downloads.where.' . $platform)) ?></p>
<?php endif ?>
<div class="card stack narrow">
  <label><?= e(t('downloads.network')) ?>
    <select data-download-network>
      <option value="wifi"><?= e(t('downloads.wifiOnly')) ?></option>
      <option value="any"><?= e(t('downloads.anyNetwork')) ?></option>
    </select>
  </label>
  <p class="small muted" data-downloads-usage data-cap-gb="<?= e((string) $maxDeviceGb) ?>"></p>
</div>
<h2><?= e(t('downloads.onThisDevice')) ?></h2>
<p class="muted" data-downloads-empty><?= e(t('downloads.none')) ?></p>
<ul class="file-list" data-downloads-list data-play-label="<?= e(t('downloads.play')) ?>" data-remove-label="<?= e(t('downloads.remove')) ?>"></ul>
<script type="module" src="<?= e(asset('js/downloads.js')) ?>"></script>
