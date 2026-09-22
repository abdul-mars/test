<?php
use QRoute\Core\View;
$e = [View::class, 'e'];
$config = $config ?? [];
$driver = $config['DB_DRIVER'] ?? 'mysql';
?>
<form method="post" action="<?= $basePath ?>/install/database" class="card">
  <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
  <h2 style="font-size:1.05rem">Connect your database</h2>
  <p class="muted small">
    These defaults match a stock XAMPP install. If MySQL is not running, start
    it in the XAMPP Control Panel first.
  </p>

  <div class="field">
    <label for="db_driver">Database type</label>
    <select id="db_driver" name="db_driver">
      <option value="mysql" <?= $driver === 'mysql' ? 'selected' : '' ?>>MySQL / MariaDB (recommended)</option>
      <option value="sqlite" <?= $driver === 'sqlite' ? 'selected' : '' ?>>SQLite (no server needed)</option>
    </select>
  </div>

  <div class="grid grid-2">
    <div class="field">
      <label for="db_host">Host</label>
      <input type="text" id="db_host" name="db_host" value="<?= $e($config['DB_HOST'] ?? '127.0.0.1') ?>">
    </div>
    <div class="field">
      <label for="db_port">Port</label>
      <input type="text" id="db_port" name="db_port" inputmode="numeric" value="<?= $e($config['DB_PORT'] ?? '3306') ?>">
    </div>
  </div>

  <div class="field">
    <label for="db_name">Database name</label>
    <input type="text" id="db_name" name="db_name" value="<?= $e($config['DB_NAME'] ?? 'qroute') ?>" required>
  </div>

  <div class="grid grid-2">
    <div class="field">
      <label for="db_user">Username</label>
      <input type="text" id="db_user" name="db_user" value="<?= $e($config['DB_USER'] ?? 'root') ?>">
    </div>
    <div class="field">
      <label for="db_pass">Password</label>
      <input type="password" id="db_pass" name="db_pass" autocomplete="off" value="">
      <p class="hint">Blank on a stock XAMPP install.</p>
    </div>
  </div>

  <div class="field">
    <label class="checkbox">
      <input type="checkbox" name="create_db" value="1" checked>
      <span>Create the database if it does not exist yet</span>
    </label>
  </div>

  <details class="disclosure" style="margin-bottom:1rem">
    <summary>Using SQLite instead</summary>
    <div class="field" style="margin-bottom:0">
      <label for="db_path">Database file</label>
      <input type="text" id="db_path" name="db_path" value="<?= $e($config['DB_PATH'] ?? 'storage/qroute.sqlite') ?>">
      <p class="hint">Relative paths are stored outside the web root automatically.</p>
    </div>
  </details>

  <div class="row">
    <button class="btn" type="submit">Test connection and create tables</button>
    <a class="btn btn-ghost" href="<?= $basePath ?>/install">Back</a>
  </div>
</form>
