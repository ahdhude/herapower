<?php /** @var array $t */ $cols = $t['cols']; $n = count($cols); ?>
<!doctype html>
<html><head><meta charset="utf-8"><style>
  @page { margin: 22px 20px; }
  body { font-family: DejaVu Sans, sans-serif; color: #111; font-size: <?= $n > 20 ? 5.5 : ($n > 9 ? 7 : 9) ?>px; }
  h1 { font-size: 15px; margin: 0 0 2px; } .sub { color: #555; margin-bottom: 10px; font-size: 10px; }
  table { border-collapse: collapse; width: 100%; }
  th { background: #e8eefc; text-align: right; padding: 3px 4px; border: .5px solid #c9d2ea; }
  td { padding: 2px 4px; border: .5px solid #dde3f2; text-align: right; }
  th.t, td.t { text-align: left; } tfoot td, tfoot th { background: #f2f5fc; font-weight: bold; }
  .notes { margin-top: 8px; color: #555; font-size: 8px; }
</style></head><body>
<h1><?= e($t['title']) ?></h1>
<div class="sub"><?= e($t['subtitle']) ?> · Canareef powerhouse · Herahakatha</div>
<table>
  <thead><tr><?php foreach ($cols as $c): ?><th class="<?= $c['type'] === 'text' ? 't' : '' ?>"><?= e($c['label']) ?></th><?php endforeach; ?></tr></thead>
  <tbody>
  <?php foreach ($t['rows'] as $row): ?><tr>
    <?php foreach ($row as $i => $v): $c = $cols[$i]; ?>
      <td class="<?= $c['type'] === 'text' ? 't' : '' ?>"><?= $v === null ? '—' : ($c['type'] === 'num' && (is_float($v) || is_int($v)) ? e(num($v, (int) ($c['dp'] ?? 1))) : e($v)) ?></td>
    <?php endforeach; ?>
  </tr><?php endforeach; ?>
  </tbody>
  <?php if (!empty($t['foot'])): ?><tfoot>
  <?php foreach ($t['foot'] as $row): ?><tr>
    <?php foreach ($row as $i => $v): $c = $cols[$i] ?? ['type' => 'text']; ?>
      <td class="<?= $c['type'] === 'text' ? 't' : '' ?>"><?= $v === null ? '' : ((is_float($v) || is_int($v)) ? e(num($v, (int) ($c['dp'] ?? 1))) : e($v)) ?></td>
    <?php endforeach; ?>
  </tr><?php endforeach; ?>
  </tfoot><?php endif; ?>
</table>
<?php foreach ($t['notes'] ?? [] as $n): ?><div class="notes"><?= e($n) ?></div><?php endforeach; ?>
</body></html>
