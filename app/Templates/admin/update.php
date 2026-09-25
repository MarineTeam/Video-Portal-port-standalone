<?php
/**
 * @var \App\Core\View $v
 * @var string $codeVersion
 * @var ?string $dbVersion
 * @var bool $behind
 * @var list<string> $pending
 * @var bool $needed
 * @var ?array{by: ?string, email: ?string, at: ?string, reason: string} $maintenance
 * @var ?array<string, mixed> $release
 * @var bool $canUploadRelease
 * @var ?string $why
 */
?>
<h1>Update</h1>
<div class="card" data-update>
  <p>These files are version <strong><?= e($codeVersion) ?></strong>. The database was last brought up to <strong><?= e($dbVersion ?? 'an earlier version') ?></strong>.</p>
  <?php if ($release !== null): ?>
    <p>A release, version <strong><?= e($release['version']) ?></strong>, is part way through (<?= e(['verified' => 'checked, not yet unpacked', 'extracted' => 'unpacked, not yet in place', 'swapped' => 'new files in place, database not yet updated'][$release['stage']] ?? $release['stage']) ?>).</p>
    <div class="row">
      <button class="button primary" type="button" data-release-continue data-id="<?= e($release['id']) ?>" data-stage="<?= e($release['stage']) ?>">Continue</button>
      <?php if ($release['stage'] === 'swapped'): ?>
        <button class="button" type="button" data-api="/api/admin/update/release/<?= e($release['id']) ?>/rollback" data-method="POST" data-confirm="Put the previous files back? Only do this if no migration has run yet.">Roll back the files</button>
      <?php else: ?>
        <button class="button" type="button" data-api="/api/admin/update/release/<?= e($release['id']) ?>" data-method="DELETE" data-confirm="Discard this upload?">Discard</button>
      <?php endif ?>
    </div>
  <?php elseif ($needed): ?>
    <p><?= e($pending === [] ? 'No database changes are needed; finishing records the new version and clears the caches.' : count($pending) . ' database change' . (count($pending) === 1 ? '' : 's') . ' to apply:') ?></p>
    <?php if ($pending !== []): ?><ul class="small"><?php foreach ($pending as $name): ?><li><code><?= e($name) ?></code></li><?php endforeach ?></ul><?php endif ?>
    <p class="small muted">The site shows visitors the maintenance page until this finishes. Each change runs in its own request, so a slow host can’t leave one half done: if a step fails, fix the cause and press the button again — it carries on where it stopped.</p>
    <button class="button primary" type="button" data-update-run>Finish the update</button>
  <?php else: ?>
    <p>Everything is up to date.</p>
  <?php endif ?>
  <progress data-update-progress value="0" max="100" hidden></progress>
  <ol class="small" data-update-log></ol>
  <p class="error" data-error hidden></p>
</div>

<h2>Maintenance mode</h2>
<div class="card">
  <?php if ($maintenance !== null): ?>
    <p>On<?php if ($maintenance['email']): ?>, turned on by <?= e($maintenance['email']) ?><?php endif ?><?php if ($maintenance['at']): ?> at <?= e($maintenance['at']) ?><?php endif ?>. Visitors see the maintenance page; <?= $maintenance['by'] !== null ? 'that administrator can use the whole site, other administrators only this page.' : 'administrators can use this page.' ?></p>
    <button class="button" type="button" data-api="/api/admin/update/maintenance" data-method="POST" data-body='{"on":false}'>Reopen the site</button>
  <?php else: ?>
    <p>Off. Turn it on before changing files by FTP, so nobody sees a half-uploaded site; you keep using it as normal.</p>
    <button class="button" type="button" data-api="/api/admin/update/maintenance" data-method="POST" data-body='{"on":true}' data-confirm="Show visitors the maintenance page until you turn it off?">Turn on maintenance mode</button>
  <?php endif ?>
  <p class="error" data-error hidden></p>
</div>

<h2>Upload a release</h2>
<?php if ($canUploadRelease): ?>
<form class="card stack narrow" data-chunked-upload="release" data-api="/api/admin/update/release" data-method="POST">
  <p class="small muted">The <code>.zip</code> from the releases page. Its signature is checked before anything is unpacked; then the new files replace the old ones (your uploads, settings, installed plugins and themes are kept) and the database is brought up to date.</p>
  <input type="file" accept=".zip" data-upload-file required>
  <input type="hidden" name="upload" data-upload-id>
  <progress data-upload-progress value="0" max="100" hidden></progress>
  <button class="button" type="submit">Upload and check</button>
  <p class="error" data-error hidden></p>
</form>
<?php else: ?>
  <p><?= e($why ?? '') ?> Upload the release by FTP instead: turn on maintenance mode, unzip the release on your computer, upload its files over the old ones (leave <code>storage/</code> alone), then come back to this page to finish.</p>
<?php endif ?>
<script type="module" src="<?= e(asset('js/update.js')) ?>"></script>
