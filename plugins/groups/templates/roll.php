<?php
/**
 * The roll a leader writes up on the night.
 *
 * @var \App\Core\View $v
 * @var array<string, mixed> $group
 * @var list<array{userId: string, name: string}> $members the people who may be marked
 * @var list<array<string, mixed>> $guides
 * @var string $today
 */
?>
<form class="stack" data-group-roll>
  <div class="row">
    <label><?= e(t('groups.meetingDate')) ?><input type="date" name="date" required value="<?= e($today) ?>" max="<?= e($today) ?>"></label>
    <label><?= e(t('groups.topic')) ?><input name="topic" maxlength="500"></label>
    <label><?= e(t('groups.visitors')) ?><input type="number" name="visitorCount" min="0" max="500" value="0"></label>
    <label class="check"><input type="checkbox" name="cancelled"> <?= e(t('groups.cancelled')) ?></label>
  </div>
  <?php foreach ($members as $member): ?>
    <div class="row" data-roll-row="<?= e($member['userId']) ?>">
      <span><?= e($member['name']) ?></span>
      <?php foreach (['PRESENT' => t('groups.present'), 'APOLOGIES' => t('groups.apologies'), 'ABSENT' => t('groups.absent')] as $status => $label): ?>
        <label class="check"><input type="radio" name="s-<?= e($member['userId']) ?>" value="<?= e($status) ?>"<?= $status === 'PRESENT' ? ' checked' : '' ?>> <?= e($label) ?></label>
      <?php endforeach ?>
      <input name="n-<?= e($member['userId']) ?>" maxlength="500" placeholder="…" aria-label="<?= e($member['name']) ?>">
    </div>
  <?php endforeach ?>
  <label><?= e(t('guides.leaderNotes')) ?><textarea name="leaderNotes" rows="2" maxlength="5000"></textarea></label>
  <?php if ($guides !== []): ?>
    <label>Guide<select name="guideId"><option value=""></option><?php foreach ($guides as $guide): ?><option value="<?= e((string) $guide['id']) ?>"><?= e((string) $guide['title']) ?></option><?php endforeach ?></select></label>
  <?php endif ?>
  <div><button class="button primary" type="submit"><?= e(t('groups.saveRoll')) ?></button></div>
  <p class="error" data-error hidden></p>
</form>
