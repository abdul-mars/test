<?php
use QRoute\Core\View;
use QRoute\Services\Plan;
$e = [View::class, 'e'];
/** @var list<\QRoute\Models\User> $users */
$pages = (int) ceil($total / $perPage);
?>
<div class="wrap" style="padding-block:1.6rem">
  <p class="small"><a href="/admin">&larr; Admin</a></p>
  <div class="row-between" style="margin-bottom:1.1rem">
    <h1 style="font-size:1.5rem;margin:0">Users <span class="faint" style="font-size:1rem">(<?= (int) $total ?>)</span></h1>
    <form method="get" action="/admin/users" class="row" style="gap:.4rem">
      <input type="search" name="q" value="<?= $e($search) ?>" placeholder="Search email or name">
      <button class="btn btn-secondary btn-sm" type="submit">Search</button>
    </form>
  </div>

  <div class="card card-flush">
    <div class="table-scroll">
      <table class="data">
        <thead>
          <tr><th>Account</th><th>Plan</th><th>Status</th><th>Joined</th><th>Actions</th></tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u): ?>
          <tr>
            <td>
              <strong class="small"><?= $e($u->email()) ?></strong>
              <?php if ($u->isAdmin()): ?><span class="badge badge-accent">Admin</span><?php endif; ?>
              <br><span class="faint"><?= $e($u->displayName()) ?></span>
            </td>
            <td>
              <form method="post" action="/admin/users/<?= (int) $u->id() ?>" class="row" style="gap:.3rem">
                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                <input type="hidden" name="action" value="plan">
                <select name="plan" aria-label="Plan for <?= $e($u->email()) ?>">
                  <?php foreach (Plan::PLANS as $key => $plan): ?>
                    <option value="<?= $e($key) ?>" <?= $u->plan() === $key ? 'selected' : '' ?>><?= $e($plan['name']) ?></option>
                  <?php endforeach; ?>
                </select>
                <input type="number" name="months" value="1" min="0" max="120" style="width:64px"
                       aria-label="Months" title="Months (0 = never expires)">
                <button class="btn btn-ghost btn-sm" type="submit">Set</button>
              </form>
            </td>
            <td>
              <?php if ($u->status() === 'active'): ?>
                <span class="badge badge-success"><span class="dot"></span>Active</span>
              <?php else: ?>
                <span class="badge badge-danger">Suspended</span>
              <?php endif; ?>
            </td>
            <td class="faint small nowrap"><?= $e(gmdate('j M Y', $u->createdAt())) ?></td>
            <td>
              <div class="row" style="gap:.25rem">
                <form method="post" action="/admin/users/<?= (int) $u->id() ?>" style="margin:0">
                  <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                  <input type="hidden" name="action" value="<?= $u->status() === 'active' ? 'suspend' : 'activate' ?>">
                  <button class="btn btn-ghost btn-sm" type="submit">
                    <?= $u->status() === 'active' ? 'Suspend' : 'Restore' ?>
                  </button>
                </form>
                <form method="post" action="/admin/users/<?= (int) $u->id() ?>" style="margin:0">
                  <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                  <input type="hidden" name="action" value="<?= $u->isAdmin() ? 'demote' : 'promote' ?>">
                  <button class="btn btn-ghost btn-sm" type="submit">
                    <?= $u->isAdmin() ? 'Remove admin' : 'Make admin' ?>
                  </button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($pages > 1): ?>
    <div class="row" style="margin-top:1rem">
      <?php for ($p = 1; $p <= min($pages, 12); $p++): ?>
        <a class="btn btn-sm <?= $p === $page ? '' : 'btn-secondary' ?>"
           href="/admin/users?page=<?= $p ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?>"><?= $p ?></a>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</div>
