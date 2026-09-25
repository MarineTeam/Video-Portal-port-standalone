<?php
/**
 * /prayer: the wall, and the form to ask.
 *
 * @var \App\Core\View $v
 * @var list<array<string, mixed>> $requests
 * @var bool $signedIn
 * @var bool $moderator
 * @var string $script
 */
?>
<h1><?= e(t('prayer.title')) ?></h1>
<p class="muted"><?= e(t('prayer.intro')) ?></p>
<form class="stack card narrow" data-prayer-form>
  <label><?= e(t('prayer.ask')) ?><textarea name="body" rows="3" required maxlength="2000" placeholder="<?= e(t('prayer.placeholder')) ?>"></textarea></label>
  <?php if (!$signedIn): ?>
    <label><?= e(t('prayer.yourName')) ?><input name="name" maxlength="255" autocomplete="name"></label>
  <?php endif ?>
  <label><?= e(t('prayer.whoMaySee')) ?>
    <select name="visibility">
      <option value="MEMBERS"><?= e(t('prayer.audienceMembers')) ?></option>
      <option value="EVERYONE"><?= e(t('prayer.audienceEveryone')) ?></option>
      <option value="LEADERS"><?= e(t('prayer.audienceLeaders')) ?></option>
    </select>
  </label>
  <label class="check"><input type="checkbox" name="anonymous"> <?= e(t('prayer.anonymously')) ?></label>
  <label class="hp" aria-hidden="true">Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
  <div><button class="button primary" type="submit"><?= e(t('prayer.send')) ?></button></div>
  <p class="notice ok" data-prayer-thanks hidden><?= e(t('prayer.thanks')) ?></p>
  <p class="error" data-error hidden></p>
</form>
<ul class="plain prayer-list" data-prayer-list data-labels="<?= e(\App\Core\View::json(['confirmDelete' => t('prayer.confirmDelete'), 'prayed' => t('prayer.prayed')])) ?>">
  <?php foreach ($requests as $r): ?>
    <?= $v->partial('prayer/request', ['r' => $r, 'moderator' => $moderator]) ?>
  <?php endforeach ?>
</ul>
<?php if ($requests === []): ?><p class="muted" data-prayer-none><?= e(t('prayer.empty')) ?></p><?php endif ?>
<script type="module" src="<?= e($script) ?>"></script>
