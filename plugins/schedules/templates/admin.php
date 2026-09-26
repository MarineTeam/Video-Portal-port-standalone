<?php
/**
 * The rotas, in the order the calendar shows them, and the one Google key
 * every sheet is read with.
 *
 * @var \App\Core\View $v
 * @var list<array<string, mixed>> $schedules
 * @var bool $keySet
 * @var list<string> $colors
 * @var string $script
 */
?>
<h1><?= e(t('schedules.admin')) ?></h1>
<p class="small muted"><a href="<?= e(url('/admin/people')) ?>"><?= e(t('schedules.people')) ?></a> · <a href="<?= e(url('/calendar')) ?>"><?= e(t('schedules.title')) ?></a></p>

<section class="card" data-schedules>
  <ol class="plain" data-schedule-list>
    <?php foreach ($schedules as $schedule): ?>
      <li class="row" data-schedule="<?= e((string) $schedule['id']) ?>">
        <span class="badge rota-<?= e((string) $schedule['color']) ?>">&nbsp;</span>
        <a href="<?= e(url('/admin/schedules/' . $schedule['id'])) ?>"><strong><?= e((string) $schedule['name']) ?></strong></a>
        <span class="small muted"><?= e(t('schedules.dates', ['count' => (string) $schedule['eventCount']])) ?></span>
        <span class="small muted"><?= e($schedule['sourceType'] === 'GOOGLE_SHEET' ? t('schedules.fromASheet') : t('schedules.managedHere')) ?></span>
        <?php if (!$schedule['enabled']): ?><span class="badge muted"><?= e(t('schedules.hidden')) ?></span><?php endif ?>
        <button class="button small" type="button" data-move="up" aria-label="↑">↑</button>
        <button class="button small" type="button" data-move="down" aria-label="↓">↓</button>
      </li>
    <?php endforeach ?>
  </ol>
  <p class="error" data-error hidden></p>
</section>

<section class="card">
  <h2><?= e(t('schedules.newSchedule')) ?></h2>
  <form class="stack" data-schedule-new>
    <label><?= e(t('schedules.name')) ?><input name="name" required maxlength="255"></label>
    <label><?= e(t('schedules.colour')) ?>
      <select name="color">
        <?php foreach ($colors as $color): ?><option value="<?= e($color) ?>"><?= e($color) ?></option><?php endforeach ?>
      </select>
    </label>
    <div><button class="button primary" type="submit"><?= e(t('common.save')) ?></button></div>
    <p class="error" data-error hidden></p>
  </form>
</section>

<section class="card">
  <h2><?= e(t('schedules.key')) ?></h2>
  <p class="small muted"><?= e(t('schedules.keyHint')) ?></p>
  <?php if ($keySet): ?>
    <p class="small"><?= e(t('schedules.keySet')) ?></p>
    <p><button class="button small danger" type="button" data-api="/api/admin/schedules/key" data-method="DELETE" data-confirm="<?= e(t('schedules.forgetKey')) ?>"><?= e(t('schedules.forgetKey')) ?></button></p>
  <?php endif ?>
  <form class="stack" data-schedule-key>
    <label><?= e(t('schedules.key')) ?><textarea name="key" rows="4" spellcheck="false"></textarea></label>
    <div><button class="button" type="submit"><?= e(t('common.save')) ?></button></div>
    <p class="error" data-error hidden></p>
  </form>
</section>
<script type="module" src="<?= e($script) ?>"></script>
