<?php
/**
 * A whole book: its contents, and where a number lands.
 *
 * @var \App\Core\View $v
 * @var array<string, mixed> $file
 * @var list<array{title: string, number: ?int, page: int, depth: int}> $hymns
 * @var ?int $wanted
 * @var array{title: string, page: int}|null $found
 * @var ?string $words the typed-out words of the wanted number, when there are any
 * @var array<string, mixed>|null $credits
 * @var string $opened
 * @var string $openedScript
 */
?>
<div hidden data-opened="<?= e($opened) ?>"></div>
<h1><?= e((string) $file['title']) ?></h1>
<?php if ($wanted !== null): ?>
  <?php if ($found !== null): ?>
    <p class="notice"><?= e(t('books.number', ['number' => (string) $wanted])) ?>: <strong><?= e($found['title']) ?></strong> ·
      <a href="<?= e(url('/read/' . $file['id'], ['page' => $found['page']])) ?>"><?= e(t('books.page', ['page' => (string) $found['page']])) ?></a>
    </p>
  <?php else: ?>
    <p class="notice warn"><?= e(t('books.noSuchNumber', ['number' => (string) $wanted])) ?></p>
  <?php endif ?>
  <?php if ($words !== null && trim($words) !== ''): ?>
    <div class="stack hymn-words">
      <?php foreach (preg_split('/\n{2,}/', str_replace(["\r\n", "\r"], "\n", $words)) ?: [] as $verse): ?>
        <?php if (trim((string) $verse) !== ''): ?><p class="hymn-verse"><?= e(trim((string) $verse)) ?></p><?php endif ?>
      <?php endforeach ?>
    </div>
    <p><a class="button small" href="<?= e(url('/present/' . $file['id'], ['hymn' => $wanted])) ?>"><?= e(t('books.present')) ?></a></p>
    <?= $v->partial('book-reader/credits', ['credits' => $credits]) ?>
  <?php endif ?>
<?php endif ?>
<p class="row">
  <a class="button small" href="<?= e(url('/read/' . $file['id'])) ?>"><?= e(t('books.open')) ?></a>
  <a class="button small" href="<?= e(url('/api/files/' . $file['id'] . '/content')) ?>" download><?= e(t('books.download')) ?></a>
</p>
<?php if ($hymns !== []): ?>
  <h2><?= e(t('books.contents')) ?></h2>
  <ol class="plain">
    <?php foreach ($hymns as $hymn): ?>
      <li class="row">
        <?php if ($hymn['number'] !== null): ?><span class="chapter-time"><?= e((string) $hymn['number']) ?></span><?php endif ?>
        <a href="<?= e(url('/read/' . $file['id'], ['page' => $hymn['page']])) ?>"><?= e($hymn['title']) ?></a>
      </li>
    <?php endforeach ?>
  </ol>
<?php else: ?>
  <p class="muted"><?= e(t('books.noContents')) ?></p>
<?php endif ?>
<script type="module" src="<?= e($openedScript) ?>"></script>
