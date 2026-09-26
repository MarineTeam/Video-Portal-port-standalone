<?php
/**
 * The file itself. The in-app reader (contents, search, highlights,
 * read-aloud, the offline copy) is not built yet; meanwhile the browser's
 * own viewer opens it, through the app's access-checked content route.
 *
 * @var \App\Core\View $v
 * @var array{id: string, title: string, mime: string} $file
 */
?>
<h1><?= e($file['title']) ?></h1>
<p class="row">
  <a class="button small" href="<?= e(url('/books/' . $file['id'])) ?>"><?= e(t('books.contents')) ?></a>
  <a class="button small" href="<?= e(url('/api/files/' . $file['id'] . '/content')) ?>" download><?= e(t('books.download')) ?></a>
</p>
<object class="book-frame" data="<?= e(url('/api/files/' . $file['id'] . '/content')) ?>" type="<?= e($file['mime']) ?>">
  <p><?= e(t('books.cannotShow')) ?></p>
</object>
