<?php
/**
 * @var \App\Core\View $v
 * @var list<array{files: int, bytes: int}> $parts
 * @var bool $zip
 * @var int $tables
 */
$mb = static fn (int $bytes): string => $bytes >= 1048576 ? number_format($bytes / 1048576, 1) . ' MB' : number_format(max(1, (int) round($bytes / 1024))) . ' KB';
?>
<h1>Backup &amp; import</h1>

<h2>Database</h2>
<div class="card stack" data-backup>
  <p>Everything the site knows — members, videos, settings, the audit log — as one <code>.sql.gz</code> file (<?= e((string) $tables) ?> tables). It is written a few thousand rows at a time, so a large site doesn’t run into the host’s limits, and it is deleted from the server as soon as you have downloaded it.</p>
  <p class="small muted">Saved passwords and API keys stay encrypted in the file with the key in <code>storage/config.php</code>: keep a copy of that file with your backups, or restore them together. To restore, import the file into an empty database with phpMyAdmin.</p>
  <div class="row"><button class="button primary" type="button" data-backup-start>Make a backup</button></div>
  <progress data-backup-progress value="0" max="100" hidden></progress>
  <p class="small" data-backup-status></p>
  <p class="error" data-error hidden></p>
</div>

<h2>Uploaded files</h2>
<div class="card">
  <?php if ($parts === []): ?>
    <p>No files have been uploaded to this server.</p>
  <?php elseif (!$zip): ?>
    <p>This host has no zip extension, so the files can’t be packed here. Download <code>storage/uploads</code> and <code>storage/media</code> with FTP or your host’s file manager instead.</p>
  <?php else: ?>
    <p>Files stored on this server, in zip files of about 100 MB so each one is made in a single request. Download every part: together they are the whole of <code>storage/uploads</code> and <code>storage/media</code>.</p>
    <table class="table">
      <thead><tr><th>Part</th><th>Files</th><th>Size</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($parts as $i => $part): ?>
        <tr>
          <td><?= e((string) ($i + 1)) ?> of <?= e((string) count($parts)) ?></td>
          <td><?= e(number_format($part['files'])) ?></td>
          <td><?= e($mb($part['bytes'])) ?></td>
          <td><a class="button small" href="<?= e(url('/api/admin/tools/uploads/' . ($i + 1))) ?>" download>Download</a></td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
    <p class="small muted">Files kept with an external storage service (such as Bunny Storage) are not on this server and aren’t included; they are safe where they are.</p>
  <?php endif ?>
</div>
<script type="module" src="<?= e(asset('js/tools.js')) ?>"></script>
