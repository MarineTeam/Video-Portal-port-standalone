<?php
/**
 * One group: what anybody may know, what people in it may know, the
 * leader's requests, the conversation and the roll.
 *
 * @var \App\Core\View $v
 * @var array<string, mixed> $group
 * @var string $threadState
 * @var list<array<string, mixed>> $messages
 * @var bool $muted
 * @var list<array<string, mixed>> $requests
 * @var bool $canLead
 * @var list<array<string, mixed>> $meetings
 * @var list<string> $quietlyMissing
 * @var list<array<string, mixed>> $guides
 * @var list<array{userId: string, name: string}> $rollMembers
 * @var string $today
 * @var string $script
 */
?>
<p><a href="<?= e(url('/groups')) ?>">← <?= e(t('groups.title')) ?></a></p>
<h1><?= e((string) $group['name']) ?></h1>
<?php if ($group['description'] !== null && $group['description'] !== ''): ?><p><?= e((string) $group['description']) ?></p><?php endif ?>
<dl class="small">
  <?php if ($group['meetsWhen'] !== null && $group['meetsWhen'] !== ''): ?>
    <dt><?= e(t('groups.meetsWhen')) ?></dt><dd><?= e((string) $group['meetsWhen']) ?></dd>
  <?php endif ?>
  <?php if ($group['area'] !== null && $group['area'] !== ''): ?>
    <dt><?= e(t('groups.where')) ?></dt><dd><?= e((string) $group['area']) ?></dd>
  <?php endif ?>
  <?php if (isset($group['address'])): ?>
    <dt><?= e(t('groups.address')) ?></dt><dd><?= e((string) $group['address']) ?> <span class="muted"><?= e(t('groups.addressHint')) ?></span></dd>
  <?php endif ?>
  <?php if ($group['leaders'] !== []): ?>
    <dt><?= e(t('groups.leaders')) ?></dt><dd><?= e(implode(', ', $group['leaders'])) ?></dd>
  <?php endif ?>
</dl>
<p class="small muted"><?= e(t('groups.members', ['count' => (string) $group['memberCount']])) ?><?php if ($group['placesLeft'] !== null): ?> · <?= e(t('groups.placesLeft', ['count' => (string) $group['placesLeft']])) ?><?php endif ?></p>

<section class="card" data-group="<?= e((string) $group['slug']) ?>" data-labels="<?= e(\App\Core\View::json(['leaveConfirm' => t('groups.leaveConfirm'), 'removeConfirm' => t('groups.removeConfirm')])) ?>">
  <p><?= e(t('groups.join.' . strtolower((string) $group['joinState']))) ?>
    <?php if (isset($group['waitingPosition'])): ?><?= e(t('groups.waitingPosition', ['position' => (string) $group['waitingPosition']])) ?><?php endif ?>
  </p>
  <?php if ($group['joinState'] === 'OPEN' || $group['joinState'] === 'WAITING' && $group['standing'] === 'NONE'): ?>
    <form class="stack" data-group-join>
      <label><?= e(t('groups.askNote')) ?><textarea name="note" rows="2" maxlength="1000"></textarea></label>
      <div><button class="button primary" type="submit"><?= e(t('groups.ask')) ?></button></div>
      <p class="error" data-error hidden></p>
    </form>
  <?php elseif ($group['standing'] === 'REQUESTED' || $group['standing'] === 'WAITLIST'): ?>
    <div><button class="button small" type="button" data-group-leave><?= e(t('groups.withdraw')) ?></button></div>
  <?php elseif ($group['standing'] === 'ACTIVE'): ?>
    <div class="row">
      <button class="button small" type="button" data-api="/api/groups/<?= e((string) $group['slug']) ?>/messages" data-method="PATCH" data-body="<?= e(\App\Core\View::json(['muted' => !$muted])) ?>"><?= e($muted ? t('groups.unmute') : t('groups.mute')) ?></button>
      <button class="button small danger" type="button" data-group-leave><?= e(t('groups.leave')) ?></button>
    </div>
  <?php endif ?>
</section>

