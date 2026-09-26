<?php
/**
 * The fields an event has, for both the add form and each edit form.
 *
 * @var \App\Core\View $v
 * @var array<string, mixed> $row empty when adding
 */
$value = static fn (string $key, string $fallback = ''): string => (string) ($row[$key] ?? $fallback);
$on = static fn (string $key, bool $fallback = false): bool => $row === [] ? $fallback : (bool) ($row[$key] ?? false);
?>
<label>Title<input name="title" required maxlength="255" value="<?= e($value('title')) ?>"></label>
<label>Where<input name="location" maxlength="500" data-null value="<?= e($value('location')) ?>"></label>
<label>About<textarea name="description" rows="3" maxlength="20000" data-null><?= e($value('description')) ?></textarea></label>
<div class="row">
  <label>Starts<input type="datetime-local" name="startsAt" data-type="datetime" required data-iso="<?= e($value('startsAt')) ?>"></label>
  <label>Ends<input type="datetime-local" name="endsAt" data-type="datetime" data-null data-iso="<?= e($value('endsAt')) ?>"></label>
</div>
<div class="row">
  <label class="check"><input type="checkbox" name="allDay"<?= $on('allDay') ? ' checked' : '' ?>> All day</label>
  <label class="check"><input type="checkbox" name="published"<?= $on('published') ? ' checked' : '' ?>> Published</label>
  <label class="check"><input type="checkbox" name="memberOnly"<?= $on('memberOnly') ? ' checked' : '' ?>> Members only</label>
  <label class="check"><input type="checkbox" name="registration"<?= $on('registration') ? ' checked' : '' ?>> Takes sign-up</label>
  <label class="check"><input type="checkbox" name="waitlist"<?= $on('waitlist', true) ? ' checked' : '' ?>> Waiting list when full</label>
</div>
<div class="row">
  <label>Places<input name="capacity" type="number" min="0" data-type="int" data-null value="<?= e($value('capacity')) ?>"></label>
  <label>Guests each may bring<input name="maxGuests" type="number" min="0" max="50" data-type="int" value="<?= e($value('maxGuests', '0')) ?>"></label>
</div>
<div class="row">
  <label>Sign-up opens<input type="datetime-local" name="opensAt" data-type="datetime" data-null data-iso="<?= e($value('opensAt')) ?>"></label>
  <label>and closes<input type="datetime-local" name="closesAt" data-type="datetime" data-null data-iso="<?= e($value('closesAt')) ?>"></label>
</div>
