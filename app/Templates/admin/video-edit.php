<?php
/**
 * @var \App\Core\View $v
 * @var array<string, mixed> $video presented, with providerLabel and tracks
 * @var list<array{id: string, title: string}> $series
 * @var list<array<string, mixed>> $categories
 * @var list<array{id: string, name: string}> $speakers
 * @var bool $canPublish
 * @var array{users: list<array<string, mixed>>, groups: list<array<string, mixed>>} $viewers
 * @var list<array{id: string, name: string}> $groups
 * @var string $thumbnail
 * @var bool $hasCaptionOps
 * @var ?array<string, mixed> $player
 * @var list<array<string, mixed>> $chapters in time order
 */
$api = '/api/admin/videos/' . $video['id'];
?>
<p><a href="<?= e(url('/admin/videos')) ?>">← Videos</a></p>
<h1><?= e($video['title']) ?></h1>
<p class="small muted">
  <?= e($video['providerLabel']) ?>
  <?php if ($video['externalUrl'] ?? null): ?> · <a href="<?= e((string) $video['externalUrl']) ?>" rel="noopener noreferrer" target="_blank">original</a><?php endif ?>
  · <?= e(match ($video['status']) { 'READY' => 'Ready', 'FAILED' => 'Failed', default => 'Processing' }) ?>
  <?php if ($video['durationSeconds'] !== null): ?> · <?= e(sprintf('%d:%02d', intdiv((int) $video['durationSeconds'], 60), (int) $video['durationSeconds'] % 60)) ?><?php endif ?>
  <button type="button" class="link" data-api="<?= e($api) ?>/sync-status" data-method="POST">Check with <?= e($video['providerLabel']) ?></button>
</p>

<?php if ($player !== null): ?>
  <div class="player narrow" data-player="<?= e(\App\Core\View::json($player)) ?>" data-title="<?= e($video['title']) ?>"></div>
<?php endif ?>
<form class="card stack narrow" data-api="<?= e($api) ?>" data-method="PATCH" data-done="Saved." data-no-reload>
  <label>Title<input name="title" value="<?= e($video['title']) ?>" required maxlength="255"></label>
  <label>Address<input name="slug" value="<?= e($video['slug']) ?>" pattern="[a-z0-9]+(-[a-z0-9]+)*" maxlength="80"></label>
  <p class="small muted"><?= e(url('/videos/' . $video['slug'])) ?> — changing it keeps the old address working.</p>
  <div class="row">
    <label>Series
      <select name="seriesId" data-null>
        <option value="">— none —</option>
        <?php foreach ($series as $s): ?>
          <option value="<?= e($s['id']) ?>"<?= $video['seriesId'] === $s['id'] ? ' selected' : '' ?>><?= e($s['title']) ?></option>
        <?php endforeach ?>
      </select>
    </label>
    <label>Category (when in no series)
      <select name="categoryId" data-null>
        <option value="">— none —</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= e($c['id']) ?>"<?= $video['categoryId'] === $c['id'] ? ' selected' : '' ?>><?= e(str_repeat('— ', (int) $c['depth']) . $c['name']) ?></option>
        <?php endforeach ?>
      </select>
    </label>
  </div>
  <label>Speaker
    <select name="speakerId" data-null>
      <option value="">— none —</option>
      <?php foreach ($speakers as $s): ?>
        <option value="<?= e($s['id']) ?>"<?= $video['speakerId'] === $s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option>
      <?php endforeach ?>
    </select>
  </label>
  <label>Description<textarea name="description" rows="6" data-null><?= e((string) ($video['description'] ?? '')) ?></textarea></label>
  <label>Scripture (one per line or comma separated, e.g. John 3:16)<textarea name="scriptureRefs" rows="2" data-type="list"><?= e(implode("\n", (array) ($video['scriptureRefs'] ?? []))) ?></textarea></label>
  <label>Language (e.g. en, es)<input name="language" value="<?= e((string) ($video['language'] ?? '')) ?>" maxlength="35" data-null></label>
  <?= $v->partial('partials/admin-publishing', ['item' => $video, 'canPublish' => $canPublish, 'downloads' => true]) ?>
  <label class="check"><input type="checkbox" name="isPremiere"<?= $video['isPremiere'] ? ' checked' : '' ?>> Premiere — shown as upcoming until its publish time</label>
  <details>
    <summary>Notes and transcript</summary>
    <label>Sermon notes<textarea name="noteOutline" rows="8" data-null><?= e((string) ($video['noteOutline'] ?? '')) ?></textarea></label>
    <label>Transcript<textarea name="transcript" rows="8" data-null><?= e((string) ($video['transcript'] ?? '')) ?></textarea></label>
  </details>
  <div><button class="button primary" type="submit">Save</button></div>
  <p class="small" data-done-message hidden></p>
  <p class="error" data-error hidden></p>