<?php if ($canLead && $requests !== []): ?>
  <section class="card">
    <h2><?= e(t('groups.requests')) ?></h2>
    <ul class="plain">
      <?php foreach ($requests as $request): ?>
        <li class="row">
          <strong><?= e((string) $request['name']) ?></strong>
          <?php if ($request['status'] === 'WAITLIST'): ?><span class="badge muted"><?= e(t('groups.waitingList')) ?></span><?php endif ?>
          <?php if ($request['note'] !== null && $request['note'] !== ''): ?><span class="small muted"><?= e((string) $request['note']) ?></span><?php endif ?>
          <button class="button small primary" type="button" data-api="/api/groups/<?= e((string) $group['slug']) ?>/requests/<?= e((string) $request['id']) ?>" data-method="PATCH" data-body="<?= e(\App\Core\View::json(['status' => 'ACTIVE'])) ?>"><?= e(t('groups.approve')) ?></button>
          <button class="button small" type="button" data-api="/api/groups/<?= e((string) $group['slug']) ?>/requests/<?= e((string) $request['id']) ?>" data-method="PATCH" data-body="<?= e(\App\Core\View::json(['status' => 'DECLINED'])) ?>"><?= e(t('groups.decline')) ?></button>
        </li>
      <?php endforeach ?>
    </ul>
  </section>
<?php endif ?>

<section class="card">
  <h2><?= e(t('groups.thread')) ?></h2>
  <?php if ($threadState !== 'OPEN'): ?>
    <p class="small muted"><?= e(t('groups.thread.' . strtolower($threadState))) ?></p>
  <?php else: ?>
    <ol class="plain group-thread" data-group-messages>
      <?php foreach ($messages as $message): ?>
        <?= $v->partial('groups/message', ['message' => $message]) ?>
      <?php endforeach ?>
    </ol>
    <?php if ($messages === []): ?><p class="small muted" data-thread-empty><?= e(t('groups.threadEmpty')) ?></p><?php endif ?>
    <form class="row" data-group-say>
      <textarea name="body" rows="2" required maxlength="4000" placeholder="<?= e(t('groups.say')) ?>" aria-label="<?= e(t('groups.say')) ?>"></textarea>
      <button class="button small primary" type="submit"><?= e(t('groups.send')) ?></button>
    </form>
    <p class="error small" data-error hidden></p>
  <?php endif ?>
</section>

<?php if ($meetings !== [] || $canLead): ?>
  <section class="card">
    <h2><?= e($canLead ? t('groups.meetings') : t('groups.myAttendance')) ?></h2>
    <?php if ($canLead && $quietlyMissing !== []): ?>
      <p class="notice warn"><strong><?= e(t('groups.quietlyMissing')) ?>:</strong> <?= e(implode(', ', $quietlyMissing)) ?></p>
    <?php endif ?>
    <?php if ($canLead): ?>
      <?= $v->partial('groups/roll', ['group' => $group, 'members' => $rollMembers, 'guides' => $guides, 'today' => $today]) ?>
    <?php endif ?>
    <?php foreach ($meetings as $meeting): ?>
      <details>
        <summary><?= e($meeting['date']) ?><?php if ($meeting['cancelled']): ?> <span class="badge muted"><?= e(t('groups.cancelled')) ?></span><?php endif ?>
          <?php if ($meeting['topic'] !== null && $meeting['topic'] !== ''): ?> <span class="small muted"><?= e((string) $meeting['topic']) ?></span><?php endif ?>
          <?php if (isset($meeting['summary'])): ?> <span class="small muted"><?= e((string) $meeting['summary']['inTheRoom']) ?> in the room</span><?php endif ?>
        </summary>
        <ul class="plain small">
          <?php foreach ($meeting['attendance'] as $row): ?>
            <li><?= e((string) $row['name']) ?> — <?= e(t('groups.' . strtolower((string) $row['status']))) ?><?php if ($row['note'] !== null && $row['note'] !== ''): ?> (<?= e((string) $row['note']) ?>)<?php endif ?></li>
          <?php endforeach ?>
        </ul>
        <?php if (($meeting['leaderNotes'] ?? '') !== ''): ?>
          <p class="small"><strong><?= e(t('guides.leaderNotes')) ?>:</strong> <?= e((string) $meeting['leaderNotes']) ?></p>
        <?php endif ?>
      </details>
    <?php endforeach ?>
  </section>
<?php endif ?>
<script type="module" src="<?= e($script) ?>"></script>
