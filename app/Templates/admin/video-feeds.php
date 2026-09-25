<?php
/**
 * @var \App\Core\View $v
 * @var list<array<string, mixed>> $feeds
 * @var list<array{id: string, title: string}> $series
 * @var list<array<string, mixed>> $categories
 * @var bool $youtubeReady
 * @var bool $vimeoReady
 */
$kinds = ['YOUTUBE_CHANNEL' => 'YouTube channel', 'YOUTUBE_PLAYLIST' => 'YouTube playlist', 'VIMEO_USER' => 'Vimeo account', 'VIMEO_SHOWCASE' => 'Vimeo showcase'];
$where = function (array $feed) use ($series, $categories): string {
    foreach ($series as $s) {
        if ($s['id'] === $feed['seriesId']) {
            return 'Series: ' . $s['title'];
        }
    }
    foreach ($categories as $c) {
        if ($c['id'] === $feed['categoryId']) {
            return 'Category: ' . $c['name'];
        }
    }
    return 'Unfiled';
};
?>
<h1>Video feeds</h1>
<p class="small muted">Point at a YouTube channel or playlist, or a Vimeo account or showcase, and its newest videos come in every night as ordinary videos. Edits you make here stick: a later sync only updates a title or description nobody has changed. Removing a feed keeps the videos it brought in.</p>
<?php if (!$youtubeReady): ?><p class="notice warn">YouTube feeds need a Data API key under <a href="<?= e(url('/admin/providers/video/youtube')) ?>">Services → Video → YouTube</a>.</p><?php endif ?>
<?php if (!$vimeoReady): ?><p class="notice warn">Vimeo feeds need an access token under <a href="<?= e(url('/admin/providers/video/vimeo')) ?>">Services → Video → Vimeo</a>.</p><?php endif ?>

<form class="card stack narrow" data-api="/api/admin/video-feeds" data-method="POST">
  <h2>Add a feed</h2>
  <div class="row">
    <label>Kind
      <select name="kind"><?php foreach ($kinds as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach ?></select>
    </label>
    <label>Its id (channel UC…, playlist PL…, Vimeo user or showcase id)<input name="externalId" required maxlength="191" pattern="[A-Za-z0-9_-]+"></label>
  </div>
  <label>Name<input name="name" required maxlength="255" placeholder="Sunday livestreams"></label>
  <div class="row">
    <label>Into series<select name="seriesId" data-null><option value="">— none —</option><?php foreach ($series as $s): ?><option value="<?= e($s['id']) ?>"><?= e($s['title']) ?></option><?php endforeach ?></select></label>
    <label>or category<select name="categoryId" data-null><option value="">— none —</option><?php foreach ($categories as $c): ?><option value="<?= e($c['id']) ?>"><?= e(str_repeat('— ', (int) $c['depth']) . $c['name']) ?></option><?php endforeach ?></select></label>
  </div>
  <div class="row">
    <label class="check"><input type="checkbox" name="autoPublish"> Publish imports straight away</label>
    <label>Look back over the newest<input type="number" name="lookBack" value="25" min="1" max="50" data-type="int"></label>
  </div>
  <div><button class="button primary" type="submit">Add feed</button></div>
  <p class="error" data-error hidden></p>
</form>

<?php if ($feeds === []): ?>
  <p class="muted">No feeds yet.</p>
<?php else: ?>
<table class="table">
  <thead><tr><th>Feed</th><th>Goes to</th><th>Videos</th><th>Last sync</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($feeds as $f): ?>
    <tr>
      <td><?= e($f['name']) ?> <div class="small muted"><?= e($kinds[$f['kind']] ?? $f['kind']) ?> · <?= e($f['externalId']) ?></div>
        <?php if (!$f['enabled']): ?><span class="badge muted">Paused</span><?php endif ?>
        <?php if ($f['autoPublish']): ?><span class="badge">Publishes</span><?php endif ?></td>
      <td class="small"><?= e($where($f)) ?></td>
      <td><?= e((string) $f['videoCount']) ?></td>
      <td class="small">
        <?= e($f['lastSyncedAt'] !== null ? substr((string) $f['lastSyncedAt'], 0, 16) . ' UTC · ' . $f['lastSyncStatus'] : 'never') ?>
        <?php if ($f['missing'] !== null): ?><div class="error"><?= e((string) $f['missing']) ?></div><?php elseif (!empty($f['lastError'])): ?><div class="error"><?= e((string) $f['lastError']) ?></div><?php endif ?>
      </td>
      <td class="actions">
        <button type="button" class="button small" data-api="/api/admin/video-feeds/<?= e($f['id']) ?>/sync" data-method="POST">Sync now</button>
        <button type="button" class="button small" data-api="/api/admin/video-feeds/<?= e($f['id']) ?>" data-method="PATCH" data-body='<?= e(\App\Core\View::json(['enabled' => !$f['enabled']])) ?>'><?= e($f['enabled'] ? 'Pause' : 'Resume') ?></button>
        <button type="button" class="button small danger" data-api="/api/admin/video-feeds/<?= e($f['id']) ?>" data-method="DELETE" data-confirm="Remove this feed? The videos it brought in stay.">Remove</button>
      </td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
<?php endif ?>
