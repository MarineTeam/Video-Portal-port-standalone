<?php
/**
 * The in-app reader.
 *
 * Drawn server-side down to the contents list, with the engine loaded
 * afterwards: a phone too old to run pdf.js still gets the book's contents
 * and a link to its own viewer rather than a spinner that never stops.
 *
 * @var \App\Core\View $v
 * @var array<string, mixed> $file
 * @var list<array<string, mixed>> $contents
 * @var ?int $startPage
 * @var bool $signedIn
 * @var string $script
 */
?>
<div class="reader" data-reader data-file-id="<?= e((string) $file['id']) ?>" data-format="<?= e((string) $file['format']) ?>"
     <?php if ($signedIn): ?>data-signed-in="1"<?php endif ?>
     <?php if ($startPage !== null): ?>data-start-page="<?= e((string) $startPage) ?>"<?php endif ?>
     data-labels="<?= e(\App\Core\View::json([
         'ofPages' => t('books.ofPages', ['count' => '{count}']),
         'noHits' => t('books.noHits'),
         'searchHint' => t('books.searchHint'),
         'readAloud' => t('books.readAloud'),
         'stopReading' => t('books.stopReading'),
         'noMarks' => t('books.noMarks'),
         'markSaved' => t('books.markSaved'),
         'deleteMark' => t('books.deleteMark'),
         'keepOffline' => t('books.keepOffline'),
         'removeOffline' => t('books.removeOffline'),
         'savingOffline' => t('books.savingOffline', ['percent' => '{percent}']),
     ])) ?>">

  <header class="reader-bar">
    <p class="row">
      <a href="<?= e(url('/books/' . $file['id'])) ?>">← <?= e(t('books.contents')) ?></a>
      <strong><?= e((string) $file['title']) ?></strong>
      <span class="small muted" data-reader-inside></span>
      <span class="small muted" data-reader-where></span>
    </p>
    <p class="row">
      <button class="button small" type="button" data-reader-previous aria-label="<?= e(t('books.previousPage')) ?>">←</button>
      <button class="button small" type="button" data-reader-next aria-label="<?= e(t('books.nextPage')) ?>">→</button>
      <button class="button small" type="button" data-reader-zoom-out aria-label="<?= e(t('books.zoomOut')) ?>">−</button>
      <button class="button small" type="button" data-reader-zoom-in aria-label="<?= e(t('books.zoomIn')) ?>">+</button>
      <?php if ($file['format'] === 'PDF'): ?>
        <form class="row" data-reader-goto>
          <label class="small"><?= e(t('books.goToPage')) ?>
            <input name="page" type="number" min="1" inputmode="numeric" size="4">
          </label>
        </form>
      <?php endif ?>
      <button class="button small" type="button" data-reader-aloud><?= e(t('books.readAloud')) ?></button>
      <?php if ($signedIn): ?>
        <button class="button small" type="button" data-reader-mark><?= e(t('books.highlight')) ?></button>
      <?php endif ?>
      <button class="button small" type="button" data-reader-keep hidden><?= e(t('books.keepOffline')) ?></button>
      <a class="button small" href="<?= e(url('/api/files/' . $file['id'] . '/content')) ?>" download><?= e(t('books.download')) ?></a>
    </p>
    <p class="notice small" data-reader-status hidden></p>
  </header>

  <div class="reader-body">
    <aside class="reader-side">
      <form class="row" data-reader-search>
        <input name="q" type="search" placeholder="<?= e(t('books.searchInBook')) ?>" aria-label="<?= e(t('books.searchInBook')) ?>">
        <button class="button small" type="submit">→</button>
      </form>
      <ul class="plain small" data-reader-hits></ul>

      <?php if ($contents !== []): ?>
        <h2 class="small"><?= e(t('books.contents')) ?></h2>
        <ol class="plain small reader-toc">
          <?php foreach ($contents as $entry): ?>
            <li style="padding-left: <?= e((string) (min(4, (int) $entry['depth']) * 12)) ?>px">
              <button type="button" class="linkish" data-toc-entry data-toc-page="<?= e((string) $entry['page']) ?>">
                <?= e((string) $entry['title']) ?>
                <?php if ($entry['printedPage'] !== null): ?><span class="muted"><?= e(t('books.page', ['page' => (string) $entry['printedPage']])) ?></span><?php endif ?>
              </button>
            </li>
          <?php endforeach ?>
        </ol>
      <?php endif ?>

      <?php if ($signedIn): ?>
        <h2 class="small"><?= e(t('books.marks')) ?></h2>
        <ul class="plain small" data-reader-marks></ul>
      <?php else: ?>
        <p class="small muted"><?= e(t('books.signInToMark')) ?></p>
      <?php endif ?>
    </aside>

    <div class="reader-stage">
      <div data-reader-page aria-live="polite"><p class="muted"><?= e(t('books.loading')) ?></p></div>
      <div class="notice warn" data-reader-fallback hidden>
        <p><?= e(t('books.cannotRender')) ?></p>
        <p><a class="button" href="<?= e(url('/api/files/' . $file['id'] . '/content')) ?>"><?= e(t('books.openInViewer')) ?></a></p>
      </div>
    </div>
  </div>
</div>
<script type="module" src="<?= e($script) ?>"></script>
