<?php
use QRoute\Core\View;
$e = [View::class, 'e'];
/** @var string $lockFile */
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Setup is closed</title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<div class="interstitial">
  <div class="panel card center">
    <h1 style="font-size:1.25rem">Setup has already run</h1>
    <p class="muted small">
      QRoute is installed, so the installer is closed. Leaving it open would
      let anyone reconfigure this site.
    </p>
    <p class="faint small">
      If you really need to run setup again, delete
      <code><?= $e($lockFile) ?></code> from the project folder first.
    </p>
    <a class="btn" href="/login">Go to sign in</a>
  </div>
</div>
</body>
</html>
