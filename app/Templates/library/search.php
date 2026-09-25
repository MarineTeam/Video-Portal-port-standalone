<?php
/**
 * @var \App\Core\View $v
 * @var string $q
 * @var array{categoryId: ?string, speakerId: ?string, sort: string} $filters
 * @var ?array{categories: list<array<string, mixed>>, series: list<array<string, mixed>>, videos: list<array<string, mixed>>, speakers: list<array<string, mixed>>, extra: list<array<string, mixed>>, fuzzy: bool} $results
 * @var list<array<string, mixed>> $categories
 * @var list<array{id: string, name: string}> $speakers
 */
$none = $results !== null && $results['categories'] === [] && $results['series'] === [] && $results['videos'] === [] && $results['speakers'] === [] && $results['extra'] === [];
?>
<h1><?= e(t('nav.search')) ?></h1>
<form method="get" class="row search-form" role="search">
  <input type="search" name="q" value="<?= e($q) ?>" maxlength="100" aria-label="<?= e(t('nav.search')) ?>" autofocus>
  <select name="category" aria-label="<?= e(t('library.categories')) ?>">
    <option value=""><?= e(t('search.anyCategory')) ?></option>
    <?php foreach ($categories as $c): ?><option value="<?= e($c['id']) ?>"<?= $filters['categoryId'] === $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach ?>
  </select>
  <?php if ($speakers !== []): ?>
  <select name="speaker" aria-label="<?= e(t('library.speaker')) ?>">
    <option value=""><?= e(t('search.anySpeaker')) ?></option>
    <?php foreach ($speakers as $s): ?><option value="<?= e($s['id']) ?>"<?= $filters['speakerId'] === $s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach ?>
  </select>
  <?php endif ?>
  <select name="sort" aria-label="<?= e(t('search.sort')) ?>">
    <option value="relevance"><?= e(t('search.relevance')) ?></option>
    <option value="newest"<?= $filters['sort'] === 'newest' ? ' selected' : '' ?>><?= e(t('search.newest')) ?></option>
  </select>
  <button class="button primary" type="submit"><?= e(t('nav.search')) ?></button>
</form>
<?php if ($results !== null): ?>
  <?php if ($none): ?>
    <p class="muted"><?= e(t('search.none', ['q' => $q])) ?></p>
  <?php else: ?>
    <?php if ($results['fuzzy']): ?><p class="small muted"><?= e(t('search.fuzzy')) ?></p><?php endif ?>
    <?php if ($results['categories'] !== [] || $results['series'] !== []): ?>
      <h2><?= e(t('library.series')) ?></h2>
      <?= $v->partial('partials/library-series-tiles', ['categories' => $results['categories'], 'series' => $results['series']]) ?>
    <?php endif ?>
    <?php if ($results['videos'] !== []): ?>
      <h2><?= e(t('library.moreVideos')) ?></h2>
      <?= $v->partial('partials/library-videos', ['videos' => $results['videos']]) ?>
    <?php endif ?>
    <?php if ($results['speakers'] !== []): ?>
      <h2><?= e(t('library.speakers')) ?></h2>
      <ul class="chips plain">
        <?php foreach ($results['speakers'] as $s): ?><li><a class="chip" href="<?= e(url('/speakers/' . $s['slug'])) ?>"><?= e($s['name']) ?></a></li><?php endforeach ?>
      </ul>
    <?php endif ?>
    <?php foreach ($results['extra'] as $section): ?>
      <?php if (!empty($section['items'])): ?>
        <h2><?= e((string) ($section['title'] ?? '')) ?></h2>
        <ul class="plain stack">
          <?php foreach ($section['items'] as $item): ?><li><a href="<?= e(url((string) $item['href'])) ?>"><?= e((string) $item['title']) ?></a><?php if (!empty($item['detail'])): ?> <span class="small muted"><?= e((string) $item['detail']) ?></span><?php endif ?></li><?php endforeach ?>
        </ul>
      <?php endif ?>
    <?php endforeach ?>
  <?php endif ?>
<?php endif ?>
