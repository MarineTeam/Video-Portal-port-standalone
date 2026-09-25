<?php
/** @var \App\Core\View $v @var list<array<string, mixed>> $jobs @var int $lastReal @var ?string $cronUrl @var ?string $flash */
?>
<h1>Scheduled jobs</h1>
<?php if ($flash): ?><p class="notice" role="status"><?= e($flash) ?></p><?php endif ?>
<p>
  Real cron: <?= $lastReal > 0 ? 'last seen ' . e(gmdate('Y-m-d H:i', $lastReal)) . ' UTC' : '<strong>never seen</strong>' ?>.
  Without one, jobs run when somebody visits the site (at most once a minute), which is fine for a quiet site and slow for a busy schedule.
</p>
<?php if ($cronUrl !== null): ?>
  <p>Add this to your host’s cron jobs (cPanel → Cron Jobs), every five minutes:</p>
  <pre class="code">*/5 * * * * curl -fsS "<?= e($cronUrl) ?>" >/dev/null</pre>
  <p class="small muted">Or with wget: <code>wget -q -O /dev/null "<?= e($cronUrl) ?>"</code>. Keep this address private: it is what lets the jobs run.</p>
<?php else: ?>
  <p class="notice error">No cron token is set, so /cron/run refuses to run anything.</p>
<?php endif ?>
<table class="table">
  <thead><tr><th>Job</th><th>Every</th><th>Last run</th><th>Status</th><th>Next</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($jobs as $job): ?>
    <tr>
      <td><code><?= e($job['name']) ?></code></td>
      <td><?= e($job['interval_seconds'] >= 86400 ? round($job['interval_seconds'] / 86400) . ' d' : ($job['interval_seconds'] >= 3600 ? round($job['interval_seconds'] / 3600) . ' h' : round($job['interval_seconds'] / 60) . ' min')) ?></td>
      <td><?= e($job['last_run_at'] ?? 'never') ?></td>
      <td><?= e($job['last_status'] ?? '') ?><?php if ($job['last_error']): ?><div class="small error"><?= e(mb_substr($job['last_error'], 0, 300)) ?></div><?php endif ?></td>
      <td><?= e($job['next_run_at']) ?></td>
      <td><form method="post" action="<?= e(url('/admin/jobs/' . $job['name'] . '/run')) ?>"><?= $v->raw(csrf_field()) ?><button class="button small" type="submit">Run now</button></form></td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
