<?php
/**
 * @var \App\Core\View $v
 * @var list<array{id: string, title: string, provider: string, problem: string, detail: string}> $videos
 * @var list<array{id: string, title: string, path: string}> $files
 * @var ?list<array{name: string, size: int, modified: int, public: bool, deletable: bool}> $orphans null unless an administrator
 * @var int $linkCount
 */
$labels = ['service' => 'Service gone', 'file' => 'File missing', 'failed' => 'Failed', 'stuck' => 'Stuck processing', 'transcript' => 'Transcription failed'];
?>
<h1>Media check</h1>
<p class="muted">What in your part of the library can’t play or can’t be found. Fix a video from its page; the list is worked out afresh each time you open this.</p>

<h2>Videos</h2>
<?php if ($videos === []): ?>
  <p class="notice ok">Every video has its service, its file and a finished status.</p>
<?php else: ?>
  <table class="table">
    <thead><tr><th>Video</th><th>Problem</th><th>Detail</th></tr></thead>
    <tbody>
    <?php foreach ($videos as $row): ?>
      <tr>
        <td><a href="<?= e(url('/admin/videos/' . $row['id'])) ?>"><?= e($row['title']) ?></a> <span class="small muted"><?= e($row['provider']) ?></span></td>
        <td><span class="badge"><?= e($labels[$row['problem']] ?? $row['problem']) ?></span></td>
        <td class="small"><?= e($row['detail']) ?></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
<?php endif ?>

<h2>Pasted links</h2>
<?php if ($linkCount === 0): ?>
  <p class="muted">No videos play from a pasted direct or Dropbox link.</p>
<?php else: ?>
  <div class="card stack" data-link-check>
    <p><?= e((string) $linkCount) ?> video<?= $linkCount === 1 ? '' : 's' ?> play from a pasted direct or Dropbox link. Checking asks each address whether it still serves a video.</p>
    <div><button type="button" class="button" data-link-check-start>Check links</button> <span class="small muted" data-link-check-progress hidden></span></div>
    <table class="table" hidden data-link-check-table>
      <thead><tr><th>Video</th><th>Result</th></tr></thead>
      <tbody></tbody>
    </table>
    <p class="error" data-error hidden></p>
  </div>
<?php endif ?>

<?php if ($files !== []): ?>
  <h2>Files missing from storage</h2>
  <ul class="plain">
    <?php foreach ($files as $f): ?>
      <li><a href="<?= e(url('/admin/files?q=' . rawurlencode($f['title']))) ?>"><?= e($f['title']) ?></a> <span class="small muted"><?= e($f['path']) ?></span></li>
    <?php endforeach ?>
  </ul>
<?php endif ?>

<?php if ($orphans !== null): ?>
  <h2>Video files nothing uses</h2>
  <?php if ($orphans === []): ?>
    <p class="muted">Every video file on this host belongs to a video (the trash included).</p>
  <?php else: ?>
    <p class="small muted">Files on this host’s disk that no video — not even one in the trash — names: usually an upload that was never finished. Deleting one frees its space for good.</p>
    <table class="table">
      <thead><tr><th>File</th><th>Size</th><th>Last changed</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($orphans as $o): ?>
        <tr>
          <td><code><?= e($o['name']) ?></code><?php if ($o['public']): ?> <span class="badge muted">public</span><?php endif ?></td>
          <td><?= e(sprintf('%.1f MB', $o['size'] / 1048576)) ?></td>
          <td><time datetime="<?= e(gmdate('c', $o['modified'])) ?>" data-local-date><?= e(gmdate('Y-m-d H:i', $o['modified'])) ?> UTC</time></td>
          <td class="actions">
            <?php if ($o['deletable']): ?>
              <button type="button" class="button small danger" data-api="/api/admin/media-check/orphans/<?= e($o['name']) ?>" data-method="DELETE" data-confirm="Delete <?= e($o['name']) ?> for good?">Delete</button>
            <?php else: ?>
              <span class="small muted">changed within the hour</span>
            <?php endif ?>
          </td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  <?php endif ?>
<?php endif ?>
<script type="module" src="<?= e(asset('js/media-check.js')) ?>"></script>
