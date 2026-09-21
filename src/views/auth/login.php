<?php
use QRoute\Core\View;
$e = [View::class, 'e'];
$error = $error ?? '';
$email = $email ?? '';
?>
<div class="wrap wrap-narrow" style="padding-block:clamp(2rem,1rem+4vw,4rem)">
  <h1 style="font-size:1.6rem">Welcome back</h1>
  <p class="muted">Sign in to manage your codes.</p>

  <?php if ($error !== ''): ?>
    <div class="alert alert-error" role="alert"><?= $e($error) ?></div>
  <?php endif; ?>

  <form method="post" action="/login" class="card">
    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
    <div class="field">
      <label for="email">Email</label>
      <input type="email" id="email" name="email" value="<?= $e($email) ?>"
             autocomplete="email" required autofocus>
    </div>
    <div class="field">
      <label for="password">Password</label>
      <input type="password" id="password" name="password" autocomplete="current-password" required>
    </div>
    <button class="btn btn-block" type="submit">Sign in</button>
  </form>

  <p class="center small muted" style="margin-top:1.1rem">
    No account yet? <a href="/register">Create one free</a>
  </p>
</div>
