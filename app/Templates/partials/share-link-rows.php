<?php
/**
 * Share links as a table: what, to whom, state, and a revoke button.
 *
 * @var \App\Core\View $v
 * @var list<array<string, mixed>> $links presented by Sharing::present
 * @var string $api '/api/share-links' or '/api/admin/share-links'
 * @var bool $showOwner
 * @var bool $showContent
 */
?>
<table class="table">
  <thead><tr>
    <?php if ($showContent): ?><th><?= e(t('share.what')) ?></th><?php endif ?>
    <?php if ($showOwner): ?><th><?= e(t('share.owner')) ?></th><?php endif ?>
    <th><?= e(t('share.who')) ?></th><th><?= e(t('share.opens')) ?></th><th></th>
  </tr></thead>
  <tbody>
  <?php foreach ($links as $l): ?>
    <tr>
      <?php if ($showContent): ?><td><?php if ($l['contentPath'] !== null): ?><a href="<?= e(url((string) $l['contentPath'])) ?>"><?= e((string) $l['contentTitle']) ?></a><?php else: ?>—<?php endif ?>
        <?php if (!empty($l['note'])): ?><div class="small muted"><?= e((string) $l['note']) ?></div><?php endif ?></td><?php endif ?>
      <?php if ($showOwner): ?><td class="small"><?= e((string) $l['ownerEmail']) ?></td><?php endif ?>
      <td class="small">
        <?= e($l['visibility'] === 'PRIVATE' ? implode(', ', $l['recipients']) : t('share.anyone')) ?>
        <?php if ($l['grantsAccess']): ?><span class="badge"><?= e(t('share.grantsAccess')) ?></span><?php endif ?>
        <?php if ($l['hasPassword']): ?><span class="badge muted"><?= e(t('share.passworded')) ?></span><?php endif ?>
        <?php if ($l['status'] !== 'active'): ?><span class="badge muted"><?= e(t('share.state.' . $l['status'])) ?></span><?php elseif ($l['expiresAt'] !== null): ?><span class="small muted"> · <?= e(t('share.until', ['date' => substr((string) $l['expiresAt'], 0, 10)])) ?></span><?php endif ?>
      </td>
      <td><?= e((string) $l['viewCount']) ?></td>
      <td class="actions">
        <?php if ($l['status'] === 'active'): ?>
          <button type="button" class="button small" data-copy="<?= e((string) $l['url']) ?>"><?= e(t('library.copyLink')) ?></button>
          <button type="button" class="button small danger" data-api="<?= e($api . '/' . $l['id']) ?>" data-method="DELETE" data-confirm="<?= e(t('share.revokeConfirm')) ?>"><?= e(t('share.revoke')) ?></button>
        <?php endif ?>
      </td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
