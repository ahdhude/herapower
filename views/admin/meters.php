<?php /** @var array $groups */ ?>
<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
  <p class="hh-lead mb-0">Every point that is read. Switch a meter off to hide it from entry; meters with readings are never deleted.</p>
  <a class="btn btn-primary" href="<?= e(url('/admin/meters/new')) ?>"><i class="bi bi-plus-lg"></i> Add meter</a>
</div>

<?php foreach ($groups as $g): ?>
  <section class="hh-metergroup" id="g<?= (int) $g['id'] ?>">
    <header>
      <h3><?= e($g['name']) ?> <small class="text-secondary fw-normal">· <?= e($g['entry_type']) ?> · <?= count($g['meters']) ?> meters</small></h3>
    </header>
    <div class="hh-list">
      <?php if (!$g['meters']): ?><div class="hh-list__item text-secondary">No meters in this group.</div><?php endif; ?>
      <?php foreach ($g['meters'] as $i => $m): ?>
        <div class="hh-meterrow <?= (int) $m['active'] ? '' : 'is-off' ?>">
          <span class="hh-meterrow__id">#<?= (int) $m['id'] ?></span>
          <div class="hh-meterrow__name">
            <strong><?= e($m['name']) ?> <?php if ((int) $m['is_incomer']): ?><span class="hh-tag">incomer</span><?php endif; ?></strong>
            <small>
              <?= e($m['code']) ?> · <?= e($m['kind']) ?> · <?= e($m['reading_type']) ?> · <?= e($m['unit']) ?>
              <?php if ($m['interval_min']): ?> · every <?= (int) $m['interval_min'] ?> min <?= e(substr((string) $m['window_start'], 0, 5)) ?>–<?= e(substr((string) $m['window_end'], 0, 5)) ?><?php endif; ?>
              <?php if ((float) $m['multiplier'] !== 1.0): ?> · ×<?= e(trim_dec($m['multiplier'])) ?><?php endif; ?>
            </small>
          </div>
          <div class="hh-meterrow__actions">
            <form method="post" action="<?= e(url('/admin/meters/' . $m['id'] . '/move')) ?>"><?= csrf_field() ?><input type="hidden" name="dir" value="up">
              <button class="btn btn-outline-secondary" <?= $i === 0 ? 'disabled' : '' ?> aria-label="Move up"><i class="bi bi-arrow-up"></i></button></form>
            <form method="post" action="<?= e(url('/admin/meters/' . $m['id'] . '/move')) ?>"><?= csrf_field() ?><input type="hidden" name="dir" value="down">
              <button class="btn btn-outline-secondary" <?= $i === count($g['meters']) - 1 ? 'disabled' : '' ?> aria-label="Move down"><i class="bi bi-arrow-down"></i></button></form>
            <a class="btn btn-outline-primary" href="<?= e(url('/admin/meters/' . $m['id'] . '/edit')) ?>">Edit</a>
            <form method="post" action="<?= e(url('/admin/meters/' . $m['id'] . '/toggle')) ?>"><?= csrf_field() ?>
              <button class="btn <?= (int) $m['active'] ? 'btn-outline-secondary' : 'btn-success' ?>"><?= (int) $m['active'] ? 'Switch off' : 'Switch on' ?></button></form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
<?php endforeach; ?>
