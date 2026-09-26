<?php
/**
 * /admin/services/report — what we sang, in the shape a licence return asks for.
 *
 * @var \App\Core\View $v
 * @var string $from
 * @var string $to
 * @var list<array<string, mixed>> $songs
 */
?>
<p><a href="<?= e(url('/admin/services')) ?>">← Service plans</a></p>
<h1>What we sang</h1>
<form class="row card" method="get" action="<?= e(url('/admin/services/report')) ?>">
  <label>From<input type="date" name="from" value="<?= e($from) ?>"></label>
  <label>To<input type="date" name="to" value="<?= e($to) ?>"></label>
  <button class="button primary" type="submit">Show</button>
  <a class="button small" href="<?= e(url('/api/admin/services/report', ['from' => $from, 'to' => $to, 'format' => 'csv'])) ?>">CSV</a>
</form>
<?php if ($songs === []): ?>
  <p class="notice">Nothing was sung in that window.</p>
<?php else: ?>
  <table class="table">
    <thead><tr><th>Song</th><th>Number</th><th>CCLI</th><th>Author</th><th>Copyright</th><th>Services</th></tr></thead>
    <tbody>
      <?php foreach ($songs as $song): ?>
        <tr>
          <td><?= e((string) $song['title']) ?></td>
          <td><?= e($song['number'] === null ? '' : (string) $song['number']) ?></td>
          <td><?= e((string) ($song['ccli'] ?? '')) ?></td>
          <td class="small"><?= e((string) ($song['author'] ?? '')) ?></td>
          <td class="small"><?= e((string) ($song['copyright'] ?? '')) ?></td>
          <td><?= e((string) $song['services']) ?> <span class="small muted"><?= e(implode(', ', array_slice($song['dates'], 0, 6))) ?></span></td>
        </tr>
      <?php endforeach ?>
    </tbody>
  </table>
<?php endif ?>
