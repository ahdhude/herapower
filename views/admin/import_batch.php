<?php
/** @var array $b @var array $sum @var array $kinds @var array $counts @var array $list */
$open = static fn (string $k) => (int) ($counts[$k]['open'] ?? 0);
?>
<a class="btn btn-link px-0 mb-2" href="<?= e(url('/admin/import')) ?>"><i class="bi bi-arrow-left"></i> All imports</a>

<div class="hh-previewhead">
  <span class="hh-chip-icon"><i class="bi bi-box-seam"></i></span>
  <div>
    <h2><?= e($b['file']) ?> <?php if ($b['undone_at']): ?><span class="hh-pill hh-pill--muted">undone <?= e(substr($b['undone_at'], 0, 16)) ?></span><?php endif; ?></h2>
    <p><?= e($kinds[$b['kind']] ?? $b['kind']) ?> · <strong><?= e(date('F Y', strtotime($b['month'] . '-01'))) ?></strong> · by <?= e($b['by_name']) ?>, <?= e(substr($b['created_at'], 0, 16)) ?></p>
  </div>
</div>

<div class="hh-stats">
  <div class="hh-stat is-good"><strong><?= e(number_format((int) $b['rows_added'])) ?></strong><span>readings added</span></div>
  <div class="hh-stat"><strong><?= e(number_format((int) $b['rows_same'])) ?></strong><span>already there, same</span></div>
  <div class="hh-stat <?= $b['rows_conflict'] ? 'is-warn' : '' ?>"><strong><?= (int) $b['rows_conflict'] ?></strong><span>differed, kept as they were</span></div>
  <?php if ((int) $b['rows_replaced']): ?><div class="hh-stat"><strong><?= (int) $b['rows_replaced'] ?></strong><span>replaced</span></div><?php endif; ?>
  <div class="hh-stat <?= ($open('cell') + $open('suspect')) ? 'is-warn' : 'is-good' ?>"><strong><?= $open('cell') + $open('suspect') ?></strong><span>still to review</span></div>
</div>
<?php if ($b['days_missing'] && $b['kind'] !== 'sql'): ?>
  <p class="hh-hint"><i class="bi bi-calendar-x"></i> No readings on day<?= str_contains($b['days_missing'], ',') || str_contains($b['days_missing'], '-') ? 's' : '' ?> <?= e($b['days_missing']) ?>.</p>
<?php endif; ?>

<?php if (!empty($sum['notes'])): ?>
  <ul class="hh-notes hh-notes--card"><?php foreach ($sum['notes'] as $n): ?><li><i class="bi bi-info-circle"></i> <?= e($n) ?></li><?php endforeach; ?></ul>
<?php endif; ?>

<?php if (!$b['undone_at']): ?>
  <div class="d-flex flex-wrap gap-2 mb-3">
    <?php foreach ([['cell', 'not a number'], ['suspect', 'to double-check']] as [$k, $lab]):
        if (!$open($k)) { continue; } ?>
      <form method="post" action="<?= e(url('/admin/import/batch/' . $b['id'] . '/bulk')) ?>" class="d-flex gap-1">
        <?= csrf_field() ?><input type="hidden" name="kind" value="<?= e($k) ?>">
        <button class="btn btn-sm btn-outline-primary" name="action" value="accept" onclick="return confirm('Apply every suggestion for the <?= (int) $open($k) ?> open items (<?= e($lab) ?>)? Items without a suggestion are left open.')"><i class="bi bi-check2-all"></i> Apply all suggestions · <?= e($lab) ?></button>
        <button class="btn btn-sm btn-outline-secondary" name="action" value="ignore" onclick="return confirm('Dismiss all <?= (int) $open($k) ?> open items (<?= e($lab) ?>)? The values stay as they are.')">Dismiss all</button>
      </form>
    <?php endforeach; ?>
    <form method="post" action="<?= e(url('/admin/import/batch/' . $b['id'] . '/undo')) ?>" class="ms-auto" onsubmit="return confirm('Undo this import? Its readings are removed (anything edited since is kept).')">
      <?= csrf_field() ?><button class="btn btn-sm btn-outline-danger"><i class="bi bi-arrow-counterclockwise"></i> Undo import</button>
    </form>
  </div>
<?php endif; ?>

<?php $info = (int) ($counts['info']['open'] ?? 0); if ($info): ?>
  <details class="hh-card hh-details mb-3"><summary><strong><?= $info ?> note<?= $info === 1 ? '' : 's' ?></strong> about how the file was read</summary>
    <?php
      $notes = App\Core\DB::all("SELECT i.*, m.name AS meter_name FROM import_issues i LEFT JOIN meters m ON m.id = i.meter_id WHERE i.batch_id = ? AND i.kind = 'info' AND i.status = 'open' ORDER BY i.id LIMIT 60", [$b['id']]); ?>
    <ul class="hh-notes mt-2"><?php foreach ($notes as $n): ?><li><?= e($n['sheet']) ?>!<?= e($n['cell']) ?> — <?= e($n['message']) ?></li><?php endforeach; ?></ul>
    <?php if ($info > 60): ?><p class="small text-secondary mb-0">…and <?= $info - 60 ?> more.</p><?php endif; ?>
    <form method="post" action="<?= e(url('/admin/import/batch/' . $b['id'] . '/bulk')) ?>" class="mt-2"><?= csrf_field() ?><input type="hidden" name="kind" value="info"><button class="btn btn-sm btn-outline-secondary" name="action" value="ignore">Dismiss notes</button></form>
  </details>
<?php endif; ?>

<?php require __DIR__ . '/../partials/import_issues.php'; ?>
