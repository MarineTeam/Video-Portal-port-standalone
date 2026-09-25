<?php
/**
 * @var \App\Core\View $v
 * @var list<array<string, mixed>> $files presented, with seriesTitle, categoryName, kind
 * @var int $total
 * @var int $page
 * @var int $perPage
 * @var string $q
 * @var string $seriesId
 * @var string $kind
 * @var list<array{id: string, title: string}> $series
 * @var list<array<string, mixed>> $categories
 * @var ?string $storedWith
 * @var bool $canPublish
 * @var string $accept
 * @var bool $bunnyStorage whether a Bunny Storage zone is set up (import, replace from storage)
 * @var bool $podcastZone whether a public podcast zone mirrors published audio
 */
$query = array_filter(['q' => $q, 'seriesId' => $seriesId, 'kind' => $kind], fn ($x) => $x !== '');
$seriesOptions = function (string $selected = '') use ($series): string {
    $out = '';
    foreach ($series as $s) {
        $out .= '<option value="' . e($s['id']) . '"' . ($selected === $s['id'] ? ' selected' : '') . '>' . e($s['title']) . '</option>';
    }
    return $out;
};
$size = function (?int $bytes): string {
    if ($bytes === null) {
        return '';
    }
    foreach (['B', 'KB', 'MB', 'GB'] as $i => $unit) {
        if ($bytes < 1024 ** ($i + 1) || $unit === 'GB') {
            return ($i === 0 ? $bytes : number_format($bytes / 1024 ** $i, 1)) . ' ' . $unit;
        }
    }
    return '';
};
?>
<h1>Files</h1>

<?php if ($storedWith === null): ?>
  <p class="notice warn">No file storage is chosen. Pick one under <a href="<?= e(url('/admin/providers')) ?>">Services</a>.</p>
<?php else: ?>
<form class="card stack" data-api="/api/admin/files" data-method="POST" data-chunked-upload="file">
  <label>Add a file (PDF, EPUB, MP3, M4A, OGG or an image)<input type="file" accept="<?= e($accept) ?>" data-upload-file required></label>
  <input type="hidden" name="upload" data-upload-id>
  <div class="row">
    <label>Title (optional)<input name="title" maxlength="255"></label>
    <label>Series<select name="seriesId" data-null><option value="">— none —</option><?= $v->raw($seriesOptions()) ?></select></label>
    <label>Or category
      <select name="categoryId" data-null>
        <option value="">— none —</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= e($c['id']) ?>"><?= e(str_repeat('— ', (int) $c['depth']) . $c['name']) ?></option>
        <?php endforeach ?>
      </select>
    </label>
  </div>
  <div class="row">
    <label class="check"><input type="checkbox" name="memberOnly"> Members only</label>
    <?php if ($canPublish): ?><label class="check"><input type="checkbox" name="published" checked> Published</label><?php endif ?>
  </div>
  <progress max="100" value="0" data-upload-progress hidden></progress>
  <p class="small muted">Stored with <?= e($storedWith) ?>.</p>
  <div><button class="button primary" type="submit">Upload</button></div>
  <p class="error" data-error hidden></p>
</form>
<?php endif ?>

<?php if ($bunnyStorage): ?>
<details class="card" data-storage-browser>
  <summary>Import from Bunny Storage</summary>
  <p class="small muted">Files already in the storage zone — a scanned hymnal too big to upload here, say. They stay where they are; each becomes a file here.</p>
  <p class="small"><strong data-storage-dir>/</strong> <button type="button" class="link" data-storage-up hidden>↑ Up</button></p>
  <ul class="plain stack small" data-storage-entries><li class="muted">Loading…</li></ul>
  <div class="row">
    <label>Into series<select name="seriesId" data-storage-series><option value="">— none —</option><?= $v->raw($seriesOptions()) ?></select></label>
    <button type="button" class="button primary small" data-storage-import>Import ticked</button>
  </div>
  <p class="error" data-error hidden></p>
