<?php
/** Review list shared by the batch page and the global review page. Expects $counts and $list. */
$q = static fn (array $over) => '?' . http_build_query(array_filter(array_merge(['kind' => $list['kind'], 'status' => $list['status']], $over), static fn ($v) => $v !== '' && $v !== null));
$tab = static fn (string $label, array $over, bool $on) => '<a class="' . ($on ? 'is-active' : '') . '" href="' . e($q($over)) . '">' . $label . '</a>';
$n = static fn (string $k, string $s) => (int) ($counts[$k][$s] ?? 0);
$openAll = $n('cell', 'open') + $n('suspect', 'open');
?>
<nav class="hh-segment mb-2" aria-label="Filter">
  <?= $tab('Open <span class="hh-count">' . $openAll . '</span>', ['status' => 'open', 'kind' => '', 'page' => ''], $list['status'] === 'open' && $list['kind'] === '') ?>
  <?= $tab('Not numbers <span class="hh-count">' . $n('cell', 'open') . '</span>', ['status' => 'open', 'kind' => 'cell', 'page' => ''], $list['status'] === 'open' && $list['kind'] === 'cell') ?>
  <?= $tab('To check <span class="hh-count">' . $n('suspect', 'open') . '</span>', ['status' => 'open', 'kind' => 'suspect', 'page' => ''], $list['status'] === 'open' && $list['kind'] === 'suspect') ?>
  <?= $tab('Done', ['status' => 'all', 'kind' => '', 'page' => ''], $list['status'] === 'all') ?>
</nav>

<?php if (!$list['rows']): ?>
  <div class="hh-card hh-empty"><div class="hh-empty__icon"><i class="bi bi-check2-circle"></i></div><p class="mb-0"><?= $list['status'] === 'open' ? 'Nothing left to review.' : 'Nothing here.' ?></p></div>
<?php else: ?>
  <div class="hh-issues">
    <?php foreach ($list['rows'] as $i):
        $cfg = ['id' => (int) $i['id'], 'status' => $i['status'], 'suggested' => $i['suggested_value'] === null ? null : trim_dec($i['suggested_value']), 'kind' => $i['kind'], 'url' => url('/admin/import/issues/' . (int) $i['id'])]; ?>
      <article class="hh-iss" x-data='importIssue(<?= e(json_encode($cfg, JSON_UNESCAPED_SLASHES)) ?>)' :class="{ 'is-done': status !== 'open' }">
        <header>
          <strong><?= e($i['meter_name'] ?? 'Unknown meter') ?></strong>
          <span class="text-secondary"><?= e($i['reading_at'] ? substr($i['reading_at'], 0, 16) : '') ?></span>
          <span class="hh-pill hh-pill--<?= $i['kind'] === 'cell' ? 'bad' : ($i['kind'] === 'suspect' ? 'warn' : 'muted') ?>"><?= $i['kind'] === 'cell' ? 'not a number' : ($i['kind'] === 'suspect' ? 'check' : 'note') ?></span>
          <small class="ms-auto text-secondary"><?= e($i['file']) ?> · <?= e($i['sheet']) ?>!<?= e($i['cell']) ?></small>
        </header>
        <p><?= e($i['message']) ?></p>
        <div class="hh-iss__vals">
          <span>In the file <code><?= e($i['raw_value']) ?></code></span>
          <?php if ($i['kind'] === 'suspect' && $i['current_value'] !== null): ?><span>Imported as <code><?= e(trim_dec($i['current_value'])) ?></code></span><?php endif; ?>
          <?php if ($i['suggested_value'] !== null): ?><span class="is-suggest">Suggested <code><?= e(trim_dec($i['suggested_value'])) ?></code><?= e($i['meter_unit'] ? ' ' . $i['meter_unit'] : '') ?></span><?php endif; ?>
        </div>

        <div class="hh-iss__actions" x-show="status === 'open'">
          <button type="button" class="btn btn-sm btn-primary" x-show="suggested !== null" :disabled="busy" @click="act('accept')">Use <code class="text-white" x-text="suggested"></code></button>
          <span class="hh-iss__fix" x-show="kind !== 'info'">
            <input class="form-control form-control-sm" inputmode="decimal" placeholder="Correct value" x-model="value" @keydown.enter.prevent="act('fix')" aria-label="Correct value">
            <button type="button" class="btn btn-sm btn-outline-primary" :disabled="busy || !value" @click="act('fix')">Save</button>
          </span>
          <button type="button" class="btn btn-sm btn-outline-secondary" :disabled="busy" @click="act('ignore')"><?= $i['kind'] === 'cell' ? 'Leave empty' : 'Keep as imported' ?></button>
        </div>
        <p class="hh-iss__result" x-show="status !== 'open'" x-text="resultText()"><?= e($i['status'] === 'open' ? '' : ucfirst($i['status'])) ?></p>
        <p class="hh-iss__error" x-show="error" x-text="error"></p>
      </article>
    <?php endforeach; ?>
  </div>

  <?php if ($list['pages'] > 1): ?>
    <nav class="d-flex justify-content-center gap-2 mt-3">
      <a class="btn btn-outline-secondary btn-sm <?= $list['page'] <= 1 ? 'disabled' : '' ?>" href="<?= e($q(['page' => $list['page'] - 1])) ?>">Previous</a>
      <span class="align-self-center small text-secondary">Page <?= (int) $list['page'] ?> of <?= (int) $list['pages'] ?> · <?= (int) $list['total'] ?> items</span>
      <a class="btn btn-outline-secondary btn-sm <?= $list['page'] >= $list['pages'] ? 'disabled' : '' ?>" href="<?= e($q(['page' => $list['page'] + 1])) ?>">Next</a>
    </nav>
  <?php endif; ?>
<?php endif; ?>
