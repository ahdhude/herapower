<?php /** @var bool $ready @var array $kinds @var array $batches @var int $openTotal @var string $maxUpload */ ?>
<?php if (!$ready): ?>
  <div class="hh-banner hh-banner--warn"><i class="bi bi-exclamation-triangle"></i> Excel files can't be read on this server yet: the PHP <code>zip</code> extension or the Composer packages are missing. The old <code>.sql</code> export still works.</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-5">
    <form method="post" action="<?= e(url('/admin/import/analyse')) ?>" enctype="multipart/form-data" class="hh-card" x-data="{ name: '' }">
      <?= csrf_field() ?>
      <h2 class="h6 fw-bold mb-1"><i class="bi bi-upload"></i> Import one month</h2>
      <p class="text-secondary small">Upload a logsheet and you'll see exactly what it would add before anything is saved.</p>

      <label class="hh-drop" :class="{ 'has-file': name }">
        <input type="file" name="file" accept=".xlsx,.xlsm,.xls,.sql" required @change="name = $event.target.files[0] ? $event.target.files[0].name : ''">
        <i class="bi bi-file-earmark-spreadsheet"></i>
        <span x-text="name || 'Choose a file (.xlsx or .sql)'"></span>
        <small>Up to <?= e($maxUpload) ?> · feeder, solar, Water &amp; Energy, or the old meter_readings export</small>
      </label>

      <div class="hh-formgrid mt-3">
        <div>
          <label class="form-label" for="imp-month">Month</label>
          <input class="form-control" id="imp-month" type="month" name="month" max="<?= e(date('Y-m')) ?>">
          <div class="form-text">Leave blank to read it from the file name (e.g. “solar log july 2025”).</div>
        </div>
        <div>
          <label class="form-label" for="imp-kind">Type of file</label>
          <select class="form-select" id="imp-kind" name="kind">
            <option value="auto">Detect automatically</option>
            <?php foreach ($kinds as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <button class="btn btn-primary w-100 mt-3" type="submit"><i class="bi bi-search"></i> Check the file</button>
    </form>

    <?php if ($openTotal): ?>
      <a class="hh-card hh-list__item mt-3 text-decoration-none" href="<?= e(url('/admin/import/review')) ?>">
        <span class="hh-chip-icon is-solar"><i class="bi bi-flag"></i></span>
        <span class="hh-list__text"><strong><?= (int) $openTotal ?> item<?= $openTotal === 1 ? '' : 's' ?> waiting for review</strong><small>Cells that weren't numbers and values that look like a missed decimal point</small></span>
        <i class="bi bi-chevron-right text-secondary"></i>
      </a>
    <?php endif; ?>
  </div>

  <div class="col-lg-7">
    <div class="hh-card">
      <h2 class="h6 fw-bold mb-2"><i class="bi bi-shield-check"></i> How the import treats your data</h2>
      <ul class="hh-rules">
        <li><strong>The date comes from the month you choose</strong> plus the day number at the start of each sheet name (<code>14.09.2025</code> is the 14th). The date typed inside a sheet is often wrong, so it is ignored.</li>
        <li><strong>Columns are matched by the feeder name in the header</strong>, so a column that moved still lands on the right meter. A single mistyped header is read from its neighbours and flagged.</li>
        <li><strong>Anything that isn't a clean number</strong> (<code>11..21</code>, <code>7.72.</code>, <code>23.8/4</code>) is not imported. It goes to the review list with a suggested fix that only applies when you accept it.</li>
        <li><strong>Numbers that look like a missed decimal</strong> (<code>3.54</code> among 400s) are imported but flagged for review.</li>
        <li><strong>Existing readings win.</strong> Nothing already in the app is overwritten unless you choose to replace earlier imported values.</li>
        <li><strong>Water &amp; Energy:</strong> only the meter readings are imported. Consumption, totals and kWh/L are recomputed, never copied.</li>
        <li><strong>Every import is a batch you can undo.</strong></li>
      </ul>
    </div>
  </div>
</div>

<h2 class="hh-section">Previous imports</h2>
<?php if (!$batches): ?>
  <div class="hh-card hh-empty"><div class="hh-empty__icon"><i class="bi bi-inbox"></i></div><p class="mb-0">Nothing imported yet.</p></div>
<?php else: ?>
  <div class="hh-tablewrap">
    <table class="hh-table">
      <thead><tr><th class="txt">#</th><th class="txt">File</th><th class="txt">Type</th><th class="txt">Month</th><th>Added</th><th>Already there</th><th>Kept (differed)</th><th>To review</th><th class="txt">By</th><th class="txt">When</th></tr></thead>
      <tbody>
        <?php foreach ($batches as $b): ?>
          <tr style="<?= $b['undone_at'] ? 'opacity:.5' : '' ?>">
            <td class="txt"><a href="<?= e(url('/admin/import/batch/' . $b['id'])) ?>">#<?= (int) $b['id'] ?></a></td>
            <td class="txt"><?= e($b['file']) ?><?php if ($b['undone_at']): ?> <span class="hh-pill hh-pill--muted">undone</span><?php endif; ?></td>
            <td class="txt"><?= e(str_replace('_', ' ', $b['kind'])) ?></td>
            <td class="txt"><?= e($b['month']) ?></td>
            <td class="num"><?= e(number_format((int) $b['rows_added'])) ?></td>
            <td class="num"><?= e(number_format((int) $b['rows_same'])) ?></td>
            <td class="num"><?= (int) $b['rows_conflict'] ?></td>
            <td class="num"><?php if ((int) $b['open_issues']): ?><span class="hh-pill hh-pill--warn"><?= (int) $b['open_issues'] ?> open</span><?php else: ?>—<?php endif; ?></td>
            <td class="txt"><?= e($b['by_name']) ?></td>
            <td class="txt"><?= e(substr($b['created_at'], 0, 16)) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
