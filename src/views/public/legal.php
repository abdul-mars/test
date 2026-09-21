<?php
use QRoute\Core\View;
$e = [View::class, 'e'];
/** @var string $heading @var list<array{0:string,1:string}> $sections */
?>
<div class="wrap wrap-mid" style="padding-block:2rem">
  <h1><?= $e($heading) ?></h1>
  <p class="faint">Last updated <?= $e(gmdate('j F Y')) ?></p>
  <div class="stack" style="--gap:1.4rem;margin-top:1.6rem">
    <?php foreach ($sections as [$title, $body]): ?>
      <section>
        <h2 style="font-size:1.08rem"><?= $e($title) ?></h2>
        <p class="muted" style="margin:0"><?= $e($body) ?></p>
      </section>
    <?php endforeach; ?>
  </div>
</div>
