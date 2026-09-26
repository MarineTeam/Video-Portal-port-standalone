<?php
/**
 * @var \App\Core\View $v
 * @var list<int> $windows
 * @var array{days: int, since: string, views: int, viewers: int, series: list<array<string, mixed>>, videos: list<array<string, mixed>>, hymns: list<array<string, mixed>>} $report
 */
$days = $report['days'];
?>
<h1>Analytics</h1>

<div class="row">
<nav class="row" aria-label="Window">
  <?php foreach ($windows as $window): ?>
    <a class="button small<?= $window === $days ? ' primary' : '' ?>" href="<?= e(url('/admin/analytics', ['days' => $window])) ?>"><?= e((string) $window) ?> days</a>
  <?php endforeach ?>
</nav>
  <span class="grow"></span>
  <a class="button small" href="<?= e(url('/api/admin/analytics/export', ['days' => $days])) ?>">Export CSV</a>
  <a class="button small" href="<?= e(url('/api/admin/analytics/export', ['days' => $days, 'format' => 'json'])) ?>">JSON</a>
</div>

<div class="row">
  <div class="card grow">
    <p class="big"><?= e(number_format($report['views'])) ?></p>
    <p class="small muted">Openings in the last <?= e((string) $days) ?> days</p>
  </div>
  <div class="card grow">
    <p class="big"><?= e(number_format($report['viewers'])) ?></p>
    <p class="small muted">People, as far as this can honestly say: a member counts once, and everybody else by their address, which is a household rather than a person.</p>
  </div>
</div>

<h2>Top series</h2>
<?php if ($report['series'] === []): ?>
  <p class="muted">Nothing was opened in this window.</p>
<?php else: ?>
<table class="table">
  <thead><tr><th>Series</th><th>Views</th></tr></thead>
  <tbody>
  <?php foreach ($report['series'] as $row): ?>
    <tr>
      <td><a href="<?= e(url('/series/' . $row['slug'])) ?>"><?= e($row['title']) ?></a></td>
      <td><?= e(number_format($row['views'])) ?></td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
<?php endif ?>

<h2>Top videos</h2>
<?php if ($report['videos'] === []): ?>
  <p class="muted">Nothing was opened in this window.</p>
<?php else: ?>
<table class="table">
  <thead><tr><th>Video</th><th>Views</th><th>Watched through</th></tr></thead>
  <tbody>
  <?php foreach ($report['videos'] as $row): ?>
    <tr>
      <td><a href="<?= e(url('/watch/' . $row['slug'])) ?>"><?= e($row['title']) ?></a></td>
      <td><?= e(number_format($row['views'])) ?></td>
      <td><?php if ($row['watchedThrough'] === null): ?><span class="muted">—</span><?php else: ?><?= e($row['watchedThrough'] . '%') ?><?php endif ?></td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
<p class="small muted">The share of this window’s players that reached the end. A video no player reported on is left blank rather than shown as 0%: “nobody finished it” and “nobody told us” are different answers.</p>
<?php endif ?>

<h2>Most opened hymns</h2>
<?php if ($report['hymns'] === []): ?>
  <p class="muted">No hymn was opened in this window. They are counted when one is really opened — its own page, a book opened at its number, or put on the screen.</p>
<?php else: ?>
<table class="table">
  <thead><tr><th>Hymn</th><th>Times</th></tr></thead>
  <tbody>
  <?php foreach ($report['hymns'] as $row): ?>
    <tr>
      <td><?= e($row['title']) ?></td>
      <td><?= e(number_format($row['lookups'])) ?></td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
<?php endif ?>
