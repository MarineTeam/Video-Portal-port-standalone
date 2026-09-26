<?php
/**
 * One book: its contents, and the three passes that fill them in.
 *
 * All three run in this browser, because that is where pdf.js is — the
 * server has no PDF library and shared hosting has nowhere to run one.
 *
 * @var \App\Core\View $v
 * @var array<string, mixed> $file
 * @var string $contentsText
 * @var string $script
 */
?>
<p><a href="<?= e(url('/admin/books')) ?>">← <?= e(t('books.adminTitle')) ?></a></p>
<h1><?= e((string) $file['title']) ?></h1>

<div data-book-admin data-file-id="<?= e((string) $file['id']) ?>" data-format="<?= e((string) $file['format']) ?>"
     data-labels="<?= e(\App\Core\View::json([
         'reading' => t('books.loading'),
         'noOutline' => t('books.noOutline'),
         'contentsSaved' => t('books.contentsSaved', ['count' => '{count}']),
         'readingText' => t('books.readingText', ['page' => '{page}', 'count' => '{count}']),
         'textRead' => t('books.textRead', ['count' => '{count}']),
         'ocrNeeded' => t('books.ocrNeeded'),
         'wordsSaved' => t('books.wordsSaved'),
     ])) ?>">

  <p class="notice small" data-book-status hidden></p>

  <?php if ($file['format'] === 'PDF'): ?>
    <section class="card">
      <h2><?= e(t('books.fromBookmarks')) ?></h2>
      <p class="small muted"><?= e(t('books.fromBookmarksHint')) ?></p>
      <p><button class="button" type="button" data-book-outline><?= e(t('books.readOutline')) ?></button></p>
    </section>
  <?php endif ?>

  <section class="card">
    <h2><?= e(t('books.contents')) ?></h2>
    <p class="small muted"><?= e(t('books.contentsHint')) ?></p>
    <form class="stack" data-book-contents>
      <label><?= e(t('books.pageOffset')) ?>
        <input name="pageOffset" type="number" value="<?= e((string) $file['pageOffset']) ?>" min="-5000" max="5000">
      </label>
      <p class="small muted"><?= e(t('books.pageOffsetHint')) ?></p>
      <label><?= e(t('books.contentsBox')) ?>
        <textarea name="text" rows="14" spellcheck="false"><?= e($contentsText) ?></textarea>
      </label>
      <div><button class="button primary" type="submit"><?= e(t('common.save')) ?></button></div>
    </form>
    <ul class="plain small error" data-book-problems></ul>
  </section>

  <?php if ($file['format'] === 'PDF'): ?>
    <section class="card">
      <h2><?= e(t('books.readTheText')) ?></h2>
      <p class="small muted"><?= e(t('books.readTheTextHint')) ?></p>
      <p>
        <?php if ($file['textIndexedAt'] !== null): ?>
          <?= e(t('books.pageCount', ['count' => (string) $file['pages']])) ?>
        <?php elseif ($file['pages'] > 0): ?>
          <?= e(t('books.partlyRead', ['count' => (string) $file['pages']])) ?>
        <?php endif ?>
      </p>
      <p class="row">
        <button class="button" type="button" data-book-text><?= e(t('books.readTheText')) ?></button>
        <button class="button small" type="button" data-book-stop hidden><?= e(t('books.stopReading')) ?></button>
        <?php if ($file['pages'] > 0): ?>
          <button class="button small danger" type="button" data-book-forget data-confirm="<?= e(t('books.forgetTextConfirm')) ?>"><?= e(t('books.forgetText')) ?></button>
        <?php endif ?>
      </p>
    </section>
  <?php endif ?>

  <section>
    <h2><?= e(t('books.words')) ?></h2>
    <p class="small muted"><?= e(t('books.wordsHint')) ?></p>
    <form class="stack" data-book-lyrics>
      <label><?= e(t('books.wordsNumber')) ?><input name="number" type="number" min="1" max="99999" data-null inputmode="numeric"></label>
      <label><?= e(t('books.wordsText')) ?><textarea name="words" rows="10" data-null placeholder="<?= e(t('books.wordsPlaceholder')) ?>"></textarea></label>
      <div class="row">
        <label><?= e(t('books.wordsAuthor')) ?><input name="author" maxlength="255" data-null></label>
        <label><?= e(t('books.wordsCopyright')) ?><input name="copyright" maxlength="500" data-null></label>
      </div>
      <div class="row">
        <label><?= e(t('books.wordsCcli')) ?><input name="ccli" maxlength="32" data-null></label>
        <label><?= e(t('books.wordsKey')) ?><input name="key" maxlength="16" data-null></label>
        <label><?= e(t('books.wordsTempo')) ?><input name="tempo" type="number" min="20" max="400" data-null inputmode="numeric"></label>
      </div>
      <p class="row">
        <button class="button primary" type="submit"><?= e(t('books.wordsSave')) ?></button>
        <span class="small" data-book-lyrics-status></span>
      </p>
    </form>
  </section>
</div>
<script type="module" src="<?= e($script) ?>"></script>
