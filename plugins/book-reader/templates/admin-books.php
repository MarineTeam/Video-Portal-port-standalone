<?php
/**
 * Every file a reader can open, and what has been indexed on each.
 *
 * @var \App\Core\View $v
 * @var list<array<string, mixed>> $books
 */
?>
<h1><?= e(t('books.adminTitle')) ?></h1>
<p class="small muted"><?= e(t('books.adminHint')) ?></p>
<?php if ($books === []): ?>
  <p class="muted"><?= e(t('books.noBooks')) ?></p>
<?php else: ?>
  <table class="list">
    <thead><tr>
      <th><?= e(t('books.book')) ?></th>
      <th><?= e(t('books.contents')) ?></th>
      <th><?= e(t('books.textPages')) ?></th>
      <th></th>
    </tr></thead>
    <tbody>
      <?php foreach ($books as $book): ?>
        <tr>
          <td>
            <a href="<?= e(url('/admin/books/' . $book['id'])) ?>"><strong><?= e((string) $book['title']) ?></strong></a>
            <span class="badge"><?= e((string) $book['format']) ?></span>
            <?php if ($book['seriesTitle'] !== null): ?><div class="small muted"><?= e((string) $book['seriesTitle']) ?></div><?php endif ?>
          </td>
          <td><?= e($book['entries'] > 0 ? t('books.entryCount', ['count' => (string) $book['entries']]) : t('books.notIndexed')) ?></td>
          <td>
            <?php if ($book['textIndexedAt'] !== null): ?>
              <?= e(t('books.pageCount', ['count' => (string) $book['pages']])) ?>
            <?php elseif ($book['pages'] > 0): ?>
              <?= e(t('books.partlyRead', ['count' => (string) $book['pages']])) ?>
            <?php else: ?>
              <span class="muted"><?= e(t('books.notRead')) ?></span>
            <?php endif ?>
          </td>
          <td><a class="button small" href="<?= e(url('/read/' . $book['id'])) ?>"><?= e(t('books.reader')) ?></a></td>
        </tr>
      <?php endforeach ?>
    </tbody>
  </table>
<?php endif ?>
