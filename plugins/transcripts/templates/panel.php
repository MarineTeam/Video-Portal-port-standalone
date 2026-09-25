<?php
/**
 * @var \App\Core\View $v
 * @var string $text
 */
?>
<details class="card transcript"><summary><?= e(t('transcripts.title')) ?></summary><div class="prose"><?= $v->raw(nl2br(e($text))) ?></div></details>
