<?php /* Shared warning / error block. Expects Alpine scope: `c` (a cell) and component methods fix, saveAnyway, override, retryReason. */ ?>
<div class="hh-issue" :class="c.state === 'warning' ? 'is-warning' : 'is-error'" x-show="c.state === 'warning' || c.state === 'error'" x-cloak>
  <ul>
    <template x-for="(m, i) in c.msgs" :key="i">
      <li><i class="bi" :class="c.state === 'warning' ? 'bi-exclamation-triangle-fill' : 'bi-x-octagon-fill'"></i> <span x-text="m.message"></span></li>
    </template>
  </ul>

  <!-- warning: Fix / Save anyway (+ optional note) -->
  <div class="hh-issue__actions" x-show="c.state === 'warning'">
    <input type="text" class="form-control form-control-sm" maxlength="255" placeholder="Note (optional) — e.g. feeder tripped" x-model="c.note" aria-label="Note">
    <button type="button" class="btn btn-sm btn-outline-secondary" @click="fix(c)">Fix</button>
    <button type="button" class="btn btn-sm btn-warning" @click="saveAnyway(c)">Save anyway</button>
  </div>

  <!-- counter backwards: supervisors can override -->
  <div class="hh-issue__actions" x-show="c.state === 'error' && c.msgs.some(m => m.overridable)">
    <input type="text" class="form-control form-control-sm" maxlength="255" placeholder="Reason" x-model="c.reason" aria-label="Reason">
    <button type="button" class="btn btn-sm btn-outline-secondary" @click="fix(c)">Fix</button>
    <button type="button" class="btn btn-sm btn-danger" @click="override(c)">Override: meter replaced / reset</button>
  </div>

  <!-- editing an old reading: reason required -->
  <div class="hh-issue__actions" x-show="c.state === 'error' && c.msgs.some(m => m.code === 'REASON_REQUIRED')">
    <input type="text" class="form-control form-control-sm" maxlength="255" placeholder="Why are you changing it?" x-model="c.reason" aria-label="Reason">
    <button type="button" class="btn btn-sm btn-primary" @click="retryReason(c)">Save change</button>
  </div>

  <div class="hh-issue__actions" x-show="c.state === 'error' && !c.msgs.some(m => m.overridable || m.code === 'REASON_REQUIRED')">
    <button type="button" class="btn btn-sm btn-outline-secondary" @click="fix(c)">Fix</button>
  </div>
</div>