</form>

<h2>Thumbnail</h2>
<div class="card stack narrow">
  <?php if ($thumbnail !== ''): ?><img src="<?= e($thumbnail) ?>" alt="" class="thumb-preview" loading="lazy"><?php endif ?>
  <?php if (in_array($video['provider'], ['youtube', 'vimeo'], true)): ?>
    <p class="small muted">The thumbnail comes from <?= e($video['providerLabel']) ?>; change it there.</p>
  <?php else: ?>
    <label>New thumbnail (JPEG, PNG or WebP)<input type="file" accept="image/png,image/jpeg,image/webp" data-video-thumbnail="<?= e($api) ?>/thumbnail"></label>
  <?php endif ?>
</div>

<h2>Captions</h2>
<div class="card stack narrow">
  <?php if ($video['tracks'] !== [] || $hasCaptionOps): ?>
    <ul class="plain" data-captions="<?= e($api) ?>/captions">
      <?php foreach ($video['tracks'] as $t): ?>
        <li><?= e($t['label']) ?> <span class="small muted">(<?= e($t['srclang']) ?>)</span>
          <button type="button" class="link" data-api="<?= e($api) ?>/captions?srclang=<?= e(rawurlencode($t['srclang'])) ?>" data-method="DELETE" data-confirm="Remove these captions?">Remove</button></li>
      <?php endforeach ?>
    </ul>
  <?php endif ?>
  <form class="stack" data-video-captions="<?= e($api) ?>/captions">
    <div class="row">
      <label>Language code<input name="srclang" required maxlength="12" pattern="[a-z]{2,3}(-[A-Za-z0-9]{2,8})?" placeholder="en"></label>
      <label>Label<input name="label" required maxlength="60" placeholder="English"></label>
    </div>
    <label>File (.vtt or .srt)<input type="file" name="file" accept=".vtt,.srt,text/vtt" required></label>
    <div><button class="button small" type="submit">Add captions</button></div>
    <p class="error" data-error hidden></p>
  </form>
</div>

<h2>Chapters</h2>
<div class="card stack">
  <p class="small muted">Named moments listed under the player when the Chapters plugin is on. Each one jumps the player there and has its own share link.</p>
  <?php foreach ($chapters as $ch): ?>
    <form class="row" data-api="/api/admin/videos/chapters/<?= e($ch['id']) ?>" data-method="PATCH">
      <input name="timestamp" aria-label="Starts at" value="<?= e(\App\Support\Timestamp::format((int) $ch['timestampSeconds'])) ?>" size="8" required pattern="[0-9:hms]+">
      <input name="title" aria-label="Chapter title" value="<?= e($ch['title']) ?>" required maxlength="255">
      <button class="button small" type="submit">Save</button>
      <button type="button" class="button small danger" data-api="/api/admin/videos/chapters/<?= e($ch['id']) ?>" data-method="DELETE" data-confirm="Remove the chapter “<?= e($ch['title']) ?>”?">Remove</button>
      <p class="error" data-error hidden></p>
    </form>
  <?php endforeach ?>
  <form class="row" data-api="<?= e($api) ?>/chapters" data-method="POST">
    <label>Starts at<input name="timestamp" size="8" required pattern="[0-9:hms]+" placeholder="12:03" data-chapter-time></label>
    <?php if ($player !== null): ?><button type="button" class="button small" data-chapter-now>Now</button><?php endif ?>
    <label>Title<input name="title" required maxlength="255" placeholder="The sermon"></label>
    <button class="button small primary" type="submit">Add chapter</button>
    <p class="error" data-error hidden></p>
  </form>
</div>

<?= $v->partial('partials/admin-viewers', ['path' => 'videos', 'id' => $video['id'], 'noun' => 'video', 'viewers' => $viewers, 'groups' => $groups]) ?>
<p><button type="button" class="button danger" data-api="<?= e($api) ?>" data-method="DELETE" data-redirect="/admin/videos" data-confirm="Move this video to the trash?">Delete video</button></p>
<script type="module" src="<?= e(asset('js/library-admin.js')) ?>"></script>
<script type="module" src="<?= e(asset('js/player.js')) ?>"></script>
