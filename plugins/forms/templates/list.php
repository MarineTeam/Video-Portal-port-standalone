<?php
/**
 * @var \App\Core\View $v
 * @var list<array{title: string, slug: string, description: ?string, memberOnly: bool}> $forms
 */
?>
<h1><?= e(t('forms.title')) ?></h1>
<?php if ($forms === []): ?>
  <p class="muted"><?= e(t('forms.none')) ?></p>
<?php else: ?>
  <ul class="plain">
    <?php foreach ($forms as $form): ?>
      <li class="card">
        <h2><a href="<?= e(url('/forms/' . $form['slug'])) ?>"><?= e($form['title']) ?></a>
          <?php if ($form['memberOnly']): ?><span class="badge muted"><?= e(t('forms.membersOnly')) ?></span><?php endif ?>
        </h2>
        <?php if ($form['description'] !== null && $form['description'] !== ''): ?><p class="small muted"><?= e((string) $form['description']) ?></p><?php endif ?>
      </li>
    <?php endforeach ?>
  </ul>
<?php endif ?>
