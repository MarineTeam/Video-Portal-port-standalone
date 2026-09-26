<?php
/**
 * One date on the calendar. A signed-out reader's copy has no people key at
 * all, so there is nothing here to accidentally print.
 *
 * @var \App\Core\View $v
 * @var array<string, mixed> $event
 * @var list<array<string, mixed>> $schedules
 */
$rota = null;
foreach ($schedules as $one) {
    if ((string) $one['id'] === (string) $event['scheduleId']) {
        $rota = $one;
    }
}
$names = \MarineTeam\Plugins\Schedules\Logic::describeParticipants($event);
?>
<li class="small" data-event="<?= e((string) $event['id']) ?>" data-rota="<?= e((string) $event['scheduleId']) ?>"
    data-people="<?= e(implode(',', array_map(fn (array $p) => (string) ($p['personId'] ?? ''), (array) ($event['people'] ?? [])))) ?>">
  <?php if ($rota !== null): ?><span class="badge rota-<?= e((string) $rota['color']) ?>"><?= e((string) $rota['name']) ?></span><?php endif ?>
  <?php if (($event['startTime'] ?? null) !== null): ?><strong class="chapter-time"><?= e((string) $event['startTime']) ?></strong><?php endif ?>
  <?php if (($event['title'] ?? null) !== null && $event['title'] !== ''): ?><strong><?= e((string) $event['title']) ?></strong><?php endif ?>
  <?php if ($names !== ''): ?><span><?= e($names) ?></span><?php endif ?>
  <?php if (!empty($event['namesWithheld'])): ?><span class="muted"><?= e(t('schedules.namesNeedSignIn')) ?></span><?php endif ?>
  <?php if (($event['location'] ?? null) !== null && $event['location'] !== ''): ?><span class="muted">· <?= e((string) $event['location']) ?></span><?php endif ?>
  <?php if (($event['notes'] ?? null) !== null && $event['notes'] !== ''): ?><span class="muted">· <?= e((string) $event['notes']) ?></span><?php endif ?>
  <?php if ((string) ($event['status'] ?? '') === 'CANCELLED'): ?><span class="badge muted"><?= e(t('schedules.cancelled')) ?></span><?php endif ?>
</li>
