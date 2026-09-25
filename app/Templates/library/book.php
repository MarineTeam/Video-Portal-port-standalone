<?php
/**
 * @var \App\Core\View $v
 * @var string $book
 * @var list<array<string, mixed>> $videos
 */
?>
<p class="crumbs small"><a href="<?= e(url('/scripture')) ?>"><?= e(t('library.scripture')) ?></a></p>
<h1><?= e($book) ?></h1>
<?= $v->partial('partials/library-videos', ['videos' => $videos]) ?>
