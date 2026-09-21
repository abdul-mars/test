<?php
use QRoute\Core\View;
$e = [View::class, 'e'];
/** @var \QRoute\Models\Link $link @var bool $failed @var string $slug */
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Password required</title>
<meta name="robots" content="noindex">
<link rel="stylesheet" href="/assets/app.css">
<script src="/assets/theme-init.js" nonce="<?= $e(View::nonce()) ?>"></script>
</head>
<body>
<div class="interstitial">
  <div class="panel card">
    <h1 style="font-size:1.25rem">This code is protected</h1>
    <p class="muted small">Enter the password you were given to continue.</p>

    <?php if ($failed): ?>
      <div class="alert alert-error" role="alert">That password is not right.</div>
    <?php endif; ?>

    <form method="post" action="/<?= $e($slug) ?>">
      <div class="field">
        <label for="p">Password</label>
        <input type="password" id="p" name="p" autocomplete="off" autofocus required>
      </div>
      <button class="btn btn-block" type="submit">Continue</button>
    </form>
  </div>
</div>
</body>
</html>
