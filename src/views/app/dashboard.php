<?php
use QRoute\Core\View;
use QRoute\Services\Plan;
$e = [View::class, 'e'];
/** @var list<\QRoute\Models\Link> $links @var array $summary @var array $usage */
?>
<div class="wrap" style="padding-block:1.6rem">

  <div class="row-between" style="margin-bottom:1.4rem">
    <div>
      <h1 style="font-size:1.55rem;margin-bottom:.1rem">Your codes</h1>
      <p class="muted small" style="margin:0">
        <?= $e(View::number($summary['scans'])) ?> scans in the last 30 days
        &middot; <?= $e(View::number($summary['uniques'])) ?> unique
      </p>
    </div>
    <?php if ($usage['links_used'] < $usage['links_max']): ?>
      <a class="btn" href="<?= $basePath ?>/app/links/new">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        </svg>
        New code
      </a>
    <?php else: ?>
      <a class="btn btn-secondary" href="<?= $basePath ?>/pricing">Upgrade for more codes</a>
    <?php endif; ?>
  </div>

  <div class="grid grid-4" style="margin-bottom:1.6rem">
    <div class="stat">
      <div class="stat-label">Codes</div>
      <div class="stat-value"><?= (int) $usage['links_used'] ?><span class="muted" style="font-size:1rem">/<?= (int) $usage['links_max'] ?></span></div>
      <div class="meter <?= $usage['links_used'] >= $usage['links_max'] ? 'over' : '' ?>">
        <span style="width:<?= (int) min(100, $usage['links_used'] / max(1, $usage['links_max']) * 100) ?>%"></span>
      </div>
    </div>
    <div class="stat">
      <div class="stat-label">Scans this month</div>
      <div class="stat-value"><?= $e(View::number($usage['scans_used'])) ?></div>
      <div class="meter <?= $usage['scans_used'] >= $usage['scans_max'] ? 'over' : ($usage['scans_used'] > $usage['scans_max'] * .8 ? 'warn' : '') ?>">
        <span style="width:<?= (int) min(100, $usage['scans_used'] / max(1, $usage['scans_max']) * 100) ?>%"></span>
      </div>
      <div class="stat-sub">of <?= $e(View::number($usage['scans_max'])) ?></div>
    </div>
    <div class="stat">
      <div class="stat-label">Scans (30d)</div>
      <div class="stat-value"><?= $e(View::number($summary['scans'])) ?></div>
      <div class="stat-sub"><?= $e(View::number($summary['uniques'])) ?> unique visitors</div>
    </div>
    <div class="stat">
      <div class="stat-label">Plan</div>
      <div class="stat-value" style="font-size:1.4rem"><?= $e(Plan::displayName($usage['plan'])) ?></div>
      <div class="stat-sub">
        <?php if ($usage['plan'] === 'free'): ?>
          <a href="<?= $basePath ?>/pricing">Remove the interstitial &rarr;</a>
        <?php else: ?>
          <a href="<?= $basePath ?>/app/billing">Manage billing</a>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if ($links === []): ?>
    <div class="empty">
      <h3>No codes yet</h3>
      <p>Create your first dynamic QR code. You can change where it points at any time,
         even after it is printed.</p>
      <a class="btn" href="<?= $basePath ?>/app/links/new">Create your first code</a>
    </div>
  <?php else: ?>
    <div class="stack" style="--gap:.75rem">
      <?php foreach ($links as $link): ?>
        <?php $short = $link->shortUrl($baseUrl); ?>
        <article class="link-card">
          <a class="qr-thumb" href="<?= $basePath ?>/app/links/<?= (int) $link->id() ?>" aria-label="Open <?= $e($link->title()) ?>">
            <img src="<?= $basePath ?>/qr/<?= $e($link->slug()) ?>.svg?s=thumb" alt="" loading="lazy" width="76" height="76">
          </a>

          <div class="grow">
            <h3 class="truncate">
              <a href="<?= $basePath ?>/app/links/<?= (int) $link->id() ?>" style="color:inherit"><?= $e($link->title()) ?></a>
              <?php if (!$link->isActive()): ?>
                <span class="badge badge-warning">Paused</span>
              <?php elseif ($link->hasExpired()): ?>
                <span class="badge badge-danger">Expired</span>
              <?php endif; ?>
            </h3>
            <div class="short truncate"><?= $e(preg_replace('#^https?://#', '', $short)) ?></div>
            <div class="dest truncate">&rarr; <?= $e($link->defaultUrl()) ?></div>
            <div class="faint" style="margin-top:.25rem">
              <?= $e(View::number($link->scanCount())) ?> scans
              &middot; last <?= $e(View::ago($link->lastScanAt())) ?>
            </div>
          </div>

          <div class="actions">
            <button class="btn btn-ghost btn-sm" type="button" data-copy="<?= $e($short) ?>" title="Copy short link">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <rect x="9" y="9" width="12" height="12" rx="2" stroke="currentColor" stroke-width="2"/>
                <path d="M5 15V5a2 2 0 0 1 2-2h10" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
              </svg>
              <span class="visually-hidden">Copy link</span>
            </button>
            <a class="btn btn-secondary btn-sm" href="<?= $basePath ?>/app/links/<?= (int) $link->id() ?>">Edit</a>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
