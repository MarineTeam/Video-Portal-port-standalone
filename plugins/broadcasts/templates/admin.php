<?php
/**
 * One message to everybody, or to one group.
 *
 * The composer says who it will reach before it is sent, and why the rest it
 * will not: without that number "I told everyone" is false, and the people
 * who got it assume everybody did.
 *
 * @var \App\Core\View $v
 * @var list<array<string, mixed>> $broadcasts
 * @var array<string, list<array{id: string, name: string}>> $audiences
 * @var bool $smsReady
 * @var bool $pushReady
 * @var string $script
 */
?>
<h1><?= e(t('broadcasts.title')) ?></h1>

<section class="card" data-broadcast-new data-audiences="<?= e(\App\Core\View::json($audiences)) ?>"
         data-labels="<?= e(\App\Core\View::json([
             'reaches' => t('broadcasts.reaches', ['count' => '{count}']),
             'andMissed' => t('broadcasts.andMissed', ['count' => '{count}']),
             'smsCost' => t('broadcasts.smsCost', ['messages' => '{messages}', 'encoding' => '{encoding}', 'remaining' => '{remaining}']),
             'sendConfirm' => t('broadcasts.sendConfirm', ['count' => '{count}']),
             'sending' => t('broadcasts.sending', ['done' => '{done}', 'total' => '{total}']),
             'sent' => t('broadcasts.sent', ['count' => '{count}']),
             'testSent' => t('broadcasts.testSent'),
             'skips' => \App\Core\View::json([
                 'noEmail' => t('broadcasts.skip.noEmail'),
                 'announcementsOff' => t('broadcasts.skip.announcementsOff'),
                 'noPhone' => t('broadcasts.skip.noPhone'),
                 'badPhone' => t('broadcasts.skip.badPhone'),
                 'notOptedIn' => t('broadcasts.skip.notOptedIn'),
                 'noAccount' => t('broadcasts.skip.noAccount'),
                 'noDevice' => t('broadcasts.skip.noDevice'),
             ]),
         ])) ?>">
  <h2><?= e(t('broadcasts.new')) ?></h2>
  <form class="stack" data-broadcast-form>
    <label><?= e(t('broadcasts.subject')) ?><input name="subject" required maxlength="500"></label>
    <label><?= e(t('broadcasts.body')) ?><textarea name="body" rows="6" required maxlength="20000"></textarea></label>

    <fieldset>
      <legend><?= e(t('broadcasts.channels')) ?></legend>
      <label class="check"><input type="checkbox" name="channels" value="EMAIL" checked> <?= e(t('broadcasts.channel.email')) ?></label>
      <label class="check">
        <input type="checkbox" name="channels" value="SMS"<?php if (!$smsReady): ?> disabled<?php endif ?>> <?= e(t('broadcasts.channel.sms')) ?>
        <?php if (!$smsReady): ?><span class="small muted"><?= e(t('broadcasts.smsOff', ['missing' => t('broadcasts.smsMissing')])) ?></span><?php endif ?>
      </label>
      <label class="check">
        <input type="checkbox" name="channels" value="PUSH"<?php if (!$pushReady): ?> disabled<?php endif ?>> <?= e(t('broadcasts.channel.push')) ?>
      </label>
      <p class="small muted" data-sms-cost hidden></p>
    </fieldset>

    <label><?= e(t('broadcasts.audience')) ?>
      <select name="audience">
        <?php foreach (['EVERYONE', 'PERMISSION_GROUP', 'EVENT', 'SMALL_GROUP', 'TEAM'] as $audience): ?>
          <option value="<?= e($audience) ?>"><?= e(t('broadcasts.audience.' . strtolower($audience))) ?></option>
        <?php endforeach ?>
      </select>
    </label>
    <label data-audience-pick hidden><span data-audience-label></span>
      <select name="audienceId"></select>
    </label>

    <p class="notice" data-reach aria-live="polite"></p>
    <div class="row">
      <button class="button" type="button" data-broadcast-test><?= e(t('broadcasts.testFirst')) ?></button>
      <button class="button primary" type="submit"><?= e(t('broadcasts.send')) ?></button>
    </div>
    <p class="small muted"><?= e(t('broadcasts.testHint')) ?></p>
    <p class="error" data-error hidden></p>
    <p class="notice" data-progress hidden></p>
  </form>
</section>

<section class="card">
  <ul class="plain">
    <?php foreach ($broadcasts as $broadcast): ?>
      <li class="row" data-broadcast="<?= e((string) $broadcast['id']) ?>">
        <a href="<?= e(url('/admin/broadcasts/' . $broadcast['id'])) ?>"><strong><?= e((string) $broadcast['subject']) ?></strong></a>
        <span class="badge"><?= e(t('broadcasts.status.' . $broadcast['status'])) ?></span>
        <span class="small muted"><?= e((string) $broadcast['audienceLabel']) ?></span>
        <span class="small muted"><?= e(t('broadcasts.reaches', ['count' => (string) $broadcast['progress']['total']])) ?></span>
        <?php if ($broadcast['progress']['failed'] > 0): ?>
          <span class="badge warn"><?= e(t('broadcasts.failures')) ?>: <?= e((string) $broadcast['progress']['failed']) ?></span>
        <?php endif ?>
        <time class="small muted" datetime="<?= e((string) $broadcast['createdAt']) ?>" data-local-time><?= e((string) $broadcast['createdAt']) ?></time>
      </li>
    <?php endforeach ?>
  </ul>
  <?php if ($broadcasts === []): ?><p class="muted"><?= e(t('broadcasts.nothingToSend')) ?></p><?php endif ?>
</section>
<script type="module" src="<?= e($script) ?>"></script>
