<?php
/**
 * @var \App\Core\View $v
 * @var list<array<string, mixed>> $videos presented, with providerLabel, seriesTitle, speakerName
 * @var int $total
 * @var int $page
 * @var int $perPage
 * @var string $q
 * @var string $seriesId
 * @var string $status
 * @var list<array{id: string, title: string}> $series
 * @var list<array{id: string, name: string}> $speakers
 * @var ?string $uploadTo the Video slot's provider, when it takes uploads
 * @var bool $bunny whether bunny.net Stream is configured (for the import)
 * @var bool $canPublish
 */
$query = array_filter(['q' => $q, 'seriesId' => $seriesId, 'status' => $status], fn ($x) => $x !== '');
$seriesOptions = function (string $selected = '') use ($series): string {
    $out = '';
    foreach ($series as $s) {
        $out .= '<option value="' . e($s['id']) . '"' . ($selected === $s['id'] ? ' selected' : '') . '>' . e($s['title']) . '</option>';
    }
    return $out;
};
?>
<h1>Videos</h1>

<section class="card stack" data-video-add>
  <div class="row" role="tablist">
    <button type="button" class="button small" role="tab" aria-selected="true" data-video-tab="link">Add by link</button>
    <button type="button" class="button small" role="tab" aria-selected="false" data-video-tab="upload">Upload</button>
    <?php if ($bunny): ?><button type="button" class="button small" role="tab" aria-selected="false" data-video-tab="bunny">Import from Bunny</button><?php endif ?>
  </div>

  <form class="stack" data-video-panel="link" data-api="/api/admin/videos" data-method="POST" data-redirect="/admin/videos/{id}">
    <input type="hidden" name="mode" value="link">
    <label>Link<input name="url" type="url" required maxlength="2000" placeholder="https://www.youtube.com/watch?v=…"></label>
    <p class="small muted">YouTube, Vimeo, Dropbox, Google Drive, OneDrive and archive.org links, or the https:// address of a video file.</p>
    <div class="row">
      <label>Title (optional)<input name="title" maxlength="255"></label>
      <label>Series<select name="seriesId" data-null><option value="">— none —</option><?= $v->raw($seriesOptions()) ?></select></label>
    </div>
    <div class="row">
      <label class="check"><input type="checkbox" name="memberOnly"> Members only</label>
      <?php if ($canPublish): ?><label class="check"><input type="checkbox" name="published"> Published</label><?php endif ?>
    </div>
    <div><button class="button primary" type="submit">Add video</button></div>
    <p class="error" data-error hidden></p>
  </form>

  <form class="stack" data-video-panel="upload" data-video-upload hidden>
    <?php if ($uploadTo === null): ?>
      <p class="muted">No video service that takes uploads is chosen. Pick one under <a href="<?= e(url('/admin/providers')) ?>">Services</a>, or add videos by link.</p>
    <?php else: ?>
      <p class="small muted">Uploads go to <?= e($uploadTo) ?>. Keep this page open until the upload finishes.</p>
      <label>Video file<input type="file" name="file" accept="video/*" required></label>
      <div class="row">
        <label>Title (optional)<input name="title" maxlength="255"></label>
        <label>Series<select name="seriesId" data-null><option value="">— none —</option><?= $v->raw($seriesOptions()) ?></select></label>
      </div>
      <div class="row">
        <label class="check"><input type="checkbox" name="memberOnly"> Members only</label>
        <?php if ($canPublish): ?><label class="check"><input type="checkbox" name="published"> Published</label><?php endif ?>
      </div>
      <progress max="100" value="0" data-upload-progress hidden></progress>
      <p class="small" data-upload-status hidden></p>
      <div><button class="button primary" type="submit">Upload</button></div>
      <p class="error" data-error hidden></p>
    <?php endif ?>
  </form>

  <?php if ($bunny): ?>
  <form class="stack" data-video-panel="bunny" data-bunny-import hidden>
    <p class="small muted">Videos in the bunny.net library that aren’t in this site yet.</p>
    <div data-bunny-list class="stack small"><button type="button" class="button small" data-bunny-load>Look in the library</button></div>
    <label>Into series<select name="seriesId" data-null><option value="">— none —</option><?= $v->raw($seriesOptions()) ?></select></label>
    <div><button class="button primary" type="submit">Import ticked</button></div>
    <p class="error" data-error hidden></p>
  </form>
  <?php endif ?>
</section>

