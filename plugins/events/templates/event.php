<?php
/**
 * One event, and its sign-up.
 *
 * @var \App\Core\View $v
 * @var array<string, mixed> $event
 * @var bool $signedIn
 * @var ?array<string, mixed> $me
 * @var ?string $series the repeat in words, when this is one date of one
 * @var list<array{title: string, href: string, when: string}> $siblings
 * @var string $script
 */
$open = in_array($event['state'], ['OPEN', 'WAITLIST'], true);
?>
<p><a href="<?= e(url('/events')) ?>">← <?= e(t('events.title')) ?></a></p>
<h1><?= e((string) $event['title']) ?></h1>
<p class="muted"><?= e((string) $event['when']) ?><?php if ($event['location'] !== null && $event['location'] !== ''): ?> · <?= e((string) $event['location']) ?><?php endif ?></p>
<?php if (!$event['memberOnly']): ?>
  <p><a class="button small" href="<?= e(url('/events/' . $event['slug'] . '/event.ics')) ?>"><?= e(t('events.addToCalendar')) ?></a></p>
<?php endif ?>
<?php if ($event['description'] !== null && $event['description'] !== ''): ?>
  <div class="stack"><?php foreach (preg_split('/\n{2,}/', (string) $event['description']) ?: [] as $para): ?><p><?= e(trim((string) $para)) ?></p><?php endforeach ?></div>
<?php endif ?>
<?php if ($series !== null): ?>
  <p class="small muted"><?= e(t('events.repeats', ['rule' => $series])) ?></p>
<?php endif ?>

<?php if ($event['state'] !== 'NONE'): ?>
  <section class="card" data-event="<?= e((string) $event['slug']) ?>" data-labels="<?= e(\App\Core\View::json(['cancelConfirm' => t('events.cancelConfirm')])) ?>">
    <h2><?= e(t('events.signUp')) ?></h2>
    <p><?= e(t('events.state.' . strtolower((string) $event['state']))) ?><?php if ($event['placesMessage'] !== ''): ?> · <?= e((string) $event['placesMessage']) ?><?php endif ?></p>
    <?php if ($event['mine'] !== null): ?>
      <p class="notice ok" role="status"><?= e(t($event['mine']['status'] === 'WAITLIST' ? 'events.youAreWaiting' : 'events.youAreGoing')) ?></p>
      <div><button class="button small danger" type="button" data-event-cancel><?= e(t('events.cancel')) ?></button></div>
    <?php elseif ($open): ?>
      <form class="stack" data-event-form>
        <label><?= e(t('events.yourName')) ?><input name="name" required maxlength="255" autocomplete="name" value="<?= e((string) ($me['name'] ?? '')) ?>"></label>
        <label><?= e(t('events.yourEmail')) ?><input name="email" type="email" required maxlength="255" autocomplete="email" value="<?= e((string) ($me['email'] ?? '')) ?>"></label>
        <label><?= e(t('events.yourPhone')) ?><input name="phone" maxlength="64" autocomplete="tel"></label>
        <?php if ((int) $event['maxGuests'] > 0): ?>
          <label><?= e(t('events.guests', ['count' => (string) $event['maxGuests']])) ?><input name="guests" type="number" min="0" max="<?= e((string) $event['maxGuests']) ?>" value="0" data-type="int"></label>
        <?php endif ?>
        <label><?= e(t('events.anythingElse')) ?><textarea name="note" rows="2" maxlength="1000"></textarea></label>
        <label class="hp" aria-hidden="true">Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
        <div><button class="button primary" type="submit"><?= e(t($event['state'] === 'WAITLIST' ? 'events.joinWaitingList' : 'events.signUp')) ?></button></div>
        <p class="error" data-error hidden></p>
      </form>
    <?php endif ?>
  </section>
<?php endif ?>

<?php if ($siblings !== []): ?>
  <h2><?= e(t('events.nextDates')) ?></h2>
  <ul class="plain">
    <?php foreach ($siblings as $next): ?>
      <li><a href="<?= e(url($next['href'])) ?>"><?= e($next['when']) ?></a></li>
    <?php endforeach ?>
  </ul>
<?php endif ?>
<script type="module" src="<?= e($script) ?>"></script>
