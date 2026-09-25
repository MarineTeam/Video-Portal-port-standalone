<?php
/**
 * @var \App\Core\View $v
 * @var array{average: ?float, count: int, mine: ?int} $summary
 * @var bool $signedIn
 * @var array<string, string> $body
 * @var string $script
 */
$shown = (int) round((float) ($summary['average'] ?? 0));
?>
<span class="star-rating" data-rating="<?= e(\App\Core\View::json($body)) ?>" data-mine="<?= e((string) ($summary['mine'] ?? '')) ?>"
  data-summary-template="<?= e(t('ratings.summary', ['average' => '{average}', 'count' => '{count}'])) ?>" data-none-text="<?= e(t('ratings.none')) ?>">
  <?php if ($signedIn): ?>
    <?php for ($i = 1; $i <= 5; $i++): ?><button type="button" data-stars="<?= e((string) $i) ?>" aria-pressed="<?= e(($summary['mine'] ?? 0) >= $i ? 'true' : 'false') ?>" aria-label="<?= e(t($i === 1 ? 'ratings.rateOne' : 'ratings.rate', ['count' => (string) $i])) ?>">★</button><?php endfor ?>
  <?php else: ?>
    <span aria-hidden="true"><?php for ($i = 1; $i <= 5; $i++): ?><span class="<?= e($i <= $shown ? 'star-on' : '') ?>">★</span><?php endfor ?></span>
  <?php endif ?>
  <span class="small muted" data-rating-summary><?= e($summary['count'] > 0 ? t('ratings.summary', ['average' => number_format((float) $summary['average'], 1), 'count' => (string) $summary['count']]) : t('ratings.none')) ?></span>
</span>
<?php if ($signedIn): ?><script type="module" src="<?= e($script) ?>"></script><?php endif ?>
