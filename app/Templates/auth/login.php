<?php
/**
 * @var \App\Core\View $v
 * @var ?string $error
 * @var string $email
 * @var string $returnTo
 * @var bool $magicLink
 * @var bool $selfRegistration
 * @var ?array{label: string, flow: string, widget: ?array{scripts: list<string>, config: array<string, mixed>, csp: array<string, list<string>>}, trial: bool} $external
 * @var bool $localOpen whether members may use the password form beside an external provider
 * @var array $shell
 */
?>
<h1><?= e(t('auth.signInTitle')) ?></h1>
<?php if ($error !== null): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif ?>
<?php if ($external !== null): ?>
  <?php if ($external['flow'] === 'redirect'): ?>
    <p><a class="button primary" href="<?= e(url('/auth/start', array_filter(['returnTo' => $returnTo, 'trial' => $external['trial'] ? '1' : null]))) ?>"><?= e(t('auth.continueWith', ['provider' => $external['label']])) ?></a></p>
  <?php elseif ($external['widget'] !== null): ?>
    <div id="external-sign-in" data-token-sign-in="<?= e(\App\Core\View::json($external['widget']['config'] + ['returnTo' => $returnTo, 'trial' => $external['trial']])) ?>"></div>
    <p class="error" data-token-error hidden></p>
    <?php foreach ($external['widget']['scripts'] as $src): ?><script src="<?= e($src) ?>" crossorigin="anonymous" defer data-token-sdk></script>
    <?php endforeach ?>
    <script type="module" src="<?= e(asset('js/token-sign-in.js')) ?>"></script>
  <?php endif ?>
  <details class="card"<?= $error !== null ? ' open' : '' ?>>
    <summary><?= e($localOpen ? t('auth.withPassword') : t('auth.adminSignIn')) ?></summary>
<?php endif ?>
<?php if ($magicLink): ?>
<form method="post" action="<?= e(url('/auth/magic')) ?>" class="stack">
  <?= $v->raw(csrf_field()) ?>
  <label><?= e(t('auth.email')) ?><input type="email" name="email" autocomplete="email" required value="<?= e($email) ?>"></label>
  <button type="submit" class="button primary"><?= e(t('auth.emailMeALink')) ?></button>
</form>
<p class="divider"><span>or</span></p>
<?php endif ?>
<form method="post" action="<?= e(url('/auth/login')) ?>" class="stack">
  <?= $v->raw(csrf_field()) ?>
  <input type="hidden" name="returnTo" value="<?= e($returnTo) ?>">
  <label><?= e(t('auth.email')) ?><input type="email" name="email" autocomplete="username" required value="<?= e($email) ?>"></label>
  <label><?= e(t('auth.password')) ?><input type="password" name="password" autocomplete="current-password" required></label>
  <button type="submit" class="button<?= $magicLink ? '' : ' primary' ?>"><?= e(t('auth.signInButton')) ?></button>
</form>
<p class="small"><a href="<?= e(url('/auth/reset')) ?>"><?= e(t('auth.forgot')) ?></a>
<?php if ($selfRegistration): ?> · <a href="<?= e(url('/auth/register')) ?>"><?= e(t('auth.register')) ?></a><?php endif ?></p>
<?php if ($external !== null): ?>
  </details>
<?php endif ?>
