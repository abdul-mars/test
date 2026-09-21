<?php
use QRoute\Core\View;
$e = [View::class, 'e'];
/** @var int $status @var string $message */
?>
<div class="wrap wrap-narrow center" style="padding-block:clamp(3rem,2rem+6vw,6rem)">
  <div style="font-size:3.4rem;font-weight:700;letter-spacing:-.04em;color:var(--text-faint)">
    <?= (int) $status ?>
  </div>
  <h1 style="font-size:1.35rem"><?= $e($message) ?></h1>
  <p class="muted"><?= $e($detail ?? 'Something went wrong handling that request.') ?></p>
  <a class="btn" href="/">Back to safety</a>
</div>
