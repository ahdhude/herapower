<?php
/** @var ?array $meter @var array $groups @var array $plants @var array $errors @var bool $locked */
$m = $meter ?? [];
$isNew = empty($m['id']);
$v = static fn (string $k, mixed $d = '') => e($m[$k] ?? $d);
$defaults = [
    'feeder'    => ['type' => 'interval',   'unit' => 'kW',  'mult' => '1',   'step' => 60, 'ws' => '00:00', 'we' => '23:00', 'group' => 1],
    'solar'     => ['type' => 'interval',   'unit' => 'kW',  'mult' => '1',   'step' => 30, 'ws' => '06:00', 'we' => '18:00', 'group' => 3],
    'inverter'  => ['type' => 'cumulative', 'unit' => 'kWh', 'mult' => '1',   'group' => 4],
    'water'     => ['type' => 'cumulative', 'unit' => 'm3',  'mult' => '1',   'group' => 5],
    'generator' => ['type' => 'cumulative', 'unit' => 'kWh', 'mult' => '0.1', 'group' => 6],
    'fuel'      => ['type' => 'cumulative', 'unit' => 'L',   'mult' => '1',   'group' => 7],
];
$state = [
    'kind' => $m['kind'] ?? 'feeder', 'type' => $m['reading_type'] ?? 'interval', 'unit' => $m['unit'] ?? 'kW',
    'mult' => isset($m['multiplier']) ? trim_dec($m['multiplier']) : '1', 'step' => $m['interval_min'] ?? 60,
    'ws' => substr((string) ($m['window_start'] ?? '00:00'), 0, 5), 'we' => substr((string) ($m['window_end'] ?? '23:00'), 0, 5),
    'group' => (int) ($m['group_id'] ?? 1), 'isNew' => $isNew, 'defaults' => $defaults,
];
?>
<a class="btn btn-link px-0 mb-2" href="<?= e(url('/admin/meters')) ?>"><i class="bi bi-arrow-left"></i> All meters</a>

<?php if ($errors): ?>
  <div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<?php if ($locked): ?>
  <div class="hh-banner hh-banner--warn"><i class="bi bi-lock"></i> This meter has readings, so its type and multiplier are locked.</div>
<?php endif; ?>