</details>
<?php endif ?>

<form method="get" class="row">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Filter by title" aria-label="Filter by title">
  <select name="seriesId" aria-label="Series">
    <option value="">Every series</option>
    <option value="none"<?= $seriesId === 'none' ? ' selected' : '' ?>>No series</option>
    <?= $v->raw($seriesOptions($seriesId)) ?>
  </select>
  <select name="kind" aria-label="Kind">
    <option value="">Any kind</option>
    <?php foreach (['document' => 'Documents', 'audio' => 'Audio', 'image' => 'Images'] as $k => $label): ?>
      <option value="<?= e($k) ?>"<?= $kind === $k ? ' selected' : '' ?>><?= e($label) ?></option>
    <?php endforeach ?>
  </select>
  <button class="button" type="submit">Filter</button>
</form>

<?php if ($files === []): ?>
  <p class="muted">No files<?= e($q !== '' ? ' match “' . $q . '”' : ' yet') ?>.</p>
<?php else: ?>
<div class="bulk" data-bulk-endpoint="/api/admin/files/bulk">
  <div class="row small">
    <span data-bulk-count>0 selected</span>
    <?php if ($canPublish): ?>
      <button type="button" class="button small" data-bulk-op="publish">Publish</button>
      <button type="button" class="button small" data-bulk-op="unpublish">Unpublish</button>
    <?php endif ?>
    <button type="button" class="button small" data-bulk-op="podcast">Add to podcast</button>
    <button type="button" class="button small" data-bulk-op="unpodcast">Remove from podcast</button>
    <select data-bulk-op-move aria-label="Move selected to">
      <option value="">Move to…</option>
      <option value="none">— no series —</option>
      <?= $v->raw($seriesOptions()) ?>
    </select>
    <button type="button" class="button small danger" data-bulk-op="delete" data-confirm="Move the selected files to the trash?">Delete</button>
  </div>
