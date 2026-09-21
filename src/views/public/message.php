<?php
use QRoute\Core\View;
$e = [View::class, 'e'];
/** @var string $title @var string $message */
$cta = $cta ?? null;
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($title) ?></title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="/assets/app.css">
<script src="/assets/theme-init.js" nonce="<?= $e(View::nonce()) ?>"></script>
</head>
<body>
<div class="interstitial">
  <div class="panel card center">
    <svg width="42" height="42" viewBox="0 0 24 24" fill="none" aria-hidden="true" style="margin-bottom:.8rem">
      <rect x="2" y="2" width="8" height="8" rx="1.6" stroke="var(--text-faint)" stroke-width="2"/>
      <rect x="14" y="2" width="8" height="8" rx="1.6" stroke="var(--text-faint)" stroke-width="2"/>
      <rect x="2" y="14" width="8" height="8" rx="1.6" stroke="var(--text-faint)" stroke-width="2"/>
      <path d="M14 14h3v3h-3zM19 19h3v3h-3z" fill="var(--text-faint)"/>
    </svg>
    <h1 style="font-size:1.3rem"><?= $e($title) ?></h1>
    <p class="muted"><?= $e($message) ?></p>
    <?php if ($cta !== null): ?>
      <a class="btn" href="<?= $e($cta['href']) ?>"><?= $e($cta['label']) ?></a>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
