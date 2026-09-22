<?php
use QRoute\Core\View;
$e = [View::class, 'e'];
/** @var list<array{name:string,ok:bool,required:bool,detail:string}> $checks @var bool $ready */
?>
<div class="card">
  <h2 style="font-size:1.05rem">Checking your server</h2>
  <p class="muted small">Everything marked required has to pass before setup can continue.</p>

  <table class="data">
    <tbody>
    <?php foreach ($checks as $check): ?>
      <tr>
        <td style="width:1%">
          <?php if ($check['ok']): ?>
            <span class="badge badge-success">OK</span>
          <?php elseif ($check['required']): ?>
            <span class="badge badge-danger">Required</span>
          <?php else: ?>
            <span class="badge badge-warning">Optional</span>
          <?php endif; ?>
        </td>
        <td>
          <strong class="small"><?= $e($check['name']) ?></strong><br>
          <span class="faint"><?= $e($check['detail']) ?></span>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <div class="row" style="margin-top:1.3rem">
    <?php if ($ready): ?>
      <a class="btn" href="<?= $basePath ?>/install/database">Continue</a>
    <?php else: ?>
      <a class="btn btn-secondary" href="<?= $basePath ?>/install">Check again</a>
      <span class="faint">Fix the required items above, then re-check.</span>
    <?php endif; ?>
  </div>
</div>
