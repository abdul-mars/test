<?php
use QRoute\Core\View;
$e = [View::class, 'e'];
$values = $values ?? [];
$guessedUrl = $guessedUrl ?? 'http://localhost';
?>
<form method="post" action="<?= $basePath ?>/install/admin" class="card">
  <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
  <h2 style="font-size:1.05rem">Create your administrator account</h2>
  <p class="muted small">
    This is the account you will sign in with. It can manage every user,
    change plans after someone pays, and suspend abusive codes.
  </p>

  <div class="field">
    <label for="display_name">Your name</label>
    <input type="text" id="display_name" name="display_name" maxlength="80"
           value="<?= $e($values['display_name'] ?? '') ?>" placeholder="Optional">
  </div>

  <div class="field">
    <label for="email">Email</label>
    <input type="email" id="email" name="email" required autofocus
           value="<?= $e($values['email'] ?? '') ?>" autocomplete="username">
  </div>

  <div class="grid grid-2">
    <div class="field">
      <label for="password">Password</label>
      <input type="password" id="password" name="password" minlength="10" required autocomplete="new-password">
      <p class="hint">At least 10 characters.</p>
    </div>
    <div class="field">
      <label for="password_confirm">Confirm password</label>
      <input type="password" id="password_confirm" name="password_confirm" minlength="10" required autocomplete="new-password">
    </div>
  </div>

  <div class="field">
    <label for="app_url">Site address</label>
    <input type="text" id="app_url" name="app_url" required
           value="<?= $e($values['app_url'] ?? $guessedUrl) ?>">
    <p class="hint">
      <strong>This gets baked into every QR code you generate.</strong>
      Change it later and codes already printed will stop working, so set it
      to the address people will really scan.
    </p>
  </div>

  <div class="row">
    <button class="btn" type="submit">Create account and finish</button>
    <a class="btn btn-ghost" href="<?= $basePath ?>/install/database">Back</a>
  </div>
</form>
