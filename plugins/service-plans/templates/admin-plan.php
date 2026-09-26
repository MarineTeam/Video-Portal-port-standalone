<?php
/**
 * One plan: its details, its running order, and who is on.
 *
 * @var \App\Core\View $v
 * @var array<string, mixed> $plan
 * @var list<array<string, mixed>> $items
 * @var list<array<string, mixed>> $rota
 * @var list<array<string, mixed>> $teams
 * @var list<array<string, mixed>> $files every file a row could point at
 * @var string $script
 */
$api = '/api/admin/services/' . $plan['id'];
?>
<p><a href="<?= e(url('/admin/services')) ?>">← Service plans</a></p>
<h1><?= e((string) $plan['title']) ?></h1>
<form class="stack card narrow" data-api="<?= e($api) ?>" data-method="PATCH">
  <label>Title<input name="title" required maxlength="255" value="<?= e((string) $plan['title']) ?>"></label>
  <label>The day<input type="date" name="serviceDate" data-null value="<?= e($plan['serviceDate'] === null ? '' : substr((string) $plan['serviceDate'], 0, 10)) ?>"></label>
  <label>Notes<textarea name="notes" rows="2" maxlength="20000" data-null><?= e((string) ($plan['notes'] ?? '')) ?></textarea></label>
  <label class="check"><input type="checkbox" name="published"<?= $plan['published'] ? ' checked' : '' ?>> Published</label>
  <div class="row">
    <button class="button primary" type="submit">Save</button>
    <a class="button small" href="<?= e(url('/services/' . $plan['id'])) ?>">The members' page</a>
    <button type="button" class="button danger" data-api="<?= e($api) ?>" data-method="DELETE" data-redirect="/admin/services" data-confirm="Delete this plan, its order and its rota?">Delete</button>
  </div>
  <p class="error" data-error hidden></p>
</form>

<h2>The running order</h2>
<section class="card" data-plan="<?= e((string) $plan['id']) ?>">
  <ol class="plain" data-order>
    <?php foreach ($items as $item): ?>
      <li class="row" data-row data-file="<?= e((string) $item['fileId']) ?>" data-number="<?= e($item['number'] === null ? '' : (string) $item['number']) ?>" data-note="<?= e((string) ($item['note'] ?? '')) ?>">
        <span><?= e((string) $item['title']) ?><?php if ($item['number'] !== null): ?> · <?= e((string) $item['number']) ?><?php endif ?><?php if ($item['note'] !== null && $item['note'] !== ''): ?> · <?= e((string) $item['note']) ?><?php endif ?></span>
        <button type="button" class="button small" data-move="up">↑</button>
        <button type="button" class="button small" data-move="down">↓</button>
        <button type="button" class="button small danger" data-remove>×</button>
      </li>
    <?php endforeach ?>
  </ol>
  <form class="row" data-add-item>
    <label>Hymn or book
      <select name="fileId" required>
        <option value=""></option>
        <?php foreach ($files as $file): ?>
          <option value="<?= e((string) $file['id']) ?>"><?= e(($file['series_title'] !== null ? $file['series_title'] . ' · ' : '') . $file['title'] . ($file['page_number'] !== null ? ' (' . $file['page_number'] . ')' : '')) ?></option>
        <?php endforeach ?>
      </select>
    </label>
    <label>Number<input name="hymnNumber" type="number" min="1" max="9999"></label>
    <label>A line for the congregation<input name="note" maxlength="500"></label>
    <button class="button small primary" type="submit">Add</button>
  </form>
  <div class="row">
    <button class="button primary" type="button" data-save-order>Save the order</button>
    <p class="error" data-error hidden></p>
  </div>
</section>

<h2>Who is on</h2>
<section class="card" data-rota>
  <ul class="plain" data-rota-list>
    <?php foreach ($rota as $row): ?>
      <li class="row" data-assignment data-user="<?= e((string) $row['userId']) ?>" data-team="<?= e((string) ($row['teamId'] ?? '')) ?>" data-position="<?= e((string) $row['role']) ?>">
        <span><strong><?= e((string) $row['role']) ?></strong> — <?= e((string) ($row['name'] ?? '')) ?> <span class="small muted"><?= e(strtolower((string) $row['status'])) ?><?php if ($row['coverWanted']): ?>, wants cover<?php endif ?></span></span>
        <button type="button" class="button small danger" data-remove>×</button>
      </li>
    <?php endforeach ?>
  </ul>
  <form class="row" data-add-assignment>
    <label>Team
      <select name="teamId" required>
        <?php foreach ($teams as $team): ?><option value="<?= e((string) $team['id']) ?>"><?= e((string) $team['name']) ?></option><?php endforeach ?>
      </select>
    </label>
    <label>Person
      <select name="userId" required>
        <?php foreach ($teams as $team): ?>
          <?php foreach ($team['members'] as $member): ?>
            <option value="<?= e((string) $member['userId']) ?>" data-team="<?= e((string) $team['id']) ?>"><?= e((string) $member['name']) ?> (<?= e((string) $team['name']) ?>)</option>
          <?php endforeach ?>
        <?php endforeach ?>
      </select>
    </label>
    <label>Job<input name="position" maxlength="191" placeholder="Sound desk"></label>
    <button class="button small primary" type="submit">Add</button>
  </form>
  <div class="row">
    <button class="button primary" type="button" data-save-rota>Save who is on</button>
    <p class="error" data-error hidden></p>
  </div>
</section>
<script type="module" src="<?= e($script) ?>"></script>
