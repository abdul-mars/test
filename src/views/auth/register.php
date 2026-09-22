<?php
use QRoute\Core\View;
$e = [View::class, 'e'];
$error = $error ?? '';
$email = $email ?? '';
?>
<div class="wrap wrap-narrow" style="padding-block:clamp(2rem,1rem+4vw,4rem)">
  <h1 style="font-size:1.6rem">Create your account</h1>
  <p class="muted">Three dynamic codes, free forever. No card required.</p>

  <?php if ($error !== ''): ?>
    <div class="alert alert-error" role="alert"><?= $e($error) ?></div>
  <?php endif; ?>

  <form method="post" action="<?= $basePath ?>/register" class="card">
    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
    <div class="field">
      <label for="email">Email</label>
      <input type="email" id="email" name="email" value="<?= $e($email) ?>"
             autocomplete="email" required autofocus>
    </div>
    <div class="field">
      <label for="password">Password</label>
      <input type="password" id="password" name="password" autocomplete="new-password"
             minlength="10" required aria-describedby="pw-hint">
      <p class="hint" id="pw-hint">At least 10 characters. A passphrase works well.</p>
    </div>
    <button class="btn btn-block" type="submit">Create account</button>
    <p class="hint center" style="margin-top:.8rem">
      By continuing you agree to our <a href="<?= $basePath ?>/legal/terms">terms</a> and
      <a href="<?= $basePath ?>/legal/privacy">privacy policy</a>.
    </p>
  </form>

  <p class="center small muted" style="margin-top:1.1rem">
    Already have an account? <a href="<?= $basePath ?>/login">Sign in</a>
  </p>
</div>
