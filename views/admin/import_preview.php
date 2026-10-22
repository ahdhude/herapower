<?php
/** @var string $token @var \App\Services\Import\Result $res @var string $monthHow @var array $plans @var array $policies @var array $kinds @var array $missing @var array $perDay */
$keep = $plans['keep'];
$byKind = ['cell' => 0, 'suspect' => 0, 'info' => 0];
foreach ($res->issues as $i) {
    $byKind[$i['kind']]++;
}
$monthLabel = date('F Y', strtotime($res->month . '-01'));
$days = $res->daysInMonth();
$isSql = $res->kind === 'sql';
?>
<div class="hh-previewhead">
  <span class="hh-chip-icon <?= $res->kind === 'solar' ? 'is-solar' : ($res->kind === 'water_energy' ? 'is-daily' : '') ?>"><i class="bi bi-<?= $res->kind === 'solar' ? 'sun' : ($res->kind === 'water_energy' ? 'droplet' : ($isSql ? 'database' : 'lightning-charge')) ?>"></i></span>
  <div>
    <h2><?= e($res->file) ?></h2>
    <p><?= e($kinds[$res->kind] ?? $res->kind) ?> · <strong><?= e($monthLabel) ?></strong> <span class="text-secondary">(<?= e($monthHow) ?>)</span></p>
  </div>
</div>

<div class="hh-stats">
  <div class="hh-stat is-good"><strong><?= e(number_format($keep['new'])) ?></strong><span>new readings to add</span></div>
  <div class="hh-stat"><strong><?= e(number_format($keep['same'])) ?></strong><span>already in the app, same value</span></div>
  <div class="hh-stat <?= $keep['conflict'] ? 'is-warn' : '' ?>"><strong><?= e(number_format($keep['conflict'])) ?></strong><span>already there, different value</span></div>
  <div class="hh-stat <?= $byKind['cell'] ? 'is-warn' : '' ?>"><strong><?= (int) $byKind['cell'] ?></strong><span>cells that aren't numbers</span></div>
  <div class="hh-stat <?= $byKind['suspect'] ? 'is-warn' : '' ?>"><strong><?= (int) $byKind['suspect'] ?></strong><span>values to double-check</span></div>
</div>

<?php if (!$res->rows): ?>
  <div class="hh-banner hh-banner--error"><i class="bi bi-x-octagon"></i> No readings were found for <?= e($monthLabel) ?>. Check the month, or the file may be an empty template.</div>
<?php endif; ?>

<?php if (!$isSql && $res->rows): ?>
  <h3 class="hh-section">Days found</h3>
  <div class="hh-days" role="list">
    <?php for ($d = 1; $d <= $days; $d++):
        $n = $perDay[$d]['rows'] ?? 0; $iss = $perDay[$d]['issues'] ?? 0; ?>
      <span role="listitem" class="hh-day <?= $n ? ($iss ? 'has-issue' : 'is-ok') : 'is-missing' ?>" title="<?= $n ? $n . ' readings' . ($iss ? ', ' . $iss . ' to review' : '') : 'No readings' ?>"><?= $d ?></span>
    <?php endfor; ?>
  </div>
  <p class="hh-hint"><?= $missing ? '<i class="bi bi-calendar-x"></i> No readings on day' . (count($missing) > 1 ? 's' : '') . ' ' . e(\App\Services\Import\Importer::ranges($missing)) . '.' : '<i class="bi bi-calendar-check"></i> Every day of the month has readings.' ?></p>
<?php endif; ?>

<?php if ($res->notes): ?>
  <ul class="hh-notes hh-notes--card">
    <?php foreach ($res->notes as $n): ?><li><i class="bi bi-info-circle"></i> <?= e($n) ?></li><?php endforeach; ?>
  </ul>
<?php endif; ?>

