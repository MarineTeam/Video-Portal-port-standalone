<?php
/**
 * @var \App\Core\View $v
 * @var list<array<string, mixed>> $rows newest first
 * @var list<array<string, mixed>> $series
 * @var list<string> $shapes
 * @var string $zone the site's own time zone, which the repeats are read in
 */
$shapeLabels = [
    'WEEKLY' => 'Weekly, on the days you pick',
    'DAILY' => 'Daily',
    'MONTHLY_DATE' => 'Monthly, on the same date',
    'MONTHLY_NTH' => 'Monthly, on the same weekday of the month',
    'MONTHLY_LAST' => 'Monthly, on the last such weekday',
];
?>
<h1>Events</h1>
<p class="muted">What's on, and who is coming. Sign-up is a switch: plenty of events are worth publishing with nothing to fill in. A members-only event is invisible to anybody not signed in, rather than refused.</p>

<h2>Add an event</h2>
<form class="stack card narrow" data-api="/api/admin/events" data-method="POST">
  <?= $v->partial('events/admin-fields', ['row' => []]) ?>
  <div><button class="button primary" type="submit">Add event</button></div>
  <p class="error" data-error hidden></p>
</form>

<h2>Something that repeats</h2>
<p class="muted">A rule, kept filled in six months ahead by a daily job. Times are read in <?= e($zone) ?>. Every date it produces is an ordinary event with its own page and its own list.</p>
<form class="stack card narrow" data-api="/api/admin/events/series" data-method="POST">
  <label>Title<input name="title" required maxlength="255"></label>
  <label>Where<input name="location" maxlength="500" data-null></label>
  <label>Repeat<select name="shape"><?php foreach ($shapes as $shape): ?><option value="<?= e($shape) ?>"><?= e($shapeLabels[$shape] ?? $shape) ?></option><?php endforeach ?></select></label>
  <fieldset class="row">
    <legend>On these days (weekly)</legend>
    <?php foreach (['MO' => 'Mon', 'TU' => 'Tue', 'WE' => 'Wed', 'TH' => 'Thu', 'FR' => 'Fri', 'SA' => 'Sat', 'SU' => 'Sun'] as $code => $label): ?>
      <label class="check"><input type="checkbox" name="days" value="<?= e($code) ?>" data-type="multi"> <?= e($label) ?></label>
    <?php endforeach ?>
  </fieldset>
  <div class="row">
    <label>Every<input name="interval" type="number" min="1" max="52" value="1" data-type="int"></label>
    <label>First date<input name="startDate" type="date" required></label>
    <label>Time<input name="startTime" placeholder="19:30" maxlength="5"></label>
    <label>Lasts (minutes)<input name="durationMinutes" type="number" min="0" max="10080" data-type="int" data-null></label>
  </div>
  <div class="row">
    <label>Stop after (times)<input name="count" type="number" min="1" max="500" data-type="int" data-null></label>
    <label>or on<input name="until" type="date" data-null></label>
  </div>
  <div class="row">
    <label class="check"><input type="checkbox" name="allDay"> All day</label>
    <label class="check"><input type="checkbox" name="published"> Published</label>
    <label class="check"><input type="checkbox" name="memberOnly"> Members only</label>
    <label class="check"><input type="checkbox" name="registration"> Takes sign-up</label>
    <label class="check"><input type="checkbox" name="waitlist" checked> Waiting list</label>
  </div>
  <div class="row">
    <label>Places<input name="capacity" type="number" min="0" data-type="int" data-null></label>
    <label>Guests each<input name="maxGuests" type="number" min="0" max="50" value="0" data-type="int"></label>
    <label>Sign-up opens (days before)<input name="opensDaysBefore" type="number" min="0" max="365" data-type="int" data-null></label>
    <label>and closes (days before)<input name="closesDaysBefore" type="number" min="0" max="365" data-type="int" data-null></label>
  </div>
  <div><button class="button primary" type="submit">Add the repeat</button></div>
  <p class="error" data-error hidden></p>
</form>

<?php foreach ($series as $s): ?>
  <details class="card">
    <summary><?= e((string) $s['title']) ?> <span class="badge muted"><?= e((string) $s['describe']) ?></span> <span class="small muted"><?= e((string) $s['dates']) ?> dates</span></summary>
    <form class="stack narrow" data-api="/api/admin/events/series/<?= e((string) $s['id']) ?>" data-method="PATCH">
      <label>Title<input name="title" required maxlength="255" value="<?= e((string) $s['title']) ?>"></label>
      <label>Where<input name="location" maxlength="500" data-null value="<?= e((string) ($s['location'] ?? '')) ?>"></label>
      <div class="row">
        <label class="check"><input type="checkbox" name="published"<?= $s['published'] ? ' checked' : '' ?>> Published</label>
        <label class="check"><input type="checkbox" name="memberOnly"<?= $s['memberOnly'] ? ' checked' : '' ?>> Members only</label>
      </div>
      <p class="small muted">Editing never rewrites the past: a title corrected in March renames every date still to come and leaves February's alone.</p>
      <div class="row">
        <button class="button primary" type="submit">Save</button>
        <button type="button" class="button danger" data-api="/api/admin/events/series/<?= e((string) $s['id']) ?>" data-method="DELETE" data-confirm="Stop this repeating? Dates nobody has signed up for are removed; anything past or booked stays as a one-off.">Stop repeating</button>
      </div>
      <p class="error" data-error hidden></p>
    </form>
  </details>
<?php endforeach ?>

<h2>The diary</h2>
<?php foreach ($rows as $row): ?>
  <details class="card">
    <summary>
      <?= e((string) $row['title']) ?>
      <span class="badge<?= $row['published'] ? '' : ' muted' ?>"><?= e($row['published'] ? 'published' : 'draft') ?></span>
      <?php if ($row['memberOnly']): ?><span class="badge muted">members only</span><?php endif ?>
      <span class="small muted"><?= e((string) $row['when']) ?></span>
      <?php if ($row['registration']): ?><span class="small muted"><?= e((string) $row['going']) ?> going<?php if ((int) $row['waiting'] > 0): ?>, <?= e((string) $row['waiting']) ?> waiting<?php endif ?></span><?php endif ?>
    </summary>
    <form class="stack narrow" data-api="/api/admin/events/<?= e((string) $row['id']) ?>" data-method="PATCH">
      <?= $v->partial('events/admin-fields', ['row' => $row]) ?>
      <div class="row">
        <button class="button primary" type="submit">Save</button>
        <a class="button small" href="<?= e(url('/admin/events/' . $row['id'])) ?>">Who is coming</a>
        <a class="button small" href="<?= e(url('/api/admin/events/' . $row['id'] . '/registrations?format=csv')) ?>">The list for the door (CSV)</a>
        <button type="button" class="button danger" data-api="/api/admin/events/<?= e((string) $row['id']) ?>" data-method="DELETE" data-confirm="Delete this event and everybody's place on it?">Delete</button>
      </div>
      <p class="error" data-error hidden></p>
    </form>
  </details>
<?php endforeach ?>