<table class="table">
  <thead><tr><th><input type="checkbox" data-bulk-all aria-label="Select all"></th><th>File</th><th>Series or category</th><th>Size</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($files as $f): ?>
    <tr>
      <td><input type="checkbox" data-bulk-id="<?= e($f['id']) ?>" aria-label="Select <?= e($f['title']) ?>"></td>
      <td>
        <details>
          <summary>
            <?= e($f['title']) ?>
            <span class="badge muted"><?= e(ucfirst((string) $f['kind'])) ?></span>
            <?php if (!$f['published']): ?><span class="badge muted">Draft</span><?php endif ?>
            <?php if ($f['memberOnly']): ?><span class="badge">Members</span><?php endif ?>
            <?php if ($f['hidden']): ?><span class="badge muted">Hidden</span><?php endif ?>
            <?php if ($f['kind'] === 'audio' && $f['podcastPublished']): ?><span class="badge"><?= e($podcastZone && $f['backend'] === 'bunny' && $f['publicPath'] === null ? 'Podcast pending' : 'In podcast') ?></span><?php endif ?>
          </summary>
          <form class="stack" data-api="/api/admin/files/<?= e($f['id']) ?>" data-method="PATCH">
            <label>Title<input name="title" value="<?= e($f['title']) ?>" required maxlength="255"></label>
            <div class="row">
              <label>Series<select name="seriesId" data-null><option value="">— none —</option><?= $v->raw($seriesOptions((string) ($f['seriesId'] ?? ''))) ?></select></label>
              <label>Page number<input name="pageNumber" type="number" min="0" data-type="int" data-null value="<?= e((string) ($f['pageNumber'] ?? '')) ?>"></label>
              <label>Group<input name="groupLabel" maxlength="255" data-null value="<?= e((string) ($f['groupLabel'] ?? '')) ?>"></label>
            </div>
            <?php if ($f['kind'] === 'document'): ?>
              <label>Page offset (printed page 1 is PDF page 1 + this)<input name="pageOffset" type="number" data-type="int" value="<?= e((string) $f['pageOffset']) ?>"></label>
            <?php endif ?>
            <?php if ($f['kind'] === 'audio'): ?>
              <label class="check"><input type="checkbox" name="podcastPublished"<?= $f['podcastPublished'] ? ' checked' : '' ?>> In the series’ podcast</label>
            <?php endif ?>
            <details>
              <summary class="small">Song details</summary>
              <div class="row">
                <label>CCLI number<input name="ccliNumber" maxlength="64" data-null value="<?= e((string) ($f['ccliNumber'] ?? '')) ?>"></label>
                <label>Key<input name="musicalKey" maxlength="16" data-null value="<?= e((string) ($f['musicalKey'] ?? '')) ?>"></label>
                <label>Tempo (BPM)<input name="tempoBpm" type="number" min="1" max="400" data-type="int" data-null value="<?= e((string) ($f['tempoBpm'] ?? '')) ?>"></label>
              </div>
              <label>Author<input name="songAuthor" maxlength="500" data-null value="<?= e((string) ($f['songAuthor'] ?? '')) ?>"></label>
              <label>Copyright<input name="songCopyright" maxlength="500" data-null value="<?= e((string) ($f['songCopyright'] ?? '')) ?>"></label>
            </details>
            <?= $v->partial('partials/admin-publishing', ['item' => $f, 'canPublish' => $canPublish, 'downloads' => false]) ?>
            <div class="row">
              <button class="button small primary" type="submit">Save</button>
              <?php if ($bunnyStorage): ?>
                <label class="small">or a path in storage<input name="storagePath" form="replace-<?= e($f['id']) ?>" maxlength="1000" placeholder="scans/hymnal-2026.pdf"></label>
                <button class="button small" type="submit" form="replace-<?= e($f['id']) ?>">Use it</button>
              <?php endif ?>
              <label class="button small">Replace file…<input type="file" accept="<?= e($accept) ?>" hidden data-upload-to="/api/admin/files/<?= e($f['id']) ?>/replace" data-purpose="file" data-confirm="Replace this file’s contents? Links to it keep working."></label>
            </div>
            <p class="error" data-error hidden></p>
          </form>
        </details>
      </td>
      <td class="small"><?= e($f['seriesTitle'] ?? $f['categoryName'] ?? '—') ?></td>
      <td class="small"><?= e($size($f['sizeBytes'] === null ? null : (int) $f['sizeBytes'])) ?></td>
      <td class="actions">
        <?php if ($f['seriesId'] !== null): ?>
          <button type="button" class="button small" aria-label="Move <?= e($f['title']) ?> up" data-api="/api/admin/files/<?= e($f['id']) ?>" data-method="PATCH" data-body='{"move":"up"}'>↑</button>
          <button type="button" class="button small" aria-label="Move <?= e($f['title']) ?> down" data-api="/api/admin/files/<?= e($f['id']) ?>" data-method="PATCH" data-body='{"move":"down"}'>↓</button>
        <?php endif ?>
        <a class="button small" href="<?= e(url('/api/files/' . $f['id'] . '/content')) ?>" target="_blank" rel="noopener">Open</a>
      </td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
</div>
<?php if ($bunnyStorage): foreach ($files as $f): ?>
  <form id="replace-<?= e($f['id']) ?>" hidden data-api="/api/admin/files/<?= e($f['id']) ?>/replace" data-method="POST" data-confirm="Point this file at that object in storage? Links to it keep working."></form>
<?php endforeach; endif ?>
<?= $v->partial('partials/pager', ['list' => ['total' => $total, 'page' => $page, 'pageSize' => $perPage], 'query' => $query, 'path' => '/admin/files']) ?>
<?php endif ?>
<script type="module" src="<?= e(asset('js/library-admin.js')) ?>"></script>
