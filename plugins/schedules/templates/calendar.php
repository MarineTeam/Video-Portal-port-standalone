<?php
/**
 * The rota, for people with no account: which rotas, what days, what notes.
 * Who is on them needs a sign-in, and a signed-out reader is handed events
 * that have nobody on them rather than names to hide.
 *
 * @var \App\Core\View $v
 * @var list<array<string, mixed>> $schedules
 * @var list<array{day: string, events: list<array<string, mixed>>}> $days
 * @var list<array<string, mixed>> $people
 * @var bool $signedIn
 * @var string $today
 * @var string $script
 */
?>
<h1><?= e(t('schedules.title')) ?></h1>

<div class="calendar" data-calendar data-today="<?= e($today) ?>" data-signed-in="<?= e($signedIn ? '1' : '0') ?>"
     data-labels="<?= e(\App\Core\View::json([
         'today' => t('schedules.today'),
         'tomorrow' => t('schedules.tomorrow'),
         'nothing' => t('schedules.nothingComingUp'),
         'removeConfirm' => t('schedules.removeFromDevice'),
     ])) ?>">
  <?php if (count($schedules) > 1): ?>
    <div class="row chips" role="group" aria-label="<?= e(t('schedules.allRotas')) ?>">
      <button type="button" class="chip on" data-rota="" aria-pressed="true"><?= e(t('schedules.allRotas')) ?></button>
      <?php foreach ($schedules as $schedule): ?>
        <button type="button" class="chip rota-<?= e((string) $schedule['color']) ?>" data-rota="<?= e((string) $schedule['id']) ?>" aria-pressed="false"><?= e((string) $schedule['name']) ?></button>
      <?php endforeach ?>
    </div>
  <?php endif ?>

  <p class="row small">
    <?php if ($signedIn): ?>
      <label><?= e(t('schedules.youAre')) ?>
        <select data-calendar-person>
          <option value=""><?= e(t('schedules.everyone')) ?></option>
          <?php foreach ($people as $person): ?>
            <option value="<?= e((string) $person['id']) ?>"><?= e((string) $person['displayName']) ?></option>
          <?php endforeach ?>
        </select>
      </label>
      <label class="small"><input type="checkbox" data-calendar-mine> <?= e(t('schedules.onlyMine')) ?></label>
    <?php else: ?>
      <span class="muted"><?= e(t('schedules.chooseNameNeedsSignIn')) ?></span>
    <?php endif ?>
    <button type="button" class="button small" data-calendar-keep hidden><?= e(t('schedules.keepOnDevice')) ?></button>
    <span class="small muted" data-calendar-saved hidden></span>
  </p>

  <ol class="plain" data-calendar-days>
    <?php foreach ($days as $day): ?>
      <li class="card" data-day="<?= e($day['day']) ?>">
        <h2 class="small"><time datetime="<?= e($day['day']) ?>" data-local-date><?= e($day['day']) ?></time></h2>
        <ul class="plain">
          <?php foreach ($day['events'] as $event): ?>
            <?= $v->partial('schedules/event', ['event' => $event, 'schedules' => $schedules]) ?>
          <?php endforeach ?>
        </ul>
      </li>
    <?php endforeach ?>
  </ol>
  <?php if ($days === []): ?><p class="muted"><?= e(t('schedules.nothingComingUp')) ?></p><?php endif ?>
  <?php if (!$signedIn): ?><p class="small muted"><?= e(t('schedules.namesNeedSignIn')) ?></p><?php endif ?>
</div>
<script type="module" src="<?= e($script) ?>"></script>
