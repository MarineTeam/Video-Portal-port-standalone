<?php
/**
 * Admin → Services: each slot, its active provider, and the providers it
 * could switch to — read from the registry alone, so a provider a plugin
 * adds appears here with nothing else changed.
 *
 * @var \App\Core\View $v
 * @var array<string, array{active: ?array, activeId: ?string, providers: array<string, class-string>}> $slots
 * @var array{ok: bool, message: string} $outbound
 * @var bool $https
 * @var ?string $flash
 * @var array<string, array{label: string, description: string, set: bool}> $integrations
 */
$labels = ['auth' => 'Sign-in', 'video' => 'Video', 'email' => 'Email', 'files' => 'Files', 'sms' => 'Text messages'];
?>
<h1>Services</h1>
<?php if ($flash): ?><p class="notice" role="status"><?= e($flash) ?></p><?php endif ?>
<p class="muted">
  Outbound HTTPS from this host: <?= $v->raw($outbound['ok'] ? '<strong>works</strong>' : '<strong>blocked</strong>') ?>.
  If your host blocks outbound HTTPS, SMTP is usually the email service that works; if it blocks the SMTP ports (25, 465, 587) instead, the HTTPS API ones are.
</p>
<?php foreach ($slots as $slot => $info): ?>
<section class="card">
  <h2><?= e($labels[$slot] ?? ucfirst($slot)) ?></h2>
  <p>
    Active:
    <?php $activeClass = $info['activeId'] !== null ? ($info['providers'][$info['activeId']] ?? null) : null; ?>
    <strong><?= e($activeClass !== null ? $activeClass::label() : 'none') ?></strong>
    <?php if ($info['active'] !== null): ?>
      <span class="small muted">— set <?= e($info['active']['updated_at']) ?><?= $v->raw($info['active']['updated_by'] ? ' by ' . e($info['active']['updated_by']) : '') ?></span>
    <?php endif ?>
  </p>
  <ul class="providers">
  <?php foreach ($info['providers'] as $id => $class): ?>
    <li>
      <a href="<?= e(url("/admin/providers/$slot/$id")) ?>"><?= e($class::label()) ?></a>
      <?php if ($class::requiresHttps() && !$https): ?><span class="badge muted">needs HTTPS</span><?php endif ?>
      <?php if ($class::requiresOutboundHttps()): ?><span class="badge <?= $outbound['ok'] ? '' : 'error' ?>">outbound HTTPS <?= $outbound['ok'] ? 'ok' : 'blocked' ?></span><?php endif ?>
      <?php if ($class::limits() !== ''): ?><div class="small muted"><?= e($class::limits()) ?></div><?php endif ?>
    </li>
  <?php endforeach ?>
  <?php if ($info['providers'] === []): ?><li class="muted">No providers yet — they arrive in a later step of the port.</li><?php endif ?>
  </ul>
  <?php if (in_array($slot, ['sms', 'video'], true) && $info['active'] !== null): ?>
    <form method="post" action="<?= e(url("/admin/providers/$slot/off")) ?>"><?= $v->raw(csrf_field()) ?><button class="button small" type="submit">Switch off</button></form>
  <?php endif ?>
</section>
<?php endforeach ?>

<section class="card">
  <h2>Integrations</h2>
  <p class="small muted">Optional extras, each with its own settings and test. Nothing here is needed for the site to run.</p>
  <ul class="providers">
  <?php foreach ($integrations as $gid => $g): ?>
    <li>
      <a href="<?= e(url('/admin/integrations/' . $gid)) ?>"><?= e($g['label']) ?></a>
      <span class="badge<?= $g['set'] ? '' : ' muted' ?>"><?= $g['set'] ? 'set' : 'not set' ?></span>
      <div class="small muted"><?= e($g['description']) ?></div>
    </li>
  <?php endforeach ?>
  </ul>
</section>
