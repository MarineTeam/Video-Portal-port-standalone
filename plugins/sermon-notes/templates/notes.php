<?php
/**
 * @var \App\Core\View $v
 * @var string $videoId
 * @var list<array<string, mixed>> $notes in time order
 * @var string $script
 */
use App\Support\Timestamp;

?>
<section class="card" aria-labelledby="notes-h" data-notes="<?= e($videoId) ?>" data-delete-label="<?= e(t('sermonNotes.delete')) ?>">
  <h2 id="notes-h"><?= e(t('sermonNotes.myNotes')) ?></h2>
  <p class="small muted"><?= e(t('sermonNotes.private')) ?></p>
  <ul class="plain" data-note-list>
    <?php foreach ($notes as $n): ?>
      <li data-note="<?= e($n['id']) ?>"><button type="button" class="link chapter-time" data-seek="<?= e((string) $n['timestampSeconds']) ?>"><?= e(Timestamp::format((int) $n['timestampSeconds'])) ?></button> <span data-note-body><?= e($n['body']) ?></span> <button type="button" class="link small" data-note-delete><?= e(t('sermonNotes.delete')) ?></button></li>
    <?php endforeach ?>
  </ul>
  <form class="row" data-note-form>
    <input name="timestamp" size="7" placeholder="0:00" pattern="[0-9:hms]*" aria-label="<?= e(t('sermonNotes.at')) ?>" data-note-time>
    <input name="body" required maxlength="5000" placeholder="<?= e(t('sermonNotes.placeholder')) ?>" aria-label="<?= e(t('sermonNotes.note')) ?>" style="flex: 1">
    <button class="button small primary" type="submit"><?= e(t('sermonNotes.add')) ?></button>
  </form>
  <p class="error small" data-error hidden></p>
  <p class="small"><a href="<?= e(url('/api/notes?format=text&videoId=' . rawurlencode($videoId))) ?>" download><?= e(t('sermonNotes.export')) ?></a></p>
</section>
<script type="module" src="<?= e($script) ?>"></script>
