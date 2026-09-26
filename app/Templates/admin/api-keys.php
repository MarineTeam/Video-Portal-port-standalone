<?php
/**
 * API keys.
 *
 * A key is shown once, here, and never again. What the list shows afterwards
 * is the prefix, who made it and when it was last used — the three things
 * anybody wants after deciding one has leaked.
 *
 * @var \App\Core\View $v
 * @var list<array<string, mixed>> $keys
 * @var list<array<string, mixed>> $scopes
 * @var ?array{key: string, name: string} $made
 * @var string $docsUrl
 */
?>
<h1>API keys</h1>
<p class="muted">Another system reading this one: a noticeboard in the foyer, a spreadsheet, the church website. Everything a key can do is a read — <a href="<?= e($docsUrl) ?>">the API describes itself</a>, without a key.</p>

<section class="card" data-api-keys>
  <h2>New key</h2>
  <form class="stack" data-api-key-new>
    <label>What it is for<input name="name" required maxlength="255" placeholder="The noticeboard in the foyer"></label>
    <fieldset>
      <legend>What it may read</legend>
      <?php foreach ($scopes as $scope): ?>
        <label class="check">
          <input type="checkbox" name="scopes" value="<?= e((string) $scope['scope']) ?>">
          <span>
            <strong><?= e((string) $scope['label']) ?></strong>
            <?php if ($scope['personal']): ?><span class="badge warn">Personal data</span><?php endif ?>
            <span class="small muted"><?= e((string) $scope['hint']) ?></span>
            <code class="small"><?= e((string) $scope['scope']) ?></code>
          </span>
        </label>
      <?php endforeach ?>
    </fieldset>
    <label>Expires (optional)<input name="expiresAt" type="date"></label>
    <p class="small muted">A key with a date on it is one somebody has thought about; a key without one lives until it is revoked.</p>
    <div><button class="button primary" type="submit">Make the key</button></div>
    <p class="error" data-error hidden></p>
  </form>

  <div class="notice ok" data-api-key-made hidden>
    <p><strong>Copy this now.</strong> It is the only time it is shown.</p>
    <p><code data-api-key-value></code></p>
    <p><button class="button small" type="button" data-api-key-copy>Copy</button></p>
  </div>
</section>

<section class="card">
  <?php if ($keys === []): ?>
    <p class="muted">No keys have been made yet.</p>
  <?php else: ?>
    <table class="list">
      <thead><tr><th>Name</th><th>Key</th><th>May read</th><th>Made</th><th>Last used</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($keys as $key): ?>
          <tr data-api-key="<?= e((string) $key['id']) ?>">
            <td>
              <strong><?= e((string) $key['name']) ?></strong>
              <?php if ($key['state'] === 'revoked'): ?><span class="badge muted">Revoked</span>
              <?php elseif ($key['state'] === 'expired'): ?><span class="badge muted">Expired</span><?php endif ?>
              <?php if ($key['expiresAt'] !== null && $key['state'] === 'ok'): ?>
                <div class="small muted">Expires <time datetime="<?= e((string) $key['expiresAt']) ?>" data-local-date><?= e((string) $key['expiresAt']) ?></time></div>
              <?php endif ?>
            </td>
            <td><code class="small"><?= e((string) $key['prefix']) ?>…</code></td>
            <td class="small"><?= e(implode(', ', $key['scopes'])) ?></td>
            <td class="small">
              <time datetime="<?= e((string) $key['createdAt']) ?>" data-local-date><?= e((string) $key['createdAt']) ?></time>
              <div class="muted"><?= e((string) $key['createdByEmail']) ?></div>
            </td>
            <td class="small">
              <?php if ($key['lastUsedAt'] === null): ?><span class="muted">Never</span>
              <?php else: ?><time datetime="<?= e((string) $key['lastUsedAt']) ?>" data-local-time><?= e((string) $key['lastUsedAt']) ?></time><?php endif ?>
            </td>
            <td>
              <?php if ($key['revokedAt'] === null): ?>
                <button class="button small danger" type="button" data-api="/api/admin/api-keys/<?= e((string) $key['id']) ?>" data-method="DELETE" data-confirm="Revoke this key? Anything using it stops working at once.">Revoke</button>
              <?php endif ?>
            </td>
          </tr>
        <?php endforeach ?>
      </tbody>
    </table>
  <?php endif ?>
</section>
<script type="module" src="<?= e(asset('js/api-keys.js')) ?>"></script>
