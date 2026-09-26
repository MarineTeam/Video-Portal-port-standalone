<?php
/**
 * One service: the running order, and who is on.
 *
 * @var \App\Core\View $v
 * @var array<string, mixed> $plan
 * @var list<array<string, mixed>> $items
 * @var list<array<string, mixed>> $rota
 */
?>
<p><a href="<?= e(url('/services')) ?>">← <?= e(t('services.title')) ?></a></p>
<h1><?= e((string) $plan['title']) ?></h1>
<?php if ($plan['date'] !== null): ?><p class="muted"><time datetime="<?= e((string) $plan['date']) ?>" data-local-date><?= e((string) $plan['date']) ?></time></p><?php endif ?>
<?php if ($plan['notes'] !== null && $plan['notes'] !== ''): ?><p><?= e((string) $plan['notes']) ?></p><?php endif ?>
<ol class="plain">
  <?php foreach ($items as $item): ?>
    <li class="card">
      <p>
        <?php if ($item['number'] !== null): ?><strong class="chapter-time"><?= e((string) $item['number']) ?></strong><?php endif ?>
        <?php if ($item['href'] !== null): ?>
          <a href="<?= e(url((string) $item['href'])) ?>"><?= e((string) $item['title']) ?></a>
        <?php else: ?>
          <?= e((string) $item['title']) ?><?php if (!$item['readable']): ?> <span class="badge muted"><?= e(t('services.unavailable')) ?></span><?php endif ?>
        <?php endif ?>
        <?php if ($item['presentHref'] !== null): ?>
          <a class="button small" href="<?= e(url((string) $item['presentHref'])) ?>"><?= e(t('services.present')) ?></a>
        <?php endif ?>
      </p>
      <?php if ($item['note'] !== null && $item['note'] !== ''): ?><p class="small muted"><?= e((string) $item['note']) ?></p><?php endif ?>
    </li>
  <?php endforeach ?>
</ol>
<?php if ($items === []): ?><p class="muted"><?= e(t('services.noItems')) ?></p><?php endif ?>
<?php if ($rota !== []): ?>
  <h2><?= e(t('services.whoIsOn')) ?></h2>
  <ul class="plain">
    <?php foreach ($rota as $row): ?>
      <li class="small">
        <strong><?= e((string) $row['role']) ?></strong>
        <?php if (isset($row['name'])): ?>— <?= e((string) $row['name']) ?><?php endif ?>
        <?php if ($row['status'] === 'DECLINED'): ?><span class="badge muted"><?= e(t('services.declined')) ?></span><?php endif ?>
        <?php if ($row['coverWanted']): ?><span class="badge"><?= e(t('services.coverWanted')) ?></span><?php endif ?>
        <?php if (($row['coveredFor'] ?? null) !== null): ?><span class="muted"><?= e(t('services.coveringFor', ['name' => (string) $row['coveredFor']])) ?></span><?php endif ?>
      </li>
    <?php endforeach ?>
  </ul>
  <?php if (!isset($rota[0]['name'])): ?><p class="small muted"><?= e(t('services.namesNeedSignIn')) ?></p><?php endif ?>
<?php endif ?>
