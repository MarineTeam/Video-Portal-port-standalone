<?php
/**
 * @var \App\Core\View $v
 * @var list<array<string, mixed>> $rows what is waiting first
 */
$audiences = ['EVERYONE' => 'Anyone who visits', 'MEMBERS' => 'Members', 'LEADERS' => 'Only whoever looks after prayer'];
?>
<h1>Prayer wall</h1>
<p class="muted">Nothing on the wall until somebody lets it through. A request posted anonymously is anonymous here too — this screen gets left open on an office laptop. Marking one answered keeps it up with a line saying what happened; hiding one keeps it out of view without deleting the decision.</p>
<?php if ($rows === []): ?>
  <p class="notice ok">Nothing has been asked yet.</p>
<?php endif ?>
<?php foreach ($rows as $r): ?>
  <div class="card">
    <p>
      <span class="badge<?= $r['status'] === 'APPROVED' ? '' : ' muted' ?>"><?= e(strtolower((string) $r['status'])) ?></span>
      <span class="small muted"><?= e($r['author'] !== null ? (string) $r['author'] : 'anonymous') ?> · <time datetime="<?= e((string) $r['createdAt']) ?>" data-local-date><?= e(substr((string) $r['createdAt'], 0, 10)) ?></time> · <?= e($audiences[$r['visibility']] ?? (string) $r['visibility']) ?> · <?= e((string) $r['prayers']) ?> prayed</span>
    </p>
    <p><?= e((string) $r['body']) ?></p>
    <form class="stack" data-api="/api/admin/prayer/<?= e((string) $r['id']) ?>" data-method="PATCH">
      <div class="row">
        <label>Who may see it<select name="visibility"><?php foreach ($audiences as $value => $label): ?><option value="<?= e($value) ?>"<?= $r['visibility'] === $value ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach ?></select></label>
        <label>What happened (with "answered")<input name="answeredNote" maxlength="1000" data-null value="<?= e((string) ($r['answeredNote'] ?? '')) ?>"></label>
      </div>
      <div class="row">
        <button class="button primary" type="submit">Save</button>
        <button type="button" class="button small" data-api="/api/admin/prayer/<?= e((string) $r['id']) ?>" data-method="PATCH" data-body='{"status":"APPROVED"}'>Let through</button>
        <button type="button" class="button small" data-api="/api/admin/prayer/<?= e((string) $r['id']) ?>" data-method="PATCH" data-body='{"status":"HIDDEN"}'>Take down</button>
        <button type="button" class="button small" data-api="/api/admin/prayer/<?= e((string) $r['id']) ?>" data-method="PATCH" data-body='{"status":"ANSWERED"}'>Answered</button>
        <button type="button" class="button small danger" data-api="/api/admin/prayer/<?= e((string) $r['id']) ?>" data-method="DELETE" data-confirm="Delete this request for good?">Delete</button>
      </div>
      <p class="error" data-error hidden></p>
    </form>
  </div>
<?php endforeach ?>
