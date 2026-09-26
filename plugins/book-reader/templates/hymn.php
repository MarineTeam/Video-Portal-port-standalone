<?php
/**
 * One hymn that is its own file.
 *
 * @var \App\Core\View $v
 * @var array<string, mixed> $file
 * @var array<string, mixed> $credits
 * @var array{title: string, slug: string}|null $series
 * @var string $opened
 * @var string $openedScript
 * @var array{actions: list<string>, top: list<string>, below: list<string>} $panels from plugins (page.file.panels)
 */
?>
<div hidden data-opened="<?= e($opened) ?>"></div>
<?php if ($panels['actions'] !== []): ?><p class="row"><?php foreach ($panels['actions'] as $html): ?><?= $v->raw($html) ?><?php endforeach ?></p><?php endif ?>
<?php foreach ($panels['top'] as $html): ?><?= $v->raw($html) ?><?php endforeach ?>
<?php if ($series !== null): ?><p><a href="<?= e(url('/series/' . $series['slug'])) ?>">← <?= e($series['title']) ?></a></p><?php endif ?>
<h1><?php if ($file['number'] !== null): ?><span class="chapter-time"><?= e((string) $file['number']) ?></span> <?php endif ?><?= e((string) $file['title']) ?></h1>
<?php if ($file['group'] !== null && $file['group'] !== ''): ?><p class="small muted"><?= e((string) $file['group']) ?></p><?php endif ?>
<?php if (trim((string) $file['words']) !== ''): ?>
  <div class="stack hymn-words">
    <?php foreach (preg_split('/\n{2,}/', str_replace(["\r\n", "\r"], "\n", (string) $file['words'])) ?: [] as $verse): ?>
      <?php if (trim((string) $verse) !== ''): ?><p class="hymn-verse"><?= e(trim((string) $verse)) ?></p><?php endif ?>
    <?php endforeach ?>
  </div>
  <p><a class="button small" href="<?= e(url('/present/' . $file['id'])) ?>"><?= e(t('books.present')) ?></a></p>
<?php else: ?>
  <p class="muted"><?= e(t('books.noWords')) ?></p>
<?php endif ?>
<p class="row">
  <a class="button small" href="<?= e(url('/read/' . $file['id'])) ?>"><?= e(t('books.open')) ?></a>
  <a class="button small" href="<?= e(url('/api/files/' . $file['id'] . '/content')) ?>" download><?= e(t('books.download')) ?></a>
</p>
<?= $v->partial('book-reader/credits', ['credits' => $credits]) ?>
<?php foreach ($panels['below'] as $html): ?><?= $v->raw($html) ?><?php endforeach ?>
<script type="module" src="<?= e($openedScript) ?>"></script>
