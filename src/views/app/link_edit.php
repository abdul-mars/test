<?php
use QRoute\Core\View;
use QRoute\Services\{Plan, RuleEngine, GeoResolver, DeviceDetector};
$e = [View::class, 'e'];
/** @var \QRoute\Models\Link $link @var list<\QRoute\Models\Rule> $rules */
$short = $link->shortUrl($baseUrl);
$style = $link->renderStyle();
$errors = $errors ?? [];
$plan = $plan ?? [];
?>
<div class="wrap" style="padding-block:1.6rem">
  <p class="small"><a href="/app">&larr; Back to your codes</a></p>

  <div class="row-between" style="margin-bottom:1.3rem">
    <div class="grow">
      <h1 style="font-size:1.5rem;margin-bottom:.15rem"><?= $e($link->title()) ?></h1>
      <div class="row">
        <code class="mono small"><?= $e(preg_replace('#^https?://#', '', $short)) ?></code>
        <button class="btn btn-ghost btn-sm" type="button" data-copy="<?= $e($short) ?>">Copy</button>
        <?php if (!$link->isActive()): ?><span class="badge badge-warning">Paused</span><?php endif; ?>
      </div>
    </div>
    <div class="row">
      <a class="btn btn-secondary btn-sm" href="/app/links/<?= (int) $link->id() ?>/analytics">Analytics</a>
    </div>
  </div>

  <?php if ($errors !== []): ?>
    <div class="alert alert-error" role="alert">
      <div><ul style="margin:0"><?php foreach ($errors as $err): ?><li><?= $e($err) ?></li><?php endforeach; ?></ul></div>
    </div>
  <?php endif; ?>

  <div class="split">
    <div class="stack" style="--gap:1.2rem">

      <!-- ------------------------------------------------ destination -->
      <section class="card">
        <h2 style="font-size:1.05rem">Default destination</h2>
        <p class="muted small">Used when no rule below matches. This is the one that
           must always be right — it is the fallback for every scan.</p>
        <form method="post" action="/app/links/<?= (int) $link->id() ?>/destination">
          <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
          <div class="field">
            <label class="visually-hidden" for="default_url">Destination URL</label>
            <input type="text" id="default_url" name="default_url" inputmode="url" required
                   value="<?= $e($link->defaultUrl()) ?>">
          </div>
          <button class="btn" type="submit">Update destination</button>
        </form>
      </section>

      <!-- ------------------------------------------------------ rules -->
      <section class="card">
        <div class="row-between" style="margin-bottom:.4rem">
          <h2 style="font-size:1.05rem;margin:0">Routing rules</h2>
          <span class="faint"><?= count($rules) ?> / <?= (int) ($plan['max_rules'] ?? 0) ?></span>
        </div>
        <p class="muted small">
          Checked top to bottom. The first rule that matches a scan wins.
        </p>

        <?php if ($rules === []): ?>
          <div class="empty" style="padding:1.6rem">
            <p style="margin:0">No rules yet — every scan goes to the default destination.</p>
          </div>
        <?php else: ?>
          <?php foreach ($rules as $i => $rule): ?>
            <div class="rule <?= $rule->isActive() ? '' : 'inactive' ?>">
              <div class="rule-head">
                <div class="grow">
                  <span class="order">#<?= $i + 1 ?></span>
                  <strong class="small"><?= $e($rule->describe()) ?></strong>
                </div>
                <div class="row">
                  <span class="badge"><?= $e(View::number($rule->hits())) ?> <?= $rule->hits() === 1 ? 'hit' : 'hits' ?></span>
                  <form method="post" action="/app/rules/<?= (int) $rule->id() ?>/toggle" style="margin:0">
                    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                    <button class="btn btn-ghost btn-sm" type="submit">
                      <?= $rule->isActive() ? 'Pause' : 'Resume' ?>
                    </button>
                  </form>
                  <form method="post" action="/app/rules/<?= (int) $rule->id() ?>/delete" style="margin:0"
                        data-confirm="Delete this rule? Scans it was catching will fall through to the next match.">
                    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                    <button class="btn btn-ghost btn-sm" type="submit" aria-label="Delete rule">&times;</button>
                  </form>
                </div>
              </div>
              <div class="target">&rarr; <?= $e($rule->targetUrl()) ?></div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>

        <?php if (count($rules) < (int) ($plan['max_rules'] ?? 0)): ?>
          <details class="disclosure" style="margin-top:1rem">
            <summary>Add a rule</summary>
            <form method="post" action="/app/links/<?= (int) $link->id() ?>/rules" id="rule-conditions">
              <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">

              <div class="field">
                <label for="target_url">Send matching scans to</label>
                <input type="text" id="target_url" name="target_url" inputmode="url" required
                       placeholder="apps.apple.com/app/your-app">
              </div>

              <fieldset data-condition>
                <legend>Device <span class="faint" data-condition-summary>Any</span></legend>
                <div class="chips">
                  <?php foreach (['mobile' => 'Phone', 'tablet' => 'Tablet', 'desktop' => 'Desktop'] as $v => $label): ?>
                    <label class="chip"><input type="checkbox" name="device[]" value="<?= $e($v) ?>"><?= $e($label) ?></label>
                  <?php endforeach; ?>
                </div>
              </fieldset>

              <fieldset data-condition>
                <legend>Operating system <span class="faint" data-condition-summary>Any</span></legend>
                <div class="chips">
                  <?php foreach (['ios' => 'iOS', 'android' => 'Android', 'windows' => 'Windows', 'macos' => 'macOS', 'linux' => 'Linux'] as $v => $label): ?>
                    <label class="chip"><input type="checkbox" name="os[]" value="<?= $e($v) ?>"><?= $e($label) ?></label>
                  <?php endforeach; ?>
                </div>
              </fieldset>

              <div class="field">
                <label for="country">Countries <span class="muted" style="font-weight:400">(optional)</span></label>
                <select id="country" name="country[]" multiple size="5">
                  <?php foreach (GeoResolver::countryList() as $code => $name): ?>
                    <option value="<?= $e($code) ?>"><?= $e($name) ?></option>
                  <?php endforeach; ?>
                </select>
                <p class="hint">Hold Ctrl (or Cmd) to pick several. Needs a CDN that supplies a country header.</p>
              </div>

              <div class="grid grid-2">
                <div class="field">
                  <label for="sched_from">Active from (time)</label>
                  <input type="time" id="sched_from" name="schedule[from]">
                </div>
                <div class="field">
                  <label for="sched_to">until</label>
                  <input type="time" id="sched_to" name="schedule[to]">
                </div>
              </div>

              <div class="field">
                <label for="sched_tz">Timezone for that schedule</label>
                <select id="sched_tz" name="schedule[tz]">
                  <?php foreach (\DateTimeZone::listIdentifiers() as $tz): ?>
                    <option value="<?= $e($tz) ?>" <?= $tz === 'UTC' ? 'selected' : '' ?>><?= $e($tz) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <fieldset data-condition>
                <legend>Days of the week <span class="faint" data-condition-summary>Any</span></legend>
                <div class="chips">
                  <?php foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'] as $n => $label): ?>
                    <label class="chip"><input type="checkbox" name="schedule[days][]" value="<?= (int) $n ?>"><?= $e($label) ?></label>
                  <?php endforeach; ?>
                </div>
              </fieldset>

              <div class="grid grid-2">
                <div class="field">
                  <label for="scans_max">Only for the first N scans</label>
                  <input type="number" id="scans_max" name="scans[max]" min="1" placeholder="e.g. 500">
                </div>
                <div class="field">
                  <label for="label">Rule name (optional)</label>
                  <input type="text" id="label" name="label" maxlength="120" placeholder="iOS → App Store">
                </div>
              </div>

              <button class="btn" type="submit">Add rule</button>
            </form>
          </details>
        <?php else: ?>
          <p class="small muted" style="margin-top:1rem">
            You have used all <?= (int) ($plan['max_rules'] ?? 0) ?> rules on this plan.
            <a href="/pricing">Upgrade for more</a>.
          </p>
        <?php endif; ?>
      </section>

      <!-- --------------------------------------------------- settings -->
      <section class="card">
        <h2 style="font-size:1.05rem">Code settings</h2>
        <form method="post" action="/app/links/<?= (int) $link->id() ?>/settings">
          <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
          <div class="field">
            <label for="ltitle">Name</label>
            <input type="text" id="ltitle" name="title" maxlength="120" value="<?= $e($link->title()) ?>">
          </div>
          <div class="field">
            <label class="checkbox">
              <input type="checkbox" name="is_active" value="1" <?= $link->isActive() ? 'checked' : '' ?>>
              <span>Active — uncheck to temporarily stop this code from redirecting</span>
            </label>
          </div>
          <button class="btn btn-secondary" type="submit">Save settings</button>
        </form>

        <hr style="border:0;border-top:1px solid var(--border);margin:1.3rem 0">
        <form method="post" action="/app/links/<?= (int) $link->id() ?>/delete"
              data-confirm="Delete this code permanently? Anything already printed with it will stop working. This cannot be undone.">
          <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
          <button class="btn btn-danger btn-sm" type="submit">Delete this code</button>
          <p class="hint">Printed copies of this code will stop working immediately.</p>
        </form>
      </section>
    </div>

    <!-- ------------------------------------------------------ QR panel -->
    <aside class="stack" style="--gap:1rem" id="qr-studio">
      <div class="card">
        <h2 style="font-size:1.05rem">Your code</h2>
        <div class="qr-stage">
          <div class="qr-frame" data-qr-stage data-qr-src="/qr/<?= $e($link->slug()) ?>.svg">
            <?= $qrSvg ?>
          </div>
        </div>

        <div class="row" style="margin-top:.9rem">
          <a class="btn btn-sm" href="/qr/<?= $e($link->slug()) ?>.svg?download=1">SVG</a>
          <a class="btn btn-secondary btn-sm" href="/qr/<?= $e($link->slug()) ?>.png?download=1&amp;scale=20">PNG</a>
        </div>
        <p class="hint">Use SVG for anything going to print — it stays sharp at any size.</p>
      </div>

      <div class="card">
        <h3 style="font-size:.95rem">Appearance</h3>
        <?php if (($plan['custom_colors'] ?? false)): ?>
          <form method="post" action="/app/links/<?= (int) $link->id() ?>/style">
            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
            <div class="row" style="margin-bottom:.8rem">
              <label class="small" for="dark">Code</label>
              <input type="color" id="dark" name="dark" value="<?= $e($style['dark']) ?>" data-qr-param="dark">
              <label class="small" for="light">Background</label>
              <input type="color" id="light" name="light" value="<?= $e($style['light']) ?>" data-qr-param="light">
            </div>
            <div class="field">
              <label for="ecc">Error correction</label>
              <select id="ecc" name="ecc" data-qr-param="ecc">
                <?php foreach (['L' => 'Low (7%) — smallest code', 'M' => 'Medium (15%)', 'Q' => 'Quartile (25%) — recommended for print', 'H' => 'High (30%) — survives damage'] as $v => $label): ?>
                  <option value="<?= $e($v) ?>" <?= $style['ecc'] === $v ? 'selected' : '' ?>><?= $e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <button class="btn btn-secondary btn-sm" type="submit">Save appearance</button>
            <p class="hint">Keep strong contrast between the two colours or scanners will struggle.</p>
          </form>
        <?php else: ?>
          <p class="small muted">Brand colours and shapes are a Pro feature.</p>
          <a class="btn btn-secondary btn-sm" href="/pricing">See Pro</a>
        <?php endif; ?>
      </div>

      <div class="card">
        <h3 style="font-size:.95rem">At a glance</h3>
        <table class="data" style="font-size:.85rem">
          <tr><td>Total scans</td><td class="num"><?= $e(View::number($link->scanCount())) ?></td></tr>
          <tr><td>Last scan</td><td class="num"><?= $e(View::ago($link->lastScanAt())) ?></td></tr>
          <tr><td>Created</td><td class="num"><?= $e(gmdate('j M Y', $link->createdAt())) ?></td></tr>
          <tr><td>Active rules</td><td class="num"><?= count(array_filter($rules, static fn($r) => $r->isActive())) ?></td></tr>
        </table>
      </div>
    </aside>
  </div>
</div>
