<?php /** @var array $rows @var int $page @var int $pages @var int $total */ ?>
<p class="hh-lead"><?= e(number_format($total)) ?> change<?= $total === 1 ? '' : 's' ?> recorded. Each row is a reading edited after it was first saved.</p>
<?php if (!$rows): ?>
  <div class="hh-empty hh-card"><div class="hh-empty__icon"><i class="bi bi-clock-history"></i></div><p>No readings have been changed yet.</p></div>
<?php else: ?>
  <div class="hh-tablewrap">
    <table class="hh-table">
      <thead><tr><th class="txt">When</th><th class="txt">Meter</th><th class="txt">Slot</th><th>Old</th><th>New</th><th class="txt">Changed by</th><th class="txt">Reason</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="txt"><?= e(substr($r['changed_at'], 0, 16)) ?></td>
            <td class="txt"><?= e($r['meter_name']) ?></td>
            <td class="txt"><?= e(substr($r['reading_at'], 0, 16)) ?></td>
            <td class="num"><?= e(trim_dec($r['old_value'])) ?> <span class="muted"><?= e($r['unit']) ?></span></td>
            <td class="num"><strong><?= e(trim_dec($r['new_value'])) ?></strong></td>
            <td class="txt"><?= e($r['user_name']) ?></td>
            <td class="txt" style="white-space:normal;min-width:180px"><?= e($r['reason'] ?: '—') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
    <nav class="d-flex justify-content-center gap-2 mt-3">
      <a class="btn btn-outline-secondary btn-sm <?= $page <= 1 ? 'disabled' : '' ?>" href="?page=<?= $page - 1 ?>">Newer</a>
      <span class="align-self-center small text-secondary">Page <?= $page ?> of <?= $pages ?></span>
      <a class="btn btn-outline-secondary btn-sm <?= $page >= $pages ? 'disabled' : '' ?>" href="?page=<?= $page + 1 ?>">Older</a>
    </nav>
  <?php endif; ?>
<?php endif; ?>
