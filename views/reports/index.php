<?php
use App\Core\Auth;
$isOp = Auth::hasRole('operator');
?>
<p class="hh-lead">Every report is built from the saved readings, so nothing is copied between sheets.</p>
<div class="hh-grid-cards">
  <?php foreach ($reports as $slug => [$label, $icon, $param, $desc]):
      $locked = $isOp && $param === 'month';
      $href = '/reports/' . str_replace('-', '/', $slug); ?>
    <article class="hh-card hh-report-card" <?= $locked ? 'style="opacity:.55"' : '' ?>>
      <span class="hh-chip-icon <?= str_contains($slug, 'solar') ? 'is-solar' : (in_array($slug, ['monthly', 'completeness'], true) ? 'is-daily' : (str_contains($slug, 'genset') ? 'is-genset' : '')) ?>"><i class="bi bi-<?= e($icon) ?>"></i></span>
      <div>
        <h3><?php if ($locked): ?><?= e($label) ?><?php else: ?><a class="stretched-link" href="<?= e(url($href)) ?>"><?= e($label) ?></a><?php endif; ?></h3>
        <p><?= e($desc) ?></p>
        <?php if ($locked): ?><p class="mt-1"><i class="bi bi-lock"></i> Supervisors and managers</p><?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
</div>
