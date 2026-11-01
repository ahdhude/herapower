<div class="hh-entry" x-data='gensetEntry(<?= e(json_encode($cfg, JSON_UNESCAPED_SLASHES)) ?>)' x-init="init()" x-cloak>

  <nav class="hh-segment" aria-label="Generators">
    <?php foreach ($gens as $g): ?>
      <a href="<?= e(url('/entry/genset?meter=' . $g['id'])) ?>" :href="genUrl(<?= (int) $g['id'] ?>)" class="<?= (int) $g['id'] === (int) $cfg['meterId'] ? 'is-active' : '' ?>">
        <i class="bi bi-fuel-pump-diesel"></i> <?= e($g['name']) ?>
      </a>
    <?php endforeach; ?>
  </nav>

  <div class="hh-toolbar">
    <div class="hh-datebar">
      <button type="button" class="hh-icon-btn" @click="shiftDay(-1)" :disabled="date <= cfg.minDate" aria-label="Previous log day"><i class="bi bi-chevron-left"></i></button>
      <label class="hh-datebar__label">
        <input type="date" x-model="date" @change="changeDate()" :min="cfg.minDate" :max="cfg.today" aria-label="Log day">
        <span x-text="dateLabel"></span><i class="bi bi-calendar3"></i>
      </label>
      <button type="button" class="hh-icon-btn" @click="shiftDay(1)" :disabled="date >= cfg.today" aria-label="Next log day"><i class="bi bi-chevron-right"></i></button>
    </div>
    <small class="text-secondary">Log day runs 08:00 → 07:00 next day · <span x-text="loggedCount"></span> of 24 hours logged</small>
  </div>

  <div class="hh-banner hh-banner--info" x-show="readOnly" x-cloak><i class="bi bi-eye"></i>
    <span x-text="cfg.editFrom === null ? 'You can look at readings here but not change them.' : 'View only: this day is older than you can change. Ask a supervisor if something needs correcting.'"></span></div>
  <div class="hh-banner hh-banner--info" x-show="olderDay" x-cloak><i class="bi bi-clock-history"></i>
    <span>This is an earlier day. You can add readings that are missing; readings already saved can only be changed by a supervisor.</span></div>
  <div class="hh-banner hh-banner--warn" x-show="!cfg.hasLimits"><i class="bi bi-info-circle"></i> No alarm limits are set yet, so out-of-range values will not be flagged. An admin can add them under Settings.</div>
  <div class="hh-banner hh-banner--warn" x-show="fromCache"><i class="bi bi-cloud-slash"></i> You're offline — showing the last saved copy.</div>
  <div class="hh-banner hh-banner--error" x-show="loadError" x-text="loadError"></div>
  <div class="hh-skeleton" x-show="loading"><span></span><span></span><span></span><span></span></div>

  <div class="hh-hours">
    <template x-for="h in hours" :key="h.at">
      <section class="hh-hour" :class="{'is-open': open === h.at, 'is-logged': h.logged, 'is-future': h.future, 'is-alarm': h.alarm}">
        <button type="button" class="hh-hour__head" @click="toggle(h)" :disabled="h.future" :aria-expanded="open === h.at">
          <span class="hh-hour__time" x-text="h.label"></span>
          <span class="hh-hour__status">
            <template x-if="h.alarm"><span class="hh-pill hh-pill--bad"><i class="bi bi-exclamation-triangle-fill"></i> Out of limit</span></template>
            <template x-if="h.logged && !h.alarm"><span class="hh-pill hh-pill--ok"><i class="bi bi-check2"></i> Logged<span x-show="h.by"> · <span x-text="h.by"></span></span></span></template>
            <template x-if="h.queued"><span class="hh-pill hh-pill--info"><i class="bi bi-hourglass-split"></i> Waiting to send</span></template>
            <template x-if="!h.logged && !h.queued && !h.future"><span class="hh-pill hh-pill--muted">Not logged</span></template>
            <template x-if="h.future"><span class="hh-pill hh-pill--muted">Later</span></template>
          </span>
          <span class="hh-hour__sum text-secondary d-none d-sm-inline" x-text="summary(h)"></span>
          <i class="bi bi-chevron-down hh-hour__chev"></i>
        </button>

        <div class="hh-hour__body" x-show="open === h.at" x-transition.opacity>
          <div class="hh-fieldgrid">
            <template x-for="f in cfg.fields" :key="f.key">
              <label class="hh-f" :class="{'is-bad': h.bad[f.key]}">
                <span x-text="f.label"></span>
                <span class="hh-f__box">
                  <input type="text" inputmode="decimal" autocomplete="off" spellcheck="false" :disabled="readOnly || hourLocked(h)" x-model="h.form[f.key]" @input="h.dirty = true; h.state = 'idle'; h.bad[f.key] = false" :aria-label="f.label">
                  <em x-text="f.unit"></em>
                </span>
              </label>
            </template>
          </div>

          <div class="hh-visual">
            <span class="hh-visual__label">Visual check</span>
            <div class="hh-toggle" role="group" aria-label="Visual check">
              <button type="button" :disabled="readOnly || hourLocked(h)" :class="{'is-on': h.form.visual_ok === true}" @click="h.form.visual_ok = true; h.dirty = true">OK</button>
              <button type="button" :disabled="readOnly || hourLocked(h)" :class="{'is-on is-bad': h.form.visual_ok === false}" @click="h.form.visual_ok = false; h.dirty = true">Not OK</button>
            </div>
            <input type="text" class="form-control" maxlength="255" placeholder="Note (leaks, noise, smell…)" :disabled="readOnly || hourLocked(h)" x-model="h.form.visual_note" @input="h.dirty = true" aria-label="Visual check note">
          </div>

          <div class="hh-issue is-error" x-show="h.state === 'error'"><ul><template x-for="(m, i) in h.msgs" :key="i"><li><i class="bi bi-x-octagon-fill"></i> <span x-text="m.message"></span></li></template></ul></div>
          <div class="hh-issue is-alarm" x-show="h.state === 'warning'">
            <ul><template x-for="(m, i) in h.msgs" :key="i"><li><i class="bi bi-exclamation-triangle-fill"></i> <span x-text="m.message"></span></li></template></ul>
            <div class="hh-issue__actions">
              <button type="button" class="btn btn-sm btn-outline-secondary" @click="h.state = 'idle'">Fix</button>
              <button type="button" class="btn btn-sm btn-danger" @click="save(h, true)">Save anyway</button>
            </div>
          </div>

          <div class="hh-hour__actions">
            <button type="button" class="btn btn-outline-secondary" @click="copyPrev(h)" :disabled="!prevLogged(h) || readOnly || hourLocked(h)"><i class="bi bi-files"></i> Copy last hour</button>
            <button type="button" class="btn btn-primary" @click="save(h, false)" :disabled="h.saving || !h.dirty || readOnly || hourLocked(h)"><span x-show="!h.saving"><i class="bi bi-check2-circle"></i> Save hour</span><span x-show="h.saving">Saving…</span></button>
          </div>
        </div>
      </section>
    </template>
  </div>
</div>
