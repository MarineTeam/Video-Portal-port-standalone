<?php
/** @var array $report */
$fmt = fn ($bytes) => $bytes === null ? 'unknown' : number_format($bytes / 1073741824, 1) . ' GB';
?>
<h1>System</h1>
<table class="table">
  <tr><th>App version</th><td><?= e($report['appVersion']) ?></td></tr>
  <tr><th>PHP</th><td><?= e($report['php']) ?></td></tr>
  <tr><th>Database</th><td><?= e($report['database'] ?? 'unreachable') ?></td></tr>
  <tr><th>Storage</th><td><code><?= e($report['storage']) ?></code> — <?= $v->raw($report['writable'] ? 'writable' : '<strong>not writable</strong>') ?></td></tr>
  <tr><th>Disk</th><td><?= e($fmt($report['disk']['free'])) ?> free of <?= e($fmt($report['disk']['total'])) ?></td></tr>
  <tr><th>This request</th><td><?= $report['https'] ? 'HTTPS' : 'plain HTTP — external sign-in providers need HTTPS' ?></td></tr>
  <tr><th>Upload slices</th><td><?= e(number_format($report['uploadChunk'] / 1048576, 1)) ?> MB (half the host’s upload limit)</td></tr>
  <tr><th>File offload</th><td><?= e($report['offload'] ?? 'none — files stream through PHP') ?></td></tr>
  <tr><th>Outbound HTTPS</th><td><?= $v->raw($report['outbound']['ok'] ? 'works' : '<strong>blocked</strong>') ?> — <?= e($report['outbound']['message']) ?> <a href="?probe=1">Check again</a></td></tr>
</table>
<h2>Limits</h2>
<table class="table">
<?php foreach ($report['limits'] as $k => $val): ?>
  <tr><th><code><?= e($k) ?></code></th><td><?= e($val === '' ? '(none)' : $val) ?></td></tr>
<?php endforeach ?>
</table>
<h2>Extensions</h2>
<table class="table">
<?php foreach ($report['extensions'] as $ext => $info): ?>
  <tr><th><code><?= e($ext) ?></code></th><td><?= $v->raw($info['loaded'] ? 'loaded' : ($info['required'] ? '<strong>missing — required</strong>' : 'missing (optional)')) ?></td></tr>
<?php endforeach ?>
</table>
