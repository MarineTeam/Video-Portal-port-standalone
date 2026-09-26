<?php
/**
 * One rota: where it comes from, and the dates themselves.
 *
 * Test connection shows the first few events exactly as the parser read
 * them, and every row it skipped and why, before anything is imported — a
 * column mapping is guesswork until you can see what it made of the sheet.
 *
 * @var \App\Core\View $v
 * @var array<string, mixed> $schedule
 * @var list<array<string, mixed>> $events
 * @var list<string> $colors
 * @var list<string> $formats
 * @var bool $keySet
 * @var string $script
 */
$source = $schedule['source'];
?>
<p><a href="<?= e(url('/admin/schedules')) ?>">← <?= e(t('schedules.admin')) ?></a></p>
<h1><?= e((string) $schedule['name']) ?></h1>

<section class="card" data-schedule="<?= e((string) $schedule['id']) ?>"
         data-labels="<?= e(\App\Core\View::json([
             'deleteDate' => t('schedules.deleteDate'),
             'deleteSchedule' => t('schedules.deleteSchedule'),
             'unchanged' => t('schedules.unchanged'),
             'wholeSheet' => t('schedules.wholeSheet'),
         ])) ?>">
  <form class="stack" data-schedule-edit>
    <label><?= e(t('schedules.name')) ?><input name="name" value="<?= e((string) $schedule['name']) ?>" required maxlength="255"></label>
    <label><?= e(t('schedules.description')) ?><textarea name="description" rows="2" maxlength="2000" data-null><?= e((string) ($schedule['description'] ?? '')) ?></textarea></label>
    <label><?= e(t('schedules.colour')) ?>
      <select name="color">
        <?php foreach ($colors as $color): ?>
          <option value="<?= e($color) ?>"<?php if ($color === $schedule['color']): ?> selected<?php endif ?>><?= e($color) ?></option>
        <?php endforeach ?>
      </select>
    </label>
    <label><input type="checkbox" name="enabled" data-type="bool"<?php if ($schedule['enabled']): ?> checked<?php endif ?>> <?= e(t('schedules.enabled')) ?></label>
    <div class="row">
      <button class="button primary" type="submit"><?= e(t('common.save')) ?></button>
      <button class="button small danger" type="button" data-api="/api/admin/schedules/<?= e((string) $schedule['id']) ?>" data-method="DELETE" data-confirm="<?= e(t('schedules.deleteSchedule')) ?>" data-redirect="/admin/schedules"><?= e(t('common.delete')) ?></button>
    </div>
    <p class="error" data-error hidden></p>
  </form>
</section>

