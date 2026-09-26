<?php
/**
 * A hymn's credits. A licence return needs the CCLI number and the
 * copyright line; the key and tempo are for whoever plays.
 *
 * @var \App\Core\View $v
 * @var array<string, mixed>|null $credits
 */
?>
<?php if ($credits !== null && array_filter($credits, fn ($value) => $value !== null && $value !== '') !== []): ?>
  <p class="small muted">
    <?php if (($credits['author'] ?? null) !== null): ?><?= e((string) $credits['author']) ?><?php endif ?>
    <?php if (($credits['copyright'] ?? null) !== null): ?> · <?= e((string) $credits['copyright']) ?><?php endif ?>
    <?php if (($credits['ccli'] ?? null) !== null): ?> · CCLI <?= e((string) $credits['ccli']) ?><?php endif ?>
    <?php if (($credits['key'] ?? null) !== null): ?> · <?= e((string) $credits['key']) ?><?php endif ?>
    <?php if (($credits['tempo'] ?? null) !== null): ?> · <?= e((string) $credits['tempo']) ?> bpm<?php endif ?>
  </p>
<?php endif ?>
