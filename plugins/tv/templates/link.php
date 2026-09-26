<?php
/**
 * Signing a television in, from a phone.
 *
 * The code is typed here, and what comes back names the television and says
 * plainly what saying yes does — the code was on a screen in a public room,
 * so the person holding the phone may not be the person who turned the set
 * on.
 *
 * @var \App\Core\View $v
 * @var string $code
 * @var bool $signedIn
 * @var string $script
 */
?>
<h1><?= e(t('tv.link')) ?></h1>
<?php if (!$signedIn): ?>
  <p class="notice"><?= e(t('tv.signInFirst')) ?></p>
  <p><a class="button primary" href="<?= e(url('/auth/login?returnTo=/link')) ?>"><?= e(t('tv.signInHere')) ?></a></p>
<?php else: ?>
  <section class="card" data-tv-link data-code="<?= e($code) ?>">
    <form class="stack" data-tv-lookup>
      <label><?= e(t('tv.code')) ?>
        <input name="code" value="<?= e($code) ?>" required maxlength="9" autocomplete="off" autocapitalize="characters" spellcheck="false" inputmode="text" aria-describedby="tv-code-hint">
      </label>
      <p class="small muted" id="tv-code-hint"><?= e(t('tv.linkHint')) ?></p>
      <div><button class="button primary" type="submit"><?= e(t('tv.continue')) ?></button></div>
      <p class="error" data-error hidden></p>
    </form>
    <div data-tv-approve hidden>
      <p data-tv-prompt></p>
      <div class="row">
        <button class="button primary" type="button" data-tv-yes><?= e(t('tv.approve')) ?></button>
        <button class="button" type="button" data-tv-no><?= e(t('tv.deny')) ?></button>
      </div>
    </div>
    <p class="notice" data-tv-done hidden></p>
  </section>
<?php endif ?>
<script type="module" src="<?= e($script) ?>"></script>
