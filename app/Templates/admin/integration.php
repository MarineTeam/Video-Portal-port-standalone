<?php
/**
 * One integration's settings: the same generated fields as a provider, a
 * Test that tries the values in the form, and Save.
 *
 * @var \App\Core\View $v
 * @var string $id
 * @var array{label: string, description: string, fields: list<array<string, mixed>>} $group
 * @var array<string, mixed> $values
 * @var bool $saved
 * @var ?array{ok: bool, message: string, steps: list<string>} $result
 * @var ?string $flash
 */
?>
<p><a href="<?= e(url('/admin/providers')) ?>">← Services</a></p>
<h1><?= e($group['label']) ?> <span class="badge<?= $saved ? '' : ' muted' ?>"><?= $saved ? 'set' : 'not set' ?></span></h1>
<p class="muted"><?= e($group['description']) ?></p>
<?php if ($flash): ?><p class="notice" role="status"><?= e($flash) ?></p><?php endif ?>
<?php if ($result !== null): ?>
  <div class="notice <?= $result['ok'] ? 'ok' : 'error' ?>" role="status">
    <p><?= e($result['message']) ?></p>
    <?php if ($result['steps'] !== []): ?><ul class="small"><?php foreach ($result['steps'] as $step): ?><li><?= e($step) ?></li><?php endforeach ?></ul><?php endif ?>
  </div>
<?php endif ?>
<form method="post" action="<?= e(url('/admin/integrations/' . $id)) ?>" class="stack narrow" autocomplete="off">
  <?= $v->raw(csrf_field()) ?>
  <?= $v->partial('partials/config-fields', ['fields' => $group['fields'], 'values' => $values]) ?>
  <div class="row">
    <button type="submit" name="action" value="test" class="button">Test</button>
    <button type="submit" name="action" value="save" class="button primary">Save</button>
  </div>
</form>
<?php if ($saved): ?>
  <form method="post" action="<?= e(url('/admin/integrations/' . $id)) ?>" class="stack narrow">
    <?= $v->raw(csrf_field()) ?>
    <button type="submit" name="action" value="clear" class="button danger">Switch off</button>
  </form>
<?php endif ?>
