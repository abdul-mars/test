<?php
use QRoute\Core\View;
use QRoute\Services\Plan;
$e = [View::class, 'e'];
/** @var \QRoute\Models\User $user */
?>
<div class="wrap wrap-mid" style="padding-block:1.6rem">
  <p class="small"><a href="<?= $basePath ?>/app/settings">&larr; Back to settings</a></p>
  <h1 style="font-size:1.5rem">Billing</h1>

  <div class="card">
    <p>
      You are on the <strong><?= $e(Plan::displayName($user->plan())) ?></strong> plan.
    </p>
    <p class="muted small">
      Payment is not wired up on this deployment yet. To take payments, connect a
      provider (Stripe Checkout is the usual choice), then have its webhook call
      <code>User::setPlan()</code> once a payment succeeds. Everything else —
      quotas, feature gating and the interstitial — already reads from the plan,
      so switching a user's plan is the only thing payment needs to do.
    </p>
    <a class="btn btn-secondary" href="<?= $basePath ?>/pricing">View plans</a>
  </div>
</div>
