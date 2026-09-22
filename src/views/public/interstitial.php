<?php
use QRoute\Core\View;
$e = [View::class, 'e'];
/** @var string $target @var int $delay @var string $adSlot */
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Taking you there&hellip;</title>
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="strict-origin-when-cross-origin">
<link rel="stylesheet" href="<?= $basePath ?>/assets/app.css">
<script src="<?= $basePath ?>/assets/theme-init.js" nonce="<?= $e($nonce) ?>"></script>
</head>
<body>
<div class="interstitial">
  <div class="panel card">
    <svg class="countdown-ring" viewBox="0 0 36 36" aria-hidden="true">
      <circle cx="18" cy="18" r="16" fill="none" stroke="var(--border)" stroke-width="3"/>
      <circle id="ring" cx="18" cy="18" r="16" fill="none" stroke="var(--accent)" stroke-width="3"
              stroke-linecap="round" stroke-dasharray="100.5" stroke-dashoffset="100.5"
              transform="rotate(-90 18 18)"/>
    </svg>

    <h1 style="font-size:1.25rem;margin-bottom:.25rem">Taking you there&hellip;</h1>
    <p class="muted small" style="margin-bottom:0">You will arrive in a moment.</p>
    <p class="dest"><?= $e($target) ?></p>

    <div class="ad-slot">
      <?php if (trim((string) $adSlot) !== ''): ?>
        <?= $adSlot /* operator-supplied markup, set in configuration */ ?>
      <?php else: ?>
        Advertisement
      <?php endif; ?>
    </div>

    <a class="btn btn-block" href="<?= $e($target) ?>" rel="nofollow noopener" id="go">Continue now</a>

    <p class="faint" style="margin:1rem 0 0">
      This short pause is shown on free QRoute codes.
      <a href="<?= $basePath ?>/pricing">Remove it</a> — or
      <a href="<?= $basePath ?>/register">make your own code</a>.
    </p>
  </div>
</div>

<noscript>
  <p class="center"><a href="<?= $e($target) ?>">Continue to your destination</a></p>
</noscript>

<script nonce="<?= $e($nonce) ?>">
(function () {
  var delay = <?= (int) $delay ?> * 1000;
  var ring = document.getElementById('ring');
  var start = Date.now();
  var C = 100.5;
  function tick() {
    var p = Math.min(1, (Date.now() - start) / delay);
    if (ring) ring.setAttribute('stroke-dashoffset', String(C * (1 - p)));
    if (p < 1) requestAnimationFrame(tick);
  }
  if (delay > 0) requestAnimationFrame(tick); else if (ring) ring.setAttribute('stroke-dashoffset', '0');
  setTimeout(function () { window.location.replace(document.getElementById('go').href); }, delay);
})();
</script>
</body>
</html>
