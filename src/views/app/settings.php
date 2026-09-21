<?php
use QRoute\Core\View;
use QRoute\Services\Plan;
$e = [View::class, 'e'];
/** @var \QRoute\Models\User $user @var list<array> $apiKeys */
$errors = $errors ?? [];
$newKey = $newKey ?? null;
$planInfo = Plan::for($user);
?>
<div class="wrap wrap-mid" style="padding-block:1.6rem">
  <h1 style="font-size:1.5rem">Settings</h1>

  <?php if ($errors !== []): ?>
    <div class="alert alert-error" role="alert">
      <div><ul style="margin:0"><?php foreach ($errors as $err): ?><li><?= $e($err) ?></li><?php endforeach; ?></ul></div>
    </div>
  <?php endif; ?>

  <div class="stack" style="--gap:1.2rem">
    <section class="card">
      <h2 style="font-size:1.05rem">Account</h2>
      <form method="post" action="/app/settings/profile">
        <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
        <div class="field">
          <label for="display_name">Display name</label>
          <input type="text" id="display_name" name="display_name" maxlength="80"
                 value="<?= $e($user->displayName()) ?>">
        </div>
        <div class="field">
          <label for="email">Email</label>
          <input type="email" id="email" value="<?= $e($user->email()) ?>" disabled>
          <p class="hint">Contact support to change the address on the account.</p>
        </div>
        <button class="btn btn-secondary" type="submit">Save</button>
      </form>
    </section>

    <section class="card">
      <h2 style="font-size:1.05rem">Change password</h2>
      <form method="post" action="/app/settings/password">
        <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
        <div class="field">
          <label for="current_password">Current password</label>
          <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>
        </div>
        <div class="field">
          <label for="new_password">New password</label>
          <input type="password" id="new_password" name="new_password" autocomplete="new-password" minlength="10" required>
          <p class="hint">Changing this signs you out on every other device.</p>
        </div>
        <button class="btn btn-secondary" type="submit">Change password</button>
      </form>
    </section>

    <section class="card">
      <div class="row-between">
        <h2 style="font-size:1.05rem;margin:0">Plan</h2>
        <span class="badge badge-accent"><?= $e($planInfo['name']) ?></span>
      </div>
      <p class="muted small">
        <?= (int) $planInfo['max_links'] ?> codes &middot;
        <?= $e(View::number((int) $planInfo['scans_per_month'])) ?> scans/month &middot;
        <?= (int) $planInfo['history_days'] ?> days of history
      </p>
      <a class="btn <?= $user->isPaid() ? 'btn-secondary' : '' ?>" href="/pricing">
        <?= $user->isPaid() ? 'Change plan' : 'Upgrade' ?>
      </a>
    </section>

    <section class="card">
      <h2 style="font-size:1.05rem">API keys</h2>
      <?php if (!Plan::can($user, 'api_access')): ?>
        <p class="muted small">The REST API is available on Pro and Team plans.</p>
        <a class="btn btn-secondary btn-sm" href="/pricing">See plans</a>
      <?php else: ?>
        <?php if ($newKey !== null): ?>
          <div class="alert alert-success" role="status">
            <div>
              <strong>Your new key — copy it now.</strong>
              <p class="mono small" style="margin:.4rem 0 0;word-break:break-all"><?= $e($newKey) ?></p>
              <p class="small" style="margin:.4rem 0 0">We store only a hash, so this is the last time it can be shown.</p>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($apiKeys === []): ?>
          <p class="muted small">No keys yet.</p>
        <?php else: ?>
          <div class="table-scroll">
            <table class="data">
              <thead><tr><th>Name</th><th>Prefix</th><th>Last used</th><th></th></tr></thead>
              <tbody>
              <?php foreach ($apiKeys as $key): ?>
                <tr>
                  <td><?= $e($key['name'] !== '' ? $key['name'] : 'Unnamed key') ?></td>
                  <td class="mono small"><?= $e($key['prefix']) ?>&hellip;</td>
                  <td class="small muted"><?= $e(View::ago($key['last_used_at'] === null ? null : (int) $key['last_used_at'])) ?></td>
                  <td class="num">
                    <form method="post" action="/app/settings/keys/<?= (int) $key['id'] ?>/revoke" style="margin:0"
                          data-confirm="Revoke this key? Anything using it stops working immediately.">
                      <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                      <button class="btn btn-ghost btn-sm" type="submit">Revoke</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>

        <form method="post" action="/app/settings/keys" class="row" style="margin-top:1rem">
          <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
          <input type="text" name="name" placeholder="Key name, e.g. Zapier" maxlength="80" class="grow">
          <button class="btn btn-secondary" type="submit">Create key</button>
        </form>
        <p class="hint">See the <a href="/docs">API documentation</a> for what you can do with it.</p>
      <?php endif; ?>
    </section>
  </div>
</div>
