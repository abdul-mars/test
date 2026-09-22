<?php
use QRoute\Core\View;
use QRoute\Services\Plan;
$e = [View::class, 'e'];
/** @var string $sampleQr */
?>
<section class="hero">
  <div class="wrap">
    <div class="hero-grid">
      <div>
        <span class="badge badge-accent" style="margin-bottom:1rem">
          <span class="dot"></span> The destination changes. The code never does.
        </span>
        <h1>Print the QR code once. Change where it goes forever.</h1>
        <p class="lede">
          A QR code on a menu, a flyer, a label or a business card is set in ink the
          moment it is printed. QRoute puts a code on it that you control: re-point it
          in two clicks, and send each scan somewhere different depending on who is
          scanning it.
        </p>
        <div class="row" style="margin-top:1.6rem">
          <a class="btn btn-lg" href="<?= $basePath ?>/register">Create a free code</a>
          <a class="btn btn-lg btn-secondary" href="<?= $basePath ?>/pricing">See pricing</a>
        </div>
        <p class="faint" style="margin-top:.9rem">
          Free forever for 3 codes. No card needed.
        </p>
      </div>

      <div class="hero-visual">
        <div class="qr-stage">
          <div class="qr-frame"><?= $sampleQr ?></div>
        </div>
        <p class="faint center" style="margin-top:.8rem">
          Scan it — this one is live.
        </p>
      </div>
    </div>
  </div>
</section>

<section class="section section-alt">
  <div class="wrap">
    <h2 class="center">One code. Many destinations.</h2>
    <p class="center muted" style="max-width:56ch;margin:0 auto 2.2rem">
      Every scan is evaluated against your rules, in the order you choose. The first
      rule that matches wins; anything that matches nothing falls through to your
      default. A scan can never dead-end.
    </p>

    <div class="grid grid-3">
      <?php
      $features = [
        ['Send iPhones to the App Store', 'and Android to Google Play, from the same printed code on the same box.', 'M12 2 2 7l10 5 10-5-10-5Z'],
        ['Swap the lunch menu for dinner', 'at 5pm, automatically, in the restaurant\'s own timezone.', 'M12 6v6l4 2'],
        ['Route by country', 'so a customer in Germany lands on your German store, not your US one.', 'M2 12h20M12 2a15 15 0 0 1 0 20'],
        ['Cap a promotion', 'after the first 500 scans, then send everyone to the regular page.', 'M3 12h4l3 8 4-16 3 8h4'],
        ['See what actually happened', 'with scans over time, device, OS, country and which rule fired.', 'M4 20V10m6 10V4m6 16v-7'],
        ['Fix a typo after printing', 'Wrong URL on 5,000 flyers is an afternoon, not a reprint bill.', 'M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z'],
      ];
      foreach ($features as [$h, $p, $path]): ?>
        <div class="card feature">
          <div class="ico">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <path d="<?= $e($path) ?>" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </div>
          <h3><?= $e($h) ?></h3>
          <p><?= $e($p) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section">
  <div class="wrap wrap-mid">
    <h2 class="center">Why a dynamic code is worth paying for</h2>
    <div class="stack" style="--gap:.8rem;margin-top:1.6rem">
      <details class="disclosure" open>
        <summary>What happens to a normal QR code when the link changes?</summary>
        <p class="muted" style="margin:0">
          Nothing good. A static QR code has the destination baked into the pattern
          itself, so changing where it goes means generating a new code and reprinting
          everything it was printed on. QRoute codes contain a short QRoute address;
          the destination lives in your dashboard, where you can change it whenever
          you like.
        </p>
      </details>
      <details class="disclosure">
        <summary>Will my code stop working if I stop paying?</summary>
        <p class="muted" style="margin:0">
          No. If a paid plan lapses, your codes drop to the free tier — they keep
          redirecting, they just show the QRoute interstitial again and stop recording
          new analytics beyond the free allowance. We will not break something you
          have already printed.
        </p>
      </details>
      <details class="disclosure">
        <summary>How fast is a redirect?</summary>
        <p class="muted" style="margin:0">
          A scan is one indexed database lookup plus rule evaluation in memory. There
          is no framework boot and no external call on the path. If analytics or geo
          lookup fail for any reason, the redirect still completes.
        </p>
      </details>
      <details class="disclosure">
        <summary>Can I use my own domain?</summary>
        <p class="muted" style="margin:0">
          Yes — point a CNAME at QRoute and set <code>APP_URL</code>. Codes then encode
          your domain, which both looks better in print and means the codes are yours
          if you ever move.
        </p>
      </details>
    </div>
  </div>
</section>

<section class="section section-alt">
  <div class="wrap center">
    <h2>Put a code on something today</h2>
    <p class="muted" style="max-width:48ch;margin:0 auto 1.5rem">
      Three codes free, forever. Upgrade when the printer gets involved.
    </p>
    <a class="btn btn-lg" href="<?= $basePath ?>/register">Create a free code</a>
  </div>
</section>
