<?php
use App\Core\Auth;

$user = Auth::user();
$links = [];
if (Auth::can('admin')) {
    $links = [
        ['Meters', 'Feeders, solar, water, generators, order and limits', '/admin/meters', 'diagram-3'],
        ['Users', 'Add people, change roles, reset passwords', '/admin/users', 'people'],
        ['Settings', 'Late rule, warning limits, generator limits', '/admin/settings', 'sliders'],
        ['Audit log', 'Every change made to a saved reading', '/admin/audit', 'clock-history'],
        ['Import history', 'Bring in the old Excel logsheets', '/admin/import', 'upload'],
    ];
}
?>
<section class="hh-card hh-profile">
  <span class="hh-avatar hh-avatar--lg"><?= e(mb_strtoupper(mb_substr($user['name'], 0, 1))) ?></span>
  <div>
    <strong><?= e($user['name']) ?></strong>
    <div class="text-secondary small">@<?= e($user['username']) ?> · <?= e(ucfirst($user['role'])) ?></div>
  </div>
</section>

<?php if ($links): ?>
  <h2 class="hh-section">Admin</h2>
  <div class="hh-list">
    <?php foreach ($links as [$l, $d, $h, $i]): ?>
      <a class="hh-list__item" href="<?= e(url($h)) ?>">
        <span class="hh-chip-icon"><i class="bi bi-<?= e($i) ?>"></i></span>
        <span class="hh-list__text"><strong><?= e($l) ?></strong><small><?= e($d) ?></small></span>
        <i class="bi bi-chevron-right text-secondary"></i>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<h2 class="hh-section">Appearance</h2>
<div class="hh-list">
  <button type="button" class="hh-list__item w-100" data-theme-toggle>
    <span class="hh-chip-icon"><i class="bi bi-moon-stars"></i></span>
    <span class="hh-list__text"><strong>Dark mode</strong><small>Easier on the eyes in the powerhouse at night</small></span>
    <i class="bi bi-toggles text-secondary"></i>
  </button>
</div>

<form method="post" action="<?= e(url('/logout')) ?>" class="mt-4"><?= csrf_field() ?>
  <button class="btn btn-outline-danger w-100 py-2" type="submit"><i class="bi bi-box-arrow-right"></i> Sign out</button>
</form>
