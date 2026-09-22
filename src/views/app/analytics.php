<?php
use QRoute\Core\View;
$e = [View::class, 'e'];
/** @var array $series @var array $breakdowns @var ?\QRoute\Models\Link $link */
$max = 1;
foreach ($series as $row) { $max = max($max, (int) $row['total']); }
$count = max(1, count($series));
$barW  = 100 / $count;
$totalScans = array_sum(array_column($series, 'total'));
$totalUnique = array_sum(array_column($series, 'uniques'));
$days = $days ?? 30;
$labels = array_keys($series);
?>
<div class="wrap" style="padding-block:1.6rem">
  <?php if ($link !== null): ?>
    <p class="small"><a href="<?= $basePath ?>/app/links/<?= (int) $link->id() ?>">&larr; Back to <?= $e($link->title()) ?></a></p>
    <h1 style="font-size:1.5rem"><?= $e($link->title()) ?> &mdash; analytics</h1>
  <?php else: ?>
    <h1 style="font-size:1.5rem">Analytics</h1>
  <?php endif; ?>

  <div class="row" style="margin-bottom:1.2rem">
    <?php
    $base = $link !== null ? '/app/links/' . $link->id() . '/analytics' : '/app/analytics';
    foreach ([7 => '7 days', 30 => '30 days', 90 => '90 days', 365 => '1 year'] as $d => $label):
      if ($d > $maxDays) { continue; }
    ?>
      <a class="btn btn-sm <?= $days === $d ? '' : 'btn-secondary' ?>"
         href="<?= $e($base) ?>?days=<?= (int) $d ?>"><?= $e($label) ?></a>
    <?php endforeach; ?>
    <?php if ($canExport && $link !== null): ?>
      <a class="btn btn-ghost btn-sm" href="<?= $basePath ?>/app/links/<?= (int) $link->id() ?>/export.csv?days=<?= (int) $days ?>">
        Export CSV
      </a>
    <?php endif; ?>
  </div>

  <div class="grid grid-3" style="margin-bottom:1.4rem">
    <div class="stat">
      <div class="stat-label">Scans</div>
      <div class="stat-value"><?= $e(View::number((int) $totalScans)) ?></div>
      <div class="stat-sub">last <?= (int) $days ?> days</div>
    </div>
    <div class="stat">
      <div class="stat-label">Unique visitors</div>
      <div class="stat-value"><?= $e(View::number((int) $totalUnique)) ?></div>
      <div class="stat-sub">
        <?= $totalScans > 0 ? (int) round($totalUnique / $totalScans * 100) : 0 ?>% of scans
      </div>
    </div>
    <div class="stat">
      <div class="stat-label">Busiest day</div>
      <?php
      $busiest = ['day' => '—', 'total' => 0];
      foreach ($series as $day => $row) {
        if ((int) $row['total'] > $busiest['total']) { $busiest = ['day' => $day, 'total' => (int) $row['total']]; }
      }
      ?>
      <div class="stat-value" style="font-size:1.4rem">
        <?= $busiest['total'] > 0 ? $e(gmdate('j M', (int) strtotime($busiest['day']))) : '—' ?>
      </div>
      <div class="stat-sub"><?= $e(View::number($busiest['total'])) ?> scans</div>
    </div>
  </div>

  <section class="card" style="margin-bottom:1.4rem">
    <h2 style="font-size:1.02rem">Scans over time</h2>
    <?php if ($totalScans === 0): ?>
      <p class="muted small" style="margin:0">No scans recorded in this period yet.</p>
    <?php else: ?>
      <svg class="chart" viewBox="0 0 100 40" preserveAspectRatio="none" role="img"
           aria-label="Daily scans for the last <?= (int) $days ?> days">
        <?php $i = 0; foreach ($series as $day => $row):
          $h = $max > 0 ? ((int) $row['total'] / $max) * 36 : 0;
          $x = $i * $barW;
          $i++;
          if ((int) $row['total'] === 0) { continue; }
        ?>
          <rect class="bar" x="<?= round($x + $barW * .12, 3) ?>" y="<?= round(38 - $h, 3) ?>"
                width="<?= round($barW * .76, 3) ?>" height="<?= round(max($h, .4), 3) ?>" rx="0.4">
            <title><?= $e($day) ?>: <?= (int) $row['total'] ?> scans</title>
          </rect>
        <?php endforeach; ?>
        <line class="axis" x1="0" y1="38.2" x2="100" y2="38.2"/>
      </svg>
      <div class="chart-labels">
        <span><?= $e(gmdate('j M', (int) strtotime((string) $labels[0]))) ?></span>
        <span><?= $e(gmdate('j M', (int) strtotime((string) $labels[count($labels) - 1]))) ?></span>
      </div>
    <?php endif; ?>
  </section>

  <div class="grid grid-2">
    <?php foreach ($breakdowns as $title => $rows): ?>
      <section class="card">
        <h3 style="font-size:.98rem"><?= $e($title) ?></h3>
        <?php if ($rows === []): ?>
          <p class="muted small" style="margin:0">No data yet.</p>
        <?php else: ?>
          <?php $topVal = max(array_column($rows, 'total')); ?>
          <ul class="breakdown">
            <?php foreach ($rows as $row): ?>
              <li>
                <span class="truncate"><?= $e($row['value']) ?></span>
                <span class="count"><?= $e(View::number((int) $row['total'])) ?></span>
                <span class="bar-track">
                  <span class="bar-fill" style="width:<?= (int) round((int) $row['total'] / max(1, $topVal) * 100) ?>%"></span>
                </span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>
    <?php endforeach; ?>
  </div>

  <?php if (!$canExport): ?>
    <div class="card" style="margin-top:1.4rem">
      <p class="small muted" style="margin:0">
        Longer history and CSV export are available on Pro. <a href="<?= $basePath ?>/pricing">See plans</a>.
      </p>
    </div>
  <?php endif; ?>
</div>
