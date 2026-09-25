<?php
/** @var \App\Core\View $v @var list<array<string, mixed>> $rows @var bool $configured @var ?string $flash */
?>
<h1>Email log</h1>
<?php if ($flash): ?><p class="notice" role="status"><?= e($flash) ?></p><?php endif ?>
<?php if (!$configured): ?><p class="notice warn">Email isn’t set up, so these were recorded but not sent. <a href="<?= e(url('/admin/providers')) ?>">Set up email</a>.</p><?php endif ?>
<table class="table">
  <thead><tr><th>When</th><th>To</th><th>Subject</th><th>Via</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td class="small"><?= e($r['created_at']) ?></td>
      <td><?= e($r['to_address']) ?></td>
      <td><?= e($r['subject']) ?></td>
      <td><?= e($r['provider']) ?></td>
      <td><?= e($r['status']) ?><?php if ($r['error']): ?><div class="small error"><?= e($r['error']) ?></div><?php endif ?></td>
      <td><?php if ($r['resendable']): ?><form method="post" action="<?= e(url('/admin/email/' . $r['id'] . '/resend')) ?>"><?= $v->raw(csrf_field()) ?><button class="button small" type="submit">Resend</button></form><?php endif ?></td>
    </tr>
  <?php endforeach ?>
  <?php if ($rows === []): ?><tr><td colspan="6" class="muted">Nothing sent yet.</td></tr><?php endif ?>
  </tbody>
</table>
