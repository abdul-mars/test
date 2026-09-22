<?php
use QRoute\Core\View;
use QRoute\Services\Plan;
$e = [View::class, 'e'];
$currentPlan = $currentPlan ?? null;
?>
<section class="section">
  <div class="wrap">
    <div class="center" style="margin-bottom:2.4rem">
      <h1>Simple pricing</h1>
      <p class="muted" style="max-width:52ch;margin-inline:auto">
        Your codes keep working on every plan, including free. Paid plans remove the
        interstitial, raise the limits and unlock the API.
      </p>
    </div>

    <div class="price-grid">
      <?php foreach (Plan::PLANS as $key => $plan): ?>
        <div class="card price-card <?= $key === 'pro' ? 'featured' : '' ?>">
          <?php if ($key === 'pro'): ?><span class="tag">Most popular</span><?php endif; ?>
          <h2 style="font-size:1.12rem;margin-bottom:.15rem"><?= $e($plan['name']) ?></h2>
          <p class="small muted" style="min-height:2.6em"><?= $e($plan['blurb']) ?></p>

          <div class="row" style="align-items:baseline;gap:.35rem">
            <span class="amount">$<?= (int) $plan['price_monthly'] ?></span>
            <span class="per">/month</span>
          </div>
          <?php if ((int) $plan['price_yearly'] > 0): ?>
            <p class="faint" style="margin:.2rem 0 0">
              or $<?= (int) $plan['price_yearly'] ?>/year — two months free
            </p>
          <?php else: ?>
            <p class="faint" style="margin:.2rem 0 0">No card required</p>
          <?php endif; ?>

          <ul>
            <?php foreach ($plan['features'] as $feature): ?>
              <li><?= $e($feature) ?></li>
            <?php endforeach; ?>
          </ul>

          <?php if ($currentPlan === $key): ?>
            <button class="btn btn-secondary btn-block" disabled>Your current plan</button>
          <?php elseif ($key === 'free'): ?>
            <a class="btn btn-secondary btn-block" href="<?= $basePath ?>/register">Start free</a>
          <?php else: ?>
            <a class="btn btn-block <?= $key === 'pro' ? '' : 'btn-secondary' ?>"
               href="<?= $currentPlan === null ? '/register' : '/app/billing?plan=' . $e($key) ?>">
              Choose <?= $e($plan['name']) ?>
            </a>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card" style="margin-top:2.2rem;max-width:720px;margin-inline:auto">
      <h3>What happens if I downgrade or stop paying?</h3>
      <p class="muted small" style="margin:0">
        Your codes keep redirecting. They revert to free-tier behaviour — the
        interstitial returns and analytics stop past the free allowance — but nothing
        you have printed stops working. We think breaking a customer's physical
        property to force an upgrade is a bad way to run a business.
      </p>
    </div>
  </div>
</section>
