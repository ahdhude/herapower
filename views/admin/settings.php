<?php /** @var array $fields @var array $values */ ?>
<form method="post" action="<?= e(url('/admin/settings')) ?>">
  <?= csrf_field() ?>
  <?php foreach ($fields as $section => $items): ?>
    <h2 class="hh-section"><?= e($section) ?></h2>
    <div class="hh-card">
      <div class="hh-formgrid">
        <?php foreach ($items as $key => [$label, $hint, $type]): $val = $values[$key] ?? ''; ?>
          <div>
            <?php if ($type === 'bool'): ?>
              <label class="hh-check"><input type="hidden" name="<?= e($key) ?>" value="0"><input class="form-check-input mt-0" type="checkbox" name="<?= e($key) ?>" value="1" <?= $val === '1' ? 'checked' : '' ?>> <strong><?= e($label) ?></strong></label>
            <?php else: ?>
              <label class="form-label" for="s-<?= e($key) ?>"><?= e($label) ?></label>
              <input class="form-control" id="s-<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e($val) ?>"
                     <?= $type === 'time' ? 'type="time"' : ($type === 'email' ? 'type="email"' : 'inputmode="decimal"') ?> autocomplete="off">
            <?php endif; ?>
            <?php if ($hint): ?><div class="form-text"><?= e($hint) ?></div><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
  <div class="mt-4"><button class="btn btn-primary px-4" type="submit"><i class="bi bi-check2"></i> Save settings</button></div>
</form>