<section class="card" data-schedule-source>
  <h2><?= e(t('schedules.fromASheet')) ?></h2>
  <?php if (!$keySet): ?>
    <p class="notice warn"><?= e(t('schedules.noKey')) ?> <a href="<?= e(url('/admin/schedules')) ?>"><?= e(t('schedules.key')) ?></a></p>
  <?php endif ?>
  <form class="stack" data-source-form>
    <label><?= e(t('schedules.spreadsheet')) ?><input name="spreadsheetId" value="<?= e((string) ($source['spreadsheetId'] ?? '')) ?>" maxlength="191" spellcheck="false"></label>
    <label><?= e(t('schedules.sheetName')) ?><input name="sheetName" value="<?= e((string) ($source['sheetName'] ?? '')) ?>" maxlength="191" data-null></label>
    <label><?= e(t('schedules.range')) ?><input name="range" value="<?= e((string) ($source['range'] ?? '')) ?>" maxlength="64" placeholder="A:F" data-null></label>
    <label><?= e(t('schedules.format')) ?>
      <select name="format">
        <?php foreach ($formats as $format): ?>
          <option value="<?= e($format) ?>"<?php if ($format === ($source['format'] ?? '')): ?> selected<?php endif ?>><?= e(t('schedules.format.' . $format)) ?></option>
        <?php endforeach ?>
      </select>
    </label>
    <label><?= e(t('schedules.syncEvery')) ?><input name="syncIntervalMinutes" type="number" min="15" max="10080" value="<?= e((string) ($source['syncIntervalMinutes'] ?? 1440)) ?>" data-type="int"></label>
    <div class="row">
      <button class="button" type="button" data-source-test><?= e(t('schedules.testConnection')) ?></button>
      <button class="button primary" type="submit"><?= e(t('common.save')) ?></button>
      <?php if ($source !== null): ?>
        <button class="button" type="button" data-source-sync><?= e(t('schedules.syncNow')) ?></button>
      <?php endif ?>
    </div>
    <p class="error" data-error hidden></p>
  </form>
  <?php if ($source !== null): ?>
    <p class="small muted">
      <?= e(t('schedules.lastSynced')) ?>:
      <?php if ($source['lastSyncedAt'] === null): ?><?= e(t('schedules.never')) ?><?php else: ?><time datetime="<?= e((string) $source['lastSyncedAt']) ?>" data-local-time><?= e((string) $source['lastSyncedAt']) ?></time> — <?= e((string) $source['lastSyncStatus']) ?><?php endif ?>
    </p>
    <?php if (($source['lastSyncError'] ?? null) !== null): ?>
      <p class="notice warn small"><?= e((string) $source['lastSyncError']) ?></p>
    <?php endif ?>
  <?php endif ?>
  <div data-source-result hidden></div>
</section>

<section class="card">
  <h2><?= e(t('schedules.addDate')) ?></h2>
  <form class="stack" data-event-new>
    <label><?= e(t('schedules.date')) ?><input name="date" type="date" required></label>
    <label><?= e(t('schedules.startTime')) ?><input name="startTime" type="time" data-null></label>
    <label><?= e(t('schedules.eventTitle')) ?><input name="title" maxlength="255" data-null></label>
    <label><?= e(t('schedules.location')) ?><input name="location" maxlength="500" data-null></label>
    <label><?= e(t('schedules.whoIsOn')) ?><textarea name="people" rows="3" data-type="multi" placeholder="<?= e(t('schedules.whoIsOnHint')) ?>"></textarea></label>
    <div><button class="button primary" type="submit"><?= e(t('common.save')) ?></button></div>
    <p class="error" data-error hidden></p>
  </form>
</section>

<section class="card">
  <h2><?= e(t('schedules.dates', ['count' => (string) count($events)])) ?></h2>
  <ul class="plain" data-event-list>
    <?php foreach ($events as $event): ?>
      <li class="row small" data-event="<?= e((string) $event['id']) ?>">
        <time datetime="<?= e((string) $event['date']) ?>" data-local-date><?= e((string) $event['date']) ?></time>
        <?php if ($event['startTime'] !== null): ?><span class="chapter-time"><?= e((string) $event['startTime']) ?></span><?php endif ?>
        <?php if (($event['title'] ?? null) !== null): ?><strong><?= e((string) $event['title']) ?></strong><?php endif ?>
        <span><?= e(\MarineTeam\Plugins\Schedules\Logic::describeParticipants($event)) ?></span>
        <?php if (($event['location'] ?? null) !== null): ?><span class="muted"><?= e((string) $event['location']) ?></span><?php endif ?>
        <?php if ((string) $event['status'] === 'CANCELLED'): ?><span class="badge muted"><?= e(t('schedules.cancelled')) ?></span><?php endif ?>
        <button class="button small danger" type="button" data-api="/api/admin/calendar-events/<?= e((string) $event['id']) ?>" data-method="DELETE" data-confirm="<?= e(t('schedules.deleteDate')) ?>"><?= e(t('common.delete')) ?></button>
      </li>
    <?php endforeach ?>
  </ul>
</section>
<script type="module" src="<?= e($script) ?>"></script>
