<?php
/**
 * @var \App\Core\View $v
 * @var list<array<string, mixed>> $plugins
 * @var list<array{id: string, name: string}> $categories
 * @var bool $zip
 * @var ?string $flash
 */
?>
<h1>Plugins</h1>
<p class="muted">Plugin code runs with the whole site’s privileges. Install only plugins you trust as much as you would trust someone editing the site.</p>
<?php if ($flash): ?><p class="notice" role="status"><?= e($flash) ?></p><?php endif ?>
<table class="table plugins">
  <thead><tr><th>Plugin</th><th>Status</th><th>Category overrides</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($plugins as $p): ?>
    <tr>
      <td>
        <strong><?= e($p['name']) ?></strong>
        <?php if ($p['bundled']): ?><span class="badge">bundled</span><?php endif ?>
        <?php if (!$p['onDisk']): ?><span class="badge muted" title="The feature's code arrives in a later step of the port">not yet ported</span><?php endif ?>
        <div class="small muted"><?= e($p['description']) ?></div>
        <?php if ($p['deactivated_reason']): ?><div class="small error">Switched off automatically (<?= e($p['deactivated_reason']) ?>): <?= e(mb_substr((string) $p['deactivated_error'], 0, 400)) ?></div><?php endif ?>
      </td>
      <td><?= $p['enabled'] ? 'Active' : 'Inactive' ?></td>
      <td class="small">
        <?php foreach ($p['overrides'] as $o): ?>
          <div><?= e($o['category_name']) ?>: <?= $o['enabled'] ? 'on' : 'off' ?></div>
        <?php endforeach ?>
      </td>
      <td>
        <form method="post" action="<?= e(url('/admin/plugins/' . $p['slug'] . '/toggle')) ?>" class="inline">
          <?= $v->raw(csrf_field()) ?>
          <input type="hidden" name="enabled" value="<?= $p['enabled'] ? '0' : '1' ?>">
          <button type="submit" class="button small"><?= $p['enabled'] ? 'Deactivate' : 'Activate' ?></button>
        </form>
        <?php if (!$p['bundled'] && $p['onDisk']): ?>
        <form method="post" action="<?= e(url('/admin/plugins/' . $p['slug'] . '/uninstall')) ?>" class="inline" data-confirm="Delete this plugin and all of its data? This can’t be undone.">
          <?= $v->raw(csrf_field()) ?>
          <button type="submit" class="button small danger">Delete</button>
        </form>
        <?php endif ?>
      </td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
<h2>Install a plugin</h2>
<?php if ($zip): ?>
  <form method="post" action="<?= e(url('/admin/plugins/install')) ?>" data-chunked-upload="plugin" class="stack narrow">
    <?= $v->raw(csrf_field()) ?>
    <input type="file" accept=".zip" data-upload-file required>
    <input type="hidden" name="upload" data-upload-id>
    <progress data-upload-progress value="0" max="100" hidden></progress>
    <button type="submit" class="button">Upload and install</button>
  </form>
<?php else: ?>
  <p>This host has no zip extension, so plugins can’t be uploaded here. Unzip the plugin on your computer, upload its folder into <code>plugins/</code> with FTP or your host’s file manager, then refresh this page.</p>
<?php endif ?>
