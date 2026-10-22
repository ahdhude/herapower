<?php
/** @var array $table @var string $param @var string $date @var string $month @var string $path @var array $can */
use App\Core\Auth;

$isOp = Auth::hasRole('operator');
$q = $param === 'month' ? 'month=' . $month : 'date=' . $date;
$cols = $table['cols'];
?>
<div class="hh-reportbar">
  <form method="get" action="<?= e(url($path)) ?>" class="no-print">
    <a class="hh-icon-btn" href="<?= e(url('/reports')) ?>" aria-label="All reports"><i class="bi bi-arrow-left"></i></a>
    <?php if ($param === 'month'): ?>
      <input class="form-control" type="month" name="month" value="<?= e($month) ?>" max="<?= e(substr($maxDate, 0, 7)) ?>" onchange="this.form.submit()" aria-label="Month" style="width:auto">
    <?php elseif (!$isOp): ?>
      <input class="form-control" type="date" name="date" value="<?= e($date) ?>" max="<?= e($maxDate) ?>" onchange="this.form.submit()" aria-label="Date" style="width:auto">
    <?php endif; ?>
  </form>
  <div class="hh-exports no-print">
    <?php foreach ([['csv', 'filetype-csv', 'CSV'], ['xlsx', 'file-earmark-excel', 'Excel'], ['pdf', 'file-earmark-pdf', 'PDF']] as [$f, $ic, $lab]): ?>
      <?php if ($can[$f]): ?>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(url($path) . '?' . $q . '&format=' . $f) ?>"><i class="bi bi-<?= e($ic) ?>"></i> <?= e($lab) ?></a>
      <?php else: ?>
        <span class="btn btn-outline-secondary btn-sm disabled" title="Not available on this server"><i class="bi bi-<?= e($ic) ?>"></i> <?= e($lab) ?></span>
      <?php endif; ?>
    <?php endforeach; ?>
    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
  </div>
</div>

<h2 class="hh-section" style="margin-top:6px"><?= e($table['title']) ?> <small class="text-secondary">· <?= e($table['subtitle']) ?></small></h2>

<?php if (!empty($table['chart']) && $table['chart']['labels']): ?>
  <section class="hh-card mb-3" x-data='reportChart(<?= e(json_encode($table['chart'], JSON_UNESCAPED_SLASHES)) ?>)' x-init="init()">
    <div class="hh-chartbox"><canvas x-ref="canvas" role="img" aria-label="Output through the day"></canvas></div>
  </section>
<?php endif; ?>

<?php $bars = $table['bars'] ?? null; ?>
<div <?php if ($bars): ?>x-data="barsView(<?= e(json_encode($bars, JSON_UNESCAPED_SLASHES)) ?>)" x-init="init()"<?php endif; ?>>
<?php if ($bars): ?>
  <nav class="hh-segment hh-viewtoggle no-print" aria-label="View">
    <button type="button" :class="{ 'is-active': view === 'table' }" @click="show('table')"><i class="bi bi-table"></i> Table</button>
    <button type="button" :class="{ 'is-active': view === 'graph' }" @click="show('graph')"><i class="bi bi-bar-chart-line"></i> Graph</button>
  </nav>
  <?php if ($table['rows']): ?>
  <section class="hh-card mb-3" x-show="view === 'graph'" x-cloak>
    <div class="hh-chartbox hh-chartbox--bars" :style="'height:' + height + 'px'"><canvas x-ref="canvas" role="img" aria-label="Estimated kWh per feeder, highest first"></canvas></div>
    <p class="hh-hint mb-0 mt-2"><i class="bi bi-info-circle"></i> Hover or tap a bar for its share of the total and the change on last month. Amber bars are up more than 10%, green bars down more than 10%.</p>
  </section>
  <?php endif; ?>
<?php endif; ?>
<?php if (!$table['rows']): ?>
  <div class="hh-empty hh-card"><div class="hh-empty__icon"><i class="bi bi-inbox"></i></div>
    <p>No data for <?= e($param === 'month' ? date('F Y', strtotime($month . '-01')) : date('j F Y', strtotime($date))) ?> yet.</p>
    <?php if (!empty($latest)): ?><a class="btn btn-outline-primary btn-sm" href="<?= e(url($path) . '?' . ($param === 'month' ? 'month=' : 'date=') . $latest . ($bars ? '&view=graph' : '')) ?>">Show the latest period with data (<?= e($param === 'month' ? date('F Y', strtotime($latest . '-01')) : date('j M Y', strtotime($latest))) ?>)</a><?php endif; ?>
  </div>
<?php endif; ?>
<div <?php if ($bars): ?>x-show="view === 'table'"<?php endif; ?>>
<?php if ($table['rows']): ?>
  <div class="hh-tablewrap">
    <table class="hh-table <?= !empty($table['sticky']) ? 'hh-table--sticky' : '' ?>">
      <thead>
        <tr>
          <?php foreach ($cols as $i => $c): ?>
            <th class="<?= $c['type'] === 'text' ? 'txt' : 'num' ?>" scope="col"><?= e($c['label']) ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($table['rows'] as $row): ?>
          <tr>
            <?php foreach ($row as $i => $v):
                $c = $cols[$i];
                $isHead = $i === 0 && !empty($table['sticky']); ?>
              <?php if ($isHead): ?>
                <th scope="row" class="txt"><?= e($v) ?></th>
              <?php elseif ($c['type'] === 'num'): ?>
                <td class="num <?= $v === null ? 'muted' : '' ?>"><?= $v === null ? '—' : e(num($v, (int) ($c['dp'] ?? 1))) ?></td>
              <?php else: ?>
                <td class="txt"><?= e($v ?? '—') ?></td>
              <?php endif; ?>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <?php if (!empty($table['foot'])): ?>
        <tfoot>
          <?php foreach ($table['foot'] as $row): ?>
            <tr>
              <?php foreach ($row as $i => $v):
                  $c = $cols[$i] ?? ['type' => 'text'];
                  $isHead = $i === 0 && !empty($table['sticky']); ?>
                <?php if ($isHead): ?>
                  <th scope="row" class="txt"><?= e($v) ?></th>
                <?php elseif (is_float($v) || is_int($v)): ?>
                  <td class="num"><?= e(num($v, (int) ($c['dp'] ?? 1))) ?></td>
                <?php else: ?>
                  <td class="<?= $c['type'] === 'num' ? 'num' : 'txt' ?>"><?= $v === null ? '' : e($v) ?></td>
                <?php endif; ?>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tfoot>
      <?php endif; ?>
    </table>
  </div>
<?php endif; ?>

</div>
</div>

<?php if (!empty($table['notes'])): ?>
  <ul class="hh-notes">
    <?php foreach ($table['notes'] as $n): ?><li><i class="bi bi-info-circle"></i> <?= e($n) ?></li><?php endforeach; ?>
  </ul>
<?php endif; ?>
