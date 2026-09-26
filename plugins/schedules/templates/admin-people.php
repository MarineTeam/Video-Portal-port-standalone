<?php
/**
 * People are names, made as they turn up in a sheet. Near-duplicates are
 * suggested and never merged automatically: "Dave" and "Davey" may well be
 * two people, and a merge moves one person's whole history onto another.
 *
 * @var \App\Core\View $v
 * @var list<array<string, mixed>> $people
 * @var list<array{a: array<string, mixed>, b: array<string, mixed>}> $duplicates
 * @var string $script
 */
?>
<p><a href="<?= e(url('/admin/schedules')) ?>">← <?= e(t('schedules.admin')) ?></a></p>
<h1><?= e(t('schedules.people')) ?></h1>
<p class="small muted"><?= e(t('schedules.peopleHint')) ?></p>

<?php if ($duplicates !== []): ?>
  <section class="card" data-duplicates>
    <h2><?= e(t('schedules.possibleDuplicates')) ?></h2>
    <ul class="plain">
      <?php foreach ($duplicates as $pair): ?>
        <li class="row small">
          <span><strong><?= e((string) $pair['a']['displayName']) ?></strong> · <strong><?= e((string) $pair['b']['displayName']) ?></strong></span>
          <button class="button small" type="button" data-merge data-keep="<?= e((string) $pair['a']['id']) ?>" data-lose="<?= e((string) $pair['b']['id']) ?>"
                  data-confirm="<?= e(t('schedules.mergeConfirm', ['lose' => (string) $pair['b']['displayName'], 'keep' => (string) $pair['a']['displayName']])) ?>">
            <?= e(t('schedules.mergeInto', ['lose' => (string) $pair['b']['displayName'], 'keep' => (string) $pair['a']['displayName']])) ?>
          </button>
          <button class="button small" type="button" data-merge data-keep="<?= e((string) $pair['b']['id']) ?>" data-lose="<?= e((string) $pair['a']['id']) ?>"
                  data-confirm="<?= e(t('schedules.mergeConfirm', ['lose' => (string) $pair['a']['displayName'], 'keep' => (string) $pair['b']['displayName']])) ?>">
            <?= e(t('schedules.mergeInto', ['lose' => (string) $pair['a']['displayName'], 'keep' => (string) $pair['b']['displayName']])) ?>
          </button>
        </li>
      <?php endforeach ?>
    </ul>
    <p class="error" data-error hidden></p>
  </section>
<?php endif ?>

<section class="card">
  <h2><?= e(t('schedules.addPerson')) ?></h2>
  <form class="row" data-person-new>
    <input name="displayName" required maxlength="255" aria-label="<?= e(t('schedules.name')) ?>">
    <button class="button primary" type="submit"><?= e(t('common.save')) ?></button>
    <p class="error" data-error hidden></p>
  </form>
</section>

<section class="card">
  <ul class="plain" data-person-list>
    <?php foreach ($people as $person): ?>
      <li class="row small" data-person="<?= e((string) $person['id']) ?>">
        <strong><?= e((string) $person['displayName']) ?></strong>
        <span class="muted"><?= e(t('schedules.dates', ['count' => (string) $person['dates']])) ?></span>
        <?php if ($person['userEmail'] !== null): ?>
          <span class="badge"><?= e(t('schedules.linkedTo', ['email' => (string) $person['userEmail']])) ?></span>
        <?php else: ?>
          <span class="badge muted"><?= e(t('schedules.notLinked')) ?></span>
        <?php endif ?>
        <?php if (!$person['active']): ?><span class="badge muted"><?= e(t('schedules.hidden')) ?></span><?php endif ?>
        <button class="button small" type="button" data-rename><?= e(t('schedules.name')) ?></button>
        <button class="button small danger" type="button" data-api="/api/admin/people/<?= e((string) $person['id']) ?>" data-method="DELETE" data-confirm="<?= e(t('schedules.deletePerson')) ?>"><?= e(t('common.delete')) ?></button>
      </li>
    <?php endforeach ?>
  </ul>
</section>
<script type="module" src="<?= e($script) ?>"></script>
