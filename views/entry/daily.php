<div class="hh-entry" x-data='dailyEntry(<?= e(json_encode($cfg, JSON_UNESCAPED_SLASHES)) ?>)' x-init="init()" x-cloak>

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
      <small><span x-text="entered"></span> of <span x-text="total"></span> meters entered</small>
    </div>
  </div>

  <p class="hh-hint"><i class="bi bi-info-circle"></i> Type each counter as shown on the meter. “Used yesterday” is worked out for you: this reading minus the previous one, times the meter multiplier.</p>

  <div class="hh-banner hh-banner--info" x-show="readOnly" x-cloak><i class="bi bi-eye"></i>
    <span x-text="cfg.editFrom === null ? 'You can look at readings here but not change them.' : 'View only: this day is older than you can change. Ask a supervisor if something needs correcting.'"></span></div>
  <div class="hh-banner hh-banner--warn" x-show="fromCache"><i class="bi bi-cloud-slash"></i> You're offline — showing the last saved copy. New values will send when the connection returns.</div>
  <div class="hh-banner hh-banner--error" x-show="loadError" x-text="loadError"></div>
  <div class="hh-skeleton" x-show="loading"><span></span><span></span><span></span><span></span></div>

  <template x-for="g in groups" :key="g.id">
    <section class="hh-card hh-daily-group">
      <header class="hh-card__head">
        <h3><i class="bi" :class="groupIcon(g)"></i> <span x-text="g.name"></span></h3>
        <small class="text-secondary"><span x-text="groupEntered(g)"></span> of <span x-text="g.meters.length"></span></small>
      </header>

      <template x-for="(m, mi) in g.meters" :key="m.id">
        <div class="hh-drow" x-data="{ get c() { return cell(m.id) } }" :class="'is-' + c.state">
          <div class="hh-drow__name">
            <label :for="'d' + m.id" x-text="m.name"></label>
            <small>
              <span x-show="m.prev_value !== null">
                <span x-text="m.prev_date === yesterdayOf(date) ? 'Yesterday' : 'On ' + m.prev_date"></span>:
                <span x-text="fmtCounter(m.prev_value)"></span>
              </span>
              <span x-show="m.prev_value === null">No earlier reading</span>
              <span class="hh-tag" x-show="m.multiplier !== 1" x-text="'× ' + m.multiplier"></span>
            </small>
          </div>
          <div class="hh-drow__input">
            <input :id="'d' + m.id" class="hh-input" type="text" inputmode="decimal" enterkeyhint="next" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="Reading"
                   :data-idx="allIndex(m.id)" :disabled="readOnly" x-model="c.value" @input="touch(c)" @blur="commit(c)" @keydown.enter.prevent="enter($event, allIndex(m.id))" :aria-label="m.name + ' reading'">
            <span class="hh-state" :class="'is-' + c.state" :title="stateText(c)"><i class="bi" :class="stateIcon(c)"></i></span>
          </div>
          <div class="hh-drow__use" :class="{'is-empty': use(m) === null}">
            <small>Used yesterday</small>
            <strong x-text="use(m) === null ? '—' : fmt(use(m)) + ' ' + m.unit"></strong>
          </div>
          <?php require __DIR__ . '/../partials/issue.php'; ?>
        </div>
      </template>
    </section>
  </template>

  <div class="hh-empty" x-show="!loading && !groups.length">
    <div class="hh-empty__icon"><i class="bi bi-inboxes"></i></div>
    <p>No daily meters are switched on.</p>
  </div>
</div>
