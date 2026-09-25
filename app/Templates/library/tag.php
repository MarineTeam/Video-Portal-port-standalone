<?php
/**
 * @var \App\Core\View $v
 * @var string $tag
 * @var list<array<string, mixed>> $series
 */
?>
<h1>#<?= e($tag) ?></h1>
<?= $v->partial('partials/library-series-tiles', ['series' => $series]) ?>
