<p class="hh-lead"><?= App\Core\Auth::can('enter') ? 'Pick a log. Each opens on the reading that is due.' : 'Pick a log to look at its readings. You can look back through earlier days.' ?></p>
<div class="hh-grid-cards">
  <?php foreach ($progress['logs'] as $l): ?>
    <article class="hh-log-card is-<?= e($l['state']) ?>">
      <div class="hh-log-card__top">
        <span class="hh-chip-icon is-<?= e($l['kind']) ?>"><i class="bi bi-<?= e($l['icon']) ?>"></i></span>
        <?php if ($l['state'] === 'complete'): ?><span class="hh-pill hh-pill--ok">Complete</span>
        <?php elseif ($l['state'] === 'missed'): ?><span class="hh-pill hh-pill--warn">Missed</span>
        <?php elseif ($l['state'] === 'due'): ?><span class="hh-pill hh-pill--info">Due</span><?php endif; ?>
      </div>
      <h3><a class="stretched-link" href="<?= e(url($l['href'])) ?>"><?= e($l['title']) ?></a></h3>
      <p class="hh-log-card__big"><?= e($l['summary']) ?></p>
      <?php if ($l['total'] > 0): ?>
        <div class="hh-bar"><span style="width: <?= e((string) round($l['done'] / $l['total'] * 100)) ?>%"></span></div>
      <?php endif; ?>
      <p class="hh-log-card__detail"><?= e($l['detail']) ?></p>
    </article>
  <?php endforeach; ?>
</div>