<form method="post" action="<?= e(url('/admin/meters')) ?>" class="hh-card" x-data='{ f: <?= e(json_encode($state)) ?>, pick() { if (!this.f.isNew) return; const d = this.f.defaults[this.f.kind]; Object.assign(this.f, { type: d.type, unit: d.unit, mult: d.mult, group: d.group }); if (d.step) Object.assign(this.f, { step: d.step, ws: d.ws, we: d.we }); } }'>
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= $isNew ? '' : (int) $m['id'] ?>">

  <div class="hh-formgrid hh-formgrid--3">
    <div>
      <label class="form-label" for="kind">Kind</label>
      <select class="form-select" id="kind" name="kind" x-model="f.kind" @change="pick()">
        <?php foreach (['feeder' => 'Feeder', 'solar' => 'Solar plant', 'inverter' => 'Solar inverter', 'water' => 'Water line', 'generator' => 'Generator', 'fuel' => 'Fuel / diesel'] as $k => $l): ?>
          <option value="<?= e($k) ?>"><?= e($l) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="form-label" for="group_id">Group</label>
      <select class="form-select" id="group_id" name="group_id" x-model.number="f.group">
        <?php foreach ($groups as $g): ?><option value="<?= (int) $g['id'] ?>"><?= e($g['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="form-label" for="reading_type">Reading type</label>
      <select class="form-select" id="reading_type" name="reading_type" x-model="f.type" <?= $locked ? 'onmousedown="return false" tabindex="-1"' : '' ?>>
        <option value="interval">Interval (kW on the hour / half hour)</option>
        <option value="cumulative">Cumulative (daily counter)</option>
      </select>
    </div>
    <div>
      <label class="form-label" for="name">Name (as on the panel)</label>
      <input class="form-control" id="name" name="name" value="<?= $v('name') ?>" required maxlength="100">
    </div>
    <div>
      <label class="form-label" for="code">Code</label>
      <input class="form-control" id="code" name="code" value="<?= $v('code') ?>" required maxlength="40" placeholder="e.g. JSB-9001">
    </div>
    <div>
      <label class="form-label" for="unit">Unit</label>
      <input class="form-control" id="unit" name="unit" x-model="f.unit" required maxlength="10">
    </div>
    <div>
      <label class="form-label" for="multiplier">Multiplier</label>
      <input class="form-control" id="multiplier" name="multiplier" x-model="f.mult" inputmode="decimal" <?= $locked ? 'readonly' : '' ?>>
      <div class="form-text">Generator counters read ÷10 to get kWh → 0.1.</div>
    </div>
    <div x-show="f.type === 'interval'">
      <label class="form-label" for="interval_min">Every (minutes)</label>
      <input class="form-control" id="interval_min" name="interval_min" type="number" min="1" max="1440" x-model.number="f.step">
    </div>
    <div x-show="f.type === 'interval'">
      <label class="form-label" for="window_start">Window start</label>
      <input class="form-control" id="window_start" name="window_start" type="time" x-model="f.ws">
    </div>
    <div x-show="f.type === 'interval'">
      <label class="form-label" for="window_end">Window end</label>
      <input class="form-control" id="window_end" name="window_end" type="time" x-model="f.we">
    </div>
    <div>
      <label class="form-label" for="min_value">Min value (warns below)</label>
      <input class="form-control" id="min_value" name="min_value" value="<?= $v('min_value') ?>" inputmode="decimal">
    </div>
    <div>
      <label class="form-label" for="max_value">Max value (warns above)</label>
      <input class="form-control" id="max_value" name="max_value" value="<?= $v('max_value') ?>" inputmode="decimal">
    </div>
    <div>
      <label class="form-label" for="sort_order">Walk order</label>
      <input class="form-control" id="sort_order" name="sort_order" type="number" value="<?= $v('sort_order', 0) ?>">
    </div>
    <div x-show="f.kind === 'inverter'">
      <label class="form-label" for="parent_id">Solar plant</label>
      <select class="form-select" id="parent_id" name="parent_id">
        <option value="">— none —</option>
        <?php foreach ($plants as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) ($m['parent_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
      </select>
      <div class="form-text">Inverters count towards this plant's kWh cross-check.</div>
    </div>
    <?php if ($isNew): ?>
      <div>
        <label class="form-label" for="new_id">Meter id (optional)</label>
        <input class="form-control" id="new_id" name="new_id" type="number" min="1" placeholder="Auto">
        <div class="form-text">Leave blank to pick the next free id for this kind.</div>
      </div>
    <?php endif; ?>
  </div>

  <div class="d-flex flex-wrap gap-4 mt-2">
    <label class="hh-check"><input class="form-check-input mt-0" type="checkbox" name="is_incomer" value="1" <?= !empty($m['is_incomer']) ? 'checked' : '' ?>> Main incomer (HV Network)</label>
    <label class="hh-check"><input class="form-check-input mt-0" type="checkbox" name="active" value="1" <?= ($isNew || !empty($m['active'])) ? 'checked' : '' ?>> Active (shown on entry screens)</label>
  </div>

  <div class="d-flex flex-wrap gap-2 justify-content-between mt-3">
    <button class="btn btn-primary px-4" type="submit"><i class="bi bi-check2"></i> <?= $isNew ? 'Add meter' : 'Save changes' ?></button>
  </div>
</form>

<?php if (!$isNew && !$locked): ?>
  <form method="post" action="<?= e(url('/admin/meters/' . (int) $m['id'] . '/delete')) ?>" class="mt-3" onsubmit="return confirm('Delete this meter? This cannot be undone.')">
    <?= csrf_field() ?><button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i> Delete meter (no readings yet)</button>
  </form>
<?php endif; ?>
