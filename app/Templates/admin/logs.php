<?php
/** @var string $channel @var list<string> $lines */
?>
<h1>Logs</h1>
<p><a href="<?= e(url('/admin/logs/download')) ?>">Download the log</a> · newest first · secrets are masked before anything is written.</p>
<?php if ($lines === []): ?>
  <p class="muted">Nothing logged yet.</p>
<?php else: ?>
  <pre class="log"><?php foreach ($lines as $line): ?><?= e($line) . "\n" ?><?php endforeach ?></pre>
<?php endif ?>
