<?php
/** @var array $cfg @var array $tabs @var string $kind */
$isSolar = $kind === 'solar';
?>
<div class="hh-entry" x-data='intervalEntry(<?= e(json_encode($cfg, JSON_UNESCAPED_SLASHES)) ?>)' x-init="init()" x-cloak>

  <?php if ($tabs): ?>
    <nav class="hh-segment" aria-label="Feeder logs">
      <?php foreach ($tabs as $t): ?>
        <a href="<?= e(url('/entry/feeders/' . $t['id'])) ?>" :href="tabUrl(<?= (int) $t['id'] ?>)" class="<?= (int) $t['id'] === (int) $group['id'] ? 'is-active' : '' ?>">
          <i class="bi bi-lightning-charge"></i> <?= e($t['name']) ?>
        </a>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>

  <!-- Toolbar: date + progress -->
  <div class="hh-toolbar">
    <div class="hh-datebar">
      <button type="button" class="hh-icon-btn" @click="shiftDay(-1)" :disabled="date <= cfg.minDate" aria-label="Previous day"><i class="bi bi-chevron-left"></i></button>
      <label class="hh-datebar__label">
        <input type="date" x-model="date" @change="changeDate()" :min="cfg.minDate" :max="cfg.today" aria-label="Date">
        <span x-text="dateLabel"></span><i class="bi bi-calendar3"></i>
      </label>
      <button type="button" class="hh-icon-btn" @click="shiftDay(1)" :disabled="date >= cfg.today" aria-label="Next day"><i class="bi bi-chevron-right"></i></button>
    </div>
    <div class="hh-meter-progress">
      <div class="hh-bar"><span :style="'width:' + progressPct + '%'"></span></div>
      <small><span x-text="completeSlots"></span> of <span x-text="slots.length"></span> <?= $isSolar ? 'slots' : 'hours' ?> complete</small>
    </div>
  </div>

  <div class="hh-banner hh-banner--info" x-show="readOnly" x-cloak><i class="bi bi-eye"></i>
    <span x-text="cfg.editFrom === null ? 'You can look at readings here but not change them.' : 'View only: this day is older than you can change. Ask a supervisor if something needs correcting.'"></span></div>
  <div class="hh-banner hh-banner--warn" x-show="fromCache"><i class="bi bi-cloud-slash"></i> You're offline — showing the last saved copy. New values will send when the connection returns.</div>
  <div class="hh-banner hh-banner--error" x-show="loadError" x-text="loadError"></div>
  <div class="hh-skeleton" x-show="loading"><span></span><span></span><span></span><span></span></div>

  <!-- ============ PHONE: one slot, one big field per meter ============ -->
  <template x-if="!wide && !loading && meters.length">
    <div>
      <div class="hh-slotnav">
        <button type="button" class="hh-icon-btn" @click="step(-1)" :disabled="slotIndex <= 0" aria-label="Earlier"><i class="bi bi-chevron-left"></i></button>
        <div class="hh-slotnav__center">
          <strong x-text="slot"></strong>
          <small x-text="slotNote"></small>
        </div>
        <button type="button" class="hh-icon-btn" @click="step(1)" :disabled="slotIndex >= slots.length - 1" aria-label="Later"><i class="bi bi-chevron-right"></i></button>
      </div>

      <div class="hh-strip" role="tablist" aria-label="Pick a time">
        <template x-for="s in slots" :key="s">
          <button type="button" class="hh-strip__chip" :class="{'is-current': s === slot, 'is-done': slotDone(s), 'is-due': s === due && !slotDone(s), 'is-missed': isMissed(s)}" @click="goSlot(s)" :data-slot="s" x-text="chipLabel(s)"></button>
        </template>
      </div>
      <div class="hh-strip__legend">
        <span><i class="dot dot--done"></i> complete</span><span><i class="dot dot--due"></i> due</span><span><i class="dot dot--missed"></i> missed</span>
        <button type="button" class="btn btn-link btn-sm p-0 ms-auto" x-show="due && slot !== due" @click="goSlot(due)">Jump to now</button>
      </div>

      <div class="hh-rows">
        <template x-for="(m, mi) in meters" :key="m.id">
          <div class="hh-row" x-data="{ get c() { return cell(m.id, slot) } }" :class="['is-' + c.state, m.is_incomer ? 'is-incomer' : '']">
            <div class="hh-row__main">
              <label class="hh-row__name" :for="'f' + m.id" x-text="m.name"></label>
              <small class="hh-row__prev">
                <span x-show="prevOf(m.id, slot) !== null">last <span x-text="prevOf(m.id, slot)"></span></span>
                <span class="hh-tag hh-tag--late" x-show="c.late">late</span>
                <span class="hh-tag" x-show="c.by" x-text="c.by"></span>
              </small>
            </div>
            <div class="hh-row__input">
              <input :id="'f' + m.id" class="hh-input" type="text" inputmode="decimal" enterkeyhint="next" autocomplete="off" autocapitalize="off" spellcheck="false"
                     placeholder="—" :disabled="isFuture(slot) || readOnly" :data-idx="mi"
                     x-model="c.value" @input="touch(c)" @blur="commit(c)" @keydown.enter.prevent="enter($event, mi)" :aria-label="m.name + ' ' + m.unit">
              <span class="hh-unit" x-text="m.unit"></span>
              <span class="hh-state" :class="'is-' + c.state" :title="stateText(c)">
                <i class="bi" :class="stateIcon(c)"></i>
              </span>
            </div>
            <?php require __DIR__ . '/../partials/issue.php'; ?>
          </div>
        </template>
      </div>

      <div class="hh-totalbar">
        <div><small><?= $isSolar ? 'Plant output' : 'This hour, all feeders on this page' ?></small><strong><span x-text="fmt(slotTotal(slot))"></span> kW</strong></div>
        <template x-if="cfg.sumCheck && sumInfo">
          <div class="hh-totalbar__sum" :class="{'is-off': sumInfo.off}">
            <small>HV Network vs Σ all feeders</small>
            <strong><span x-text="fmt(sumInfo.hv)"></span> vs <span x-text="fmt(sumInfo.sum)"></span> kW <i class="bi bi-exclamation-triangle-fill" x-show="sumInfo.off"></i></strong>
          </div>
        </template>
      </div>
    </div>
  </template>

  <!-- ============ PC: the whole day as a grid ============ -->
  <template x-if="wide && !loading && meters.length">
    <div>
      <p class="hh-hint"><i class="bi bi-keyboard"></i> Arrow keys move between cells · Enter saves and goes down · paste a block from Excel to fill several cells.</p>
      <div class="hh-gridwrap">
        <table class="hh-grid">
          <thead>
            <tr>
              <th class="hh-grid__corner" scope="col">Time</th>
              <template x-for="m in meters" :key="m.id">
                <th scope="col" :class="{'is-incomer': m.is_incomer}"><span x-text="m.name"></span><small x-text="m.unit"></small></th>
              </template>
              <th scope="col" class="hh-grid__sum" x-show="meters.length > 1">Σ</th>
            </tr>
          </thead>
          <tbody>
            <template x-for="(s, si) in slots" :key="s">
              <tr :class="{'is-due': s === due, 'is-future': isFuture(s)}">
                <th scope="row" x-text="s"></th>
                <template x-for="(m, mi) in meters" :key="m.id">
                  <td x-data="{ get c() { return cell(m.id, s) } }" :class="'is-' + c.state">
                    <input class="hh-cell" type="text" inputmode="decimal" autocomplete="off" spellcheck="false"
                           :disabled="isFuture(s) || readOnly" :data-r="si" :data-c="mi" :aria-label="m.name + ' ' + s"
                           x-model="c.value" @input="touch(c)" @blur="commit(c)"
                           @keydown="gridKey($event, si, mi)" @paste="paste($event, si, mi)" :title="c.msgs.length ? c.msgs[0].message : (c.by ? c.by : '')">
                  </td>
                </template>
                <td class="hh-grid__sum" x-show="meters.length > 1" x-text="fmt(slotTotal(s), 1)"></td>
              </tr>
            </template>
          </tbody>
          <tfoot>
            <tr>
              <th scope="row">Σ kW</th>
              <template x-for="m in meters" :key="m.id"><td x-text="fmt(colTotal(m.id))"></td></template>
              <td x-show="meters.length > 1"></td>
            </tr>
            <tr>
              <th scope="row">Est. kWh</th>
              <template x-for="m in meters" :key="m.id"><td x-text="fmt(colTotal(m.id) * cfg.intervalMin / 60)"></td></template>
              <td x-show="meters.length > 1" x-text="fmt(gridKwh())"></td>
            </tr>
          </tfoot>
        </table>
      </div>

      <section class="hh-card mt-3" x-show="issues().length">
        <header class="hh-card__head"><h3><i class="bi bi-exclamation-triangle"></i> Needs a look (<span x-text="issues().length"></span>)</h3></header>
        <template x-for="c in issues()" :key="c.mid + '|' + c.s">
          <div class="hh-issuerow">
            <strong x-text="meterName(c.mid) + ' · ' + c.s + ' = ' + c.value"></strong>
            <?php require __DIR__ . '/../partials/issue.php'; ?>
          </div>
        </template>
      </section>
    </div>
  </template>

  <?php if ($isSolar): ?>
    <section class="hh-card mt-3">
      <header class="hh-card__head"><h3><i class="bi bi-graph-up"></i> Today's curve</h3><small class="text-secondary">A missed decimal shows up as a dip</small></header>
      <div class="hh-chartbox"><canvas id="solarChart" aria-label="Solar output through the day" role="img"></canvas></div>
    </section>
  <?php endif; ?>

  <div class="hh-empty" x-show="!loading && !meters.length">
    <div class="hh-empty__icon"><i class="bi bi-inboxes"></i></div>
    <p>No active meters in this log. An admin can switch them on under Meters.</p>
  </div>
</div>
