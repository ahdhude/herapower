<?php
use App\Core\Auth;

$canEnter = Auth::can('enter');
$canApprove = Auth::can('approve');
$first = explode(' ', trim((string) (Auth::user()['name'] ?? '')))[0];
$h = (int) now()->format('H');
$greet = $h < 12 ? 'Good morning' : ($h < 18 ? 'Good afternoon' : 'Good evening');
?>
<div x-data='todayPage(<?= e(json_encode(['progress' => $progress, 'review' => $review, 'canEnter' => $canEnter, 'canApprove' => $canApprove], JSON_UNESCAPED_SLASHES)) ?>)' x-init="init()" x-cloak>

  <!-- Next up -->
  <section class="hh-hero" :class="next ? 'is-action' : 'is-clear'">
    <div class="hh-hero__text">
      <span class="hh-eyebrow" x-text="next ? 'Next up' : 'All caught up'"></span>
      <h2 x-text="next ? next.title : '<?= e($greet) ?>, <?= e($first) ?>'"></h2>
      <p x-text="next ? nextHint(next) : 'Nothing is overdue. The next reading will show here when it is due.'"></p>
    </div>
    <?php if ($canEnter): ?>
      <a class="hh-hero__btn" x-show="next" :href="url(next.href)">
        <span x-text="next && next.due_open ? 'Start ' + next.due + ' round' : 'Open'"></span><i class="bi bi-arrow-right"></i>
      </a>
    <?php endif; ?>
    <i class="bi bi-lightning-charge-fill hh-hero__bolt" aria-hidden="true"></i>
  </section>

  <!-- Waiting to send (offline queue) -->
  <section class="hh-card hh-queue" x-show="queue.length" x-transition>
    <header class="hh-card__head">
      <h3><i class="bi bi-hourglass-split"></i> Waiting to send: <span x-text="queue.length"></span></h3>
      <button type="button" class="btn btn-sm btn-outline-primary" @click="HQ.flush()" :disabled="!online">Send now</button>
    </header>
    <ul class="hh-queue__list">
      <template x-for="q in queue" :key="q.id">
        <li :class="{'is-held': q.state === 'held'}">
          <div>
            <strong x-text="q.label"></strong>
            <span class="text-secondary" x-text="q.kind === 'reading' ? ' = ' + q.payload.value : ' · hourly log'"></span>
            <div class="small" :class="q.state === 'held' ? 'text-warning-emphasis' : 'text-secondary'" x-text="q.note || (online ? 'Sending…' : 'Will send when you are back online')"></div>
          </div>
          <div class="hh-queue__actions" x-show="q.state === 'held'">
            <button type="button" class="btn btn-sm btn-outline-secondary" @click="HQ.remove(q.id)">Discard</button>
            <button type="button" class="btn btn-sm btn-warning" x-show="q.canForce" @click="HQ.forceHeld(q.id)">Save anyway</button>
          </div>
        </li>
      </template>
    </ul>
  </section>

  <!-- One card per log -->
  <h2 class="hh-section">Today's logs <small class="text-secondary" x-text="'· updated ' + updated"></small></h2>
  <div class="hh-grid-cards">
    <template x-for="l in progress.logs" :key="l.key">
      <article class="hh-log-card" :class="'is-' + l.state">
        <div class="hh-log-card__top">
          <span class="hh-chip-icon" :class="'is-' + l.kind"><i :class="'bi bi-' + l.icon"></i></span>
          <span class="hh-pill" :class="'hh-pill--' + pill(l).tone" x-text="pill(l).text"></span>
        </div>
        <h3><a class="stretched-link" :href="url(l.href)" x-text="l.title"></a></h3>
        <p class="hh-log-card__big" x-text="l.summary"></p>
        <div class="hh-bar" x-show="l.total > 0"><span :style="'width:' + pct(l) + '%'"></span></div>
        <p class="hh-log-card__detail" x-text="l.detail"></p>
        <ul class="hh-log-card__lines" x-show="l.lines.length">
          <template x-for="ln in l.lines" :key="ln.label">
            <li><a :href="url(ln.href)"><strong x-text="ln.label"></strong> <span x-text="ln.text"></span></a></li>
          </template>
        </ul>
        <p class="hh-log-card__approved" x-show="l.approved"><i class="bi bi-patch-check-fill"></i> Approved by <span x-text="l.approved && l.approved.by"></span></p>
      </article>
    </template>
  </div>

  <?php if ($canApprove): ?>
    <h2 class="hh-section">Yesterday's review <small class="text-secondary" x-text="review ? '· ' + review.date : ''"></small></h2>
    <div class="hh-list" x-show="review">
      <template x-for="l in (review ? review.logs : [])" :key="'r' + l.key">
        <div class="hh-list__item">
          <span class="hh-chip-icon" :class="'is-' + l.kind"><i :class="'bi bi-' + l.icon"></i></span>
          <span class="hh-list__text">
            <strong x-text="l.title"></strong>
            <small x-text="l.kind === 'genset' ? l.summary : l.detail"></small>
          </span>
          <span class="hh-pill hh-pill--ok" x-show="l.approved"><i class="bi bi-patch-check-fill"></i> Approved</span>
          <button type="button" class="btn btn-sm btn-outline-primary" x-show="!l.approved" @click="approve(l)">Approve</button>
        </div>
      </template>
    </div>
  <?php endif; ?>
</div>
