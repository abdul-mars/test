<?php
use QRoute\Core\View;
$e = [View::class, 'e'];
/** @var string $adminEmail @var string $appUrl @var bool $envWritten */
?>
<div class="card">
  <h2 style="font-size:1.05rem">
    <span class="badge badge-success">Done</span>
    QRoute is installed
  </h2>

  <?php if (!$envWritten): ?>
    <div class="alert alert-warning" role="alert">
      <div>
        <strong>One manual step left.</strong>
        The <code>.env</code> file could not be written, so your settings are
        not saved yet. Create a file called <code>.env</code> in the project
        root (next to <code>README.md</code>, <em>not</em> inside
        <code>public/</code>) and paste in the contents below.
      </div>
    </div>
    <textarea readonly rows="14" class="mono" style="font-size:.78rem"><?= $e($envContents) ?></textarea>
    <p class="hint">Keep this file private. It contains the key that signs every session.</p>
  <?php endif; ?>

  <table class="data" style="margin-block:1rem">
    <tr><td>Sign in as</td><td class="mono"><?= $e($adminEmail) ?></td></tr>
    <tr><td>Site address</td><td class="mono"><?= $e($appUrl) ?></td></tr>
    <tr><td>Database</td><td><?= $e(strtoupper((string) ($config['DB_DRIVER'] ?? 'mysql'))) ?></td></tr>
  </table>

  <a class="btn btn-lg" href="/login">Sign in to QRoute</a>

  <hr style="border:0;border-top:1px solid var(--border);margin:1.5rem 0">

  <h3 style="font-size:.98rem">Before you go live</h3>
  <ul class="small muted" style="margin:0;padding-left:1.1rem">
    <li>
      The installer has locked itself. Delete
      <code>storage/installed.lock</code> only if you genuinely need to run
      setup again — while it is missing, anyone who can reach your site can
      repoint it at their own database.
    </li>
    <li>
      Serve the site from the <code>public/</code> folder only. If Apache is
      pointed at the project root, your <code>.env</code> becomes downloadable.
    </li>
    <li>
      Schedule the maintenance commands:
      <code>php bin/console gc</code> every 10 minutes and
      <code>php bin/console rollup</code> once a day.
    </li>
    <li>
      Payments are not connected yet. Until they are, upgrade paying
      customers from <strong>Admin &rarr; Users</strong>.
    </li>
  </ul>
</div>
