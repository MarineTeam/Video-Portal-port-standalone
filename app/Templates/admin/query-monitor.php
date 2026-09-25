<?php
/**
 * @var \App\Core\View $v
 * @var bool $configured
 * @var bool $enabled
 */
?>
<h1>Query monitor</h1>
<p class="muted">A bar at the foot of every page — how many database queries it ran and how long they took, the page’s own time, peak memory — for administrators only. It shows when both switches below are on.</p>
<div class="card stack narrow">
  <h2>On this server: <?= e($configured ? 'on' : 'off') ?></h2>
  <p class="small">Set in <code>storage/config.php</code> with <code>'query_monitor' => true,</code> — it also turns on the recording itself, so while it is off the monitor costs nothing. This page can’t change it.</p>
</div>
<div class="card stack narrow">
  <h2>The bar: <?= e($enabled ? 'shown' : 'hidden') ?></h2>
  <p class="small">A switch for the moment — to hide the bar during a demo and bring it back after — with nothing to redeploy.</p>
  <div><button class="button" type="button" data-api="/api/admin/query-monitor" data-method="PATCH" data-body="<?= e(json_encode(['enabled' => !$enabled])) ?>"><?= e($enabled ? 'Hide the bar' : 'Show the bar') ?></button></div>
  <p class="error" data-error hidden></p>
</div>
