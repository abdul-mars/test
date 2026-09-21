<?php
use QRoute\Core\View;
$e = [View::class, 'e'];
?>
<div class="wrap wrap-mid" style="padding-block:2rem">
  <h1>REST API</h1>
  <p class="muted">
    Everything the dashboard does, the API does. Available on Pro and Team plans.
  </p>

  <div class="card" style="margin-bottom:1.3rem">
    <h2 style="font-size:1.05rem">Authentication</h2>
    <p class="small muted">Send your key as a bearer token. Create one in Settings.</p>
    <pre class="mono small" style="overflow-x:auto;background:var(--bg-subtle);padding:.8rem;border-radius:8px"><code>curl <?= $e($baseUrl) ?>/api/v1/links \
  -H "Authorization: Bearer qr_live_..."</code></pre>
  </div>

  <div class="card" style="margin-bottom:1.3rem">
    <h2 style="font-size:1.05rem">Endpoints</h2>
    <div class="table-scroll">
      <table class="data">
        <thead><tr><th>Method</th><th>Path</th><th>Does</th></tr></thead>
        <tbody>
          <?php foreach ([
            ['GET',  '/api/v1/links',            'List your codes'],
            ['POST', '/api/v1/links',            'Create a code'],
            ['GET',  '/api/v1/links/{id}',       'Fetch one code with its rules'],
            ['POST', '/api/v1/links/{id}',       'Update title, destination or active state'],
            ['POST', '/api/v1/links/{id}/delete','Delete a code permanently'],
            ['POST', '/api/v1/links/{id}/rules', 'Add a routing rule'],
            ['POST', '/api/v1/rules/{id}/delete','Delete a rule'],
            ['GET',  '/api/v1/links/{id}/stats', 'Daily scans and breakdowns'],
            ['GET',  '/api/v1/me',               'Your account, plan and usage'],
          ] as [$m, $p, $d]): ?>
            <tr>
              <td><span class="badge <?= $m === 'GET' ? 'badge-success' : 'badge-accent' ?>"><?= $e($m) ?></span></td>
              <td class="mono small"><?= $e($p) ?></td>
              <td class="small muted"><?= $e($d) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card" style="margin-bottom:1.3rem">
    <h2 style="font-size:1.05rem">Re-point a code</h2>
    <p class="small muted">The one call that makes the whole product worth it.</p>
    <pre class="mono small" style="overflow-x:auto;background:var(--bg-subtle);padding:.8rem;border-radius:8px"><code>curl -X POST <?= $e($baseUrl) ?>/api/v1/links/42 \
  -H "Authorization: Bearer qr_live_..." \
  -H "Content-Type: application/json" \
  -d '{"default_url": "https://example.com/new-menu"}'</code></pre>
  </div>

  <div class="card">
    <h2 style="font-size:1.05rem">Add a routing rule</h2>
    <pre class="mono small" style="overflow-x:auto;background:var(--bg-subtle);padding:.8rem;border-radius:8px"><code>curl -X POST <?= $e($baseUrl) ?>/api/v1/links/42/rules \
  -H "Authorization: Bearer qr_live_..." \
  -H "Content-Type: application/json" \
  -d '{
    "label": "iOS to the App Store",
    "target_url": "https://apps.apple.com/app/id123456",
    "conditions": { "os": ["ios"] }
  }'</code></pre>
    <p class="small muted" style="margin-top:.8rem">
      Conditions accept <code>device</code>, <code>os</code>, <code>browser</code>,
      <code>country</code>, <code>lang</code>, <code>referer</code>,
      <code>schedule</code>, <code>window</code> and <code>scans</code>. Values
      within one condition are OR'd; conditions are AND'd together.
    </p>
  </div>

  <div class="card" style="margin-top:1.3rem">
    <h2 style="font-size:1.05rem">Rate limits</h2>
    <p class="small muted" style="margin:0">
      600 requests per minute per key. Responses carry
      <code>X-RateLimit-Remaining</code>, and a 429 carries <code>Retry-After</code>.
    </p>
  </div>
</div>
