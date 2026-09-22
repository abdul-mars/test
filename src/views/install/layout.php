<?php
use QRoute\Core\View;
$e = [View::class, 'e'];
/** @var string $step @var list<string> $steps */
$labels = ['requirements' => 'Requirements', 'database' => 'Database', 'admin' => 'Administrator', 'done' => 'Finished'];
$current = array_search($step, $steps, true);
$current = $current === false ? 0 : $current;
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Set up QRoute — step <?= $current + 1 ?> of <?= count($steps) ?></title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="<?= $basePath ?>/assets/app.css">
<script src="<?= $basePath ?>/assets/theme-init.js" nonce="<?= $e(View::nonce()) ?>"></script>
</head>
<body>
<div class="wrap wrap-mid" style="padding-block:2rem">

  <div class="row" style="gap:.6rem;margin-bottom:1.6rem">
    <svg width="30" height="30" viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <rect x="1" y="1" width="9" height="9" rx="2" stroke="currentColor" stroke-width="2"/>
      <rect x="14" y="1" width="9" height="9" rx="2" stroke="currentColor" stroke-width="2"/>
      <rect x="1" y="14" width="9" height="9" rx="2" stroke="currentColor" stroke-width="2"/>
      <path d="M14 18h5m0 0-2.5-2.5M19 18l-2.5 2.5" stroke="var(--accent)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
    <div>
      <h1 style="font-size:1.35rem;margin:0">Set up QRoute</h1>
      <p class="faint" style="margin:0">Step <?= $current + 1 ?> of <?= count($steps) ?></p>
    </div>
  </div>

  <ol class="row" style="list-style:none;padding:0;margin:0 0 1.6rem;gap:.4rem">
    <?php foreach ($steps as $i => $s): ?>
      <li class="badge <?= $i < $current ? 'badge-success' : ($i === $current ? 'badge-accent' : '') ?>">
        <?php if ($i < $current): ?>&check;<?php else: ?><?= $i + 1 ?>.<?php endif; ?>
        <?= $e($labels[$s] ?? $s) ?>
      </li>
    <?php endforeach; ?>
  </ol>

  <?php if (!empty($error)): ?>
    <div class="alert alert-error" role="alert"><div><?= $e($error) ?></div></div>
  <?php endif; ?>
  <?php if (!empty($errors)): ?>
    <div class="alert alert-error" role="alert">
      <div><ul style="margin:0"><?php foreach ($errors as $err): ?><li><?= $e($err) ?></li><?php endforeach; ?></ul></div>
    </div>
  <?php endif; ?>

  <?= View::render('install/step_' . $step, get_defined_vars()) ?>

</div>
</body>
</html>
