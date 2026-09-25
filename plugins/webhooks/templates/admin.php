<?php
/**
 * @var \App\Core\View $v
 * @var list<array<string, mixed>> $hooks
 */
?>
<h1>Webhooks</h1>
<p class="muted">Each address below gets a JSON POST when a series or video is published: <code>{"event": "video.published", "id", "title", "slug", "url", "memberOnly", "publishedAt"}</code>. With a secret, the body is signed as <code>X-Webhook-Signature</code> (hex HMAC-SHA256). Addresses must be public.</p>
<form class="stack card narrow" data-api="/api/admin/webhooks" data-method="POST">
  <label>Address<input name="url" type="url" required placeholder="https://example.org/hooks/church" maxlength="2000"></label>
  <label>Secret (optional)<input name="secret" type="password" autocomplete="new-password" data-null maxlength="500"></label>
  <label class="check"><input type="checkbox" name="active" checked> Active</label>
  <div><button class="button primary" type="submit">Add webhook</button></div>
  <p class="error" data-error hidden></p>
</form>
<?php foreach ($hooks as $hook): ?>
  <details class="card">
    <summary><code><?= e($hook['url']) ?></code> <span class="badge<?= $hook['active'] ? '' : ' muted' ?>"><?= e($hook['active'] ? 'active' : 'off') ?></span><?php if ($hook['secretSet']): ?> <span class="badge muted">signed</span><?php endif ?></summary>
    <form class="stack narrow" data-api="/api/admin/webhooks/<?= e($hook['id']) ?>" data-method="PATCH">
      <label>Address<input name="url" type="url" required value="<?= e($hook['url']) ?>" maxlength="2000"></label>
      <label class="check"><input type="checkbox" name="active"<?= $hook['active'] ? ' checked' : '' ?>> Active</label>
      <div class="row">
        <button class="button primary" type="submit">Save</button>
        <button type="button" class="button" data-api="/api/admin/webhooks/<?= e($hook['id']) ?>/test" data-method="POST" data-no-reload data-done="Delivered.">Send a test</button>
        <button type="button" class="button danger" data-api="/api/admin/webhooks/<?= e($hook['id']) ?>" data-method="DELETE" data-confirm="Delete this webhook?">Delete</button>
      </div>
      <p class="small" data-done-message hidden></p>
      <p class="error" data-error hidden></p>
    </form>
    <form class="row narrow" data-api="/api/admin/webhooks/<?= e($hook['id']) ?>" data-method="PATCH">
      <label>New secret (empty removes it)<input name="secret" type="password" autocomplete="new-password" maxlength="500"></label>
      <button class="button" type="submit">Set secret</button>
      <p class="error" data-error hidden></p>
    </form>
  </details>
<?php endforeach ?>
