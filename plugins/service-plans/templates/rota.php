<?php
/**
 * /profile/rota: what this member is on for, and when they are away.
 *
 * @var \App\Core\View $v
 * @var array $shell
 * @var list<array{href: string, label: string}> $sections
 * @var list<array<string, mixed>> $assignments
 * @var list<array<string, mixed>> $cover slots going begging on this member's teams
 * @var list<array{id: string, from: string, to: string, reason: ?string}> $blockouts
 */
?>
<h1><?= e(t('services.myRota')) ?></h1>
<?= $v->partial('partials/profile-nav', ['shell' => $shell, 'sections' => $sections]) ?>
<?php if ($assignments === []): ?>
  <p class="muted"><?= e(t('services.rotaEmpty')) ?></p>
<?php else: ?>
  <ul class="plain">
    <?php foreach ($assignments as $row): ?>
      <li class="card">
        <p><strong><?= e((string) $row['role']) ?></strong> — <a href="<?= e(url('/services/' . $row['planId'])) ?>"><?= e((string) $row['plan']) ?></a>
          <?php if ($row['date'] !== null): ?><span class="small muted"><time datetime="<?= e((string) $row['date']) ?>" data-local-date><?= e((string) $row['date']) ?></time></span><?php endif ?>
          <?php if ($row['away']): ?><span class="badge error"><?= e(t('services.youAreAway')) ?></span><?php endif ?>
        </p>
        <p class="row">
          <button class="button small<?= $row['status'] === 'ACCEPTED' ? ' primary' : '' ?>" type="button" data-api="/api/rota" data-body="<?= e(\App\Core\View::json(['assignmentId' => $row['id'], 'status' => 'ACCEPTED'])) ?>"><?= e(t('services.yes')) ?></button>
          <button class="button small<?= $row['status'] === 'DECLINED' ? ' danger' : '' ?>" type="button" data-api="/api/rota" data-body="<?= e(\App\Core\View::json(['assignmentId' => $row['id'], 'status' => 'DECLINED'])) ?>"><?= e(t('services.no')) ?></button>
          <button class="button small" type="button" data-api="/api/rota" data-body="<?= e(\App\Core\View::json(['assignmentId' => $row['id'], 'coverWanted' => !$row['coverWanted']])) ?>"><?= e($row['coverWanted'] ? t('services.coverCancel') : t('services.coverAsk')) ?></button>
        </p>
      </li>
    <?php endforeach ?>
  </ul>
<?php endif ?>

<?php if ($cover !== []): ?>
  <h2><?= e(t('services.coverNeeded')) ?></h2>
  <ul class="plain">
    <?php foreach ($cover as $row): ?>
      <li class="card row">
        <span><strong><?= e((string) $row['role']) ?></strong> — <?= e((string) $row['plan']) ?><?php if ($row['date'] !== null): ?> <span class="small muted"><?= e((string) $row['date']) ?></span><?php endif ?></span>
        <?php if ($row['coverNote'] !== null && $row['coverNote'] !== ''): ?><span class="small muted"><?= e((string) $row['coverNote']) ?></span><?php endif ?>
        <button class="button small primary" type="button" data-api="/api/rota" data-body="<?= e(\App\Core\View::json(['assignmentId' => $row['id'], 'takeCover' => true])) ?>"><?= e(t('services.takeIt')) ?></button>
      </li>
    <?php endforeach ?>
  </ul>
<?php endif ?>

<h2><?= e(t('services.away')) ?></h2>
<p class="small muted"><?= e(t('services.awayHint')) ?></p>
<ul class="plain">
  <?php foreach ($blockouts as $blockout): ?>
    <li class="row">
      <span><?= e($blockout['from']) ?> – <?= e($blockout['to']) ?><?php if ($blockout['reason'] !== null && $blockout['reason'] !== ''): ?> · <?= e((string) $blockout['reason']) ?><?php endif ?></span>
      <button class="button small danger" type="button" data-api="/api/rota" data-method="DELETE" data-body="<?= e(\App\Core\View::json(['id' => $blockout['id']])) ?>"><?= e(t('services.remove')) ?></button>
    </li>
  <?php endforeach ?>
</ul>
<form class="row card" data-api="/api/rota" data-method="POST">
  <label><?= e(t('services.from')) ?><input type="date" name="startDate" required></label>
  <label><?= e(t('services.to')) ?><input type="date" name="endDate" required></label>
  <label><?= e(t('services.reason')) ?><input name="reason" maxlength="500" data-null></label>
  <button class="button primary" type="submit"><?= e(t('services.addAway')) ?></button>
  <p class="error" data-error hidden></p>
</form>
