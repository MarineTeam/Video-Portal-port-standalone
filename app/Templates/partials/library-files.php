<?php
/**
 * Files with an open and a download link, both through /api/files/[id]/content.
 *
 * @var list<array<string, mixed>> $files
 */
?>
<ul class="file-list">
<?php foreach ($files as $f): ?>
  <?php $href = '/api/files/' . $f['id'] . '/content'; $ext = strtoupper(\App\Modules\Files\UploadTypes::extensionOf((string) $f['storage_path'])); ?>
  <li class="file-item">
    <span class="file-kind"><?= e($ext) ?></span>
    <span class="file-title"><?= e($f['title']) ?><?php if ($f['page_number'] !== null): ?> <span class="small muted">p. <?= e((string) $f['page_number']) ?></span><?php endif ?></span>
    <?php if (str_starts_with((string) $f['mime_type'], 'audio/')): ?>
      <audio controls preload="none" src="<?= e(url($href)) ?>"></audio>
    <?php else: ?>
      <a class="button small" href="<?= e(url($href)) ?>" target="_blank" rel="noopener"><?= e(t('library.open')) ?></a>
    <?php endif ?>
    <a class="button small" href="<?= e(url($href, ['download' => '1'])) ?>"><?= e(t('library.download')) ?></a>
  </li>
<?php endforeach ?>
</ul>