<details class="hh-card hh-details" <?= count($res->sheets) <= 8 ? 'open' : '' ?>>
  <summary><strong><?= count(array_filter($res->sheets, fn ($s) => $s['status'] === 'used')) ?> sheet(s) read</strong>, <?= count(array_filter($res->sheets, fn ($s) => $s['status'] === 'skipped')) ?> skipped</summary>
  <table class="hh-table mt-2">
    <thead><tr><th class="txt">Sheet</th><th class="txt">Result</th><th>Readings</th></tr></thead>
    <tbody>
      <?php foreach ($res->sheets as $s): ?>
        <tr>
          <td class="txt"><?= e($s['name']) ?></td>
          <td class="txt" style="white-space:normal"><?= $s['status'] === 'used' ? '<span class="hh-pill hh-pill--ok">read</span> ' . e($s['reason']) : '<span class="hh-pill hh-pill--muted">skipped</span> ' . e($s['reason']) ?></td>
          <td class="num"><?= $s['status'] === 'used' ? e(number_format((int) $s['cells'])) : '' ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</details>

<?php $sample = array_values(array_filter($res->issues, fn ($i) => $i['kind'] !== 'info')); if ($sample): ?>
  <details class="hh-card hh-details">
    <summary><strong><?= count($sample) ?> item<?= count($sample) === 1 ? '' : 's' ?> will go to the review list</strong> (first 15 shown)</summary>
    <table class="hh-table mt-2">
      <thead><tr><th class="txt">Where</th><th class="txt">Meter · time</th><th class="txt">In the file</th><th class="txt">Suggested</th></tr></thead>
      <tbody>
        <?php foreach (array_slice($sample, 0, 15) as $i): ?>
          <tr>
            <td class="txt"><?= e($i['sheet']) ?>!<?= e($i['cell']) ?></td>
            <td class="txt"><?= e(substr((string) $i['reading_at'], 0, 16)) ?></td>
            <td class="txt"><code><?= e($i['raw']) ?></code> <span class="hh-pill hh-pill--<?= $i['kind'] === 'cell' ? 'bad' : 'warn' ?>"><?= $i['kind'] === 'cell' ? 'not a number' : 'check' ?></span></td>
            <td class="txt"><?= $i['suggested'] !== null ? '<code>' . e($i['suggested']) . '</code>' : '<span class="text-secondary">none</span>' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </details>
<?php endif; ?>

<form method="post" action="<?= e(url('/admin/import/commit')) ?>" class="hh-card mt-3">
  <?= csrf_field() ?>
  <input type="hidden" name="token" value="<?= e($token) ?>">
  <h3 class="h6 fw-bold mb-2">If a reading is already in the app</h3>
  <?php foreach ($policies as $k => $label): $p = $plans[$k]; ?>
    <label class="hh-radio">
      <input type="radio" name="policy" value="<?= e($k) ?>" <?= $k === 'keep' ? 'checked' : '' ?>>
      <span><strong><?= e($label) ?></strong>
        <small><?= e(number_format($p['new'] + $p['replace'])) ?> change<?= ($p['new'] + $p['replace']) === 1 ? '' : 's' ?><?= $k === 'replace-imported' ? ' (' . (int) $p['replace'] . ' replaced)' : '' ?><?= $p['conflict'] ? ', ' . (int) $p['conflict'] . ' left alone' : '' ?></small></span>
    </label>
  <?php endforeach; ?>
  <div class="d-flex flex-wrap gap-2 mt-3">
    <button class="btn btn-primary px-4" type="submit" <?= (!$res->rows && !$res->issues) ? 'disabled' : '' ?>><i class="bi bi-check2-circle"></i> Import <?= e(number_format($keep['new'])) ?> readings</button>
    <button class="btn btn-outline-secondary" type="submit" formaction="<?= e(url('/admin/import/cancel')) ?>">Cancel</button>
  </div>
  <p class="hh-hint mt-2 mb-0"><i class="bi bi-arrow-counterclockwise"></i> You can undo the whole import afterwards.</p>
</form>
