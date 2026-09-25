<?php
/** @var \App\Core\View $v @var array $counts @var array $cards @var int $cronSeen @var bool $emailConfigured */
?>
<h1>Admin</h1>
<ul class="stats">
<?php foreach ($counts as $label => $n): ?>
  <li><strong><?= e(number_format($n)) ?></strong><span><?= e($label) ?></span></li>
<?php endforeach ?>
</ul>
<?php if ($cronSeen < time() - 3600): ?>
  <p class="notice warn">No real cron has run in the last hour, so scheduled jobs only run when people visit. <a href="<?= e(url('/admin/jobs')) ?>">Set up the cron line</a>.</p>
<?php endif ?>
<?php if (!$emailConfigured): ?>
  <p class="notice warn">Email isn’t set up: notifications, password resets and sign-in links won’t be sent. <a href="<?= e(url('/admin/providers')) ?>">Set up email</a>.</p>
<?php endif ?>
<?php foreach ($cards as $card): ?>
  <section class="card"><?= $v->raw(is_string($card) ? $card : '') ?></section>
<?php endforeach ?>
