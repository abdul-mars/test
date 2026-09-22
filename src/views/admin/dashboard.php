<?php
use QRoute\Core\View;
use QRoute\Services\Plan;
$e = [View::class, 'e'];
/** @var array $stats @var array $planCounts @var list<\QRoute\Models\User> $recent */
?>
<div class="wrap" style="padding-block:1.6rem">
  <div class="row-between" style="margin-bottom:1.3rem">
    <div>
      <h1 style="font-size:1.5rem;margin-bottom:.1rem">Admin</h1>
      <p class="muted small" style="margin:0">System-wide view. Only administrators can see this.</p>
    </div>
    <div class="row">
      <a class="btn btn-secondary btn-sm" href="<?= $basePath ?>/admin/users">Users</a>
      <a class="btn btn-secondary btn-sm" href="<?= $basePath ?>/admin/links">Codes</a>
    </div>
  </div>

  <div class="grid grid-4" style="margin-bottom:1.5rem">
    <div class="stat">
      <div class="stat-label">Users</div>
      <div class="stat-value"><?= $e(View::number((int) $stats['users'])) ?></div>
      <div class="stat-sub"><?= (int) $stats['new_users_30d'] ?> new in 30 days</div>
    </div>
    <div class="stat">
      <div class="stat-label">Active codes</div>
      <div class="stat-value"><?= $e(View::number((int) $stats['links'])) ?></div>
      <div class="stat-sub"><?= $e(View::number((int) $stats['rules'])) ?> routing rules</div>
    </div>
    <div class="stat">
      <div class="stat-label">Scans this month</div>
      <div class="stat-value"><?= $e(View::number((int) $stats['scans_month'])) ?></div>
      <div class="stat-sub"><?= $e(View::number((int) $stats['scans_total'])) ?> all time</div>
    </div>
    <div class="stat">
      <div class="stat-label">Administrators</div>
      <div class="stat-value"><?= (int) $stats['admins'] ?></div>
      <div class="stat-sub">of <?= (int) $stats['users'] ?> accounts</div>
    </div>
  </div>

  <div class="grid grid-2">
    <section class="card">
      <h2 style="font-size:1.02rem">Accounts by plan</h2>
      <?php $totalUsers = max(1, (int) $stats['users']); ?>
      <ul class="breakdown">
        <?php foreach (Plan::PLANS as $key => $plan): $n = (int) ($planCounts[$key] ?? 0); ?>
          <li>
            <span><?= $e($plan['name']) ?>
              <?php if ((int) $plan['price_monthly'] > 0): ?>
                <span class="faint">$<?= (int) $plan['price_monthly'] ?>/mo</span>
              <?php endif; ?>
            </span>
            <span class="count"><?= $n ?></span>
            <span class="bar-track"><span class="bar-fill" style="width:<?= (int) round($n / $totalUsers * 100) ?>%"></span></span>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php
      $mrr = 0;
      foreach (Plan::PLANS as $key => $plan) {
          $mrr += (int) ($planCounts[$key] ?? 0) * (int) $plan['price_monthly'];
      }
      ?>
      <p class="small muted" style="margin:.8rem 0 0">
        Gross monthly value of current plans: <strong>$<?= number_format($mrr) ?></strong>.
        This counts assigned plans, not payments received — connect a payment
        provider before treating it as revenue.
      </p>
    </section>

    <section class="card">
      <h2 style="font-size:1.02rem">Newest accounts</h2>
      <?php if ($recent === []): ?>
        <p class="muted small" style="margin:0">No accounts yet.</p>
      <?php else: ?>
        <div class="table-scroll">
          <table class="data">
            <tbody>
            <?php foreach ($recent as $u): ?>
              <tr>
                <td class="truncate" style="max-width:190px"><?= $e($u->email()) ?></td>
                <td><span class="badge"><?= $e(Plan::displayName($u->plan())) ?></span></td>
                <td class="num faint"><?= $e(View::ago($u->createdAt())) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p style="margin:.8rem 0 0"><a class="small" href="<?= $basePath ?>/admin/users">See all users &rarr;</a></p>
      <?php endif; ?>
    </section>
  </div>
</div>
