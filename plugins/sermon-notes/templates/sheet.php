<?php
/**
 * @var \App\Core\View $v
 * @var string $videoId
 * @var list<list<array{text: string}|array{gap: int}>> $lines
 * @var string $version the sheet's fingerprint
 * @var array<string, string> $answers this member's, when written against this version
 * @var ?array<string, string> $stale answers written against an earlier version of the sheet
 * @var bool $signedIn
 * @var string $script
 */
?>
<section class="card note-sheet" aria-labelledby="sheet-h" data-note-sheet="<?= e($videoId) ?>" data-version="<?= e($version) ?>">
  <h2 id="sheet-h"><?= e(t('sermonNotes.sheet')) ?></h2>
  <?php if ($stale !== null): ?>
    <div class="notice warn small">
      <p><?= e(t('sermonNotes.sheetChangedSince')) ?></p>
      <?php if ($stale !== []): ?><p><?= e(t('sermonNotes.yourEarlierAnswers')) ?> <?= e(implode(' · ', array_values($stale))) ?></p><?php endif ?>
    </div>
  <?php endif ?>
  <div class="prose">
    <?php foreach ($lines as $line): ?>
      <p><?php foreach ($line as $segment): ?><?php if (isset($segment['gap'])): ?><?php if ($signedIn): ?><input class="gap" name="gap-<?= e((string) $segment['gap']) ?>" data-gap="<?= e((string) $segment['gap']) ?>" value="<?= e((string) ($answers[(string) $segment['gap']] ?? '')) ?>" maxlength="500" aria-label="<?= e(t('sermonNotes.blank', ['n' => (string) ($segment['gap'] + 1)])) ?>"><?php else: ?><span class="gap-line">________</span><?php endif ?><?php else: ?><?= e($segment['text']) ?><?php endif ?><?php endforeach ?></p>
    <?php endforeach ?>
  </div>
  <?php if ($signedIn): ?>
    <p class="small muted" data-sheet-status data-saved="<?= e(t('sermonNotes.saved')) ?>" aria-live="polite"><?= e(t('sermonNotes.savesAsYouType')) ?></p>
  <?php else: ?>
    <p class="small muted"><a href="<?= e(url('/auth/login')) ?>"><?= e(t('sermonNotes.signInToKeep')) ?></a></p>
  <?php endif ?>
</section>
<?php if ($signedIn): ?><script type="module" src="<?= e($script) ?>"></script><?php endif ?>
