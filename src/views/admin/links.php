<?php
use QRoute\Core\View;
$e = [View::class, 'e'];
/** @var list<array<string,mixed>> $rows */
?>
<div class="wrap" style="padding-block:1.6rem">
  <p class="small"><a href="/admin">&larr; Admin</a></p>
  <div class="row-between" style="margin-bottom:1.1rem">
    <div>
      <h1 style="font-size:1.5rem;margin:0">Codes</h1>
      <p class="muted small" style="margin:0">Busiest first. Disable anything pointing somewhere it should not.</p>
    </div>
    <form method="get" action="/admin/links" class="row" style="gap:.4rem">
      <input type="search" name="q" value="<?= $e($search) ?>" placeholder="Search slug, URL or owner">
      <button class="btn btn-secondary btn-sm" type="submit">Search</button>
    </form>
  </div>

  <div class="card card-flush">
    <div class="table-scroll">
      <table class="data">
        <thead><tr><th>Code</th><th>Destination</th><th>Owner</th><th class="num">Scans</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
          <tr>
            <td class="mono small nowrap">
              /<?= $e($row['slug']) ?>
              <?php if ((int) $row['is_active'] !== 1): ?>
                <span class="badge badge-warning">Off</span>
              <?php endif; ?>
            </td>
            <td class="truncate" style="max-width:280px">
              <span class="small"><?= $e($row['default_url']) ?></span>
            </td>
            <td class="truncate small faint" style="max-width:170px"><?= $e($row['owner_email']) ?></td>
            <td class="num"><?= $e(View::number((int) $row['scan_count'])) ?></td>
            <td>
              <form method="post" action="/admin/links/<?= (int) $row['id'] ?>" style="margin:0"
                    data-confirm="<?= (int) $row['is_active'] === 1 ? 'Disable this code? Anyone scanning it will see a paused message.' : '' ?>">
                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                <input type="hidden" name="action" value="<?= (int) $row['is_active'] === 1 ? 'disable' : 'enable' ?>">
                <button class="btn btn-ghost btn-sm" type="submit">
                  <?= (int) $row['is_active'] === 1 ? 'Disable' : 'Enable' ?>
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