<form method="get" class="row">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Filter by title" aria-label="Filter by title">
  <select name="seriesId" aria-label="Series">
    <option value="">Every series</option>
    <option value="none"<?= $seriesId === 'none' ? ' selected' : '' ?>>No series</option>
    <?= $v->raw($seriesOptions($seriesId)) ?>
  </select>
  <select name="status" aria-label="Status">
    <option value="">Any status</option>
    <?php foreach (['READY' => 'Ready', 'PROCESSING' => 'Processing', 'FAILED' => 'Failed'] as $k => $label): ?>
      <option value="<?= e($k) ?>"<?= $status === $k ? ' selected' : '' ?>><?= e($label) ?></option>
    <?php endforeach ?>
  </select>
  <button class="button" type="submit">Filter</button>
</form>

<?php if ($videos === []): ?>
  <p class="muted">No videos<?= e($q !== '' ? ' match “' . $q . '”' : ' yet') ?>.</p>
<?php else: ?>
<div class="bulk" data-bulk-endpoint="/api/admin/videos/bulk">
  <div class="row small">
    <span data-bulk-count>0 selected</span>
    <?php if ($canPublish): ?>
      <button type="button" class="button small" data-bulk-op="publish">Publish</button>
      <button type="button" class="button small" data-bulk-op="unpublish">Unpublish</button>
    <?php endif ?>
    <select data-bulk-op-move aria-label="Move selected to">
      <option value="">Move to…</option>
      <option value="none">— no series —</option>
      <?= $v->raw($seriesOptions()) ?>
    </select>
    <button type="button" class="button small danger" data-bulk-op="delete" data-confirm="Move the selected videos to the trash?">Delete</button>
  </div>
<table class="table">
  <thead><tr><th><input type="checkbox" data-bulk-all aria-label="Select all"></th><th>Video</th><th>Series</th><th>Service</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($videos as $video): ?>
    <tr>
      <td><input type="checkbox" data-bulk-id="<?= e($video['id']) ?>" aria-label="Select <?= e($video['title']) ?>"></td>
      <td>
        <a href="<?= e(url('/admin/videos/' . $video['id'])) ?>"><?= e($video['title']) ?></a>
        <?php if ($video['status'] !== 'READY'): ?><span class="badge <?= $video['status'] === 'FAILED' ? 'danger' : 'muted' ?>"><?= e($video['status'] === 'FAILED' ? 'Failed' : 'Processing') ?></span><?php endif ?>
        <?php if (!$video['published']): ?><span class="badge muted">Draft</span><?php elseif ($video['publishAt'] !== null && $video['publishAt'] > gmdate('Y-m-d\TH:i:s')): ?><span class="badge muted">Scheduled</span><?php endif ?>
        <?php if ($video['memberOnly']): ?><span class="badge">Members</span><?php endif ?>
        <?php if ($video['hidden']): ?><span class="badge muted">Hidden</span><?php endif ?>
        <?php if ($video['speakerName'] !== null): ?><div class="small muted"><?= e($video['speakerName']) ?></div><?php endif ?>
      </td>
      <td class="small"><?= e($video['seriesTitle'] ?? '—') ?></td>
      <td class="small"><?= e($video['providerLabel']) ?></td>
      <td class="actions">
        <?php if ($video['seriesId'] !== null): ?>
          <button type="button" class="button small" aria-label="Move <?= e($video['title']) ?> up" data-api="/api/admin/videos/<?= e($video['id']) ?>" data-method="PATCH" data-body='{"move":"up"}'>↑</button>
          <button type="button" class="button small" aria-label="Move <?= e($video['title']) ?> down" data-api="/api/admin/videos/<?= e($video['id']) ?>" data-method="PATCH" data-body='{"move":"down"}'>↓</button>
        <?php endif ?>
        <?php if ($video['status'] !== 'READY'): ?>
          <button type="button" class="button small" data-api="/api/admin/videos/<?= e($video['id']) ?>/sync-status" data-method="POST">Check status</button>
        <?php endif ?>
        <a class="button small" href="<?= e(url('/admin/videos/' . $video['id'])) ?>">Edit</a>
      </td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
</div>
<?= $v->partial('partials/pager', ['list' => ['total' => $total, 'page' => $page, 'pageSize' => $perPage], 'query' => $query, 'path' => '/admin/videos']) ?>
<?php endif ?>
<script type="module" src="<?= e(asset('js/video-upload.js')) ?>"></script>
